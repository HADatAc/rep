(function (Drupal, $) {
  Drupal.behaviors.repModal = {
    attach: function (context, settings) {
      $('.open-tree-modal', context)
        .not('.repModal-processed')
        .addClass('repModal-processed')
        .on('click', function (e) {
          e.preventDefault();

          const url = $(this).data('url');
          //const fieldId = $(this).data('field-id');
          const fieldId = $('#tree-root').data('field-id') || $(this).data('field-id');
          const elementtype = $(this).data('elementtype');
          const searchValue = $(this).val();

          $('#tree-root').data('field-id', fieldId);

          // console.log(fieldId);

          if (!drupalSettings.rep_tree) {
            drupalSettings.rep_tree = {};
          }

          drupalSettings.rep_tree.searchValue = searchValue;

          const $searchField = $('#tree-search');
          const $clearButton = $('#clear-search');
          const openedFromDrupalModal = $(this).closest('#drupal-modal').length > 0;
          const requestedDialogType = $(this).data('dialog-type') || 'modal';
          // Nested modal: avoid replacing the existing #drupal-modal.
          const dialogType = (openedFromDrupalModal && requestedDialogType === 'modal') ? 'dialog' : requestedDialogType;
          const $existingModal = dialogType === 'modal'
            ? $('#drupal-modal.ui-dialog-content')
            : $('#drupal-dialog.ui-dialog-content');

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
            // Keep the tree picker modal even when we use the non-modal dialog
            // container (#drupal-dialog) to avoid replacing the parent modal.
            modal: true,
            close: function () {
              const currentelementtype = $(this).data('elementtype') || ['unknown'];

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
            dialogType: dialogType,
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
            const $newModal = dialogType === 'modal'
              ? $('#drupal-modal.ui-dialog-content')
              : $('#drupal-dialog.ui-dialog-content');
            if ($newModal.length) {
              $newModal.data('elementtype', elementtype);
            }
          }, 500);
        });
    },
  };

  Drupal.behaviors.repTreeSelection = {
    attach: function (context, settings) {
      const $selectNodeButton = $('#select-tree-node', context);

      $selectNodeButton
        .off('click.repTreeSelection')
        .on('click.repTreeSelection', function (e) {
          e.preventDefault();

          const selectedValue = $(this).data('selected-value');
          // const fieldId = $(this).data('field-id');
          const fieldId = $('#tree-root').data('field-id') || $(this).data('field-id');


          if (fieldId && selectedValue) {
            //$(`[name="${fieldId}"], #${fieldId}`).val(selectedValue);
            $('#' + fieldId).val(selectedValue).trigger('change');
          }

          // Close only the dialog that contains this tree picker.
          // (Never close all dialogs, otherwise we also close the parent form.)
          const $dialogContent = $(this).closest('.ui-dialog-content');
          if ($dialogContent.length && $dialogContent.data('ui-dialog')) {
            $dialogContent.dialog('close');
          }

          // Prevent any other click handlers from also closing dialogs.
          e.stopImmediatePropagation();
        });
    },
  };
})(Drupal, jQuery);

