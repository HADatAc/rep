(function ($, Drupal, drupalSettings) {

  /**
   * Behavior principal, inicializa e gerencia a árvore (jstree).
   */
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      console.log("[DEBUG] - Drupal.behaviors.tree.attach - START");

      // Envolvemos em ready para garantir que o DOM esteja pronto
      $(document).ready(function () {
        console.log("[DEBUG] - document.ready no behavior tree...");

        const $treeRoot = $('#tree-root');
        console.log("[DEBUG] - $treeRoot encontrado?", $treeRoot.length > 0);

        if (!$treeRoot.length) {
          console.warn("[DEBUG] - O elemento #tree-root não foi encontrado. Abortando init.");
          return;
        }

        // Se houver valor pré-definido para busca, aplica no campo
        if (drupalSettings.rep_tree && drupalSettings.rep_tree.searchValue) {
          console.log("[DEBUG] - SearchValue definido em drupalSettings:", drupalSettings.rep_tree.searchValue);
          $('#tree-search').val(drupalSettings.rep_tree.searchValue);
        }

        // Seletores
        const $selectNodeButton = $('#select-tree-node');
        const $searchInput = $('#search_input');
        const $clearButton = $('#clear-search');
        const $waitMessage = $('#wait-message');

        let searchTimeout;
        let activityTimeout = null;
        let initialSearchDone = false;
        const activityDelay = 1000;

        /**
         * (1) Função para filtrar duplicatas dos nós raiz (compara label)
         */
        function getFilteredBranches() {
          console.log("[DEBUG] - getFilteredBranches() chamado.");
          const seenLabels = new Set();
          const filtered = drupalSettings.rep_tree.branches.filter(branch => {
            if (seenLabels.has(branch.label)) {
              console.warn("[DEBUG] - Duplicate branch removed:", branch.label);
              return false;
            }
            seenLabels.add(branch.label);
            return true;
          });
          console.log("[DEBUG] - Branches após filtrar duplicados:", filtered);
          return filtered;
        }

        /**
         * (2) Tratamento de tempo de atividade para exibir a árvore e esconder mensagem "aguarde"
         */
        function resetActivityTimeout() {
          console.log("[DEBUG] - resetActivityTimeout() chamado.");
          if (activityTimeout) {
            clearTimeout(activityTimeout);
          }
          activityTimeout = setTimeout(() => {
            if (!initialSearchDone) {
              console.log("[DEBUG] - activityTimeout disparou e initialSearchDone == false. Fechando nós e mostrando árvore.");
              $treeRoot.jstree('close_all');
              $waitMessage.hide();
              $treeRoot.show();
              $searchInput.prop('disabled', false);

              const initialSearch = $searchInput.val().trim();
              if (initialSearch.length > 0) {
                setTimeout(() => {
                  console.log("[DEBUG] - Havia initialSearch, removendo handlers load_node e open_node.");
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

        /**
         * (3) Função para anexar eventos (select, hover, etc.)
         */
        function attachTreeEventListeners() {
          console.log("[DEBUG] - attachTreeEventListeners() chamado.");
          // Remove qualquer evento duplicado
          $treeRoot.off('select_node.jstree hover_node.jstree load_node.jstree open_node.jstree');

          // load/open
          $treeRoot.on('load_node.jstree open_node.jstree', function () {
            console.log("[DEBUG] - load_node.jstree / open_node.jstree disparado.");
          });

          // select_node
          $treeRoot.on('select_node.jstree', function (e, data) {
            console.log("[DEBUG] - select_node.jstree disparado. data:", data);

            const selectedNode = data.node.original;
            console.log("[DEBUG] - Node original:", selectedNode);

            if (selectedNode && selectedNode.id) {
              console.log("[DEBUG] - Ativando selectNodeButton. ID:", selectedNode.id);
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data('selected-value', selectedNode.uri
                  ? selectedNode.text + " [" + selectedNode.uri + "]"
                  : selectedNode.typeNamespace)
                .data('field-id', $treeRoot.data('field-id'));
            } else {
              console.log("[DEBUG] - Desabilitando selectNodeButton (sem ID).");
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value')
                .removeData('field-id');
            }

            // Exibe o comentário, se houver
            const comment = data.node.data && data.node.data.comment;
            console.log("[DEBUG] - Comentário do nó:", comment);
            if (comment && comment.trim().length > 0) {
              console.log("[DEBUG] - Definindo HTML e exibindo #node-comment-display.");
              $('#node-comment-display')
                .html(`<strong>Description:</strong><br>${comment}`)
                .show(); // ou fadeIn()
            } else {
              console.log("[DEBUG] - Escondendo #node-comment-display (sem comentário).");
              $('#node-comment-display').hide();
            }
          });

          // hover_node
          $treeRoot.on('hover_node.jstree', function (e, data) {
            console.log("[DEBUG] - hover_node.jstree disparado.");
            const comment = data.node.data && data.node.data.comment || '';
            const nodeAnchor = $('#' + data.node.id + '_anchor');
            console.log("[DEBUG] - anchor ID:", nodeAnchor.attr('id'), "comment:", comment);
            if (comment) {
              nodeAnchor.attr('title', comment);
            } else {
              nodeAnchor.removeAttr('title');
            }
          });
        }

        /**
         * (4) Inicializa o jstree com dados iniciais
         */
        function initializeJstree() {
          console.log("[DEBUG] - initializeJstree() chamado. Iniciando jstree...");
          $treeRoot.jstree({
            core: {
              data: function (node, cb) {
                console.log("[DEBUG] - jstree core.data - node:", node);
                if (node.id === '#') {
                  console.log("[DEBUG] - Carregando nós raiz (branches).");
                  cb(getFilteredBranches().map(branch => ({
                    id: branch.id,
                    text: branch.label,
                    uri: branch.uri,
                    typeNamespace: branch.typeNamespace || '',
                    data: {
                      typeNamespace: branch.typeNamespace || '',
                      comment: branch.comment || ''
                    },
                    icon: 'fas fa-folder',
                    children: true,
                  })));
                } else {
                  console.log("[DEBUG] - Carregando filhos via AJAX para nodeUri:", node.original.uri);
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      console.log("[DEBUG] - Sucesso AJAX para filhos, data:", data);
                      let uniqueChildren = [];
                      let seenChildIds = new Set();
                      data.forEach(item => {
                        if (!seenChildIds.has(item.nodeId)) {
                          seenChildIds.add(item.nodeId);
                          uniqueChildren.push({
                            id: `node_${item.nodeId}`,
                            text: item.label || 'Unnamed Node',
                            uri: item.uri,
                            typeNamespace: item.typeNamespace || '',
                            data: {
                              typeNamespace: item.typeNamespace || '',
                              comment: item.comment || ''
                            },
                            icon: 'fas fa-file-alt',
                            children: true,
                          });
                        }
                      });
                      console.log("[DEBUG] - uniqueChildren:", uniqueChildren);
                      cb(uniqueChildren);
                    },
                    error: function () {
                      console.warn("[DEBUG] - Erro AJAX ao carregar filhos. cb([])");
                      cb([]);
                    },
                  });
                }
              },
              "multiple": false,
              "check_callback": true,
              "dblclick_toggle": true
            },
            plugins: ['search', 'wholerow'],
            search: {
              case_sensitive: false,
              show_only_matches: true,
              show_only_matches_children: true,
              search_callback: function (str, node) {
                const searchTerm = str.toLowerCase();
                const nodeText = node.text.toLowerCase();
                const hasTS = node.data.typeNamespace && node.data.typeNamespace.toLowerCase().includes(searchTerm);
                const match = nodeText.includes(searchTerm) || hasTS;
                return match;
              },
            },
          });

          // Eventos após jstree pronto
          $treeRoot.on('ready.jstree', function () {
            console.log("[DEBUG] - jstree ready.jstree disparado. Chamando attachTreeEventListeners...");
            attachTreeEventListeners();

            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);

            resetActivityTimeout();
          });
        }

        /**
         * (5) Carrega a árvore via autocomplete, construindo hierarquia
         */
        function buildHierarchy(items) {
          console.log("[DEBUG] - buildHierarchy() chamado. items:", items);
          const nodeMap = new Map();
          let root = null;
          items.forEach(item => {
            const node = {
              id: item.uri,
              text: item.label || 'Unnamed Node',
              uri: item.uri,
              typeNamespace: item.typeNamespace || '',
              icon: 'fas fa-file-alt',
              data: {
                comment: item.comment || '',
                typeNamespace: item.typeNamespace || ''
              },
              children: []
            };
            nodeMap.set(item.uri, node);
          });

          items.forEach(item => {
            const node = nodeMap.get(item.uri);
            if (item.superUri) {
              const parent = nodeMap.get(item.superUri);
              if (parent) {
                parent.children.push(node);
              }
            } else {
              root = node;
            }
          });
          console.log("[DEBUG] - buildHierarchy() retornando root:", root);
          return root;
        }

        function populateTree(uri) {
          console.log("[DEBUG] - populateTree() chamado com uri:", uri);
          $.ajax({
            url: drupalSettings.rep_tree.searchSuperClassEndPoint,
            type: 'GET',
            data: { uri: encodeURI(uri) },
            dataType: 'json',
            success: function (data) {
              console.log("[DEBUG] - populateTree() success, data:", data);
              const hierarchy = buildHierarchy(data);
              const formattedData = Array.isArray(hierarchy) ? hierarchy : [hierarchy];

              const jstreeInstance = $treeRoot.jstree(true);
              console.log("[DEBUG] - definindo jstreeInstance.settings.core.data com hierarchy formatada.");
              jstreeInstance.settings.core.data = formattedData;
              jstreeInstance.refresh();

              $treeRoot.on('refresh.jstree', function () {
                console.log("[DEBUG] - refresh.jstree disparado. Expandindo nós recursivamente...");
                const treeInstance = $treeRoot.jstree(true);
                function openNodesRecursively(nodeId) {
                  treeInstance.open_node(nodeId, function () {
                    const children = treeInstance.get_node(nodeId).children;
                    if (children && children.length > 0) {
                      children.forEach(childId => openNodesRecursively(childId));
                    }
                  });
                }
                const rootNodeIds = treeInstance.get_node('#').children;
                rootNodeIds.forEach(rootId => openNodesRecursively(rootId));
              });
            },
            error: function () {
              console.error("[DEBUG] - populateTree() AJAX error para URI:", uri);
            },
          });
        }

        /**
         * (6) Reset da árvore
         */
        function resetTree() {
          console.log("[DEBUG] - resetTree() chamado. Limpando search, destruindo e recriando jstree...");
          $searchInput.val('');
          $clearButton.hide();

          $treeRoot.jstree('destroy').empty();

          // Recria a árvore com dados iniciais (branches filtrados)
          $treeRoot.jstree({
            core: {
              data: function (node, cb) {
                console.log("[DEBUG] - resetTree() - jstree core.data, node:", node);
                if (node.id === '#') {
                  console.log("[DEBUG] - Raiz no resetTree(). Usando getFilteredBranches().");
                  cb(getFilteredBranches().map(branch => ({
                    id: branch.id,
                    text: branch.label,
                    uri: branch.uri,
                    typeNamespace: branch.typeNamespace || '',
                    data: {
                      typeNamespace: branch.typeNamespace || '',
                      comment: branch.comment || ''
                    },
                    icon: 'fas fa-folder',
                    children: true,
                    state: { opened: false },
                  })));
                } else {
                  console.log("[DEBUG] - resetTree() - Carregando filhos via AJAX, nodeUri:", node.original.uri);
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      console.log("[DEBUG] - resetTree() - AJAX success, data:", data);
                      let uniqueChildren = [];
                      let seenChildIds = new Set();
                      data.forEach(item => {
                        if (!seenChildIds.has(item.nodeId)) {
                          seenChildIds.add(item.nodeId);
                          uniqueChildren.push({
                            id: `node_${item.nodeId}`,
                            text: item.label || 'Unnamed Node',
                            uri: item.uri,
                            typeNamespace: item.typeNamespace || '',
                            data: {
                              typeNamespace: item.typeNamespace || '',
                              comment: item.comment || ''
                            },
                            icon: 'fas fa-file-alt',
                            children: true,
                          });
                        }
                      });
                      cb(uniqueChildren);
                    },
                    error: function () {
                      console.warn("[DEBUG] - resetTree() - AJAX error, cb([])");
                      cb([]);
                    },
                  });
                }
              },
              "multiple": false,
              "check_callback": true,
              "dblclick_toggle": true
            },
            plugins: ['search', 'wholerow'],
            search: {
              case_sensitive: false,
              show_only_matches: true,
              show_only_matches_children: true,
            },
          });

          // Reanexa eventos após jstree pronto
          $treeRoot.on('ready.jstree', function () {
            console.log("[DEBUG] - resetTree() - ready.jstree. Chamando attachTreeEventListeners()");
            attachTreeEventListeners();
          });
          console.log("[DEBUG] - resetTree() complete. Esperando ready.jstree para reanexar handlers.");
        }

        /**
         * (7) Eventos de busca e reset
         */
        $searchInput.on('input', function () {
          console.log("[DEBUG] - $searchInput on.input:", $(this).val());
          const searchTerm = $(this).val();
          if (searchTerm.length > 0) {
            $clearButton.show();
          } else {
            $clearButton.hide();
          }
        });

        $searchInput.on('keyup', function (e) {
          // Evita submit no Enter
          if (e.key === 'Enter') {
            e.preventDefault();
            return;
          }
          clearTimeout(searchTimeout);
          searchTimeout = setTimeout(() => {
            console.log("[DEBUG] - searchTimeout disparado, valor:", $searchInput.val());
            // Ex: performSearch($searchInput.val().trim());
          }, 500);
        });

        $clearButton.on('click', function () {
          console.log("[DEBUG] - $clearButton.on(click) -> Limpando busca e resetando árvore.");
          $searchInput.val('');
          $clearButton.hide();
          $treeRoot.jstree('clear_search');
          resetTree();
        });

        $(document).on('keypress', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
          }
        });

        $('#reset-tree').on('click', function () {
          console.log("[DEBUG] - #reset-tree.on(click). Chamando resetTree()...");
          resetTree();
        });

        /**
         * (8) Autocomplete
         */
        function setupAutocomplete(inputField) {
          console.log("[DEBUG] - setupAutocomplete() chamado para inputField:", inputField);
          $(inputField).on('input', function () {
            const searchTerm = $(this).val();
            console.log("[DEBUG] - autocomplete - digitou:", searchTerm);

            if (searchTerm.length < 3) {
              console.log("[DEBUG] - searchTerm < 3. Ocultando #autocomplete-suggestions.");
              $('#autocomplete-suggestions').hide();
              return;
            }
            $.ajax({
              url: drupalSettings.rep_tree.searchSubClassEndPoint,
              type: 'GET',
              data: {
                keyword: searchTerm,
                superuri: drupalSettings.rep_tree.superclass
              },
              dataType: 'json',
              success: function (data) {
                console.log("[DEBUG] - Autocomplete success, data:", data);
                const suggestions = data.map(item => ({
                  id: item.nodeId,
                  label: item.label || 'Unnamed Node',
                  uri: item.uri,
                }));

                let suggestionBox = $('#autocomplete-suggestions');
                if (suggestionBox.length === 0) {
                  console.log("[DEBUG] - Criando #autocomplete-suggestions no DOM.");
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
                  const suggestionItem = $('<div class="suggestion-item"></div>')
                    .text(suggestion.label)
                    .css({ padding: '5px', cursor: 'pointer' });
                  suggestionItem.on('click', function () {
                    console.log("[DEBUG] - Clicou em suggestionItem:", suggestion);
                    populateTree(suggestion.uri);
                    suggestionBox.hide();
                    $(inputField).val(suggestion.label);
                  });
                  suggestionBox.append(suggestionItem);
                });
                const offset = $(inputField).offset();
                suggestionBox.css({
                  top: offset.top + $(inputField).outerHeight(),
                  left: offset.left,
                  width: $(inputField).outerWidth(),
                }).show();
              },
              error: function () {
                console.error("[DEBUG] - Autocomplete AJAX error.");
              },
            });
          });

          // Esconde a box se o input perder foco
          $(inputField).on('blur', function () {
            setTimeout(() => $('#autocomplete-suggestions').hide(), 200);
          });
        }

        /**
         * (9) Oculta a árvore e exibe mensagem de espera inicialmente
         */
        console.log("[DEBUG] - Escondendo árvore e mostrando mensagem de espera.");
        $treeRoot.hide();
        $waitMessage.show();
        $searchInput.prop('disabled', true);

        /**
         * (10) Inicializa a jstree
         */
        console.log("[DEBUG] - Chamando initializeJstree()...");
        initializeJstree();

        /**
         * (11) Inicializa o autocomplete
         */
        console.log("[DEBUG] - Chamando setupAutocomplete() para #search_input...");
        setupAutocomplete('#search_input');

        // FORÇA que clicar no texto do nó selecione
        $('#tree-root')
        .off('click.jstree')  // remove outro handler, se houver
        .on('click.jstree', '.jstree-anchor', function (e) {
          e.preventDefault();
          e.stopPropagation();
          console.log("Clique simples capturado em .jstree-anchor. Forçando select_node().");
          // $(this).jstree(true).select_node(this);
        });


      }); // document.ready
      console.log("[DEBUG] - Drupal.behaviors.tree.attach - END");
    },
  };

  /**
   * Behavior adicional para corrigir issues de modal (caso uses jQuery UI Dialog).
   */
  Drupal.behaviors.modalFix = {
    attach: function (context, settings) {
      console.log("[DEBUG] - Drupal.behaviors.modalFix.attach - START");
      const $selectNodeButton = $('#select-tree-node');

      function adjustModal() {
        console.log("[DEBUG] - adjustModal() chamado. Ajustando CSS do .ui-dialog.");
        $('.ui-dialog').each(function () {
          $(this).css({
            width: 'calc(100% - 50%)',
            left: '25%',
            right: '25%',
            transform: 'none',
            top: '10%',
          });
        });
      }

      $(document).on('dialogopen', adjustModal);

      $(document).on('select_node.jstree', function () {
        console.log("[DEBUG] - [modalFix] event select_node.jstree disparado. Chamando adjustModal...");
        setTimeout(adjustModal, 100);
      });

      $(document).on('dialog:afterclose', function () {
        console.log("[DEBUG] - [modalFix] event dialog:afterclose. Restaurando CSS no <html>.");
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });
      });

      $selectNodeButton.on('click', function () {
        console.log("[DEBUG] - $selectNodeButton.on(click). Restaurando overflow no <html> e disparando change do field.");
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });

        var fieldId = $(this).data('field-id');
        if (fieldId) {
          console.log("[DEBUG] - fieldId encontrado:", fieldId, "disparando change no input.");
          setTimeout(function () {
            $('#' + fieldId).trigger('change');
          }, 100);
        } else {
          console.log("[DEBUG] - sem fieldId no data('field-id').");
        }
      });

      $(document).on('click', '.ui-dialog-titlebar-close', function () {
        console.log("[DEBUG] - .ui-dialog-titlebar-close clicado. Restaurando <html> CSS.");
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });
      });

      const observer = new MutationObserver(adjustModal);
      $('.ui-dialog-content').each(function () {
        observer.observe(this, { childList: true, subtree: true });
      });
      console.log("[DEBUG] - Drupal.behaviors.modalFix.attach - END");
    },
  };

})(jQuery, Drupal, drupalSettings);
