/**
 * rep/sigma_graph_panel : Experimental Sigma.js (WebGL) graph viewer
 *
 * Goals (POC):
 * - Smooth pan/zoom for very large graphs (WebGL).
 * - Single click: show node details in the right panel (via /rep/graph/node).
 * - Double click: expand 1 hop (via /rep/graph/expand) for a limited set of predicates.
 * - No wheel-zoom (zoom only via +/- buttons).
 * - Right panel can collapse; fullscreen toggle.
 */

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.repSigmaGraphInit = {
    attach: function (context) {
      const container = context.querySelector('#my-network');
      if (!container || container.dataset.sigmaLoaded === 'true') return;
      if (typeof Sigma === 'undefined' || typeof graphology === 'undefined') return;

      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
      };
      if (!isVisible(container)) return;

      container.dataset.sigmaLoaded = 'true';

      const shell = container.closest('.rep-graph-shell');
      const explorer = shell ? shell.querySelector('#rep-graph-explorer') : null;
      if (shell && !shell.style.position) shell.style.position = 'relative';

      const socEndpoint =
        (drupalSettings && drupalSettings.rep && drupalSettings.rep.socObjectsEndpoint) ||
        (window.Drupal && Drupal.url ? Drupal.url('rep/graph/expand') : '/rep/graph/expand');

      const nodeInfoEndpoint =
        (drupalSettings && drupalSettings.rep && drupalSettings.rep.nodeInfoEndpoint) ||
        (window.Drupal && Drupal.url ? Drupal.url('rep/graph/node') : '/rep/graph/node');

      const limits = (drupalSettings && drupalSettings.rep && drupalSettings.rep.graphLimits) || {};
      const PAGE_SIZE = Number(limits.pageSize || 25);
      const MAX_LIVE_NODES = Number(limits.maxLiveNodes || 600);

      const predCfg = (drupalSettings && drupalSettings.rep && drupalSettings.rep.graphPredicateConfig) || {};
      const normalizePredForApi = (v) => {
        const s = String(v || '').trim();
        if (!s) return '';
        const l = s.toLowerCase();
        if (l === 'super' || l === 'superuri' || l === 'hassuperuri' || l === 'superclassuri' || l === 'hassuperclassuri') return 'super';
        return s;
      };
      const normalizePredKey = (v) => normalizePredForApi(v).toLowerCase();
      const hiddenPredicateKeys = (() => {
        const raw = predCfg && predCfg.hidden;
        const arr = Array.isArray(raw)
          ? raw
          : String(raw || '').split(/\R+/);
        return new Set(arr.map(normalizePredKey).filter(Boolean));
      })();

      // ----- Helpers -----
      const AHEAD = 'http://hadatac.org/ont/arrowhead/';
      function expandCurie(v) {
        return (typeof v === 'string' && v.startsWith('ahead:')) ? (AHEAD + v.slice(6)) : v;
      }

      function stableHash(str) {
        const s = String(str || '');
        let h = 0;
        for (let i = 0; i < s.length; i++) {
          h = ((h << 5) - h) + s.charCodeAt(i);
          h |= 0;
        }
        return h;
      }

      function defaultLabelForId(id) {
        const s = String(id || '');
        const p = s.split('/');
        return p[p.length - 1] || s || '(no label)';
      }

      function isClassNode(nodeId, typeUri) {
        const id = expandCurie(nodeId);
        const tu = expandCurie(typeUri);
        return !!id && !!tu && id === tu;
      }

      function buildLocalUriLink(iri) {
        const v = String(iri || '').trim();
        if (!v) return null;
        try {
          const b64 = window.btoa(unescape(encodeURIComponent(v)))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/g, '');
          return (window.Drupal && Drupal.url) ? Drupal.url('rep/uri/' + b64) : ('/rep/uri/' + b64);
        } catch (e) {
          return null;
        }
      }

      function copyText(text) {
        const t = String(text || '');
        if (!t) return;
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(t);
            return;
          }
        } catch (e) {}
        try {
          const ta = document.createElement('textarea');
          ta.value = t;
          ta.style.position = 'fixed';
          ta.style.top = '-9999px';
          document.body.appendChild(ta);
          ta.focus();
          ta.select();
          document.execCommand('copy');
          ta.remove();
        } catch (e) {}
      }

      function makeCopyLink(text, getVal) {
        const a = document.createElement('a');
        a.href = '#';
        a.textContent = text;
        a.style.cssText = 'margin-left:6px; font-size:12px; color:#007bff; white-space:nowrap;';
        a.addEventListener('click', (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          try { copyText(getVal()); } catch (e) {}
        });
        return a;
      }

      function makeStatusRow(text) {
        const row = document.createElement('div');
        const statusId = arguments.length > 1 ? arguments[1] : 'rep-graph-status';
        const nodeId = arguments.length > 2 ? arguments[2] : null;
        row.id = statusId;
        if (nodeId) row.setAttribute('data-node-id', String(nodeId));
        row.style.cssText = 'font-size:12px; opacity:.75; margin-bottom:6px;';
        const msg = String(text || '');
        if (/\bloading\b/i.test(msg)) {
          const icon = document.createElement('i');
          icon.className = 'fa fa-spinner fa-spin';
          icon.style.marginRight = '6px';
          row.appendChild(icon);
        }
        const t = document.createElement('span');
        t.textContent = msg;
        row.appendChild(t);
        return row;
      }

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

      // ----- Graph -----
      const Graph = graphology.Graph;
      const graph = new Graph();
      const pinnedNodes = new Set();

      function nodeColorFor(id, typeUri) {
        if (isClassNode(id, typeUri)) return '#28a745';
        return '#007bff';
      }

      function ensureNode(n, initialPos = null) {
        const id = expandCurie(n && (n.id || n.uri));
        if (!id) return { id: null, added: false };

        const typeUri = expandCurie(n && (n.typeUri || n.hascoTypeUri || null));
        const label = (n && n.label) ? String(n.label) : defaultLabelForId(id);

        if (graph.hasNode(id)) {
          // keep label/type up to date
          try {
            if (label) graph.setNodeAttribute(id, 'label', label);
            if (typeUri) graph.setNodeAttribute(id, 'typeUri', typeUri);

            const effType = typeUri || graph.getNodeAttribute(id, 'typeUri');
            const isCls = isClassNode(id, effType);
            graph.setNodeAttribute(id, 'size', isCls ? 12 : 10);
            graph.setNodeAttribute(id, 'color', nodeColorFor(id, effType));
          } catch (e) {}
          return { id, added: false };
        }

        if (graph.order >= MAX_LIVE_NODES) return { id, added: false, capped: true };

        const pos = initialPos || { x: 0, y: 0 };
        graph.addNode(id, {
          label,
          typeUri,
          x: typeof pos.x === 'number' ? pos.x : 0,
          y: typeof pos.y === 'number' ? pos.y : 0,
          size: isClassNode(id, typeUri) ? 12 : 10,
          color: nodeColorFor(id, typeUri),
        });

        return { id, added: true };
      }

      function edgeKey(from, to, label) {
        return `${from}__${label}__${to}`;
      }

      function ensureEdge(e) {
        const from = expandCurie(e && e.from);
        const to = expandCurie(e && e.to);
        const label = String(e && e.label || '').trim();
        if (!from || !to || !label) return;
        if (!graph.hasNode(from) || !graph.hasNode(to)) return;

        const k = edgeKey(from, to, label);
        if (graph.hasEdge(k)) return;

        try {
          graph.addDirectedEdgeWithKey(k, from, to, {
            label,
            color: '#999',
            size: 1,
          });
        } catch (e2) {
          // ignore duplicate key/race
        }
      }

      function getNodePos(id) {
        try {
          const a = graph.getNodeAttributes(id);
          if (!a) return null;
          return { x: a.x || 0, y: a.y || 0 };
        } catch (e) {
          return null;
        }
      }

      function applyNodeTargets(targets, animate = true, duration = 240) {
        const ids = Object.keys(targets || {}).filter((id) => id && graph.hasNode(id) && !pinnedNodes.has(id));
        if (!ids.length) return;

        if (!animate || ids.length > 80) {
          ids.forEach((id) => {
            const t = targets[id];
            if (!t) return;
            try {
              graph.setNodeAttribute(id, 'x', t.x);
              graph.setNodeAttribute(id, 'y', t.y);
            } catch (e) {}
          });
          return;
        }

        const start = (window.performance && performance.now) ? performance.now() : Date.now();
        const starts = {};
        ids.forEach((id) => {
          const s = getNodePos(id) || targets[id];
          starts[id] = { x: s.x, y: s.y };
        });

        const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

        const step = (now) => {
          const n = (window.performance && performance.now) ? now : Date.now();
          const t = Math.max(0, Math.min(1, (n - start) / Math.max(1, duration)));
          const k = easeOutCubic(t);
          ids.forEach((id) => {
            const a = starts[id];
            const b = targets[id];
            if (!a || !b) return;
            try {
              graph.setNodeAttribute(id, 'x', a.x + (b.x - a.x) * k);
              graph.setNodeAttribute(id, 'y', a.y + (b.y - a.y) * k);
            } catch (e) {}
          });
          if (t < 1) window.requestAnimationFrame(step);
        };

        window.requestAnimationFrame(step);
      }

      function placeAround(anchorId, ids, radius, angleOffset) {
        const anchor = getNodePos(anchorId);
        if (!anchor) return;
        const list = (ids || []).filter((id) => id && !pinnedNodes.has(id));
        if (!list.length) return;
        const N = list.length || 1;
        const targets = {};
        list.forEach((id, i) => {
          const a = angleOffset + (2 * Math.PI * i) / N;
          targets[id] = { x: anchor.x + radius * Math.cos(a), y: anchor.y + radius * Math.sin(a) };
        });
        applyNodeTargets(targets, true);
      }

      function placeInRow(anchorId, ids, dy, spacing) {
        const anchor = getNodePos(anchorId);
        if (!anchor) return;
        const list = (ids || []).filter((id) => id && !pinnedNodes.has(id));
        if (!list.length) return;
        const startX = anchor.x - ((list.length - 1) * spacing) / 2;
        const targets = {};
        list.forEach((id, i) => {
          targets[id] = { x: startX + (i * spacing), y: anchor.y + dy };
        });
        applyNodeTargets(targets, true);
      }

      function placeByPredicate(anchorId, ids, predicate) {
        const lbl = String(predicate || '').trim();
        const k = lbl.toLowerCase();
        if (k === 'super') return placeInRow(anchorId, ids, -180, 130);
        if (k === 'children') return placeInRow(anchorId, ids, 180, 130);
        if (k === 'contains') return placeInRow(anchorId, ids, 260, 130);

        const seed = Math.abs(stableHash(k || lbl || ''));
        const ring = seed % 4;
        const radius = 180 + (ring * 90);
        const angleOffset = (seed % 360) * (Math.PI / 180);
        return placeAround(anchorId, ids, radius, angleOffset);
      }

      function mergeExpandPayload(payload, anchorId, predLabel) {
        const newIds = [];
        const nodes = (payload && payload.nodes) || [];
        const edges = (payload && payload.edges) || [];

        const anchor = anchorId ? (getNodePos(anchorId) || { x: 0, y: 0 }) : { x: 0, y: 0 };

        nodes.forEach((n) => {
          const nid = expandCurie(n && (n.id || n.uri));
          const seed = Math.abs(stableHash(nid || ''));
          const angle = (seed % 360) * (Math.PI / 180);
          const r = 24 + (seed % 20);
          const jitterPos = { x: anchor.x + r * Math.cos(angle), y: anchor.y + r * Math.sin(angle) };

          const res = ensureNode(n, jitterPos);
          if (res && res.added && res.id) newIds.push(res.id);
        });

        edges.forEach((e) => ensureEdge(e));

        if (anchorId && newIds.length) {
          placeByPredicate(anchorId, Array.from(new Set(newIds)), predLabel);
        }

        return newIds;
      }

      function relaxAfterExpand(anchorIdRaw, newIdsRaw) {
        const anchorId = expandCurie(anchorIdRaw);
        if (!anchorId || !graph.hasNode(anchorId)) return;

        const newIds = Array.from(new Set((newIdsRaw || []).map(expandCurie))).filter(Boolean);
        if (!newIds.length) return;

        const set = new Set();
        const add = (id) => { if (id && graph.hasNode(id)) set.add(id); };

        add(anchorId);
        newIds.forEach(add);

        const addNeighbors = (id) => {
          try { graph.forEachNeighbor(id, (nbr) => add(nbr)); } catch (e) {}
        };

        addNeighbors(anchorId);
        newIds.slice(0, 60).forEach(addNeighbors);

        // Second hop (bounded) to also spread existing nearby nodes.
        Array.from(set).slice(0, 60).forEach(addNeighbors);

        let ids = Array.from(set);
        const CAP = 180;
        if (ids.length > CAP) ids = ids.slice(0, CAP);

        const fixed = new Set();
        fixed.add(anchorId);
        try { pinnedNodes.forEach((id) => fixed.add(id)); } catch (e) {}

        const pos = Object.create(null);
        const size = Object.create(null);
        ids.forEach((id) => {
          const p = getNodePos(id) || { x: 0, y: 0 };
          pos[id] = { x: p.x, y: p.y };
          let s = 10;
          try { s = Number(graph.getNodeAttribute(id, 'size') || 10); } catch (e) {}
          if (!Number.isFinite(s) || s <= 0) s = 10;
          size[id] = s;
        });

        const desiredMult = 9;
        const softMult = 2.2;          // comfort distance multiplier (soft repulsion)
        const softK = 0.22;            // soft repulsion strength
        const hardK = 0.70;            // hard collision strength
        const gravityK = 0.004;        // weak pull toward anchor
        const maxPush = 110;
        const iterations = Math.min(26, Math.max(12, Math.floor(ids.length / 6)));

        for (let it = 0; it < iterations; it++) {
          let moved = false;

          for (let a = 0; a < ids.length; a++) {
            const ida = ids[a];
            const pa = pos[ida];
            if (!pa) continue;

            for (let b = a + 1; b < ids.length; b++) {
              const idb = ids[b];
              const pb = pos[idb];
              if (!pb) continue;
              if (fixed.has(ida) && fixed.has(idb)) continue;

              let dx = pb.x - pa.x;
              let dy = pb.y - pa.y;
              let dist2 = dx * dx + dy * dy;
              if (dist2 < 1e-6) {
                dx = (Math.random() - 0.5) * 0.01;
                dy = (Math.random() - 0.5) * 0.01;
                dist2 = dx * dx + dy * dy;
              }
              const dist = Math.sqrt(dist2);
              const desired = (size[ida] + size[idb]) * desiredMult;
              const comfort = desired * softMult;
              if (dist >= comfort) continue;

              moved = true;
              const overlapSoft = comfort - dist;
              const overlapHard = Math.max(0, desired - dist);
              const ux = dx / dist;
              const uy = dy / dist;

              // Soft repulsion when too close, plus stronger collision when overlapping.
              const pushSoft = Math.min(maxPush, overlapSoft * softK);
              const pushHard = overlapHard > 0 ? Math.min(maxPush, overlapHard * hardK) : 0;
              const push = Math.max(pushSoft, pushHard);

              if (!fixed.has(ida) && !fixed.has(idb)) {
                pa.x -= ux * (push * 0.5);
                pa.y -= uy * (push * 0.5);
                pb.x += ux * (push * 0.5);
                pb.y += uy * (push * 0.5);
              } else if (!fixed.has(ida) && fixed.has(idb)) {
                pa.x -= ux * push;
                pa.y -= uy * push;
              } else if (fixed.has(ida) && !fixed.has(idb)) {
                pb.x += ux * push;
                pb.y += uy * push;
              }
            }
          }

          // Small gravity to keep the cluster near the anchor (avoids exploding away).
          const ap = pos[anchorId];
          if (ap) {
            ids.forEach((id) => {
              if (fixed.has(id)) return;
              const p = pos[id];
              if (!p) return;
              p.x += (ap.x - p.x) * gravityK;
              p.y += (ap.y - p.y) * gravityK;
            });
          }

          if (!moved) break;
        }

        const targets = {};
        ids.forEach((id) => {
          if (fixed.has(id)) return;
          if (!pos[id]) return;
          targets[id] = pos[id];
        });

        applyNodeTargets(targets, true, 320);
      }

      // Seed initial graph
      const base = (drupalSettings && drupalSettings.graphData) || {};
      const baseNodes = Array.isArray(base.nodes) ? base.nodes : [];
      const baseEdges = Array.isArray(base.edges) ? base.edges : [];

      let currentRootId = null;
      baseNodes.forEach((n, idx) => {
        const id = expandCurie(n && n.id);
        const pos = (idx === 0) ? { x: 0, y: 0 } : { x: 8 + idx, y: 0 };
        const res = ensureNode(n, pos);
        if (!currentRootId && res && res.id) currentRootId = res.id;
      });
      baseEdges.forEach((e) => ensureEdge(e));

      let selectedNodeId = currentRootId;

      // Sigma renderer
      const renderer = new Sigma(graph, container, {
        allowInvalidContainer: true,
        hideEdgesOnMove: false,
        hideLabelsOnMove: false,
        renderEdgeLabels: false,
        enableEdgeClickEvents: false,
        enableEdgeHoverEvents: false,
        nodeReducer: (node, data) => {
          if (node === selectedNodeId) {
            return {
              ...data,
              color: '#ffc107',
              size: Math.max(12, (data && data.size) ? (data.size * 1.35) : 12),
            };
          }
          return data;
        },
      });

      // Disable wheel zoom entirely (and allow page scroll).
      try {
        const mc = renderer.getMouseCaptor();
        container.removeEventListener('wheel', mc.handleWheel);
      } catch (e) {}

      // Prevent default double-click zoom (we use double-click for expand).
      renderer.on('doubleClickStage', (e) => {
        try { if (e && e.preventSigmaDefault) e.preventSigmaDefault(); } catch (err) {}
      });

      // Resize helper
      const safeResize = () => {
        try { renderer.resize(); } catch (e) {}
      };

      // ---- Right panel collapse ----
      if (shell && explorer && !shell.dataset.repExplorerCollapseInit) {
        shell.dataset.repExplorerCollapseInit = '1';

        const collapseBtn = document.createElement('button');
        collapseBtn.type = 'button';
        collapseBtn.className = 'btn btn-sm btn-light';
        collapseBtn.textContent = '»';
        collapseBtn.title = 'Collapse panel';
        collapseBtn.style.cssText = 'position:absolute; z-index:30;';

        const expandBtn = document.createElement('button');
        expandBtn.type = 'button';
        expandBtn.className = 'btn btn-sm btn-light';
        expandBtn.textContent = '«';
        expandBtn.title = 'Expand panel';
        expandBtn.style.cssText = 'position:absolute; top:50%; right:10px; transform:translateY(-50%); z-index:30; display:none;';

        shell.appendChild(collapseBtn);
        shell.appendChild(expandBtn);

        const positionHandle = () => {
          try {
            if (explorer.style.display === 'none') return;
            const shellRect = shell.getBoundingClientRect();
            const expRect = explorer.getBoundingClientRect();
            const left = Math.max(6, (expRect.left - shellRect.left) - 18);
            collapseBtn.style.left = `${left}px`;
            collapseBtn.style.top = '50%';
            collapseBtn.style.transform = 'translateY(-50%)';
          } catch (e) {}
        };

        const setCollapsed = (collapsed) => {
          explorer.style.display = collapsed ? 'none' : '';
          collapseBtn.style.display = collapsed ? 'none' : '';
          expandBtn.style.display = collapsed ? '' : 'none';
          if (!collapsed) positionHandle();
          window.setTimeout(() => { safeResize(); renderer.refresh(); }, 60);
        };

        positionHandle();
        window.addEventListener('resize', positionHandle);
        collapseBtn.addEventListener('click', (ev) => { ev.preventDefault(); setCollapsed(true); });
        expandBtn.addEventListener('click', (ev) => { ev.preventDefault(); setCollapsed(false); });
      }

      // ---- Fullscreen + zoom buttons ----
      if (!container.dataset.repFullscreenInit) {
        container.dataset.repFullscreenInit = '1';
        if (!container.style.position) container.style.position = 'relative';

        const ui = document.createElement('div');
        ui.className = 'rep-graph-ui';
        ui.style.cssText = 'position:absolute; right:10px; bottom:10px; z-index:30; pointer-events:auto; display:flex; flex-direction:column; gap:6px;';

        const zoomInBtn = document.createElement('button');
        zoomInBtn.type = 'button';
        zoomInBtn.className = 'btn btn-sm btn-light';
        zoomInBtn.textContent = '+';
        zoomInBtn.title = 'Zoom in';

        const zoomOutBtn = document.createElement('button');
        zoomOutBtn.type = 'button';
        zoomOutBtn.className = 'btn btn-sm btn-light';
        zoomOutBtn.textContent = '−';
        zoomOutBtn.title = 'Zoom out';

        const fsBtn = document.createElement('button');
        fsBtn.type = 'button';
        fsBtn.className = 'btn btn-sm btn-light';
        fsBtn.textContent = '⤢';
        fsBtn.title = 'Fullscreen';

        ui.appendChild(zoomInBtn);
        ui.appendChild(zoomOutBtn);
        ui.appendChild(fsBtn);
        container.appendChild(ui);

        const camera = renderer.getCamera();
        const zoomFactor = 1.2;

        const bindBtn = (btn, fn) => {
          const handler = (ev) => {
            try {
              ev.preventDefault();
              ev.stopPropagation();
            } catch (e) {}
            try { fn(); } catch (e2) {}
          };
          btn.addEventListener('pointerdown', handler);
          btn.addEventListener('click', handler);
        };

        bindBtn(zoomInBtn, () => { camera.animatedZoom(zoomFactor); });
        bindBtn(zoomOutBtn, () => { camera.animatedUnzoom(zoomFactor); });

        const fsTarget = shell || container;
        const orig = {
          target: fsTarget ? {
            position: fsTarget.style.position,
            top: fsTarget.style.top,
            left: fsTarget.style.left,
            right: fsTarget.style.right,
            bottom: fsTarget.style.bottom,
            width: fsTarget.style.width,
            height: fsTarget.style.height,
            zIndex: fsTarget.style.zIndex,
            padding: fsTarget.style.padding,
            background: fsTarget.style.background,
          } : null,
          containerHeight: container.style.height,
          explorerHeight: explorer ? explorer.style.height : null,
        };
        let pseudoFs = false;

        const applyFillHeights = (on) => {
          if (on) {
            container.style.height = '100%';
            if (explorer) explorer.style.height = '100%';
            if (fsTarget && fsTarget !== container) {
              fsTarget.style.height = '100%';
              if (!fsTarget.style.background) fsTarget.style.background = 'white';
              if (!fsTarget.style.padding) fsTarget.style.padding = '12px';
            }
          } else {
            container.style.height = orig.containerHeight || '';
            if (explorer) explorer.style.height = orig.explorerHeight || '';
            if (fsTarget && orig.target) {
              fsTarget.style.height = orig.target.height || '';
              fsTarget.style.background = orig.target.background || '';
              fsTarget.style.padding = orig.target.padding || '';
            }
          }
        };

        const isNativeOn = () => {
          try { return document.fullscreenElement === fsTarget; } catch (e) { return false; }
        };

        const updateFsBtn = () => {
          const on = isNativeOn() || pseudoFs;
          fsBtn.textContent = on ? '⤡' : '⤢';
          fsBtn.title = on ? 'Exit fullscreen' : 'Fullscreen';
        };

        const enterPseudo = () => {
          if (!fsTarget || !orig.target) return;
          pseudoFs = true;
          fsTarget.style.position = 'fixed';
          fsTarget.style.top = '0';
          fsTarget.style.left = '0';
          fsTarget.style.right = '0';
          fsTarget.style.bottom = '0';
          fsTarget.style.width = '100vw';
          fsTarget.style.height = '100vh';
          fsTarget.style.zIndex = '99999';
          fsTarget.style.padding = '12px';
          if (!fsTarget.style.background) fsTarget.style.background = 'white';
          applyFillHeights(true);
          updateFsBtn();
          window.setTimeout(() => { safeResize(); renderer.refresh(); }, 80);
        };

        const exitPseudo = () => {
          if (!fsTarget || !orig.target) return;
          pseudoFs = false;
          fsTarget.style.position = orig.target.position || '';
          fsTarget.style.top = orig.target.top || '';
          fsTarget.style.left = orig.target.left || '';
          fsTarget.style.right = orig.target.right || '';
          fsTarget.style.bottom = orig.target.bottom || '';
          fsTarget.style.width = orig.target.width || '';
          fsTarget.style.height = orig.target.height || '';
          fsTarget.style.zIndex = orig.target.zIndex || '';
          fsTarget.style.padding = orig.target.padding || '';
          fsTarget.style.background = orig.target.background || '';
          applyFillHeights(false);
          updateFsBtn();
          window.setTimeout(() => { safeResize(); renderer.refresh(); }, 80);
        };

        fsBtn.addEventListener('click', (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          try {
            if (isNativeOn()) {
              document.exitFullscreen();
              return;
            }
            if (pseudoFs) {
              exitPseudo();
              return;
            } else if (fsTarget && fsTarget.requestFullscreen) {
              if (document.fullscreenEnabled) {
                fsTarget.requestFullscreen().catch(() => enterPseudo());
              } else {
                enterPseudo();
              }
            } else {
              enterPseudo();
            }
          } catch (e) {}
        });

        document.addEventListener('fullscreenchange', () => {
          const on = isNativeOn();
          applyFillHeights(on);
          updateFsBtn();
          window.setTimeout(() => { safeResize(); renderer.refresh(); }, 80);
        });

        updateFsBtn();
      }

      // ---- Node details (right panel) ----
      const nodeInfoCache = Object.create(null);
      const nodeInfoWaiters = Object.create(null);

      function ensureNodeInfo(nodeId, done) {
        const id = expandCurie(nodeId);
        if (!nodeInfoEndpoint) { if (done) done(null); return; }

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
            q.forEach((fn) => { try { if (fn) fn(nodeInfoCache[id]); } catch (e) {} });
          });
      }

      function renderExplorer(nodeId) {
        if (!explorer) return;
        const id = expandCurie(nodeId);

        const attrs = graph.hasNode(id) ? graph.getNodeAttributes(id) : null;
        const title = (attrs && attrs.label) ? String(attrs.label) : defaultLabelForId(id);

        explorer.innerHTML = '';

        const header = document.createElement('div');
        header.style.cssText = 'display:flex; align-items:flex-start; justify-content:space-between; gap:10px; padding-bottom:8px; border-bottom:1px solid #ddd; margin-bottom:10px;';

        const left = document.createElement('div');
        left.style.cssText = 'min-width:0;';
        const h = document.createElement('div');
        h.textContent = title;
        h.style.cssText = 'font-weight:600; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
        h.title = id;
        const sub = document.createElement('div');
        sub.style.cssText = 'font-size:12px; opacity:.8; word-break:break-all;';
        sub.textContent = id;
        left.appendChild(h);
        left.appendChild(sub);

        const actions = document.createElement('div');
        actions.style.cssText = 'display:flex; align-items:center; gap:10px; flex:0 0 auto;';
        actions.appendChild(makeCopyLink('Copy URI', () => id));
        const open = buildLocalUriLink(id);
        if (open) {
          const a = document.createElement('a');
          a.href = open;
          a.target = '_blank';
          a.rel = 'noopener noreferrer';
          a.textContent = 'Open';
          a.style.cssText = 'font-size:12px; color:#007bff; white-space:nowrap;';
          actions.appendChild(a);
        }
        header.appendChild(left);
        header.appendChild(actions);
        explorer.appendChild(header);

        const attrPanel = document.createElement('details');
        attrPanel.open = true;
        attrPanel.style.cssText = 'border:1px solid #e5e5e5; background:white; border-radius:6px; padding:8px; margin-bottom:10px;';

        const attrSummary = document.createElement('summary');
        attrSummary.style.cssText = 'cursor:pointer; font-weight:600; font-size:13px;';
        attrSummary.textContent = 'Attributes';
        attrPanel.appendChild(attrSummary);

        const box = document.createElement('div');
        box.style.cssText = 'margin-top:8px;';
        attrPanel.appendChild(box);
        explorer.appendChild(attrPanel);

        box.appendChild(makeStatusRow('Loading node details…', 'rep-graph-status', id));

        const addRow = (k, v, asLink = null) => {
          if (v === null || typeof v === 'undefined' || v === '') return;

          const row = document.createElement('div');
          row.style.cssText = 'display:flex; gap:10px; align-items:flex-start; font-size:12px; padding:2px 0;';

          const kk = document.createElement('div');
          kk.style.cssText = 'flex:0 0 130px; opacity:.75;';
          kk.textContent = String(k);

          const vv = document.createElement('div');
          vv.style.cssText = 'flex:1 1 auto; word-break:break-all;';
          vv.textContent = String(v);

          row.appendChild(kk);
          row.appendChild(vv);

          if (asLink) {
            const raw = String(asLink || '');
            row.appendChild(makeCopyLink('Copy', () => raw));
            const href = /^https?:\/\//i.test(raw) ? raw : buildLocalUriLink(raw);
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

          box.appendChild(row);
        };

        ensureNodeInfo(id, (info) => {
          if (!box.isConnected) return;
          box.innerHTML = '';

          if (!info) {
            box.appendChild(makeStatusRow('Failed to load node details.', 'rep-graph-status', id));
            return;
          }

          // Apply canonical label/type to the graph node immediately.
          try {
            ensureNode({ id: id, label: info.label || title, typeUri: info.typeUri || info.hascoTypeUri || null });
          } catch (e) {}

          const addRow = (k, v, asLink = null) => {
            if (v === null || typeof v === 'undefined' || v === '') return;

            const keyRaw = String(k || '').trim();
            const keyNorm = normalizePredKey(keyRaw);
            if (keyNorm && hiddenPredicateKeys.has(keyNorm)) return;

            // Avoid duplicates: if we already show the normalized "super" row, do not
            // also show superUri/superURI variants as plain fields.
            if (keyNorm === 'super' && keyRaw !== 'super' && info && info.superUri) return;

            const row = document.createElement('div');
            row.style.cssText = 'display:flex; gap:10px; align-items:flex-start; font-size:12px; padding:2px 0;';

            const kk = document.createElement('div');
            kk.style.cssText = 'flex:0 0 130px; opacity:.75;';
            kk.textContent = keyRaw;

            const vv = document.createElement('div');
            vv.style.cssText = 'flex:1 1 auto; word-break:break-all;';
            vv.textContent = String(v);

            // Clamp long comments (2 lines) and allow opening full text in a modal.
            if (keyNorm === 'comment') {
              vv.style.cssText = [
                'flex:1 1 auto',
                'word-break:break-word',
                'white-space:normal',
                'overflow:hidden',
                'display:-webkit-box',
                '-webkit-line-clamp:2',
                '-webkit-box-orient:vertical',
                'max-height:3.2em',
              ].join(';');
            }

            row.appendChild(kk);
            row.appendChild(vv);

            if (asLink) {
              const raw = String(asLink || '');
              row.appendChild(makeCopyLink('Copy', () => raw));
              const href = /^https?:\/\//i.test(raw) ? raw : buildLocalUriLink(raw);
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

          const hascoType = info.hascoTypeUri || null;
          const typeUri = info.typeUri || null;
          if (hascoType) addRow('hascoTypeUri', hascoType, hascoType);
          if (typeUri && typeUri !== hascoType) addRow('typeUri', typeUri, typeUri);
          if (info.superUri) addRow('super', info.superUri, info.superUri);

          (Array.isArray(info.fields) ? info.fields : []).slice(0, 12).forEach((f) => {
            if (f && f.key) addRow(f.key, f.value);
          });

          (Array.isArray(info.lists) ? info.lists : []).slice(0, 10).forEach((it) => {
            if (it && it.key && typeof it.count !== 'undefined') addRow(it.key, String(it.count));
          });

          (Array.isArray(info.links) ? info.links : []).slice(0, 10).forEach((lnk) => {
            if (lnk && lnk.key && lnk.uri) addRow(lnk.key, lnk.label || lnk.uri, lnk.uri);
          });

          try { renderer.refresh(); } catch (e) {}
        });

        // Predicates panel (so users don't need to guess what double-click expands)
        const predPanel = document.createElement('details');
        predPanel.open = true;
        predPanel.style.cssText = 'border:1px solid #e5e5e5; background:white; border-radius:6px; padding:8px; margin-bottom:10px;';

        const predSummary = document.createElement('summary');
        predSummary.style.cssText = 'cursor:pointer; font-weight:600; font-size:13px;';
        predSummary.textContent = 'Predicates';
        predPanel.appendChild(predSummary);

        const predBox = document.createElement('div');
        predBox.style.cssText = 'margin-top:8px;';
        predPanel.appendChild(predBox);
        explorer.appendChild(predPanel);

        predBox.appendChild(makeStatusRow('', 'rep-graph-pred-status', id));

        const dblHint = document.createElement('div');
        dblHint.style.cssText = 'font-size:12px; opacity:.85; margin-bottom:8px;';
        dblHint.textContent = 'Double-click a node to load its relationships.';
        predBox.appendChild(dblHint);

        const predList = document.createElement('div');
        predList.style.cssText = 'display:flex; flex-direction:column; gap:6px;';
        predBox.appendChild(predList);

        const renderPredicates = (info) => {
          predList.innerHTML = '';

          const all = [];
          if (info && Array.isArray(info.predicates)) {
            info.predicates.forEach((p) => {
              if (!p || !p.key) return;
              const key = normalizePredForApi(p.key);
              const keyNorm = normalizePredKey(key);
              if (keyNorm && hiddenPredicateKeys.has(keyNorm)) return;
              const kind = p.kind || null;
              const count = (kind === 'link') ? 1 : ((typeof p.count === 'number') ? p.count : null);
              all.push({ key, kind, count });
            });
          }
          if (info && info.superUri) {
            const keyNorm = normalizePredKey('super');
            if (!hiddenPredicateKeys.has(keyNorm)) all.push({ key: 'super', kind: 'link', count: 1 });
          }

          // Helpful always-visible type predicates.
          ['typeUri', 'hascoTypeUri'].forEach((k) => {
            const keyNorm = normalizePredKey(k);
            if (!hiddenPredicateKeys.has(keyNorm)) all.push({ key: k, kind: 'link', count: 1 });
          });

          const uniq = [];
          const seen = new Set();
          all.forEach((p) => {
            const nk = normalizePredKey(p.key);
            if (!nk || seen.has(nk)) return;
            seen.add(nk);
            uniq.push(p);
          });

          uniq.sort((a, b) => String(a.key).localeCompare(String(b.key)));

          const willLoad = labelsForNode(id, info, 6);
          dblHint.textContent = willLoad.length
            ? `Double-click will load (max 6): ${willLoad.join(', ')}`
            : 'Double-click will load: (none detected)';

          if (!uniq.length) {
            const empty = document.createElement('div');
            empty.style.cssText = 'opacity:.75; font-size:13px;';
            empty.textContent = 'No predicates available for this node.';
            predList.appendChild(empty);
            return;
          }

          uniq.forEach((p) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:10px; padding:4px 6px; border:1px solid #e5e5e5; border-radius:6px; background:white;';

            const left = document.createElement('div');
            left.style.cssText = 'min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
            left.textContent = p.key;

            const right = document.createElement('div');
            right.style.cssText = 'font-size:12px; opacity:.75; white-space:nowrap;';
            right.textContent = (typeof p.count === 'number') ? `(${p.count})` : '';

            row.appendChild(left);
            row.appendChild(right);
            predList.appendChild(row);
          });
        };

        // Fill predicates once nodeInfo arrives.
        ensureNodeInfo(id, (info) => {
          if (!predBox.isConnected) return;
          renderPredicates(info);
        });
      }

      // ---- Expand on double click ----
      const expanding = Object.create(null);

      function labelsForNode(nodeId, info, cap = 6) {
        const labels = [];
        if (info && Array.isArray(info.predicates)) {
          info.predicates.forEach((p) => { if (p && p.key) labels.push(p.key); });
        }
        if (info && info.superUri) labels.push('super');

        // Always expose type predicates unless hidden.
        if (!hiddenPredicateKeys.has(normalizePredKey('typeUri'))) labels.push('typeUri');
        if (!hiddenPredicateKeys.has(normalizePredKey('hascoTypeUri'))) labels.push('hascoTypeUri');

        const attrs = graph.hasNode(nodeId) ? graph.getNodeAttributes(nodeId) : null;
        const typeUri = attrs && attrs.typeUri;
        if (isClassNode(nodeId, typeUri)) {
          labels.push('children');
          labels.push('super');
        }

        // De-dupe, keep stable ordering
        const preferred = ['contains', 'children', 'super', 'typeUri', 'hascoTypeUri'];
        let uniq = Array.from(new Set(labels.map((x) => String(x || '').trim()).filter(Boolean)));
        uniq = uniq.filter((k) => !hiddenPredicateKeys.has(normalizePredKey(k)));
        uniq.sort((a, b) => {
          const ia = preferred.indexOf(a);
          const ib = preferred.indexOf(b);
          if (ia !== -1 || ib !== -1) return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
          return String(a).localeCompare(String(b));
        });

        // Safety cap: avoid spamming too many calls
        if (cap === null || cap === undefined) return uniq;
        if (cap === Infinity) return uniq;
        const n = Number(cap);
        if (!Number.isFinite(n) || n <= 0) return uniq;
        return uniq.slice(0, n);
      }

      function fetchExpand(nodeId, label) {
        return new Promise((resolve) => {
          if (!socEndpoint) return resolve({ nodes: [], edges: [] });

          const params = { from: nodeId, limit: PAGE_SIZE, offset: 0, debug: 1 };
          if (label === 'contains') params.relation = 'contains';
          else params.label = label;

          $.getJSON(socEndpoint, params)
            .done((data) => resolve(data || { nodes: [], edges: [] }))
            .fail(() => resolve({ nodes: [], edges: [] }));
        });
      }

      function expandNode(nodeId) {
        const id = expandCurie(nodeId);
        if (expanding[id]) return;
        expanding[id] = true;

        // Spinner in predicates panel.
        if (explorer) {
          const st = explorer.querySelector('#rep-graph-pred-status');
          if (st && st.getAttribute('data-node-id') === id) {
            st.replaceWith(makeStatusRow('Loading relationships…', 'rep-graph-pred-status', id));
          }
        }

        ensureNodeInfo(id, async (info) => {
          const labels = labelsForNode(id, info);

          const allNew = [];

          for (const lbl of labels) {
            const payload = await fetchExpand(id, lbl);
            const added = mergeExpandPayload(payload, id, lbl) || [];
            allNew.push(...added);
          }

          expanding[id] = false;
          try { renderer.refresh(); } catch (e) {}

          // Repulsion/relax to reduce overlaps and use space better.
          const uniqAllNew = Array.from(new Set(allNew));
          if (uniqAllNew.length) {
            window.setTimeout(() => {
              try { relaxAfterExpand(id, uniqAllNew); } catch (e) {}
              try { renderer.refresh(); } catch (e2) {}
            }, 260);
          }

          // Best-effort background label fix for newly added nodes.
          const uniqNew = Array.from(new Set(allNew)).slice(0, 25);
          if (uniqNew.length) {
            let pending = uniqNew.length;
            uniqNew.forEach((nid) => {
              ensureNodeInfo(nid, (ninfo) => {
                if (ninfo && ninfo.label) {
                  try { ensureNode({ id: nid, label: ninfo.label, typeUri: ninfo.typeUri || ninfo.hascoTypeUri || null }); } catch (e) {}
                }
                pending--;
                if (pending <= 0) {
                  try { renderer.refresh(); } catch (e) {}
                }
              });
            });
          }

          // Clear spinner.
          if (explorer) {
            const st = explorer.querySelector('#rep-graph-pred-status');
            if (st && st.getAttribute('data-node-id') === id) {
              st.replaceWith(makeStatusRow('', 'rep-graph-pred-status', id));
            }
          }

          // Re-render explorer counts (best-effort)
          try { renderExplorer(id); } catch (e) {}
        });
      }

      // ---- Events ----
      renderer.on('clickNode', (e) => {
        try {
          const id = e && e.node;
          if (!id) return;
          selectedNodeId = id;
          renderer.refresh();
          renderExplorer(id);
        } catch (err) {}
      });

      renderer.on('doubleClickNode', (e) => {
        try {
          if (e && e.preventSigmaDefault) e.preventSigmaDefault();
          const id = e && e.node;
          if (!id) return;
          selectedNodeId = id;
          renderer.refresh();
          expandNode(id);
        } catch (err) {}
      });

      // Node dragging
      let draggedNode = null;
      let dragRaf = 0;
      let dragLast = null;
      let dragStartClient = null;
      let dragMoved = false;
      const dragMove = (ev) => {
        if (!draggedNode) return;
        if (!dragStartClient) dragStartClient = { x: ev.clientX, y: ev.clientY };
        const rect = container.getBoundingClientRect();
        dragLast = { x: ev.clientX - rect.left, y: ev.clientY - rect.top };

        // Don't treat a click as a drag.
        if (!dragMoved) {
          const dx = ev.clientX - dragStartClient.x;
          const dy = ev.clientY - dragStartClient.y;
          if ((dx * dx + dy * dy) < 16) return; // <4px
          dragMoved = true;
        }

        if (dragRaf) return;
        dragRaf = window.requestAnimationFrame(() => {
          dragRaf = 0;
          if (!draggedNode || !dragLast) return;
          try {
            const p = renderer.viewportToGraph(dragLast);
            graph.setNodeAttribute(draggedNode, 'x', p.x);
            graph.setNodeAttribute(draggedNode, 'y', p.y);
          } catch (e) {}
        });
      };
      const dragEnd = () => {
        if (!draggedNode) return;

        if (dragMoved) {
          try { pinnedNodes.add(draggedNode); } catch (e) {}
        }

        draggedNode = null;
        dragStartClient = null;
        dragMoved = false;
        try { renderer.getCamera().enable(); } catch (e) {}
        try { renderer.refresh(); } catch (e) {}
      };

      renderer.on('downNode', (e) => {
        try {
          const original = e && e.event && e.event.original;
          if (original && typeof original.button === 'number' && original.button !== 0) return;
          if (e && e.preventSigmaDefault) e.preventSigmaDefault();
          draggedNode = e.node;
          dragStartClient = null;
          dragMoved = false;
          try { renderer.getCamera().disable(); } catch (err) {}
        } catch (err) {}
      });

      document.addEventListener('mousemove', dragMove, true);
      document.addEventListener('mouseup', dragEnd, true);

      // Auto select root
      if (currentRootId) {
        try {
          selectedNodeId = currentRootId;
          renderer.refresh();
          renderExplorer(currentRootId);
        } catch (e) {}
      }
    },
  };
})(jQuery, Drupal, drupalSettings);
