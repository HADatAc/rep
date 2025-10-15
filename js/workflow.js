(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.workflowCanvasInit = {
    attach: function (context) {
      // ➜ Usa o MESMO ID definido no Form PHP
      var el = context.querySelector('#workflow-canvas');
      if (!el || el.dataset.loaded === 'true') return;
      if (typeof vis === 'undefined') return;
      el.dataset.loaded = 'true';

      // Dados base vindos do PHP
      var base = (typeof drupalSettings !== 'undefined' && drupalSettings.workflowData)
                 ? drupalSettings.workflowData
                 : { nodes: [], edges: [] };

      // Endpoint (sem optional chaining)
      var endpoint = null;
      if (typeof drupalSettings !== 'undefined' &&
          drupalSettings.rep &&
          drupalSettings.rep.workflowEndpoint) {
        endpoint = drupalSettings.rep.workflowEndpoint;
      }

      // Conjuntos
      var nodes = new vis.DataSet(((base.nodes || [])).map(styleNode));
      var edges = new vis.DataSet(((base.edges || [])).map(styleEdge));

      var options = {
        nodes: { shape: 'box', font: { color: 'white' } },
        edges: { arrows: 'to', smooth: { type: 'dynamic' } },
        interaction: { multiselect: true, navigationButtons: false, keyboard: false },
        physics: { stabilization: true }
      };

      // Cria o network
      var network = new vis.Network(el, { nodes: nodes, edges: edges }, options);

      // ===== Toolbar (agora FORA do canvas) =====
      addToolbarOutside(el, nodes, edges, network, endpoint);

      // ===== Duplo clique para renomear =====
      network.on('doubleClick', function (params) {
        if (params.nodes && params.nodes.length === 1) {
          var id = params.nodes[0];
          var n = nodes.get(id);
          var label = prompt('Rename task:', (n && n.label) ? n.label : '');
          if (label !== null) nodes.update({ id: id, label: label });
        }
      });

      // ===== Delete para apagar seleção =====
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Delete' || ev.key === 'Backspace') {
          var sel = network.getSelection();
          var hasNodes = sel.nodes && sel.nodes.length;
          var hasEdges = sel.edges && sel.edges.length;
          if (hasNodes || hasEdges) {
            if (confirm('Delete selected nodes/edges?')) {
              if (hasEdges) edges.remove(sel.edges);
              if (hasNodes) nodes.remove(sel.nodes);
            }
          }
        }
      });

      // ===== Menu de contexto =====
      network.on('oncontext', function (params) {
        params.event.preventDefault();
        var nodeId = network.getNodeAt(params.pointer.DOM);
        if (!nodeId) return;
        showContextMenu(params.event, [
          { text: 'Rename', action: function () {
              var n = nodes.get(nodeId);
              var label = prompt('Rename task:', (n && n.label) ? n.label : '');
              if (label !== null) nodes.update({ id: nodeId, label: label });
            }},
          { text: 'Delete', action: function () { nodes.remove(nodeId); } }
        ]);
      });

      function showContextMenu(domEvent, items) {
        closeMenus();
        var m = document.createElement('div');
        m.className = 'wf-menu';
        m.style.cssText = 'position:absolute; z-index:1000; background:#fff; border:1px solid #ddd; box-shadow:0 2px 8px rgba(0,0,0,.08);';
        items.forEach(function (it) {
          var b = document.createElement('div');
          b.textContent = it.text;
          b.style.cssText = 'padding:8px 12px; cursor:pointer;';
          b.onmouseenter = function () { b.style.background = '#f5f5f5'; };
          b.onmouseleave = function () { b.style.background = ''; };
          b.onclick = function () { it.action(); closeMenus(); };
          m.appendChild(b);
        });
        document.body.appendChild(m);
        m.style.left = domEvent.clientX + 'px';
        m.style.top  = domEvent.clientY + 'px';
        setTimeout(function () { document.addEventListener('click', closeMenus, { once: true }); }, 0);

        function closeMenus() {
          var menus = document.querySelectorAll('.wf-menu');
          for (var i = 0; i < menus.length; i++) menus[i].remove();
        }
      }

      function styleNode(n) {
        var colors = {
          'task':   { background: '#007bff', border: '#0056b3' },
          'choice': { background: '#6f42c1', border: '#59359a' },
          'and':    { background: '#28a745', border: '#1e7e34' }
        };
        var color = colors[n.type] || { background: '#6c757d', border: '#495057' };
        return Object.assign({}, n, { shape: 'box', color: color, font: { color: 'white' } });
      }

      function styleEdge(e) {
        var base = { arrows: 'to' };
        if (e.label === '>>')  return Object.assign({}, e, base, { dashes: false });
        if (e.label === '|||') return Object.assign({}, e, base, { dashes: true });
        if (e.label === '[]')  return Object.assign({}, e, base, { color: { color: '#6f42c1' } });
        return Object.assign({}, e, base);
      }

      function addToolbarOutside(canvasEl, nodes, edges, network, endpoint) {
        var bar = document.createElement('div');
        bar.style.cssText = 'margin:0 0 8px 0; display:flex; gap:8px; flex-wrap:wrap;';

        var addTaskBtn = mkBtn('Add Task', function () {
          var id = 'T:' + Date.now();
          nodes.add(styleNode({ id: id, label: 'New Task', type: 'task' }));
        });

        var linkSeqBtn = mkBtn('Link >>', function () { linkSelected('>>'); });
        var linkParBtn = mkBtn('Link |||', function () { linkSelected('|||'); });
        var linkAltBtn = mkBtn('Link []', function () { linkSelected('[]'); });

        var fitBtn  = mkBtn('Fit',  function () { network.fit({ animation: true }); });
        var zoomIn  = mkBtn('+',    function () { network.moveTo({ scale: network.getScale() * 1.2 }); });
        var zoomOut = mkBtn('−',    function () { network.moveTo({ scale: network.getScale() / 1.2 }); });

        var exportBtn = mkBtn('Export JSON', function () {
          var payload = currentPayload();
          var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
          var a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = 'workflow.json';
          a.click();
        });

        var importInp = document.createElement('input');
        importInp.type = 'file'; importInp.accept = 'application/json';
        var importBtn = mkBtn('Import JSON', function () { importInp.click(); });
        importInp.onchange = function (e) {
          var file = (e.target && e.target.files) ? e.target.files[0] : null;
          if (!file) return;
          var reader = new FileReader();
          reader.onload = function () {
            try {
              var payload = JSON.parse(reader.result);
              replaceWithPayload(payload);
            } catch (err) { alert('Invalid JSON'); }
          };
          reader.readAsText(file);
        };

        var saveBtn = mkBtn('Save (server)', function () {
          if (!endpoint) { alert('No endpoint configured.'); return; }
          fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(currentPayload())
          })
          .then(function (res) { return res.json(); })
          .then(function (j) {
            var msg = (j && j.meta && j.meta.message) ? j.meta.message : 'Saved.';
            alert(msg);
          })
          .catch(function (e) { alert('Save failed. Check console.'); console.error(e); });
        });

        var loadBtn = mkBtn('Load (server)', function () {
          if (!endpoint) { alert('No endpoint configured.'); return; }
          fetch(endpoint)
            .then(function (res) { return res.json(); })
            .then(function (j) { replaceWithPayload(j); })
            .catch(function (e) { alert('Load failed.'); console.error(e); });
        });

        function linkSelected(op) {
          var sel = network.getSelectedNodes();
          if (sel.length === 2) {
            var e = { from: sel[0], to: sel[1], label: op };
            edges.add(styleEdge(e));
          } else {
            alert('Seleciona exatamente 2 tarefas para ligar.');
          }
        }

        function currentPayload() {
          return {
            nodes: nodes.get().map(function (t) { return { id: t.id, label: t.label, type: t.type }; }),
            edges: edges.get().map(function (e) { return { from: e.from, to: e.to, label: e.label }; }),
            meta: { ts: Date.now() }
          };
        }

        function replaceWithPayload(payload) {
          var ns = ((payload && payload.nodes) ? payload.nodes : []).map(styleNode);
          var es = ((payload && payload.edges) ? payload.edges : []).map(styleEdge);
          nodes.clear(); edges.clear();
          nodes.add(ns); edges.add(es);
          network.fit({ animation: true });
        }

        // 👉 coloca a toolbar ANTES do canvas
        canvasEl.parentNode.insertBefore(bar, canvasEl);

        // Botões
        bar.appendChild(addTaskBtn);
        bar.appendChild(linkSeqBtn);
        bar.appendChild(linkParBtn);
        bar.appendChild(linkAltBtn);
        bar.appendChild(fitBtn);
        bar.appendChild(zoomIn);
        bar.appendChild(zoomOut);
        bar.appendChild(exportBtn);
        bar.appendChild(importBtn);
        bar.appendChild(saveBtn);
        bar.appendChild(loadBtn);
        bar.appendChild(importInp);
        importInp.style.display = 'none';
      }

      function mkBtn(txt, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = txt;
        b.className = 'btn btn-sm btn-primary';
        b.style.cssText = 'padding:4px 8px;';
        b.onclick = onClick;
        return b;
      }

      // Estilo leve do canvas (fallback caso não uses CSS)
      if (!el.style.height) el.style.height = '700px';
      el.style.width = '100%';
      el.style.marginTop = '10px';
      el.style.background = '#fafafa';
      el.style.border = '1px solid #ddd';
    }
  };
})(jQuery, Drupal, drupalSettings);
