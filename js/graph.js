(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.graphInit = {
    attach: function (context, settings) {
      if (!context.querySelector || context.querySelector('#my-network')?.dataset.loaded === "true") return;

      const container = document.getElementById("my-network");
      if (!container || typeof vis === 'undefined') return;

      container.dataset.loaded = "true";

      const eyeSVG = `<i class="fa fa-eye"></i>`;
      const eyeOffSVG = `<i class="fa fa-eye-slash"></i>`;

      const nodes = new vis.DataSet(drupalSettings.graphData.nodes);
      const edges = new vis.DataSet(drupalSettings.graphData.edges);
      const extraNodes = drupalSettings.graphData.extraNodes;
      let extraEdges = drupalSettings.graphData.extraEdges; // << alterado de const para let

      // 🔁 Transformar loops (from === to) em conexões com nó virtual
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
        // Substituir a aresta de loop por aresta com destino ao nó virtual
        extraEdges.push({
          from: e.from,
          to: virtualNodeId,
          label: e.label
        });
      });
      // Remover os loops originais
      extraEdges = extraEdges.filter(e => e.from !== e.to);


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
          stabilization: { iterations: 50 },
          solver: 'forceAtlas2Based'
        }
      };

      const network = new vis.Network(container, { nodes, edges }, options);
      let selectedNodeId = null;
      const originalLabels = {};
      const expansionState = {};

      nodes.get().forEach(n => {
        originalLabels[n.id] = n.label;
        if (!n.label.includes('➕')) {
          nodes.update({
            id: n.id,
            label: `${n.label}\n➕`,
            font: { size: 14 }
          });
        }
      });

      const expandMenu = document.createElement("div");
      expandMenu.id = "expand-menu";
      expandMenu.style.cssText = "position:absolute;z-index:1000;background:#f8f9fa;border:1px solid #ccc;padding:6px 10px;border-radius:5px;box-shadow:2px 2px 6px rgba(0,0,0,0.1);display:none;";
      document.body.appendChild(expandMenu);

      function updateExpandButtonPosition() {
        if (!selectedNodeId) return;
        const nodePos = network.getPositions([selectedNodeId])[selectedNodeId];
        const canvasPos = network.canvasToDOM(nodePos);
        const networkRect = container.getBoundingClientRect();
        const topOffset = window.scrollY + networkRect.top;
        expandMenu.style.left = `${networkRect.left + canvasPos.x + 30}px`;
        expandMenu.style.top = `${topOffset + canvasPos.y - 10}px`;
      }

      network.on("click", function (params) {
        expandMenu.style.display = "none";
        if (params.nodes.length === 0) {
          selectedNodeId = null;
          return;
        }

        selectedNodeId = params.nodes[0];
        const relatedEdges = extraEdges.filter(e => e.from === selectedNodeId);
        let labels = [...new Set(
  relatedEdges
    .filter(e => extraNodes.some(n => n.id === e.to))
    .map(e => e.label)
)];

// Adiciona "hascoTypeUri" se houver aresta válida e destino existente
const hasHascoTypeUriEdge = extraEdges.some(e =>
  e.label === 'hascoTypeUri' &&
  e.from === selectedNodeId &&
  extraNodes.find(n => n.id === e.to)
);
if (hasHascoTypeUriEdge && !labels.includes('hascoTypeUri')) {
  labels.push('hascoTypeUri');
}


        expandMenu.innerHTML = '';

        labels.forEach(label => {
          const key = `${selectedNodeId}_${label}`;
          const isExpanded = expansionState[key] || false;

          const opt = document.createElement("div");
          opt.style.cssText = `
            cursor: pointer;
            margin: 2px 0;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            min-width: 200px;
          `;

          const labelSpan = document.createElement("span");
          labelSpan.textContent = label;

          const eyeIcon = document.createElement("span");
          eyeIcon.innerHTML = isExpanded ? eyeOffSVG : eyeSVG;

          opt.appendChild(labelSpan);
          opt.appendChild(eyeIcon);

          if (label === 'hasVirtualColumn' || label === 'hasSampleCollection') {
            opt.addEventListener("click", () => {
              if (opt.querySelector(".submenu")) {
                opt.querySelector(".submenu").remove();
                return;
              }
              const submenu = document.createElement("div");
              submenu.className = "submenu";
              submenu.style.cssText = "position:absolute; left:120px; top:0; background:#f1f1f1; border:1px solid #ccc; padding:5px; border-radius:4px; box-shadow:1px 1px 4px rgba(0,0,0,0.2); z-index:1001;";

              const vcEdges = relatedEdges.filter(e => e.label === label);

              vcEdges.forEach(e => {
                const vcNode = extraNodes.find(n => n.id === e.to);
                if (!vcNode) return;

                const edgeId = `${e.from}_${e.to}`;
                const isVisible = nodes.get(vcNode.id) !== null;

                const vcItem = document.createElement("div");
                vcItem.style.cssText = `
                  display: flex;
                  align-items: center;
                  justify-content: space-between;
                  gap: 12px;
                  padding: 4px 6px;
                  min-width: 240px;
                  cursor: default;
                `;

                const labelSpan = document.createElement("span");
                labelSpan.textContent = vcNode.label;
                labelSpan.style.cssText = "flex-grow: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;";

                const toggleBtn = document.createElement("span");
                toggleBtn.innerHTML = isVisible ? eyeOffSVG : eyeSVG;
                toggleBtn.style.cssText = "cursor: pointer;";

                toggleBtn.addEventListener("click", (ev) => {
                  ev.stopPropagation();
                  if (nodes.get(vcNode.id)) {
                    nodes.remove(vcNode.id);
                    edges.remove(edgeId);
                    toggleBtn.innerHTML = eyeSVG;
                  } else {
                    if (!vcNode.color) {
                      if (vcNode.shape === 'ellipse') {
                        vcNode.color = { background: '#28a745', border: '#1e7e34' };
                        vcNode.font = { color: 'black' };
                      } else {
                        vcNode.color = { background: '#007bff', border: '#0056b3' };
                        vcNode.font = { color: 'white' };
                      }
                    }
                    nodes.add(vcNode);
                    edges.add({ ...e, id: edgeId });
                    toggleBtn.innerHTML = eyeOffSVG;
                  }
                });

                vcItem.appendChild(labelSpan);
                vcItem.appendChild(toggleBtn);
                submenu.appendChild(vcItem);
              });

              opt.appendChild(submenu);
            });
          } else if (label === 'hascoTypeUri') {
  opt.addEventListener("click", () => {
    const uriEdge = extraEdges.find(e =>
      e.label === 'hascoTypeUri' && e.from === selectedNodeId
    );
    if (!uriEdge) return;

    const targetNode = extraNodes.find(n => n.id === uriEdge.to);
    if (!targetNode) return;

    const nodeAlreadyVisible = nodes.get(targetNode.id);

    if (nodeAlreadyVisible) {
      edges.remove({ id: `${uriEdge.from}_${uriEdge.to}` });

      const hasOtherConnections = edges.get().some(e =>
        e.from === targetNode.id || e.to === targetNode.id
      );
      if (!hasOtherConnections) {
        nodes.remove({ id: targetNode.id });
      }

      expansionState[key] = false;
      eyeIcon.innerHTML = eyeSVG;

    } else {
      if (!targetNode.label || targetNode.label.trim() === '') {
        const idParts = targetNode.id.split('/');
        targetNode.label = idParts[idParts.length - 1] || targetNode.id;
      }

      if (!targetNode.label.includes('➕')) {
        targetNode.label += '\n➕';
      }

      targetNode.font = targetNode.font || { size: 14 };
      if (!targetNode.color) {
        targetNode.color = {
          background: '#007bff',
          border: '#0056b3'
        };
        targetNode.font.color = 'white';
      }

      nodes.add(targetNode);
      edges.add({ ...uriEdge, id: `${uriEdge.from}_${uriEdge.to}` });

      expansionState[key] = true;
      eyeIcon.innerHTML = eyeOffSVG;
    }

    setTimeout(updateExpandButtonPosition, 0);
  });  

          } else {
            opt.addEventListener("click", () => {
              const edgesToToggle = relatedEdges.filter(e => e.label === label);
              const nodeIds = edgesToToggle.map(e => e.to);

              if (!expansionState[key]) {
                nodeIds.forEach(id => {
                  if (!nodes.get(id)) {
                    const restore = extraNodes.find(n => n.id === id);
                    if (restore) {
                      if (!restore.label || restore.label.trim() === '') {
                    const idParts = restore.id.split('/');
                    restore.label = idParts[idParts.length - 1] || restore.id;
                      }
                      if (!restore.label.includes('➕')) {
                        restore.label += '\n➕';
                      }
                      restore.font = restore.font || {};
                      restore.font.size = 14;

                      if (!restore.color) {
                        if (restore.shape === 'ellipse') {
                          restore.color = { background: '#28a745', border: '#1e7e34' };
                          restore.font = { color: 'black' };
                        } else {
                          restore.color = { background: '#007bff', border: '#0056b3' };
                          restore.font = { color: 'white' };
                        }
                      }

                      nodes.add(restore);
                      selectedNodeId = restore.id;
                      network.selectNodes([restore.id]);
                      network.emit("click", { nodes: [restore.id] });

                    }
                  }
                });
                edgesToToggle.forEach(e => {
                  const id = `${e.from}_${e.to}`;
                  if (!edges.get(id)) edges.add({ ...e, id });
                });
                expansionState[key] = true;
                eyeIcon.innerHTML = eyeOffSVG;
              } else {
  // 1. Primeiro remova as arestas
  edgesToToggle.forEach(e => {
    const edgeId = `${e.from}_${e.to}`;
    if (edges.get(edgeId)) {
      edges.remove(edgeId);
    }
  });

  // 2. Depois remova os nós, apenas se não tiverem mais conexões
  nodeIds.forEach(id => {
    if (id.includes('_loop_virtual_')) return; // nunca remover nó virtual

    const node = nodes.get(id);
    const hasOtherConnections = edges.get().some(e => e.from === id || e.to === id);

    if (node && !hasOtherConnections) {
      nodes.remove(id);
    }
  });
  expansionState[key] = false;
  eyeIcon.innerHTML = eyeSVG;
}

              setTimeout(updateExpandButtonPosition, 0);
            });
          }

          expandMenu.appendChild(opt);
        });

        expandMenu.style.display = "block";
        setTimeout(updateExpandButtonPosition, 0);
      });

      network.on("dragEnd", () => {
        if (expandMenu.style.display === "block") setTimeout(updateExpandButtonPosition, 0);
      });

      network.on("afterDrawing", () => {
        if (expandMenu.style.display === "block") setTimeout(updateExpandButtonPosition, 0);
      });

      const baseNodeId = nodes.getIds()[0];
      network.selectNodes([baseNodeId]);
      network.once("afterDrawing", () => {
        network.emit("click", { nodes: [baseNodeId] });
      });

      document.body.addEventListener("click", function (event) {
        const toggleWrapper = event.target.closest(".graph-toggle");
        if (toggleWrapper) {
          const nodeId = toggleWrapper.getAttribute("data-node");
          if (!nodeId) return;

          const nodeExists = nodes.get(nodeId);

          if (!nodeExists) {
            const node = extraNodes.find(n => n.id === nodeId);
            if (node) {
              if (!node.label.includes('➕')) {
                node.label += '\n➕';
              }
              node.font = node.font || {};
              node.font.size = 14;

              if (!node.color) {
                if (node.shape === 'ellipse') {
                  node.color = { background: '#28a745', border: '#1e7e34' };
                  node.font = { color: 'black' };
                } else {
                  node.color = { background: '#007bff', border: '#0056b3' };
                  node.font = { color: 'white' };
                }
              }

              nodes.add(node);
              const relatedEdges = extraEdges.filter(e => e.to === nodeId || e.from === nodeId);
              relatedEdges.forEach(edge => {
                const edgeId = `${edge.from}_${edge.to}`;
                if (!edges.get(edgeId)) {
                  edges.add({ ...edge, id: edgeId });
                }
              });

              toggleWrapper.innerHTML = eyeOffSVG;
            }
          } else {
            nodes.remove({ id: nodeId });
            const edgeIds = edges.getIds().filter(id => id.includes(nodeId));
            edges.remove(edgeIds);
            toggleWrapper.innerHTML = eyeSVG;
          }
        }
      });

      document.addEventListener("click", function (e) {
        if (!expandMenu.contains(e.target)) {
          const submenu = document.querySelector(".submenu");
          if (submenu) submenu.remove();
        }
      });

      window.graphNodes = nodes;
      window.graphEdges = edges;
      window.extraGraphNodes = extraNodes;
      window.extraGraphEdges = extraEdges;
    }
  };
})(jQuery, Drupal, drupalSettings);
