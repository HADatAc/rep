(function ($, Drupal) {
    Drupal.behaviors.infinitescroll = {
      attach: function (context, settings) {
        var isLoading = false;
        var $window = $(window);
  
        function loadMoreContent() {
          if (isLoading) {
            return;
          }
  
          var loadMoreButton = $('button[data-drupal-selector="edit-load-more"]');
          if (loadMoreButton.length === 0) {
            return;
          }
  
          var elementTable = $('#element-table');
          var page = loadMoreButton.data('page');
          var elementtype = loadMoreButton.data('elementtype');
          var keyword = loadMoreButton.data('keyword');
  
          isLoading = true;
          $.ajax({
            url: Drupal.url('ajax/rep/list/load_more'),
            type: 'POST',
            data: {
              page: page + 1,
              elementtype: elementtype,
              keyword: keyword
            },
            success: function (response) {
              if (response && response.commands) {
                response.commands.forEach(function (command) {
                  if (command.command === 'insert' && command.selector === '#element-table') {
                    elementTable.append($(command.data));
                  }
                });
                loadMoreButton.data('page', page + 1);
                if ((page + 1) * settings.rep.pagesize >= settings.rep.list_size) {
                  loadMoreButton.remove();
                }
              }
              isLoading = false;
            }
          });
        }
  
        $window.on('scroll', function () {
          if ($window.scrollTop() + $window.height() >= $(document).height() - 100) {
            loadMoreContent();
          }
        });
      }
    };
  })(jQuery, Drupal);
  