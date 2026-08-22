(function (Drupal, once) {
  'use strict';

  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }

    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', 'readonly');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }

  Drupal.behaviors.wkfValidationPanel = {
    attach: function attach(context) {
      once('wkf-validation-copy', '.wkf-validation-copy-btn', context).forEach(function (button) {
        button.addEventListener('click', async function () {
          var selector = button.getAttribute('data-copy-source') || '#wkf-validation-copy-source';
          var source = document.querySelector(selector);
          var status = button.parentElement ? button.parentElement.querySelector('.wkf-validation-copy-status') : null;

          if (!source) {
            if (status) {
              status.textContent = 'Nothing to copy';
            }
            return;
          }

          var text = source.value || source.textContent || '';
          text = text.trim();
          if (!text) {
            if (status) {
              status.textContent = 'Nothing to copy';
            }
            return;
          }

          try {
            await copyText(text);
            if (status) {
              status.textContent = 'Copied';
              window.setTimeout(function () {
                status.textContent = '';
              }, 1800);
            }
          } catch (e) {
            if (status) {
              status.textContent = 'Copy failed';
            }
          }
        });
      });
    }
  };
})(Drupal, once);
