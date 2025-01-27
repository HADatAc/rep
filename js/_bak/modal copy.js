(function (Drupal, $) {
  Drupal.behaviors.repModal = {
    attach: function (context, settings) {
      $('.open-tree-modal', context)
        .not('.repModal-processed')
        .addClass('repModal-processed')
        .on('click', function (e) {
          e.preventDefault();

          const url = $(this).data('url');
          const fieldId = $(this).data('field-id');
          const elementtype = $(this).data('elementtype');
          const searchValue = $(this).val();

          if (!drupalSettings.rep_tree) {
            drupalSettings.rep_tree = {};
          }

          drupalSettings.rep_tree.searchValue = searchValue;

          const $searchField = $('#tree-search');
          const $clearButton = $('#clear-search');
          const $existingModal = $('.ui-dialog-content');

          if (
            $existingModal.length &&
            JSON.stringify($existingModal.data('elementtype')) === JSON.stringify(elementtype)
          ) {
            $existingModal.dialog('open');

            const $treeRoot = $('#tree-root');
            if ($treeRoot.length) {
              $treeRoot.jstree('close_all');
            }


            if ($searchField.length) {
              const valToSet = drupalSettings.rep_tree.searchValue || '';
              $searchField.val(valToSet);
              $clearButton.show();

              setTimeout(() => {
                const treeInstance = $treeRoot.jstree(true);
                if (treeInstance) {
                  treeInstance.search(valToSet);
                }
              }, 300);
            }
            return;
          }

          const dialogOptions = {
            title: Drupal.t('Knowledge Graph Hierarchy'),
            width: 800,
            modal: true,
            close: function () {
              const currentelementtype = $(this).data('elementtype') || ['desconhecido'];

              const initialValue = $(this).data('initial-value');
              if (initialValue) {
                $(`[name="${fieldId}"], #${fieldId}`).val(initialValue);
              }
            },
          };

          const modalUrl = `${url}&field_id=${fieldId}`;
          const $field = $(`[name="${fieldId}"], #${fieldId}`);
          const initialValue = $field.val();

          $field.data('initial-value', initialValue);

          Drupal.ajax({
            url: modalUrl,
            dialogType: 'modal',
            dialog: dialogOptions,
          }).execute();

          setTimeout(() => {
            const valToSet = drupalSettings.rep_tree.searchValue || '';
            const $searchField = $('#tree-search');

            if ($searchField.length) {
              $searchField.val(valToSet);
              $searchField.trigger('input');

              if ($searchField.val().length > 0) {
                $clearButton.show();
              } else {
                $clearButton.hide();
              }

              $clearButton.off('click').on('click', function () {
                $searchField.val('');
                $clearButton.hide();

                const $treeRoot = $('#tree-root');
                if ($treeRoot.length) {
                  $treeRoot.jstree('clear_search');
                  $treeRoot.jstree('close_all');
                }
              });
            }
          }, 500);

          setTimeout(() => {
            const $newModal = $('.ui-dialog-content');
            if ($newModal.length) {
              $newModal.data('elementtype', elementtype);
            }
          }, 500);
        });
    },
  };

  Drupal.behaviors.repTreeSelection = {
    attach: function (context, settings) {
      const $treeRoot = $('#tree-root', context);
      const $selectNodeButton = $('#select-tree-node', context);

      $selectNodeButton.prop('disabled', true);

      $treeRoot
        .off('select_node.jstree')
        .on('select_node.jstree', function (e, data) {
          const selectedNode = data.node.original;
          const typeNamespace = selectedNode.uri;

          // console.log(selectedNode);

          // if (selectedNode) {
          //   console.log(typeNamespace);
          //   $selectNodeButton
          //     .prop('disabled', false)
          //     .removeClass('disabled')
          //     .data('data-selected-value', typeNamespace);
          // } else {
          //   //console.warn('typeNamespace não encontrado, botão permanece desativado.');
          //   $selectNodeButton
          //     .prop('disabled', true)
          //     .addClass('disabled')
          //     .removeData('selected-value');
          // }
        });

      $selectNodeButton
        .off('click')
        .on('click', function (e) {
          e.preventDefault();

          const selectedValue = $(this).data('selected-value');
          const fieldId = $(this).data('field-id');

          if (fieldId && selectedValue) {
            $(`[name="${fieldId}"], #${fieldId}`).val(selectedValue);
          }
          $('.ui-dialog-content').dialog('close');
        });
    },
  };
})(Drupal, jQuery);
