(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.workflowCanvasInit = {
    attach: function (context) {
      const el = context.querySelector('#workflow-canvas');
      if (!el || el.dataset.loaded === 'true') return;
      if (typeof vis === 'undefined') return;
      el.dataset.loaded = 'true';

      // ===== Dados base =====
      const base = (drupalSettings && drupalSettings.workflowData) || { nodes: [], edges: [] };
      const endpoint = drupalSettings?.rep?.workflowEndpoint || null;

      const nodes = new vis.DataSet((base.nodes || []).map(styleNode));
      const edges = new vis.DataSet((base.edges || []).map(styleEdge));

      // ===== Helpers CTT: parent/irmãs =====
      function getParent(nodeId) {
        // parent é a aresta sem label que entra no nó (decomposição)
        const inDecomp = edges.get({
          filter: e => e.to === nodeId && (!e.label || e.label === '')
        });
        return inDecomp.length ? inDecomp[0].from : null;
      }
      function areSiblings(a, b) {
        if (!a || !b || a === b) return false;
        const pa = getParent(a), pb = getParent(b);
        return !!(pa && pb && pa === pb);
      }

      // ===== Layout hierárquico =====
      const options = {
        layout: {
          hierarchical: {
            enabled: true,
            direction: 'UD',
            sortMethod: 'directed',
            levelSeparation: 120,
            nodeSpacing: 150,
            treeSpacing: 200,
            shakeTowards: 'roots'
          }
        },
        physics: { enabled: false },
        interaction: {
          multiselect: true,
          selectConnectedEdges: false,
          hover: true,
          zoomView: true,
          dragView: true,
          dragNodes: true
        },
        nodes: { shape: 'box', font: { color: 'white' } },
        edges: { smooth: false, color: { color: '#444' } },
        manipulation: { enabled: false }
      };

      const network = new vis.Network(el, { nodes, edges }, options);

      function applyHierarchical() {
        network.setOptions({
          layout: { hierarchical: {
            enabled: true, direction: 'UD', sortMethod: 'directed',
            levelSeparation: 120, nodeSpacing: 150, treeSpacing: 200
          }},
          physics: { enabled: false }
        });
        setTimeout(() => network.fit({ animation: true }), 0);
      }

      // ===== Toolbar =====
      addToolbarOutside(el, nodes, edges, network, endpoint, applyHierarchical);

      // ===== Duplo clique: rename node =====
      network.on('doubleClick', (params) => {
        if (params.nodes?.length === 1) {
          const id = params.nodes[0];
          const n = nodes.get(id);
          const label = prompt('Rename task:', n?.label || '');
          if (label !== null) nodes.update({ id, label });
        }
      });

      // ===== Delete via teclado =====
      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Delete' || ev.key === 'Backspace') {
          const sel = network.getSelection();
          if ((sel.nodes?.length || sel.edges?.length) && confirm('Delete selected nodes/edges?')) {
            if (sel.edges?.length) edges.remove(sel.edges);
            if (sel.nodes?.length) nodes.remove(sel.nodes);
            applyHierarchical();
          }
        }
      });

      // ===== Editor de Arestas (com regra CTT) =====
      let edgeEditorEl = null;
      let activeEdgeId = null;

      network.on('click', (params) => {
        if (params.edges && params.edges.length === 1) openEdgeEditor(params.edges[0]);
        else closeEdgeEditor();
      });
      network.on('selectEdge', (params) => {
        if (params.edges && params.edges.length === 1) openEdgeEditor(params.edges[0]);
      });

      function openEdgeEditor(edgeId) {
        closeEdgeEditor();
        activeEdgeId = edgeId;
        network.setSelection({ edges: [edgeId] });

        const e = edges.get(edgeId);
        if (!e) return;

        // Ponto médio para posicionar
        const p1 = network.getPosition(e.from);
        const p2 = network.getPosition(e.to);
        const mid = { x: (p1.x + p2.x) / 2, y: (p1.y + p2.y) / 2 };
        const dom = network.canvasToDOM(mid);
        const rect = el.getBoundingClientRect();

        // Só pode ter operador temporal se for entre irmãs
        const canTemporal = areSiblings(e.from, e.to);

        edgeEditorEl = document.createElement('div');
        edgeEditorEl.className = 'wf-edge-editor';
        edgeEditorEl.style.cssText =
          'position:absolute; z-index:1001; background:#fff; border:1px solid #ddd;' +
          'box-shadow:0 2px 10px rgba(0,0,0,.12); border-radius:6px; padding:6px; display:flex; gap:6px;';

        const noLbl = mkMiniBtn('No label', () => updateEdgeLabel(edgeId, null));

        const b1 = mkMiniBtn('>>',  () => updateEdgeLabel(edgeId, '>>'));
        const b2 = mkMiniBtn('|||', () => updateEdgeLabel(edgeId, '|||'));
        const b3 = mkMiniBtn('[]',  () => updateEdgeLabel(edgeId, '[]'));
        [b1, b2, b3].forEach(btn => {
          if (!canTemporal) {
            btn.disabled = true;
            btn.title = 'Operadores temporais só entre sub-tasks do mesmo parent';
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
          }
        });

        const swap   = mkMiniBtn('Swap',   () => { swapEnds(edgeId); });
        const rewire = mkMiniBtn('Rewire', () => {
          network.setOptions({ manipulation: { enabled: true, editEdge: true } });
          network.editEdgeMode();
          closeEdgeEditor();
          setTimeout(() => {
            network.disableEditMode();
            validateAllEdges(); // garante a regra após rewire
            applyHierarchical();
          }, 2000);
        });
        const del    = mkMiniBtn('Delete', () => { edges.remove(edgeId); closeEdgeEditor(); applyHierarchical(); });

        [noLbl, b1, b2, b3, swap, rewire, del].forEach(b => edgeEditorEl.appendChild(b));
        document.body.appendChild(edgeEditorEl);
        edgeEditorEl.style.left = (rect.left + dom.x + 8) + 'px';
        edgeEditorEl.style.top  = (rect.top  + dom.y + 8) + 'px';

        document.removeEventListener('click', edgeCloser, true);
        setTimeout(() => document.addEventListener('click', edgeCloser, true), 50);
      }

      function closeEdgeEditor() {
        if (edgeEditorEl) { edgeEditorEl.remove(); edgeEditorEl = null; }
        activeEdgeId = null;
        document.removeEventListener('click', edgeCloser, true);
      }
      function edgeCloser(e) {
        if (!edgeEditorEl) return;
        if (!edgeEditorEl.contains(e.target)) closeEdgeEditor();
      }

      // Aplica/retira label com validação CTT
      function updateEdgeLabel(edgeId, label) {
        const e = edges.get(edgeId);
        if (!e) return;
        if (label && !areSiblings(e.from, e.to)) {
          alert('Operadores temporais só entre sub-tasks do mesmo parent. Mantém-se sem label.');
          label = '';
        }
        edges.update({ id: edgeId, label: (label ? label : '') });
        closeEdgeEditor(); applyHierarchical();
      }

      function swapEnds(edgeId) {
        const e = edges.get(edgeId);
        if (!e) return;
        edges.update({ id: edgeId, from: e.to, to: e.from });
        validateAllEdges();
        closeEdgeEditor(); applyHierarchical();
      }

      // Remove labels inválidos (não-irmãs)
      function validateAllEdges() {
        edges.get().forEach(e => {
          if (e.label && !areSiblings(e.from, e.to)) {
            edges.update({ id: e.id, label: '' });
          }
        });
      }

      // ===== Menu de contexto (nós) =====
      network.on('oncontext', (params) => {
        params.event.preventDefault();
        const nodeId = network.getNodeAt(params.pointer.DOM);
        if (!nodeId) return;
        showContextMenu(params.event, [
          { text: 'Rename', action: () => {
              const n = nodes.get(nodeId);
              const label = prompt('Rename task:', n?.label || '');
              if (label !== null) nodes.update({ id: nodeId, label });
            }},
          { text: 'Delete', action: () => { nodes.remove(nodeId); applyHierarchical(); } }
        ]);
      });

      function showContextMenu(ev, items) {
        document.querySelectorAll('.wf-menu').forEach(e => e.remove());
        const m = document.createElement('div');
        m.className = 'wf-menu';
        m.style.cssText = 'position:absolute; z-index:1000; background:#fff; border:1px solid #ddd; box-shadow:0 2px 8px rgba(0,0,0,.08); border-radius:6px;';
        items.forEach(it => {
          const b = document.createElement('div');
          b.textContent = it.text;
          b.style.cssText = 'padding:8px 12px; cursor:pointer; border-bottom:1px solid #eee;';
          b.onmouseenter = () => b.style.background = '#f5f5f5';
          b.onmouseleave = () => b.style.background = '';
          b.onclick = () => { it.action(); m.remove(); };
          m.appendChild(b);
        });
        if (m.lastChild) m.lastChild.style.borderBottom = 'none';
        document.body.appendChild(m);
        m.style.left = ev.clientX + 'px';
        m.style.top = ev.clientY + 'px';
        setTimeout(() => document.addEventListener('click', () => m.remove(), { once: true }), 0);
      }

      // ===== Helpers visuais =====
      function styleNode(n) {
        const colors = {
          task:   { background: '#007bff', border: '#0056b3' },
          choice: { background: '#6f42c1', border: '#59359a' },
          and:    { background: '#28a745', border: '#1e7e34' }
        };
        const color = colors[n.type] || { background: '#6c757d', border: '#495057' };
        return { ...n, shape: 'box', color, font: { color: 'white' } };
      }
      function styleEdge(e) {
        const out = { from: e.from, to: e.to, smooth: false, color: { color: '#444' } };
        if (e.label) out.label = e.label; // só quando existir
        return out;
      }
      function mkBtn(txt, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = txt;
        b.className = 'btn btn-sm btn-primary';
        b.style.cssText = 'padding:4px 8px;';
        b.onclick = onClick;
        return b;
      }
      function mkMiniBtn(txt, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = txt;
        b.style.cssText = 'padding:4px 8px; font-size:12px; border-radius:4px; border:1px solid #ccc; background:#f8f9fa; cursor:pointer;';
        b.onmouseenter = () => b.style.background = '#eef2f6';
        b.onmouseleave = () => b.style.background = '#f8f9fa';
        b.onclick = onClick;
        return b;
      }

      // ===== Toolbar externa =====
      function addToolbarOutside(canvasEl, nodes, edges, network, endpoint, applyHierarchical) {
        const bar = document.createElement('div');
        bar.style.cssText = 'margin:0 0 8px 0; display:flex; gap:8px; flex-wrap:wrap;';

        const addTaskBtn = mkBtn('Add Task', () => {
          const id = 'T:' + Date.now();
          nodes.add(styleNode({ id, label: 'New Task', type: 'task' }));
          applyHierarchical();
        });

        const addSubBtn = mkBtn('Add Subtask', () => {
          const sel = network.getSelectedNodes();
          if (sel && sel.length === 1) {
            createSubtask(sel[0]);
          } else {
            alert('Clique numa tarefa para ser o parent.');
            const once = (params) => {
              if (params.nodes && params.nodes.length === 1) createSubtask(params.nodes[0]);
              network.off('click', once);
            };
            network.on('click', once);
          }
        });

        function createSubtask(parentId) {
          const id = 'T:' + Date.now();
          const label = prompt('Subtask name:', 'New Task');
          nodes.add(styleNode({ id, label: label || 'New Task', type: 'task' }));
          edges.add(styleEdge({ from: parentId, to: id })); // decomposição: SEM label
          applyHierarchical();
        }

        const parentChildBtn = mkBtn('Parent → Child', () => {
          const sel = network.getSelectedNodes();
          if (!sel || sel.length !== 2) return alert('Seleciona 2 tarefas: parent e child.');
          edges.add(styleEdge({ from: sel[0], to: sel[1] })); // SEM label (decomposição)
          applyHierarchical();
        });

        const linkSeqBtn = mkBtn('Link >>', () => linkSelected('>>'));
        const linkParBtn = mkBtn('Link |||', () => linkSelected('|||'));
        const linkAltBtn = mkBtn('Link []', () => linkSelected('[]'));

        const fitBtn   = mkBtn('Fit', () => network.fit({ animation: true }));
        const zoomIn   = mkBtn('+',   () => network.moveTo({ scale: network.getScale() * 1.2 }));
        const zoomOut  = mkBtn('−',   () => network.moveTo({ scale: network.getScale() / 1.2 }));

        const exportBtn = mkBtn('Export JSON', () => {
          const blob = new Blob([JSON.stringify(currentPayload(), null, 2)], { type: 'application/json' });
          const a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = 'workflow.json';
          a.click();
        });

        const importInp = document.createElement('input');
        importInp.type = 'file'; importInp.accept = 'application/json';
        const importBtn = mkBtn('Import JSON', () => importInp.click());
        importInp.onchange = (e) => {
          const file = e.target.files?.[0]; if (!file) return;
          const reader = new FileReader();
          reader.onload = () => {
            try {
              const payload = JSON.parse(reader.result);
              replaceWithPayload(payload);
              validateAllEdges();
              applyHierarchical();
            } catch { alert('Invalid JSON'); }
          };
          reader.readAsText(file);
        };

        const saveBtn = mkBtn('Save (server)', () => {
          if (!endpoint) return alert('No endpoint configured.');
          fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(currentPayload())
          })
            .then(r => r.json())
            .then(j => alert(j?.meta?.message || 'Saved.'))
            .catch(() => alert('Save failed.'));
        });

        const loadBtn = mkBtn('Load (server)', () => {
          if (!endpoint) return alert('No endpoint configured.');
          fetch(endpoint)
            .then(r => r.json())
            .then(j => { replaceWithPayload(j); validateAllEdges(); applyHierarchical(); })
            .catch(() => alert('Load failed.'));
        });

        // >>> Só cria operadores entre irmãs
        function linkSelected(op) {
          const sel = network.getSelectedNodes();
          if (sel.length !== 2) return alert('Seleciona exatamente 2 tarefas.');
          const [a, b] = sel;
          if (!areSiblings(a, b)) return alert('Operadores temporais só entre sub-tasks do mesmo parent.');
          edges.add(styleEdge({ from: a, to: b, label: op }));
          applyHierarchical();
        }

        function currentPayload() {
          return {
            nodes: nodes.get().map(t => ({ id: t.id, label: t.label, type: t.type })),
            edges: edges.get().map(e => ({ from: e.from, to: e.to, ...(e.label ? { label: e.label } : {}) })),
            meta: { ts: Date.now() }
          };
        }

        function replaceWithPayload(payload) {
          const ns = (payload.nodes || []).map(styleNode);
          const es = (payload.edges || []).map(styleEdge);
          nodes.clear(); edges.clear();
          nodes.add(ns); edges.add(es);
          network.fit({ animation: true });
        }

        canvasEl.parentNode.insertBefore(bar, canvasEl);
        [addTaskBtn, addSubBtn, parentChildBtn, linkSeqBtn, linkParBtn, linkAltBtn,
         fitBtn, zoomIn, zoomOut, exportBtn, importBtn, saveBtn, loadBtn, importInp]
          .forEach(b => bar.appendChild(b));
        importInp.style.display = 'none';
      }

      // ===== Estilo base do canvas =====
      if (!el.style.height) el.style.height = '700px';
      el.style.width = '100%';
      el.style.marginTop = '10px';
      el.style.background = '#fafafa';
      el.style.border = '1px solid #ddd';
    }
  };

  // ==== helpers comuns (fora do attach para manter o escopo limpo) ====
  function styleNode(n) {
    const colors = {
      task:   { background: '#007bff', border: '#0056b3' },
      choice: { background: '#6f42c1', border: '#59359a' },
      and:    { background: '#28a745', border: '#1e7e34' }
    };
    const color = colors[n.type] || { background: '#6c757d', border: '#495057' };
    return { ...n, shape: 'box', color, font: { color: 'white' } };
  }
  function styleEdge(e) {
    const out = { from: e.from, to: e.to, smooth: false, color: { color: '#444' } };
    if (e.label) out.label = e.label;
    return out;
  }
  function mkBtn(txt, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = txt;
    b.className = 'btn btn-sm btn-primary';
    b.style.cssText = 'padding:4px 8px;';
    b.onclick = onClick;
    return b;
  }
  function mkMiniBtn(txt, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = txt;
    b.style.cssText =
      'padding:4px 8px; font-size:12px; border-radius:4px; border:1px solid #ccc; background:#f8f9fa; cursor:pointer;';
    b.onmouseenter = () => b.style.background = '#eef2f6';
    b.onmouseleave = () => b.style.background = '#f8f9fa';
    b.onclick = onClick;
    return b;
  }
})(jQuery, Drupal, drupalSettings);
