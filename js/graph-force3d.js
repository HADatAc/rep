/**
 * rep/force3d_graph_panel : Experimental 3D graph viewer (3d-force-graph)
 *
 * Goals (POC):
 * - True 3D navigation (orbit rotate in 3 axes).
 * - Single click: show node details in the right panel (via /rep/graph/node).
 * - Double click: expand 1 hop (via /rep/graph/expand) for a limited set of predicates.
 * - No HASCOAPI changes.
 */

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.repForce3DGraphInit = {
    attach: function (context) {
      const container = context.querySelector('#my-network');
      if (!container || container.dataset.force3dLoaded === 'true') return;
      if (typeof ForceGraph3D === 'undefined') return;

      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
      };
      if (!isVisible(container)) return;

      container.dataset.force3dLoaded = 'true';

      const shell = container.closest('.rep-graph-shell');
      const explorer = shell ? shell.querySelector('#rep-graph-explorer') : null;
      if (shell && !shell.style.position) shell.style.position = 'relative';
      if (!container.style.position) container.style.position = 'relative';

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
        const arr = Array.isArray(raw) ? raw : String(raw || '').split(/\R+/);
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

      function makeStatusRow(text, statusId = 'rep-graph-status', nodeId = null) {
        const row = document.createElement('div');
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

      // ----- Graph data -----
      const nodesById = new Map();
      const linkKeys = new Set();
      const graphData = { nodes: [], links: [] };

      let selectedNodeId = null;

      function nodeColorFor(id, typeUri) {
        if (isClassNode(id, typeUri)) return '#28a745';
        return '#007bff';
      }

      function ensureNode(n, initialPos = null) {
        const id = expandCurie(n && (n.id || n.uri));
        if (!id) return { id: null, added: false };

        const typeUri = expandCurie(n && (n.typeUri || n.hascoTypeUri || null));
        const label = (n && n.label) ? String(n.label) : defaultLabelForId(id);

        if (nodesById.has(id)) {
          const obj = nodesById.get(id);
          if (label) obj.label = label;
          if (typeUri) obj.typeUri = typeUri;
          const effType = obj.typeUri || typeUri;
          obj.color = nodeColorFor(id, effType);
          obj.size = isClassNode(id, effType) ? 10 : 6;
          return { id, added: false };
        }

        if (nodesById.size >= MAX_LIVE_NODES) return { id, added: false, capped: true };

        const obj = {
          id,
          label,
          typeUri: typeUri || null,
          color: nodeColorFor(id, typeUri),
          size: isClassNode(id, typeUri) ? 10 : 6,
        };

        if (initialPos && typeof initialPos.x === 'number') {
          obj.x = initialPos.x;
          obj.y = initialPos.y;
          obj.z = (typeof initialPos.z === 'number') ? initialPos.z : 0;
        }

        nodesById.set(id, obj);
        graphData.nodes.push(obj);
        return { id, added: true };
      }

      function edgeKey(from, label, to) {
        return `${from}__${label}__${to}`;
      }

      function ensureLink(from, to, label) {
        const f = expandCurie(from);
        const t = expandCurie(to);
        const l = String(label || '').trim();
        if (!f || !t || !l) return;
        if (!nodesById.has(f) || !nodesById.has(t)) return;

        const k = edgeKey(f, l, t);
        if (linkKeys.has(k)) return;
        linkKeys.add(k);

        graphData.links.push({
          source: f,
          target: t,
          label: l,
          color: '#999',
        });
      }

      // Seed initial graph (base node only).
      const base = (drupalSettings && drupalSettings.graphData) || {};
      const baseNodes = Array.isArray(base.nodes) ? base.nodes : [];
      const baseEdges = Array.isArray(base.edges) ? base.edges : [];

      let currentRootId = null;
      baseNodes.forEach((n, idx) => {
        const pos = (idx === 0) ? { x: 0, y: 0, z: 0 } : { x: 8 + idx, y: 0, z: 0 };
        const res = ensureNode(n, pos);
        if (!currentRootId && res && res.id) currentRootId = res.id;
      });
      baseEdges.forEach((e) => {
        if (!e) return;
        ensureNode({ id: e.from }, null);
        ensureNode({ id: e.to }, null);
        ensureLink(e.from, e.to, e.label);
      });

      selectedNodeId = currentRootId;

      // ----- ForceGraph3D init -----
      const fg = ForceGraph3D();
      const graph = fg(container)
        .graphData(graphData)
        .backgroundColor('#ffffff')
        .nodeId('id')
        .nodeLabel((n) => String((n && (n.label || n.id)) || ''))
        .nodeColor((n) => {
          const id = n && n.id;
          if (id && selectedNodeId && id === selectedNodeId) return '#ffc107';
          return (n && n.color) ? n.color : '#007bff';
        })
        .nodeVal((n) => (n && typeof n.size === 'number' ? n.size : 6))
        .linkLabel((l) => (l && l.label) ? String(l.label) : '')
        .linkColor((l) => (l && l.color) ? l.color : '#999')
        .linkDirectionalArrowLength(3)
        .linkDirectionalArrowRelPos(1)
        .enableNodeDrag(true);

      // Allow graph-collapse.js to access instance if needed.
      try { container.__repForceGraph3D = graph; } catch (e) {}

      // Tune forces for better separation (best-effort).
      try {
        const charge = graph.d3Force('charge');
        if (charge && charge.strength) charge.strength(-140);
      } catch (e) {}
      try {
        const link = graph.d3Force('link');
        if (link && link.distance) link.distance(40);
      } catch (e) {}
      try {
        graph.d3ReheatSimulation();
      } catch (e) {}

      // Disable wheel-zoom to avoid trapping page scroll; zoom via +/- UI.
      try {
        const ctrls = graph.controls && graph.controls();
        if (ctrls) ctrls.enableZoom = false;
      } catch (e) {}

      function safeResize() {
        try {
          const rect = container.getBoundingClientRect();
          const w = Math.max(1, Math.floor(rect.width));
          const h = Math.max(1, Math.floor(rect.height));
          if (graph.width) graph.width(w);
          if (graph.height) graph.height(h);
          if (graph.refresh) graph.refresh();
        } catch (e) {}
      }

      window.addEventListener('resize', () => {
        window.setTimeout(safeResize, 30);
      });
      window.setTimeout(safeResize, 30);

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
          window.setTimeout(safeResize, 60);
        };

        positionHandle();
        window.addEventListener('resize', positionHandle);
        collapseBtn.addEventListener('click', (ev) => { ev.preventDefault(); setCollapsed(true); });
        expandBtn.addEventListener('click', (ev) => { ev.preventDefault(); setCollapsed(false); });
      }

      // ---- Fullscreen + zoom buttons ----
      if (!container.dataset.repFullscreenInit) {
        container.dataset.repFullscreenInit = '1';

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

        const zoomFactor = 1.25;
        const zoomBy = (factor) => {
          const cam = graph.camera && graph.camera();
          const ctrls = graph.controls && graph.controls();
          if (!cam || !ctrls || !ctrls.target) return;

          const t = ctrls.target;
          const dx = cam.position.x - t.x;
          const dy = cam.position.y - t.y;
          const dz = cam.position.z - t.z;

          cam.position.x = t.x + dx / factor;
          cam.position.y = t.y + dy / factor;
          cam.position.z = t.z + dz / factor;

          try { ctrls.update(); } catch (e) {}
        };

        bindBtn(zoomInBtn, () => zoomBy(zoomFactor));
        bindBtn(zoomOutBtn, () => zoomBy(1 / zoomFactor));

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
          window.setTimeout(safeResize, 80);
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
          window.setTimeout(safeResize, 80);
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
          window.setTimeout(safeResize, 80);
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

        $.getJSON(nodeInfoEndpoint, { uri: id, debug: 1 })
          .done((data) => {
            nodeInfoCache[id] = data || null;
            const waiters = nodeInfoWaiters[id] || [];
            delete nodeInfoWaiters[id];
            waiters.forEach((fn) => { try { fn(data || null); } catch (e) {} });
          })
          .fail(() => {
            nodeInfoCache[id] = null;
            const waiters = nodeInfoWaiters[id] || [];
            delete nodeInfoWaiters[id];
            waiters.forEach((fn) => { try { fn(null); } catch (e) {} });
          });
      }

      function renderExplorer(nodeId) {
        if (!explorer) return;
        const id = expandCurie(nodeId);

        explorer.innerHTML = '';

        const title = document.createElement('div');
        title.style.cssText = 'font-weight:600; font-size:14px; margin-bottom:6px;';
        title.textContent = 'Node';
        explorer.appendChild(title);

        explorer.appendChild(makeStatusRow('Loading node details…', 'rep-graph-status', id));

        ensureNodeInfo(id, (info) => {
          // Ignore out-of-order responses.
          const status = explorer.querySelector('#rep-graph-status');
          if (!status || status.getAttribute('data-node-id') !== id) return;

          explorer.innerHTML = '';

          if (!info || info.error) {
            explorer.appendChild(makeStatusRow('No details available.', 'rep-graph-status', id));
            return;
          }

          // Keep node label/type in sync.
          try {
            ensureNode({ id: id, label: info.label, typeUri: info.typeUri || info.hascoTypeUri || null });
          } catch (e) {}

          const hdr = document.createElement('div');
          hdr.style.cssText = 'display:flex; align-items:flex-start; justify-content:space-between; gap:10px;';

          const name = document.createElement('div');
          name.style.cssText = 'font-weight:700; font-size:14px; line-height:1.2;';
          name.textContent = String(info.label || defaultLabelForId(id));

          hdr.appendChild(name);
          explorer.appendChild(hdr);

          // URI row
          const uriRow = document.createElement('div');
          uriRow.style.cssText = 'font-size:12px; margin:6px 0 10px; word-break:break-word;';

          const uriLbl = document.createElement('span');
          uriLbl.style.cssText = 'font-weight:600;';
          uriLbl.textContent = 'URI: ';
          uriRow.appendChild(uriLbl);

          const uriVal = document.createElement('span');
          uriVal.textContent = id;
          uriRow.appendChild(uriVal);

          const local = buildLocalUriLink(id);
          if (local) {
            const open = document.createElement('a');
            open.href = local;
            open.target = '_blank';
            open.rel = 'noopener noreferrer';
            open.textContent = 'Open';
            open.style.cssText = 'margin-left:8px; font-size:12px; color:#007bff; white-space:nowrap;';
            uriRow.appendChild(open);
          }
          uriRow.appendChild(makeCopyLink('Copy', () => id));

          explorer.appendChild(uriRow);

          // Type URIs
          const types = [];
          if (info.hascoTypeUri) types.push({ key: 'hascoTypeUri', uri: info.hascoTypeUri });
          if (info.typeUri) types.push({ key: 'typeUri', uri: info.typeUri });

          if (types.length) {
            const sec = document.createElement('div');
            sec.style.cssText = 'margin:8px 0;';
            const t = document.createElement('div');
            t.style.cssText = 'font-weight:600; font-size:13px; margin-bottom:4px;';
            t.textContent = 'Types';
            sec.appendChild(t);

            types.forEach((tp) => {
              if (hiddenPredicateKeys.has(normalizePredKey(tp.key))) return;
              const row = document.createElement('div');
              row.style.cssText = 'font-size:12px; word-break:break-word;';
              const k = document.createElement('span');
              k.style.cssText = 'font-weight:600;';
              k.textContent = tp.key + ': ';
              row.appendChild(k);

              const v = document.createElement('span');
              v.textContent = String(tp.uri || '');
              row.appendChild(v);

              const local2 = buildLocalUriLink(tp.uri);
              if (local2) {
                const open2 = document.createElement('a');
                open2.href = local2;
                open2.target = '_blank';
                open2.rel = 'noopener noreferrer';
                open2.textContent = 'Open';
                open2.style.cssText = 'margin-left:8px; font-size:12px; color:#007bff; white-space:nowrap;';
                row.appendChild(open2);
              }
              row.appendChild(makeCopyLink('Copy', () => String(tp.uri || '')));
              sec.appendChild(row);
            });

            explorer.appendChild(sec);
          }

          // Fields
          if (Array.isArray(info.fields) && info.fields.length) {
            const sec = document.createElement('div');
            sec.style.cssText = 'margin:10px 0;';

            const t = document.createElement('div');
            t.style.cssText = 'font-weight:600; font-size:13px; margin-bottom:4px;';
            t.textContent = 'Attributes';
            sec.appendChild(t);

            info.fields.forEach((f) => {
              if (!f || !f.key) return;
              if (hiddenPredicateKeys.has(normalizePredKey(f.key))) return;

              const row = document.createElement('div');
              row.style.cssText = 'padding:4px 0; border-top:1px solid #eee;';

              const k = document.createElement('div');
              k.style.cssText = 'font-weight:600; font-size:12px;';
              k.textContent = String(f.key);

              const v = document.createElement('div');
              v.style.cssText = 'font-size:12px; word-break:break-word;';
              const val = String(f.value || '');

              if (String(f.key).toLowerCase() === 'comment') {
                const preview = document.createElement('div');
                preview.textContent = val;
                preview.style.cssText = [
                  'display:-webkit-box',
                  '-webkit-line-clamp:2',
                  '-webkit-box-orient:vertical',
                  'overflow:hidden',
                ].join(';');

                v.appendChild(preview);

                const more = document.createElement('a');
                more.href = '#';
                more.textContent = 'View full';
                more.style.cssText = 'display:inline-block; margin-top:2px; font-size:12px; color:#007bff;';
                more.addEventListener('click', (ev) => {
                  ev.preventDefault();
                  openTextModal('comment', val);
                });
                v.appendChild(more);

              } else {
                v.textContent = val;
              }

              row.appendChild(k);
              row.appendChild(v);
              sec.appendChild(row);
            });

            explorer.appendChild(sec);
          }

          // Links
          if (Array.isArray(info.links) && info.links.length) {
            const sec = document.createElement('div');
            sec.style.cssText = 'margin:10px 0;';

            const t = document.createElement('div');
            t.style.cssText = 'font-weight:600; font-size:13px; margin-bottom:4px;';
            t.textContent = 'Links';
            sec.appendChild(t);

            info.links.forEach((lnk) => {
              if (!lnk || !lnk.key || !lnk.uri) return;
              if (hiddenPredicateKeys.has(normalizePredKey(lnk.key))) return;

              const row = document.createElement('div');
              row.style.cssText = 'padding:4px 0; border-top:1px solid #eee; font-size:12px; word-break:break-word;';

              const k = document.createElement('span');
              k.style.cssText = 'font-weight:600;';
              k.textContent = String(lnk.key) + ': ';
              row.appendChild(k);

              const a = document.createElement('a');
              a.href = buildLocalUriLink(lnk.uri) || '#';
              a.target = '_blank';
              a.rel = 'noopener noreferrer';
              a.textContent = String(lnk.label || lnk.uri);
              a.style.cssText = 'color:#007bff;';
              row.appendChild(a);

              row.appendChild(makeCopyLink('Copy', () => String(lnk.uri)));
              sec.appendChild(row);
            });

            explorer.appendChild(sec);
          }

          // Predicates panel
          const predBox = document.createElement('div');
          predBox.style.cssText = 'margin:10px 0;';

          const t = document.createElement('div');
          t.style.cssText = 'font-weight:600; font-size:13px; margin-bottom:4px;';
          t.textContent = 'Predicates';
          predBox.appendChild(t);

          predBox.appendChild(makeStatusRow('', 'rep-graph-pred-status', id));

          const dblHint = document.createElement('div');
          dblHint.style.cssText = 'font-size:12px; opacity:.75; margin:4px 0 8px;';
          predBox.appendChild(dblHint);

          const predList = document.createElement('div');
          predList.style.cssText = 'display:flex; flex-direction:column; gap:6px;';
          predBox.appendChild(predList);

          explorer.appendChild(predBox);

          const renderPredicates = (info2) => {
            predList.innerHTML = '';

            const labels = labelsForNode(id, info2, Infinity);
            const willLoad = labelsForNode(id, info2, 6);

            dblHint.textContent = willLoad.length
              ? `Double-click will load (max 6): ${willLoad.join(', ')}`
              : 'Double-click will load: (none detected)';

            if (!labels.length) {
              const empty = document.createElement('div');
              empty.style.cssText = 'opacity:.75; font-size:13px;';
              empty.textContent = 'No predicates available for this node.';
              predList.appendChild(empty);
              return;
            }

            // Prefer server-provided predicates with counts.
            const server = (info2 && Array.isArray(info2.predicates)) ? info2.predicates : [];
            const serverKeys = new Set(server.map((p) => String(p && p.key || '').trim()).filter(Boolean));

            const merged = [];
            server.forEach((p) => {
              const key = String(p && p.key || '').trim();
              if (!key) return;
              if (hiddenPredicateKeys.has(normalizePredKey(key))) return;
              merged.push({ key, count: (typeof p.count === 'number') ? p.count : null });
            });

            labels.forEach((k) => {
              const key = String(k || '').trim();
              if (!key) return;
              if (hiddenPredicateKeys.has(normalizePredKey(key))) return;
              if (serverKeys.has(key)) return;
              merged.push({ key, count: null });
            });

            merged.forEach((p) => {
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

        if (!hiddenPredicateKeys.has(normalizePredKey('typeUri'))) labels.push('typeUri');
        if (!hiddenPredicateKeys.has(normalizePredKey('hascoTypeUri'))) labels.push('hascoTypeUri');

        const nodeObj = nodesById.get(nodeId);
        const typeUri = nodeObj && nodeObj.typeUri;
        if (isClassNode(nodeId, typeUri)) {
          labels.push('children');
          labels.push('super');
        }

        const preferred = ['contains', 'children', 'super', 'typeUri', 'hascoTypeUri'];
        let uniq = Array.from(new Set(labels.map((x) => String(x || '').trim()).filter(Boolean)));
        uniq = uniq.filter((k) => !hiddenPredicateKeys.has(normalizePredKey(k)));
        uniq.sort((a, b) => {
          const ia = preferred.indexOf(a);
          const ib = preferred.indexOf(b);
          if (ia !== -1 || ib !== -1) return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
          return String(a).localeCompare(String(b));
        });

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

      function mergeExpandPayload(payload, anchorId) {
        const outNew = [];
        if (!payload) return outNew;

        const nodes = Array.isArray(payload.nodes) ? payload.nodes : [];
        const edges = Array.isArray(payload.edges) ? payload.edges : [];

        const anchor = nodesById.get(anchorId);
        const ax = anchor && typeof anchor.x === 'number' ? anchor.x : 0;
        const ay = anchor && typeof anchor.y === 'number' ? anchor.y : 0;
        const az = anchor && typeof anchor.z === 'number' ? anchor.z : 0;

        nodes.forEach((n) => {
          const id = expandCurie(n && (n.id || n.uri));
          if (!id) return;
          if (nodesById.has(id)) {
            ensureNode(n);
            return;
          }

          // Spread new nodes around the anchor (stable-ish).
          const h = stableHash(id);
          const dx = ((h % 19) - 9) * 1.2;
          const dy = (((h / 19) % 19) - 9) * 1.2;
          const dz = (((h / (19 * 19)) % 19) - 9) * 1.2;

          const res = ensureNode(n, { x: ax + dx, y: ay + dy, z: az + dz });
          if (res && res.added) outNew.push(id);
        });

        edges.forEach((e) => {
          if (!e) return;
          const from = expandCurie(e.from);
          const to = expandCurie(e.to);
          const label = String(e.label || '').trim();
          if (!from || !to || !label) return;
          ensureNode({ id: from });
          ensureNode({ id: to });
          ensureLink(from, to, label);
        });

        try {
          graph.graphData(graphData);
          graph.d3ReheatSimulation();
          if (graph.refresh) graph.refresh();
        } catch (e) {}

        return outNew;
      }

      function expandNode(nodeId) {
        const id = expandCurie(nodeId);
        if (!id) return;
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
            const added = mergeExpandPayload(payload, id) || [];
            allNew.push(...added);
          }

          expanding[id] = false;

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
                  try { graph.graphData(graphData); graph.d3ReheatSimulation(); } catch (e) {}
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

          try { renderExplorer(id); } catch (e) {}
        });
      }

      // ---- Click / double-click detection ----
      let lastClickId = null;
      let lastClickAt = 0;

      graph.onNodeClick((node, ev) => {
        const id = expandCurie(node && node.id);
        if (!id) return;

        selectedNodeId = id;
        try { if (graph.refresh) graph.refresh(); } catch (e) {}
        try { renderExplorer(id); } catch (e2) {}

        const now = Date.now();
        const detail = ev && typeof ev.detail === 'number' ? ev.detail : 0;
        const isDbl = (detail >= 2) || (lastClickId === id && (now - lastClickAt) <= 320);
        lastClickId = id;
        lastClickAt = now;

        if (isDbl) {
          expandNode(id);
        }
      });

      // Pin nodes only after a real drag.
      graph.onNodeDragEnd((node) => {
        try {
          if (!node) return;
          node.fx = node.x;
          node.fy = node.y;
          node.fz = node.z;
        } catch (e) {}
      });

      // Initial explorer render (base node).
      if (selectedNodeId) {
        window.setTimeout(() => {
          try { renderExplorer(selectedNodeId); } catch (e) {}
        }, 10);
      }
    }
  };
})(jQuery, Drupal, drupalSettings);
