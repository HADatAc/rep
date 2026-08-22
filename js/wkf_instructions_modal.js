(function ($, Drupal, once) {
  'use strict';

  var PENDING_WKF_STORAGE_KEY = 'rep_pending_wkf_card_check';
  var SOURCE_DOC_CONTEXT_KEY = 'rep_wkf_source_document_context';
  var SOURCE_DOC_CONTENT_KEY = 'rep_wkf_source_document_content';
  var SOURCE_DOC_CONTENT_BY_URI_KEY = 'rep_wkf_source_document_content_by_uri';
  var PHASE1_CORE_CONTEXT_KEY = 'rep_wkf_phase1_core_context';
  var WKF_ACTIVE_URI_CONTEXT_KEY = 'rep_wkf_active_uri_context';
  var MAX_REFRESH_ATTEMPTS = 30;
  var REFRESH_INTERVAL_MS = 4000;

  function normalizeText(value) {
    return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function cardContainsWkfName(name) {
    var target = normalizeText(name);
    if (!target) {
      return false;
    }

    var headers = document.querySelectorAll('#element-cards-wrapper .card .card-header h5, #element-cards-wrapper .card .card-header');
    if (!headers || headers.length === 0) {
      return false;
    }

    for (var i = 0; i < headers.length; i++) {
      var header = headers[i];
      var text = normalizeText(header && header.textContent ? header.textContent : '');
      if (text && text.indexOf(target) !== -1) {
        return true;
      }
    }

    return false;
  }

  function savePendingWkfRefresh(name) {
    try {
      sessionStorage.setItem(PENDING_WKF_STORAGE_KEY, JSON.stringify({
        name: String(name || '').trim(),
        attempts: 0
      }));
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

  function rememberPacketContexts(sourceDocContext, phase1CoreContext, wkfUri) {
    var src = String(sourceDocContext || '').trim();
    var core = String(phase1CoreContext || '').trim();
    var uri = String(wkfUri || '').trim();

    setHiddenValue('#wkf-source-document-context', src);
    setHiddenValue('#wkf-phase1-core-context', core);
    if (uri && uri !== 'n/a') {
      setHiddenValue('#wkf-active-uri', uri);
    }

    try {
      if (src) {
        sessionStorage.setItem(SOURCE_DOC_CONTEXT_KEY, src);
      }
      if (core) {
        sessionStorage.setItem(PHASE1_CORE_CONTEXT_KEY, core);
      }
      if (uri && uri !== 'n/a') {
        sessionStorage.setItem(WKF_ACTIVE_URI_CONTEXT_KEY, uri);
      }
    } catch (e) {
      // Ignore storage failures.
    }
  }

  function readFileAsText(file) {
    return new Promise(function(resolve) {
      if (!file) {
        resolve('');
        return;
      }
      var reader = new FileReader();
      reader.onload = function() {
        resolve(String(reader.result || ''));
      };
      reader.onerror = function() {
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
    return new Promise(function(resolve) {
      if (!file) {
        resolve(null);
        return;
      }
      var reader = new FileReader();
      reader.onload = function() {
        resolve(reader.result || null);
      };
      reader.onerror = function() {
        resolve(null);
      };
      try {
        reader.readAsArrayBuffer(file);
      } catch (e) {
        resolve(null);
      }
    });
  }

  function extractPdfText(file) {
    if (!window.pdfjsLib || typeof window.pdfjsLib.getDocument !== 'function') {
      return Promise.resolve('');
    }

    return readFileAsArrayBuffer(file).then(function(buffer) {
      if (!buffer) {
        return '';
      }

      return window.pdfjsLib.getDocument({ data: buffer }).promise
        .then(function(pdf) {
          var pagePromises = [];
          for (var p = 1; p <= pdf.numPages; p++) {
            pagePromises.push(
              pdf.getPage(p).then(function(page) {
                return page.getTextContent().then(function(content) {
                  var items = (content && content.items) ? content.items : [];
                  return items.map(function(item) {
                    return (item && item.str) ? String(item.str) : '';
                  }).join(' ').trim();
                });
              }).catch(function() {
                return '';
              })
            );
          }

          return Promise.all(pagePromises).then(function(pages) {
            return pages.filter(function(pageText) {
              return String(pageText || '').trim() !== '';
            }).join('\n\n');
          });
        })
        .catch(function() {
          return '';
        });
    });
  }

  function initPdfJsWorker(settings) {
    if (!window.pdfjsLib) {
      return;
    }

    var baseUrl = '/';
    if (settings && settings.path && settings.path.baseUrl) {
      baseUrl = String(settings.path.baseUrl || '/');
    }
    if (!baseUrl.endsWith('/')) {
      baseUrl += '/';
    }

    window.pdfjsLib.GlobalWorkerOptions.workerSrc = baseUrl + 'modules/custom/rep/js/pdf.worker.min.js';
  }

  function extractSourceTextForPacket(file) {
    if (!file) {
      return Promise.resolve('');
    }

    var name = String(file.name || '').toLowerCase();
    var type = String(file.type || '').toLowerCase();
    var isPdf = name.endsWith('.pdf') || type === 'application/pdf';
    var isText = type.indexOf('text/') === 0 || name.endsWith('.txt') || name.endsWith('.md') || name.endsWith('.csv');

    if (isPdf) {
      return extractPdfText(file).then(function(text) {
        return String(text || '').trim();
      });
    }

    if (isText) {
      return readFileAsText(file).then(function(text) {
        return String(text || '').trim();
      });
    }

    return Promise.resolve('');
  }

  function persistSourceDocumentContent(content) {
    var value = String(content || '').trim();
    if (!value) {
      return;
    }

    setHiddenValue('#wkf-source-document-context', value);

    try {
      sessionStorage.setItem(SOURCE_DOC_CONTEXT_KEY, value);
      sessionStorage.setItem(SOURCE_DOC_CONTENT_KEY, value);
    } catch (e) {
      // Ignore storage failures.
    }
  }

  function persistSourceDocumentContentByUri(wkfUri, content) {
    var uri = String(wkfUri || '').trim();
    var value = String(content || '').trim();
    if (!uri || !value) {
      return;
    }

    try {
      var raw = sessionStorage.getItem(SOURCE_DOC_CONTENT_BY_URI_KEY);
      var map = raw ? JSON.parse(raw) : {};
      if (!map || typeof map !== 'object') {
        map = {};
      }
      var aliases = getUriAliases(uri);
      for (var i = 0; i < aliases.length; i++) {
        map[aliases[i]] = value;
      }
      sessionStorage.setItem(SOURCE_DOC_CONTENT_BY_URI_KEY, JSON.stringify(map));
    } catch (e) {
      // Ignore storage failures.
    }
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

  function isMetadataOnlySourceDocument(value) {
    var text = String(value || '').trim();
    if (!text) {
      return true;
    }
    return /^supporting\s+document\s+filename\s*:/i.test(text);
  }

  function getSourceDocumentContentByUri(wkfUri) {
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

  function restorePacketContexts() {
    var src = '';
    var core = '';
    var uri = '';
    try {
      src = String(sessionStorage.getItem(SOURCE_DOC_CONTEXT_KEY) || '').trim();
      core = String(sessionStorage.getItem(PHASE1_CORE_CONTEXT_KEY) || '').trim();
      uri = String(sessionStorage.getItem(WKF_ACTIVE_URI_CONTEXT_KEY) || '').trim();
    } catch (e) {
      // Keep empty defaults.
    }

    var currentUri = String((document.querySelector('#wkf-active-uri') && document.querySelector('#wkf-active-uri').value) || '').trim();
    var resolvedUri = currentUri || uri;
    var byUriSource = getSourceDocumentContentByUri(resolvedUri);
    if (byUriSource && !isMetadataOnlySourceDocument(byUriSource)) {
      src = byUriSource;
    }

    if (src) {
      setHiddenValue('#wkf-source-document-context', src);
    }
    if (core) {
      setHiddenValue('#wkf-phase1-core-context', core);
    }
    if (uri) {
      var current = String((document.querySelector('#wkf-active-uri') && document.querySelector('#wkf-active-uri').value) || '').trim();
      if (!current) {
        setHiddenValue('#wkf-active-uri', uri);
      }
    }
  }

  function continuePendingWkfRefreshLoop() {
    var raw = null;
    try {
      raw = sessionStorage.getItem(PENDING_WKF_STORAGE_KEY);
    } catch (e) {
      return;
    }

    if (!raw) {
      return;
    }

    var payload;
    try {
      payload = JSON.parse(raw);
    } catch (e) {
      sessionStorage.removeItem(PENDING_WKF_STORAGE_KEY);
      return;
    }

    var name = String(payload && payload.name ? payload.name : '').trim();
    var attempts = parseInt(payload && payload.attempts ? payload.attempts : 0, 10);
    if (!name) {
      sessionStorage.removeItem(PENDING_WKF_STORAGE_KEY);
      return;
    }

    if (cardContainsWkfName(name)) {
      sessionStorage.removeItem(PENDING_WKF_STORAGE_KEY);
      alert('Draft WKF card detected in Manage WKFs: ' + name);
      return;
    }

    if (attempts >= MAX_REFRESH_ATTEMPTS) {
      sessionStorage.removeItem(PENDING_WKF_STORAGE_KEY);
      alert('Draft WKF was uploaded but card was not detected yet. Please refresh manually in a few seconds.');
      return;
    }

    try {
      sessionStorage.setItem(PENDING_WKF_STORAGE_KEY, JSON.stringify({
        name: name,
        attempts: attempts + 1
      }));
    } catch (e) {
      return;
    }

    setTimeout(function() {
      window.location.reload();
    }, REFRESH_INTERVAL_MS);
  }

  function updatePhase1GenerateState() {
    var wkfName = String($('#phase1WkfName').val() || '').trim();
    var processStemUri = String($('#phase1ClinicalProcess').val() || '').trim();
    var fileInput = document.getElementById('phase1SourceDocument');
    var hasSourceDocument = !!(fileInput && fileInput.files && fileInput.files.length > 0);
    var canGenerate = (wkfName !== '' && processStemUri !== '' && hasSourceDocument);

    var $btn = $('#generatePhase1DraftWkf');
    if ($btn.length) {
      $btn.prop('disabled', !canGenerate);
      $btn.attr('aria-disabled', canGenerate ? 'false' : 'true');
      if (!canGenerate) {
        $btn.attr('title', 'Provide name, clinical process, and source document.');
      } else {
        $btn.removeAttr('title');
      }
    }
  }

  function downloadPhase1Workbook(url, formData, triggerElement) {
    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    })
      .then(function(resp) {
        if (!resp.ok) {
          return resp.text().then(function(txt) {
            throw new Error(txt || 'Failed to generate WKF workbook.');
          });
        }

        var uploadStatus = String(resp.headers.get('X-WKF-Upload-Status') || '').toLowerCase();
        var uploadMessage = String(resp.headers.get('X-WKF-Upload-Message') || '').trim();
        var wkfUri = String(resp.headers.get('X-WKF-Uri') || '').trim();

        var disposition = String(resp.headers.get('content-disposition') || '');
        var fileName = 'WKF-PHASE1.xlsx';
        var match = disposition.match(/filename="?([^";]+)"?/i);
        if (match && match[1]) {
          fileName = match[1];
        }

        return resp.blob().then(function(blob) {
          return {
            blob: blob,
            fileName: fileName,
            uploadStatus: uploadStatus,
            uploadMessage: uploadMessage,
            wkfUri: wkfUri
          };
        });
      })
      .then(function(result) {
        var href = URL.createObjectURL(result.blob);
        var link = document.createElement('a');
        link.href = href;
        link.download = result.fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(href);

        if (result.uploadStatus === 'ok') {
          var generatedCoreContext = [
            'Phase I WKF generated file: ' + String(result.fileName || ''),
            'Core WKF Name: ' + String(formData.get('wkfName') || ''),
            'Clinical Process: ' + String(formData.get('processStemLabel') || ''),
            'Clinical Process URI: ' + String(formData.get('processStemUri') || '')
          ].join('\n');
          var sourceDocContext = String(formData.get('sourceDocumentContent') || '').trim();
          if (!sourceDocContext) {
            sourceDocContext = String(sessionStorage.getItem(SOURCE_DOC_CONTENT_KEY) || '').trim();
          }
          if (!sourceDocContext) {
            sourceDocContext = 'Supporting document filename: ' + String((formData.get('sourceDocument') && formData.get('sourceDocument').name) || '');
          }
          if (result.wkfUri && sourceDocContext && sourceDocContext.indexOf('Supporting document filename:') !== 0) {
            persistSourceDocumentContentByUri(result.wkfUri, sourceDocContext);
          }
          rememberPacketContexts(sourceDocContext, generatedCoreContext, result.wkfUri);
          savePendingWkfRefresh(formData.get('wkfName') || '');
          setTimeout(function() {
            window.location.reload();
          }, REFRESH_INTERVAL_MS);
        } else if (result.uploadStatus === 'error') {
          var msg = result.uploadMessage || 'Unknown upload error.';
          alert('WKF file downloaded, but PMSR upload failed: ' + msg);
        }
      })
      .catch(function(err) {
        alert('Failed to generate Phase I WKF: ' + err.message);
      })
      .finally(function() {
        $(triggerElement).prop('disabled', false);
        updatePhase1GenerateState();
      });
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).catch(function() {
        fallbackCopyToClipboard(text);
      });
      return;
    }
    fallbackCopyToClipboard(text);
  }

  function fallbackCopyToClipboard(text) {
    var textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
      document.execCommand('copy');
    } catch (err) {
      console.error('Fallback: Failed to copy', err);
    }

    document.body.removeChild(textArea);
  }

  Drupal.behaviors.wkfInstructionsModal = {
    attach: function (context, settings) {
      once('wkf-pdfjs-worker-init', 'body', context).forEach(function() {
        initPdfJsWorker(settings || {});
      });

      once('phase1-refresh-until-card-visible', 'body', context).forEach(function() {
        restorePacketContexts();
        continuePendingWkfRefreshLoop();
      });

      // Handle language selector change for instructions modal only.
      once('wkf-lang-switch', '#wkf-language-selector', context).forEach(function(element) {
        $(element).on('change', function() {
          var selectedLang = $(this).val();

          // Only switch language for instructions content
          $('.instructions-content').hide();

          if (selectedLang === 'pt') {
            $('#instructions-pt').show();
          } else {
            $('#instructions-en').show();
          }
        });
      });

      // Update modal content when instructions modal is shown
      once('wkf-instructions-shown', '#wkfInstructionsModal', context).forEach(function(element) {
        $(element).on('show.bs.modal', function() {
          var selectedLang = $('#wkf-language-selector').val();
          $('.instructions-content').hide();
          if (selectedLang === 'pt') {
            $('#instructions-pt').show();
          } else {
            $('#instructions-en').show();
          }
        });
      });

      // Open phase instructions in a new window based on selected language.
      once('open-phase-instructions-window', '.open-wkf-phase-instructions-window', context).forEach(function(element) {
        $(element).on('click', function() {
          var selectedLang = $('#wkf-language-selector').val();
          var targetSelector = String($(this).attr('data-instructions-target') || '').trim();
          if (!targetSelector) {
            return;
          }

          var langSelector = targetSelector + ' .instructions-content-phase[data-lang="' + selectedLang + '"]';
          var fallbackSelector = targetSelector + ' .instructions-content-phase[data-lang="en"]';
          var instructionsContent = $(langSelector).html() || $(fallbackSelector).html() || '';

          var instructionsWindow = window.open('', 'WKF_Instructions', 'width=800,height=600,scrollbars=yes,resizable=yes');

          if (instructionsWindow) {
            instructionsWindow.document.write('<!DOCTYPE html><html lang="' + selectedLang + '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>WKF Generation Instructions</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>body { padding: 20px; font-family: Arial, sans-serif; } h5 { color: #0d6efd; margin-bottom: 20px; } ol { padding-left: 20px; } li { margin-bottom: 10px; } strong { color: #333; }</style></head><body><div class="container">' + instructionsContent + '</div></body></html>');
            instructionsWindow.document.close();
            instructionsWindow.focus();
          } else {
            alert('Please allow pop-ups to view the instructions in a new window.');
          }
        });
      });

      // Backward compatibility for older single instructions button.
      once('open-instructions-window-legacy', '#openWkfInstructionsWindow', context).forEach(function(element) {
        $(element).on('click', function() {
          var selectedLang = $('#wkf-language-selector').val();
          var instructionsContent = selectedLang === 'pt'
            ? $('#instructions-pt').html()
            : $('#instructions-en').html();

          var instructionsWindow = window.open('', 'WKF_Instructions', 'width=800,height=600,scrollbars=yes,resizable=yes');

          if (instructionsWindow) {
            instructionsWindow.document.write('<!DOCTYPE html><html lang="' + selectedLang + '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>WKF Generation Instructions</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>body { padding: 20px; font-family: Arial, sans-serif; } h5 { color: #0d6efd; margin-bottom: 20px; } ol { padding-left: 20px; } li { margin-bottom: 10px; } strong { color: #333; }</style></head><body><div class="container">' + instructionsContent + '</div></body></html>');
            instructionsWindow.document.close();
            instructionsWindow.focus();
          }
        });
      });

      // Generic copy-to-clipboard for all prompt modals.
      once('copy-any-prompt', '.wkf-copy-prompt', context).forEach(function(element) {
        $(element).on('click', function() {
          var sourceSelector = String($(this).attr('data-source') || '').trim();
          if (!sourceSelector) {
            return;
          }
          var promptText = $(sourceSelector).text();
          copyToClipboard(promptText);

          var btn = $(this);
          var originalHtml = btn.html();
          btn.html('<i class="fas fa-check"></i> Copied!').prop('disabled', true);
          setTimeout(function() {
            btn.html(originalHtml).prop('disabled', false);
          }, 2000);
        });
      });

      // Generate and download a real Phase I WKF file from the form values.
      once('generate-phase1-wkf-file', '#generatePhase1DraftWkf', context).forEach(function(element) {
        once('phase1-generate-prereq-watchers', 'body', context).forEach(function() {
          $(document).on('input change', '#phase1WkfName, #phase1ClinicalProcess, #phase1SourceDocument', function() {
            updatePhase1GenerateState();
          });
          updatePhase1GenerateState();
        });

        $(element).on('click', function() {
          var wkfName = String($('#phase1WkfName').val() || '').trim();
          var processSelect = $('#phase1ClinicalProcess');
          var processStemUri = String(processSelect.val() || '').trim();
          var processStemLabel = String(processSelect.find('option:selected').text() || '').trim();
          var downloadBaseUrl = String($(element).attr('data-download-url') || '').trim();
          var sourceDocumentInput = document.getElementById('phase1SourceDocument');
          var sourceDocument = sourceDocumentInput && sourceDocumentInput.files && sourceDocumentInput.files.length > 0
            ? sourceDocumentInput.files[0]
            : null;

          if (!wkfName && processStemLabel) {
            // If label is in the form "Name (URI)", keep only "Name".
            var inferredName = processStemLabel.replace(/\s*\((https?:\/\/[^)]+)\)\s*$/, '').trim();
            wkfName = inferredName || processStemLabel;
            $('#phase1WkfName').val(wkfName);
          }

          if (!wkfName) {
            alert('Please provide Core WKF Name.');
            return;
          }

          if (!processStemUri) {
            alert('Please select a Clinical Process.');
            return;
          }

          if (!sourceDocument) {
            alert('Please upload a Source document.');
            return;
          }

          if (!downloadBaseUrl) {
            alert('WKF download endpoint is not configured.');
            return;
          }

          $(element).prop('disabled', true);

          var preCoreContext = [
            'Phase I WKF seed prepared.',
            'Core WKF Name: ' + wkfName,
            'Clinical Process: ' + processStemLabel,
            'Clinical Process URI: ' + processStemUri
          ].join('\n');

          extractSourceTextForPacket(sourceDocument)
            .then(function(extractedText) {
              var preSourceContext = extractedText || ('Supporting document filename: ' + String(sourceDocument.name || ''));
              rememberPacketContexts(preSourceContext, preCoreContext, '');
              if (extractedText) {
                persistSourceDocumentContent(extractedText);
              }

              var formData = new FormData();
              formData.append('wkfName', wkfName);
              formData.append('processStemUri', processStemUri);
              formData.append('processStemLabel', processStemLabel);
              formData.append('sourceDocument', sourceDocument);
              if (extractedText) {
                formData.append('sourceDocumentContent', extractedText);
              }

              downloadPhase1Workbook(downloadBaseUrl, formData, element);
            })
            .catch(function() {
              var fallbackContext = 'Supporting document filename: ' + String(sourceDocument.name || '');
              rememberPacketContexts(fallbackContext, preCoreContext, '');

              var formData = new FormData();
              formData.append('wkfName', wkfName);
              formData.append('processStemUri', processStemUri);
              formData.append('processStemLabel', processStemLabel);
              formData.append('sourceDocument', sourceDocument);

              downloadPhase1Workbook(downloadBaseUrl, formData, element);
            });
        });
      });
    }
  };

})(jQuery, Drupal, once);
