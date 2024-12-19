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
          const searchValue = $(this).val(); // Obter o valor atual do campo

          // Configurar opções do modal
          const dialogOptions = {
            title: Drupal.t('Tree Form'),
            width: 800,
            modal: true,
            close: function () {
              // Restaurar o valor original se o modal for fechado
              const initialValue = $(this).data('initial-value');
              if (initialValue) {
                $(`[name="${fieldId}"], #${fieldId}`).val(initialValue);
              }
            },
          };

          // Abrir o modal usando Ajax
          const modalUrl = `${url}&field_id=${fieldId}`;
          const $field = $(`[name="${fieldId}"], #${fieldId}`);
          const initialValue = $field.val();

          // Salvar o valor inicial para restauração, se necessário
          $field.data('initial-value', initialValue);

          Drupal.ajax({
            url: modalUrl,
            dialogType: 'modal',
            dialog: dialogOptions,
          }).execute();

          // Após abrir o modal, configurar o campo de busca
          setTimeout(() => {
            const $searchField = $('#tree-search'); // Campo de busca no modal
            if ($searchField.length) {
              $searchField.val(searchValue || ''); // Definir valor inicial
              $searchField.trigger('input'); // Disparar o evento input para busca automática
            }
          }, 500); // Atraso para garantir que o modal foi carregado
        });
    },
  };

  // Lógica para o botão "Select Node" permanece inalterada
  Drupal.behaviors.repTreeSelection = {
    attach: function (context, settings) {
      const $treeRoot = $('#tree-root', context); // JSTree root
      const $selectNodeButton = $('#select-tree-node', context); // Botão "Select Node"

      // Inicializar o botão como desativado
      $selectNodeButton.prop('disabled', true);

      // Lidar com a seleção de nós no JSTree
      $treeRoot
        .off('select_node.jstree') // Prevenir bindings duplicados
        .on('select_node.jstree', function (e, data) {
          const selectedNode = data.node.original;
          const typeNamespace = selectedNode.typeNamespace || '';

          if (typeNamespace) {
            console.log('typeNamespace detectado:', typeNamespace);

            // Ativar o botão e salvar o valor selecionado
            $selectNodeButton
              .prop('disabled', false)
              .removeClass('disabled')
              .data('selected-value', typeNamespace);
          } else {
            console.warn('typeNamespace não encontrado, botão permanece desativado.');
            $selectNodeButton
              .prop('disabled', true)
              .addClass('disabled')
              .removeData('selected-value');
          }
        });

      // Lidar com clique no botão "Select Node"
      $selectNodeButton
        .off('click') // Prevenir bindings duplicados
        .on('click', function (e) {
          e.preventDefault();

          const selectedValue = $(this).data('selected-value');
          const fieldId = $(this).data('field-id');

          if (fieldId && selectedValue) {
            // Atualizar o campo com o valor selecionado
            $(`[name="${fieldId}"], #${fieldId}`).val(selectedValue);

            console.log(`Campo ${fieldId} preenchido com o valor ${selectedValue}`);
          }

          // Fechar o modal
          $('.ui-dialog-content').dialog('close');
        });
    },
  };
})(Drupal, jQuery);
