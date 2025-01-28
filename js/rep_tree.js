(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {

      once('jstree-initialized', '#tree-root', context).forEach((element) => {

        if (drupalSettings.rep_tree && drupalSettings.rep_tree.searchValue) {
          const searchVal = drupalSettings.rep_tree.searchValue;
          $('#tree-search').val(searchVal);
          //console.log(drupalSettings);
        }

        const seen = new Set();
        drupalSettings.rep_tree.branches = drupalSettings.rep_tree.branches.filter(branch => {
          const key = branch.id;
          if (seen.has(key)) {
            return false;
          }
          seen.add(key);
          return true;
        });

        //console.warn('After removing duplicates:', drupalSettings.rep_tree.branches);

        const $treeRoot = $(element);
        const $selectNodeButton = $('#select-tree-node', context);
        const $searchInput = $('#search_input', context);
        const $clearButton = $('#clear-search', context);
        const $waitMessage = $('#wait-message', context);

        let searchTimeout;
        let treeReady = false;

        let activityTimeout = null;
        const activityDelay = 1000;
        let initialSearchDone = false;

        function resetActivityTimeout() {
          if (activityTimeout) {
            clearTimeout(activityTimeout);
          }
          activityTimeout = setTimeout(() => {
            if (!initialSearchDone) {
              $treeRoot.jstree('close_all');
              $waitMessage.hide();
              $treeRoot.show();
              treeReady = true;
              $searchInput.prop('disabled', false);

              const initialSearch = $searchInput.val().trim();
              if (initialSearch.length > 0) {
                setTimeout(() => {
                  //performSearch(initialSearch);
                  $treeRoot.off('load_node.jstree', resetActivityTimeout);
                  $treeRoot.off('open_node.jstree', resetActivityTimeout);
                  initialSearchDone = true;
                }, 100);
              } else {
                initialSearchDone = true;
              }
            }
          }, activityDelay);
        }

        $treeRoot.hide();
        $waitMessage.show();

        $searchInput.prop('disabled', true);

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
            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);

            //UBERON TEMP FIX
            //console.log(drupalSettings.rep_tree);
            if (drupalSettings.rep_tree.elementType !== 'detectorattribute')
              $treeRoot.jstree('open_all');

            resetActivityTimeout();
          });

          $treeRoot.on('select_node.jstree', function (e, data) {
            //console.log('Node selected:', data.node);
            const selectedNode = data.node.original;

            if (selectedNode.id) {
              //console.log(selectedNode);
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data('selected-value', selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace)
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

          // V1
          // function performSearch(searchTerm) {
          //   if (!treeReady) {
          //     //console.warn('Tree is not ready for search.');
          //     return;
          //   }
          //   if (searchTerm.length < 2) {
          //     //console.warn('Search term must be at least 2 characters.');
          //     return;
          //   }
          //   if (searchTerm === lastSearchTerm) {
          //     return;
          //   }
          //   lastSearchTerm = searchTerm;

          //   //console.log('Performing search for term:', searchTerm);

          //   console.log('Loading children for node URI:', node.original.uri);
          //   $.ajax({
          //     url: drupalSettings.rep_tree.searchSubClassEndPoint,
          //     type: 'GET',
          //     data: { nodeUri: node.original.uri },
          //     dataType: 'json',
          //     success: function (data) {
          //       console.log('Fetched child nodes:', data);
          //       cb(data.map(item => ({
          //         id: item.nodeId,
          //         text: item.label || 'Unnamed Node',
          //         uri: item.uri,
          //         typeNamespace: item.typeNamespace || '',
          //         data: { typeNamespace: item.typeNamespace || '' },
          //         icon: 'fas fa-file-alt',
          //         children: true,
          //       })));
          //     },
          //     error: function () {
          //       //console.error('Error fetching children for node:', node.original.uri);
          //       cb([]);
          //     },
          //   });


          //   $treeRoot.jstree('search', searchTerm);

          //   setTimeout(() => {
          //     const matchedNodes = $treeRoot.jstree(true).get_json('#', { flat: true })
          //       .filter(node => (
          //         node.text.toLowerCase().includes(searchTerm.toLowerCase()) ||
          //         (node.data.typeNamespace &&
          //         node.data.typeNamespace.toLowerCase().includes(searchTerm.toLowerCase()))
          //       ));

          //     matchedNodes.forEach(node => {
          //       if (!$treeRoot.jstree(true).is_open(node.id)) {
          //         //console.log('Expanding matched node:', node.id);
          //         $treeRoot.jstree('open_node', node.parents, () => {
          //           $treeRoot.jstree('open_node', node.id, false, true);
          //         });
          //       }
          //     });
          //   }, 500);
          // }

          // FUNCIONA
          // function performSearch(searchTerm) {
          //   if (!treeReady) {
          //     console.warn('Tree is not ready for search.');
          //     return;
          //   }

          //   if (searchTerm.length < 2) {
          //     console.warn('Search term must be at least 2 characters.');
          //     return;
          //   }

          //   if (searchTerm === lastSearchTerm) {
          //     return;
          //   }

          //   lastSearchTerm = searchTerm;

          //   // Envia o termo de busca para a API
          //   console.log('Performing search for term:', searchTerm);
          //   $.ajax({
          //     url: drupalSettings.rep_tree.searchSubClassEndPoint,
          //     type: 'GET',
          //     data: {
          //       keyword: searchTerm,
          //       superuri:  drupalSettings.rep_tree.superclass
          //     }, // Aqui, envie o termo de busca para a API
          //     dataType: 'json',
          //     success: function (data) {
          //       console.log('Search results:', data);

          //       // Atualiza a árvore com os resultados retornados da API
          //       const formattedData = data.map(item => ({
          //         id: item.nodeId,
          //         text: item.label || 'Unnamed Node',
          //         uri: item.uri,
          //         typeNamespace: item.typeNamespace || '',
          //         data: { typeNamespace: item.typeNamespace || '' },
          //         icon: 'fas fa-file-alt',
          //         children: true,
          //       }));

          //       // Limpa os nós da árvore antes de adicionar novos resultados
          //       $treeRoot.jstree(true).settings.core.data = formattedData;

          //       // Recarrega a árvore com os novos resultados
          //       $treeRoot.jstree(true).refresh();

          //       // Abre os nós que correspondem ao termo de busca
          //       formattedData.forEach(node => {
          //         if (node.text.toLowerCase().includes(searchTerm.toLowerCase())) {
          //           $treeRoot.jstree('open_node', node.id);
          //         }
          //       });
          //     },
          //     error: function (xhr, status, error) {
          //       console.error('Error fetching search results:', error);
          //     },
          //   });

          //   // Opcional: Realiza a busca no cliente para complementar os resultados
          //   setTimeout(() => {
          //     const matchedNodes = $treeRoot.jstree(true).get_json('#', { flat: true })
          //       .filter(node => (
          //         node.text.toLowerCase().includes(searchTerm.toLowerCase()) ||
          //         (node.data.typeNamespace &&
          //         node.data.typeNamespace.toLowerCase().includes(searchTerm.toLowerCase()))
          //       ));

          //     matchedNodes.forEach(node => {
          //       if (!$treeRoot.jstree(true).is_open(node.id)) {
          //         console.log('Expanding matched node:', node.id);
          //         $treeRoot.jstree('open_node', node.parents, () => {
          //           $treeRoot.jstree('open_node', node.id, false, true);
          //         });
          //       }
          //     });
          //   }, 500);
          // }

          function setupAutocomplete(inputField) {
            $(inputField).on('input', function () {
              const searchTerm = $(this).val();

              if (searchTerm.length < 3) {
                // Oculta sugestões se o termo for muito curto
                $('#autocomplete-suggestions').hide();
                return;
              }

              // Envia o termo de busca para a API para obter sugestões
              $.ajax({
                url: drupalSettings.rep_tree.searchSubClassEndPoint,
                type: 'GET',
                data: { keyword: searchTerm, superuri: drupalSettings.rep_tree.superclass },
                dataType: 'json',
                success: function (data) {
                  const suggestions = data.map(item => ({
                    id: item.nodeId,
                    label: item.label || 'Unnamed Node',
                    uri: item.uri,
                  }));

                  // Mostra as sugestões em uma lista flutuante
                  let suggestionBox = $('#autocomplete-suggestions');
                  if (suggestionBox.length === 0) {
                    suggestionBox = $('<div id="autocomplete-suggestions"></div>').css({
                      position: 'absolute',
                      border: '1px solid #ccc',
                      background: '#fff',
                      zIndex: 1000,
                      maxHeight: '200px',
                      overflowY: 'auto',
                    }).appendTo('body');
                  }

                  suggestionBox.empty();
                  suggestions.forEach(suggestion => {
                    const suggestionItem = $('<div class="suggestion-item"></div>').text(suggestion.label).css({
                      padding: '5px',
                      cursor: 'pointer',
                    });

                    suggestionItem.on('click', function () {
                      // Quando uma sugestão é clicada, use-a para carregar a árvore
                      populateTree(suggestion.uri);
                      suggestionBox.hide();
                      $(inputField).val(suggestion.label);
                    });

                    suggestionBox.append(suggestionItem);
                  });

                  // Mostra a lista flutuante perto do campo de texto
                  const offset = $(inputField).offset();
                  suggestionBox.css({
                    top: offset.top + $(inputField).outerHeight(),
                    left: offset.left,
                    width: $(inputField).outerWidth(),
                  }).show();
                },
                error: function () {
                  console.error('Error fetching suggestions.');
                },
              });
            });

            // Oculta a lista quando o campo perde o foco
            $(inputField).on('blur', function () {
              setTimeout(() => $('#autocomplete-suggestions').hide(), 200);
            });
          }

          function buildHierarchy(items) {
            const nodeMap = new Map();
            let root = null;

            // Cria os nós e os adiciona ao mapa
            items.forEach(item => {
              const node = {
                id: item.uri,
                text: item.label || 'Unnamed Node',
                uri: item.uri,
                typeNamespace: item.typeNamespace || '',
                icon: 'fas fa-file-alt', // Define o ícone aqui
                children: [] // Adiciona o array de filhos
              };

              nodeMap.set(item.uri, node); // Adiciona ao mapa
            });

            // Conecta os nós ao seu respectivo pai
            items.forEach(item => {
              const node = nodeMap.get(item.uri);

              if (item.superUri) {
                const parent = nodeMap.get(item.superUri);
                if (parent) {
                  parent.children.push(node); // Adiciona o nó como filho
                }
              } else {
                // Se não tem pai, é o nó raiz
                root = node;
              }
            });

            return root;
          }


          function populateTree(uri) {
            console.log('Loading tree data for URI:', uri);
            $.ajax({
              url: drupalSettings.rep_tree.searchSuperClassEndPoint, // Substitua pelo endpoint da sua API para carregar a árvore
              type: 'GET',
              data: { uri: encodeURI(uri) },
              dataType: 'json',
              success: function (data) {
                console.log('Tree data loaded:', data);

                const hierarchy = buildHierarchy(data);
                const formattedData = Array.isArray(hierarchy) ? hierarchy : [hierarchy];

                $treeRoot.jstree(true).settings.core.data = formattedData;
                $treeRoot.jstree(true).refresh();

                $treeRoot.on('refresh.jstree', function () {
                  const treeInstance = $treeRoot.jstree(true);

                  // Função recursiva para abrir nós até o último nível
                  function openNodesRecursively(nodeId) {
                    treeInstance.open_node(nodeId, function () {
                      const children = treeInstance.get_node(nodeId).children;
                      if (children && children.length > 0) {
                        children.forEach(childId => openNodesRecursively(childId));
                      }
                    });
                  }

                  // Começa a abrir a partir do nó raiz
                  const rootNodeIds = treeInstance.get_node('#').children;
                  rootNodeIds.forEach(rootId => openNodesRecursively(rootId));
                });
              },

              error: function () {
                //console.error('Error loading tree data for URI:', uri);
              },
            });
          }

          // Inicializa o autocomplete no campo de texto de pesquisa
          $(document).ready(function () {
            setupAutocomplete('#search_input'); // Substitua '#search-field' pelo ID do seu campo de texto
          });

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
              //performSearch($searchInput.val().trim());
            }, 500);
          });

          $clearButton.on('click', function () {
            //console.log('Clear search clicked.');
            $searchInput.val('');
            $clearButton.hide();
            $treeRoot.jstree('clear_search');
            $treeRoot.jstree('close_all');
          });

          $(document).on('keypress', function (e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              //console.log('Enter press prevented globally.');
            }
          });

          $('#reset-tree').on('click', function () {
            console.log('reset');
            resetTree();
          });

          function resetTree() {
            console.log('Resetting the tree to its initial state...');

            // Clear search input and hide the clear button
            $searchInput.val('');
            $clearButton.hide();

            // Reset the tree data to initial branches
            $treeRoot.jstree(true).settings.core.data = function (node, cb) {
              if (node.id === '#') {
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
                // Do not load children for any nodes during reset
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
            };

            // Refresh the tree to reload the data
            $treeRoot.jstree(true).refresh();

            // Ensure no nodes are expanded
            $treeRoot.on('refresh.jstree', function () {
              $treeRoot.jstree('close_all'); // Close all nodes
              console.log('Tree has been reset to its initial state with only root nodes visible.');
            });
          }

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
      const $modal = $('.ui-dialog');
      const $selectNodeButton = $('#select-tree-node');

      function resetHtmlAttributes() {
        //console.log('Resetting <html> attributes...');
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });
      }

      $(document).on('dialog:afterclose', function () {
        //console.log('Drupal dialog closed.');
        resetHtmlAttributes();
      });

      $selectNodeButton.on('click', function () {
        //console.log('"Select Node" button clicked.');
        resetHtmlAttributes();
      });

      $(document).on('click', '.ui-dialog-titlebar-close', function () {
        //console.log('"X" button clicked.');
        resetHtmlAttributes();
      });
    },
  };
})(jQuery, Drupal);
