(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      once('jstree-initialized', '#tree-root', context).forEach((element) => {

        // Antes de inicializar a árvore,
        // Se rep_tree.searchValue existir, insira-o no #tree-search
        if (drupalSettings.rep_tree && drupalSettings.rep_tree.searchValue) {
          const searchVal = drupalSettings.rep_tree.searchValue;
          $('#tree-search').val(searchVal);
        }

        // Exemplo rápido para remover duplicados:
        const seen = new Set();
        drupalSettings.rep_tree.branches = drupalSettings.rep_tree.branches.filter(branch => {
          const key = branch.id;
          if (seen.has(key)) {
            return false; // descarta duplicado
          }
          seen.add(key);
          return true;
        });

        //console.warn('After removing duplicates:', drupalSettings.rep_tree.branches);

        const $treeRoot = $(element);
        const $selectNodeButton = $('#select-tree-node', context);
        const $searchInput = $('#tree-search', context);
        const $clearButton = $('#clear-search', context);
        const $expandButton = $('#expand-tree', context);
        const $collapseButton = $('#collapse-tree', context);
        const $waitMessage = $('#wait-message', context);

        let searchTimeout;
        let treeReady = false;

        let activityTimeout = null;
        const activityDelay = 1000;
        let initialSearchDone = false; // para controlar se já removemos os eventos

        function resetActivityTimeout() {
          if (activityTimeout) {
            clearTimeout(activityTimeout);
          }
          activityTimeout = setTimeout(() => {
            // Executado quando nenhum evento por activityDelay ms
            // Só deve acontecer antes da pesquisa inicial
            if (!initialSearchDone) {
              $treeRoot.jstree('close_all');
              $waitMessage.hide();
              $treeRoot.show();
              treeReady = true;
              $searchInput.prop('disabled', false);

              const initialSearch = $searchInput.val().trim();
              if (initialSearch.length > 0) {
                setTimeout(() => {
                  performSearch(initialSearch);
                  // Ao fazer a pesquisa inicial, removemos os eventos
                  $treeRoot.off('load_node.jstree', resetActivityTimeout);
                  $treeRoot.off('open_node.jstree', resetActivityTimeout);
                  initialSearchDone = true;
                }, 100);
              } else {
                // Se não há pesquisa inicial, ainda assim marque como done
                // para evitar re-colapsos posteriores
                initialSearchDone = true;
              }
            }
          }, activityDelay);
        }

        // Inicialmente, a árvore está escondida e a mensagem de espera é exibida
        $treeRoot.hide();
        $waitMessage.show();

        // O campo de pesquisa é desativado inicialmente
        $searchInput.prop('disabled', true);

        // Inicializa o JSTree
        if ($treeRoot.length) {
          //console.warn(JSON.stringify(drupalSettings.rep_tree.branches));
          //console.log('Initializing JSTree...');
          const treeInstance = $treeRoot.jstree({
            core: {
              data: function (node, cb) {
                //console.log('Fetching tree data for node:', node);
                if (node.id === '#') {
                  //console.log('Loading root branches...');
                  cb(drupalSettings.rep_tree.branches.map(branch => ({
                    id: branch.id,
                    text: branch.label,
                    uri: branch.uri,
                    typeNamespace: branch.typeNamespace || '',
                    data: { typeNamespace: branch.typeNamespace || '' },
                    icon: 'fas fa-folder',
                    children: true,
                  })));
                } else {
                  //console.log('Loading children for node URI:', node.original.uri);
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      //console.log('Fetched child nodes:', data);
                      cb(data.map(item => ({
                        id: item.nodeId,
                        text: item.label || 'Unnamed Node',
                        uri: item.uri,
                        typeNamespace: item.typeNamespace || '',
                        data: { typeNamespace: item.typeNamespace || '' },
                        icon: 'fas fa-file-alt',
                        children: true,
                      })));
                    },
                    error: function () {
                      //console.error('Error fetching children for node:', node.original.uri);
                      cb([]);
                    },
                  });
                }
              },
            },
            plugins: ['search', 'wholerow'],
            search: {
              case_sensitive: false,
              show_only_matches: true,
              show_only_matches_children: true,
              search_callback: function (str, node) {
                const searchTerm = str.toLowerCase();
                return node.text.toLowerCase().includes(searchTerm) ||
                  (node.data.typeNamespace && node.data.typeNamespace.toLowerCase().includes(searchTerm));
              },
            },
          });

          $treeRoot.on('ready.jstree', function() {
            // Ao invés de definir resetActivityTimeout de novo aqui, apenas chamamos
            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);

            // Ao chamar open_all, começará o processo de disparo destes eventos
            $treeRoot.jstree('open_all');

            // Iniciamos o timeout também aqui, caso não dispare nenhum evento
            resetActivityTimeout();
          });


          // Botões Expandir e Colapsar
          $expandButton.on('click', function () {
            if (treeReady) {
              //console.log('Expanding all nodes...');
              $treeRoot.jstree('open_all');
            } else {
              //console.warn('Tree is not ready to expand.');
            }
          });

          $collapseButton.on('click', function () {
            if (treeReady) {
              //console.log('Collapsing all nodes...');
              $treeRoot.jstree('close_all');
            } else {
              //console.warn('Tree is not ready to collapse.');
            }
          });

          // Gerencia a seleção de nós e ativa o botão "Select Node"
          $treeRoot.on('select_node.jstree', function (e, data) {
            //console.log('Node selected:', data.node);
            const selectedNode = data.node.original;

            if (selectedNode.typeNamespace) {
              //console.log('typeNamespace detected:', selectedNode.typeNamespace);
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data('selected-value', selectedNode.typeNamespace)
                .data('field-id', $('#tree-root').data('field-id')); // Associar o campo
            } else {
              //console.warn('No typeNamespace found. Disabling button.');
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value')
                .removeData('field-id');
            }
          });

          let lastSearchTerm = '';

          function performSearch(searchTerm) {
            if (!treeReady) {
              //console.warn('Tree is not ready for search.');
              return;
            }
            if (searchTerm.length < 2) {
              //console.warn('Search term must be at least 2 characters.');
              return;
            }
            if (searchTerm === lastSearchTerm) {
              // Se for o mesmo termo da última pesquisa, não faz nada para evitar loop
              return;
            }
            lastSearchTerm = searchTerm;

            //console.log('Performing search for term:', searchTerm);
            $treeRoot.jstree('search', searchTerm);

            setTimeout(() => {
              const matchedNodes = $treeRoot.jstree(true).get_json('#', { flat: true })
                .filter(node => (
                  node.text.toLowerCase().includes(searchTerm.toLowerCase()) ||
                  (node.data.typeNamespace &&
                  node.data.typeNamespace.toLowerCase().includes(searchTerm.toLowerCase()))
                ));

              matchedNodes.forEach(node => {
                // Antes de abrir, verifica se o nó já está aberto
                if (!$treeRoot.jstree(true).is_open(node.id)) {
                  //console.log('Expanding matched node:', node.id);
                  $treeRoot.jstree('open_node', node.parents, () => {
                    $treeRoot.jstree('open_node', node.id, false, true);
                  });
                }
              });
            }, 500);
          }

          // Search Logic
          $searchInput.on('input', function () {
            const searchTerm = $searchInput.val().trim();
            if (searchTerm.length > 0) {
              $clearButton.show();
            } else {
              $clearButton.hide();
            }
          });

          $searchInput.on('keyup', function (e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              //console.log('Enter press prevented on search input.');
              return;
            }
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
              performSearch($searchInput.val().trim());
            }, 500);
          });

          $clearButton.on('click', function () {
            //console.log('Clear search clicked.');
            $searchInput.val('');
            $clearButton.hide();
            $treeRoot.jstree('clear_search');
            $treeRoot.jstree('close_all');
          });

          // Previne submissão com Enter no botão ou em qualquer lugar do formulário
          $(document).on('keypress', function (e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              //console.log('Enter press prevented globally.');
            }
          });
        } else {
          //console.warn('Tree root not found. Initialization aborted.');
        }
      });
    },
  };


})(jQuery, Drupal, drupalSettings);

(function ($, Drupal) {
  Drupal.behaviors.modalFix = {
    attach: function (context, settings) {
      const $modal = $('.ui-dialog'); // Seletor padrão para o modal do Drupal
      const $selectNodeButton = $('#select-tree-node'); // Certifique-se de que este seletor está correto

      // Função para limpar atributos do <html>
      function resetHtmlAttributes() {
        //console.log('Resetting <html> attributes...');
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });
      }

      // Evento disparado quando o modal do Drupal é fechado
      $(document).on('dialog:afterclose', function () {
        //console.log('Drupal dialog closed.');
        resetHtmlAttributes();
      });

      // Callback ao clicar no botão "Select Node"
      $selectNodeButton.on('click', function () {
        //console.log('"Select Node" button clicked.');
        resetHtmlAttributes();
      });

      // Fallback para o botão "X" do modal (caso necessário)
      $(document).on('click', '.ui-dialog-titlebar-close', function () {
        //console.log('"X" button clicked.');
        resetHtmlAttributes();
      });
    },
  };
})(jQuery, Drupal);
