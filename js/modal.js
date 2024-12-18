(function (Drupal, $, undefined) {
  Drupal.behaviors.repModal = {
    attach: function (context, settings) {
      $('.open-tree-modal', context)
        .not('.repModal-processed')
        .addClass('repModal-processed')
        .on('click', function (e) {
          e.preventDefault();

          const url = $(this).data('url');
          const fieldId = $(this).data('field-id');

          const dialogOptions = {
            title: Drupal.t('Tree Form'),
            width: 800,
            modal: true,
            close: function () {
              // Restore the original value if the modal is closed
              const initialValue = $(this).data('initial-value');
              if (initialValue) {
                $(`[name="${fieldId}"], #${fieldId}`).val(initialValue);
              }
            },
          };

          // Get the initial value of the field
          const $field = $(`[name="${fieldId}"], #${fieldId}`);
          const initialValue = $field.val();

          // Save the initial value for restoration if needed
          $field.data('initial-value', initialValue);

          // Open the modal
          const modalUrl = `${url}&field_id=${fieldId}`;
          Drupal.ajax({
            url: modalUrl,
            dialogType: 'modal',
            dialog: dialogOptions,
          }).execute();
        });
    },
  };

  // Logic for Select Node Button remains unchanged
  Drupal.behaviors.repTreeSelection = {
    attach: function (context, settings) {
      const $treeRoot = $('#tree-root', context); // JSTree root
      const $selectNodeButton = $('#select-tree-node', context); // Select Node button

      // Initialize the button as disabled
      $selectNodeButton.prop('disabled', true);

      // Handle node selection in JSTree
      $treeRoot
        .off('select_node.jstree') // Prevent duplicate bindings
        .on('select_node.jstree', function (e, data) {
          const selectedNode = data.node.original;
          const typeNamespace = selectedNode.typeNamespace || '';

          if (typeNamespace) {
            console.log('typeNamespace detected:', typeNamespace);

            // Enable the button and save the selected value
            $selectNodeButton
              .prop('disabled', false)
              .removeClass('disabled')
              .data('selected-value', typeNamespace);
          } else {
            console.warn('typeNamespace not found, button remains disabled.');
            $selectNodeButton
              .prop('disabled', true)
              .addClass('disabled')
              .removeData('selected-value');
          }
        });

      // Handle click on Select Node button
      $selectNodeButton
        .off('click') // Prevent duplicate bindings
        .on('click', function (e) {
          e.preventDefault();

          const selectedValue = $(this).data('selected-value');
          const fieldId = $(this).data('field-id');

          if (fieldId && selectedValue) {
            // Update the field with the selected value
            $(`[name="${fieldId}"], #${fieldId}`).val(selectedValue);

            console.log(`Filled field ${fieldId} with value ${selectedValue}`);
          }

          // Close the modal
          $('.ui-dialog-content').dialog('close');
        });
    },
  };
})(Drupal, jQuery);
