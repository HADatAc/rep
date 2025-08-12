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
 * This version unifies the submenu logic: EVERY label opens the same
 * paginated submenu (like "contains"), with « / » navigation and
 * incremental fetch (limit/offset) from the backend.
 * Additionally, we apply a defensive client-side cap ("slim") so that even if
 * the backend returns thousands of items, we only merge PAGE_SIZE per fetch.
 *
 * Important in this build:
 * - `hascoTypeUri` and `typeUri` are handled as different, independent labels everywhere.
 * - The external toggle (.graph-toggle) can target a specific label using data-label="...".
 *   If no data-label is provided, it falls back to the legacy behavior (toggle all edges to/from the node).
 */

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.graphInit = {
    attach: function (context) {
      const container = context.querySelector('#my-network');
      if (!container || container.dataset.loaded === 'true') return;
      if (typeof vis === 'undefined') return;
      container.dataset.loaded = 'true';

      // ----- Config & limits (with safe defaults) -----
      const socEndpoint =
        (drupalSettings && drupalSettings.rep && drupalSettings.rep.socObjectsEndpoint) ||
        (window.Drupal && Drupal.url ? Drupal.url('rep/graph/expand') : '/rep/graph/expand');

      const limits = (drupalSettings && drupalSettings.rep && drupalSettings.rep.graphLimits) || {};
      const MAX_MEMBERS_PER_SOC = Number(limits.maxMembersPerSOC || 5); // submenu window size
      const PAGE_SIZE           = Number(limits.pageSize         || 5); // server fetch size
      const MAX_LIVE_NODES      = Number(limits.maxLiveNodes     || 600);
      const AUTO_SHOW_ON_FETCH  = Number(limits.autoShowOnFetch  || 0);

      const eyeSVG = `<i class="fa fa-eye"></i>`;
      const eyeOffSVG = `<i class="fa fa-eye-slash"></i>`;

      // ----- Data caches -----
      const base = drupalSettings.graphData || {};
      const nodes = new vis.DataSet(base.nodes || []);
      const edges = new vis.DataSet(base.edges || []);
      const extraNodes = base.extraNodes || [];
      let   extraEdges = base.extraEdges || [];

      // ----- Normalize CURIE -> IRI -----
      const AHEAD = 'http://hadatac.org/ont/arrowhead/';
      function expandCurie(v) {
        return (typeof v === 'string' && v.startsWith('ahead:')) ? (AHEAD + v.slice(6)) : v;
      }
      function normalizeNode(n) {
        n = { ...n };
        n.id = expandCurie(n.id);
        if (n.typeUri) n.typeUri = expandCurie(n.typeUri);
        return n;
      }
      function normalizeEdge(e) {
        e = { ...e };
        e.from = expandCurie(e.from);
        e.to   = expandCurie(e.to);
        return e;
      }
      for (let i = 0; i < extraNodes.length; i++) extraNodes[i] = normalizeNode(extraNodes[i]);
      for (let i = 0; i < extraEdges.length; i++) extraEdges[i] = normalizeEdge(extraEdges[i]);

      // Guards & paging state
      const openedNodes = {};      // avoid double initial fetch per node
      // `${nodeId}:${label}` -> { offset, fetched, hasMoreServer, totalGuess, prefetchTried }
      const pageState = Object.create(null);

      // ----- Layout helpers -----
      function freezeAllNodes(ds) { ds.get().forEach(n => ds.update({ id: n.id, fixed: { x: true, y: true } })); }
      function unfreezeNodes(ds, ids) { ids.forEach(id => ds.update({ id, fixed: { x: false, y: false } })); }
      function placeAround(network, centerId, newIds, radius = 140) {
        const pos = network.getPositions([centerId])[centerId];
        if (!pos) return;
        const N = newIds.length || 1;
        newIds.forEach((id, i) => {
          const a = (2 * Math.PI * i) / N;
          network.moveNode(id, pos.x + radius * Math.cos(a), pos.y + radius * Math.sin(a));
        });
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
        extraEdges.push({ from: e.from, to: virtualNodeId, label: e.label });
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
        edges: { arrows: "to", smooth: true },
        layout: { improvedLayout: true },
        physics: { solver: 'repulsion', stabilization: { enabled: true, iterations: 500, updateInterval: 100 } }
      };
      const network = new vis.Network(container, { nodes, edges }, options);

      // Minor affordance: add "➕" line to existing labels so users know they can expand
      nodes.get().forEach(n => {
        if (!n.label?.includes('➕')) {
          nodes.update({ id: n.id, label: `${n.label}\n➕`, font: { size: 14 } });
        }
      });

      // ----- Floating menu container -----
      const expandMenu = document.createElement("div");
      expandMenu.id = "expand-menu";
      expandMenu.style.cssText = `
        position:absolute;z-index:1000;background:#f8f9fa;border:1px solid #ccc;
        padding:6px 10px;border-radius:5px;box-shadow:2px 2px 6px rgba(0,0,0,0.1);
        display:none; pointer-events:auto;
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

      // ----- Utilities -----
      function edgeIdOf(e) {
        const from = expandCurie(e.from);
        the_to = expandCurie(e.to);
        return e.id || `${from}_${the_to}_${e.label}`;
      }
      function ensureNodeStyle(n) {
        // Provide safe label + colors if missing
        if (!n.label || !n.label.trim()) {
          const p = (n.id || '').split('/');
          n.label = p[p.length - 1] || (n.id || '');
        }
        if (!n.label.includes('➕')) n.label += '\n➕';
        n.font = n.font || { size: 14 };
        if (!n.color) {
          if (n.shape === 'ellipse') {
            n.color = { background: '#28a745', border: '#1e7e34' };
            n.font = { ...(n.font || {}), color: 'black' };
          } else {
            n.color = { background: '#007bff', border: '#0056b3' };
            n.font = { ...(n.font || {}), color: 'white' };
          }
        }
        return n;
      }
      function isDisplayableLabel(label) {
        // Hide purely textual/meta predicates from the menu
        if (!label) return false;
        if (label === 'hasCollection') return false; // normalized below
        const blacklist = new Set(['label','comment','body','hasImageUri','hasWebDocument','hasStatus','id']);
        return !blacklist.has(label);
      }
      function buildLabelEdgesMap(relEdges) {
        // Build: label -> [{ edge, id, normalized }]
        const map = new Map();
        relEdges.forEach(e => {
          const E = normalizeEdge(e);
          const target = extraNodes.find(n => n.id === E.to);
          let lbl = e.label;

          // Normalize generic hasCollection into specific labels based on target.typeUri
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

      // Create synthetic "contains" edges from reverse membership if present
      function synthesizeContainsFromReverse(nodeId) {
        const rev = extraEdges
          .map(normalizeEdge)
          .filter(e => e.to === nodeId && isReverseMemberLabel(e.label));
        rev.forEach(e => {
          const synth = normalizeEdge({ from: nodeId, to: e.from, label: 'contains' });
          const id = edgeIdOf(synth);
          if (!extraEdges.find(x => edgeIdOf(normalizeEdge(x)) === id)) {
            extraEdges.push({ ...synth, id });
          }
        });
      }

      function canAddMoreVisibleNodes(addCount) {
        const current = nodes.length ? nodes.length : nodes.getIds().length;
        return (current + addCount) <= MAX_LIVE_NODES;
      }
      function warnNodeCap() {
        alert(`Node limit reached (${MAX_LIVE_NODES}). Hide some items before loading more.`);
      }

      // ---------- Defensive cap: keep only PAGE_SIZE items per fetch ----------
      function slimPayloadForLabel(data, nodeId, label, maxKeep) {
        const normNodes = (data.nodes || []).map(normalizeNode);
        const normEdges = (data.edges || []).map(normalizeEdge);

        // Accept only edges for the requested label (or normalized "contains")
        const accept = (e) => {
          if (e.from !== nodeId) return false;
          if (label === 'contains') return isMemberLabel(e.label); // normalize to 'contains'
          if (label === 'hascoTypeUri') return e.label === 'hascoTypeUri';
          if (label === 'typeUri') return e.label === 'typeUri';
          return e.label === label;
        };

        const keptEdges = [];
        const keptNodeIds = new Set();

        for (const e of normEdges) {
          if (!accept(e)) continue;
          const lbl = (label === 'contains') ? 'contains' : e.label;
          const id  = edgeIdOf({ ...e, label: lbl });
          keptEdges.push({ ...e, id, label: lbl });
          keptNodeIds.add(e.to);
          if (keptEdges.length >= maxKeep) break;
        }

        const keptNodes = normNodes.filter(n => keptNodeIds.has(n.id));
        return { nodes: keptNodes, edges: keptEdges, meta: data.meta || {} };
      }

      // ---------- Unified submenu helpers (EVERY label uses this) ----------
      function itemsForLabel(nodeId, label) {
        // Build current cached edges for this label from "nodeId"
        const norms = extraEdges.map(normalizeEdge);
        if (label === 'contains') {
          return norms
            .filter(e => e.from === nodeId && isMemberLabel(e.label))
            .map(e => ({ edge: { ...e, label: 'contains' }, id: edgeIdOf({ ...e, label: 'contains' }) }))
            .filter(obj => extraNodes.some(n => n.id === obj.edge.to));
        }
        const rel = norms.filter(e => e.from === nodeId);
        const map = buildLabelEdgesMap(rel);
        return (map.get(label) || []).filter(({ edge }) => extraNodes.some(n => n.id === edge.to));
      }

      function fetchMoreForLabel(nodeId, label, state, rightBtn, after) {
        // Request next page for this label (server-side)
        if (!socEndpoint) return;

        // Type edges are singletons; do not fetch/paginate them.
        if (label === 'hascoTypeUri' || label === 'typeUri') {
          state.hasMoreServer = false;
          after(0);
          return;
        }

        const params = { from: nodeId, limit: PAGE_SIZE, offset: state.fetched, debug: 1 };
        if (label === 'contains') params.relation = 'contains';
        else params.label = label;

        const prev = rightBtn.textContent;
        rightBtn.disabled = true; rightBtn.textContent = '…';

        $.getJSON(socEndpoint, params)
          .done(data => {
            const slim = slimPayloadForLabel(data, nodeId, label, PAGE_SIZE);
            const returned = slim.edges.length;

            mergeGraphPayload(slim, nodeId);

            state.fetched += returned;
            const meta = slim.meta || {};
            state.hasMoreServer = (typeof meta.totalGuess === 'number')
              ? (state.fetched < meta.totalGuess)
              : (returned === PAGE_SIZE);

            if (typeof meta.total === 'number') state.totalGuess = meta.total;
            else if (typeof meta.totalGuess === 'number') state.totalGuess = meta.totalGuess;

            after(returned);
          })
          .fail(() => {
            state.hasMoreServer = false;
            after(0);
          })
          .always(() => {
            rightBtn.textContent = prev;
            rightBtn.disabled = false;
          });
      }

      function openLabelSubmenu(opt, nodeId, label) {
        let submenu = opt.querySelector(".submenu");
        if (submenu) { submenu.remove(); return; }
        closeAllSubmenus();

        // Initialize paging state for (node,label) if missing
        const key = `${nodeId}:${label}`;
        if (!pageState[key]) {
          const now = itemsForLabel(nodeId, label).length;
          pageState[key] = {
            offset: 0,
            fetched: now,
            hasMoreServer: (now >= PAGE_SIZE),
            totalGuess: null,
            prefetchTried: false
          };
        }
        const state = pageState[key];

        submenu = document.createElement("div");
        submenu.className = "submenu";
        submenu.style.cssText = `
          position:absolute; left:140px; top:0; background:#f1f1f1;
          border:1px solid #ccc; padding:6px; border-radius:4px;
          box-shadow:1px 1px 4px rgba(0,0,0,0.2); z-index:1001;
          max-height: 340px; overflow:auto; min-width: 320px; pointer-events:auto;
        `;
        opt.appendChild(submenu);

        const renderPage = () => {
          submenu.innerHTML = '';

          // Current cached rows for this label
          const list = itemsForLabel(nodeId, label);
          const totalFetched = list.length;

          // Prefetch only for multi-item labels (skip both type labels)
          const fetchable = (label !== 'hascoTypeUri' && label !== 'typeUri');
          if (fetchable && totalFetched === 0 && !state.prefetchTried) {
            state.prefetchTried = true;
            state.hasMoreServer = true;
            fetchMoreForLabel(nodeId, label, state, { textContent:'', disabled:false }, () => {
              renderPage();
            });
            const loading = document.createElement('div');
            loading.style.cssText = "padding:6px 4px; opacity:.7;";
            loading.textContent = 'Loading...';
            submenu.appendChild(loading);
            return; // defer actual render until fetch returns
          }

          // Pagination window within cached items
          const start = Math.max(0, Math.min(state.offset, Math.max(0, totalFetched - MAX_MEMBERS_PER_SOC)));
          const end = Math.min(start + MAX_MEMBERS_PER_SOC, totalFetched);
          const page = list.slice(start, end);

          // Rows — IMPORTANT: toggle state is per-edge (not per-node)
          page.forEach(({ edge: e, id }) => {
            let child = extraNodes.find(n => n.id === e.to);
            if (!child) {
              child = normalizeNode({
                id: e.to,
                label: (e.to.split('/').pop() || e.to),
                shape: 'box'
              });
              extraNodes.push(child);
            }

            const displayLabel = (child.label && String(child.label).trim())
              ? child.label
              : (child.id?.split('/').pop() || child.id || '(no label)');
            if (!child.label || !String(child.label).trim()) {
              child.label = displayLabel;
            }

            const edgeId = id || edgeIdOf({ ...e, label });
            const edgeVisible = !!edges.get(edgeId);   // <-- check the edge, not the node

            const row = document.createElement("div");
            row.style.cssText = `
              display:flex; align-items:center; justify-content:space-between;
              gap:12px; padding:4px 6px; min-width:300px; cursor:default;
            `;

            const s = document.createElement("span");
            s.textContent = displayLabel;
            s.style.cssText = "flex-grow:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;";

            const toggle = document.createElement("span");
            toggle.innerHTML = edgeVisible ? eyeOffSVG : eyeSVG;
            toggle.style.cssText = "cursor:pointer; padding:2px 4px; display:inline-block;";

            // Submenu toggle adds/removes ONLY the selected (node,label) edge.
            toggle.addEventListener("click", (ev) => {
              ev.stopPropagation();

              const visibleNow = !!edges.get(edgeId);
              if (!visibleNow) {
                // Add ONLY this edge; keep typeUri and hascoTypeUri independent
                if (!nodes.get(child.id)) {
                  if (!canAddMoreVisibleNodes(1)) { warnNodeCap(); return; }
                  nodes.add(ensureNodeStyle({ ...child }));
                }
                if (!edges.get(edgeId)) edges.add({ ...e, id: edgeId, label });
                toggle.innerHTML = eyeOffSVG;
              } else {
                // Remove ONLY this edge
                edges.remove(edgeId);

                // If the child became isolated, remove it to keep the canvas clean
                const stillConnected = edges.get().some(x => x.from === child.id || x.to === child.id);
                if (!stillConnected) nodes.remove(child.id);

                toggle.innerHTML = eyeSVG;
              }
            });

            row.appendChild(s);
            row.appendChild(toggle);
            submenu.appendChild(row);
          });

          // Footer with « and » controls
          const footer = document.createElement('div');
          footer.style.cssText = "display:flex; justify-content:space-between; align-items:center; margin-top:6px; gap:8px;";

          const left = document.createElement('button');
          left.type = 'button';
          left.className = 'btn btn-sm btn-light';
          left.textContent = '«';
          left.disabled = (state.offset <= 0);
          left.onclick = (e3) => { e3.stopPropagation(); state.offset = Math.max(0, state.offset - MAX_MEMBERS_PER_SOC); renderPage(); };

          const info = document.createElement('span');
          info.style.cssText = "font-size:12px; opacity:.8;";
          const pageNum = Math.floor(state.offset / MAX_MEMBERS_PER_SOC) + 1;

          const knownTotal = (typeof state.totalGuess === 'number') ? state.totalGuess : null;
          const totalPagesKnown = knownTotal ? Math.max(1, Math.ceil(knownTotal / MAX_MEMBERS_PER_SOC)) : null;
          const totalPagesTxt = totalPagesKnown ?? (state.hasMoreServer ? '…' : Math.max(1, Math.ceil(totalFetched / MAX_MEMBERS_PER_SOC)));
          const totalCountTxt = knownTotal ?? (totalFetched + (state.hasMoreServer ? '+' : ''));

          info.textContent = `Page ${pageNum} / ${totalPagesTxt} — showing ${page.length} of ${totalCountTxt}`;

          const right = document.createElement('button');
          right.type = 'button';
          right.className = 'btn btn-sm btn-light';
          right.textContent = '»';

          const canAdvanceCached = (state.offset + MAX_MEMBERS_PER_SOC) < totalFetched;
          const canFetchMore = !!state.hasMoreServer && (label !== 'hascoTypeUri' && label !== 'typeUri');
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

          footer.appendChild(left);
          footer.appendChild(info);
          footer.appendChild(right);
          submenu.appendChild(footer);

          // Keep menu positioned with the node
          setTimeout(() => updateExpandMenuPosition(nodeId), 0);
        };

        renderPage();
      }

      // ----- Node click -> build floating menu and wire submenu for each label -----
      network.on("click", function (params) {
        expandMenu.style.display = "none";
        closeAllSubmenus();
        if (params.nodes.length === 0) return;

        const selectedNodeId = params.nodes[0];
        const selectedNode   = nodes.get(selectedNodeId) || extraNodes.find(n => n.id === selectedNodeId);
        if (!selectedNode) return;

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

        // If reverse membership is cached, synthesize "contains" edges
        const hasContains =
          extraEdges.map(normalizeEdge).some(e => e.from === selectedNodeId && isMemberLabel(e.label)) ||
          extraEdges.map(normalizeEdge).some(e => e.to   === selectedNodeId && isReverseMemberLabel(e.label));
        if (hasContains) synthesizeContainsFromReverse(selectedNodeId);

        // Lazy fetch initial SOC members if unknown
        if (socEndpoint && kind === 'soc' && !hasContains && !openedNodes[selectedNodeId]) {
          openedNodes[selectedNodeId] = true;
          $.getJSON(socEndpoint, { from: selectedNodeId, relation: 'contains', limit: PAGE_SIZE, offset: 0, debug: 1 })
            .done(data => {
              const slim = slimPayloadForLabel(data, selectedNodeId, 'contains', PAGE_SIZE);
              mergeGraphPayload(slim, selectedNodeId);
              setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
            })
            .fail(() => { openedNodes[selectedNodeId] = false; });
          return;
        }

        // Lazy fetch Study → SOCs (first page)
        const hasSOC = extraEdges.map(normalizeEdge).some(e =>
          e.from === selectedNodeId &&
          (e.label === 'hasSampleCollection' ||
           e.label === 'hasSubjectCollection' ||
           e.label === 'hasSpaceCollection'   ||
           e.label === 'hasTimeCollection'    ||
           e.label === 'hasCollection')
        );
        if (socEndpoint && kind === 'study' && !hasSOC && !openedNodes[selectedNodeId]) {
          openedNodes[selectedNodeId] = true;
          $.getJSON(socEndpoint, { from: selectedNodeId, limit: PAGE_SIZE, offset: 0, debug: 1 })
            .done(data => {
              mergeGraphPayload(data, selectedNodeId);
              setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
            })
            .fail(() => { openedNodes[selectedNodeId] = false; });
          return;
        }

        // Build available labels for this node from cached edges
        const relatedEdges = extraEdges.map(normalizeEdge).filter(e => e.from === selectedNodeId);
        const labelEdgesMap = buildLabelEdgesMap(
          relatedEdges.filter(e => extraNodes.some(n => n.id === e.to))
        );
        let labels = Array.from(labelEdgesMap.keys()).filter(isDisplayableLabel);

        // Ensure BOTH type labels appear independently when available
        const hasHascoType = extraEdges.map(normalizeEdge).some(e =>
          e.label === 'hascoTypeUri' && e.from === selectedNodeId && extraNodes.find(n => n.id === e.to)
        );
        const hasStdType = extraEdges.map(normalizeEdge).some(e =>
          e.label === 'typeUri' && e.from === selectedNodeId && extraNodes.find(n => n.id === e.to)
        );
        if (hasHascoType && !labels.includes('hascoTypeUri')) labels.push('hascoTypeUri');
        if (hasStdType && !labels.includes('typeUri')) labels.push('typeUri');

        if (kind === 'soc' && !labels.includes('contains')) labels.unshift('contains');

        // Render floating menu (each label opens the same unified submenu)
        expandMenu.innerHTML = '';
        closeAllSubmenus();

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

      // ----- Merge payload (respect limits, no auto-flood) -----
      function mergeGraphPayload(payload, anchorNodeId) {
        if (!payload) return;
        const newNodes = (payload.nodes || []).map(normalizeNode);
        const newEdges = (payload.edges || []).map(normalizeEdge);

        // Cache merge (dedupe)
        newNodes.forEach(n => { if (!extraNodes.find(x => x.id === n.id)) extraNodes.push(n); });
        newEdges.forEach(e => {
          const id = edgeIdOf(e);
          if (!extraEdges.find(x => edgeIdOf(normalizeEdge(x)) === id)) {
            extraEdges.push({ ...e, id });
          }
        });

        // Optional preview auto-show
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
            edges.add({ ...e, id });
          }
        });

        if (addedIds.length) {
          freezeAllNodes(nodes);
          placeAround(network, anchorNodeId, addedIds);
          network.redraw();
          unfreezeNodes(nodes, addedIds);
        }
      }

      // Keep menu positioned while dragging / drawing
      network.on("dragEnd", params => {
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

      // Auto-open first node
      const baseNodeId = nodes.getIds()[0];
      if (baseNodeId) {
        network.selectNodes([baseNodeId]);
        network.once("afterDrawing", () => {
          if (network.emit) network.emit("click", { nodes: [baseNodeId] });
        });
      }

      // ---------- External toggle buttons (.graph-toggle) ----------
      // Each eye can target a specific label via data-label (e.g., "typeUri" or "hascoTypeUri").
      // If data-label is missing, we fallback to the legacy behavior (toggle the node and all its edges).
      document.body.addEventListener("click", function (event) {
        const toggleWrapper = event.target.closest(".graph-toggle");
        if (!toggleWrapper) return;

        const nodeId = expandCurie(toggleWrapper.getAttribute("data-node") || "");
        if (!nodeId) return;

        // Optional: restrict to a specific label (e.g., "typeUri" / "hascoTypeUri")
        const onlyLabel = (toggleWrapper.getAttribute("data-label") || "").trim();

        // Helper: make sure a node is on canvas before adding edges to/from it
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

        // Determine current "active" state *for this label*:
        // - If onlyLabel is given: active = at least one visible edge of that label touches the node.
        // - Otherwise: active = the node itself is visible (legacy).
        const hasEdgesForLabel = () => edges.get().some(ed =>
          (ed.from === nodeId || ed.to === nodeId) && (!onlyLabel || ed.label === onlyLabel)
        );
        const isNodeVisible = !!nodes.get(nodeId);
        const isActive = onlyLabel ? hasEdgesForLabel() : isNodeVisible;

        if (!isActive) {
          // ---- ACTIVATE: show the node and only the requested label edges (if provided)
          if (!canAddMoreVisibleNodes(1)) { warnNodeCap(); return; }
          ensureVisibleNode(nodeId);

          const related = extraEdges
            .map(normalizeEdge)
            .filter(e => (e.to === nodeId || e.from === nodeId) && (!onlyLabel || e.label === onlyLabel));

          related.forEach(e => {
            const other = (e.from === nodeId) ? e.to : e.from;
            ensureVisibleNode(other);
            const id = edgeIdOf(e);
            if (!edges.get(id) && nodes.get(e.from) && nodes.get(e.to)) {
              edges.add({ ...e, id });
            }
          });

          toggleWrapper.innerHTML = eyeOffSVG;
        } else {
          // ---- DEACTIVATE
          if (onlyLabel) {
            // Remove only edges of that label touching this node
            const toRemove = edges.get().filter(ed =>
              (ed.from === nodeId || ed.to === nodeId) && ed.label === onlyLabel
            );
            const orphanCheck = new Set();
            toRemove.forEach(ed => {
              orphanCheck.add(ed.from);
              orphanCheck.add(ed.to);
              edges.remove(ed.id);
            });

            // Remove this node if it became isolated
            const stillHas = edges.get().some(ed => ed.from === nodeId || ed.to === nodeId);
            if (!stillHas) nodes.remove(nodeId);

            // Optionally remove other endpoints that also became isolated
            orphanCheck.forEach(nid => {
              if (nid === nodeId) return;
              if (!nodes.get(nid)) return;
              const hasAny = edges.get().some(ed => ed.from === nid || ed.to === nid);
              if (!hasAny) nodes.remove(nid);
            });
          } else {
            // Legacy: remove the node entirely and all edges touching it
            nodes.remove(nodeId);
            const ids = edges.getIds().filter(id =>
              id.startsWith(`${nodeId}_`) || id.includes(`_${nodeId}_`)
            );
            edges.remove(ids);
          }

          toggleWrapper.innerHTML = eyeSVG;
        }
      });

      // Close submenus globally (but only if click is outside the floating menu)
      document.addEventListener("click", function (e) {
        if (!expandMenu.contains(e.target)) closeAllSubmenus();
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllSubmenus(); });

      // Debug exports
      window.graphNodes = nodes;
      window.graphEdges = edges;
      window.extraGraphNodes = extraNodes;
      window.extraGraphEdges = extraEdges;
    }
  };
})(jQuery, Drupal, drupalSettings);
