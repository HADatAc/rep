(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      console.log('Tree behavior attached.');
      const $treeRoot = $('#tree-root', context);
      const $selectNodeButton = $('#select-tree-node', context);
      const $searchInput = $('#tree-search', context);
      const $clearButton = $('#clear-search', context);
      const $modalField = $('[data-initial-uri]');
      const $waitMessage = $('#wait-message', context);

      let searchTimeout;
      let treeReady = false;

      // Inicialmente, a árvore está escondida e a mensagem de espera é exibida
      $treeRoot.hide();
      $waitMessage.show();

      // JSTree Initialization
      if ($treeRoot.length) {
        console.log('Initializing JSTree...');
        const treeInstance = $treeRoot.jstree({
          core: {
            data: function (node, cb) {
              console.log('Fetching tree data for node:', node);
              if (node.id === '#') {
                console.log('Loading root branches...');
                cb(drupalSettings.rep_tree.branches.map(branch => ({
                  id: branch.id,
                  text: branch.label,
                  uri: branch.uri,
                  typeNamespace: branch.typeNamespace || '',
                  data: { typeNamespace: branch.typeNamespace || '' },
                  icon: 'fas fa-folder',
                  children: true
                })));
              } else {
                console.log('Loading children for node URI:', node.original.uri);
                $.ajax({
                  url: drupalSettings.rep_tree.apiEndpoint,
                  type: 'GET',
                  data: { nodeUri: node.original.uri },
                  dataType: 'json',
                  success: function (data) {
                    console.log('Fetched child nodes:', data);
                    cb(data.map(item => ({
                      id: item.nodeId,
                      text: item.label || 'Unnamed Node',
                      uri: item.uri,
                      typeNamespace: item.typeNamespace || '',
                      data: { typeNamespace: item.typeNamespace || '' },
                      icon: 'fas fa-file-alt',
                      children: true
                    })));
                  },
                  error: function () {
                    console.error('Error fetching children for node:', node.original.uri);
                    cb([]);
                  }
                });
              }
            }
          },
          plugins: ['search', 'wholerow']
        });

        // Expand and Collapse Logic After Tree Load
        $treeRoot.on('ready.jstree', function () {
          console.log('JSTree ready. Expanding all nodes...');
          $treeRoot.jstree('open_all');

          setTimeout(() => {
            console.log('Tree fully expanded. Collapsing all nodes...');
            $treeRoot.jstree('close_all');

            console.log('Tree collapsed. Showing the tree...');
            $waitMessage.hide();
            $treeRoot.show();

            treeReady = true; // Sinaliza que a árvore está carregada

            // Simula "limpar" a caixa de texto se estiver vazia
            const searchTerm = $searchInput.val().trim();
            if (searchTerm.length === 0) {
              console.log('Search input is empty. Simulating clear action...');
              $treeRoot.jstree('clear_search');
              $treeRoot.jstree('close_all'); // Certifica-se de que a árvore está colapsada
            } else if (searchTerm.length > 1) {
              console.log(`Performing search for term: ${searchTerm}`);
              performSearch(searchTerm); // Realiza a pesquisa se houver um termo válido
            }
          }, 3500);
        });

        // Função de pesquisa atualizada
        function performSearch(searchTerm) {
          if (!treeReady) {
            console.warn('Tree is not ready for search.');
            return;
          }
          if (searchTerm.length < 2) {
            console.warn('Search term must be at least 2 characters.');
            return;
          }

          console.log('Performing search for term:', searchTerm);

          // Realiza a pesquisa
          $treeRoot.jstree('search', searchTerm);

          // Aguarda para garantir que a árvore esteja manipulável
          setTimeout(() => {
            const matchedNodes = $treeRoot.jstree(true).get_json('#', { flat: true })
              .filter(node => {
                return (
                  node.text.toLowerCase().includes(searchTerm.toLowerCase()) ||
                  (node.data.typeNamespace &&
                    node.data.typeNamespace.toLowerCase().includes(searchTerm.toLowerCase()))
                );
              });

            if (matchedNodes.length > 0) {
              matchedNodes.forEach(node => {
                console.log('Expanding matched node:', node.id);

                // Abre todos os nós pais do nó correspondente
                const path = $treeRoot.jstree('get_path', node.id, '/', true); // Retorna o caminho como string
                const parents = path.split('/'); // Divide o caminho em um array

                parents.forEach(parentId => {
                  $treeRoot.jstree('open_node', parentId, false, true);
                });

                // Seleciona o nó encontrado para destacá-lo
                $treeRoot.jstree('select_node', node.id);
              });
            } else {
              console.warn('No matching nodes found.');
            }
          }, 300);
        }


        // Search Logic
        $searchInput.on('input', function () {
          console.log('Search input changed:', $(this).val());
          const searchTerm = $(this).val().trim();
          if (searchTerm.length > 0) {
            $clearButton.show(); // Exibe o botão "X"
          } else {
            $clearButton.hide(); // Oculta o botão "X"
          }
        });

        $searchInput.on('keyup', function () {
          clearTimeout(searchTimeout);
          searchTimeout = setTimeout(() => {
            performSearch($searchInput.val().trim());
          }, 300);
        });

        $clearButton.on('click', function () {
          console.log('Clear search clicked.');
          $searchInput.val('');
          $clearButton.hide();
          $treeRoot.jstree('clear_search');
          $treeRoot.jstree('close_all'); // Colapsa a árvore ao limpar a pesquisa
        });

        $searchInput.on('input', function () {
          const searchTerm = $(this).val().trim();
          if (searchTerm.length > 0) {
            $clearButton.show();
          } else {
            $clearButton.hide();
          }
        });

        // Node Selection
        $treeRoot.on('select_node.jstree', function (e, data) {
          console.log('Node selected:', data.node);
          const selectedNode = data.node.original;

          if (selectedNode.typeNamespace) {
            console.log('typeNamespace detected:', selectedNode.typeNamespace);
            $selectNodeButton
              .prop('disabled', false)
              .removeClass('disabled')
              .data('selected-value', selectedNode.typeNamespace);
          } else {
            console.warn('No typeNamespace found for selected node.');
            $selectNodeButton
              .prop('disabled', true)
              .addClass('disabled')
              .removeData('selected-value');
          }
        });
      } else {
        console.warn('Tree root not found. Initialization aborted.');
      }
    }
  };
})(jQuery, Drupal, drupalSettings);
