(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.graphInit = {
    attach: function (context, settings) {
      if (!context.querySelector || context.querySelector('#my-network')?.dataset.loaded === "true") return;

      const container = document.getElementById("my-network");
      if (!container || typeof vis === 'undefined') return;

      container.dataset.loaded = "true";

      const nodes = new vis.DataSet(drupalSettings.graphData.nodes);
      const edges = new vis.DataSet(drupalSettings.graphData.edges);
      const extraNodes = drupalSettings.graphData.extraNodes;
      const extraEdges = drupalSettings.graphData.extraEdges;

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
        nodes.update({
          id: n.id,
          label: `${n.label}\n➕`,
          font: { size: 14 }
        });
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
        const labels = [...new Set(relatedEdges.map(e => e.label))];
        expandMenu.innerHTML = '';

        labels.forEach(label => {
          const key = `${selectedNodeId}_${label}`;
          const isExpanded = expansionState[key] || false;
          const opt = document.createElement("div");
          opt.textContent = `${isExpanded ? "🙈" : "👁️"} ${label}`;
          opt.style.cssText = "cursor:pointer;margin:2px 0;";
          opt.addEventListener("click", () => {
            const edgesToToggle = relatedEdges.filter(e => e.label === label);
            const nodeIds = edgesToToggle.map(e => e.to);
            if (!expansionState[key]) {
              nodeIds.forEach(id => {
                if (!nodes.get(id)) {
                  const restore = extraNodes.find(n => n.id === id);
                  if (restore) {
                    restore.color = restore.shape === 'ellipse'
                      ? { background: '#28a745', border: '#1e7e34' }
                      : { background: '#007bff', border: '#0056b3' };
                    restore.font = { color: 'white', size: 14 };
                    restore.label = `${restore.label}\n➕`;
                    nodes.add(restore);
                  }
                }
              });
              edgesToToggle.forEach(e => {
                const id = `${e.from}_${e.to}`;
                if (!edges.get(id)) edges.add({ ...e, id });
              });
              expansionState[key] = true;
              opt.textContent = `🙈 ${label}`;
            } else {
              nodeIds.forEach(id => nodes.remove(id));
              edgesToToggle.forEach(e => edges.remove(`${e.from}_${e.to}`));
              expansionState[key] = false;
              opt.textContent = `👁️ ${label}`;
            }
            setTimeout(updateExpandButtonPosition, 0);
          });
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

      window.graphNodes = nodes;
      window.graphEdges = edges;
      window.extraGraphNodes = extraNodes;
      window.extraGraphEdges = extraEdges;
    }
  };
})(jQuery, Drupal, drupalSettings);
