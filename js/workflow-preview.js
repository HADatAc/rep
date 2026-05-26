(function (Drupal, once) {
  'use strict';

  function requestFullscreen(element) {
    if (!element) {
      return Promise.reject(new Error('No fullscreen target.'));
    }
    if (element.requestFullscreen) {
      return element.requestFullscreen();
    }
    if (element.webkitRequestFullscreen) {
      element.webkitRequestFullscreen();
      return Promise.resolve();
    }
    return Promise.reject(new Error('Fullscreen API is not supported.'));
  }

  function exitFullscreen() {
    if (document.exitFullscreen) {
      return document.exitFullscreen();
    }
    if (document.webkitExitFullscreen) {
      document.webkitExitFullscreen();
      return Promise.resolve();
    }
    return Promise.resolve();
  }

  Drupal.behaviors.repWorkflowPreview = {
    attach: function (context) {
      once('rep-workflow-preview', '[data-workflow-preview-block]', context).forEach(function (block) {
        var button = block.querySelector('[data-workflow-preview-fullscreen]');
        if (!button) {
          return;
        }

        function triggerEditorResize() {
          try {
            window.dispatchEvent(new Event('resize'));
          } catch (e) {
            // Ignore browsers without Event constructor support.
          }
        }

        function updateFullscreenState() {
          var isFullscreen = document.fullscreenElement === block;
          button.setAttribute('aria-pressed', isFullscreen ? 'true' : 'false');
          button.textContent = isFullscreen ? Drupal.t('Exit fullscreen') : Drupal.t('Fullscreen');
          button.setAttribute('title', button.textContent);
          block.classList.toggle('is-fullscreen', isFullscreen);
          triggerEditorResize();
          setTimeout(triggerEditorResize, 80);
        }

        button.addEventListener('click', function (event) {
          event.preventDefault();
          var isFullscreen = document.fullscreenElement === block;
          if (isFullscreen) {
            exitFullscreen().finally(updateFullscreenState);
          } else {
            requestFullscreen(block).then(updateFullscreenState).catch(function () {
              updateFullscreenState();
            });
          }
        });

        document.addEventListener('fullscreenchange', updateFullscreenState);
        updateFullscreenState();
      });
    }
  };
})(Drupal, once);
