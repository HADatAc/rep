(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.rdfGraphEditor = {
    attach(context) {
      const ta = context.querySelector('#rdf-editor-textarea');
      const graphDiv = context.querySelector('#rdf-editor-graph');
      if (!ta || ta._cm) return;

      const dirtyInput = context.querySelector('input[name="is_dirty"]');
      const saveBtn    = context.querySelector('#rep-ont-save');
      const ingestLink = context.querySelector('#rep-ont-ingest');

      function setIngestEnabled(enabled) {
        if (!ingestLink) return;
        if (enabled) {
          ingestLink.classList.remove('is-disabled');
          ingestLink.removeAttribute('aria-disabled');
        } else {
          ingestLink.classList.add('is-disabled');
          ingestLink.setAttribute('aria-disabled', 'true');
        }
      }

      function setDirty(changed) {
        if (dirtyInput) {
          dirtyInput.value = changed ? '1' : '0';
          dirtyInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (saveBtn) saveBtn.disabled = !changed;
        setIngestEnabled(!changed);
      }

      ta._cm = CodeMirror.fromTextArea(ta, {
        mode: 'text/turtle',
        lineNumbers: true,
        matchBrackets: true,
      });
      ta._cm.setSize(null, '45vh');

      let baseline = ta.value || '';
      const getUrl = drupalSettings?.repRdfEditor?.getUrl;

      const loadAndRender = () => {
        fetch(drupalSettings.repRdfEditor.getUrl, { credentials: 'same-origin' })
          .then(r => r.text())
          .then(text => {
            ta._cm.setValue(text);

            ta.dataset.initialTtl = text;
            setDirty(false);

            renderGraph(text);
          });
      };

      const renderGraph = Drupal.debounce((ttlText) => {
        if (!graphDiv) return;

        graphDiv.innerHTML = '';
        const parser = new N3.Parser();
        const store  = new N3.Store();

        parser.parse(ttlText, (err, quad) => {
          if (quad) store.addQuad(quad);
        });

        const nodes = {};
        const elements = [];
        store.getQuads(null, null, null, null).forEach(q => {
          const s = q.subject.value;
          const p = q.predicate.value;
          const o = q.object.value;
          if (!nodes[s]) nodes[s] = { data: { id: s, label: s.replace(/^.*[#\/]/, '') } };
          if (!nodes[o]) nodes[o] = { data: { id: o, label: o.replace(/^.*[#\/]/, '') } };
          elements.push({
            data: {
              id: `${s}-${p}-${o}-${Math.random()}`,
              source: s,
              target: o,
              label: p.replace(/^.*[#\/]/, '')
            }
          });
        });

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
            } }
          ],
          layout: { name: 'cose' }
        });
      }, 400);

      (async () => {
        try {
          if (getUrl) {
            const resp = await fetch(getUrl, { credentials: 'same-origin' });
            const text = await resp.text();
            ta._cm.setValue(text);
            baseline = text;
          } else {
            baseline = ta._cm.getValue();
          }
        } catch (e) {
          baseline = ta._cm.getValue();
        }
        setDirty(false);
        renderGraph(ta._cm.getValue());
      })();

      ta._cm.on('change', (cm) => {
        const current = cm.getValue();
        setDirty(current !== baseline);
        renderGraph(current);
      });

      if (ingestLink) {
        ingestLink.addEventListener('click', (e) => {
          if (ingestLink.classList.contains('is-disabled')) {
            e.preventDefault();
            alert(Drupal.t('Please save your changes before ingestion.'));
          }
        });
      }
    }
  };
})(Drupal, drupalSettings);
