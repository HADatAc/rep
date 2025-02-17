(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      once('jstree-initialized', '#tree-root', context).forEach((element) => {
        // Preenche o campo de busca se existir valor definido
        if (drupalSettings.rep_tree && drupalSettings.rep_tree.searchValue) {
          $('#tree-search').val(drupalSettings.rep_tree.searchValue);
        }

        // Função para filtrar duplicatas dos nós raiz (compara o label)
        function getFilteredBranches() {
          const seenLabels = new Set();
          return drupalSettings.rep_tree.branches.filter(branch => {
            if (seenLabels.has(branch.label)) {
              console.warn("Duplicate branch removed:", branch);
              return false;
            }
            seenLabels.add(branch.label);
            return true;
          });
        }

        // Seletores e variáveis de estado
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

        // Gerencia o tempo de atividade e exibição da árvore
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

        // Anexa os eventos de seleção, tooltip e carregamento ao jstree
        function attachTreeEventListeners() {
          // Remove eventos anteriores para evitar duplicação
          $treeRoot.off('select_node.jstree hover_node.jstree load_node.jstree open_node.jstree');

          $treeRoot.on('load_node.jstree open_node.jstree', function () {
            //console.log('Node loaded or opened.');
          });

          $treeRoot.on('select_node.jstree', function (e, data) {
            const selectedNode = data.node.original;
            // Atualiza o botão de seleção
            if (selectedNode.id) {
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data('selected-value', selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace)
                //data('field-id', $treeRoot.data('field-id'));
                .data('field-id', $('#tree-root').data('field-id')); // Mantém o campo correto
            } else {
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value')
                .removeData('field-id');
            }
            const comment = data.node.data.comment || "";
            let html = `
              <strong>URI:</strong>
              <a href="${drupalSettings.rep_tree.baseUrl}/rep/uri/${base64EncodeUnicode(selectedNode.typeNamespace)}"
                target="_new">
                ${selectedNode.uri}
              </a><br />
            `;
            if (comment.trim().length > 0) {
              html += `
                <br />
                <strong>Description:</strong><br />
                ${comment}
              `;
            }
            $('#node-comment-display')
              .html(html)
              .show();
          });

          $treeRoot.on('hover_node.jstree', function (e, data) {
            const comment = data.node.data.comment || '';
            const nodeAnchor = $('#' + data.node.id + '_anchor');
            if (comment) {
              nodeAnchor.attr('title', comment);
            } else {
              nodeAnchor.removeAttr('title');
            }
          });
        }

        function base64EncodeUnicode(str) {
          const utf8Bytes = new TextEncoder().encode(str);

          let asciiStr = '';
          for (let i = 0; i < utf8Bytes.length; i++) {
            asciiStr += String.fromCharCode(utf8Bytes[i]);
          }

          return btoa(asciiStr);
        }

        // Inicializa o jstree com os dados iniciais
        function initializeJstree() {
          $treeRoot.jstree({
            core: {
              data: function (node, cb) {
                if (node.id === '#') {
                  // Utiliza os branches filtrados para evitar duplicatas (com base no label)
                  cb(getFilteredBranches().map(branch => ({
                    id: branch.id,
                    text: branch.label,
                    uri: branch.uri,
                    typeNamespace: branch.typeNamespace || '',
                    data: { typeNamespace: branch.typeNamespace || '' },
                    icon: 'fas fa-folder',
                    children: true,
                  })));
                } else {
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
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
                            comment: item.comment || '',
                            data: { typeNamespace: item.typeNamespace || '', comment: item.comment || '' },
                            icon: 'fas fa-file-alt',
                            children: true,
                          });
                        }
                        //console.log(item);
                        if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Deprecated') {
                          node.li_attr = { style: 'font-style: italic;' };
                          node.state = { disabled: true };
                        }else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Draft') {
                          node.li_attr = { style: 'font-style: italic; color:#ff0000' };
                          node.state = { disabled: true };
                        }
                      });
                      cb(uniqueChildren);
                    },
                    error: function () {
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

          // Após a inicialização, anexa os eventos e configura o timeout de atividade
          $treeRoot.on('ready.jstree', function () {
            attachTreeEventListeners();
            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);
            if (drupalSettings.rep_tree.elementType !== 'detectorattribute') {
              // Opcional: $treeRoot.jstree('open_all');
            }
            resetActivityTimeout();
          });
        }

        // Constrói a hierarquia dos nós a partir dos dados da API
        function buildHierarchy(items) {
          const nodeMap = new Map();
          let root = null;
          items.forEach(item => {
            // Cria cada nó com as propriedades necessárias para o jstree
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
              children: []  // Inicialmente vazio; serão adicionados se houver filhos
            };
            nodeMap.set(item.uri, node);
          });
          // Conecta cada nó ao seu pai (se houver)
          items.forEach(item => {
            const node = nodeMap.get(item.uri);
            if (item.superUri) {
              const parent = nodeMap.get(item.superUri);
              if (parent) {
                parent.children.push(node);
              }
            } else {
              // Se não há superUri, esse é o nó raiz
              root = node;
            }
          });
          return root;
        }

        // Carrega a árvore com base em uma URI específica (para o autocomplete)
        function populateTree(uri) {
          console.log('Loading tree data for URI:', uri);
          $.ajax({
            url: drupalSettings.rep_tree.searchSuperClassEndPoint,
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
              console.error('Error loading tree data for URI:', uri);
            },
          });
        }

        // Reinicializa a árvore (reset), aplicando novamente a filtragem e reanexando os eventos
        function resetTree() {
          console.log('Resetting the tree to its initial state...');
          $searchInput.val('');
          $clearButton.hide();
          // Destroi a árvore atual e limpa o HTML residual
          $treeRoot.jstree('destroy').empty();
          // Recria a árvore com os dados iniciais, utilizando os branches filtrados
          $treeRoot.jstree({
            core: {
              data: function (node, cb) {
                if (node.id === '#') {
                  cb(getFilteredBranches().map(branch => ({
                    id: branch.id,
                    text: branch.label,
                    uri: branch.uri,
                    typeNamespace: branch.typeNamespace || '',
                    comment: branch.comment || '',
                    data: { typeNamespace: branch.typeNamespace || '', comment: branch.comment || '' },
                    icon: 'fas fa-folder',
                    children: true,
                    state: { opened: false },
                  })));
                } else {
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
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
                            comment: item.comment || '',
                            data: { typeNamespace: item.typeNamespace || '', comment: item.comment || '' },
                            icon: 'fas fa-file-alt',
                            children: true,
                          });
                        }
                        //console.log(item);
                        if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Deprecated') {
                          node.li_attr = { style: 'font-style: italic; color:#ff0000' };
                          node.state = { disabled: true };
                        }else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Draft') {
                          node.li_attr = { style: 'color:#ff0000' };
                          node.state = { disabled: true };
                        }
                      });
                      cb(uniqueChildren);
                    },
                    error: function () {
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
            },
          });
          // Reanexa os eventos assim que a árvore estiver pronta
          $treeRoot.on('ready.jstree', function () {
            attachTreeEventListeners();
          });
          console.log('Tree reset complete. Only the root node is loaded.');
        }

        // Eventos do campo de pesquisa e do botão de reset
        $searchInput.on('input', function () {
          const searchTerm = $searchInput.val();
          if (searchTerm.length > 0) {
            $clearButton.show();
          } else {
            $clearButton.hide();
          }
        });

        $searchInput.on('keyup', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            return;
          }
          clearTimeout(searchTimeout);
          searchTimeout = setTimeout(() => {
            // Aqui pode ser chamada uma função de pesquisa se necessário
            // performSearch($searchInput.val().trim());
          }, 500);
        });

        $clearButton.on('click', function () {
          console.log('Resetting tree after search clear.');
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
          console.log('reset');
          resetTree();
        });

        // Configuração do autocomplete
        function setupAutocomplete(inputField) {
          $(inputField).on('input', function () {
            const searchTerm = $(this).val();
            if (searchTerm.length < 3) {
              $('#autocomplete-suggestions').hide();
              return;
            }
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
                  const suggestionItem = $('<div class="suggestion-item"></div>')
                    .text(suggestion.label)
                    .css({ padding: '5px', cursor: 'pointer' });
                  suggestionItem.on('click', function () {
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
                console.error('Error fetching suggestions.');
              },
            });
          });
          $(inputField).on('blur', function () {
            setTimeout(() => $('#autocomplete-suggestions').hide(), 200);
          });
        }

        // Inicialmente, oculta a árvore e exibe a mensagem de espera
        $treeRoot.hide();
        $waitMessage.show();
        $searchInput.prop('disabled', true);

        if ($treeRoot.length) {
          initializeJstree();
          // Inicializa o autocomplete
          $(document).ready(function () {
            setupAutocomplete('#search_input');
          });
        } else {
          console.warn('Tree root not found. Initialization aborted.');
        }
      });
    },
  };
})(jQuery, Drupal, drupalSettings);

(function ($, Drupal) {
  Drupal.behaviors.modalFix = {
    attach: function (context, settings) {
      const $selectNodeButton = $('#select-tree-node');

      function adjustModal() {
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
        setTimeout(adjustModal, 100);
      });

      $(document).on('dialog:afterclose', function () {
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });
      });

      $selectNodeButton.on('click', function () {
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });

        // Recupera o ID do campo de texto onde o valor foi escrito.
        var fieldId = $(this).data('field-id');
        //console.log(fieldId);
        if (fieldId) {
          // Um pequeno delay pode ajudar a garantir que o valor já esteja escrito.
          setTimeout(function () {
            //console.log($('#' + fieldId));
            // Dispara o evento blur apenas para o input desejado.
            $('#' + fieldId).trigger('change');
          }, 100);
        }
      });

      $(document).on('click', '.ui-dialog-titlebar-close', function () {
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
    },
  };
})(jQuery, Drupal, drupalSettings);
