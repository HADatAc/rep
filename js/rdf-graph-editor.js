import debounce from 'https://cdn.jsdelivr.net/npm/lodash-es@4.17.21/debounce.js';

(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.rdfGraphEditor = {
    attach(context) {
      const ta = context.querySelector('#rdf-editor-textarea');
      const graphDiv = context.querySelector('#rdf-editor-graph');
      if (!ta || ta._cm) return;  // evita reinicializar

      // 1) Inicia CodeMirror
      ta._cm = CodeMirror.fromTextArea(ta, {
        mode: 'text/turtle',
        lineNumbers: true,
        matchBrackets: true,
      });

      ta._cm.setSize(null, '45vh');

      // 2) Função que faz fetch + inicial render
      const loadAndRender = () => {
        fetch(drupalSettings.repRdfEditor.getUrl, { credentials: 'same-origin' })
          .then(r => r.text())
          .then(text => {
            ta._cm.setValue(text);
            renderGraph(text);
          });
      };

      // 3) Debounce para re-renderizar o grafo ao editar
      const renderGraph = debounce((ttlText) => {
        // Parse TTL em quads
        const parser = new N3.Parser();
        const store = new N3.Store();
        parser.parse(ttlText, (err, quad) => {
          if (quad) store.addQuad(quad);
        });

        // Monta nós e arestas
        const nodes = {};
        const elements = [];
        store.getQuads(null, null, null, null).forEach(q => {
          const s = q.subject.value;
          const p = q.predicate.value;
          const o = q.object.value;

          if (!nodes[s]) {
            nodes[s] = { data: { id: s, label: s.replace(/^.*[#\/]/, '') } };
          }
          if (!nodes[o]) {
            nodes[o] = { data: { id: o, label: o.replace(/^.*[#\/]/, '') } };
          }
          elements.push({
            data: {
              id: `${s}-${p}-${o}-${Math.random()}`,
              source: s,
              target: o,
              label: p.replace(/^.*[#\/]/, '')
            }
          });
        });

        // Renderiza com Cytoscape
        cytoscape({
          container: graphDiv,
          elements: Object.values(nodes).concat(elements),
          style: [
            { selector: 'node', style: { 'label': 'data(label)', 'text-valign': 'center' } },
            { selector: 'edge', style: {
                'label': 'data(label)',
                'curve-style': 'bezier',
                'target-arrow-shape': 'triangle',
                'font-size': '8px'
             }
            }
          ],
          layout: { name: 'cose' }
        });
      }, 500);

      // 4) Liga o editor e o grafo
      loadAndRender();
      ta._cm.on('change', cm => {
        renderGraph(cm.getValue());
      });
    }
  };
})(Drupal, drupalSettings);
