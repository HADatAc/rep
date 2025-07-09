(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      once('jstree-initialized', '#tree-root', context).forEach((element) => {

        // console.log(drupalSettings.rep_tree);

        // If a search value exists, fill in the search input.
        if (drupalSettings.rep_tree && drupalSettings.rep_tree.searchValue) {
          $('#tree-search').val(drupalSettings.rep_tree.searchValue);
        }

        function namespaceUri(uri) {
          // Assuming drupalSettings.rep_tree.namespaces is an object, e.g.:
          // { "ABC": "http://abc.org/", "XYZ": "http://xyz.org/" }
          const namespaces = drupalSettings.rep_tree.nameSpacesList;
          for (const abbrev in namespaces) {
            if (namespaces.hasOwnProperty(abbrev)) {
              const ns = namespaces[abbrev];
              // Check that both the abbreviation and the namespace URI exist.
              if (abbrev && ns && uri.startsWith(ns)) {
                const replacement = abbrev + ":";
                return uri.replace(ns, replacement);
              }
            }
          }
          return uri;
        }

        function namespacePrefixUri(uri) {
          // Assuming drupalSettings.rep_tree.namespaces is an object, e.g.:
          // { "ABC": "http://abc.org/", "XYZ": "http://xyz.org/" }
          const namespaces = drupalSettings.rep_tree.nameSpacesList;
          for (const abbrev in namespaces) {
            if (namespaces.hasOwnProperty(abbrev)) {
              const ns = namespaces[abbrev];
              // Check that both the abbreviation and the namespace URI exist.
              if (abbrev && ns && uri.startsWith(ns)) {
                const replacement = abbrev + ":";
                return replacement;
                // return uri.replace(ns, replacement);
              }
            }
          }
          return uri;
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

        let activityTimeout = null;
        const activityDelay = 1000;
        let initialSearchDone = false;
        // Read whether to hide Draft nodes from drupalSettings.
        let hideDraft = drupalSettings.rep_tree.hideDraft || false;
        let hideDeprecated = drupalSettings.rep_tree.hideDeprecated || false;

        let showLabel = drupalSettings.rep_tree.showLabel || 'label';

        $('#toggle-draft').on('change', function () {
          // If checkbox is checked, hideDraft = true; else false
          hideDraft = $(this).is(':checked');
          // console.log("Hide Draft toggled to:", hideDraft);
          // Rebuild the tree with the new hideDraft value
          resetTree();
        });

        $('#toggle-deprecated').on('change', function () {
          hideDeprecated = $(this).is(':checked');
          // console.log("Hide Deprecated toggled to:", hideDeprecated);
          // Rebuild the tree with the new hideDraft value
          resetTree();
        });

        $(document).on('change', 'input[name="label_mode"]', function () {
          const selectedValue = $(this).val();
          //console.log("Radio changed, value:", selectedValue);

          const treeInstance = $('#tree-root').jstree(true);
          if (!treeInstance) {
            return;
          }

          // Iterate over all nodes in the jsTree internal model.
          for (let nodeId in treeInstance._model.data) {
            if (nodeId === '#') continue;
            const node = treeInstance._model.data[nodeId];
            if (node && node.data) {
              // console.log(node);
              let nodeText;
              switch (selectedValue) {
                case 'labelprefix':
                  nodeText = node.data.originalPrefixLabel;
                  break;
                case 'uri':
                  nodeText = node.data.originalUri;
                  break;
                case 'uriprefix':
                  nodeText = node.data.originalPrefixUri;
                  break;
                default: // 'label'
                  nodeText = node.data.originalLabel;
                  break;
              }
              if (node.text !== nodeText) {
                treeInstance.rename_node(nodeId, nodeText);
              }
            }
          }
        });

        function setNodeText(item) {

          const selectedValue = $('input[name="label_mode"]:checked').val();

          // console.log(selectedValue);

          let nodeText;

          switch (selectedValue) {
            case 'labelprefix':
              nodeText = namespacePrefixUri(item.uri) + item.label;
              break;
            case 'uri':
              nodeText = item.uri;
              break;
            case 'uriprefix':
              nodeText = namespaceUri(item.uri);
              break;
            default: // 'label'
              nodeText = item.label ?? item.uri; // Case label empty present uri by default
              break;
          }
          return nodeText;
        }

        function setTitleSufix(item) {
          let sufix = '';
          if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Deprecated') {
            sufix += ' (Deprecated)';
            if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
              sufix += ' (' + drupalSettings.rep_tree.username + ')';
            } else {
              sufix += ' (Another Person)';
            }
          }

          if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Draft') {
            sufix += ' (Draft)';
            if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
              sufix += ' (' + drupalSettings.rep_tree.username + ')';
            } else {
              sufix += ' (Another Person)';
            }
          }

          if (item.hasStatus === 'http://hadatac.org/ont/vstoi#UnderReview') {
            sufix += ' (Under Review)';
            if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
              sufix += ' (' + drupalSettings.rep_tree.username + ')';
            } else {
              sufix += ' (Another Person)';
            }
          }

          return sufix;
        }

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

        function attachTreeEventListeners() {
          $treeRoot.off('select_node.jstree hover_node.jstree load_node.jstree open_node.jstree');
          $treeRoot.on('load_node.jstree open_node.jstree', function () { });
          $treeRoot.on('select_node.jstree', function (e, data) {
            const selectedNode = data.node.original;
            // Define the URIs for Draft and Deprecated statuses.
            const DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
            const DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
            const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

            // If the node is Draft or Deprecated, keep the button disabled.
            if ((selectedNode.hasStatus === DRAFT_URI || selectedNode.hasStatus === DEPRECATED_URI || selectedNode.hasStatus === UNDERREVIEW_URI) && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail) {
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
            } else if (selectedNode.hasStatus === UNDERREVIEW_URI && selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail) {
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value');
            } else {
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data('selected-value', selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace)
                .data('field-id', $('#tree-root').data('field-id'));
            }

            // console.log(selectedNode);
            let html = `
              <strong>Label:</strong>
              ${selectedNode.label}
              <br />
              <strong>URI:</strong>
              <a href="${drupalSettings.rep_tree.baseUrl}/rep/uri/${base64EncodeUnicode(selectedNode.uri)}"
                target="_new">
                ${selectedNode.uri}
              </a><br />
            `;

            const webdocument = data.node.data.hasWebDocument || "";
            if (webdocument.trim().length > 0) {
              if (webdocument.trim().toLowerCase().startsWith("http")) {
                html += `
                  <strong>Web Document:</strong>
                  <a href="${webdocument}" target="_new">
                    ${webdocument}
                  </a><br />
                `;
              } else {
                const uriPart = selectedNode.uri.includes('#/') ? selectedNode.uri.split('#/')[1] : selectedNode.uri;
                // const downloadUrl = `${drupalSettings.rep_tree.baseUrl}/rep/webdocdownload/${encodeURIComponent(uriPart)}?doc=${encodeURIComponent(webdocument)}`;
                const downloadUrl = `${drupalSettings.rep_tree.baseUrl}/rep/webdocdownload/${encodeURIComponent(uriPart)}?doc=${encodeURIComponent(webdocument)}`;
                html += `
                  <strong>Web Document:</strong>
                  <a href="#" class="view-media-button" data-view-url="${downloadUrl}">
                    ${webdocument}
                  </a><br />
                `;
              }
            }

            const comment = data.node.data.comment || "";
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
              check_callback: true,
              data: function (node, cb) {
                if (node.id === '#') {
                  cb(getFilteredBranches().map(branch => ({
                    id: branch.id,
                    // text: (showNameSpace ? branch.label : branch.typeNamespace),
                    text: setNodeText(branch),
                    label: branch.label,
                    uri: branch.uri,
                    typeNamespace: branch.typeNamespace || '',
                    data: {
                      originalLabel: branch.label + setTitleSufix(branch),
                      originalPrefixLabel: namespacePrefixUri(branch.uri) + branch.label + setTitleSufix(branch),
                      originalUri: branch.uri + setTitleSufix(branch),
                      originalPrefixUri: namespaceUri(branch.uri) + setTitleSufix(branch),
                      typeNamespace: branch.typeNamespace || '',
                      comment: branch.comment || '',
                      hasWebDocument: branch.hasWebDocument,
                      hasImageUri: branch.hasImageUri,
                    },
                    icon: 'fas fa-folder',
                    hasStatus: branch.hasStatus,
                    hasSIRManagerEmail: branch.hasSIRManagerEmail,
                    hasWebDocument: branch.hasWebDocument,
                    hasImageUri: branch.hasImageUri,
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
                            text: setNodeText(item),
                            label: item.label,
                            uri: item.uri,
                            typeNamespace: item.typeNamespace || '',
                            comment: item.comment || '',
                            data: {
                              originalLabel: item.label + setTitleSufix(item),
                              originalPrefixLabel: namespacePrefixUri(item.uri) + item.label + setTitleSufix(item),
                              originalUri: item.uri + setTitleSufix(item),
                              originalPrefixUri: namespaceUri(item.uri) + setTitleSufix(item),
                              typeNamespace: item.typeNamespace || '',
                              comment: item.comment || '',
                              hasWebDocument: item.hasWebDocument,
                              hasImageUri: item.hasImageUri, },
                            icon: 'fas fa-file-alt',
                            hasStatus: item.hasStatus,
                            hasSIRManagerEmail: item.hasSIRManagerEmail,
                            hasWebDocument: item.hasWebDocument,
                            hasImageUri: item.hasImageUri,
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
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgba(141, 141, 141, 0.77);' };
                              } else {
                                nodeObj.text += ' (Another Person)';
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                              }
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
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgba(153, 0, 0, 0.77);' };
                              } else {
                                nodeObj.text += ' (Another Person)';
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                              }

                            }
                          } else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#UnderReview') {
                            if (hideDraft && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                              nodeObj.skip = true;
                            } else {
                              nodeObj.text += ' (Under Review)';
                              if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                                nodeObj.text += ' (' + drupalSettings.rep_tree.username + ')';
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgb(172, 164, 164);' };
                              } else {
                                nodeObj.text += ' (Another Person)';
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgba(206, 103, 19, 0.77);' };
                              }

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

          $treeRoot.on('ready.jstree', function () {
            attachTreeEventListeners();
            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);
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

        // populate Tree function

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

          // 1.1) If forcedRootUri is provided, limit the chain so that we only use items
          // from the result (first element) up to the forced top node.
          let filteredItems = uniqueItems;
          if (forcedRootUri) {
            const forcedIndex = uniqueItems.findIndex(item => item.uri === forcedRootUri);
            if (forcedIndex !== -1) {
              filteredItems = uniqueItems.slice(0, forcedIndex + 1);
            }
          }

          // 2) Build a node map (URI -> node object).
          //    We apply the Draft/Deprecated checks while constructing each node.
          const nodeMap = new Map();
          filteredItems.forEach(item => {
            let nodeText;
            if (showLabel) {
              switch (showLabel) {
                case 'labelprefix':
                  nodeText = namespacePrefixUri(item.uri) + item.label;
                  break;
                case 'uri':
                  nodeText = namespacePrefixUri(item.uri) + item.label;
                  break;
                case 'uriprefix':
                  nodeText = namespaceUri(item.uri);
                  break;
                default:
                case 'label':
                  nodeText = item.label;
                  break;
              }
            }

            let a_attr = {}; // default: no special style
            // Optionally, add a "skip" property to the item if needed.
            item.skip = false;

            // Set the node text using a helper function.
            nodeText = setNodeText(item);

            if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Deprecated') {
              // Check if we should hide deprecated nodes (for non-owners).
              if (hideDeprecated && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                // Show deprecated nodes with a specific style.
                nodeText += ' (Deprecated)';
                if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                  nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                  a_attr = { style: 'font-style: italic; color:rgba(141, 141, 141, 0.77);' };
                } else {
                  nodeText += ' (Another Person)';
                  a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                }
              } else {
                nodeText += ' (Deprecated)';
                if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                  nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                  a_attr = { style: 'font-style: italic; color:rgba(141, 141, 141, 0.77);' };
                } else {
                  nodeText += ' (Another Person)';
                  a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                }
              }
            } else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Draft') {
              // Check if we should hide draft nodes for non-owners.
              if (hideDraft && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                item.skip = true;
              } else {
                nodeText += ' (Draft)';
                if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                  nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                  a_attr = { style: 'font-style: italic; color:rgba(153, 0, 0, 0.77);' };
                } else {
                  nodeText += ' (Another Person)';
                  a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                }
              }
            } else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#UnderReview') {
              // Check if we should hide draft nodes for non-owners.
              if (hideDraft && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                item.skip = true;
              } else {
                nodeText += ' (Under Review)';
                if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                  nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                  a_attr = { style: 'font-style: italic; color:rgb(172, 164, 164);' };
                } else {
                  nodeText += ' (Another Person)';
                  a_attr = { style: 'font-style: italic; color:rgba(206, 103, 19, 0.77);' };
                }
              }
            }

            // Create the node and store it in the nodeMap.
            nodeMap.set(item.uri, {
              id: item.uri,
              text: nodeText,
              label: item.label,
              uri: item.uri,
              superUri: item.superUri || null, // Keep parent pointer.
              typeNamespace: item.typeNamespace || '',
              icon: 'fas fa-file-alt',
              hasStatus: item.hasStatus,
              hasSIRManagerEmail: item.hasSIRManagerEmail,
              hasWebDocument: item.hasWebDocument,
              hasImageUri: item.hasImageUri,
              data: {
                originalLabel: item.label + setTitleSufix(item),
                originalPrefixLabel: namespacePrefixUri(item.uri) + item.label + setTitleSufix(item),
                originalUri: item.uri + setTitleSufix(item),
                originalPrefixUri: namespaceUri(item.uri + setTitleSufix(item)),
                comment: item.comment || '',
                typeNamespace: item.typeNamespace || '',
                hasWebDocument: item.hasWebDocument,
                hasImageUri: item.hasImageUri,
              },
              a_attr: a_attr,
              children: []
            });
          });

          // 3) Link each node to its parent's children array, ignoring skipped nodes.
          let root = null;
          if (forcedRootUri) {
            // When forcedRootUri is provided, we assume the API returns a sequential chain
            // (first element is the result and the last is the top parent).
            // We ignore the superUri values and build the chain based solely on the array order.
            const chain = filteredItems.slice(); // Copy the filtered items.
            // Reverse the chain so that the forced top becomes the root.
            chain.reverse();
            chain.forEach((item, index) => {
              const node = nodeMap.get(item.uri);
              if (index === 0) {
                // The first node (after reverse) is the forced root.
                root = node;
              } else {
                // Always attach the current node as the child of the last node in the chain.
                let current = root;
                // Since it's a chain, traverse down the only child.
                while (current.children && current.children.length > 0) {
                  current = current.children[0];
                }
                current.children.push(node);
              }
            });
          } else {
            // Original linking using the superUri property.
            filteredItems.forEach(item => {
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
          }

          // 4) If forcedRootUri is provided, then force that node to be the root.
          if (forcedRootUri && nodeMap.has(forcedRootUri)) {
            root = nodeMap.get(forcedRootUri);
          } else if (!root) {
            // Fallback: if no root found, try to find any node without a parent.
            for (const item of filteredItems) {
              if (!item.superUri) {
                root = nodeMap.get(item.uri);
                break;
              }
            }
          }

          return root;
        }


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
              check_callback: true,
              data: function (node, cb) {
                if (node.id === '#') {
                  cb(getFilteredBranches().map(branch => ({
                    id: branch.id,
                    // text: (showNameSpace ? branch.label : branch.typeNamespace),
                    text: setNodeText(branch),
                    label: branch.label,
                    uri: branch.uri,
                    typeNamespace: branch.typeNamespace || '',
                    comment: branch.comment || '',
                    data: {
                      originalLabel: branch.label + setTitleSufix(branch),
                      originalPrefixLabel: namespacePrefixUri(branch.uri) + branch.label + setTitleSufix(branch),
                      originalUri: branch.uri + setTitleSufix(branch),
                      originalPrefixUri: namespaceUri(branch.uri) + setTitleSufix(branch),
                      typeNamespace: branch.typeNamespace || '',
                      comment: branch.comment || '',
                      hasWebDocument: branch.hasWebDocument,
                      hasImageUri: branch.hasImageUri,
                    },
                    icon: 'fas fa-folder',
                    hasStatus: branch.hasStatus,
                    hasSIRManagerEmail: branch.hasSIRManagerEmail,
                    hasWebDocument: branch.hasWebDocument,
                    hasImageUri: branch.hasImageUri,
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
                            text: setNodeText(item),
                            label: item.label,
                            uri: item.uri,
                            typeNamespace: item.typeNamespace || '',
                            comment: item.comment || '',
                            data: {
                              originalLabel: item.label + setTitleSufix(item),
                              originalPrefixLabel: namespacePrefixUri(item.uri) + item.label + setTitleSufix(item),
                              originalUri: item.uri + setTitleSufix(item),
                              originalPrefixUri: namespaceUri(item.uri) + setTitleSufix(item),
                              typeNamespace: item.typeNamespace || '',
                              comment: item.comment || '',
                              hasWebDocument: item.hasWebDocument,
                              hasImageUri: item.hasImageUri,
                            },
                            icon: 'fas fa-file-alt',
                            hasStatus: item.hasStatus,
                            hasSIRManagerEmail: item.hasSIRManagerEmail,
                            hasWebDocument: item.hasWebDocument,
                            hasImageUri: item.hasImageUri,
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
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgba(141, 141, 141, 0.77);' };
                              } else {
                                nodeObj.text += ' (Another Person)';
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                              // nodeObj.state = { disabled: true };
                              }
                            }
                          } else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#Draft') {
                            if (hideDraft && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                              nodeObj.skip = true;
                            } else {
                              nodeObj.text += ' (Draft)';
                              if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                                nodeObj.text += ' (' + drupalSettings.rep_tree.username + ')';
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgba(153, 0, 0, 0.77);' };
                              } else {
                                nodeObj.text += ' (Another Person)';
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                              }
                            }
                          } else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#UnderReview') {
                            if (hideDraft && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                              nodeObj.skip = true;
                            } else {
                              nodeObj.text += ' (Under Review)';
                              if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                                nodeObj.text += ' (' + drupalSettings.rep_tree.username + ')';
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgb(172, 164, 164);' };
                              } else {
                                nodeObj.text += ' (Another Person)';
                                nodeObj.a_attr = { style: 'font-style: italic; color:rgba(206, 103, 19, 0.77);' };
                              }
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
              data: {
                keyword: searchTerm,
                superuri: drupalSettings.rep_tree.superclass,
                typeNameSpace: searchTerm,
              },
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

        var fieldId = $(this).data('field-id');
        //console.log(fieldId);
        if (fieldId) {
          setTimeout(function () {
            //console.log($('#' + fieldId));
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
