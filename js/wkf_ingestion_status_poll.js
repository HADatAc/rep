(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.repWkfIngestionStatusPoll = {
    attach: function (context) {
      once('rep-wkf-ingestion-status-poll', 'body', context).forEach(function () {
        var hasWorking = document.querySelector('[data-rep-wkf-ingestion="working"]');
        if (!hasWorking) {
          return;
        }

        // While WKF ingestion is async and still WORKING, poll by reloading the page.
        var intervalMs = 4000;
        var timer = window.setInterval(function () {
          var stillWorking = document.querySelector('[data-rep-wkf-ingestion="working"]');
          if (!stillWorking) {
            window.clearInterval(timer);
            return;
          }
          window.location.reload();
        }, intervalMs);
      });
    }
  };
})(Drupal, once);
