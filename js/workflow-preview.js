(function (Drupal, once) {
  'use strict';

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function normalizeText(value) {
    return String(value || '')
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  function hasProgressIndicator(scope) {
    if (!scope || !scope.querySelector) {
      return false;
    }

    return Boolean(scope.querySelector('.MuiCircularProgress-root, [role="progressbar"], .ctt-loading-indicator, .ajax-progress-throbber .throbber'));
  }

  function hasEditorSurfaceMarker(container) {
    if (!container || !container.querySelector) {
      return false;
    }

    return Boolean(container.querySelector('.react-flow, .react-flow__viewport, .react-flow__renderer, [class*="react-flow"]'));
  }

  function installConnectionFallback(block) {
    if (!block || block.getAttribute('data-workflow-preview-fallback-installed') === '1') {
      return;
    }

    var container = block.querySelector('.ctt-workflow-preview-app') || block.querySelector('#ctt-workflow-app');
    if (!container) {
      return;
    }

    block.setAttribute('data-workflow-preview-fallback-installed', '1');

    var startedAt = Date.now();
    var timeoutMs = 15000;
    var resolved = false;
    var intervalId = 0;

    function looksConnecting() {
      var text = normalizeText(container.innerText || container.textContent || '');
      if (text.indexOf('connecting to api') !== -1) {
        return true;
      }

      if (hasProgressIndicator(container) && !hasEditorSurfaceMarker(container)) {
        return true;
      }

      return false;
    }

    function looksLoaded() {
      if (container.querySelector && container.querySelector('.ctt-api-timeout')) {
        return true;
      }

      if (hasEditorSurfaceMarker(container)) {
        return true;
      }

      var text = normalizeText(container.innerText || container.textContent || '');
      if (text.indexOf('connecting to api') !== -1) {
        return false;
      }
      if (text.indexOf('loading workflow canvas') !== -1 || text.indexOf('loading ctt workflow editor') !== -1) {
        return false;
      }

      var nodeCount = container.querySelectorAll ? container.querySelectorAll('*').length : 0;
      var interactiveCount = container.querySelectorAll
        ? container.querySelectorAll('button, [role="button"], input, select, textarea, svg').length
        : 0;

      if (nodeCount >= 40 && interactiveCount >= 6) {
        return true;
      }

      return text.length >= 240 && interactiveCount >= 4;
    }

    function renderFallback() {
      if (resolved) {
        return;
      }

      var elapsed = Math.max(1, Math.round((Date.now() - startedAt) / 1000));
      var editorUrl = String(container.getAttribute('data-workflow-preview-editor-url') || '').trim();
      var actionHtml = '<button type="button" class="btn btn-default btn-sm" data-workflow-preview-retry="1">' + Drupal.t('Retry') + '</button>';

      if (editorUrl !== '') {
        actionHtml = '<a class="btn btn-primary btn-sm" href="' + escapeHtml(editorUrl) + '">' + Drupal.t('Open stable editor') + '</a> ' + actionHtml;
      }

      container.innerHTML = ''
        + '<div class="alert alert-warning workflow-preview-timeout" role="alert" style="margin:8px;">'
        + '  <h4 class="alert-heading" style="margin-top:0;">' + Drupal.t('Workflow preview timeout') + '</h4>'
        + '  <p style="margin-bottom:10px;">' + Drupal.t('Embedded preview is still connecting to API after @seconds seconds.', {'@seconds': elapsed}) + '</p>'
        + '  <div style="display:flex; gap:8px; flex-wrap:wrap;">' + actionHtml + '</div>'
        + '</div>';

      var retry = container.querySelector('[data-workflow-preview-retry="1"]');
      if (retry) {
        retry.addEventListener('click', function () {
          window.location.reload();
        });
      }

      resolved = true;
      if (intervalId) {
        clearInterval(intervalId);
        intervalId = 0;
      }
    }

    intervalId = window.setInterval(function () {
      if (resolved) {
        clearInterval(intervalId);
        intervalId = 0;
        return;
      }

      if (looksLoaded()) {
        resolved = true;
        clearInterval(intervalId);
        intervalId = 0;
        return;
      }

      var elapsedMs = Date.now() - startedAt;
      if (elapsedMs >= timeoutMs && looksConnecting()) {
        renderFallback();
        return;
      }

      if (elapsedMs >= (timeoutMs + 3000) && !looksLoaded()) {
        renderFallback();
      }
    }, 500);
  }

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

  function setIconButtonContent(button, iconClass, label) {
    if (!button) {
      return;
    }

    button.innerHTML = '<i class="fa ' + escapeHtml(iconClass) + '" aria-hidden="true"></i>'
      + '<span class="workflow-preview-sr">' + escapeHtml(label) + '</span>';
    button.setAttribute('aria-label', label);
    button.setAttribute('title', label);
  }

  Drupal.behaviors.repWorkflowPreview = {
    attach: function (context) {
      once('rep-workflow-preview', '[data-workflow-preview-block]', context).forEach(function (block) {
        installConnectionFallback(block);

        var fullscreenButton = block.querySelector('[data-workflow-preview-fullscreen]');
        var collapseButton = block.querySelector('[data-workflow-preview-collapse]');
        var canvasBody = block.querySelector('.workflow-canvas-body');

        function triggerEditorResize() {
          try {
            window.dispatchEvent(new Event('resize'));
          } catch (e) {
            // Ignore browsers without Event constructor support.
          }
        }

        function setCollapsed(collapsed) {
          if (!collapseButton || !canvasBody) {
            return;
          }

          block.classList.toggle('is-collapsed', collapsed);
          canvasBody.hidden = collapsed;
          collapseButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

          if (collapsed) {
            setIconButtonContent(collapseButton, 'fa-chevron-down', Drupal.t('Expand workflow canvas'));
            return;
          }

          setIconButtonContent(collapseButton, 'fa-chevron-up', Drupal.t('Collapse workflow canvas'));
          triggerEditorResize();
          setTimeout(triggerEditorResize, 80);
        }

        if (collapseButton && canvasBody) {
          collapseButton.addEventListener('click', function (event) {
            event.preventDefault();
            setCollapsed(!block.classList.contains('is-collapsed'));
          });

          setCollapsed(false);
        }

        if (!fullscreenButton) {
          return;
        }

        function updateFullscreenState() {
          var isFullscreen = document.fullscreenElement === block;
          fullscreenButton.setAttribute('aria-pressed', isFullscreen ? 'true' : 'false');

          if (isFullscreen) {
            setIconButtonContent(fullscreenButton, 'fa-compress', Drupal.t('Exit fullscreen'));
          } else {
            setIconButtonContent(fullscreenButton, 'fa-expand', Drupal.t('Enter fullscreen'));
          }

          block.classList.toggle('is-fullscreen', isFullscreen);
          triggerEditorResize();
          setTimeout(triggerEditorResize, 80);
        }

        fullscreenButton.addEventListener('click', function (event) {
          event.preventDefault();

          if (block.classList.contains('is-collapsed') && collapseButton && canvasBody) {
            setCollapsed(false);
          }

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
