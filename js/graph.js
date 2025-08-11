/**
 * rep/vis_graph_panel : Graph behavior with lazy-loading (vis.js)
 *
 * Expects backend to inject:
 *   drupalSettings.graphData = {
 *     nodes: [...],
 *     edges: [...],
 *     extraNodes: [...],
 *     extraEdges: [...]
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
      const MAX_MEMBERS_PER_SOC = Number(limits.maxMembersPerSOC || 5); // client page size
      const PAGE_SIZE           = Number(limits.pageSize         || 5); // server page size
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
      const openedNodes = {};                             // fetched-once guard (per node for initial lazy fetch)
      const pageState = Object.create(null);              // `${nodeId}:${label}` -> { offset, fetched, hasMoreServer, prefetchTried? }

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

      // Small affordance: add "➕" line to existing labels
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
        const to   = expandCurie(e.to);
        return e.id || `${from}_${to}_${e.label}`;
      }
      function ensureNodeStyle(n) {
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
        if (!label) return false;
        if (label === 'hasCollection') return false; // normalized below
        const blacklist = new Set(['label','comment','body','hasImageUri','hasWebDocument','hasStatus','id']);
        return !blacklist.has(label);
      }
      function buildLabelEdgesMap(relEdges) {
        // Map: label -> [{ edge, id, normalized }]
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

      // ---------- Unified submenu helpers (EVERY label uses this) ----------
      function itemsForLabel(nodeId, label) {
        // Build the list of edges currently cached for this label from "nodeId"
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
        // Ask the backend for the next page for this label
        if (!socEndpoint) return;
        const params = { from: nodeId, limit: PAGE_SIZE, offset: state.fetched, debug: 1 };
        if (label === 'contains') params.relation = 'contains';
        else params.label = label;

        const prev = rightBtn.textContent;
        rightBtn.disabled = true; rightBtn.textContent = '…';

        const before = itemsForLabel(nodeId, label).length;

        $.getJSON(socEndpoint, params)
          .done(data => {
            mergeGraphPayload(data, nodeId);
            // Recompute returned count based on what we can see in cache
            const afterCount = itemsForLabel(nodeId, label).length;
            const returned = Math.max(0, afterCount - before);
            state.fetched += returned;
            const meta = (data && data.meta) || {};
            state.hasMoreServer = (typeof meta.totalGuess === 'number')
              ? (state.fetched < meta.totalGuess)
              : (returned === PAGE_SIZE);
            after(returned);
          })
          .fail(() => {
            // if it fails, stop trying to fetch infinitely
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
          pageState[key] = { offset: 0, fetched: now, hasMoreServer: false, prefetchTried: false };
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

          // If nothing cached yet (and it's not a trivial 1-item label), try a prefetch
          const fetchable = (label !== 'hascoTypeUri');
          if (fetchable && totalFetched === 0 && !state.prefetchTried) {
            state.prefetchTried = true;
            // Assume there *may* be more on server until proven otherwise
            state.hasMoreServer = true;
            fetchMoreForLabel(nodeId, label, state, { textContent:'', disabled:false }, (returned) => {
              // Re-render after the first batch
              renderPage();
            });
            // Show a light skeleton for UX
            const loading = document.createElement('div');
            loading.style.cssText = "padding:6px 4px; opacity:.7;";
            loading.textContent = 'Loading...';
            submenu.appendChild(loading);
            return; // defer actual render until fetch returns
          }

          // Pagination window within cached items
          const start = Math.max(
            0,
            Math.min(state.offset, Math.max(0, totalFetched - MAX_MEMBERS_PER_SOC))
          );
          const end = Math.min(start + MAX_MEMBERS_PER_SOC, totalFetched);
          const page = list.slice(start, end);

          // Rows
          page.forEach(({ edge: e, id }) => {
            const child = extraNodes.find(n => n.id === e.to)
                        || normalizeNode({ id: e.to, label: (e.to.split('/').pop() || e.to), shape: 'box' });
            if (!extraNodes.find(n => n.id === child.id)) extraNodes.push(child);

            const edgeId = id || edgeIdOf({ ...e, label }); // ensure stable id
            const isVisible = !!nodes.get(child.id);

            const row = document.createElement("div");
            row.style.cssText = `
              display:flex; align-items:center; justify-content:space-between;
              gap:12px; padding:4px 6px; min-width:300px; cursor:default;
            `;

            const s = document.createElement("span");
            s.textContent = child.label;
            s.style.cssText = "flex-grow:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;";

            const toggle = document.createElement("span");
            toggle.innerHTML = isVisible ? eyeOffSVG : eyeSVG;
            toggle.style.cssText = "cursor:pointer; padding:2px 4px; display:inline-block;";

            toggle.addEventListener("click", (ev) => {
              ev.stopPropagation();
              if (!nodes.get(child.id)) {
                if (!canAddMoreVisibleNodes(1)) { warnNodeCap(); return; }
                nodes.add(ensureNodeStyle({ ...child }));
                if (!edges.get(edgeId)) edges.add({ ...e, id: edgeId, label });
                toggle.innerHTML = eyeOffSVG;
              } else {
                if (edges.get(edgeId)) edges.remove(edgeId);
                const still = edges.get().some(x => x.from === child.id || x.to === child.id);
                if (!still) nodes.remove(child.id);
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
          const totalPages = Math.max(1, Math.ceil(totalFetched / MAX_MEMBERS_PER_SOC));
          info.textContent = `Page ${pageNum} / ${totalPages} — showing ${page.length} of ${totalFetched}${state.hasMoreServer ? '+' : ''}`;

          const right = document.createElement('button');
          right.type = 'button';
          right.className = 'btn btn-sm btn-light';
          right.textContent = '»';

          const canAdvanceCached = (state.offset + MAX_MEMBERS_PER_SOC) < totalFetched;
          const canFetchMore = !!state.hasMoreServer && (label !== 'hascoTypeUri');
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

          // keep menu positioned
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

        // Lazy fetch initial SOC members or Study SOCs if we know nothing yet
        if (socEndpoint && kind === 'soc' && !hasContains && !openedNodes[selectedNodeId]) {
          openedNodes[selectedNodeId] = true;
          $.getJSON(socEndpoint, { from: selectedNodeId, relation: 'contains', limit: PAGE_SIZE, offset: 0, debug: 1 })
            .done(data => {
              mergeGraphPayload(data, selectedNodeId);
              setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
            })
            .fail(() => { openedNodes[selectedNodeId] = false; });
          return;
        }
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

        // Ensure type and contains appear when available/meaningful
        const hasTypeEdge = extraEdges.map(normalizeEdge).some(e =>
          e.label === 'hascoTypeUri' && e.from === selectedNodeId && extraNodes.find(n => n.id === e.to)
        );
        if (hasTypeEdge && !labels.includes('hascoTypeUri')) labels.push('hascoTypeUri');
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

      // External toggle buttons (.graph-toggle)
      document.body.addEventListener("click", function (event) {
        const toggleWrapper = event.target.closest(".graph-toggle");
        if (!toggleWrapper) return;

        const nodeId = expandCurie(toggleWrapper.getAttribute("data-node"));
        if (!nodeId) return;

        const exists = !!nodes.get(nodeId);
        if (!exists) {
          if (!canAddMoreVisibleNodes(1)) { warnNodeCap(); return; }
          const node = extraNodes.find(n => n.id === nodeId) ||
                       normalizeNode({ id: nodeId, label: (nodeId.split('/').pop() || nodeId), shape: 'box' });
          if (!extraNodes.find(n => n.id === node.id)) extraNodes.push(node);
          nodes.add(ensureNodeStyle({ ...node }));
          const related = extraEdges.map(normalizeEdge).filter(e => e.to === nodeId || e.from === nodeId);
          related.forEach(e => {
            const id = edgeIdOf(e);
            if (!edges.get(id)) edges.add({ ...e, id });
          });
          toggleWrapper.innerHTML = eyeOffSVG;
        } else {
          nodes.remove(nodeId);
          const ids = edges.getIds().filter(id =>
            id.startsWith(`${nodeId}_`) || id.includes(`_${nodeId}_`)
          );
          edges.remove(ids);
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
