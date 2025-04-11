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
        isLoading: false
      };

      // Debounce function to limit the frequency of the scroll event.
      function debounce(func, wait) {
        var timeout;
        return function () {
          clearTimeout(timeout);
          timeout = setTimeout(() => func.apply(this, arguments), wait);
        };
      }

      // onScroll function: Triggers AJAX load when near the bottom.
      function onScroll() {
        // Skip processing if scroll detection is disabled.
        if (window.myInfiniteScroll.disableScrollDetection) return;
        var scrollThreshold = 0;
        var loadState = $("#list_state").val();
        if (
          loadState == 1 &&
          $(window).scrollTop() + $(window).height() >= $(document).height() - scrollThreshold &&
          !window.myInfiniteScroll.isLoading
        ) {
          window.myInfiniteScroll.isLoading = true;
          $('#loading-overlay').show();
          $('#load-more-button').trigger('click');
        }
      }

      // Bind the debounced scroll event on window (using a custom event namespace).
      $(window).on('scroll.repInfiniteScroll', debounce(onScroll, 1000));

      // After each AJAX request completes, hide the loading overlay and reset isLoading flag.
      $(document).ajaxComplete(function () {
        $('#loading-overlay').hide();
        window.myInfiniteScroll.isLoading = false;
        console.log("finito");
      });
    }
  };

  // Behavior to scroll after AJAX completes.
  // This behavior temporarily disables the infinite scroll detection
  // to avoid triggering another AJAX load during the programmatic scroll.
  Drupal.behaviors.scrollAfterAjax = {
    attach: function (context, settings) {
      if (drupalSettings.meugrafo && drupalSettings.meugrafo.scrollAfterAjax) {
        // Disable infinite scroll detection temporarily.
        window.myInfiniteScroll.disableScrollDetection = true;
        // Delay to ensure the DOM is fully updated.
        setTimeout(function () {
          // Scroll to near the bottom of the document (adjust offset as needed).
          document.documentElement.scrollTop = document.body.scrollHeight - 1500;
          // After the scroll, re-enable scroll detection.
          setTimeout(function () {
            window.myInfiniteScroll.disableScrollDetection = false;
            drupalSettings.meugrafo.scrollAfterAjax = false;
          }, 500);
        }, 100);
      }
    }
  };
})(jQuery, Drupal, drupalSettings);
