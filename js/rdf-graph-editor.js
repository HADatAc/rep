(function ($, Drupal, drupalSettings) {
  'use strict';

  const HISTORY_REF_PARAM = 'ref';

  function buildUrlWithRef(baseUrl, ref) {
    if (!baseUrl) return '';
    if (!ref) return baseUrl;

    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.set(HISTORY_REF_PARAM, ref);
    return url.toString();
  }

  function extractOntologyVersion(ttlText) {
    const m = String(ttlText || '').match(/owl:versionIRI\s+hasco:(\d+(?:\.\d+)?)\s*;/);
    return m ? String(m[1]) : '';
  }

  function computeNextVersion(curr) {
    const n = parseInt(curr, 10);
    if (Number.isFinite(n)) return String(n + 1);
    return '';
  }

  function ensureDiffStyles() {
    if (document.getElementById('rep-ont-diff-style')) return;

    const style = document.createElement('style');
    style.id = 'rep-ont-diff-style';
    style.textContent = `
      #rep-ont-diff { white-space: pre-wrap; word-break: break-word; }
      .rep-diff-added { background: #e6ffed; color: #1a7f37; }
      .rep-diff-removed { background: #ffeef0; color: #cf222e; }
      .rep-diff-unchanged { color: inherit; }
      .rep-ont-pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; }
      .rep-ont-pill-ok { background: #e6ffed; color: #1a7f37; }
      .rep-ont-pill-err { background: #ffeef0; color: #cf222e; }
    `;
    document.head.appendChild(style);
  }

  function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  function setVisible(id, visible) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = visible ? '' : 'none';
  }

  function clearNode(id) {
    const el = document.getElementById(id);
    if (!el) return;
    while (el.firstChild) el.removeChild(el.firstChild);
  }

  function renderHistorySelect(history) {
    const select = document.getElementById('rep-ont-history-ref');
    if (!select) return;

    select.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = Drupal.t('Select…');
    select.appendChild(placeholder);

    const versions = history?.versions || [];
    const snapshots = history?.snapshots || [];

    if (versions.length) {
      const og = document.createElement('optgroup');
      og.label = Drupal.t('Versions');
      versions.forEach((v) => {
        const opt = document.createElement('option');
        opt.value = String(v.ref || '');
        opt.textContent = String(v.label || v.ref || '');
        og.appendChild(opt);
      });
      select.appendChild(og);
    }

    if (snapshots.length) {
      const og = document.createElement('optgroup');
      og.label = Drupal.t('Snapshots');
      snapshots.forEach((v) => {
        const opt = document.createElement('option');
        opt.value = String(v.ref || '');
        opt.textContent = String(v.label || v.ref || '');
        og.appendChild(opt);
      });
      select.appendChild(og);
    }
  }

  function getDiffLib() {
    return window.Diff || window.JsDiff || null;
  }

  function renderDiff(fromText, toText) {
    ensureDiffStyles();

    const diffWrap = document.getElementById('rep-ont-diff-wrap');
    const pre = document.getElementById('rep-ont-diff');
    if (!diffWrap || !pre) return;

    const lib = getDiffLib();
    if (!lib || typeof lib.diffLines !== 'function') {
      pre.textContent = Drupal.t('Diff library not available.');
      diffWrap.style.display = '';
      return;
    }

    clearNode('rep-ont-diff');

    const parts = lib.diffLines(String(fromText || ''), String(toText || ''));
    parts.forEach((p) => {
      const span = document.createElement('span');
      span.className = p.added
        ? 'rep-diff-added'
        : p.removed
          ? 'rep-diff-removed'
          : 'rep-diff-unchanged';
      span.textContent = p.value;
      pre.appendChild(span);
    });

    diffWrap.style.display = '';
  }

  Drupal.behaviors.rdfGraphEditor = {
    attach(context) {
      const ta = context.querySelector('#rdf-editor-textarea');
      const graphDiv = context.querySelector('#rdf-editor-graph');
      const toolsDiv = context.querySelector('#rep-ont-tools');
      if (!ta || ta._cm) return;

      const dirtyInput = context.querySelector('input[name="is_dirty"]');
      const saveBtn = context.querySelector('#rep-ont-save');
      const ingestLink = context.querySelector('#rep-ont-ingest');

      const cfg = drupalSettings?.repRdfEditor || {};
      const getUrl = cfg.getUrl;

      const state = {
        baseline: '',
        dirty: false,
        valid: true,
        errorMsg: '',
      };

      let programmaticSet = false;

      function setIngestEnabled(enabled) {
        if (!ingestLink) return;

        if (enabled) {
          ingestLink.classList.remove('is-disabled');
          ingestLink.removeAttribute('aria-disabled');
          ingestLink.style.pointerEvents = '';
          ingestLink.style.opacity = '';
        } else {
          ingestLink.classList.add('is-disabled');
          ingestLink.setAttribute('aria-disabled', 'true');
          ingestLink.style.pointerEvents = 'none';
          ingestLink.style.opacity = '0.6';
        }
      }

      function updateButtons() {
        const canSave = state.dirty && state.valid;
        if (dirtyInput) {
          dirtyInput.value = state.dirty ? '1' : '0';
          dirtyInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (saveBtn) saveBtn.disabled = !canSave;
        setIngestEnabled(!state.dirty && state.valid);
      }

      function setDirty(changed) {
        state.dirty = !!changed;
        updateButtons();
      }

      function setValidity(valid, message) {
        state.valid = !!valid;
        state.errorMsg = String(message || '');

        const pill = document.getElementById('rep-ont-ttl-pill');
        const err = document.getElementById('rep-ont-ttl-error');

        if (pill) {
          pill.classList.remove('rep-ont-pill-ok', 'rep-ont-pill-err');
          if (state.valid) {
            pill.classList.add('rep-ont-pill', 'rep-ont-pill-ok');
            pill.textContent = Drupal.t('Turtle OK');
          } else {
            pill.classList.add('rep-ont-pill', 'rep-ont-pill-err');
            pill.textContent = Drupal.t('Turtle ERROR');
          }
        }

        if (err) {
          err.textContent = state.valid ? '' : state.errorMsg;
          err.style.display = state.valid ? 'none' : '';
        }

        updateButtons();
      }

      function validateTurtle(ttlText) {
        try {
          const parser = new N3.Parser();
          let hasError = null;
          parser.parse(String(ttlText || ''), (err) => {
            if (err && !hasError) {
              hasError = err;
            }
          });

          if (hasError) {
            setValidity(false, hasError.message || String(hasError));
            return;
          }

          setValidity(true, '');
        } catch (e) {
          setValidity(false, e?.message || String(e));
        }
      }

      const validateTurtleDebounced = Drupal.debounce((ttlText) => {
        validateTurtle(ttlText);

        // Optional graph rendering (only if a graph container exists).
        if (!graphDiv) return;

        graphDiv.innerHTML = '';
        const parser = new N3.Parser();
        const store = new N3.Store();

        parser.parse(String(ttlText || ''), (err, quad) => {
          if (quad) store.addQuad(quad);
        });

        const nodes = {};
        const elements = [];
        store.getQuads(null, null, null, null).forEach((q) => {
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
              label: p.replace(/^.*[#\/]/, ''),
            },
          });
        });

        cytoscape({
          container: graphDiv,
          elements: Object.values(nodes).concat(elements),
          style: [
            { selector: 'node', style: { label: 'data(label)', 'text-valign': 'center' } },
            {
              selector: 'edge',
              style: {
                label: 'data(label)',
                'curve-style': 'bezier',
                'target-arrow-shape': 'triangle',
                'font-size': '8px',
              },
            },
          ],
          layout: { name: 'cose' },
        });
      }, 350);

      function injectToolsUi() {
        if (!toolsDiv) return;
        if (toolsDiv.dataset.repOntToolsInjected === '1') return;

        toolsDiv.dataset.repOntToolsInjected = '1';
        ensureDiffStyles();

        toolsDiv.innerHTML = `
          <div class="border rounded p-2">
            <div class="d-flex flex-wrap align-items-center gap-3">
              <div class="small">
                <strong>${Drupal.t('Current version')}</strong>:
                <span id="rep-ont-current-version">—</span>
                <span class="text-muted">(${Drupal.t('next')}: <span id="rep-ont-next-version">—</span>)</span>
              </div>
              <div id="rep-ont-ttl-pill" class="rep-ont-pill rep-ont-pill-ok">${Drupal.t('Turtle OK')}</div>
              <div class="ms-auto d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="rep-ont-reload-disk">${Drupal.t('Reload from disk')}</button>
              </div>
            </div>

            <div id="rep-ont-ttl-error" class="text-danger small mt-2" style="display:none;"></div>

            <hr class="my-2" />

            <div class="row g-2 align-items-end">
              <div class="col-md-6">
                <label for="rep-ont-history-ref" class="form-label small mb-1">${Drupal.t('History')}</label>
                <select id="rep-ont-history-ref" class="form-select form-select-sm"></select>
              </div>
              <div class="col-md-6 d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="rep-ont-history-view">${Drupal.t('View')}</button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="rep-ont-history-diff">${Drupal.t('Diff')}</button>
                <button type="button" class="btn btn-sm btn-outline-warning" id="rep-ont-history-load">${Drupal.t('Load into editor')}</button>
              </div>
            </div>

            <div id="rep-ont-diff-wrap" class="mt-3" style="display:none;">
              <div class="d-flex align-items-center justify-content-between">
                <div class="small fw-bold">${Drupal.t('Diff')}</div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="rep-ont-diff-close">${Drupal.t('Close')}</button>
              </div>
              <pre id="rep-ont-diff" class="border rounded p-2 mt-2" style="max-height:260px; overflow:auto;"></pre>
            </div>
          </div>
        `;

        renderHistorySelect(cfg.history || {});

        const current = cfg.currentVersion || '';
        setText('rep-ont-current-version', current || '—');
        setText('rep-ont-next-version', computeNextVersion(current) || '—');

        const btnReload = document.getElementById('rep-ont-reload-disk');
        if (btnReload) {
          btnReload.addEventListener('click', async (e) => {
            e.preventDefault();
            if (state.dirty && !window.confirm(Drupal.t('Discard unsaved changes and reload from disk?'))) {
              return;
            }
            await api.reloadFromDisk({ preserveView: true });
          });
        }

        const btnClose = document.getElementById('rep-ont-diff-close');
        if (btnClose) {
          btnClose.addEventListener('click', (e) => {
            e.preventDefault();
            setVisible('rep-ont-diff-wrap', false);
            clearNode('rep-ont-diff');
          });
        }

        const getSelectedRef = () => {
          const sel = document.getElementById('rep-ont-history-ref');
          return sel ? String(sel.value || '') : '';
        };

        const viewBtn = document.getElementById('rep-ont-history-view');
        if (viewBtn) {
          viewBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const ref = getSelectedRef();
            if (!ref) return;
            const url = buildUrlWithRef(getUrl, ref);
            if (url) window.open(url, '_blank', 'noopener');
          });
        }

        const loadBtn = document.getElementById('rep-ont-history-load');
        if (loadBtn) {
          loadBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const ref = getSelectedRef();
            if (!ref) return;

            if (state.dirty && !window.confirm(Drupal.t('Replace the editor content? Unsaved changes will be lost.'))) {
              return;
            }

            const url = buildUrlWithRef(getUrl, ref);
            if (!url) return;

            const resp = await fetch(url, { credentials: 'same-origin' });
            const text = await resp.text();

            programmaticSet = true;
            try {
              ta._cm.setValue(text);
            } finally {
              programmaticSet = false;
            }

            // Loading a historical version is always a local edit until saved.
            setDirty(true);
            validateTurtleDebounced(text);

            const v = extractOntologyVersion(text);
            setText('rep-ont-current-version', v || '—');
            setText('rep-ont-next-version', computeNextVersion(v) || '—');
          });
        }

        const diffBtn = document.getElementById('rep-ont-history-diff');
        if (diffBtn) {
          diffBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const ref = getSelectedRef();
            if (!ref) return;

            const url = buildUrlWithRef(getUrl, ref);
            if (!url) return;

            const resp = await fetch(url, { credentials: 'same-origin' });
            const oldText = await resp.text();
            const curText = ta._cm.getValue();

            renderDiff(oldText, curText);
          });
        }
      }

      async function reloadFromDisk({ preserveView } = {}) {
        if (!getUrl) return;

        const cm = ta._cm;
        const cursor = preserveView ? cm.getCursor() : null;
        const scrollInfo = preserveView ? cm.getScrollInfo() : null;

        const resp = await fetch(getUrl, { credentials: 'same-origin' });
        const text = await resp.text();

        programmaticSet = true;
        try {
          cm.setValue(text);
        } finally {
          programmaticSet = false;
        }

        state.baseline = text;
        setDirty(false);
        validateTurtleDebounced(text);

        const v = extractOntologyVersion(text);
        if (v) {
          setText('rep-ont-current-version', v);
          setText('rep-ont-next-version', computeNextVersion(v) || '—');
        }

        // Hide any diff panel after reload.
        setVisible('rep-ont-diff-wrap', false);
        clearNode('rep-ont-diff');

        if (preserveView && cursor && scrollInfo) {
          cm.setCursor(cursor);
          cm.scrollTo(scrollInfo.left, scrollInfo.top);
        }
      }

      // Initialize CodeMirror.
      ta._cm = CodeMirror.fromTextArea(ta, {
        mode: 'text/turtle',
        lineNumbers: true,
        matchBrackets: true,
      });
      ta._cm.setSize(null, '45vh');

      // Public API for Ajax callbacks.
      const api = {
        reloadFromDisk,
        afterSave: async (newVersion) => {
          // Always reload from disk so versionIRI changes are reflected.
          await reloadFromDisk({ preserveView: true });
          if (newVersion) {
            setText('rep-ont-current-version', String(newVersion));
            setText('rep-ont-next-version', computeNextVersion(String(newVersion)) || '—');
          }
        },
      };
      ta._repRdfApi = api;

      // Inject tools panel and load initial content from the API.
      injectToolsUi();

      (async () => {
        try {
          if (getUrl) {
            const resp = await fetch(getUrl, { credentials: 'same-origin' });
            const text = await resp.text();

            programmaticSet = true;
            try {
              ta._cm.setValue(text);
            } finally {
              programmaticSet = false;
            }

            state.baseline = text;
          } else {
            state.baseline = ta._cm.getValue();
          }
        } catch (e) {
          state.baseline = ta._cm.getValue();
        }

        setDirty(false);
        validateTurtleDebounced(ta._cm.getValue());

        const v = cfg.currentVersion || extractOntologyVersion(ta._cm.getValue());
        if (v) {
          setText('rep-ont-current-version', String(v));
          setText('rep-ont-next-version', computeNextVersion(String(v)) || '—');
        }
      })();

      ta._cm.on('change', (cm) => {
        const current = cm.getValue();

        if (!programmaticSet) {
          setDirty(current !== state.baseline);
        }

        validateTurtleDebounced(current);
      });

      // Prevent ingestion when disabled.
      if (ingestLink) {
        ingestLink.addEventListener('click', (e) => {
          if (ingestLink.classList.contains('is-disabled')) {
            e.preventDefault();
            alert(Drupal.t('Please save your changes before ingestion.'));
          }
        });
      }

      // Warn on accidental navigation with unsaved changes.
      if (!window.__repOntEditorBeforeUnloadBound) {
        window.__repOntEditorBeforeUnloadBound = true;
        window.addEventListener('beforeunload', (e) => {
          const t = document.querySelector('#rdf-editor-textarea');
          const isDirty = !!(t && t._repRdfApi && t._repRdfApi && document.querySelector('input[name="is_dirty"]')?.value === '1');
          if (!isDirty) return;
          e.preventDefault();
          e.returnValue = '';
        });
      }

      // Cmd/Ctrl+S → save.
      if (!window.__repOntEditorShortcutBound) {
        window.__repOntEditorShortcutBound = true;
        document.addEventListener('keydown', (e) => {
          if (!(e.metaKey || e.ctrlKey)) return;
          if (String(e.key || '').toLowerCase() !== 's') return;

          const t = document.querySelector('#rdf-editor-textarea');
          const btn = document.querySelector('#rep-ont-save');
          if (!t || !t._cm || !btn) return;

          e.preventDefault();
          if (!btn.disabled) {
            btn.click();
          }
        });
      }
    },
  };

  // jQuery plugin invoked by the server-side Ajax callback.
  if ($ && $.fn && !$.fn.repOntAfterSave) {
    $.fn.repOntAfterSave = function (newVersion) {
      const ta = document.querySelector('#rdf-editor-textarea');
      if (ta && ta._repRdfApi && typeof ta._repRdfApi.afterSave === 'function') {
        ta._repRdfApi.afterSave(newVersion);
      }
      return this;
    };
  }

})(window.jQuery, Drupal, drupalSettings);
