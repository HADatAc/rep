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
 */

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.graphInit = {
    attach: function (context) {
      // Prevent double init
      const container = context.querySelector('#my-network');
      if (!container || container.dataset.loaded === 'true') return;
      if (typeof vis === 'undefined') return;
      container.dataset.loaded = 'true';

      // Endpoint
      const socEndpoint =
        (drupalSettings && drupalSettings.rep && drupalSettings.rep.socObjectsEndpoint) ||
        (window.Drupal && Drupal.url ? Drupal.url('rep/graph/expand') : '/rep/graph/expand');

      // Icons
      const eyeSVG = `<i class="fa fa-eye"></i>`;
      const eyeOffSVG = `<i class="fa fa-eye-slash"></i>`;

      // Data caches
      const base = drupalSettings.graphData || {};
      const nodes = new vis.DataSet(base.nodes || []);
      const edges = new vis.DataSet(base.edges || []);
      const extraNodes = base.extraNodes || [];
      let   extraEdges = base.extraEdges || [];

      // ------ ID normalization (CURIE -> IRI) ------
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
      // normalize initial caches
      for (let i = 0; i < extraNodes.length; i++) extraNodes[i] = normalizeNode(extraNodes[i]);
      for (let i = 0; i < extraEdges.length; i++) extraEdges[i] = normalizeEdge(extraEdges[i]);

      // Track nodes for which we fetched
      const openedNodes = {};

      // ------ layout helpers ------
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

      // ------ convert loop edges into "virtual" nodes ------
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

      // ------ vis.js ------
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

      // add "+” affordance on visible nodes
      nodes.get().forEach(n => {
        if (!n.label?.includes('➕')) {
          nodes.update({ id: n.id, label: `${n.label}\n➕`, font: { size: 14 } });
        }
      });

      // ------ floating menu ------
      const expansionState = {}; // `${nodeId}_${label}` -> boolean
      const expandMenu = document.createElement("div");
      expandMenu.id = "expand-menu";
      expandMenu.style.cssText = `
        position:absolute;z-index:1000;background:#f8f9fa;border:1px solid #ccc;
        padding:6px 10px;border-radius:5px;box-shadow:2px 2px 6px rgba(0,0,0,0.1);
        display:none;
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

      // ------ utilities ------
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
            n.font.color = 'black';
          } else {
            n.color = { background: '#007bff', border: '#0056b3' };
            n.font = { ...(n.font || {}), color: 'white' };
          }
        }
        return n;
      }
      function isDisplayableLabel(label) {
        if (!label) return false;
        if (label === 'contains' || label === 'hascoTypeUri') return true;
        if (label === 'hasCollection') return false;
        if (label.startsWith('has')) return true;
        const blacklist = new Set(['label','comment','body','hasImageUri','hasWebDocument','hasStatus','id']);
        return !blacklist.has(label);
      }
      function buildLabelEdgesMap(relEdges) {
        const map = new Map();
        relEdges.forEach(e => {
          const E = normalizeEdge(e);
          const target = extraNodes.find(n => n.id === E.to);
          let lbl = e.label;
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

      // membership label helpers
      const isMemberLabel = (lbl) =>
        lbl === 'contains' || lbl === 'hasMember' || lbl === 'hasStudyObject' || lbl === 'hasObject';
      const isReverseMemberLabel = (lbl) =>
        lbl === 'isMemberOf' || lbl === 'memberOf' || lbl === 'isObjectOf';

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

      // ------ node click handler ------
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

        const relatedEdges = extraEdges.map(normalizeEdge).filter(e => e.from === selectedNodeId);

        // contains availability (forward or reverse)
        let hasContains =
          extraEdges.map(normalizeEdge).some(e => e.from === selectedNodeId && isMemberLabel(e.label)) ||
          extraEdges.map(normalizeEdge).some(e => e.to   === selectedNodeId && isReverseMemberLabel(e.label));

        if (hasContains) synthesizeContainsFromReverse(selectedNodeId);

        // lazy fetch for SOC if nothing known yet
        if (socEndpoint && kind === 'soc' && !hasContains && !openedNodes[selectedNodeId]) {
          openedNodes[selectedNodeId] = true;
          $.getJSON(socEndpoint, { from: selectedNodeId, limit: 100, offset: 0, debug: 1 })
            .done(data => {
              mergeGraphPayload(data, selectedNodeId);
              synthesizeContainsFromReverse(selectedNodeId);
              setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
            })
            .fail(() => { openedNodes[selectedNodeId] = false; });
          return;
        }

        // lazy fetch for Study -> SOCs
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
          $.getJSON(socEndpoint, { from: selectedNodeId, limit: 100, offset: 0, debug: 1 })
            .done(data => {
              mergeGraphPayload(data, selectedNodeId);
              setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
            })
            .fail(() => { openedNodes[selectedNodeId] = false; });
          return;
        }

        // map labels
        const labelEdgesMap = buildLabelEdgesMap(
          relatedEdges.filter(e => extraNodes.some(n => n.id === e.to))
        );

        let labels = Array.from(labelEdgesMap.keys()).filter(isDisplayableLabel);

        const hasTypeEdge = extraEdges.map(normalizeEdge).some(e =>
          e.label === 'hascoTypeUri' && e.from === selectedNodeId && extraNodes.find(n => n.id === e.to)
        );
        if (hasTypeEdge && !labels.includes('hascoTypeUri')) labels.push('hascoTypeUri');

        if (kind === 'soc' && !labels.includes('contains')) labels.unshift('contains');

        // build menu
        expandMenu.innerHTML = '';
        closeAllSubmenus();

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

          // ---------- CONTAINS as a SUBMENU (individual toggles) ----------
          if (label === 'contains') {
            opt.addEventListener("click", (ev) => {
              ev.stopPropagation();

              // Toggle if already open
              const existing = opt.querySelector(".submenu");
              if (existing) { existing.remove(); return; }

              closeAllSubmenus();

              // Get member edges (forward first)
              let memberEdges = extraEdges.map(normalizeEdge).filter(
                e => e.from === selectedNodeId && isMemberLabel(e.label)
              );

              // If none, synthesize from reverse and retry
              if (!memberEdges.length) {
                const reverse = extraEdges.map(normalizeEdge).filter(
                  e => e.to === selectedNodeId && isReverseMemberLabel(e.label)
                );
                if (reverse.length) {
                  synthesizeContainsFromReverse(selectedNodeId);
                  memberEdges = extraEdges.map(normalizeEdge).filter(
                    e => e.from === selectedNodeId && isMemberLabel(e.label)
                  );
                }
              }

              // If still none, fetch and rebuild
              if (!memberEdges.length) {
                if (!socEndpoint) return;
                $.getJSON(socEndpoint, { from: selectedNodeId, limit: 100, offset: 0, debug: 1 })
                  .done(data => {
                    mergeGraphPayload(data, selectedNodeId);
                    synthesizeContainsFromReverse(selectedNodeId);
                    setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
                  });
                return;
              }

              // Ensure every target node exists (create minimal stub if needed)
              memberEdges.forEach(e => {
                if (!extraNodes.find(n => n.id === e.to)) {
                  const stub = normalizeNode({ id: e.to, label: (e.to.split('/').pop() || e.to), shape: 'box' });
                  extraNodes.push(stub);
                }
              });

              // Build submenu
              const submenu = document.createElement("div");
              submenu.className = "submenu";
              submenu.style.cssText = `
                position:absolute; left:120px; top:0; background:#f1f1f1;
                border:1px solid #ccc; padding:5px; border-radius:4px;
                box-shadow:1px 1px 4px rgba(0,0,0,0.2); z-index:1001;
              `;

              memberEdges.forEach(e => {
                const child = extraNodes.find(n => n.id === e.to);
                if (!child) return;

                const id = edgeIdOf({ ...e, label: 'contains' }); // normalize id
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

                toggle.addEventListener("click", (e2) => {
                  e2.stopPropagation();
                  if (nodes.get(child.id)) {
                    if (edges.get(id)) edges.remove(id);
                    const still = edges.get().some(x => x.from === child.id || x.to === child.id);
                    if (!still) nodes.remove(child.id);
                    toggle.innerHTML = eyeSVG;
                  } else {
                    nodes.add(ensureNodeStyle({ ...child }));
                    if (!edges.get(id)) edges.add({ ...e, id, label: 'contains' });
                    toggle.innerHTML = eyeOffSVG;
                  }
                });

                row.appendChild(s);
                row.appendChild(toggle);
                submenu.appendChild(row);
              });

              opt.appendChild(submenu);
              setTimeout(() => updateExpandMenuPosition(selectedNodeId), 0);
            });

          // ---------- SOC/VC submenus ----------
          } else if (['hasVirtualColumn','hasSampleCollection','hasSubjectCollection','hasSpaceCollection','hasTimeCollection','hasCollection'].includes(label)) {
            opt.addEventListener("click", (ev) => {
              ev.stopPropagation();
              const existing = opt.querySelector(".submenu");
              if (existing) { existing.remove(); return; }
              closeAllSubmenus();

              const submenu = document.createElement("div");
              submenu.className = "submenu";
              submenu.style.cssText = `
                position:absolute; left:120px; top:0; background:#f1f1f1;
                border:1px solid #ccc; padding:5px; border-radius:4px;
                box-shadow:1px 1px 4px rgba(0,0,0,0.2); z-index:1001;
              `;

              let labelEdges = (buildLabelEdgesMap(relatedEdges)).get(label) || [];
              const socLabels = new Set(['hasSampleCollection','hasSubjectCollection','hasSpaceCollection','hasTimeCollection','hasCollection']);
              if (labelEdges.length === 0 && socEndpoint && socLabels.has(label)) {
                $.getJSON(socEndpoint, { from: selectedNodeId, limit: 100, offset: 0, debug: 1 })
                  .done(data => {
                    mergeGraphPayload(data, selectedNodeId);
                    setTimeout(() => network.emit && network.emit("click", { nodes: [selectedNodeId] }), 0);
                  });
                return;
              }

              labelEdges.forEach(({ edge: e, id }) => {
                const child = extraNodes.find(n => n.id === e.to);
                if (!child) return;

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

                toggle.addEventListener("click", (e2) => {
                  e2.stopPropagation();
                  if (nodes.get(child.id)) {
                    if (edges.get(id)) edges.remove(id);
                    const still = edges.get().some(x => x.from === child.id || x.to === child.id);
                    if (!still) nodes.remove(child.id);
                    toggle.innerHTML = eyeSVG;
                  } else {
                    nodes.add(ensureNodeStyle({ ...child }));
                    if (!edges.get(id)) edges.add({ ...e, id, label }); // normalized label
                    toggle.innerHTML = eyeOffSVG;
                  }
                });

                row.appendChild(s);
                row.appendChild(toggle);
                submenu.appendChild(row);
              });

              opt.appendChild(submenu);
              setTimeout(() => updateExpandMenuPosition(selectedNodeId), 0);
            });

          // ---------- Type link ----------
          } else if (label === 'hascoTypeUri') {
            opt.addEventListener("click", (ev) => {
              ev.stopPropagation();
              closeAllSubmenus();

              const uriEdge = extraEdges.map(normalizeEdge).find(e => e.label === 'hascoTypeUri' && e.from === selectedNodeId);
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

          // ---------- Generic toggle ----------
          } else {
            opt.addEventListener("click", (ev) => {
              ev.stopPropagation();
              closeAllSubmenus();

              const edgesToToggle = (buildLabelEdgesMap(relatedEdges)).get(label) || [];
              const nodeIds = edgesToToggle.map(obj => obj.edge.to);

              if (!expansionState[key]) {
                nodeIds.forEach(id => {
                  if (!nodes.get(id)) {
                    const src = extraNodes.find(n => n.id === id) ||
                                normalizeNode({ id, label: (id.split('/').pop() || id), shape: 'box' });
                    if (!extraNodes.find(n => n.id === src.id)) extraNodes.push(src);
                    nodes.add(ensureNodeStyle({ ...src }));
                  }
                });
                edgesToToggle.forEach(({ edge: e, id }) => {
                  if (!edges.get(id)) edges.add({ ...normalizeEdge(e), id, label }); // normalized label
                });
                expansionState[key] = true;
                eyeIcon.innerHTML = eyeOffSVG;
              } else {
                edgesToToggle.forEach(({ id }) => { if (edges.get(id)) edges.remove(id); });
                nodeIds.forEach(id => {
                  if (id.includes('_loop_virtual_')) return;
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

        // show menu
        expandMenu.style.display = "block";
        setTimeout(() => updateExpandMenuPosition(selectedNodeId), 0);
      });

      // ------ merge payload ------
      function mergeGraphPayload(payload, anchorNodeId) {
        if (!payload) return;
        const newNodes = (payload.nodes || []).map(normalizeNode);
        const newEdges = (payload.edges || []).map(normalizeEdge);

        newNodes.forEach(n => { if (!extraNodes.find(x => x.id === n.id)) extraNodes.push(n); });
        newEdges.forEach(e => {
          const id = edgeIdOf(e);
          if (!extraEdges.find(x => edgeIdOf(normalizeEdge(x)) === id)) {
            extraEdges.push({ ...e, id });
          }
        });

        const existing = new Set(nodes.getIds());
        const addedIds = [];
        newNodes.forEach(n => {
          if (!existing.has(n.id)) { nodes.add(ensureNodeStyle({ ...n })); addedIds.push(n.id); }
          else { nodes.update(ensureNodeStyle({ ...n })); }
        });
        const eExisting = new Set(edges.getIds());
        newEdges.forEach(e => {
          const id = edgeIdOf(e);
          if (!eExisting.has(id)) edges.add({ ...e, id });
        });

        if (addedIds.length) {
          freezeAllNodes(nodes);
          placeAround(network, anchorNodeId, addedIds);
          network.redraw();
          unfreezeNodes(nodes, addedIds);
        }
      }

      // keep menu position
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

      // auto-select first node
      const baseNodeId = nodes.getIds()[0];
      if (baseNodeId) {
        network.selectNodes([baseNodeId]);
        network.once("afterDrawing", () => {
          if (network.emit) network.emit("click", { nodes: [baseNodeId] });
        });
      }

      // external toggle buttons
      document.body.addEventListener("click", function (event) {
        const toggleWrapper = event.target.closest(".graph-toggle");
        if (!toggleWrapper) return;

        const nodeId = expandCurie(toggleWrapper.getAttribute("data-node"));
        if (!nodeId) return;

        const exists = !!nodes.get(nodeId);
        if (!exists) {
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

      // close submenus
      document.addEventListener("click", function (e) {
        if (!expandMenu.contains(e.target)) closeAllSubmenus();
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllSubmenus(); });

      // debug hooks
      window.graphNodes = nodes;
      window.graphEdges = edges;
      window.extraGraphNodes = extraNodes;
      window.extraGraphEdges = extraEdges;
    }
  };
})(jQuery, Drupal, drupalSettings);
