(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      console.log('Tree behavior attached.');
      const $treeRoot = $('#tree-root', context);
      const $selectNodeButton = $('#select-tree-node', context);
      const $searchInput = $('#tree-search', context);
      const $clearButton = $('#clear-search', context);
      const $expandButton = $('#expand-tree', context);
      const $collapseButton = $('#collapse-tree', context);
      const $waitMessage = $('#wait-message', context);

      let searchTimeout;
      let treeReady = false;

      // Inicialmente, a árvore está escondida e a mensagem de espera é exibida
      $treeRoot.hide();
      $waitMessage.show();

      // O campo de pesquisa é desativado inicialmente
      $searchInput.prop('disabled', true);

      // Inicializa o JSTree
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
                  children: true,
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
                      children: true,
                    })));
                  },
                  error: function () {
                    console.error('Error fetching children for node:', node.original.uri);
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
            treeReady = true;

            // Habilita o campo de pesquisa
            $searchInput.prop('disabled', false);

            // Se o campo "unit" já contiver valores, realiza a pesquisa com atraso
            const initialSearch = $searchInput.val().trim();
            if (initialSearch.length > 0) {
              setTimeout(() => {
                performSearch(initialSearch);
              }, 500); // Atraso de 500ms para garantir que a árvore esteja pronta
            }
          }, 3500);
        });

        // Botões Expandir e Colapsar
        $expandButton.on('click', function () {
          if (treeReady) {
            console.log('Expanding all nodes...');
            $treeRoot.jstree('open_all');
          } else {
            console.warn('Tree is not ready to expand.');
          }
        });

        $collapseButton.on('click', function () {
          if (treeReady) {
            console.log('Collapsing all nodes...');
            $treeRoot.jstree('close_all');
          } else {
            console.warn('Tree is not ready to collapse.');
          }
        });

        // Gerencia a seleção de nós e ativa o botão "Select Node"
        $treeRoot.on('select_node.jstree', function (e, data) {
          console.log('Node selected:', data.node);
          const selectedNode = data.node.original;

          if (selectedNode.typeNamespace) {
            console.log('typeNamespace detected:', selectedNode.typeNamespace);
            $selectNodeButton
              .prop('disabled', false)
              .removeClass('disabled')
              .data('selected-value', selectedNode.typeNamespace)
              .data('field-id', $('#tree-root').data('field-id')); // Associar o campo
          } else {
            console.warn('No typeNamespace found. Disabling button.');
            $selectNodeButton
              .prop('disabled', true)
              .addClass('disabled')
              .removeData('selected-value')
              .removeData('field-id');
          }
        });

        // Função de pesquisa
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
          $treeRoot.jstree('search', searchTerm);

          setTimeout(() => {
            const matchedNodes = $treeRoot.jstree(true).get_json('#', { flat: true })
              .filter(node => (
                node.text.toLowerCase().includes(searchTerm.toLowerCase()) ||
                (node.data.typeNamespace &&
                  node.data.typeNamespace.toLowerCase().includes(searchTerm.toLowerCase()))
              ));

            matchedNodes.forEach(node => {
              console.log('Expanding matched node:', node.id);

              // Certifique-se de abrir os pais do nó antes de expandi-lo
              $treeRoot.jstree('open_node', node.parents, () => {
                $treeRoot.jstree('open_node', node.id, false, true);
              });
            });
          }, 500);
        }
        // function performSearch(searchTerm) {
        //   if (!treeReady) {
        //     console.warn('Tree is not ready for search.');
        //     return;
        //   }
        //   if (searchTerm.length < 2) {
        //     console.warn('Search term must be at least 2 characters.');
        //     return;
        //   }

        //   console.log('Performing search for term:', searchTerm);

        //   // Limpa buscas anteriores e inicia uma nova busca
        //   $treeRoot.jstree('clear_search');
        //   $treeRoot.jstree('search', searchTerm);

        //   // Aguarda o evento de busca concluída
        //   $treeRoot.off('search.jstree'); // Remove handlers duplicados, se houver
        //   $treeRoot.on('search.jstree', function (e, data) {
        //     console.log('Search completed. Nodes:', data.nodes);

        //     // Certifique-se de que `data.nodes` é uma lista iterável
        //     const nodes = Array.isArray(data.nodes) ? data.nodes : Array.from(data.nodes || []);
        //     if (nodes.length === 0) {
        //       console.warn('No matches found for term:', searchTerm);
        //       return;
        //     }

        //     // Para cada nó encontrado, abre os pais e destaca o nó
        //     nodes.forEach(nodeId => {
        //       console.log('Processing node:', nodeId);

        //       // Abre o caminho completo até o nó
        //       openPathToNode(nodeId, function () {
        //         console.log('Selecting node:', nodeId);
        //         $treeRoot.jstree('deselect_all'); // Desseleciona nós anteriores
        //         $treeRoot.jstree('select_node', nodeId); // Seleciona e destaca o nó encontrado
        //       });
        //     });
        //   });
        // }

        // // Função para abrir os pais de um nó e garantir expansão
        // function openPathToNode(nodeId, callback) {
        //   const treeInstance = $treeRoot.jstree(true);
        //   const parents = treeInstance.get_path(nodeId, true); // Obtém os IDs dos pais, incluindo o próprio nó

        //   if (!parents || parents.length === 0) {
        //     console.warn('No parents found for node:', nodeId);
        //     callback();
        //     return;
        //   }

        //   let index = 0;

        //   function openNextParent() {
        //     if (index >= parents.length - 1) {
        //       console.log('All parents opened. Expanding node:', nodeId);
        //       callback(); // Após abrir os pais, chama o callback
        //       return;
        //     }

        //     const parentId = parents[index];
        //     console.log('Opening parent node:', parentId);

        //     // Verifica se o nó pai já está aberto antes de tentar abri-lo
        //     if (treeInstance.is_open(parentId)) {
        //       console.log('Parent node already open:', parentId);
        //       index++;
        //       openNextParent(); // Continua com o próximo pai
        //     } else {
        //       treeInstance.open_node(parentId, () => {
        //         index++;
        //         openNextParent(); // Continua com o próximo pai após abertura
        //       });
        //     }
        //   }

        //   openNextParent(); // Inicia a sequência de abertura
        // }


        // Search Logic

        $searchInput.on('input', function () {
          const searchTerm = $(this).val().trim();
          if (searchTerm.length > 0) {
            $clearButton.show();
          } else {
            $clearButton.hide();
          }
        });

        $searchInput.on('keyup', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            console.log('Enter press prevented on search input.');
            return;
          }
          clearTimeout(searchTimeout);
          searchTimeout = setTimeout(() => {
            performSearch($searchInput.val().trim());
          }, 500);
        });

        $clearButton.on('click', function () {
          console.log('Clear search clicked.');
          $searchInput.val('');
          $clearButton.hide();
          $treeRoot.jstree('clear_search');
          $treeRoot.jstree('close_all');
        });

        // Previne submissão com Enter no botão ou em qualquer lugar do formulário
        $(document).on('keypress', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            console.log('Enter press prevented globally.');
          }
        });
      } else {
        console.warn('Tree root not found. Initialization aborted.');
      }
    },
  };
})(jQuery, Drupal, drupalSettings);
