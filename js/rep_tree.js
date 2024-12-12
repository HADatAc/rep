(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      const $treeRoot = $('#tree-root', context);
      const $searchInput = $('#tree-search', context);
      const $clearButton = $('#clear-search', context);
      const apiEndpoint = drupalSettings.rep_tree.apiEndpoint;
      const branches = drupalSettings.rep_tree.branches;

      let searchTimeout;

      if ($treeRoot.length) {
        const treeInstance = $treeRoot.jstree({
          core: {
            data: function (node, cb) {
              if (node.id === '#') {
                cb(branches.map(branch => ({
                  id: branch.id,
                  text: branch.label,
                  uri: branch.uri,
                  icon: 'fas fa-folder',
                  children: true
                })));
              } else {
                $.ajax({
                  url: apiEndpoint,
                  type: 'GET',
                  data: { nodeUri: node.original.uri },
                  dataType: 'json',
                  success: function (data) {
                    cb(data.map(item => ({
                      id: item.nodeId,
                      text: item.label || 'Unnamed Node',
                      uri: item.uri,
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
            },
            themes: {
              responsive: true,
              dots: false,
              icons: true
            }
          },
          plugins: ['search', 'wholerow']
        });

        // Prevenir múltiplos eventos
        $treeRoot.off('select_node.jstree').on('select_node.jstree', function (e, data) {
          const node = data.node;
          const nodeText = truncateText(node.text, 50); // Limitar o texto a 50 caracteres
          const nodeUri = node.original.uri || 'N/A';

          const outputText = `${nodeText} [${nodeUri}]`;
          if (outputText.length > 125) {
            console.warn('Output length exceeds 125 characters, truncating...');
          }

          // Atualizar o campo global
          updateGlobalField(outputText);

          // Debug para validação
          // console.log('Selected node:', {
          //   text: nodeText,
          //   uri: nodeUri,
          //   output: outputText
          // });
        });

        $treeRoot.on('hover_node.jstree', function (e, data) {
          const node = data.node;
          const $nodeAnchor = $(`#${node.id}_anchor`);

          // Verifica se o tooltip já existe, para evitar duplicações
          if (!$nodeAnchor.attr('title')) {
            const fullText = node.text; // Obtém o texto completo do nó
            $nodeAnchor.attr('title', fullText); // Define o atributo title para exibir o tooltip
          }
        });

        // Função para preencher o campo globalmente
        function updateGlobalField(value) {
          const outputFieldSelector = '#edit-search-keyword--2';
          const $outputField = $(outputFieldSelector);

          if ($outputField.length) {
            $outputField.val(value);
          } else {
            console.warn(`Output field (${outputFieldSelector}) not found. Retrying...`);
            setTimeout(() => updateGlobalField(value), 500);
          }
        }

        // Função para truncar texto
        function truncateText(text, maxLength) {
          if (text.length > maxLength) {
            return text.slice(0, maxLength - 3) + '...';
          }
          return text;
        }

        // Botões de controle
        $('#expand-all', context).on('click', function () {
          $treeRoot.jstree('open_all');
        });

        $('#collapse-all', context).on('click', function () {
          $treeRoot.jstree('close_all');
        });

        $('#select-all', context).on('click', function () {
          $treeRoot.jstree('check_all');
        });

        $('#unselect-all', context).on('click', function () {
          $treeRoot.jstree('uncheck_all');
        });

        $clearButton.on('click', function () {
          $searchInput.val('');
          $clearButton.hide();
          clearHighlight();
          updateGlobalField();
          $treeRoot.jstree('clear_search');
          $treeRoot.jstree('close_all');
        });

        $searchInput.on('input', function () {
          if ($(this).val().length > 0) {
            $clearButton.show();
          } else {
            $clearButton.hide();
          }
        });

        $searchInput.attr('autocomplete', 'off');
        $searchInput.on('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
          }
        });

        $searchInput.on('keyup', function () {
          clearTimeout(searchTimeout);
          const searchTerm = $(this).val().toLowerCase();

          searchTimeout = setTimeout(() => {
            if (searchTerm.length > 0) {
              clearHighlight();
              performSearchAndCollapse(searchTerm);
            } else {
              clearHighlight();
              $treeRoot.jstree('clear_search');
              $treeRoot.jstree('close_all');
            }
          }, 300);
        });

        function performSearchAndCollapse(searchTerm) {
          // console.log('Iniciando pesquisa:', searchTerm);

          function searchNodeRecursively(nodeId, cb) {
            $treeRoot.jstree('open_node', nodeId, function () {
              const children = $treeRoot.jstree('get_node', nodeId).children;
              let hasMatchingChild = false;

              if (children && children.length) {
                let pendingCallbacks = children.length;

                children.forEach(function (childId) {
                  const text = $treeRoot.jstree('get_text', childId).toLowerCase();

                  searchNodeRecursively(childId, function (childHasMatch) {
                    if (text.includes(searchTerm) || childHasMatch) {
                      styleNode(childId);
                      hasMatchingChild = true;
                    } else {
                      $treeRoot.jstree('close_node', childId);
                    }
                    pendingCallbacks--;
                    if (pendingCallbacks === 0) {
                      cb(hasMatchingChild);
                    }
                  });
                });
              } else {
                const text = $treeRoot.jstree('get_text', nodeId).toLowerCase();
                const isMatch = text.includes(searchTerm);
                if (isMatch) {
                  styleNode(nodeId);
                }
                cb(isMatch);
              }
            });
          }

          let pendingBranches = branches.length;
          branches.forEach(branch => {
            searchNodeRecursively(branch.id, function (hasMatch) {
              if (!hasMatch) {
                $treeRoot.jstree('close_node', branch.id);
              }
              pendingBranches--;
              if (pendingBranches === 0) {
                // console.log('Pesquisa concluída.');
              }
            });
          });
        }

        function styleNode(nodeId) {
          const $nodeAnchor = $(`#${nodeId}_anchor`);
          $nodeAnchor.css({
            'color': 'blue',
            'font-style': 'italic',
            'background-color': 'lightgreen'
          });
        }

        function clearHighlight() {
          // console.log('Limpando realces anteriores.');
          $treeRoot.find('.jstree-anchor').css({
            'color': '',
            'font-style': '',
            'background-color': ''
          });
        }
      }
    }
  };
})(jQuery, Drupal, drupalSettings);
