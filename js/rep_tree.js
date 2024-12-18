(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      console.log('Tree behavior attached.');
      const $treeRoot = $('#tree-root', context);
      const $selectNodeButton = $('#select-tree-node', context);
      const $searchInput = $('#tree-search', context);
      const $clearButton = $('#clear-search', context);
      const $modalField = $('[data-initial-uri]'); // Field with initial value
      const $waitMessage = $('#wait-message', context); // Wait message element

      let searchTimeout;
      let ajaxTimeout;

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
                      icon: 'fas fa-file-alt',
                      children: true
                    })));
                    resetAjaxTimeout();
                  },
                  error: function () {
                    console.error('Error fetching children for node:', node.original.uri);
                    cb([]);
                    resetAjaxTimeout();
                  }
                });
              }
            }
          },
          plugins: ['search', 'wholerow']
        });

        // Dispara o evento Expand All ao inicializar a árvore
        $treeRoot.on('ready.jstree', function () {
          console.log('JSTree ready. Expanding all nodes...');
          $treeRoot.jstree('open_all');
        });

        // Função para monitorar requisições AJAX
        function resetAjaxTimeout() {
          clearTimeout(ajaxTimeout);
          ajaxTimeout = setTimeout(function () {
            console.log('No AJAX requests for 5 seconds. Collapsing all nodes and showing the tree.');
            $treeRoot.jstree('close_all');
            $waitMessage.hide();
            $treeRoot.show();
          }, 5000);
        }

        // Highlight and Expand Specific Node
        function highlightNode(initialUri) {
          console.log('Attempting to highlight node with URI:', initialUri);
          if (!initialUri) {
            console.warn('No initial URI provided.');
            return;
          }

          $treeRoot.jstree('close_all'); // Collapse all nodes
          console.log('Tree collapsed. Searching for the node...');

          const nodes = $treeRoot.jstree(true).get_json('#', { flat: true });
          console.log('Retrieved flat node list:', nodes);

          let targetNode = null;

          nodes.forEach(node => {
            console.log('Checking node:', node);
            if (node.original && node.original.uri === initialUri) {
              console.log('Node matched:', node);
              targetNode = node;
            }
          });

          if (targetNode) {
            console.log('Expanding path to the node:', targetNode.id);
            const parents = $treeRoot.jstree('get_path', targetNode.id, '/', true);
            parents.forEach(parentId => {
              console.log('Opening parent node:', parentId);
              $treeRoot.jstree('open_node', parentId);
            });

            console.log('Selecting the node:', targetNode.id);
            $treeRoot.jstree('select_node', targetNode.id);
          } else {
            console.warn('Node with URI not found:', initialUri);
          }
        }

        // Event: Modal Opens
        $modalField.on('click', function () {
          const initialValue = $(this).val();
          console.log('Modal opened. Initial value to search:', initialValue);

          setTimeout(() => {
            highlightNode(initialValue);
          }, 500);
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

        // Expand and Collapse All
        $('#expand-all', context).on('click', function () {
          console.log('Expand All clicked.');
          $treeRoot.jstree('open_all');
        });

        $('#collapse-all', context).on('click', function () {
          console.log('Collapse All clicked.');
          $treeRoot.jstree('close_all');
        });

        // Search Logic
        $searchInput.on('input', function () {
          console.log('Search input changed:', $(this).val());
          if ($(this).val().length > 0) {
            $clearButton.show();
          } else {
            $clearButton.hide();
          }
        });

        $searchInput.on('keyup', function () {
          clearTimeout(searchTimeout);
          const searchTerm = $(this).val().toLowerCase();
          console.log('Search triggered. Term:', searchTerm);

          searchTimeout = setTimeout(() => {
            if (searchTerm.length > 0) {
              console.log('Searching in tree for term:', searchTerm);
              $treeRoot.jstree('search', searchTerm);
            } else {
              console.log('Clearing search and collapsing tree.');
              $treeRoot.jstree('clear_search');
              $treeRoot.jstree('close_all');
            }
          }, 300);
        });

        $clearButton.on('click', function () {
          console.log('Clear search clicked.');
          $searchInput.val('');
          $clearButton.hide();
          $treeRoot.jstree('clear_search');
          $treeRoot.jstree('close_all');
        });
      } else {
        console.warn('Tree root not found. Initialization aborted.');
      }
    }
  };
})(jQuery, Drupal, drupalSettings);
