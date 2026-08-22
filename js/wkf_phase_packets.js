(function (Drupal, once) {
  'use strict';

  var SOURCE_DOC_CONTENT_KEY = 'rep_wkf_source_document_content';
  var SOURCE_DOC_CONTENT_BY_URI_KEY = 'rep_wkf_source_document_content_by_uri';

  function normalizeUriCandidate(raw) {
    var value = String(raw || '').trim();
    if (!value) {
      return '';
    }

    try {
      value = decodeURIComponent(value);
    } catch (e) {
      // Keep original value when decode fails.
    }

    return value;
  }

  function extractSelectedWkfUriFromTable() {
    var selected = document.querySelectorAll('input[type="checkbox"][name^="element_table["]:checked');
    if (!selected || selected.length === 0) {
      return '';
    }

    for (var i = 0; i < selected.length; i++) {
      var input = selected[i];
      var val = normalizeUriCandidate(input.value || '');
      if (val) {
        return val;
      }

      var name = String(input.getAttribute('name') || '');
      var m = name.match(/^element_table\[(.*)\]$/);
      if (m && m[1]) {
        var key = normalizeUriCandidate(m[1]);
        if (key) {
          return key;
        }
      }
    }

    return '';
  }

  function syncActiveWkfUriFromSelection() {
    var activeInput = document.getElementById('wkf-active-uri');
    if (!activeInput) {
      return;
    }

    var selectedUri = extractSelectedWkfUriFromTable();
    if (selectedUri) {
      activeInput.value = selectedUri;
    }
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }

    return new Promise(function (resolve, reject) {
      try {
        var textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(textArea);
        if (ok) {
          resolve();
        } else {
          reject(new Error('copy failed'));
        }
      } catch (err) {
        reject(err);
      }
    });
  }

  function getText(selector) {
    if (!selector) {
      return '';
    }
    var el = document.querySelector(selector);
    if (!el) {
      return '';
    }
    return (el.value || el.textContent || '').trim();
  }

  function getSessionValue(key) {
    try {
      return String(sessionStorage.getItem(key) || '').trim();
    } catch (e) {
      return '';
    }
  }

  function setSessionValue(key, value) {
    try {
      sessionStorage.setItem(key, String(value || ''));
    } catch (e) {
      // Ignore storage failures.
    }
  }

  function setHiddenValue(selector, value) {
    var el = document.querySelector(selector);
    if (!el) {
      return;
    }
    el.value = String(value || '');
  }

  function isMetadataOnlySourceDoc(value) {
    var text = String(value || '').trim();
    if (!text) {
      return true;
    }
    return /^supporting\s+document\s+filename\s*:/i.test(text);
  }

  function getUriAliases(rawUri) {
    var uri = String(rawUri || '').trim();
    if (!uri) {
      return [];
    }

    var aliases = [uri];
    var pmsrPrefix = 'pmsr:';
    var fullPrefix = 'https://pmsr.net/ont/';

    if (uri.indexOf(pmsrPrefix) === 0) {
      aliases.push(fullPrefix + uri.substring(pmsrPrefix.length));
    } else if (uri.indexOf(fullPrefix) === 0) {
      aliases.push(pmsrPrefix + uri.substring(fullPrefix.length));
    }

    return aliases;
  }

  function getSourceDocByUri(wkfUri) {
    var uri = String(wkfUri || '').trim();
    if (!uri) {
      return '';
    }
    try {
      var raw = sessionStorage.getItem(SOURCE_DOC_CONTENT_BY_URI_KEY);
      if (!raw) {
        return '';
      }
      var map = JSON.parse(raw);
      if (!map || typeof map !== 'object') {
        return '';
      }
      var aliases = getUriAliases(uri);
      for (var i = 0; i < aliases.length; i++) {
        var value = String(map[aliases[i]] || '').trim();
        if (value) {
          return value;
        }
      }
      return '';
    } catch (e) {
      return '';
    }
  }

  function readFileAsText(file) {
    return new Promise(function (resolve) {
      if (!file) {
        resolve('');
        return;
      }
      var reader = new FileReader();
      reader.onload = function () {
        resolve(String(reader.result || ''));
      };
      reader.onerror = function () {
        resolve('');
      };
      try {
        reader.readAsText(file);
      } catch (e) {
        resolve('');
      }
    });
  }

  function readFileAsArrayBuffer(file) {
    return new Promise(function (resolve) {
      if (!file) {
        resolve(null);
        return;
      }
      var reader = new FileReader();
      reader.onload = function () {
        resolve(reader.result || null);
      };
      reader.onerror = function () {
        resolve(null);
      };
      try {
        reader.readAsArrayBuffer(file);
      } catch (e) {
        resolve(null);
      }
    });
  }

  function extractPdfTextFromFile(file) {
    if (!window.pdfjsLib || typeof window.pdfjsLib.getDocument !== 'function') {
      return Promise.resolve('');
    }

    return readFileAsArrayBuffer(file).then(function (buffer) {
      if (!buffer) {
        return '';
      }

      return window.pdfjsLib.getDocument({ data: buffer }).promise
        .then(function (pdf) {
          var pages = [];
          for (var p = 1; p <= pdf.numPages; p++) {
            pages.push(pdf.getPage(p).then(function (page) {
              return page.getTextContent().then(function (content) {
                var items = (content && content.items) ? content.items : [];
                return items.map(function (item) {
                  return (item && item.str) ? String(item.str) : '';
                }).join(' ').trim();
              });
            }).catch(function () {
              return '';
            }));
          }

          return Promise.all(pages).then(function (parts) {
            return parts.filter(function (txt) {
              return String(txt || '').trim() !== '';
            }).join('\n\n');
          });
        })
        .catch(function () {
          return '';
        });
    });
  }

  function extractSourceDocFromPhase1Input() {
    var input = document.getElementById('phase1SourceDocument');
    var file = input && input.files && input.files.length > 0 ? input.files[0] : null;
    if (!file) {
      return Promise.resolve('');
    }

    var name = String(file.name || '').toLowerCase();
    var type = String(file.type || '').toLowerCase();
    var isPdf = name.endsWith('.pdf') || type === 'application/pdf';
    var isText = type.indexOf('text/') === 0 || name.endsWith('.txt') || name.endsWith('.md') || name.endsWith('.csv');

    if (isPdf) {
      return extractPdfTextFromFile(file).then(function (txt) {
        return String(txt || '').trim();
      });
    }

    if (isText) {
      return readFileAsText(file).then(function (txt) {
        return String(txt || '').trim();
      });
    }

    return Promise.resolve('');
  }

  function showPacketModal(packetText, fileName) {
    var output = document.getElementById('wkf-phase-packet-output');
    var fileInput = document.getElementById('wkf-phase-packet-filename');
    var copyBtn = document.getElementById('wkf-phase-packet-copy');
    var downloadBtn = document.getElementById('wkf-phase-packet-download');

    if (!output || !fileInput || !copyBtn || !downloadBtn) {
      alert('WKF phase packet modal is not available.');
      return;
    }

    output.value = packetText || '';
    fileInput.value = fileName || 'wkf-phase-packet.txt';

    var modalEl = document.getElementById('wkfPhasePacketModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
      alert('Packet generated.\n\n' + packetText);
      return;
    }

    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
  }

  function showCopiedState(button, copiedLabel, busyLabel) {
    if (!button) {
      return {
        setBusy: function () {},
        setCopied: function () {},
        reset: function () {}
      };
    }

    var originalText = button.textContent;
    var originalDisabled = !!button.disabled;

    return {
      setBusy: function () {
        button.disabled = true;
        button.textContent = busyLabel || 'Building...';
      },
      setCopied: function () {
        button.disabled = true;
        button.textContent = copiedLabel || 'Copied';
        setTimeout(function () {
          button.textContent = originalText;
          button.disabled = originalDisabled;
        }, 1400);
      },
      reset: function () {
        button.textContent = originalText;
        button.disabled = originalDisabled;
      }
    };
  }

  function fetchJson(url, options) {
    return fetch(url, options || {}).then(function (resp) {
      return resp.json();
    });
  }

  function renderHistoryOptions(items) {
    var select = document.getElementById('wkf-phase-packet-history');
    if (!select) {
      return;
    }

    function escapeHtml(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    var html = '<option value="">Select saved packet...</option>';
    (items || []).forEach(function (item) {
      var id = String(item.packetId || '');
      if (!id) {
        return;
      }
      var phase = String(item.phaseTitle || ('Phase ' + String(item.phase || '')));
      var created = String(item.createdAt || '');
      var label = phase + (created ? (' [' + created + ']') : '');
      html += '<option value="' + escapeHtml(id) + '">' + escapeHtml(label) + '</option>';
    });
    select.innerHTML = html;
  }

  function refreshPacketHistory() {
    var wkfUri = getText('#wkf-active-uri');
    var endpoint = inferBaseUrl() + '/rep/wkf/phase/packet/history';
    if (wkfUri) {
      endpoint += '?wkfUri=' + encodeURIComponent(wkfUri);
    }

    return fetchJson(endpoint, {
      method: 'GET',
      credentials: 'same-origin'
    }).then(function (json) {
      if (!json || !json.success) {
        throw new Error((json && json.error) ? json.error : 'Could not fetch packet history.');
      }
      renderHistoryOptions(json.items || []);
      return json;
    });
  }

  function loadSelectedHistoryPacket() {
    var select = document.getElementById('wkf-phase-packet-history');
    if (!select) {
      return;
    }

    var packetId = String(select.value || '').trim();
    if (!packetId) {
      return;
    }

    var wkfUri = getText('#wkf-active-uri');
    var endpoint = inferBaseUrl() + '/rep/wkf/phase/packet/history/item/' + encodeURIComponent(packetId);
    if (wkfUri) {
      endpoint += '?wkfUri=' + encodeURIComponent(wkfUri);
    }

    fetchJson(endpoint, {
      method: 'GET',
      credentials: 'same-origin'
    })
      .then(function (json) {
        if (!json || !json.success || !json.item) {
          throw new Error((json && json.error) ? json.error : 'Could not load selected packet.');
        }

        var item = json.item;
        var output = document.getElementById('wkf-phase-packet-output');
        var fileInput = document.getElementById('wkf-phase-packet-filename');
        if (output) {
          output.value = String(item.packetText || '');
        }
        if (fileInput) {
          fileInput.value = String(item.fileName || 'wkf-phase-packet.txt');
        }
      })
      .catch(function (err) {
        alert('Failed to load saved packet: ' + err.message);
      });
  }

  function deleteSelectedHistoryPacket() {
    var select = document.getElementById('wkf-phase-packet-history');
    if (!select) {
      return;
    }

    var packetId = String(select.value || '').trim();
    if (!packetId) {
      alert('Select a saved packet first.');
      return;
    }

    if (!window.confirm('Delete the selected saved packet?')) {
      return;
    }

    var wkfUri = getText('#wkf-active-uri');
    var endpoint = inferBaseUrl() + '/rep/wkf/phase/packet/history/item/' + encodeURIComponent(packetId) + '/delete';
    if (wkfUri) {
      endpoint += '?wkfUri=' + encodeURIComponent(wkfUri);
    }

    fetchJson(endpoint, {
      method: 'POST',
      credentials: 'same-origin'
    })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.error) ? json.error : 'Could not delete selected packet.');
        }
        var output = document.getElementById('wkf-phase-packet-output');
        var fileInput = document.getElementById('wkf-phase-packet-filename');
        if (output) {
          output.value = '';
        }
        if (fileInput) {
          fileInput.value = 'wkf-phase-packet.txt';
        }
        return refreshPacketHistory();
      })
      .catch(function (err) {
        alert('Failed to delete saved packet: ' + err.message);
      });
  }

  function clearPacketHistoryScope() {
    if (!window.confirm('Clear all saved packets for the current WKF scope?')) {
      return;
    }

    var wkfUri = getText('#wkf-active-uri');
    var endpoint = inferBaseUrl() + '/rep/wkf/phase/packet/history/clear';
    if (wkfUri) {
      endpoint += '?wkfUri=' + encodeURIComponent(wkfUri);
    }

    fetchJson(endpoint, {
      method: 'POST',
      credentials: 'same-origin'
    })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.error) ? json.error : 'Could not clear packet history.');
        }
        var output = document.getElementById('wkf-phase-packet-output');
        var fileInput = document.getElementById('wkf-phase-packet-filename');
        if (output) {
          output.value = '';
        }
        if (fileInput) {
          fileInput.value = 'wkf-phase-packet.txt';
        }
        return refreshPacketHistory();
      })
      .catch(function (err) {
        alert('Failed to clear packet history: ' + err.message);
      });
  }

  function renderResponseHistoryOptions(items) {
    var select = document.getElementById('wkf-phase-response-history');
    if (!select) {
      return;
    }

    function escapeHtml(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    var html = '<option value="">Select saved response...</option>';
    (items || []).forEach(function (item) {
      var id = String(item.responseId || '');
      if (!id) {
        return;
      }
      var phase = String(item.phaseTitle || ('Phase ' + String(item.phase || '')));
      var created = String(item.createdAt || '');
      var marker = item.applied ? 'applied' : 'saved';
      var label = phase + ' [' + marker + ']' + (created ? (' [' + created + ']') : '');
      html += '<option value="' + escapeHtml(id) + '">' + escapeHtml(label) + '</option>';
    });
    select.innerHTML = html;
  }

  function refreshResponseHistory() {
    var wkfUri = getText('#wkf-active-uri');
    var endpoint = inferBaseUrl() + '/rep/wkf/phase/response/history';
    if (wkfUri) {
      endpoint += '?wkfUri=' + encodeURIComponent(wkfUri);
    }

    return fetchJson(endpoint, {
      method: 'GET',
      credentials: 'same-origin'
    }).then(function (json) {
      if (!json || !json.success) {
        throw new Error((json && json.error) ? json.error : 'Could not fetch response history.');
      }
      renderResponseHistoryOptions(json.items || []);
      return json;
    });
  }

  function loadSelectedResponseHistoryItem() {
    var select = document.getElementById('wkf-phase-response-history');
    if (!select) {
      return;
    }

    var responseId = String(select.value || '').trim();
    if (!responseId) {
      return;
    }

    var wkfUri = getText('#wkf-active-uri');
    var endpoint = inferBaseUrl() + '/rep/wkf/phase/response/history/item/' + encodeURIComponent(responseId);
    if (wkfUri) {
      endpoint += '?wkfUri=' + encodeURIComponent(wkfUri);
    }

    fetchJson(endpoint, {
      method: 'GET',
      credentials: 'same-origin'
    })
      .then(function (json) {
        if (!json || !json.success || !json.item) {
          throw new Error((json && json.error) ? json.error : 'Could not load selected response.');
        }
        var item = json.item;
        var textarea = document.getElementById('wkf-phase-response-input');
        var phaseInput = document.getElementById('wkf-phase-response-phase');
        var statusSel = document.getElementById('wkf-phase-response-status');
        if (textarea) {
          textarea.value = String(item.responseText || '');
        }
        if (phaseInput && item.phase) {
          phaseInput.value = String(item.phase);
        }
        if (statusSel && item.targetStatus) {
          statusSel.value = String(item.targetStatus);
        }
      })
      .catch(function (err) {
        alert('Failed to load saved response: ' + err.message);
      });
  }

  function deleteSelectedResponseHistoryItem() {
    var select = document.getElementById('wkf-phase-response-history');
    if (!select) {
      return;
    }

    var responseId = String(select.value || '').trim();
    if (!responseId) {
      alert('Select a saved response first.');
      return;
    }
    if (!window.confirm('Delete selected saved response?')) {
      return;
    }

    var wkfUri = getText('#wkf-active-uri');
    var endpoint = inferBaseUrl() + '/rep/wkf/phase/response/history/item/' + encodeURIComponent(responseId) + '/delete';
    if (wkfUri) {
      endpoint += '?wkfUri=' + encodeURIComponent(wkfUri);
    }

    fetchJson(endpoint, {
      method: 'POST',
      credentials: 'same-origin'
    })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.error) ? json.error : 'Could not delete selected response.');
        }
        return refreshResponseHistory();
      })
      .catch(function (err) {
        alert('Failed to delete saved response: ' + err.message);
      });
  }

  function clearResponseHistoryScope() {
    if (!window.confirm('Clear all saved responses for the current WKF scope?')) {
      return;
    }

    var wkfUri = getText('#wkf-active-uri');
    var endpoint = inferBaseUrl() + '/rep/wkf/phase/response/history/clear';
    if (wkfUri) {
      endpoint += '?wkfUri=' + encodeURIComponent(wkfUri);
    }

    fetchJson(endpoint, {
      method: 'POST',
      credentials: 'same-origin'
    })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.error) ? json.error : 'Could not clear response history.');
        }
        var textarea = document.getElementById('wkf-phase-response-input');
        if (textarea) {
          textarea.value = '';
        }
        return refreshResponseHistory();
      })
      .catch(function (err) {
        alert('Failed to clear response history: ' + err.message);
      });
  }

  function openResponseModalForPhase(phase) {
    var phaseInput = document.getElementById('wkf-phase-response-phase');
    if (phaseInput) {
      phaseInput.value = String(phase || 2);
    }

    var modalEl = document.getElementById('wkfPhaseResponseModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
      alert('Response modal is not available.');
      return;
    }

    var msg = document.getElementById('wkf-phase-response-status-msg');
    if (msg) {
      msg.textContent = '';
    }

    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    refreshResponseHistory().catch(function () {
      // Keep modal usable even if refresh fails.
    });
  }

  function applyPhaseResponse() {
    var phase = parseInt(getText('#wkf-phase-response-phase') || '0', 10);
    if (!(phase >= 2 && phase <= 5)) {
      alert('Invalid phase for response apply.');
      return;
    }

    syncActiveWkfUriFromSelection();
    var wkfUri = getText('#wkf-active-uri');
    if (!wkfUri) {
      alert('Select a WKF first so the active URI is known.');
      return;
    }

    var responseText = getText('#wkf-phase-response-input');
    if (!responseText) {
      alert('Paste ChatGPT response text first.');
      return;
    }

    var targetStatus = getText('#wkf-phase-response-status') || 'current';
    var autoValidate = !!document.getElementById('wkf-phase-response-auto-validate') && document.getElementById('wkf-phase-response-auto-validate').checked;
    var statusBox = document.getElementById('wkf-phase-response-status-msg');
    if (statusBox) {
      statusBox.textContent = 'Applying response...';
      statusBox.className = 'small mt-2 text-muted';
    }

    var endpoint = inferBaseUrl() + '/rep/wkf/phase/response/' + phase + '/apply';
    fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        wkfUri: wkfUri,
        responseText: responseText,
        targetStatus: targetStatus,
        autoValidate: autoValidate
      })
    })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.error) ? json.error : 'Could not apply ChatGPT response.');
        }

        var resultMsg = json.applyMessage || 'Response applied successfully.';
        if (json.validation) {
          var verdict = json.validation.valid ? 'PASS' : 'FAIL';
          resultMsg += ' Validation: ' + verdict + (json.validation.snapshotId ? (' [' + json.validation.snapshotId + ']') : '');
        }

        if (statusBox) {
          statusBox.textContent = resultMsg;
          statusBox.className = 'small mt-2 text-success';
        }

        refreshResponseHistory().catch(function () {
          // Silent history refresh failure.
        });
      })
      .catch(function (err) {
        if (statusBox) {
          statusBox.textContent = 'Apply failed: ' + err.message;
          statusBox.className = 'small mt-2 text-danger';
        } else {
          alert('Apply failed: ' + err.message);
        }
      });
  }

  function inferBaseUrl() {
    var base = (Drupal && Drupal.url) ? Drupal.url('') : '/';
    if (!base) {
      base = '/';
    }
    return base.endsWith('/') ? base.slice(0, -1) : base;
  }

  function promptSelectorForPhase(phase) {
    if (phase === 2) {
      return '#phase2PromptText';
    }
    if (phase === 3) {
      return '#phase3PromptText';
    }
    if (phase === 4) {
      return '#phase4PromptText';
    }
    if (phase === 5) {
      return '#phase5PromptText';
    }
    return '';
  }

  function mapPublicPhaseToBackendPhase(publicPhase) {
    if (publicPhase === 2) {
      return 2;
    }
    if (publicPhase === 3) {
      return 4;
    }
    if (publicPhase === 4) {
      return 5;
    }
    return 0;
  }

  function findPhaseSelectorForWkf(wkfUri, triggerElement) {
    var uri = String(wkfUri || '').trim();

    if (triggerElement) {
      var footer = triggerElement.closest('.wkf-card-footer');
      if (footer) {
        var local = footer.querySelector('.wkf-card-phase-selector');
        if (local) {
          return local;
        }
      }
    }

    var selectors = document.querySelectorAll('.wkf-card-phase-selector');
    if (!selectors || selectors.length === 0) {
      return null;
    }

    for (var i = 0; i < selectors.length; i++) {
      var sel = selectors[i];
      var selUri = String(sel.getAttribute('data-wkf-uri') || '').trim();
      if (uri && selUri === uri) {
        return sel;
      }
    }

    return selectors[0];
  }

  function openSheetUpdateModal(updateType, wkfUri, publicPhase) {
    var modalEl = document.getElementById('wkfSheetUpdateModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
      alert('Sheet update modal is not available.');
      return;
    }

    var titleEl = document.getElementById('wkfSheetUpdateModalLabel');
    var hintEl = document.getElementById('wkf-sheet-update-hint');
    var uriEl = document.getElementById('wkf-sheet-update-wkf-uri');
    var typeEl = document.getElementById('wkf-sheet-update-type');
    var phaseEl = document.getElementById('wkf-sheet-update-phase');
    var inputEl = document.getElementById('wkf-sheet-update-input');
    var statusEl = document.getElementById('wkf-sheet-update-status');

    if (titleEl) {
      titleEl.textContent = updateType === 'scenario' ? 'Scenario Update (STD sheet)' : 'Task Model Update (Tasks sheet)';
    }
    if (hintEl) {
      hintEl.textContent = updateType === 'scenario'
        ? 'Use this in official Phase III. Paste full TSV rows for STD sheet; this replaces the current STD sheet content.'
        : 'Use this in Phase II and official Phase IV. Paste full TSV rows for Tasks sheet; this replaces the current Tasks sheet content.';
    }
    if (uriEl) {
      uriEl.value = wkfUri || '';
    }
    if (typeEl) {
      typeEl.value = updateType;
    }
    if (phaseEl) {
      phaseEl.value = String(publicPhase || '');
    }
    if (inputEl) {
      inputEl.value = '';
    }
    if (statusEl) {
      statusEl.textContent = '';
      statusEl.className = 'small mt-2 text-muted';
    }

    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
  }

  function applySheetUpdateFromModal() {
    var wkfUri = getText('#wkf-sheet-update-wkf-uri');
    var updateType = getText('#wkf-sheet-update-type');
    var phase = parseInt(getText('#wkf-sheet-update-phase') || '0', 10);
    var tsvContent = getText('#wkf-sheet-update-input');
    var statusEl = document.getElementById('wkf-sheet-update-status');

    if (!wkfUri) {
      alert('WKF URI is missing for sheet update.');
      return;
    }
    if (updateType !== 'scenario' && updateType !== 'task_model') {
      alert('Unknown sheet update type.');
      return;
    }
    if (!tsvContent) {
      if (statusEl) {
        statusEl.textContent = 'Paste TSV content before applying update.';
        statusEl.className = 'small mt-2 text-danger';
      }
      return;
    }

    if (statusEl) {
      statusEl.textContent = 'Applying sheet update...';
      statusEl.className = 'small mt-2 text-muted';
    }

    var endpoint = inferBaseUrl() + '/rep/wkf/sheet/update';
    fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        wkfUri: wkfUri,
        updateType: updateType,
        phase: phase,
        tsvContent: tsvContent
      })
    })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.error) ? json.error : 'Could not apply sheet update.');
        }

        var validationSummary = '';
        var isTaskModel = (updateType === 'task_model');
        var isScenario = (updateType === 'scenario');
        var requiresValidation = isTaskModel || isScenario;
        var validationValid = true;
        if (requiresValidation) {
          validationValid = !!(json.validation && json.validation.valid);
          validationSummary = (json.validation && json.validation.summary) ? String(json.validation.summary) : '';
          if (!validationValid) {
            throw new Error(validationSummary || 'Sheet validation failed after apply.');
          }
        }

        if (statusEl) {
          var statusMessage = json.applyMessage || 'Sheet update applied successfully.';
          if (requiresValidation && validationSummary) {
            statusMessage += ' Validation: ' + validationSummary;
          }
          statusEl.textContent = statusMessage;
          statusEl.className = 'small mt-2 text-success';
        }

        if (requiresValidation) {
          var nextPublicPhase = parseInt((json.nextPublicPhase || phase || 0), 10);
          var selector = findPhaseSelectorForWkf(wkfUri, null);
          if (selector && nextPublicPhase >= 2 && nextPublicPhase <= 4) {
            selector.value = String(nextPublicPhase);
          }

          var modalEl = document.getElementById('wkfSheetUpdateModal');
          if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.hide();
          }

          var successLabel = isScenario ? 'Scenario Update' : 'Task Model Update';
          alert(successLabel + ' succeeded and validation passed.');
        }

        refreshPacketHistory().catch(function () {
          // Silent refresh failure.
        });
        refreshResponseHistory().catch(function () {
          // Silent refresh failure.
        });
      })
      .catch(function (err) {
        if (statusEl) {
          statusEl.textContent = 'Sheet update failed: ' + err.message;
          statusEl.className = 'small mt-2 text-danger';
        } else {
          alert('Sheet update failed: ' + err.message);
        }
      });
  }

  function buildPhasePacket(phase, promptSelector, wkfUriOverride, triggerButton) {
    if (!(phase >= 2 && phase <= 5)) {
      alert('Invalid phase for packet generation.');
      return;
    }

    var uiState = showCopiedState(triggerButton, 'Copied', 'Building...');
    uiState.setBusy();

    var promptText = getText(promptSelector || '');
    syncActiveWkfUriFromSelection();

    var wkfUri = String(wkfUriOverride || '').trim();
    if (!wkfUri) {
      wkfUri = getText('#wkf-active-uri');
    }
    if (!wkfUri) {
      wkfUri = getSessionValue('rep_wkf_active_uri_context');
    }
    if (wkfUri) {
      var uriInput = document.getElementById('wkf-active-uri');
      if (uriInput) {
        uriInput.value = wkfUri;
      }
      try {
        sessionStorage.setItem('rep_wkf_active_uri_context', wkfUri);
      } catch (e) {
        // Ignore storage failures.
      }
    }

    var sourceDoc = '';
    var byUriSource = getSourceDocByUri(wkfUri);
    if (byUriSource && !isMetadataOnlySourceDoc(byUriSource)) {
      sourceDoc = byUriSource;
    }
    if (!sourceDoc) {
      sourceDoc = getSessionValue(SOURCE_DOC_CONTENT_KEY);
    }
    if (!sourceDoc) {
      sourceDoc = getText('#wkf-source-document-context');
    }
    if (!sourceDoc) {
      sourceDoc = getSessionValue('rep_wkf_source_document_context');
    }
    if (isMetadataOnlySourceDoc(sourceDoc)) {
      sourceDoc = '';
    }
    var validationMessages = getText('#wkf-validation-copy-source');
    var wkfContent = getText('#wkf-validation-wkf-source');
    if (!wkfContent) {
      wkfContent = getText('#wkf-phase1-core-context');
    }
    if (!wkfContent) {
      wkfContent = getSessionValue('rep_wkf_phase1_core_context');
    }

    var endpoint = inferBaseUrl() + '/rep/wkf/phase/packet/' + phase;

    var sourcePromise = sourceDoc ? Promise.resolve(sourceDoc) : extractSourceDocFromPhase1Input();

    sourcePromise
      .then(function (resolvedSourceDoc) {
        var finalSource = String(resolvedSourceDoc || sourceDoc || '').trim();
        if (finalSource && !isMetadataOnlySourceDoc(finalSource)) {
          setSessionValue(SOURCE_DOC_CONTENT_KEY, finalSource);
          setSessionValue('rep_wkf_source_document_context', finalSource);
          setHiddenValue('#wkf-source-document-context', finalSource);
          if (wkfUri) {
            try {
              var raw = sessionStorage.getItem(SOURCE_DOC_CONTENT_BY_URI_KEY);
              var map = raw ? JSON.parse(raw) : {};
              if (!map || typeof map !== 'object') {
                map = {};
              }
              var aliases = getUriAliases(wkfUri);
              for (var i = 0; i < aliases.length; i++) {
                map[aliases[i]] = finalSource;
              }
              sessionStorage.setItem(SOURCE_DOC_CONTENT_BY_URI_KEY, JSON.stringify(map));
            } catch (e) {
              // Ignore storage failures.
            }
          }
        } else {
          finalSource = '';
        }

        var payload = {
          wkfUri: wkfUri,
          sourceDocument: finalSource,
          promptOverride: promptText,
          validationMessages: validationMessages,
          wkfContent: wkfContent,
          versionLabel: getText('#wkf-version-label')
        };

        return fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });
      })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (!json || !json.success) {
          var err = (json && json.error) ? json.error : 'Unknown error building packet.';
          throw new Error(err);
        }

        var packetText = String(json.packetText || '');
        if (!packetText) {
          throw new Error('Packet was generated but is empty.');
        }

        return copyToClipboard(packetText).then(function () {
          uiState.setCopied();
          refreshPacketHistory().catch(function () {
            // Silent history refresh failure.
          });
        });
      })
      .catch(function (err) {
        uiState.reset();
        alert('Failed to build phase packet: ' + err.message);
      });
  }

  Drupal.behaviors.wkfPhasePackets = {
    attach: function attach(context) {
      once('wkf-phase-uri-sync-bind', 'body', context).forEach(function () {
        var tableWrapper = document.getElementById('element-table-wrapper');
        if (tableWrapper) {
          tableWrapper.addEventListener('change', function (evt) {
            var target = evt && evt.target;
            if (!target || target.tagName !== 'INPUT' || target.type !== 'checkbox') {
              return;
            }
            var name = String(target.getAttribute('name') || '');
            if (name.indexOf('element_table[') === 0) {
              syncActiveWkfUriFromSelection();
              refreshPacketHistory().catch(function () {
                // Silent refresh failure on selection change.
              });
              refreshResponseHistory().catch(function () {
                // Silent refresh failure on selection change.
              });
            }
          });
        }

        // Initial sync after attach, useful on ajax rebuilds.
        syncActiveWkfUriFromSelection();
        refreshPacketHistory().catch(function () {
          // Silent initial refresh failure.
        });
        refreshResponseHistory().catch(function () {
          // Silent initial refresh failure.
        });
      });

      once('wkf-phase-open-response-modal', '.wkf-open-response-modal', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var phase = parseInt(btn.getAttribute('data-phase') || '2', 10);
          openResponseModalForPhase(phase);
        });
      });

      once('wkf-phase-packet-build', '.wkf-build-phase-packet', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var phase = parseInt(button.getAttribute('data-phase') || '0', 10);
          var promptSelector = button.getAttribute('data-prompt-source') || '';
          buildPhasePacket(phase, promptSelector, '', button);
        });
      });

      once('wkf-card-phase-packet-build', '.wkf-card-build-phase-packet', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var wkfUri = String(button.getAttribute('data-wkf-uri') || '').trim();
          var selector = findPhaseSelectorForWkf(wkfUri, button);
          var publicPhase = selector ? parseInt(selector.value || '0', 10) : 0;
          var phase = mapPublicPhaseToBackendPhase(publicPhase);

          if (!(phase >= 2 && phase <= 5)) {
            alert('Select a valid phase first.');
            return;
          }

          var promptSelector = promptSelectorForPhase(phase);
          buildPhasePacket(phase, promptSelector, wkfUri, button);
        });
      });

      once('wkf-card-scenario-update-open', '.wkf-card-scenario-update', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var wkfUri = String(button.getAttribute('data-wkf-uri') || '').trim();
          var selector = findPhaseSelectorForWkf(wkfUri, button);
          var publicPhase = selector ? parseInt(selector.value || '0', 10) : 0;

          if (publicPhase !== 3) {
            alert('Scenario Update is intended for official Phase III. Select Phase III first.');
            return;
          }

          openSheetUpdateModal('scenario', wkfUri, publicPhase);
        });
      });

      once('wkf-card-task-model-update-open', '.wkf-card-task-model-update', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var wkfUri = String(button.getAttribute('data-wkf-uri') || '').trim();
          var selector = findPhaseSelectorForWkf(wkfUri, button);
          var publicPhase = selector ? parseInt(selector.value || '0', 10) : 0;

          if (!(publicPhase === 2 || publicPhase === 4)) {
            alert('Task Model Update is intended for Phase II and official Phase IV. Select one of those phases first.');
            return;
          }

          openSheetUpdateModal('task_model', wkfUri, publicPhase);
        });
      });

      once('wkf-sheet-update-apply', '#wkf-sheet-update-apply', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          applySheetUpdateFromModal();
        });
      });

      once('wkf-phase-packet-copy', '#wkf-phase-packet-copy', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var text = getText('#wkf-phase-packet-output');
          if (!text) {
            return;
          }
          copyToClipboard(text).then(function () {
            var old = btn.textContent;
            btn.textContent = 'Copied';
            btn.disabled = true;
            setTimeout(function () {
              btn.textContent = old;
              btn.disabled = false;
            }, 1400);
          }).catch(function (err) {
            alert('Failed to copy packet: ' + (err && err.message ? err.message : 'clipboard unavailable'));
          });
        });
      });

      once('wkf-phase-packet-download', '#wkf-phase-packet-download', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var text = getText('#wkf-phase-packet-output');
          if (!text) {
            return;
          }
          var fileName = getText('#wkf-phase-packet-filename') || 'wkf-phase-packet.txt';
          var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
          var href = URL.createObjectURL(blob);
          var link = document.createElement('a');
          link.href = href;
          link.download = fileName;
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);
          URL.revokeObjectURL(href);
        });
      });

      once('wkf-phase-packet-refresh-history', '#wkf-phase-packet-refresh-history', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          refreshPacketHistory().catch(function (err) {
            alert('Failed to refresh packet history: ' + err.message);
          });
        });
      });

      once('wkf-phase-packet-load-history', '#wkf-phase-packet-load-history', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          loadSelectedHistoryPacket();
        });
      });

      once('wkf-phase-packet-delete-history', '#wkf-phase-packet-delete-history', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          deleteSelectedHistoryPacket();
        });
      });

      once('wkf-phase-packet-clear-history', '#wkf-phase-packet-clear-history', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          clearPacketHistoryScope();
        });
      });

      once('wkf-phase-response-refresh-history', '#wkf-phase-response-refresh-history', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          refreshResponseHistory().catch(function (err) {
            alert('Failed to refresh response history: ' + err.message);
          });
        });
      });

      once('wkf-phase-response-load-history', '#wkf-phase-response-load-history', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          loadSelectedResponseHistoryItem();
        });
      });

      once('wkf-phase-response-delete-history', '#wkf-phase-response-delete-history', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          deleteSelectedResponseHistoryItem();
        });
      });

      once('wkf-phase-response-clear-history', '#wkf-phase-response-clear-history', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          clearResponseHistoryScope();
        });
      });

      once('wkf-phase-response-apply', '#wkf-phase-response-apply', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          applyPhaseResponse();
        });
      });

    }
  };
})(Drupal, once);
