(function ($, Drupal, once) {
  Drupal.behaviors.ajaxScrollToBottom = {
    attach: function (context, settings) {
      once('ajax-scroll', '[data-ajax-scroll="true"]', context).forEach((el) => {
        el.addEventListener('ajaxComplete', function () {
          const wrapper = document.getElementById("cards-wrapper");
          if (wrapper) {
            wrapper.scrollIntoView({ behavior: 'smooth', block: 'end' });
            console.log('[Scroll] Feito com sucesso.');
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
