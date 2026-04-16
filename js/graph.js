/**
 * rep/vis_graph_panel : Graph behavior with lazy-loading (vis.js)
 *
 * Expects backend to inject:
 *   drupalSettings.graphData = {
 *     nodes: [...],           // base visible nodes
 *     edges: [...],           // base visible edges
 *     extraNodes: [...],      // cache of known-but-hidden nodes
 *     extraEdges: [...]       // cache of known-but-hidden edges
 *   }
 * And:
 *   drupalSettings.rep.socObjectsEndpoint
 *   drupalSettings.rep.graphLimits = {
 *     maxMembersPerSOC, pageSize, maxLiveNodes, autoShowOnFetch
 *   }
 *
 * This build ensures:
 * - `hascoTypeUri` and `typeUri` are independent menu options.
 * - If the server only returns `typeUri`, the "hascoTypeUri" submenu still shows the target class
 *   and toggling there creates a **hascoTypeUri** edge (not reusing the typeUri edge).
 * - If both type edges exist for the same (from,to), they are drawn with opposite curves.
 * - "Copy URI" copies the ORIGINAL IRI (e.g., https://hadatac.org/ont/hadatac#/PER...), not a local proxy.
 * - "Make it base" promotes a node to be the **graph root** and performs a **real navigation**
 *   to that node’s page, but the graph is snapshotted in sessionStorage and restored on load
 *   so the visualization stays exactly as it was (no losses).
 */

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.graphInit = {
    attach: function (context) {
      const container = context.querySelector('#my-network');
      if (!container || container.dataset.loaded === 'true') return;
      if (typeof vis === 'undefined') return;

      // If the graph canvas is inside a collapsed/hidden region, defer initialization.
      // Initializing vis.Network while hidden commonly yields a 0x0 canvas and an
      // off-center view when the region is later shown.
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        return (rect.width > 0 && rect.height > 0);
      };
      if (!isVisible(container)) return;

      container.dataset.loaded = 'true';

      // ----- Config & limits -----
      const socEndpoint =
        (drupalSettings && drupalSettings.rep && drupalSettings.rep.socObjectsEndpoint) ||
        (window.Drupal && Drupal.url ? Drupal.url('rep/graph/expand') : '/rep/graph/expand');

      const limits = (drupalSettings && drupalSettings.rep && drupalSettings.rep.graphLimits) || {};
      const MAX_MEMBERS_PER_SOC = Number(limits.maxMembersPerSOC || 5);
      const PAGE_SIZE           = Number(limits.pageSize         || 5);
      const MAX_LIVE_NODES      = Number(limits.maxLiveNodes     || 600);
      const AUTO_SHOW_ON_FETCH  = Number(limits.autoShowOnFetch  || 0);

      const nodeInfoEndpoint =
        (drupalSettings && drupalSettings.rep && drupalSettings.rep.nodeInfoEndpoint) ||
        (window.Drupal && Drupal.url ? Drupal.url('rep/graph/node') : '/rep/graph/node');

      const predCfg = (drupalSettings && drupalSettings.rep && drupalSettings.rep.graphPredicateConfig) || {};
      const normalizePredForApi = (v) => {
        const s = String(v || '').trim();
        if (!s) return '';
        const l = s.toLowerCase();
        if (l === 'super' || l === 'superuri' || l === 'hassuperuri' || l === 'superclassuri' || l === 'hassuperclassuri') return 'super';
        return s;
      };
      const normalizePredKey = (v) => normalizePredForApi(v).toLowerCase();

      const hiddenPredicateKeys = new Set(((predCfg.hidden || [])).map(normalizePredKey).filter(Boolean));
      const autoExpandPredicates = ((predCfg.autoExpand || [])).map(normalizePredForApi).filter(Boolean);

      const eyeSVG = `<i class="fa fa-eye"></i>`;
      const eyeOffSVG = `<i class="fa fa-eye-slash"></i>`;

      // ---------- Restore graph from session (if coming from "Make it base") ----------
      function parseIriFromLocation() {
        const m = (window.location.pathname || '').match(/\/rep\/uri\/([^\/#?]+)/);
        if (!m) return null;
        try {
          const b64 = m[1].replace(/-/g, '+').replace(/_/g, '/');
          const padded = b64 + '==='.slice((b64.length + 3) % 4);
          return decodeURIComponent(escape(window.atob(padded)));
        } catch (e) { return null; }
      }
      const arrivingIri = parseIriFromLocation();

      let restore = null;
      try {
        const raw = sessionStorage.getItem('repGraphState');
        if (raw) {
          const obj = JSON.parse(raw);
          // Optional sanity check: only restore if it matches the page we're opening
          if (!obj.targetIri || obj.targetIri === arrivingIri) restore = obj;
        }
      } catch (e) { /* ignore */ }

      // ----- Data caches -----
      const base = drupalSettings.graphData || {};
      if (restore) {
        // Replace base data with the saved snapshot
        base.nodes      = Array.isArray(restore.nodes) ? restore.nodes : (base.nodes || []);
        base.edges      = Array.isArray(restore.edges) ? restore.edges : (base.edges || []);
        base.extraNodes = Array.isArray(restore.extraNodes) ? restore.extraNodes : (base.extraNodes || []);
        base.extraEdges = Array.isArray(restore.extraEdges) ? restore.extraEdges : (base.extraEdges || []);
      }

      const nodes = new vis.DataSet(base.nodes || []);
      const edges = new vis.DataSet(base.edges || []);
      const extraNodes = base.extraNodes || [];
      let   extraEdges = base.extraEdges || [];

      // Initial root and current root (promoted by "Make it base")
      const firstNodeId = (nodes.getIds && nodes.getIds()[0]) || null;
      let currentRootId = restore?.currentRootId || firstNodeId;

      // ----- Normalize CURIE -> IRI -----
      const AHEAD = 'http://hadatac.org/ont/arrowhead/';
      function expandCurie(v) {
        return (typeof v === 'string' && v.startsWith('ahead:')) ? (AHEAD + v.slice(6)) : v;
      }
      function normalizeNode(n) {
        n = { ...n };
        n.id = expandCurie(n.id);
        if (n.typeUri) n.typeUri = expandCurie(n.typeUri);
        if (n.hascoTypeUri) n.hascoTypeUri = expandCurie(n.hascoTypeUri);
        return n;
      }
      function normalizeEdge(e) {
        e = { ...e };
        e.from = expandCurie(e.from);
        e.to   = expandCurie(e.to);
        if (e.predUri) e.predUri = expandCurie(e.predUri);

        // Normalize label variants for the direct parent pointer.
        if (typeof e.label === 'string' && e.label) {
          const l = e.label.trim();
          const lk = l.toLowerCase();
          if (lk === 'super' || lk === 'superuri' || lk === 'hassuperuri' || lk === 'superclassuri' || lk === 'hassuperclassuri') {
            e.label = 'super';
          }
        }

        return e;
      }
      for (let i = 0; i < extraNodes.length; i++) extraNodes[i] = normalizeNode(extraNodes[i]);
      for (let i = 0; i < extraEdges.length; i++) extraEdges[i] = normalizeEdge(extraEdges[i]);

      // Guards & paging state
      const openedNodes = {};
      const primedNodes = {};
      const pageState = Object.create(null);

      // ----- Layout helpers -----
      // Users must be able to drag nodes and keep their manual layout.
      const pinnedNodes = new Set();

      // Kept for compatibility with older placement logic. (no-op)
      function freezeAllNodes() {}
      function unfreezeNodes() {}

      function isPinned(id) { return pinnedNodes.has(id); }

      function placeAround(network, centerId, newIds, radius = 140) {
        const ids = (newIds || []).filter(id => id && !isPinned(id));
        if (!ids.length) return;
        const pos = network.getPositions([centerId])[centerId];
        if (!pos) return;
        const N = ids.length || 1;
        ids.forEach((id, i) => {
          const a = (2 * Math.PI * i) / N;
          network.moveNode(id, pos.x + radius * Math.cos(a), pos.y + radius * Math.sin(a));
        });
      }

      function placeInRow(network, centerId, idsRaw, dy, spacing = 170) {
        const ids = (idsRaw || []).filter(id => id && !isPinned(id));
        if (!ids.length) return;
        const pos = network.getPositions([centerId])[centerId];
        if (!pos) return;
        const startX = pos.x - ((ids.length - 1) * spacing) / 2;
        ids.forEach((id, i) => {
          network.moveNode(id, startX + (i * spacing), pos.y + dy);
        });
      }

      function placeByPredicate(network, anchorId, ids, predicate) {
        const lbl = normalizePredForApi(predicate);
        if (lbl === 'super') return placeInRow(network, anchorId, ids, -170);
        if (lbl === 'children' || lbl === 'contains') return placeInRow(network, anchorId, ids, 170);
        return placeAround(network, anchorId, ids, 160);
      }

      // ----- Virtualize loop edges so they are clickable -----
      const loopEdges = extraEdges.filter(e => e.from === e.to);
      loopEdges.forEach(e => {
        const virtualNodeId = `${e.from}_loop_virtual_${e.label}`;
        if (!extraNodes.some(n => n.id === virtualNodeId)) {
          extraNodes.push({
            id: virtualNodeId,
            label: e.label,
            shape: 'ellipse',
            font: { color: 'black' },
            color: { background: '#ffc107', border: '#e0a800' }
          });
        }
        extraEdges.push(normalizeEdge({ from: e.from, to: virtualNodeId, label: e.label }));
      });
      extraEdges = extraEdges.filter(e => e.from !== e.to);

      // ----- vis.js network -----
      const options = {
        nodes: {
          shape: "box",
          font: { align: "center", size: 14 },
          widthConstraint: { minimum: 70, maximum: 70 },
          heightConstraint: { minimum: 35 }
        },
        edges: {
          arrows: { to: { enabled: true, scaleFactor: 0.85 } },
          smooth: false,
        },
        layout: {
          improvedLayout: false,
        },
        interaction: {
          dragNodes: true,
          dragView: true,
          zoomView: true,
          navigationButtons: true,
          keyboard: { enabled: true },
          hideEdgesOnDrag: false,
          hideEdgesOnZoom: false,
          zoomSpeed: 0.9,
          tooltipDelay: 200,
        },
        // Physics disabled: we place new nodes deterministically and keep the graph responsive.
        physics: { enabled: false }
      };
      const network = new vis.Network(container, { nodes, edges }, options);

      // Ensure mouse wheel zoom works (some themes/plugins can swallow wheel events).
      try {
        const clamp = (v, min, max) => Math.min(max, Math.max(min, v));

        container.addEventListener('wheel', (ev) => {
          // Let Shift+wheel scroll the page normally.
          if (ev.shiftKey) return;

          // Only handle wheel events that happen over the graph container.
          if (!container.contains(ev.target)) return;

          ev.preventDefault();
          ev.stopPropagation();

          const deltaY = (typeof ev.deltaY === 'number') ? ev.deltaY : 0;
          if (deltaY === 0) return;

          const current = network.getScale();
          const factor = Math.exp(-deltaY * 0.0015);
          const next = clamp(current * factor, 0.1, 4.0);

          const rect = container.getBoundingClientRect();
          const domX = (typeof ev.clientX === 'number') ? (ev.clientX - rect.left) : (container.clientWidth / 2);
          const domY = (typeof ev.clientY === 'number') ? (ev.clientY - rect.top)  : (container.clientHeight / 2);

          const cx = container.clientWidth / 2;
          const cy = container.clientHeight / 2;

          const pointerCanvas = network.DOMtoCanvas({ x: domX, y: domY });
          const newView = {
            x: pointerCanvas.x - (domX - cx) / next,
            y: pointerCanvas.y - (domY - cy) / next,
          };

          network.moveTo({ position: newView, scale: next, animation: false });
        }, { passive: false, capture: true });
      } catch (e) {
        // ignore
      }

      // Expose for other behaviors (e.g., collapse/show) to re-fit on demand.
      container.__repNetwork = network;
      container.__repRootId = currentRootId;

      function centerOnRoot() {
        try {
          if (currentRootId && nodes.get(currentRootId)) {
            network.focus(currentRootId, { scale: 1.0, animation: { duration: 250 } });
          } else {
            network.fit({ animation: { duration: 250 } });
          }
        } catch (e) {
          // ignore
        }

        // Critical: disable physics after the initial layout so the graph becomes
        // responsive and nodes stay where the user drags them.
        try {
          network.setOptions({ physics: { enabled: false } });
        } catch (e2) {
          // ignore
        }
      }

      // Center once the first draw happens.
      try {
        network.once('afterDrawing', centerOnRoot);
      } catch (e) {
        // ignore
      }

      // ---------- Styling helpers ----------
      function isClassNode(n) {
        if (!n) return false;
        const id = expandCurie(n.id);
        const tu = n.typeUri && expandCurie(n.typeUri);
        return !!id && !!tu && id === tu;
      }
      function ensureNodeStyle(n) {
        // Always keep nodes draggable.
        n.fixed = false;

        if (!n.label || !String(n.label).trim()) {
          const p = (n.id || '').split('/');
          n.label = p[p.length - 1] || (n.id || '');
        }
        if (!n.label.includes('➕')) n.label += '\n➕';
        n.font = n.font || { size: 14 };

        if (n.shape === 'ellipse') {
          n.color = { background: '#28a745', border: '#1e7e34' };
          n.font  = { ...(n.font || {}), color: 'black' };
          return n;
        }
        if (isClassNode(n)) {
          n.shape = 'box';
          n.color = { background: '#28a745', border: '#1e7e34' };
          n.font  = { ...(n.font || {}), color: 'white' };
          return n;
        }
        if (!n.color) {
          n.color = { background: '#007bff', border: '#0056b3' };
          n.font  = { ...(n.font || {}), color: 'white' };
        }
        return n;
      }
      nodes.get().forEach(n => nodes.update(ensureNodeStyle({ ...n })));

      // Root style handling (highlight current root)
      function applyRootStyle(id, on) {
        const n = nodes.get(id);
        if (!n) return;
        const base = ensureNodeStyle({ ...n });
        const upd = on
          ? { borderWidth: 4, color: { ...(base.color || {}), border: '#ffc107' }, font: { ...(base.font||{}), bold: true, size: 16 } }
          : { borderWidth: 1, color: { ...(base.color || {}), border: (isClassNode(base) ? '#1e7e34' : '#0056b3') }, font: { ...(base.font||{}), bold: false, size: 14 } };
        nodes.update({ id, ...upd });
      }

      // ----- Explorer panel (preferred v2 UI) -----
      const explorer = (container.parentElement && container.parentElement.querySelector('#rep-graph-explorer')) || null;
      if (explorer && !explorer.dataset.repInit) {
        explorer.dataset.repInit = '1';
        explorer.innerHTML = '<div style="opacity:.8;font-size:13px;">Click a node to see details. Double-click to load relationships.</div>';
      }

      // ----- Floating menu -----
      const expandMenu = document.createElement("div");
      expandMenu.id = "expand-menu";
      expandMenu.style.cssText = `
        position:absolute;z-index:1000;background:#f8f9fa;border:1px solid #ccc;
        padding:6px 10px;border-radius:5px;box-shadow:2px 2px 6px rgba(0,0,0,0.1);
        display:none; pointer-events:auto; min-width: 340px;
      `;
      document.body.appendChild(expandMenu);

      function closeAllSubmenus(except = null) {
        expandMenu.querySelectorAll('.submenu').forEach(el => { if (el !== except) el.remove(); });
      }
      function updateExpandMenuPosition(nodeId) {
        if (!nodeId) return;
        const nodePos = network.getPositions([nodeId])[nodeId];
        if (!nodePos) return;
        const canvasPos = network.canvasToDOM(nodePos);
        const rect = container.getBoundingClientRect();
        const topOffset = window.scrollY + rect.top;
        expandMenu.style.left = `${rect.left + canvasPos.x + 30}px`;
        expandMenu.style.top  = `${topOffset + canvasPos.y - 10}px`;
      }

      // ----- Clipboard helpers -----
      // NOTE: We keep base64/url helpers for navigation, but "Copy URI" now copies the ORIGINAL IRI raw.
      function encodeBase64Url(str) {
        const b64 = window.btoa(unescape(encodeURIComponent(str)));
        return b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
      }
      function buildLocalUriLink(iri) {
        if (!iri) return '';
        if (/\/rep\/uri\//.test(iri)) return iri;
        const b64 = encodeBase64Url(iri);
        let base = (window.Drupal && Drupal.url) ? Drupal.url('rep/uri') : '/rep/uri';
        if (!base) base = '/rep/uri';
        if (!base.endsWith('/')) base += '/';
        const origin = window.location && window.location.origin ? window.location.origin : '';
        const full = /^https?:\/\//i.test(base) ? (base + b64) : (origin + base + b64);
        return full;
      }
      function copyToClipboard(text, onDone) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(onDone).catch(() => fallbackCopy(text, onDone));
        } else {
          fallbackCopy(text, onDone);
        }
      }
      function fallbackCopy(text, onDone) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:absolute; left:-9999px; top:-9999px;';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        if (onDone) onDone();
      }
      function makeCopyLink(labelText, valueSupplier) {
        const link = document.createElement('span');
        link.textContent = labelText;
        link.style.cssText = `
          cursor:pointer; font-size:12px; color:#007bff; white-space:nowrap;
          padding:2px 6px; border-radius:4px;
        `;
        link.title = 'Copy to clipboard';
        link.addEventListener('click', (ev) => {
          ev.stopPropagation();
          const valRaw = (typeof valueSupplier === 'function') ? valueSupplier() : valueSupplier;
          const val = String(valRaw ?? ''); // copy RAW IRI (not local proxy)
          copyToClipboard(val, () => {
            const old = link.textContent;
            link.textContent = 'Copied!';
            setTimeout(() => { link.textContent = old; }, 900);
          });
        });
        return link;
      }

      // ----- Utilities -----
      function edgeIdOf(e) {
        const from = expandCurie(e.from);
        const to   = expandCurie(e.to);
        const key  = e.predUri ? expandCurie(e.predUri) : e.label;
        return e.id || `${from}_${to}_${key}`;
      }
      function isDisplayableLabel(label) {
        if (!label) return false;
        const raw = String(label);
        const key = normalizePredKey(raw);
        if (!key) return false;
        if (hiddenPredicateKeys.has(key)) return false;

        if (raw === 'hasCollection') return false;
        const blacklist = new Set(['label','comment','body','hasImageUri','hasWebDocument','hasStatus','id']);
        return !blacklist.has(raw);
      }
      function buildLabelEdgesMap(relEdges) {
        const map = new Map();
        relEdges.forEach(e => {
          // relEdges are expected to be normalized already; normalize defensively only if needed.
          const E = (e && typeof e === 'object' && typeof e.from === 'string' && typeof e.to === 'string') ? e : normalizeEdge(e);
          const target = extraNodes.find(n => n.id === E.to);
          let lbl = E.label;
          if (lbl === 'hasCollection' && target) {
            const tu = (target.typeUri || '');
            if (tu.includes('/hasco/SampleCollection')) lbl = 'hasSampleCollection';
            else if (tu.includes('/hasco/SpaceCollection')) lbl = 'hasSpaceCollection';
            else if (tu.includes('/hasco/TimeCollection')) lbl = 'hasTimeCollection';
            else if (tu.includes('/hasco/SubjectGroup') || tu.includes('/hasco/StudyObjectCollection')) lbl = 'hasSubjectCollection';
          }
          const obj = { edge: { ...E, label: lbl }, id: edgeIdOf({ ...E, label: lbl }), normalized: lbl };
          if (!map.has(lbl)) map.set(lbl, []);
          map.get(lbl).push(obj);
        });
        return map;
      }
      const isMemberLabel = (lbl) =>
        lbl === 'contains' || lbl === 'hasMember' || lbl === 'hasStudyObject' || lbl === 'hasObject';
      const isReverseMemberLabel = (lbl) =>
        lbl === 'isMemberOf' || lbl === 'memberOf' || lbl === 'isObjectOf';

      function synthesizeContainsFromReverse(nodeId) {
        const rev = extraEdges
          .filter(e => e.to === nodeId && isReverseMemberLabel(e.label));
        rev.forEach(e => {
          const synth = normalizeEdge({ from: nodeId, to: e.from, label: 'contains' });
          const id = edgeIdOf(synth);
          if (!extraEdges.some(x => edgeIdOf(x) === id)) {
            extraEdges.push({ ...synth, id });
          }
        });
      }

      function canAddMoreVisibleNodes(addCount) {
        const current = nodes.length ? nodes.length : nodes.getIds().length;
        return (current + addCount) <= MAX_LIVE_NODES;
      }
      function warnNodeCap() { alert(`Node limit reached (${MAX_LIVE_NODES}). Hide some items before loading more.`); }

      // ---------- Seed type edges from the node itself ----------
      function seedTypeEdgesForNode(node) {
        if (!node) return;
        const nid = expandCurie(node.id);
        function ensureTypeEdge(label, typeId) {
          if (!typeId) return;
          const tid = expandCurie(typeId);
          if (!extraNodes.find(n => n.id === tid)) {
            extraNodes.push({ id: tid, label: (tid.split('/').pop() || tid), typeUri: tid, shape: 'box' });
          }
          const e = { from: nid, to: tid, label };
          const id = edgeIdOf(e);
          if (!extraEdges.some(x => edgeIdOf(x) === id)) {
            extraEdges.push(normalizeEdge({ ...e, id }));
          }
        }
        ensureTypeEdge('typeUri', node.typeUri);
        ensureTypeEdge('hascoTypeUri', node.hascoTypeUri);
      }

      // ---------- Add visible edge; curve twin type edges so both appear ----------
      function addEdgeVisible(eRaw) {
        const e = normalizeEdge(eRaw);
        const id = edgeIdOf(e);
        if (edges.get(id)) return;

        let styled = { ...e, id };
        const isType = (e.label === 'typeUri' || e.label === 'hascoTypeUri');
        if (isType) {
          const twinLabel = (e.label === 'typeUri') ? 'hascoTypeUri' : 'typeUri';
          const twinId = edgeIdOf({ from: e.from, to: e.to, label: twinLabel });
          if (edges.get(twinId)) {
            styled.smooth = { type: 'curvedCW', roundness: 0.25 };
            const twin = edges.get(twinId);
            edges.update({ id: twin.id, smooth: { type: 'curvedCCW', roundness: 0.25 } });
          }
        }
        edges.add(styled);
      }

      // ---------- Slim payload ----------
      function slimPayloadForLabel(data, nodeId, label, maxKeep, direction = 'out', predUri = null) {
        const normNodes = (data.nodes || []).map(normalizeNode);
        const normEdges = (data.edges || []).map(normalizeEdge);

        const accept = (e) => {
          if (direction === 'in') {
            if (e.to !== nodeId) return false;
            if (predUri) return String(e.predUri || '') === String(predUri);
            return e.label === label;
          }

          if (e.from !== nodeId) return false;
          if (label === 'contains') return isMemberLabel(e.label);
          if (label === 'hascoTypeUri') return e.label === 'hascoTypeUri';
          if (label === 'typeUri')     return e.label === 'typeUri';
          return e.label === label;
        };

        const keptEdges = [];
        const keptNodeIds = new Set();

        for (const e of normEdges) {
          if (!accept(e)) continue;
          const lbl = (label === 'contains') ? 'contains' : e.label;
          const id  = edgeIdOf({ ...e, label: lbl });
          keptEdges.push({ ...e, id, label: lbl });
          keptNodeIds.add(direction === 'in' ? e.from : e.to);
          if (keptEdges.length >= maxKeep) break;
        }

        const keptNodes = normNodes.filter(n => keptNodeIds.has(n.id));
        return { nodes: keptNodes, edges: keptEdges, meta: data.meta || {} };
      }

      // ---------- Items for label (with fallback for hascoTypeUri) ----------
      function itemsForLabel(nodeId, label) {
        const norms = extraEdges;

        if (label === 'contains') {
          return norms
            .filter(e => e.from === nodeId && isMemberLabel(e.label))
            .map(e => ({ edge: { ...e, label: 'contains' }, id: edgeIdOf({ ...e, label: 'contains' }) }));
        }

        const rel = norms.filter(e => e.from === nodeId);
        const map = buildLabelEdgesMap(rel);
        let list = (map.get(label) || []);
        if (label === 'hascoTypeUri' && list.length === 0) {
          list = (map.get('typeUri') || []);
        }
        return list;
      }

      // ---------- Fetch more ----------
      function fetchMoreForLabel(nodeId, label, state, rightBtn, after) {
        const isType = (label === 'hascoTypeUri' || label === 'typeUri');
        if (!socEndpoint) return;

        const paramsLabeled = { from: nodeId, limit: isType ? 6 : PAGE_SIZE, offset: state.fetched, debug: 1 };
        if (label === 'contains') paramsLabeled.relation = 'contains';
        else paramsLabeled.label = label;

        const prev = rightBtn.textContent;
        rightBtn.disabled = true; rightBtn.textContent = '…';

        const finish = (returned, hasMore, meta) => {
          state.fetched += returned;
          state.hasMoreServer = !!hasMore;
          if (meta) {
            if (typeof meta.total === 'number') state.totalGuess = meta.total;
            else if (typeof meta.totalGuess === 'number') state.totalGuess = meta.totalGuess;
          }
          after(returned);
          rightBtn.textContent = prev; rightBtn.disabled = false;
        };

        $.getJSON(socEndpoint, paramsLabeled)
          .done(data => {
            const slim = slimPayloadForLabel(data, nodeId, label, isType ? 6 : PAGE_SIZE);
            const returned = slim.edges.length;
            mergeGraphPayload(slim, nodeId);

            if (isType) {
              finish(returned, false, slim.meta);
              if (returned === 0 && !state.triedGeneric) {
                state.triedGeneric = true;
                $.getJSON(socEndpoint, { from: nodeId, limit: 8, offset: 0, debug: 1 })
                  .done(data2 => {
                    const slim2 = slimPayloadForLabel(data2, nodeId, label, 8);
                    const ret2 = slim2.edges.length;
                    mergeGraphPayload(slim2, nodeId);
                    finish(ret2, false, slim2.meta);
                  })
                  .fail(() => finish(0, false));
              }
            } else {
              const meta = slim.meta || {};
              const hasMore = (typeof meta.totalGuess === 'number') ? (state.fetched < meta.totalGuess)
                                : (returned === PAGE_SIZE);
              finish(returned, hasMore, meta);
            }
          })
          .fail(() => {
            if (isType && !state.triedGeneric) {
              state.triedGeneric = true;
              $.getJSON(socEndpoint, { from: nodeId, limit: 8, offset: 0, debug: 1 })
                .done(data2 => {
                  const slim2 = slimPayloadForLabel(data2, nodeId, label, 8);
                  const ret2 = slim2.edges.length;
                  mergeGraphPayload(slim2, nodeId);
                  finish(ret2, false, slim2.meta);
                })
                .fail(() => finish(0, false));
            } else {
              finish(0, false);
            }
          });
      }

      function openLabelSubmenu(opt, nodeId, label) {
        let submenu = opt.querySelector(".submenu");
        if (submenu) { submenu.remove(); return; }
        closeAllSubmenus();

        const key = `${nodeId}:${label}`;
        if (!pageState[key]) {
          const now = itemsForLabel(nodeId, label).length;
          pageState[key] = {
            offset: 0,
            fetched: now,
            hasMoreServer: (now >= PAGE_SIZE),
            totalGuess: null,
            prefetchTried: false,
            triedGeneric: false
          };
        }
        const state = pageState[key];

        submenu = document.createElement("div");
        submenu.className = "submenu";
        submenu.style.cssText = `
          position:absolute; left:140px; top:0; background:#f1f1f1;
          border:1px solid #ccc; padding:6px; border-radius:4px;
          box-shadow:1px 1px 4px rgba(0,0,0,0.2); z-index:1001;
          max-height: 340px; overflow:auto; min-width: 360px; pointer-events:auto;
        `;
        opt.appendChild(submenu);

        const renderPage = () => {
          submenu.innerHTML = '';

          const list = itemsForLabel(nodeId, label);
          const totalFetched = list.length;

          if (totalFetched === 0 && !state.prefetchTried) {
            state.prefetchTried = true;
            state.hasMoreServer = true;
            fetchMoreForLabel(nodeId, label, state, { textContent:'', disabled:false }, () => { renderPage(); });
            const loading = document.createElement('div');
            loading.style.cssText = "padding:6px 4px; opacity:.7;";
            loading.textContent = 'Loading...';
            submenu.appendChild(loading);
            return;
          }

          const start = Math.max(0, Math.min(state.offset, Math.max(0, totalFetched - MAX_MEMBERS_PER_SOC)));
          const end = Math.min(start + MAX_MEMBERS_PER_SOC, totalFetched);
          const page = list.slice(start, end);

          page.forEach(({ edge: e }) => {
            const actualEdge = e;
            let child = extraNodes.find(n => n.id === actualEdge.to);
            if (!child) {
              child = normalizeNode({ id: actualEdge.to, label: (actualEdge.to.split('/').pop() || actualEdge.to), shape: 'box' });
              if (label === 'typeUri' || label === 'hascoTypeUri') child.typeUri = child.id;
              extraNodes.push(child);
            }

            const displayLabel = (child.label && String(child.label).trim())
              ? child.label
              : (child.id?.split('/').pop() || child.id || '(no label)');
            if (!child.label || !String(child.label).trim()) child.label = displayLabel;

            const desiredEdge = { from: nodeId, to: child.id, label };
            const desiredEdgeId = edgeIdOf(desiredEdge);
            const edgeOn = !!edges.get(desiredEdgeId);

            const row = document.createElement("div");
            row.style.cssText = `
              display:flex; align-items:center; justify-content:space-between;
              gap:12px; padding:4px 6px; min-width:340px; cursor:default;
            `;

            const leftWrap = document.createElement('div');
            leftWrap.style.cssText = 'display:flex; align-items:center; gap:8px; min-width:0; flex:1 1 auto;';

            const s = document.createElement("span");
            s.textContent = displayLabel;
            s.style.cssText = "flex:1 1 auto; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;";

            const copyChild = makeCopyLink('Copy URI', () => child.id);
            copyChild.style.marginLeft = '4px';

            leftWrap.appendChild(s);
            leftWrap.appendChild(copyChild);

            const toggle = document.createElement("span");
            toggle.innerHTML = edgeOn ? eyeOffSVG : eyeSVG;
            toggle.style.cssText = "cursor:pointer; padding:2px 6px; display:inline-block;";

            toggle.addEventListener("click", (ev) => {
              ev.stopPropagation();

              if (!edges.get(desiredEdgeId)) {
                const wasVisible = !!nodes.get(child.id);

                if (!wasVisible) nodes.add(ensureNodeStyle({ ...child }));
                if (!nodes.get(nodeId))  nodes.add(ensureNodeStyle({ id: nodeId, label: (nodeId.split('/').pop()||nodeId), shape:'box'}));

                if (!extraEdges.some(x => edgeIdOf(x) === desiredEdgeId)) {
                  extraEdges.push(normalizeEdge({ ...desiredEdge, id: desiredEdgeId }));
                }

                addEdgeVisible({ ...desiredEdge, id: desiredEdgeId });

                // Only position the newly shown node; never reposition existing nodes.
                if (!wasVisible) {
                  freezeAllNodes(nodes);
                  placeByPredicate(network, nodeId, [child.id], label);
                  network.redraw();
                  unfreezeNodes(nodes, [child.id]);
                }

                toggle.innerHTML = eyeOffSVG;
              } else {
                edges.remove(desiredEdgeId);
                const still = edges.get().some(x => x.from === child.id || x.to === child.id);
                if (!still && child.id !== currentRootId) nodes.remove(child.id);
                toggle.innerHTML = eyeSVG;
              }
            });

            row.appendChild(leftWrap);
            row.appendChild(toggle);
            submenu.appendChild(row);
          });

          // Footer / pagination
          const footer = document.createElement('div');
          footer.style.cssText = "display:flex; justify-content:space-between; align-items:center; margin-top:6px; gap:8px;";
          const showPager = (totalFetched > MAX_MEMBERS_PER_SOC) || !!state.hasMoreServer;
          const info = document.createElement('span');
          info.style.cssText = "font-size:12px; opacity:.8;";

          if (showPager) {
            const pageNum = Math.floor(state.offset / MAX_MEMBERS_PER_SOC) + 1;
            const knownTotal = (typeof state.totalGuess === 'number') ? state.totalGuess : null;
            const totalPagesKnown = knownTotal ? Math.max(1, Math.ceil(knownTotal / MAX_MEMBERS_PER_SOC)) : null;
            const totalPagesTxt = totalPagesKnown ?? (state.hasMoreServer ? '…' : Math.max(1, Math.ceil(totalFetched / MAX_MEMBERS_PER_SOC)));
            const totalCountTxt = knownTotal ?? (totalFetched + (state.hasMoreServer ? '+' : ''));

            info.textContent = `Page ${pageNum} / ${totalPagesTxt} - showing ${page.length} of ${totalCountTxt}`;

            const left = document.createElement('button');
            left.type = 'button'; left.className = 'btn btn-sm btn-light';
            left.textContent = '«'; left.disabled = (state.offset <= 0);
            left.onclick = (e3) => { e3.stopPropagation(); state.offset = Math.max(0, state.offset - MAX_MEMBERS_PER_SOC); renderPage(); };

            const right = document.createElement('button');
            right.type = 'button'; right.className = 'btn btn-sm btn-light'; right.textContent = '»';
            const canAdvanceCached = (state.offset + MAX_MEMBERS_PER_SOC) < totalFetched;
            const canFetchMore = !!state.hasMoreServer;
            right.disabled = !canAdvanceCached && !canFetchMore;
            right.onclick = (e3) => {
              e3.stopPropagation();
              if (canAdvanceCached) {
                state.offset += MAX_MEMBERS_PER_SOC;
                renderPage();
              } else if (canFetchMore) {
                fetchMoreForLabel(nodeId, label, state, right, (returned) => {
                  if (returned > 0) state.offset += MAX_MEMBERS_PER_SOC;
                  renderPage();
                });
              }
            };

            footer.append(left, info, right);
          } else {
            info.textContent = (totalFetched === 1)
              ? 'Showing 1 item'
              : `Showing ${totalFetched} items`;
            footer.appendChild(info);
          }

          submenu.appendChild(footer);
          setTimeout(() => updateExpandMenuPosition(nodeId), 0);
        };

        renderPage();
      }

      // ----- Merge payload -----
      function mergeGraphPayload(payload, anchorNodeId) {
        if (!payload) return;
        const newNodes = (payload.nodes || []).map(normalizeNode);
        const newEdges = (payload.edges || []).map(normalizeEdge);

        newNodes.forEach(n => { if (!extraNodes.find(x => x.id === n.id)) extraNodes.push(n); });
        newEdges.forEach(e => {
          const id = edgeIdOf(e);
          if (!extraEdges.some(x => edgeIdOf(x) === id)) {
            extraEdges.push({ ...e, id });
          }
        });

        let autoShown = 0;
        const existing = new Set(nodes.getIds());
        const addedIds = [];
        newNodes.forEach(n => {
          if (AUTO_SHOW_ON_FETCH <= 0) return;
          if (autoShown >= AUTO_SHOW_ON_FETCH) return;
          if (!existing.has(n.id)) {
            if (!canAddMoreVisibleNodes(1)) return;
            nodes.add(ensureNodeStyle({ ...n }));
            addedIds.push(n.id);
            autoShown++;
          }
        });
        newEdges.forEach(e => {
          const id = edgeIdOf(e);
          if (!edges.get(id) && nodes.get(e.from) && nodes.get(e.to)) {
            addEdgeVisible({ ...e, id });
          }
        });

        if (addedIds.length) {
          freezeAllNodes(nodes);
          placeAround(network, anchorNodeId, addedIds);
          network.redraw();
          unfreezeNodes(nodes, addedIds);
        }
      }

      // ---------- Explorer panel (v2 UI) ----------
      const explorerState = {
        currentNodeId: null,
        tab: 'out', // 'out' | 'in'
        outLabel: null,
        inLabel: null,
      };
      const primedIncomingNodes = Object.create(null);

      const nodeInfoCache = Object.create(null);
      const nodeInfoWaiters = Object.create(null);
      const nodeInfoRenderQueued = Object.create(null);

      // Simple modal for long text (e.g., comment).
      let repTextModal = null;
      let repTextModalTitle = null;
      let repTextModalBody = null;
      let repTextModalKeyListenerBound = false;

      function ensureTextModal() {
        if (repTextModal) return;

        repTextModal = document.createElement('div');
        repTextModal.id = 'rep-graph-text-modal';
        repTextModal.style.cssText = [
          'position:fixed',
          'inset:0',
          'z-index:20000',
          'display:none',
          'align-items:center',
          'justify-content:center',
          'background:rgba(0,0,0,0.45)',
          'padding:20px',
        ].join(';');

        const box = document.createElement('div');
        box.style.cssText = [
          'background:#fff',
          'border-radius:10px',
          'max-width:900px',
          'width:90vw',
          'max-height:80vh',
          'overflow:auto',
          'box-shadow:0 10px 30px rgba(0,0,0,0.25)',
          'padding:12px 14px',
        ].join(';');

        const header = document.createElement('div');
        header.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px;';

        repTextModalTitle = document.createElement('div');
        repTextModalTitle.style.cssText = 'font-weight:600; font-size:14px;';

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn btn-sm btn-light';
        closeBtn.textContent = 'Close';
        closeBtn.addEventListener('click', () => { repTextModal.style.display = 'none'; });

        header.appendChild(repTextModalTitle);
        header.appendChild(closeBtn);

        repTextModalBody = document.createElement('div');
        repTextModalBody.style.cssText = 'white-space:pre-wrap; word-break:break-word; font-size:13px; line-height:1.35;';

        box.appendChild(header);
        box.appendChild(repTextModalBody);
        repTextModal.appendChild(box);
        document.body.appendChild(repTextModal);

        repTextModal.addEventListener('click', (ev) => {
          if (ev.target === repTextModal) {
            repTextModal.style.display = 'none';
          }
        });

        if (!repTextModalKeyListenerBound) {
          repTextModalKeyListenerBound = true;
          document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape' && repTextModal && repTextModal.style.display !== 'none') {
              repTextModal.style.display = 'none';
            }
          });
        }
      }

      function openTextModal(title, text) {
        ensureTextModal();
        if (!repTextModal) return;
        repTextModalTitle.textContent = String(title || 'Details');
        repTextModalBody.textContent = String(text || '');
        repTextModal.style.display = 'flex';
      }

      function ensureNodeInfo(nodeId, done) {
        const id = expandCurie(nodeId);

        if (!nodeInfoEndpoint) {
          if (done) done(null);
          return;
        }

        if (Object.prototype.hasOwnProperty.call(nodeInfoCache, id)) {
          if (done) done(nodeInfoCache[id]);
          return;
        }

        if (Array.isArray(nodeInfoWaiters[id])) {
          nodeInfoWaiters[id].push(done);
          return;
        }

        nodeInfoWaiters[id] = [done];

        $.getJSON(nodeInfoEndpoint, { uri: id })
          .done((data) => { nodeInfoCache[id] = data || null; })
          .fail(() => { nodeInfoCache[id] = null; })
          .always(() => {
            const q = nodeInfoWaiters[id] || [];
            delete nodeInfoWaiters[id];
            q.forEach((fn) => {
              try { if (fn) fn(nodeInfoCache[id]); } catch (e) {}
            });
          });
      }

      function setExplorerStatus(nodeId, text) {
        if (!explorer) return;
        const el = explorer.querySelector('#rep-graph-status');
        if (!el) return;
        if (el.getAttribute('data-node-id') !== nodeId) return;
        el.textContent = String(text || '');
      }

      function renderNodeInfoBox(box, nodeId, fallbackNode) {
        if (!box) return;

        const rebuild = (info, statusText) => {
          if (!box.isConnected) return;
          box.setAttribute('data-node-id', nodeId);
          box.innerHTML = '';

          const status = document.createElement('div');
          status.id = 'rep-graph-status';
          status.setAttribute('data-node-id', nodeId);
          status.style.cssText = 'font-size:12px; opacity:.75; margin-bottom:6px;';
          status.textContent = String(statusText || '');
          box.appendChild(status);

          const addRow = (k, v, copyVal = null) => {
            if (v === null || typeof v === 'undefined' || v === '') return;

            // Respect hidden predicates also in the node details panel.
            const keyRaw = String(k || '').trim();
            const keyNorm = normalizePredKey(keyRaw);
            if (keyNorm && hiddenPredicateKeys.has(keyNorm)) return;

            // Avoid duplicates: if we already show the normalized "super" row, do not
            // also show superUri/superURI variants as plain fields.
            if (keyNorm === 'super' && keyRaw !== 'super' && info?.superUri) return;

            const row = document.createElement('div');
            row.style.cssText = 'display:flex; gap:10px; align-items:flex-start; font-size:12px; padding:2px 0;';

            const kk = document.createElement('div');
            kk.style.cssText = 'flex:0 0 130px; opacity:.75;';
            kk.textContent = keyRaw;

            const vv = document.createElement('div');
            vv.style.cssText = 'flex:1 1 auto; word-break:break-all;';
            vv.textContent = String(v);

            // Clamp long comments in the panel (2-3 lines) and allow opening full text in a modal.
            if (keyNorm === 'comment') {
              vv.style.cssText = [
                'flex:1 1 auto',
                'word-break:break-word',
                'white-space:normal',
                'overflow:hidden',
                'display:-webkit-box',
                '-webkit-line-clamp:3',
                '-webkit-box-orient:vertical',
                'max-height:4.6em',
              ].join(';');
            }

            row.appendChild(kk);
            row.appendChild(vv);

            if (copyVal) {
              const raw = String(copyVal || '');
              const c = makeCopyLink('Copy', () => raw);
              c.style.marginLeft = '6px';
              row.appendChild(c);

              // Open links in a new tab (direct for http(s) URLs; otherwise open the REP page).
              const isHttp = /^https?:\/\//i.test(raw);
              const kLower = keyRaw.toLowerCase();
              const preferDirect = isHttp && (
                kLower.includes('webdocument') ||
                kLower.includes('image') ||
                kLower.endsWith('url') ||
                kLower === 'url'
              );
              const href = preferDirect ? raw : buildLocalUriLink(raw);
              if (href) {
                const a = document.createElement('a');
                a.href = href;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = 'Open';
                a.style.cssText = 'margin-left:6px; font-size:12px; color:#007bff; white-space:nowrap;';
                row.appendChild(a);
              }
            }

            if (keyNorm === 'comment') {
              const full = String(v || '');
              if (full.length > 260) {
                const more = document.createElement('span');
                more.textContent = 'View full';
                more.style.cssText = 'margin-left:6px; cursor:pointer; font-size:12px; color:#007bff; white-space:nowrap;';
                more.addEventListener('click', (ev2) => {
                  ev2.preventDefault();
                  ev2.stopPropagation();
                  openTextModal('comment', full);
                });
                row.appendChild(more);
              }
            }

            box.appendChild(row);
          };

          const hascoType = info?.hascoTypeUri || fallbackNode?.hascoTypeUri || null;
          const typeUri   = info?.typeUri || fallbackNode?.typeUri || null;

          if (hascoType) addRow('hascoTypeUri', hascoType, hascoType);
          if (typeUri && typeUri !== hascoType) addRow('typeUri', typeUri, typeUri);

          if (info?.superUri) addRow('super', info.superUri, info.superUri);

          const fields = Array.isArray(info?.fields) ? info.fields : [];
          fields.slice(0, 10).forEach((f) => {
            if (f && f.key) addRow(f.key, f.value);
          });

          const lists = Array.isArray(info?.lists) ? info.lists : [];
          lists.slice(0, 8).forEach((it) => {
            if (it && it.key && typeof it.count !== 'undefined') addRow(it.key, String(it.count));
          });

          const links = Array.isArray(info?.links) ? info.links : [];
          links.slice(0, 6).forEach((lnk) => {
            if (lnk && lnk.key && lnk.uri) {
              addRow(lnk.key, lnk.label || lnk.uri, lnk.uri);
            }
          });
        };

        if (!nodeInfoEndpoint) {
          rebuild(null, 'Node details endpoint not configured.');
          return;
        }

        // Quick paint using whatever we already know.
        const canonicalId = expandCurie(nodeId);
        const cached = Object.prototype.hasOwnProperty.call(nodeInfoCache, canonicalId) ? nodeInfoCache[canonicalId] : null;
        if (cached) {
          rebuild(cached, '');
          return;
        }

        rebuild(null, 'Loading node details…');
        ensureNodeInfo(nodeId, (info) => {
          if (!box.isConnected) return;
          if (box.getAttribute('data-node-id') !== nodeId) return;
          rebuild(info, info ? '' : 'Failed to load node details.');
        });
      }

      function primeIncomingOnce(nodeId, done) {
        if (!explorer || !socEndpoint) {
          if (done) done();
          return;
        }
        if (primedIncomingNodes[nodeId] === true) {
          if (done) done();
          return;
        }
        if (primedIncomingNodes[nodeId] === 'pending') {
          // avoid duplicate inflight calls; poll once
          window.setTimeout(() => { if (done) done(); }, 150);
          return;
        }
        primedIncomingNodes[nodeId] = 'pending';
        $.getJSON(socEndpoint, { from: nodeId, direction: 'in', limit: PAGE_SIZE, offset: 0, debug: 1 })
          .done(data => mergeGraphPayload(data, nodeId))
          .always(() => { primedIncomingNodes[nodeId] = true; if (done) done(); });
      }

      function itemsForIncomingLabel(nodeId, label) {
        return extraEdges
          .filter(e => e.to === nodeId && e.label === label)
          .map(e => ({ edge: { ...e }, id: edgeIdOf(e) }));
      }

      function inferIncomingPredUri(nodeId, label) {
        const one = extraEdges.find(e => e.to === nodeId && e.label === label && e.predUri);
        return one ? one.predUri : null;
      }

      function fetchMoreIncomingForLabel(nodeId, label, state, btn, after) {
        if (!socEndpoint) return;

        const params = {
          from: nodeId,
          direction: 'in',
          limit: PAGE_SIZE,
          offset: state.fetched || 0,
          debug: 1,
        };
        if (state.predUri) params.predUri = state.predUri;
        else params.label = label;

        const prev = btn.textContent;
        btn.disabled = true; btn.textContent = '…';

        const finish = (returned) => {
          state.fetched = (state.fetched || 0) + returned;
          state.hasMoreServer = returned === PAGE_SIZE;
          if (returned === 0) state.exhausted = true;
          btn.textContent = prev; btn.disabled = false;
          if (after) after(returned);
        };

        $.getJSON(socEndpoint, params)
          .done(data => {
            const slim = slimPayloadForLabel(data, nodeId, label, PAGE_SIZE, 'in', state.predUri || null);
            if (!state.predUri && slim.edges && slim.edges.length && slim.edges[0].predUri) {
              state.predUri = slim.edges[0].predUri;
            }
            mergeGraphPayload(slim, nodeId);
            finish((slim.edges || []).length);
          })
          .fail(() => finish(0));
      }

      function ensureExtraNodeById(id, opts = {}) {
        const nid = expandCurie(id);
        let n = nodes.get(nid) || extraNodes.find(x => x.id === nid);
        if (n) return n;

        const fallbackLabel = opts.label || (nid.split('/').pop() || nid);
        n = normalizeNode({ id: nid, label: fallbackLabel, shape: 'box' });
        if (opts.typeUri) n.typeUri = expandCurie(opts.typeUri);
        if (opts.asClass) n.typeUri = nid;
        extraNodes.push(n);
        return n;
      }

      function removeDanglingNodes(exceptIds = new Set()) {
        const liveEdges = edges.get();
        const connected = new Set();
        liveEdges.forEach(e => { connected.add(e.from); connected.add(e.to); });
        nodes.getIds().forEach(id => {
          if (exceptIds.has(id)) return;
          if (!connected.has(id)) {
            try { nodes.remove(id); } catch (e) {}
          }
        });
      }

      function setEdgeVisible(desiredEdgeRaw, on, anchorId) {
        const desiredEdge = normalizeEdge(desiredEdgeRaw);
        const eid = edgeIdOf(desiredEdge);

        if (on) {
          if (edges.get(eid)) return { changed: false, addedNodeIds: [] };

          const addedNodeIds = [];
          const ensureVisible = (id) => {
            if (!id) return;
            if (nodes.get(id)) return;
            if (!canAddMoreVisibleNodes(1)) { warnNodeCap(); return; }
            const n = ensureExtraNodeById(id);
            nodes.add(ensureNodeStyle({ ...n }));
            addedNodeIds.push(id);
          };

          ensureVisible(desiredEdge.from);
          ensureVisible(desiredEdge.to);

          // Ensure it exists in cache as well
          if (!extraEdges.some(x => edgeIdOf(x) === eid)) {
            extraEdges.push({ ...desiredEdge, id: eid });
          }

          if (nodes.get(desiredEdge.from) && nodes.get(desiredEdge.to)) {
            addEdgeVisible({ ...desiredEdge, id: eid });
          }

          const toPlace = anchorId ? addedNodeIds.filter(id => id !== anchorId) : addedNodeIds;
          if (toPlace.length && anchorId) {
            freezeAllNodes(nodes);
            placeByPredicate(network, anchorId, toPlace, desiredEdge.label);
            network.redraw();
            unfreezeNodes(nodes, toPlace);
          }
          return { changed: true, addedNodeIds };
        }

        // off
        if (!edges.get(eid)) return { changed: false, addedNodeIds: [] };
        edges.remove(eid);
        removeDanglingNodes(new Set([currentRootId]));
        return { changed: true, addedNodeIds: [] };
      }

      function renderExplorer(nodeId, selectedNode) {
        if (!explorer) return;

        nodeId = expandCurie(nodeId);
        explorerState.currentNodeId = nodeId;

        // Fetch node info so users can see which predicates exist without guessing.
        if (nodeInfoEndpoint && !Object.prototype.hasOwnProperty.call(nodeInfoCache, nodeId)) {
          if (!nodeInfoRenderQueued[nodeId]) {
            nodeInfoRenderQueued[nodeId] = true;
            ensureNodeInfo(nodeId, () => {
              nodeInfoRenderQueued[nodeId] = false;
              if (explorerState.currentNodeId === nodeId) {
                renderExplorer(nodeId, selectedNode);
              }
            });
          }
        }

        const cleanTitle = selectedNode?.label
          ? String(selectedNode.label).replace(/\n?➕$/, '')
          : (nodeId.split('/').pop() || nodeId);

        const kindByTypeUri = (typeUri) => {
          if (!typeUri) return 'other';
          if (typeUri.includes('/hasco/Study')) return 'study';
          if (
            typeUri.includes('/hasco/SampleCollection') ||
            typeUri.includes('/hasco/SubjectGroup') ||
            typeUri.includes('/hasco/StudyObjectCollection') ||
            typeUri.includes('/hasco/SpaceCollection') ||
            typeUri.includes('/hasco/TimeCollection')
          ) return 'soc';
          return 'other';
        };
        const kind = kindByTypeUri(selectedNode?.typeUri);

        const renderBody = () => {
          // Tabs
          const tab = explorerState.tab;
          const dir = (tab === 'in') ? 'in' : 'out';
          const info = nodeInfoCache[nodeId] || null;

          // Labels
          let labels = [];
          if (dir === 'out') {
            const related = extraEdges.filter(e => e.from === nodeId);
            const map = buildLabelEdgesMap(related);
            labels = Array.from(map.keys());

            // Add predicates discovered from the node payload so users don't need to guess.
            if (info) {
              if (Array.isArray(info.predicates)) {
                info.predicates.forEach((p) => { if (p && p.key) labels.push(p.key); });
              } else {
                (info.links || []).forEach((lnk) => { if (lnk && lnk.key) labels.push(lnk.key); });
                (info.lists || []).forEach((it) => { if (it && it.key && it.expandable !== false) labels.push(it.key); });
              }
              if (info.superUri) labels.push('super');
            }

            // Always expose both type labels (unless hidden).
            labels = labels.concat(['typeUri', 'hascoTypeUri']);

            labels = labels
              .map(normalizePredForApi)
              .filter(Boolean)
              .filter(isDisplayableLabel);
            labels = Array.from(new Set(labels));

            // Known Study predicates (even if not explicitly present in the payload).
            if (kind === 'study') {
              ['hasVirtualColumn', 'hasSampleCollection', 'hasSubjectCollection', 'hasSpaceCollection', 'hasTimeCollection']
                .forEach((p) => { if (isDisplayableLabel(p) && !labels.includes(p)) labels.push(p); });
            }

            if (kind === 'soc' && !labels.includes('contains') && isDisplayableLabel('contains')) labels.unshift('contains');
            if (isClassNode(selectedNode) && !labels.includes('children') && isDisplayableLabel('children')) labels.unshift('children');
            if (!labels.includes('super') && isDisplayableLabel('super') && (isClassNode(selectedNode) || (info && info.superUri))) labels.unshift('super');
          } else {
            const incoming = extraEdges.filter(e => e.to === nodeId);
            labels = Array.from(new Set(incoming.map(e => normalizePredForApi(e.label)))).filter(isDisplayableLabel);
          }

          // Ensure stable ordering: keep contains/children first if present.
          const preferred = ['contains', 'children', 'super', 'typeUri', 'hascoTypeUri'];
          labels.sort((a, b) => {
            const ia = preferred.indexOf(a);
            const ib = preferred.indexOf(b);
            if (ia !== -1 || ib !== -1) return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
            return String(a).localeCompare(String(b));
          });

          const picked = (dir === 'out') ? explorerState.outLabel : explorerState.inLabel;
          let currentLabel = (picked && labels.includes(picked)) ? picked : (labels[0] || null);
          if (dir === 'out') explorerState.outLabel = currentLabel;
          else explorerState.inLabel = currentLabel;

          // Header
          explorer.innerHTML = '';

          const header = document.createElement('div');
          header.style.cssText = 'display:flex; align-items:flex-start; justify-content:space-between; gap:10px; padding-bottom:8px; border-bottom:1px solid #ddd; margin-bottom:10px;';
          const left = document.createElement('div');
          left.style.cssText = 'min-width:0;';
          const h = document.createElement('div');
          h.textContent = cleanTitle;
          h.style.cssText = 'font-weight:600; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
          h.title = nodeId;
          const sub = document.createElement('div');
          sub.style.cssText = 'font-size:12px; opacity:.8; word-break:break-all;';
          sub.textContent = nodeId;
          left.appendChild(h);
          left.appendChild(sub);

          const actions = document.createElement('div');
          actions.style.cssText = 'display:flex; align-items:center; gap:10px; flex:0 0 auto;';
          const copyNode = makeCopyLink('Copy URI', () => nodeId);
          const makeBase = document.createElement('span');
          makeBase.textContent = 'Set as base';
          makeBase.style.cssText = 'cursor:pointer; font-size:12px; color:#28a745; padding:2px 6px; border-radius:4px;';
          makeBase.title = 'Set this node as the graph base and open its page';
          makeBase.addEventListener('click', (ev) => { ev.stopPropagation(); promoteToRoot(nodeId); });
          actions.appendChild(copyNode);
          actions.appendChild(makeBase);

          header.appendChild(left);
          header.appendChild(actions);
          explorer.appendChild(header);

          // Node details (single click)
          const detailsBox = document.createElement('div');
          detailsBox.id = 'rep-graph-node-details';
          detailsBox.style.cssText = 'border:1px solid #e5e5e5; background:white; border-radius:6px; padding:8px; margin-bottom:10px;';
          explorer.appendChild(detailsBox);
          renderNodeInfoBox(detailsBox, nodeId, selectedNode);

          // Predicates / relationships (collapsible)
          const relPanel = document.createElement('details');
          relPanel.open = true;
          relPanel.style.cssText = 'border:1px solid #e5e5e5; background:white; border-radius:6px; padding:8px; margin-bottom:10px;';

          const relSummary = document.createElement('summary');
          relSummary.style.cssText = 'cursor:pointer; font-weight:600; font-size:13px;';
          relSummary.textContent = 'Predicates';
          relPanel.appendChild(relSummary);

          const relInner = document.createElement('div');
          relInner.style.cssText = 'margin-top:8px; display:flex; flex-direction:column; gap:8px;';
          relPanel.appendChild(relInner);
          explorer.appendChild(relPanel);

          if (!labels || labels.length === 0) {
            const empty = document.createElement('div');
            empty.style.cssText = 'opacity:.75; font-size:13px;';
            empty.textContent = 'No predicates available for this node.';
            relInner.appendChild(empty);
            return;
          }

          const countHintFor = (lbl) => {
            let count = itemsForLabel(nodeId, lbl).length;
            if (count > 0) return count;
            if (!info) return count;

            const normLbl = normalizePredForApi(lbl);

            if (normLbl === 'super' && info.superUri) return 1;

            if (Array.isArray(info.predicates)) {
              const p = info.predicates.find(x => normalizePredForApi(x?.key) === normLbl);
              if (p) {
                if (p.kind === 'link') return 1;
                if (typeof p.count === 'number') return p.count;
              }
            } else {
              const lnk = (info.links || []).find(x => normalizePredForApi(x?.key) === normLbl);
              const lst = (info.lists || []).find(x => normalizePredForApi(x?.key) === normLbl && x?.expandable !== false);
              if (lnk) return 1;
              if (lst && typeof lst.count === 'number') return lst.count;
            }

            return count;
          };

          const renderPredicateBody = (lbl, bodyEl, summaryEl) => {
            if (!bodyEl) return;
            bodyEl.innerHTML = '';

            const list = itemsForLabel(nodeId, lbl);
            const hint = countHintFor(lbl);
            const shown = Math.max(list.length, hint);
            if (summaryEl) summaryEl.textContent = `${lbl} (${shown})`;

            const key = `out:${nodeId}:${lbl}`;
            if (!pageState[key]) {
              pageState[key] = {
                offset: 0,
                fetched: list.length,
                hasMoreServer: true,
                exhausted: false,
                prefetchTried: false,
                triedGeneric: false,
                totalGuess: null,
              };
            }
            const state = pageState[key];
            if (typeof state.offset !== 'number') state.offset = 0;
            if (typeof state.fetched !== 'number') state.fetched = list.length;
            if (state.fetched < list.length) state.fetched = list.length;
            if (hint > list.length) state.hasMoreServer = true;

            const controls = document.createElement('div');
            controls.style.cssText = 'display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:8px;';

            const showBtn = document.createElement('button');
            showBtn.type = 'button';
            showBtn.className = 'btn btn-sm btn-light';
            showBtn.textContent = 'Show all';

            const hideBtn = document.createElement('button');
            hideBtn.type = 'button';
            hideBtn.className = 'btn btn-sm btn-light';
            hideBtn.textContent = 'Hide all';

            const loadBtn = document.createElement('button');
            loadBtn.type = 'button';
            loadBtn.className = 'btn btn-sm btn-light';
            loadBtn.textContent = (list.length === 0) ? 'Load' : 'Load more';

            controls.appendChild(showBtn);
            controls.appendChild(hideBtn);

            const canLoad = !!socEndpoint && !state.exhausted && (state.hasMoreServer !== false || list.length === 0);
            if (canLoad) {
              controls.appendChild(loadBtn);
            }

            bodyEl.appendChild(controls);

            // Prefetch on first open if empty.
            if (list.length === 0 && canLoad && !state.prefetchTried) {
              state.prefetchTried = true;
              const loading = document.createElement('div');
              loading.style.cssText = 'padding:6px 4px; opacity:.7;';
              loading.textContent = 'Loading…';
              bodyEl.appendChild(loading);
              fetchMoreForLabel(nodeId, lbl, state, loadBtn, () => {
                renderPredicateBody(lbl, bodyEl, summaryEl);
              });
              return;
            }

            // Handlers
            showBtn.addEventListener('click', (ev) => {
              ev.preventDefault();

              const itemsAll = itemsForLabel(nodeId, lbl);
              if (!itemsAll.length) return;

              let warned = false;
              const addedNodeIds = [];

              // Ensure anchor exists.
              if (!nodes.get(nodeId)) {
                nodes.add(ensureNodeStyle({ id: nodeId, label: (nodeId.split('/').pop()||nodeId), shape:'box'}));
              }

              for (const { edge: e } of itemsAll) {
                const otherId = e.to;
                const other = ensureExtraNodeById(otherId, { asClass: (lbl === 'typeUri' || lbl === 'hascoTypeUri') });

                if (!nodes.get(other.id)) {
                  if (!canAddMoreVisibleNodes(1)) {
                    if (!warned) { warned = true; warnNodeCap(); }
                    break;
                  }
                  nodes.add(ensureNodeStyle({ ...other }));
                  addedNodeIds.push(other.id);
                }

                const desired = { from: nodeId, to: other.id, label: lbl };
                if (lbl === 'contains') desired.label = 'contains';
                if (lbl === 'typeUri' || lbl === 'hascoTypeUri') desired.to = other.id;

                addEdgeVisible(desired);
              }

              if (addedNodeIds.length) {
                placeByPredicate(network, nodeId, Array.from(new Set(addedNodeIds)), lbl);
              }

              network.redraw();
              renderPredicateBody(lbl, bodyEl, summaryEl);
            });

            hideBtn.addEventListener('click', (ev) => {
              ev.preventDefault();
              const itemsAll = itemsForLabel(nodeId, lbl);
              const idsToRemove = [];
              itemsAll.forEach(({ edge: e }) => {
                const desired = { from: nodeId, to: e.to, label: lbl };
                if (lbl === 'contains') desired.label = 'contains';
                const id = edgeIdOf(desired);
                if (edges.get(id)) idsToRemove.push(id);
              });
              if (idsToRemove.length) {
                edges.remove(idsToRemove);
                removeDanglingNodes(new Set([currentRootId, nodeId]));
                network.redraw();
              }
              renderPredicateBody(lbl, bodyEl, summaryEl);
            });

            if (canLoad) {
              loadBtn.disabled = state.hasMoreServer === false;
              loadBtn.style.display = loadBtn.disabled ? 'none' : '';
              loadBtn.addEventListener('click', (ev) => {
                ev.preventDefault();
                fetchMoreForLabel(nodeId, lbl, state, loadBtn, () => {
                  renderPredicateBody(lbl, bodyEl, summaryEl);
                });
              });
            }

            // Items list (paged)
            const totalFetched = list.length;
            const start = Math.max(0, Math.min(state.offset, Math.max(0, totalFetched - MAX_MEMBERS_PER_SOC)));
            const end = Math.min(start + MAX_MEMBERS_PER_SOC, totalFetched);
            const page = list.slice(start, end);

            const listWrap = document.createElement('div');
            listWrap.style.cssText = 'display:flex; flex-direction:column; gap:6px;';

            if (!page.length) {
              const none = document.createElement('div');
              none.style.cssText = 'opacity:.75; font-size:13px;';
              none.textContent = 'No items.';
              listWrap.appendChild(none);
            } else {
              page.forEach(({ edge: e }) => {
                const otherId = e.to;
                let other = extraNodes.find(n => n.id === otherId) || nodes.get(otherId);
                if (!other) {
                  other = ensureExtraNodeById(otherId, { asClass: (lbl === 'typeUri' || lbl === 'hascoTypeUri') });
                }

                const displayLabel = (other.label && String(other.label).trim())
                  ? String(other.label).replace(/\n?➕$/, '')
                  : (other.id?.split('/').pop() || other.id || '(no label)');

                const desired = { from: nodeId, to: other.id, label: lbl };
                if (lbl === 'contains') desired.label = 'contains';
                const desiredId = edgeIdOf(desired);
                const edgeOn = !!edges.get(desiredId);

                const row = document.createElement('div');
                row.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:10px; padding:4px 6px; border:1px solid #e5e5e5; border-radius:6px; background:white;';

                const left = document.createElement('div');
                left.style.cssText = 'min-width:0; display:flex; align-items:center; gap:8px; flex:1 1 auto;';

                const name = document.createElement('div');
                name.textContent = displayLabel;
                name.style.cssText = 'white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
                name.title = other.id;

                const copy = makeCopyLink('Copy URI', () => other.id);

                left.appendChild(name);
                left.appendChild(copy);

                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'btn btn-sm btn-light';
                toggle.innerHTML = edgeOn ? eyeOffSVG : eyeSVG;
                toggle.addEventListener('click', (ev) => {
                  ev.preventDefault();
                  ev.stopPropagation();
                  setEdgeVisible(desired, !edges.get(desiredId), nodeId);
                  renderPredicateBody(lbl, bodyEl, summaryEl);
                });

                row.appendChild(left);
                row.appendChild(toggle);
                listWrap.appendChild(row);
              });
            }

            bodyEl.appendChild(listWrap);

            // Pager
            const footer = document.createElement('div');
            footer.style.cssText = 'display:flex; justify-content:space-between; align-items:center; margin-top:8px; gap:8px;';

            const leftBtn = document.createElement('button');
            leftBtn.type = 'button';
            leftBtn.className = 'btn btn-sm btn-light';
            leftBtn.textContent = '«';
            leftBtn.disabled = (state.offset <= 0);
            leftBtn.addEventListener('click', (ev) => {
              ev.preventDefault();
              state.offset = Math.max(0, state.offset - MAX_MEMBERS_PER_SOC);
              renderPredicateBody(lbl, bodyEl, summaryEl);
            });

            const infoTxt = document.createElement('span');
            infoTxt.style.cssText = 'font-size:12px; opacity:.8;';
            const pageNum = Math.floor(start / MAX_MEMBERS_PER_SOC) + 1;
            const totalPages = Math.max(1, Math.ceil(Math.max(totalFetched, shown) / MAX_MEMBERS_PER_SOC));
            infoTxt.textContent = `Page ${pageNum} / ${totalPages}`;

            const rightBtn = document.createElement('button');
            rightBtn.type = 'button';
            rightBtn.className = 'btn btn-sm btn-light';
            rightBtn.textContent = '»';

            const canAdvanceCached = (state.offset + MAX_MEMBERS_PER_SOC) < totalFetched;
            const canFetchMore = canLoad && state.hasMoreServer !== false;
            rightBtn.disabled = !canAdvanceCached && !canFetchMore;
            rightBtn.addEventListener('click', (ev) => {
              ev.preventDefault();
              if (canAdvanceCached) {
                state.offset += MAX_MEMBERS_PER_SOC;
                renderPredicateBody(lbl, bodyEl, summaryEl);
              } else if (canFetchMore) {
                fetchMoreForLabel(nodeId, lbl, state, rightBtn, (returned) => {
                  if (returned > 0) {
                    state.offset += MAX_MEMBERS_PER_SOC;
                  }
                  renderPredicateBody(lbl, bodyEl, summaryEl);
                });
              }
            });

            footer.appendChild(leftBtn);
            footer.appendChild(infoTxt);
            footer.appendChild(rightBtn);
            bodyEl.appendChild(footer);
          };

          const predList = document.createElement('div');
          predList.style.cssText = 'display:flex; flex-direction:column; gap:6px;';
          relInner.appendChild(predList);

          labels.forEach((lbl, idx) => {
            const det = document.createElement('details');
            det.open = (idx === 0);
            det.style.cssText = 'border:1px solid #eee; border-radius:6px; padding:6px; background:#f8f9fa;';

            const sum = document.createElement('summary');
            sum.style.cssText = 'cursor:pointer; font-size:13px;';
            sum.textContent = `${lbl} (${countHintFor(lbl)})`;
            det.appendChild(sum);

            const body = document.createElement('div');
            body.style.cssText = 'margin-top:8px;';
            det.appendChild(body);

            det.addEventListener('toggle', () => {
              if (det.open) {
                renderPredicateBody(lbl, body, sum);
              }
            });

            predList.appendChild(det);

            if (det.open) {
              renderPredicateBody(lbl, body, sum);
            }
          });
        };

        renderBody();
      }

      // ----- Snapshot & Navigation helpers -----
      function snapshotGraph(targetIri) {
        try {
          const snap = {
            version: 1,
            currentRootId,
            targetIri: targetIri || null,
            nodes: nodes.get().map(n => ({ ...n })),   // shallow copy
            edges: edges.get().map(e => ({ ...e })),
            extraNodes: extraNodes.map(n => ({ ...n })),
            extraEdges: extraEdges.map(e => ({ ...e }))
          };
          sessionStorage.setItem('repGraphState', JSON.stringify(snap));
        } catch (e) {
          // If storage fails we simply won't persist, but we still navigate.
        }
      }

      // ----- Promote to ROOT (Make it base) -----
      function promoteToRoot(newRootId) {
        if (!newRootId || newRootId === currentRootId) return;
        if (currentRootId && nodes.get(currentRootId)) applyRootStyle(currentRootId, false);
        currentRootId = newRootId;
        if (nodes.get(currentRootId)) applyRootStyle(currentRootId, true);

        // Smooth focus just for feedback
        try {
          const pos = network.getPositions([currentRootId])[currentRootId];
          if (pos) network.focus(currentRootId, { scale: 1.0, animation: { duration: 250, easingFunction: 'easeInOutQuad' } });
        } catch (e) {}

        // Build link (local router), snapshot graph, and navigate for real
        const link = buildLocalUriLink(currentRootId);
        snapshotGraph(currentRootId);
        window.location.assign(link);
      }

      // ----- Click handler -----
      network.on("click", function (params) {
        expandMenu.style.display = "none";
        closeAllSubmenus();
        if (params.nodes.length === 0) return;

        const selectedNodeId = params.nodes[0];
        const selectedNode   = nodes.get(selectedNodeId) || extraNodes.find(n => n.id === selectedNodeId);
        if (!selectedNode) return;

        seedTypeEdgesForNode(selectedNode);

        function nodeKindByTypeUri(typeUri) {
          if (!typeUri) return 'other';
          if (typeUri.includes('/hasco/Study')) return 'study';
          if (
            typeUri.includes('/hasco/SampleCollection') ||
            typeUri.includes('/hasco/SubjectGroup') ||
            typeUri.includes('/hasco/StudyObjectCollection') ||
            typeUri.includes('/hasco/SpaceCollection') ||
            typeUri.includes('/hasco/TimeCollection')
          ) return 'soc';
          return 'other';
        }
        const kind = nodeKindByTypeUri(selectedNode?.typeUri);

        const hasContains =
          extraEdges.some(e => e.from === selectedNodeId && isMemberLabel(e.label)) ||
          extraEdges.some(e => e.to   === selectedNodeId && isReverseMemberLabel(e.label));
        if (hasContains) synthesizeContainsFromReverse(selectedNodeId);

        // Lazy loading happens on double-click (single click only shows details/UI).

        // Build labels from cached edges
        const relatedEdges = extraEdges.filter(e => e.from === selectedNodeId);
        const labelEdgesMap = buildLabelEdgesMap(relatedEdges);
        let labels = Array.from(labelEdgesMap.keys()).filter(isDisplayableLabel);

        // Always expose both type labels (unless hidden).
        labels = Array.from(new Set(labels.concat(['typeUri', 'hascoTypeUri']))).filter(isDisplayableLabel);

        if (kind === 'soc' && !labels.includes('contains') && isDisplayableLabel('contains')) labels.unshift('contains');
        if (isClassNode(selectedNode) && !labels.includes('children') && isDisplayableLabel('children')) labels.unshift('children');
        if (isClassNode(selectedNode) && !labels.includes('super') && isDisplayableLabel('super')) labels.unshift('super');

        // Keep a stable ordering.
        const preferred = ['contains', 'children', 'super', 'typeUri', 'hascoTypeUri'];
        labels.sort((a, b) => {
          const ia = preferred.indexOf(a);
          const ib = preferred.indexOf(b);
          if (ia !== -1 || ib !== -1) return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
          return String(a).localeCompare(String(b));
        });

        // Prefer the fixed explorer panel when present.
        if (explorer) {
          renderExplorer(selectedNodeId, selectedNode);
          return;
        }

        // Render menu
        expandMenu.innerHTML = '';
        closeAllSubmenus();

        // Header with node label (left) + actions (right)
        const header = document.createElement('div');
        header.style.cssText = `
          display:flex; align-items:center; justify-content:space-between;
          gap:8px; padding:2px 0 6px 0; border-bottom:1px dashed #ddd; margin-bottom:6px;
        `;
        const title = document.createElement('div');
        title.textContent = selectedNode.label ? String(selectedNode.label).replace(/\n?➕$/, '') : (selectedNode.id.split('/').pop() || selectedNode.id);
        title.style.cssText = 'font-weight:600; max-width:230px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
        title.title = selectedNode.id;

        const actions = document.createElement('div');
        actions.style.cssText = 'display:flex; align-items:center; gap:10px;';

        const copyNode = makeCopyLink('Copy URI', () => selectedNode.id);
        copyNode.style.alignSelf = 'flex-start';

        // "Make it base" -> promote to root and navigate, restoring the graph on next page
        const makeBold = document.createElement('span');
        makeBold.textContent = 'Set as base';
        makeBold.style.cssText = 'cursor:pointer; font-size:12px; color:#28a745; padding:2px 6px; border-radius:4px;';
        makeBold.title = 'Set this node as the graph base and open its page';
        makeBold.addEventListener('click', (ev) => {
          ev.stopPropagation();
          promoteToRoot(selectedNodeId);
        });

        actions.appendChild(copyNode);
        actions.appendChild(makeBold);

        header.appendChild(title);
        header.appendChild(actions);
        expandMenu.appendChild(header);

        labels.forEach(label => {
          const opt = document.createElement("div");
          opt.style.cssText = `
            cursor: pointer; margin: 2px 0; position: relative;
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; min-width: 260px;
          `;
          const labelSpan = document.createElement("span");
          labelSpan.textContent = label;
          const eyeIcon = document.createElement("span");
          eyeIcon.innerHTML = eyeSVG;

          opt.appendChild(labelSpan);
          opt.appendChild(eyeIcon);

          opt.addEventListener("click", (ev) => {
            ev.stopPropagation();
            openLabelSubmenu(opt, selectedNodeId, label);
            setTimeout(() => updateExpandMenuPosition(selectedNodeId), 0);
          });

          expandMenu.appendChild(opt);
        });

        expandMenu.style.display = "block";
        setTimeout(() => updateExpandMenuPosition(selectedNodeId), 0);
      });

      // ----- Double-click: prefetch relationships (expand) -----
      network.on("doubleClick", function (params) {
        expandMenu.style.display = "none";
        closeAllSubmenus();
        if (!params.nodes || params.nodes.length === 0) return;

        const nodeId = params.nodes[0];
        const node   = nodes.get(nodeId) || extraNodes.find(n => n.id === nodeId);
        if (!node) return;

        seedTypeEdgesForNode(node);

        const kindByTypeUri = (typeUri) => {
          if (!typeUri) return 'other';
          if (typeUri.includes('/hasco/Study')) return 'study';
          if (
            typeUri.includes('/hasco/SampleCollection') ||
            typeUri.includes('/hasco/SubjectGroup') ||
            typeUri.includes('/hasco/StudyObjectCollection') ||
            typeUri.includes('/hasco/SpaceCollection') ||
            typeUri.includes('/hasco/TimeCollection')
          ) return 'soc';
          return 'other';
        };

        // Pick predicates to prefetch.
        let labels = (autoExpandPredicates || [])
          .map(normalizePredForApi)
          .filter((l) => l && !hiddenPredicateKeys.has(normalizePredKey(l)));

        if (!labels.length) {
          const kind = kindByTypeUri(node?.typeUri);
          if (isClassNode(node)) {
            labels = ['children', 'super'].filter(isDisplayableLabel);
          } else if (kind === 'soc') {
            labels = ['contains'].filter(isDisplayableLabel);
          }
        }

        // Safety cap: avoid spamming too many HTTP calls per double-click.
        labels = labels.slice(0, 6);

        // Ensure the explorer is visible/up-to-date while we load.
        if (explorer) {
          renderExplorer(nodeId, node);
          setExplorerStatus(nodeId, 'Loading relationships…');
        }

        const prefetchLabel = (lbl) => new Promise((resolve) => {
          if (!socEndpoint) return resolve(0);

          const key = `out:${nodeId}:${lbl}`;
          if (!pageState[key]) {
            pageState[key] = {
              fetched: itemsForLabel(nodeId, lbl).length,
              hasMoreServer: true,
              exhausted: false,
              prefetchTried: false,
              triedGeneric: false,
            };
          }
          const state = pageState[key];

          const dummyBtn = { textContent: '»', disabled: false };
          fetchMoreForLabel(nodeId, lbl, state, dummyBtn, (returned) => resolve(returned));
        });

        const prefetchGenericOnce = () => new Promise((resolve) => {
          if (!socEndpoint) return resolve(0);
          if (primedNodes[nodeId]) return resolve(0);
          primedNodes[nodeId] = true;
          $.getJSON(socEndpoint, { from: nodeId, limit: PAGE_SIZE, offset: 0, debug: 1 })
            .done(data => mergeGraphPayload(data, nodeId))
            .always(() => resolve(1));
        });

        const tasks = labels.length ? labels : [null];
        let chain = Promise.resolve();
        tasks.forEach((lbl) => {
          chain = chain.then(() => {
            if (!lbl) return prefetchGenericOnce();
            return prefetchLabel(lbl);
          });
        });

        chain
          .then(() => { if (explorer) renderExplorer(nodeId, node); })
          .catch(() => {})
          .then(() => { if (explorer) setExplorerStatus(nodeId, ''); });
      });

      // Keep menu positioned while dragging / drawing
      network.on("dragEnd", params => {
        if (params && Array.isArray(params.nodes) && params.nodes.length) {
          params.nodes.forEach((id) => pinnedNodes.add(id));
        }

        if (expandMenu.style.display === "block" && params.nodes?.length) {
          setTimeout(() => updateExpandMenuPosition(params.nodes[0]), 0);
        }
      });
      network.on("afterDrawing", () => {
        if (expandMenu.style.display === "block") {
          const sel = network.getSelectedNodes();
          if (sel && sel.length) setTimeout(() => updateExpandMenuPosition(sel[0]), 0);
        }
      });

      // Auto-open first node (or restored root) and highlight it
      if (currentRootId) applyRootStyle(currentRootId, true);
      const toSelect = currentRootId || firstNodeId;
      if (toSelect) {
        network.selectNodes([toSelect]);
        network.once("afterDrawing", () => { if (network.emit) network.emit("click", { nodes: [toSelect] }); });
      }

      // ---------- External toggles (.graph-toggle) ----------
      document.body.addEventListener("click", function (event) {
        const toggleWrapper = event.target.closest(".graph-toggle");
        if (!toggleWrapper) return;

        const nodeId = expandCurie(toggleWrapper.getAttribute("data-node") || "");
        if (!nodeId) return;

        const onlyLabel = (toggleWrapper.getAttribute("data-label") || "").trim();  // e.g. "typeUri" | "hascoTypeUri"
        const fromIdRaw = toggleWrapper.getAttribute("data-from") || "";
        const fromId = fromIdRaw ? expandCurie(fromIdRaw) : "";

        function ensureVisibleNode(id) {
          if (!id) return;
          if (nodes.get(id)) return;
          let n = extraNodes.find(x => x.id === id);
          if (!n) {
            n = normalizeNode({ id, label: (id.split('/').pop() || id), shape: 'box' });
            extraNodes.push(n);
          }
          if (!canAddMoreVisibleNodes(1)) return;
          nodes.add(ensureNodeStyle({ ...n }));
        }

        const isActive = (() => {
          if (onlyLabel) {
            if (fromId) {
              return edges.get().some(ed => ed.from === fromId && ed.to === nodeId && ed.label === onlyLabel);
            }
            return edges.get().some(ed => (ed.from === nodeId || ed.to === nodeId) && ed.label === onlyLabel);
          }
          return !!nodes.get(nodeId);
        })();

        if (!isActive) {
          if (!canAddMoreVisibleNodes(1)) { warnNodeCap(); return; }
          ensureVisibleNode(nodeId);
          if (fromId) ensureVisibleNode(fromId);

          const related = extraEdges
            .filter(e => {
              if (onlyLabel) {
                const want = normalizePredForApi(onlyLabel);
                if (fromId) return e.from === fromId && e.to === nodeId && normalizePredForApi(e.label) === want;
                return (e.from === nodeId || e.to === nodeId) && normalizePredForApi(e.label) === want;
              }
              return (e.to === nodeId || e.from === nodeId);
            });

          related.forEach(e => {
            ensureVisibleNode(e.from);
            ensureVisibleNode(e.to);
            const id = edgeIdOf(e);
            if (!edges.get(id) && nodes.get(e.from) && nodes.get(e.to)) addEdgeVisible({ ...e, id });
          });

          toggleWrapper.innerHTML = eyeOffSVG;
        } else {
          if (onlyLabel) {
            const removed = [];
            edges.get().forEach(ed => {
              const match = fromId
                ? (ed.from === fromId && ed.to === nodeId && ed.label === onlyLabel)
                : ((ed.from === nodeId || ed.to === nodeId) && ed.label === onlyLabel);
              if (match) removed.push(ed);
            });
            removed.forEach(ed => edges.remove(ed.id));

            const candidates = new Set();
            removed.forEach(ed => { candidates.add(ed.from); candidates.add(ed.to); });
            candidates.forEach(nid => {
              if (nid === currentRootId) return;
              if (!nodes.get(nid)) return;
              const hasAny = edges.get().some(ed => ed.from === nid || ed.to === nid);
              if (!hasAny) nodes.remove(nid);
            });
          } else {
            if (nodeId === currentRootId) {
              const ids = edges.get().filter(ed => ed.from === nodeId || ed.to === nodeId).map(ed => ed.id);
              edges.remove(ids);
            } else {
              const neighbors = edges.get().filter(ed => ed.from === nodeId || ed.to === nodeId)
                .map(ed => (ed.from === nodeId ? ed.to : ed.from));
              const ids = edges.get().filter(ed => ed.from === nodeId || ed.to === nodeId).map(ed => ed.id);
              edges.remove(ids);
              if (nodes.get(nodeId)) nodes.remove(nodeId);
              neighbors.forEach(nid => {
                if (nid === currentRootId) return;
                const still = edges.get().some(ed => ed.from === nid || ed.to === nid);
                if (!still && nodes.get(nid)) nodes.remove(nid);
              });
            }
          }

          toggleWrapper.innerHTML = eyeSVG;
        }
      });

      // Close submenus / shortcuts
      document.addEventListener("click", function (e) {
        if (!expandMenu.contains(e.target)) closeAllSubmenus();
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllSubmenus(); });

      // If we restored from session, clear the one-shot snapshot
      if (restore) {
        try { sessionStorage.removeItem('repGraphState'); } catch (e) {}
      }

      // Debug globals
      window.graphNodes = nodes;
      window.graphEdges = edges;
      window.extraGraphNodes = extraNodes;
      window.extraGraphEdges = extraEdges;
      window.repPromoteToRoot = promoteToRoot; // handy for manual tests
    }
  };
})(jQuery, Drupal, drupalSettings);
