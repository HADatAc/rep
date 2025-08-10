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
 * And the Study/SOC lazy endpoint:
 *   drupalSettings.rep.socObjectsEndpoint = '/rep/graph/expand'
 *
 * What it does:
 *  - Builds one vis.Network and never recreates it (preserves canvas/layout).
 *  - Keeps extraNodes/extraEdges as an in-memory cache for on-demand expansion.
 *  - Converts self-loops into virtual nodes so users can toggle them.
 *  - On node click:
 *      • shows an expand menu with the available predicates (labels)
 *      • if node looks like SOC and we don’t yet know “contains”, it fetches it
 *      • if node looks like Study and we don’t yet know SOC edges, it fetches them
 *  - Adds/removes nodes/edges incrementally.
 */

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.graphInit = {
    attach: function (context) {
      // Avoid attaching twice to the same canvas
      const container = context.querySelector('#my-network');
      if (!container || container.dataset.loaded === 'true') return;
      if (typeof vis === 'undefined') return;
      container.dataset.loaded = 'true';

      // Endpoint for lazy expansion (with safe fallbacks)
      const socEndpoint =
        (drupalSettings && drupalSettings.rep && drupalSettings.rep.socObjectsEndpoint) ||
        (window.Drupal && Drupal.url ? Drupal.url('rep/graph/expand') : '/rep/graph/expand');

      // Small icons (Font Awesome expected on the page)
      const eyeSVG = `<i class="fa fa-eye"></i>`;
      const eyeOffSVG = `<i class="fa fa-eye-slash"></i>`;

      // Bootstrap sources from drupalSettings
      const base = drupalSettings.graphData || {};
      const nodes = new vis.DataSet(base.nodes || []);
      const edges = new vis.DataSet(base.edges || []);
      const extraNodes = base.extraNodes || [];
      let   extraEdges = base.extraEdges || [];

      // Avoid repeated fetch for the same node
      const openedNodes = {};

      // ------------------ helpers: keep layout stable while adding stuff ------------------
      function freezeAllNodes(ds) {
        ds.get().forEach(n => ds.update({ id: n.id, fixed: { x: true, y: true } }));
      }
      function unfreezeNodes(ds, ids) {
        ids.forEach(id => ds.update({ id, fixed: { x: false, y: false } }));
      }
      function placeAround(network, centerId, newIds, radius = 140) {
        const pos = network.getPositions([centerId])[centerId];
        if (!pos) return;
        const N = newIds.length || 1;
        newIds.forEach((id, i) => {
          const a = (2 * Math.PI * i) / N;
          network.moveNode(id, pos.x + radius * Math.cos(a), pos.y + radius * Math.sin(a));
        });
      }

      // ------------------ convert loop edges into "virtual" nodes ------------------
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
      extraEdges = extraEdges.filter(e => e.from !== e.to); // drop original loops

      // ------------------ vis.js options ------------------
      const options = {
        nodes: {
          shape: "box",
          font: { align: "center", size: 14 },
          widthConstraint: { minimum: 70, maximum: 70 },
          heightConstraint: { minimum: 35 }
        },
        edges: { arrows: "to", smooth: true },
        layout: { improvedLayout: true },
        physics: {
          solver: 'repulsion',
          stabilization: {
            enabled: true,
            iterations: 500,
            updateInterval: 100
          }
        }
      };

      // Build network once (we only mutate datasets after this)
      const network = new vis.Network(container, { nodes, edges }, options);

      // Add a “+” affordance on initial visible nodes
      nodes.get().forEach(n => {
        if (!n.label?.includes('➕')) {
          nodes.update({ id: n.id, label: `${n.label}\n➕`, font: { size: 14 } });
        }
      });

      // ------------------ floating expand menu ------------------
      const expansionState = {}; // key `${nodeId}_${label}` → boolean

      const expandMenu = document.createElement("div");
      expandMenu.id = "expand-menu";
      expandMenu.style.cssText = `
        position:absolute;z-index:1000;background:#f8f9fa;border:1px solid #ccc;
        padding:6px 10px;border-radius:5px;box-shadow:2px 2px 6px rgba(0,0,0,0.1);
        display:none;
      `;
      document.body.appendChild(expandMenu);

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

      // ------------------ utilities ------------------
      function edgeIdOf(e) {
        return e.id || `${e.from}_${e.to}_${e.label}`;
      }

      function ensureNodeStyle(n) {
        if (!n.label || !n.label.trim()) {
          const p = n.id.split('/');
          n.label = p[p.length - 1] || n.id;
        }
        if (!n.label.includes('➕')) n.label += '\n➕';
        n.font = n.font || { size: 14 };
        if (!n.color) {
          if (n.shape === 'ellipse') {
            n.color = { background: '#28a745', border: '#1e7e34' };
            n.font.color = 'black';
          } else {
            n.color = { background: '#007bff', border: '#0056b3' };
            n.font = { ...(n.font || {}), color: 'white' };
          }
        }
        return n;
      }

      // Filter which predicate labels we want to show in the menu
      function isDisplayableLabel(label) {
        if (!label) return false;
        if (label === 'contains' || label === 'hascoTypeUri') return true;
        if (label.startsWith('has')) return true; // most domain links
        // hide common literals/internal props from the menu
        const blacklist = new Set(['label','comment','body','hasImageUri','hasWebDocument','hasStatus','id']);
        return !blacklist.has(label);
      }

      // Merge payload → cache + live datasets + position new nodes
      function mergeGraphPayload(payload, anchorNodeId) {
        if (!payload) return;
        const newNodes = payload.nodes || [];
        const newEdges = payload.edges || [];

        // Cache merge (avoid duplicates)
        newNodes.forEach(n => {
          if (!extraNodes.find(x => x.id === n.id)) extraNodes.push(n);
        });
        newEdges.forEach(e => {
          const id = edgeIdOf(e);
          if (!extraEdges.find(x => edgeIdOf(x) === id)) {
            e.id = id;
            extraEdges.push(e);
          }
        });

        // Live datasets + style
        const existing = new Set(nodes.getIds());
        const addedIds = [];
        newNodes.forEach(n => {
          if (!existing.has(n.id)) {
            nodes.add(ensureNodeStyle({ ...n }));
            addedIds.push(n.id);
          } else {
            nodes.update(ensureNodeStyle({ ...n }));
          }
        });
        const eExisting = new Set(edges.getIds());
        newEdges.forEach(e => {
          const id = edgeIdOf(e);
          if (!eExisting.has(id)) edges.add({ ...e, id });
        });

        // Place newcomers around anchor, without global shake
        if (addedIds.length) {
          freezeAllNodes(nodes);
          placeAround(network, anchorNodeId, addedIds);
          network.redraw();
          unfreezeNodes(nodes, addedIds);
        }
      }

      // Quick “kind” classifier by typeUri (used to decide when to lazy-load)
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

      // ------------------ node click: build menu + lazy-load if needed ------------------
      network.on("click", function (params) {
        expandMenu.style.display = "none";
        if (params.nodes.length === 0) return;

        const selectedNodeId = params.nodes[0];
        const selectedNode   = nodes.get(selectedNodeId) || extraNodes.find(n => n.id === selectedNodeId);
        if (!selectedNode) return;

        const kind = nodeKindByTypeUri(selectedNode?.typeUri);

        // Already-known edges from this node
        const relatedEdges = extraEdges.filter(e => e.from === selectedNodeId);

        // If SOC and we still don't know "contains", fetch it now (even if other labels exist)
        const hasContains = extraEdges.some(e => e.from === selectedNodeId && e.label === 'contains');
        if (socEndpoint && kind === 'soc' && !hasContains && !openedNodes[selectedNodeId]) {
          openedNodes[selectedNodeId] = true;
          $.getJSON(socEndpoint, { from: selectedNodeId, limit: 100, offset: 0, debug: 1 })
            .done(data => {
              console.log('[EXPAND]', selectedNodeId, data);
              mergeGraphPayload(data, selectedNodeId);
              setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
            })
            .fail(() => { openedNodes[selectedNodeId] = false; });
          return; // wait for AJAX
        }

        // If Study and we still don't know SOC edges, fetch them now
        const hasSOC = extraEdges.some(e =>
          e.from === selectedNodeId &&
          (e.label === 'hasSampleCollection' || e.label === 'hasSubjectCollection' || e.label === 'hasCollection')
        );
        if (socEndpoint && kind === 'study' && !hasSOC && !openedNodes[selectedNodeId]) {
          openedNodes[selectedNodeId] = true;
          $.getJSON(socEndpoint, { from: selectedNodeId, limit: 100, offset: 0, debug: 1 })
            .done(data => {
              console.log('[EXPAND]', selectedNodeId, data);
              mergeGraphPayload(data, selectedNodeId);
              setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
            })
            .fail(() => { openedNodes[selectedNodeId] = false; });
          return; // wait for AJAX
        }

        // Build the list of labels we can toggle in the menu
        let labels = [...new Set(
          relatedEdges
            .filter(e => extraNodes.some(n => n.id === e.to))
            .map(e => e.label)
        )].filter(isDisplayableLabel);

        // Always expose type edge if already known
        const hasTypeEdge = extraEdges.some(e =>
          e.label === 'hascoTypeUri' && e.from === selectedNodeId && extraNodes.find(n => n.id === e.to)
        );
        if (hasTypeEdge && !labels.includes('hascoTypeUri')) labels.push('hascoTypeUri');

        // Always show "contains" for SOCs (even if not loaded yet)
        if (kind === 'soc' && !labels.includes('contains')) labels.unshift('contains');

        // Build the floating menu
        expandMenu.innerHTML = '';

        labels.forEach(label => {
          const key = `${selectedNodeId}_${label}`;
          const isExpanded = !!expansionState[key];

          const opt = document.createElement("div");
          opt.style.cssText = `
            cursor: pointer; margin: 2px 0; position: relative;
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; min-width: 220px;
          `;

          const labelSpan = document.createElement("span");
          labelSpan.textContent = label;

          const eyeIcon = document.createElement("span");
          eyeIcon.innerHTML = isExpanded ? eyeOffSVG : eyeSVG;

          opt.appendChild(labelSpan);
          opt.appendChild(eyeIcon);

          // SPECIAL: "contains" toggle for SOCs (will fetch if missing, else toggle)
          if (label === 'contains') {
            opt.addEventListener("click", () => {
              const isMemberLabel = (lbl) =>
                lbl === 'contains' || lbl === 'hasMember' || lbl === 'hasStudyObject' || lbl === 'hasObject';

              let edgesContains = extraEdges.filter(
                e => e.from === selectedNodeId && isMemberLabel(e.label)
              );

              // Not loaded yet? Fetch and reopen menu.
              if (!edgesContains.length) {
                if (!socEndpoint) return;
                $.getJSON(socEndpoint, { from: selectedNodeId, limit: 100, offset: 0, debug: 1 })
                  .done(data => {
                    console.log('[graph] contains response', data);
                    mergeGraphPayload(data, selectedNodeId);

                    // Refresh local cache after merge
                    edgesContains = extraEdges.filter(
                      e => e.from === selectedNodeId && isMemberLabel(e.label)
                    );

                    if (!edgesContains.length) return; // nothing to show
                    setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
                  })
                  .fail(xhr => console.warn('[graph] contains fetch failed', xhr.status, xhr.responseText));
                return;
              }

              // Toggle all contained nodes/edges at once
              const nodeIds = edgesContains.map(e => e.to);
              if (!expansionState[key]) {
                nodeIds.forEach(id => {
                  if (!nodes.get(id)) {
                    const src = extraNodes.find(n => n.id === id);
                    if (src) nodes.add(ensureNodeStyle({ ...src }));
                  }
                });
                edgesContains.forEach(e => {
                  const id = edgeIdOf(e);
                  if (!edges.get(id)) edges.add({ ...e, id });
                });
                expansionState[key] = true;
                eyeIcon.innerHTML = eyeOffSVG;
              } else {
                edgesContains.forEach(e => {
                  const id = edgeIdOf(e);
                  if (edges.get(id)) edges.remove(id);
                });
                nodeIds.forEach(id => {
                  const hasOther = edges.get().some(e => e.from === id || e.to === id);
                  if (!hasOther && nodes.get(id)) nodes.remove(id);
                });
                expansionState[key] = false;
                eyeIcon.innerHTML = eyeSVG;
              }

              setTimeout(() => updateExpandMenuPosition(selectedNodeId), 0);
            });

          // Submenu for VC/SOCs (toggle children individually)
          } else if (label === 'hasVirtualColumn' || label === 'hasSampleCollection' || label === 'hasSubjectCollection') {
            opt.addEventListener("click", () => {
              if (opt.querySelector(".submenu")) {
                opt.querySelector(".submenu").remove();
                return;
              }

              const submenu = document.createElement("div");
              submenu.className = "submenu";
              submenu.style.cssText = `
                position:absolute; left:120px; top:0; background:#f1f1f1;
                border:1px solid #ccc; padding:5px; border-radius:4px;
                box-shadow:1px 1px 4px rgba(0,0,0,0.2); z-index:1001;
              `;

              let labelEdges = relatedEdges.filter(e => e.label === label);

              // If none yet for SOC labels, fetch and rebuild submenu
              if (labelEdges.length === 0 && socEndpoint &&
                  (label === 'hasSampleCollection' || label === 'hasSubjectCollection')) {
                $.getJSON(socEndpoint, { from: selectedNodeId, limit: 100, offset: 0, debug: 1 })
                  .done(data => {
                    console.log('[EXPAND submenu]', selectedNodeId, data);
                    mergeGraphPayload(data, selectedNodeId);
                    setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
                  });
                return;
              }

              // One row per child node
              labelEdges.forEach(e => {
                const child = extraNodes.find(n => n.id === e.to);
                if (!child) return;

                const id = edgeIdOf(e);
                const isVisible = !!nodes.get(child.id);

                const row = document.createElement("div");
                row.style.cssText = `
                  display:flex; align-items:center; justify-content:space-between;
                  gap:12px; padding:4px 6px; min-width:260px; cursor:default;
                `;

                const s = document.createElement("span");
                s.textContent = child.label;
                s.style.cssText = "flex-grow:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;";

                const toggle = document.createElement("span");
                toggle.innerHTML = isVisible ? eyeOffSVG : eyeSVG;
                toggle.style.cssText = "cursor:pointer;";

                toggle.addEventListener("click", (ev) => {
                  ev.stopPropagation();
                  if (nodes.get(child.id)) {
                    if (edges.get(id)) edges.remove(id);
                    const still = edges.get().some(x => x.from === child.id || x.to === child.id);
                    if (!still) nodes.remove(child.id);
                    toggle.innerHTML = eyeSVG;
                  } else {
                    nodes.add(ensureNodeStyle({ ...child }));
                    if (!edges.get(id)) edges.add({ ...e, id });
                    toggle.innerHTML = eyeOffSVG;
                  }
                });

                row.appendChild(s);
                row.appendChild(toggle);
                submenu.appendChild(row);
              });

              opt.appendChild(submenu);
            });

          // Type link (single target)
          } else if (label === 'hascoTypeUri') {
            opt.addEventListener("click", () => {
              const uriEdge = extraEdges.find(e => e.label === 'hascoTypeUri' && e.from === selectedNodeId);
              if (!uriEdge) return;
              const targetNode = extraNodes.find(n => n.id === uriEdge.to);
              if (!targetNode) return;

              const id = edgeIdOf(uriEdge);
              const visible = !!nodes.get(targetNode.id);

              if (visible) {
                if (edges.get(id)) edges.remove(id);
                const hasOther = edges.get().some(e => e.from === targetNode.id || e.to === targetNode.id);
                if (!hasOther) nodes.remove(targetNode.id);
                expansionState[key] = false;
                eyeIcon.innerHTML = eyeSVG;
              } else {
                nodes.add(ensureNodeStyle({ ...targetNode }));
                if (!edges.get(id)) edges.add({ ...uriEdge, id });
                expansionState[key] = true;
                eyeIcon.innerHTML = eyeOffSVG;
              }
              setTimeout(() => updateExpandMenuPosition(selectedNodeId), 0);
            });

          // Generic toggle: add/remove all nodes for this predicate at once
          } else {
            opt.addEventListener("click", () => {
              const edgesToToggle = relatedEdges.filter(e => e.label === label);
              const nodeIds = edgesToToggle.map(e => e.to);

              if (!expansionState[key]) {
                nodeIds.forEach(id => {
                  if (!nodes.get(id)) {
                    const src = extraNodes.find(n => n.id === id);
                    if (src) nodes.add(ensureNodeStyle({ ...src }));
                  }
                });
                edgesToToggle.forEach(e => {
                  const id = edgeIdOf(e);
                  if (!edges.get(id)) edges.add({ ...e, id });
                });
                expansionState[key] = true;
                eyeIcon.innerHTML = eyeOffSVG;
              } else {
                edgesToToggle.forEach(e => {
                  const id = edgeIdOf(e);
                  if (edges.get(id)) edges.remove(id);
                });
                nodeIds.forEach(id => {
                  if (id.includes('_loop_virtual_')) return; // keep virtual node
                  const hasOther = edges.get().some(e => e.from === id || e.to === id);
                  if (!hasOther && nodes.get(id)) nodes.remove(id);
                });
                expansionState[key] = false;
                eyeIcon.innerHTML = eyeSVG;
              }
              setTimeout(() => updateExpandMenuPosition(selectedNodeId), 0);
            });
          }

          expandMenu.appendChild(opt);
        });

        // Show & position menu
        expandMenu.style.display = "block";
        setTimeout(() => updateExpandMenuPosition(selectedNodeId), 0);
      });

      // Keep menu next to node while dragging/redrawing
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

      // Select base node and open its menu once
      const baseNodeId = nodes.getIds()[0];
      if (baseNodeId) {
        network.selectNodes([baseNodeId]);
        network.once("afterDrawing", () => {
          if (network.emit) network.emit("click", { nodes: [baseNodeId] });
        });
      }

      // Optional: support external toggle buttons (.graph-toggle[data-node="<id>"])
      document.body.addEventListener("click", function (event) {
        const toggleWrapper = event.target.closest(".graph-toggle");
        if (!toggleWrapper) return;

        const nodeId = toggleWrapper.getAttribute("data-node");
        if (!nodeId) return;

        const exists = !!nodes.get(nodeId);
        if (!exists) {
          const node = extraNodes.find(n => n.id === nodeId);
          if (!node) return;
          nodes.add(ensureNodeStyle({ ...node }));
          const related = extraEdges.filter(e => e.to === nodeId || e.from === nodeId);
          related.forEach(e => {
            const id = edgeIdOf(e);
            if (!edges.get(id)) edges.add({ ...e, id });
          });
          toggleWrapper.innerHTML = eyeOffSVG;
        } else {
          nodes.remove(nodeId);
          // Remove all edges touching this node
          const ids = edges.getIds().filter(id =>
            id.startsWith(`${nodeId}_`) || id.includes(`_${nodeId}_`)
          );
          edges.remove(ids);
          toggleWrapper.innerHTML = eyeSVG;
        }
      });

      // Close any submenu when clicking outside
      document.addEventListener("click", function (e) {
        if (!expandMenu.contains(e.target)) {
          const submenu = document.querySelector(".submenu");
          if (submenu) submenu.remove();
        }
      });

      // Expose for quick debugging in console
      window.graphNodes = nodes;
      window.graphEdges = edges;
      window.extraGraphNodes = extraNodes;
      window.extraGraphEdges = extraEdges;
    }
  };
})(jQuery, Drupal, drupalSettings);
