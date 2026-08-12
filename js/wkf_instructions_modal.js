(function ($, Drupal, once) {
  'use strict';

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
        $(element).on('click', function() {
          var wkfName = String($('#phase1WkfName').val() || '').trim();
          var processSelect = $('#phase1ClinicalProcess');
          var processStemUri = String(processSelect.val() || '').trim();
          var processStemLabel = String(processSelect.find('option:selected').text() || '').trim();
          var downloadBaseUrl = String($(element).attr('data-download-url') || '').trim();

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

          if (!downloadBaseUrl) {
            alert('WKF download endpoint is not configured.');
            return;
          }

          var query = $.param({
            wkfName: wkfName,
            processStemUri: processStemUri,
            processStemLabel: processStemLabel
          });

          var url = downloadBaseUrl + (downloadBaseUrl.indexOf('?') === -1 ? '?' : '&') + query;
          window.location.href = url;
        });
      });
    }
  };

})(jQuery, Drupal, once);
