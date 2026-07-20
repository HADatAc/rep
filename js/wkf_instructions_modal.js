(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.wkfInstructionsModal = {
    attach: function (context, settings) {
      // Handle language selector change for instructions modal only
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

      // Open Instructions in New Window
      once('open-instructions-window', '#openWkfInstructionsWindow', context).forEach(function(element) {
        $(element).on('click', function() {
          var selectedLang = $('#wkf-language-selector').val();
          var instructionsContent = selectedLang === 'pt' 
            ? $('#instructions-pt').html()
            : $('#instructions-en').html();
          
          // Create a new window with the instructions
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

      // Copy Generation Prompt to Clipboard
      once('copy-gen-prompt', '#copyGenerationPrompt', context).forEach(function(element) {
        $(element).on('click', function() {
          var promptText = $('#generationPromptText').text();
          copyToClipboard(promptText);
          
          // Show feedback
          var btn = $(this);
          var originalHtml = btn.html();
          btn.html('<i class="fas fa-check"></i> Copied!').prop('disabled', true);
          setTimeout(function() {
            btn.html(originalHtml).prop('disabled', false);
          }, 2000);
        });
      });

      // Copy Validation Prompt to Clipboard
      once('copy-val-prompt', '#copyValidationPrompt', context).forEach(function(element) {
        $(element).on('click', function() {
          var promptText = $('#validationPromptText').text();
          copyToClipboard(promptText);
          
          // Show feedback
          var btn = $(this);
          var originalHtml = btn.html();
          btn.html('<i class="fas fa-check"></i> Copied!').prop('disabled', true);
          setTimeout(function() {
            btn.html(originalHtml).prop('disabled', false);
          }, 2000);
        });
      });

      // Helper function to copy text to clipboard
      function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          // Modern approach
          navigator.clipboard.writeText(text).catch(function(err) {
            console.error('Failed to copy text: ', err);
            fallbackCopyToClipboard(text);
          });
        } else {
          // Fallback for older browsers
          fallbackCopyToClipboard(text);
        }
      }

      // Fallback copy method
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
    }
  };

})(jQuery, Drupal, once);
