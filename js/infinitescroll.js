(function ($, Drupal) {
  Drupal.behaviors.repInfiniteScroll = {
    attach: function (context, settings) {
      if (window.infiniteScrollInitialized) {
        return;
      }
      window.infiniteScrollInitialized = true;

      let isLoading = false;
      const pageSizeIncrement = 9;

      function debounce(func, wait) {
        let timeout;
        return function () {
          clearTimeout(timeout);
          timeout = setTimeout(() => func.apply(this, arguments), wait);
        };
      }

      function onScroll() {
        const scrollThreshold = 20;
        const loadState = $("#list_state").val();

        // Se há mais itens para carregar e estamos perto do final da página
        if (loadState == 1 && $(window).scrollTop() + $(window).height() >= $(document).height() - scrollThreshold && !isLoading) {
          isLoading = true;
          $('#loading-overlay').show();
          $('#load-more-button').trigger('click'); // Dispara o clique no botão "Load More"
        }
      }

      // Quando o carregamento é concluído, esconder o indicador e liberar para próximo carregamento
      $(document).ajaxComplete(function () {
        $('#loading-overlay').hide();
        isLoading = false;
      });

      // Bind debounce to scroll
      $(window).on('scroll', debounce(onScroll, 50));
    }
  };
})(jQuery, Drupal);
