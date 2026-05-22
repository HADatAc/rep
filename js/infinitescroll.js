(function ($, Drupal, drupalSettings) {
  // Global flag to ensure the infinite scroll behavior is attached only once.
  if (typeof window.repInfiniteScrollInitialized === 'undefined') {
    window.repInfiniteScrollInitialized = false;
  }

  Drupal.behaviors.repInfiniteScroll = {
    attach: function (context, settings) {
      // Check if the behavior is already initialized.
      if (window.repInfiniteScrollInitialized) {
        return;
      }
      window.repInfiniteScrollInitialized = true;

      // Global object to control scroll flags.
      window.myInfiniteScroll = window.myInfiniteScroll || {
        disableScrollDetection: false,
        isLoading: false,
        pendingLoadRequest: false,
        autoLoadDisabled: false,
        autoLoadErrorThreshold: 2,
        consecutiveAjaxErrors: 0,
        activeTriggerSource: null,
        pendingFailSafeTimerId: null,
        pendingSubmitFallbackTimerId: null,
        manualRequestStamp: 0,
        lastLoadMoreAjaxSendAt: 0,
        previousCardCountBeforeLoad: null,
        lastScrollYBeforeLoad: null,
        restoreScrollStorageKey: 'repInfiniteScrollRestoreY',
        restoreFlagStorageKey: 'repInfiniteScrollRestoreFlag'
      };

      var isSirSelectCardContext = !!(
        (drupalSettings.sir_select_form && drupalSettings.sir_select_form.disable_auto_scroll) ||
        (
          window.location.pathname.indexOf('/sir/select/') !== -1 &&
          $('#cards-wrapper').length > 0 &&
          $('#list_state').length > 0
        )
      );

      if (isSirSelectCardContext) {
        // SIR card view runs in explicit manual load mode only.
        window.myInfiniteScroll.autoLoadDisabled = true;

        // Prevent browser restoring to the old page position on refresh.
        if (window.history && 'scrollRestoration' in window.history) {
          window.history.scrollRestoration = 'manual';
        }

        // On hard refresh, force top position to avoid restoring to list end.
        var isPageReload = false;
        try {
          var navEntries = window.performance && window.performance.getEntriesByType
            ? window.performance.getEntriesByType('navigation')
            : [];
          if (navEntries && navEntries.length > 0) {
            isPageReload = navEntries[0].type === 'reload';
          }
          else if (window.performance && window.performance.navigation) {
            isPageReload = window.performance.navigation.type === 1;
          }
        }
        catch (e) {
          isPageReload = false;
        }

        if (isPageReload) {
          setTimeout(function () {
            window.scrollTo(0, 0);
          }, 0);
        }
      }

      function getOverlayElement() {
        if ($('#sir-loading-overlay').length) {
          return $('#sir-loading-overlay');
        }

        return $('#loading-overlay');
      }

      function getLoadMoreButtonSelector() {
        return '#load-more-button, #edit-load-more-button';
      }

      function getLoadMoreButton() {
        return $(getLoadMoreButtonSelector()).first();
      }

      function showOverlay() {
        getOverlayElement().css('display', 'flex');
      }

      function hideOverlay() {
        getOverlayElement().css('display', 'none');
      }

      function getCurrentScrollY() {
        return window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
      }

      function persistScrollForReload(y) {
        if (isSirSelectCardContext) {
          return;
        }

        try {
          sessionStorage.setItem(window.myInfiniteScroll.restoreFlagStorageKey, '1');
          sessionStorage.setItem(window.myInfiniteScroll.restoreScrollStorageKey, String(y));
        }
        catch (e) {
          // Ignore storage errors (private mode / blocked storage).
        }
      }

      function clearPersistedScrollForReload() {
        try {
          sessionStorage.removeItem(window.myInfiniteScroll.restoreFlagStorageKey);
          sessionStorage.removeItem(window.myInfiniteScroll.restoreScrollStorageKey);
        }
        catch (e) {
          // Ignore storage errors.
        }
      }

      function restoreScrollAfterReloadIfNeeded() {
        if (isSirSelectCardContext) {
          clearPersistedScrollForReload();
          return;
        }

        if (!isInfiniteScrollContextActive()) {
          clearPersistedScrollForReload();
          return;
        }

        try {
          var shouldRestore = sessionStorage.getItem(window.myInfiniteScroll.restoreFlagStorageKey) === '1';
          if (!shouldRestore) {
            return;
          }

          var rawY = sessionStorage.getItem(window.myInfiniteScroll.restoreScrollStorageKey);
          var targetY = Number(rawY);
          if (!Number.isNaN(targetY)) {
            window.scrollTo(0, Math.max(0, targetY));
            setTimeout(function () {
              window.scrollTo(0, Math.max(0, targetY));
            }, 200);
          }

          clearPersistedScrollForReload();
        }
        catch (e) {
          clearPersistedScrollForReload();
        }
      }

      function restoreInPageScrollIfNeeded() {
        if (typeof window.myInfiniteScroll.lastScrollYBeforeLoad !== 'number') {
          return;
        }

        var targetY = Math.max(0, window.myInfiniteScroll.lastScrollYBeforeLoad);
        var currentY = getCurrentScrollY();
        if ((currentY + 120) < targetY) {
          // Apply restoration more than once to survive late focus/layout shifts.
          [0, 80, 220, 500].forEach(function (delay) {
            setTimeout(function () {
              window.scrollTo(0, targetY);
            }, delay);
          });
        }

        window.myInfiniteScroll.lastScrollYBeforeLoad = null;
      }

      function isLoadMoreAjaxRequest(settings) {
        if (!settings) {
          return false;
        }

        var data = settings.data || '';
        if (typeof data !== 'string') {
          try {
            data = $.param(data);
          }
          catch (e) {
            data = '';
          }
        }

        if (!data) {
          return false;
        }

        return data.indexOf('load_more_button') !== -1;
      }

      function shouldHandlePendingRequest(settings) {
        if (!window.myInfiniteScroll.pendingLoadRequest) {
          return false;
        }

        if (isSirSelectCardContext) {
          return true;
        }

        return isLoadMoreAjaxRequest(settings);
      }

      function markLoadRequest(source) {
        if (window.myInfiniteScroll.pendingLoadRequest) {
          return;
        }

        var currentY = getCurrentScrollY();
        window.myInfiniteScroll.lastScrollYBeforeLoad = currentY;
        window.myInfiniteScroll.previousCardCountBeforeLoad = $('#cards-wrapper').children('[id^="card-item-"]').length;
        persistScrollForReload(currentY);

        window.myInfiniteScroll.pendingLoadRequest = true;
        window.myInfiniteScroll.activeTriggerSource = source;
        if (source === 'manual') {
          window.myInfiniteScroll.manualRequestStamp = Date.now();
        }
        window.myInfiniteScroll.isLoading = true;
        armPendingFailSafe();
        showOverlay();
      }

      function clearSubmitFallbackTimer() {
        if (window.myInfiniteScroll.pendingSubmitFallbackTimerId) {
          clearTimeout(window.myInfiniteScroll.pendingSubmitFallbackTimerId);
          window.myInfiniteScroll.pendingSubmitFallbackTimerId = null;
        }
      }

      function armManualSubmitFallback(buttonEl) {
        if (!isSirSelectCardContext) {
          return;
        }

        clearSubmitFallbackTimer();
        window.myInfiniteScroll.pendingSubmitFallbackTimerId = setTimeout(function () {
          if (!window.myInfiniteScroll.pendingLoadRequest) {
            return;
          }

          var manualStamp = Number(window.myInfiniteScroll.manualRequestStamp || 0);
          var ajaxStamp = Number(window.myInfiniteScroll.lastLoadMoreAjaxSendAt || 0);
          if (ajaxStamp >= manualStamp) {
            return;
          }

          var submitButton = buttonEl || getLoadMoreButton().get(0);
          var formEl = submitButton && submitButton.form ? submitButton.form : getLoadMoreButton().closest('form').get(0);
          if (!formEl) {
            return;
          }

          if (typeof formEl.requestSubmit === 'function' && submitButton) {
            formEl.requestSubmit(submitButton);
          }
          else {
            formEl.submit();
          }
        }, 300);
      }

      function clearPendingFailSafe() {
        if (window.myInfiniteScroll.pendingFailSafeTimerId) {
          clearTimeout(window.myInfiniteScroll.pendingFailSafeTimerId);
          window.myInfiniteScroll.pendingFailSafeTimerId = null;
        }

        clearSubmitFallbackTimer();
      }

      function armPendingFailSafe() {
        clearPendingFailSafe();
        window.myInfiniteScroll.pendingFailSafeTimerId = setTimeout(function () {
          if (!window.myInfiniteScroll.pendingLoadRequest) {
            return;
          }

          hideOverlay();
          window.myInfiniteScroll.isLoading = false;
          window.myInfiniteScroll.pendingLoadRequest = false;
          window.myInfiniteScroll.activeTriggerSource = null;
        }, 20000);
      }

      function moveViewportToContinuationPoint() {
        var cards = $('#cards-wrapper').children('[id^="card-item-"]');
        var previousCount = Number(window.myInfiniteScroll.previousCardCountBeforeLoad);

        if (!Number.isNaN(previousCount) && previousCount >= 0 && cards.length > previousCount) {
          var firstNewCard = cards.get(previousCount);
          if (firstNewCard && typeof firstNewCard.scrollIntoView === 'function') {
            firstNewCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
          }
        }

        var targetEl = null;
        var loadMoreButton = $(getLoadMoreButtonSelector() + ':visible').first();
        if (loadMoreButton.length > 0) {
          targetEl = loadMoreButton.get(0);
        }
        else {
          var lastCard = $('#cards-wrapper').children('[id^="card-item-"]').last();
          if (lastCard.length > 0) {
            targetEl = lastCard.get(0);
          }
        }

        if (targetEl && typeof targetEl.scrollIntoView === 'function') {
          targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }

      function showManualFallbackNotice() {
        var noticeId = '#infinite-scroll-safety-notice';
        var noticeText = Drupal.t('Auto-load paused due to repeated loading errors. Use Load More to continue.');
        var noticeHtml = '<div id="infinite-scroll-safety-notice" class="alert alert-warning mt-2">' + noticeText + '</div>';

        if ($(noticeId).length === 0 && getLoadMoreButton().length) {
          getLoadMoreButton().after(noticeHtml);
        }
      }

      function getAjaxErrorToastHost() {
        var hostId = 'rep-ajax-error-toast-host';
        var $host = $('#' + hostId);
        if ($host.length) {
          return $host;
        }

        $host = $('<div/>', { id: hostId }).css({
          position: 'fixed',
          right: '16px',
          bottom: '16px',
          width: 'min(420px, calc(100vw - 24px))',
          zIndex: 20000,
          display: 'flex',
          flexDirection: 'column',
          gap: '10px',
          pointerEvents: 'none'
        });
        $('body').append($host);
        return $host;
      }

      function buildAjaxErrorMessage(xhr) {
        var baseMessage = Drupal.t('Could not load more results. Please try again.');
        var status = (xhr && xhr.status) ? (' (HTTP ' + xhr.status + ')') : '';
        return baseMessage + status;
      }

      function showAjaxErrorToast(message) {
        var $host = getAjaxErrorToastHost();
        var $toast = $('<div/>').css({
          pointerEvents: 'auto',
          background: '#fff4f4',
          border: '1px solid #f1b8b8',
          borderLeft: '4px solid #d93f3f',
          color: '#7b1f1f',
          borderRadius: '8px',
          boxShadow: '0 10px 24px rgba(0, 0, 0, 0.16)',
          padding: '10px 12px 10px 12px',
          fontSize: '13px',
          lineHeight: '1.4',
          position: 'relative'
        });

        var $close = $('<button/>', {
          type: 'button',
          'aria-label': Drupal.t('Close'),
          text: 'x'
        }).css({
          position: 'absolute',
          top: '6px',
          right: '8px',
          border: 'none',
          background: 'transparent',
          color: '#7b1f1f',
          fontWeight: 700,
          fontSize: '14px',
          cursor: 'pointer',
          padding: 0,
          lineHeight: 1
        });

        var $content = $('<div/>').text(message).css({ paddingRight: '18px' });

        $close.on('click', function () {
          $toast.remove();
        });

        $toast.append($close).append($content);
        $host.append($toast);

        setTimeout(function () {
          $toast.fadeOut(180, function () {
            $toast.remove();
          });
        }, 7000);
      }

      function enableManualFallback() {
        window.myInfiniteScroll.autoLoadDisabled = true;
        window.myInfiniteScroll.consecutiveAjaxErrors = 0;
        showManualFallbackNotice();
      }

      // Debounce function to limit the frequency of the scroll event.
      function debounce(func, wait) {
        var timeout;
        return function () {
          clearTimeout(timeout);
          timeout = setTimeout(() => func.apply(this, arguments), wait);
        };
      }

      // onScroll function: Triggers AJAX load when near the bottom.
      function isInfiniteScrollContextActive() {
        return $('#cards-wrapper').length > 0 &&
          getLoadMoreButton().length > 0 &&
          $('#list_state').length > 0;
      }

      function onScroll() {
        // Skip processing if scroll detection is disabled.
        if (window.myInfiniteScroll.disableScrollDetection) return;
        if (window.myInfiniteScroll.autoLoadDisabled) return;
        if (!isInfiniteScrollContextActive()) return;

        var scrollThreshold = 240;
        var loadState = $("#list_state").val();
        if (
          String(loadState) === '1' &&
          $(window).scrollTop() + $(window).height() >= $(document).height() - scrollThreshold &&
          !window.myInfiniteScroll.isLoading
        ) {
          markLoadRequest('auto');
          getLoadMoreButton().trigger('click');
        }
      }

      // Track manual press early to activate overlay before request starts.
      $(document).on('mousedown.repInfiniteScroll', getLoadMoreButtonSelector(), function () {
        if (!isInfiniteScrollContextActive()) {
          return;
        }

        markLoadRequest('manual');
      });

      // If AJAX binding fails to start, force a submit as a safety fallback.
      $(document).on('click.repInfiniteScroll', getLoadMoreButtonSelector(), function () {
        if (!isInfiniteScrollContextActive()) {
          return;
        }

        if (!window.myInfiniteScroll.pendingLoadRequest) {
          markLoadRequest('manual');
        }

        armManualSubmitFallback(this);
      });

      // Fallback: if load-more AJAX starts without click tracking, still show loader.
      $(document).ajaxSend(function (event, xhr, settings) {
        var isLoadMoreRequest = isLoadMoreAjaxRequest(settings);
        if (!isLoadMoreRequest && !(isSirSelectCardContext && window.myInfiniteScroll.pendingLoadRequest)) {
          return;
        }

        if (isLoadMoreRequest) {
          window.myInfiniteScroll.lastLoadMoreAjaxSendAt = Date.now();
          clearSubmitFallbackTimer();
        }

        if (!window.myInfiniteScroll.pendingLoadRequest) {
          window.myInfiniteScroll.lastScrollYBeforeLoad = getCurrentScrollY();
          window.myInfiniteScroll.previousCardCountBeforeLoad = $('#cards-wrapper').children('[id^="card-item-"]').length;
          window.myInfiniteScroll.pendingLoadRequest = true;
          window.myInfiniteScroll.activeTriggerSource = 'ajax-send-fallback';
          window.myInfiniteScroll.isLoading = true;
          armPendingFailSafe();
        }

        showOverlay();
      });

      // Bind the debounced scroll event on window (using a custom event namespace).
      if (!isSirSelectCardContext) {
        $(window).on('scroll.repInfiniteScroll', debounce(onScroll, 1000));

        // Trigger once on attach (covers cases where the page has no scroll yet).
        setTimeout(onScroll, 0);
        setTimeout(restoreScrollAfterReloadIfNeeded, 0);
      }
      else {
        // Remove stale persisted values from previous versions on SIR pages.
        clearPersistedScrollForReload();
      }

      // After each AJAX request completes, hide the loading overlay and reset isLoading flag.
      $(document).ajaxComplete(function (event, xhr, settings) {
        if (!shouldHandlePendingRequest(settings)) {
          return;
        }

        var requestSource = window.myInfiniteScroll.activeTriggerSource;

        hideOverlay();
        clearPendingFailSafe();
        window.myInfiniteScroll.isLoading = false;
        window.myInfiniteScroll.pendingLoadRequest = false;
        window.myInfiniteScroll.activeTriggerSource = null;
        clearPersistedScrollForReload();
        restoreInPageScrollIfNeeded();

        if (isSirSelectCardContext && requestSource === 'manual') {
          setTimeout(moveViewportToContinuationPoint, 40);
        }

        window.myInfiniteScroll.previousCardCountBeforeLoad = null;

        // In manual fallback mode we intentionally keep auto-load disabled.
        if (window.myInfiniteScroll.autoLoadDisabled) {
          return;
        }

        // If content is still shorter than viewport and there are more items,
        // trigger another pass to fill the page progressively.
        var loadState = $("#list_state").val();
        if (String(loadState) === '1' && $(document).height() <= ($(window).height() + 50)) {
          setTimeout(onScroll, 100);
        }
      });

      $(document).ajaxSuccess(function (event, xhr, settings) {
        if (!shouldHandlePendingRequest(settings)) {
          return;
        }

        window.myInfiniteScroll.consecutiveAjaxErrors = 0;
      });

      $(document).ajaxError(function (event, xhr, settings) {
        if (!shouldHandlePendingRequest(settings)) {
          if (isInfiniteScrollContextActive()) {
            showAjaxErrorToast(buildAjaxErrorMessage(xhr));
          }
          return;
        }

        showAjaxErrorToast(buildAjaxErrorMessage(xhr));

        window.myInfiniteScroll.consecutiveAjaxErrors += 1;
        if (
          !window.myInfiniteScroll.autoLoadDisabled &&
          window.myInfiniteScroll.consecutiveAjaxErrors >= window.myInfiniteScroll.autoLoadErrorThreshold
        ) {
          enableManualFallback();
        }

        hideOverlay();
        clearPendingFailSafe();
        window.myInfiniteScroll.isLoading = false;
        window.myInfiniteScroll.pendingLoadRequest = false;
        window.myInfiniteScroll.activeTriggerSource = null;
        clearPersistedScrollForReload();
        restoreInPageScrollIfNeeded();
        window.myInfiniteScroll.previousCardCountBeforeLoad = null;
      });
    }
  };

  // Behavior to scroll after AJAX completes.
  // This behavior temporarily disables the infinite scroll detection
  // to avoid triggering another AJAX load during the programmatic scroll.
  Drupal.behaviors.scrollAfterAjax = {
    attach: function (context, settings) {
      // Do not run legacy programmatic scroll on card infinite-scroll screens.
      if ($('#cards-wrapper').length > 0 && $('#list_state').length > 0) {
        return;
      }

      if (drupalSettings.Social && drupalSettings.Social.scrollAfterAjax) {
        // Disable infinite scroll detection temporarily.
        window.myInfiniteScroll.disableScrollDetection = true;
        // Delay to ensure the DOM is fully updated.
        setTimeout(function () {
          // Scroll to near the bottom of the document (adjust offset as needed).
          document.documentElement.scrollTop = document.body.scrollHeight - 1500;
          // After the scroll, re-enable scroll detection.
          setTimeout(function () {
            window.myInfiniteScroll.disableScrollDetection = false;
            drupalSettings.Social.scrollAfterAjax = false;
          }, 500);
        }, 100);
      }
    }
  };
})(jQuery, Drupal, drupalSettings);
