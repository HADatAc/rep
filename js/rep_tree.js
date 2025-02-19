(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      once('jstree-initialized', '#tree-root', context).forEach((element) => {
        // If a search value exists, fill in the search input.
        if (drupalSettings.rep_tree && drupalSettings.rep_tree.searchValue) {
          $('#tree-search').val(drupalSettings.rep_tree.searchValue);
        }

        function sanitizeForId(str) {
          return str.replace(/[^A-Za-z0-9_-]/g, '_');
        }

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

        // Selectors and state variables.
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
        // Read whether to hide Draft nodes from drupalSettings.
        let hideDraft = drupalSettings.rep_tree.hideDraft || false;
        let hideDeprecated = drupalSettings.rep_tree.hideDeprecated || false;

        // Attach a toggle switch click handler.
        // Make sure an element with id "toggle-draft" exists in your page.
        $('#toggle-draft').on('click.toggleDraft', function (e) {
          e.preventDefault(); // Prevent full form submission
          hideDraft = !hideDraft;

          if (hideDraft) {
            // If hiding drafts, label says "Show Draft"
            $(this)
              .text('Show Draft')
              .removeClass('btn-danger')
              .addClass('btn-success');
          } else {
            // If showing drafts, label says "Hide Draft"
            $(this)
              .text('Hide Draft')
              .removeClass('btn-success')
              .addClass('btn-danger');
          }

          // Rebuild the tree to update the view.
          resetTree();
        });


        $('#toggle-deprecated').on('click.toggleDeprecated', function (e) {
          e.preventDefault(); // Prevent full form submission
          hideDeprecated = !hideDeprecated;

          if (hideDeprecated) {
            // If hiding Deprecated, label says "Show Draft"
            $(this)
              .text('Show Deprecated')
              .removeClass('btn-danger')
              .addClass('btn-success');
          } else {
            // If showing Deprecated, label says "Hide Draft"
            $(this)
              .text('Hide Deprecated')
              .removeClass('btn-success')
              .addClass('btn-danger');
          }

          // Rebuild the tree to update the view.
          resetTree();
        });

        // Activity timeout handler.
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

        // Attach JSTree event listeners.
        // function attachTreeEventListeners() {
        //   $treeRoot.off('select_node.jstree hover_node.jstree load_node.jstree open_node.jstree');
        //   $treeRoot.on('load_node.jstree open_node.jstree', function () { });
        //   $treeRoot.on('select_node.jstree', function (e, data) {
        //     const selectedNode = data.node.original;
        //     if (selectedNode.id) {
        //       $selectNodeButton
        //         .prop('disabled', false)
        //         .removeClass('disabled')
        //         .data('selected-value', selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace)
        //         .data('field-id', $('#tree-root').data('field-id'));
        //     } else {
        //       $selectNodeButton
        //         .prop('disabled', true)
        //         .addClass('disabled')
        //         .removeData('selected-value')
        //         .removeData('field-id');
        //     }
        //     const comment = data.node.data.comment || "";
        //     let html = `
        //       <strong>URI:</strong>
        //       <a href="${drupalSettings.rep_tree.baseUrl}/rep/uri/${base64EncodeUnicode(selectedNode.uri)}"
        //         target="_new">
        //         ${selectedNode.uri}
        //       </a><br />
        //     `;
        //     if (comment.trim().length > 0) {
        //       html += `
        //         <br />
        //         <strong>Description:</strong><br />
        //         ${comment}
        //       `;
        //     }
        //     $('#node-comment-display').html(html).show();
        //   });
        //   $treeRoot.on('hover_node.jstree', function (e, data) {
        //     const comment = data.node.data.comment || '';
        //     // Use $.escapeSelector for safety.
        //     const nodeAnchor = $('#' + $.escapeSelector(data.node.id + '_anchor'));
        //     if (comment) {
        //       nodeAnchor.attr('title', comment);
        //     } else {
        //       nodeAnchor.removeAttr('title');
        //     }
        //   });
        // }
        function attachTreeEventListeners() {
          $treeRoot.off('select_node.jstree hover_node.jstree load_node.jstree open_node.jstree');
          $treeRoot.on('load_node.jstree open_node.jstree', function () { });
          $treeRoot.on('select_node.jstree', function (e, data) {
            const selectedNode = data.node.original;
            // Define the URIs for Draft and Deprecated statuses.
            const DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
            const DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
            // console.log("Selected node:", selectedNode);
            // console.log("Status:", selectedNode.hasStatus);
            // console.log("Owner:", selectedNode.hasSIRManagerEmail);
            // console.log("Autenticated:", drupalSettings.rep_tree.managerEmail);

            // If the node is Draft or Deprecated, keep the button disabled.
            if ((selectedNode.hasStatus === DRAFT_URI || selectedNode.hasStatus === DEPRECATED_URI) && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail) {
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value');
            } else if (selectedNode.hasStatus === DEPRECATED_URI && selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail) {
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value');
            } else if (selectedNode.hasStatus === DRAFT_URI && selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail) {
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data('selected-value', selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace)
                .data('field-id', $('#tree-root').data('field-id'));
            } else {
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data('selected-value', selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace)
                .data('field-id', $('#tree-root').data('field-id'));
            }
            const comment = data.node.data.comment || "";
            let html = `
              <strong>URI:</strong>
              <a href="${drupalSettings.rep_tree.baseUrl}/rep/uri/${base64EncodeUnicode(selectedNode.uri)}"
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
            $('#node-comment-display').html(html).show();
          });
          $treeRoot.on('hover_node.jstree', function (e, data) {
            const comment = data.node.data.comment || '';
            // Use $.escapeSelector for safety.
            const nodeAnchor = $('#' + $.escapeSelector(data.node.id + '_anchor'));
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

        // Initialize JSTree with initial data.
        function initializeJstree() {
          $treeRoot.jstree({
            core: {
              data: function (node, cb) {
                if (node.id === '#') {
                  cb(getFilteredBranches().map(branch => ({
                    id: branch.id,
                    text: branch.label,
                    uri: branch.uri,
                    typeNamespace: branch.typeNamespace || '',
                    data: { typeNamespace: branch.typeNamespace || '' },
                    icon: 'fas fa-folder',
                    hasStatus: branch.hasStatus,
                    hasSIRManagerEmail: branch.hasSIRManagerEmail,
                    children: true,
                  })));
                } else {
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      let tempNodes = [];
                      let seenChildIds = new Set();
                      data.forEach(item => {
                        var normalizedUri = $.trim(item.uri).toLowerCase();
                        if (!seenChildIds.has(normalizedUri)) {
                          seenChildIds.add(normalizedUri);
                          const nodeObj = {
                            id: 'node_' + sanitizeForId(item.uri),
                            text: item.label || 'Unnamed Node',
                            uri: item.uri,
                            typeNamespace: item.typeNamespace || '',
                            comment: item.comment || '',
                            data: { typeNamespace: item.typeNamespace || '', comment: item.comment || '' },
                            icon: 'fas fa-file-alt',
                            hasStatus: item.hasStatus,
                            hasSIRManagerEmail: item.hasSIRManagerEmail,
                            children: true,
                            skip: false
                          };
                          if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Deprecated') {
                            if (hideDeprecated && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                              nodeObj.skip = true;
                            } else {
                              nodeObj.text += ' (Deprecated)';
                              if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                                nodeObj.text += ' (' + drupalSettings.rep_tree.username + ')';
                              } else {
                                nodeObj.text += ' (Another Person)';
                              }
                              nodeObj.a_attr = { style: 'font-style: italic; color:rgba(141, 141, 141, 0.77);' };
                              // nodeObj.state = { disabled: true };
                            }
                          }
                          else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Draft') {
                            if (hideDraft && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                              nodeObj.skip = true;
                            } else {
                              nodeObj.text += ' (Draft)';
                              if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                                nodeObj.text += ' (' + drupalSettings.rep_tree.username + ')';
                              } else {
                                nodeObj.text += ' (Another Person)';
                              }
                              nodeObj.a_attr = { style: 'font-style: italic; color:rgba(153, 0, 0, 0.77);' };
                            }
                          }
                          tempNodes.push(nodeObj);
                        }
                      });
                      cb(tempNodes.filter(n => !n.skip));
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

        /**
         * Build a tree from the given items, but only return the subtree rooted at `forcedRootUri`.
         * If `forcedRootUri` is missing or not found, we fall back to the first node with no parent.
         *
         * @param {Array} items - array of objects like:
         *   { uri, label, comment, superUri, typeNamespace }
         * @param {string|null} forcedRootUri - the URI to treat as the root of the subtree
         * @returns {Object|null} - the root node (with children) for JSTree. If not found, returns null.
         */
        function buildHierarchy(items, forcedRootUri = null) {
          // 1) Remove duplicates by URI.
          const uniqueItems = [];
          const seenUris = new Set();
          items.forEach(item => {
            if (!seenUris.has(item.uri)) {
              uniqueItems.push(item);
              seenUris.add(item.uri);
            }
          });

          // 2) Build a node map (URI -> node object).
          //    We apply the Draft/Deprecated checks while constructing each node.
          const nodeMap = new Map();
          uniqueItems.forEach(item => {
            let nodeText = item.label || 'Unnamed Node';
            let a_attr = {}; // default: no special style
            // Optionally, add a "skip" property to the item if needed.
            item.skip = false;

            if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Deprecated') {
              // Check if we should hide deprecated nodes (for non-owners).
              if (hideDeprecated && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                // In your case, you want to show these nodes but with a specific style.
                // So do not mark as skip; instead, append the text.
                nodeText += ' (Deprecated)';
                if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                  nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                } else {
                  nodeText += ' (Another Person)';
                }
                a_attr = { style: 'font-style: italic; color:rgba(141, 141, 141, 0.77);' };
              } else {
                nodeText += ' (Deprecated)';
                if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                  nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                } else {
                  nodeText += ' (Another Person)';
                }
                a_attr = { style: 'font-style: italic; color:rgba(141, 141, 141, 0.77);' };
              }
            } else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Draft') {
              // Check if we should hide draft nodes for non-owners.
              if (hideDraft && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                item.skip = true;
              } else {
                nodeText += ' (Draft)';
                if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                  nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                } else {
                  nodeText += ' (Another Person)';
                }
                a_attr = { style: 'font-style: italic; color:rgba(153, 0, 0, 0.77);' };
              }
            }

            // Create the node and store it in the nodeMap.
            nodeMap.set(item.uri, {
              id: item.uri,
              text: nodeText,
              uri: item.uri,
              superUri: item.superUri || null, // Keep parent pointer.
              typeNamespace: item.typeNamespace || '',
              icon: 'fas fa-file-alt',
              hasStatus: item.hasStatus,
              hasSIRManagerEmail: item.hasSIRManagerEmail,
              data: {
                comment: item.comment || '',
                typeNamespace: item.typeNamespace || ''
              },
              a_attr: a_attr,
              children: []
            });
          });

          // 3) Link each node to its parent's children array, ignoring skipped nodes.
          let root = null;
          uniqueItems.forEach(item => {
            // If the item is marked to skip, do not link it.
            if (item.skip) {
              return;
            }
            const node = nodeMap.get(item.uri);
            if (item.superUri && !item.skip) {
              const parent = nodeMap.get(item.superUri);
              if (parent && !parent.skip) {
                parent.children.push(node);
              }
            } else {
              // If there is no superUri, this node becomes the root.
              root = node;
            }
          });

          // 4) If forcedRootUri is provided, then force that node to be the root.
          if (forcedRootUri && nodeMap.has(forcedRootUri)) {
            root = nodeMap.get(forcedRootUri);
          } else if (!root) {
            // Fallback: if no root found, try to find any node without a parent.
            for (const item of uniqueItems) {
              if (!item.superUri) {
                root = nodeMap.get(item.uri);
                break;
              }
            }
          }

          return root;
        }

        // populate Tree function
        function populateTree(uri) {
          //console.log('Loading tree data for URI:', uri);
          $.ajax({
            url: drupalSettings.rep_tree.searchSuperClassEndPoint,
            type: 'GET',
            data: { uri: encodeURI(uri) },
            dataType: 'json',
            success: function (data) {
              //console.log('Tree data loaded:', data);

              // Suppose we want to treat drupalSettings.rep_tree.superclass as the forced root
              const forcedRootUri = drupalSettings.rep_tree.superclass;

              // Build the subtree
              const rootNode = buildHierarchy(data, forcedRootUri);

              // JSTree expects an array for top-level data
              const treeData = rootNode ? [rootNode] : [];

              // Refresh JSTree
              $treeRoot.jstree(true).settings.core.data = treeData;
              $treeRoot.jstree(true).refresh();

              // Optional: auto-open nodes
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

        // Reset tree function.
        function resetTree() {
          //console.log(hideDraft);
          //console.log('Resetting the tree to its initial state...');
          $searchInput.val('');
          $clearButton.hide();
          $treeRoot.jstree('destroy').empty();
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
                    hasStatus: branch.hasStatus,
                    hasSIRManagerEmail: branch.hasSIRManagerEmail,
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
                      let tempNodes = [];
                      let seenChildIds = new Set();
                      data.forEach(item => {
                        if (!seenChildIds.has(item.uri)) {
                          seenChildIds.add(item.uri);
                          const nodeObj = {
                            id: 'node_' + sanitizeForId(item.uri),
                            text: item.label || 'Unnamed Node',
                            uri: item.uri,
                            typeNamespace: item.typeNamespace || '',
                            comment: item.comment || '',
                            data: { typeNamespace: item.typeNamespace || '', comment: item.comment || '' },
                            icon: 'fas fa-file-alt',
                            hasStatus: item.hasStatus,
                            hasSIRManagerEmail: item.hasSIRManagerEmail,
                            children: true,
                            skip: false
                          };
                          if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Deprecated') {
                            if (hideDeprecated && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                              nodeObj.skip = true;
                            } else {
                              nodeObj.text += ' (Deprecated)';
                              if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                                nodeObj.text += ' (' + drupalSettings.rep_tree.username + ')';
                              } else {
                                nodeObj.text += ' (Another Person)';
                              }
                              nodeObj.a_attr = { style: 'font-style: italic; color:rgba(141, 141, 141, 0.77);' };
                              // nodeObj.state = { disabled: true };
                            }
                          }
                          else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Draft') {
                            if (hideDraft && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                              nodeObj.skip = true;
                            } else {
                              nodeObj.text += ' (Draft)';
                              if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                                nodeObj.text += ' (' + drupalSettings.rep_tree.username + ')';
                              } else {
                                nodeObj.text += ' (Another Person)';
                              }
                              nodeObj.a_attr = { style: 'font-style: italic; color:rgba(153, 0, 0, 0.77);' };
                            }
                          }
                          tempNodes.push(nodeObj);
                        }
                      });
                      cb(tempNodes.filter(n => !n.skip));
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
          $treeRoot.on('ready.jstree', function () {
            attachTreeEventListeners();
          });
          //console.log('Tree reset complete. Only the root node is loaded.');
        }

        $('#reset-tree').on('click', function () {
          //console.log('reset');
          resetTree();
        });

        // Autocomplete configuration.
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

        // Initially, hide the tree and show the wait message.
        $treeRoot.hide();
        $waitMessage.show();
        $searchInput.prop('disabled', true);

        if ($treeRoot.length) {
          initializeJstree();
          // Initialize autocomplete.
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
