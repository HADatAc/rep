(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      once('jstree-initialized', '#tree-root', context).forEach((element) => {

        // If a search value exists, fill in the search input.
        if (drupalSettings.rep_tree && drupalSettings.rep_tree.searchValue) {
          $('#search_input', context).val(drupalSettings.rep_tree.searchValue);
          // populateTree(drupalSettings.rep_tree.searchValue);
        }

        /**
         * Given a full URI, returns the prefixed form (e.g. "sio:SIO_001013").
         * Assumes drupalSettings.rep_tree.nameSpacesList is an object mapping
         * prefix -> namespace URI.
         */
        function namespacePrefixUri(uri) {
          const namespaces = drupalSettings.rep_tree.nameSpacesList;
          for (const abbrev in namespaces) {
            if (namespaces.hasOwnProperty(abbrev)) {
              const ns = namespaces[abbrev];
              // If URI starts with namespace URI, replace that part with prefix + ":"
              if (abbrev && ns && uri.startsWith(ns)) {
                return abbrev + ":" + uri.slice(ns.length);
              }
            }
          }
          // Return original URI if no matching namespace found
          return uri;
        }

        /**
         * Returns the namespace‐URI form for a prefixed URI (if needed).
         * Not used directly in search, but kept for completeness.
         */
        function namespaceUri(uri) {
          const namespaces = drupalSettings.rep_tree.nameSpacesList;
          for (const abbrev in namespaces) {
            if (namespaces.hasOwnProperty(abbrev)) {
              const ns = namespaces[abbrev];
              if (abbrev && ns && uri.startsWith(ns)) {
                return uri.replace(ns, abbrev + ":");
              }
            }
          }
          return uri;
        }

        /**
         * Sanitize a string to be a valid DOM element ID: replace any character
         * not alphanumeric, underscore, or hyphen with underscore.
         */
        function sanitizeForId(str) {
          return str.replace(/[^A-Za-z0-9_-]/g, '_');
        }

        /**
         * Remove any duplicate branch labels to prevent duplicates in the root level.
         */
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

        // Selectors and state variables:
        const $treeRoot = $(element);
        const $selectNodeButton = $('#select-tree-node', context);
        const $searchInput = $('#search_input', context);
        const $clearButton = $('#clear-search', context);
        const $waitMessage = $('#wait-message', context);

        // Retrieve the initial search value from drupalSettings:
        var inicial = drupalSettings.rep_tree.searchValue;

        // Fill the search field if there is an initial value:
        if (inicial && inicial.length > 0) {
          $('#search_input', context).val(inicial);
        }

        let activityTimeout = null;
        const activityDelay = 1000;
        let initialSearchDone = false;

        // Read whether to hide Draft or Deprecated nodes from drupalSettings:
        let hideDraft = drupalSettings.rep_tree.hideDraft || false;
        let hideDeprecated = drupalSettings.rep_tree.hideDeprecated || false;

        // Read rendering mode for labels (e.g. “label”, “labelprefix”, etc.)
        let showLabel = drupalSettings.rep_tree.showLabel || 'label';

        $('#toggle-draft').on('change', function () {
          // If checkbox is checked, hideDraft = true; otherwise false
          hideDraft = $(this).is(':checked');
          // Rebuild the tree with the new hideDraft value
          resetTree();
        });

        $('#toggle-deprecated').on('change', function () {
          hideDeprecated = $(this).is(':checked');
          // Rebuild the tree with the new hideDeprecated value
          resetTree();
        });

        /**
         * When the rendering mode radio buttons change, update each node's displayed text.
         * Possible modes: "label", "labelprefix", "uri", "uriprefix".
         */
        $(document).on('change', 'input[name="label_mode"]', function () {
          const selectedValue = $(this).val();
          const treeInstance = $('#tree-root').jstree(true);
          if (!treeInstance) {
            return;
          }
          for (let nodeId in treeInstance._model.data) {
            if (nodeId === '#') continue;
            const node = treeInstance._model.data[nodeId];
            if (node && node.data) {
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

        /**
         * Compute the node text based on the currently selected rendering mode.
         * If mode is 'label', show item.label; if 'labelprefix', prefix + label; etc.
         */
        function setNodeText(item) {
          const selectedValue = $('input[name="label_mode"]:checked').val();
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
              nodeText = item.label || item.uri;
              break;
          }
          return nodeText;
        }

        /**
         * Append a status suffix (e.g. "(Draft)", "(Deprecated)", "(Under Review)")
         * to the node label string, based on item.hasStatus and ownership.
         */
        function setTitleSufix(item) {
          let suffix = '';
          const DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
          const DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
          const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

          if (item.hasStatus === DEPRECATED_URI) {
            suffix += ' (Deprecated)';
            if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
              suffix += ' (' + drupalSettings.rep_tree.username + ')';
            } else {
              suffix += ' (Another Person)';
            }
          }
          if (item.hasStatus === DRAFT_URI) {
            suffix += ' (Draft)';
            if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
              suffix += ' (' + drupalSettings.rep_tree.username + ')';
            } else {
              suffix += ' (Another Person)';
            }
          }
          if (item.hasStatus === UNDERREVIEW_URI) {
            suffix += ' (Under Review)';
            if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
              suffix += ' (' + drupalSettings.rep_tree.username + ')';
            } else {
              suffix += ' (Another Person)';
            }
          }
          return suffix;
        }

        /**
         * Throttled callback to hide the “waiting” message and show the tree
         * once initial nodes have loaded or nodes have opened.
         */
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
                // After the first real search, we won’t need to throttle again.
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

        /**
         * Attach event listeners to the jsTree instance for node selection and hover.
         */
        function attachTreeEventListeners() {
          $treeRoot.off('select_node.jstree hover_node.jstree load_node.jstree open_node.jstree');

          // Nothing special on load_node or open_node here (just placeholder).
          $treeRoot.on('load_node.jstree open_node.jstree', function () {});

          // When a node is selected, update the “Select Node” button’s enabled state
          // and display the node’s details below the tree.
          $treeRoot.on('select_node.jstree', function (e, data) {
            const selectedNode = data.node.original;
            const DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
            const DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
            const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

            // If the node is Draft/Deprecated/UnderReview and not owned by the current user, disable button.
            if (
              (selectedNode.hasStatus === DRAFT_URI ||
               selectedNode.hasStatus === DEPRECATED_URI ||
               selectedNode.hasStatus === UNDERREVIEW_URI) &&
              selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail
            ) {
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value');
            }
            // If it’s Deprecated but owned by the user, still disable (cannot pick deprecated).
            else if (
              selectedNode.hasStatus === DEPRECATED_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value');
            }
            // If it’s Draft and owned by the user, allow selection.
            else if (
              selectedNode.hasStatus === DRAFT_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data('selected-value', selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace)
                .data('field-id', $('#tree-root').data('field-id'));
            }
            // If it’s UnderReview but owned by the user, still disable.
            else if (
              selectedNode.hasStatus === UNDERREVIEW_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value');
            }
            // Otherwise (normal, non‐draft/non‐deprecated), enable selection.
            else {
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data('selected-value', selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace)
                .data('field-id', $('#tree-root').data('field-id'));
            }

            // Build HTML to show the selected node’s details:
            let html = `
              <strong>Label:</strong> ${selectedNode.label}<br/>
              <strong>URI:</strong>
              <a href="${drupalSettings.rep_tree.baseUrl}/rep/uri/${base64EncodeUnicode(selectedNode.uri)}" target="_new">
                ${selectedNode.uri}
              </a><br/>
            `;

            // If there is a web document, show link to view it:
            const webDocument = data.node.data.hasWebDocument || "";
            if (webDocument.trim().length > 0) {
              if (webDocument.trim().toLowerCase().startsWith("http")) {
                html += `
                  <strong>Web Document:</strong>
                  <a href="${webDocument}" target="_new">${webDocument}</a><br/>
                `;
              } else {
                const uriPart = selectedNode.uri.includes('#/') ? selectedNode.uri.split('#/')[1] : selectedNode.uri;
                const downloadUrl = `${drupalSettings.rep_tree.baseUrl}/rep/webdocdownload/${encodeURIComponent(uriPart)}?doc=${encodeURIComponent(webDocument)}`;
                html += `
                  <strong>Web Document:</strong>
                  <a href="#" class="view-media-button" data-view-url="${downloadUrl}">${webDocument}</a><br/>
                `;
              }
            }

            // If the node has a description comment, display it:
            const comment = data.node.data.comment || "";
            if (comment.trim().length > 0) {
              html += `
                <br/>
                <strong>Description:</strong><br/>
                ${comment}
              `;
            }

            $('#node-comment-display').html(html).show();
          });

          // On hover, set the title attribute on the anchor so tooltip appears:
          $treeRoot.on('hover_node.jstree', function (e, data) {
            const comment = data.node.data.comment || '';
            const nodeAnchor = $('#' + $.escapeSelector(data.node.id + '_anchor'));
            if (comment) {
              nodeAnchor.attr('title', comment);
            } else {
              nodeAnchor.removeAttr('title');
            }
          });
        }

        /**
         * Base64‐encode a Unicode string so it can be placed in a URL.
         */
        function base64EncodeUnicode(str) {
          const utf8Bytes = new TextEncoder().encode(str);
          let asciiStr = '';
          for (let i = 0; i < utf8Bytes.length; i++) {
            asciiStr += String.fromCharCode(utf8Bytes[i]);
          }
          return btoa(asciiStr);
        }

        /**
         * Initialize jsTree with root‐level branches, plus AJAX callback for children.
         */

        var inicial = drupalSettings.rep_tree.searchValue;

        function initializeJstree() {
          $treeRoot.jstree({
            core: {
              check_callback: true,
              data: function (node, cb) {
                if (node.id === '#') {
                  // Top‐level branches
                  const arr = getFilteredBranches().map(branch => {
                    const prefixed = namespacePrefixUri(branch.uri);
                    return {
                      id: branch.id,
                      text: setNodeText(branch),
                      label: branch.label,
                      uri: branch.uri,
                      typeNamespace: branch.typeNamespace || '',
                      data: {
                        // Store original label, prefix+label, URI forms, etc.
                        originalLabel: branch.label + setTitleSufix(branch),
                        originalPrefixLabel: namespacePrefixUri(branch.uri) + branch.label + setTitleSufix(branch),
                        originalUri: branch.uri + setTitleSufix(branch),
                        originalPrefixUri: namespaceUri(branch.uri) + setTitleSufix(branch),
                        // Store just the prefix, e.g. "sio:SIO_001013"
                        prefix: prefixed,
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
                    };
                  });
                  cb(arr);
                } else {
                  // AJAX: load children of a given node
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      const temp = [];
                      const seen = new Set();
                      data.forEach(item => {
                        const normalizedUri = item.uri.trim().toLowerCase();
                        if (!seen.has(normalizedUri)) {
                          seen.add(normalizedUri);
                          const prefixed = namespacePrefixUri(item.uri);
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
                              prefix: prefixed,
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

                          // If the item is deprecated and we should hide deprecated for non‐owners:
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
                            }
                          }
                          // If the item is a draft and we should hide drafts for non‐owners:
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
                          }
                          // If the item is under review and hideDraft is true for non‐owners:
                          else if (item.hasStatus === 'http://hadatac.org/ont/vstoi#UnderReview') {
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

                          // Only push if not flagged as skipped:
                          if (!nodeObj.skip) {
                            temp.push(nodeObj);
                          }
                        }
                      });
                      cb(temp);
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
  // 1) Anexa listeners de seleção, hover etc.
  attachTreeEventListeners();
  $treeRoot.on('load_node.jstree open_node.jstree', resetActivityTimeout);
  resetActivityTimeout();

  // 2) Se houver um URI “inicial” (drupalSettings.rep_tree.searchValue),
  //    vamos reconstruir a árvore até esse nó e depois abri-la por completo.
  if (inicial && inicial.length > 0 && drupalSettings.rep_tree.prefix !== "false") {
    // 2.1) Chama populateTree com o URI “inicial”
    populateTree(inicial);

    // 2.2) Registra um handler one‐time para quando o jsTree terminar
    //      de dar refresh() com os dados (netos/filhos de populateTree).
    $treeRoot.one('refresh.jstree', function () {
      const ti = $treeRoot.jstree(true);
      // 2.3) Expande TODOS os ramos a partir da raiz (“#”). Quando terminar, seleciona “inicial”.
      ti.open_all('#', function () {
        ti.select_node(inicial);
      });
    });
  }
});
          // If there is an initial search term, perform the search now:
          if (inicial && inicial.length > 0) {
            $treeRoot.jstree(true).search(inicial);
          }
        }

        /**
         * Build a subtree rooted at forcedRootUri. If forcedRootUri is provided,
         * only nodes up to that URI will be used. Otherwise build the full hierarchy
         * via superUri relationships.
         *
         * @param {Array} items - array of objects like { uri, label, comment, superUri, typeNamespace, hasStatus, hasSIRManagerEmail, hasWebDocument, hasImageUri }
         * @param {string|null} forcedRootUri - the URI to treat as the root of the subtree
         * @returns {Object|null} - the root node with its children, or null if not found
         */
        function buildHierarchy(items, forcedRootUri = null) {
          // 1) Remove duplicates by URI
          const uniqueItems = [];
          const seenUris = new Set();
          items.forEach(item => {
            if (!seenUris.has(item.uri)) {
              uniqueItems.push(item);
              seenUris.add(item.uri);
            }
          });

          // 1.1) If a forcedRootUri is provided, only take items up to that index
          let filteredItems = uniqueItems;
          if (forcedRootUri) {
            const forcedIndex = uniqueItems.findIndex(item => item.uri === forcedRootUri);
            if (forcedIndex !== -1) {
              filteredItems = uniqueItems.slice(0, forcedIndex + 1);
            }
          }

          // 2) Build a Map from URI to node object (with children = [])
          const nodeMap = new Map();
          filteredItems.forEach(item => {
            let nodeText = setNodeText(item);
            let a_attr = {};
            item.skip = false;

            // Apply status logic: "Deprecated", "Draft", "UnderReview"
            const DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
            const DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
            const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

            if (item.hasStatus === DEPRECATED_URI) {
              if (hideDeprecated && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                // Mark as skip if non‐owner and hideDeprecated=true
                item.skip = true;
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
            } else if (item.hasStatus === DRAFT_URI) {
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
            } else if (item.hasStatus === UNDERREVIEW_URI) {
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

            const prefixed = namespacePrefixUri(item.uri);

            nodeMap.set(item.uri, {
              id: item.uri,
              text: nodeText,
              label: item.label,
              uri: item.uri,
              superUri: item.superUri || null,
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
                originalPrefixUri: namespaceUri(item.uri) + setTitleSufix(item),
                prefix: prefixed,
                comment: item.comment || '',
                typeNamespace: item.typeNamespace || '',
                hasWebDocument: item.hasWebDocument,
                hasImageUri: item.hasImageUri,
              },
              a_attr: a_attr,
              children: []
            });
          });

          // 3) Link each node to its parent's children array, ignoring skipped nodes
          let root = null;
          if (forcedRootUri) {
            // If forcedRootUri is given, assume items form a linear chain in filteredItems
            const chain = filteredItems.slice(); // copy
            chain.reverse(); // root is last in original
            chain.forEach((item, index) => {
              const node = nodeMap.get(item.uri);
              if (!node) return;
              if (index === 0) {
                // First (after reverse) is forced root
                root = node;
              } else {
                let current = root;
                while (current.children && current.children.length > 0) {
                  current = current.children[0];
                }
                current.children.push(node);
              }
            });
          } else {
            // Standard parent-child linking via superUri
            filteredItems.forEach(item => {
              if (item.skip) return;
              const node = nodeMap.get(item.uri);
              if (!node) return;
              if (item.superUri && !item.skip) {
                const parent = nodeMap.get(item.superUri);
                if (parent && !parent.skip) {
                  parent.children.push(node);
                }
              } else {
                // No superUri => root candidate
                root = node;
              }
            });
          }

          // 4) If forcedRootUri is present in nodeMap, force that node to be root
          if (forcedRootUri && nodeMap.has(forcedRootUri)) {
            root = nodeMap.get(forcedRootUri);
          } else if (!root) {
            // If no root found, pick the first item without a superUri
            for (const item of filteredItems) {
              if (!item.superUri) {
                root = nodeMap.get(item.uri);
                break;
              }
            }
          }

          return root;
        }

        /**
         * Given a URI, call the subclass search endpoint, build a subtree, and refresh the tree.
         */
        function populateTree(uri) {
          $.ajax({
            url: drupalSettings.rep_tree.searchSuperClassEndPoint,
            type: 'GET',
            data: { uri: encodeURI(uri) },
            dataType: 'json',
            success: function (data) {
              const forcedRootUri = drupalSettings.rep_tree.superclass;
              const rootNode = buildHierarchy(data, forcedRootUri);
              const treeData = rootNode ? [rootNode] : [];
              const treeInstance = $treeRoot.jstree(true);
              if (treeInstance) {
                treeInstance.settings.core.data = treeData;
                treeInstance.refresh();

                // Auto-open all nodes after refresh
                // $treeRoot.on('refresh.jstree', function () {
                //   const ti = $treeRoot.jstree(true);
                //   function openRecursively(nodeId) {
                //     ti.open_node(nodeId, function () {
                //       const children = ti.get_node(nodeId).children;
                //       if (children && children.length > 0) {
                //         children.forEach(childId => openRecursively(childId));
                //       }
                //     });
                //   }
                //   const rootIds = ti.get_node('#').children;
                //   rootIds.forEach(rid => openRecursively(rid));
                // });
                $treeRoot.one('refresh.jstree', function () {
                  const ti = $treeRoot.jstree(true);
                  // Abre todos os ramos
                  ti.open_all('#', function () {
                    // Só após tudo estar aberto, seleciona o nó cujo ID/URI é “inicial”
                    ti.select_node(inicial);
                  });
                });
              }
            },
            error: function () {
              console.error('Error loading tree data for URI:', uri);
            },
          });
        }

        /**
         * Destroy and recreate the entire tree to reset to the initial root state.
         */
        function resetTree() {
          $searchInput.val('');
          $clearButton.hide();
          $treeRoot.jstree('destroy').empty();
          $treeRoot.jstree({
            core: {
              check_callback: true,
              data: function (node, cb) {
                if (node.id === '#') {
                  const arr = getFilteredBranches().map(branch => {
                    const prefixed = namespacePrefixUri(branch.uri);
                    return {
                      id: branch.id,
                      text: setNodeText(branch),
                      label: branch.label,
                      uri: branch.uri,
                      typeNamespace: branch.typeNamespace || '',
                      data: {
                        originalLabel: branch.label + setTitleSufix(branch),
                        originalPrefixLabel: namespacePrefixUri(branch.uri) + branch.label + setTitleSufix(branch),
                        originalUri: branch.uri + setTitleSufix(branch),
                        originalPrefixUri: namespaceUri(branch.uri) + setTitleSufix(branch),
                        prefix: prefixed,
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
                    };
                  });
                  cb(arr);
                } else {
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      const temp = [];
                      const seen = new Set();
                      data.forEach(item => {
                        const normalizedUri = item.uri.trim().toLowerCase();
                        if (!seen.has(normalizedUri)) {
                          seen.add(normalizedUri);
                          const prefixed = namespacePrefixUri(item.uri);
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
                              prefix: prefixed,
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

                          // Apply hide logic for Deprecated, Draft, UnderReview
                          const DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
                          const DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
                          const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

                          if (item.hasStatus === DEPRECATED_URI) {
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
                            }
                          } else if (item.hasStatus === DRAFT_URI) {
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
                          } else if (item.hasStatus === UNDERREVIEW_URI) {
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

                          if (!nodeObj.skip) {
                            temp.push(nodeObj);
                          }
                        }
                      });
                      cb(temp);
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

                // 1) Match against the node text (whatever is visible)
                if (node.text.toLowerCase().includes(searchTerm)) {
                  return true;
                }

                // 2) Match against typeNamespace
                if (
                  node.data.typeNamespace &&
                  node.data.typeNamespace.toLowerCase().includes(searchTerm)
                ) {
                  return true;
                }

                // 3) Match against the full prefixed URI (originalPrefixUri)
                if (
                  node.data.originalPrefixUri &&
                  node.data.originalPrefixUri.toLowerCase().includes(searchTerm)
                ) {
                  return true;
                }

                // 4) Match against the simple prefix field (prefix)
                if (
                  node.data.prefix &&
                  node.data.prefix.toLowerCase().includes(searchTerm)
                ) {
                  return true;
                }

                return false;
              },
            },
          });

          $treeRoot.on('ready.jstree', function () {
            attachTreeEventListeners();
            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);
            resetActivityTimeout();

            if (inicial && inicial.length > 0 && drupalSettings.rep_tree.prefix !== "false") {
              // Esta chamada irá rebuildar a árvore com a hierarquia até "inicial"
              populateTree(inicial);

              // 2) Agora, antes do próximo refresh, configure um handler one‐time:
              $treeRoot.one('refresh.jstree', function () {
                // Garante que, depois do refresh, o nó "inicial" seja selecionado:
                var treeInst = $treeRoot.jstree(true);
                if (treeInst) {
                  treeInst.select_node(inicial);
                }
              });
            }
          });

          // Perform initial search if there was a value passed in:
          if (inicial && inicial.length > 0) {
            $treeRoot.jstree(true).search(inicial);
          }
        }

        $('#reset-tree', context).on('click', function (e) {
          e.preventDefault();
          resetTree();
        });

        // Hide the tree until it's ready, show a “waiting” message instead:
        $treeRoot.hide();
        $waitMessage.show();
        $searchInput.prop('disabled', true);

        if ($treeRoot.length) {
          initializeJstree();
          // Initialize autocomplete on the search input:
          $(document).ready(function () {
            setupAutocomplete('#search_input');

            // If user presses Enter in the search field, trigger jsTree search:
            $('#search_input').on('keypress', function (e) {
              if (e.which === 13) {
                e.preventDefault();
                const term = $searchInput.val().trim();
                $treeRoot.jstree(true).search(term);
              }
            });
          });
        } else {
          console.warn('Tree root not found. Initialization aborted.');
        }

        /**
         * Configure autocomplete suggestions when typing in the search input.
         * Makes an AJAX call to rep_tree.searchSubClassEndPoint, shows a dropdown of suggestions,
         * and on click, repopulates the tree with that node’s ancestry.
         */
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
          // Hide suggestions box when input loses focus
          $(inputField).on('blur', function () {
            setTimeout(() => $('#autocomplete-suggestions').hide(), 200);
          });
        }
      });
    },
  };
})(jQuery, Drupal, drupalSettings);

(function ($, Drupal) {
  Drupal.behaviors.modalFix = {
    attach: function (context, settings) {
      const $selectNodeButton = $('#select-tree-node');

      /**
       * Adjust the modal dialog width and position whenever it opens or content changes.
       */
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

      // Whenever a dialog opens, adjust its CSS
      $(document).on('dialogopen', adjustModal);

      // Also adjust when a node is selected (jstree event), in case size changes
      $(document).on('select_node.jstree', function () {
        setTimeout(adjustModal, 100);
      });

      // When the dialog closes, restore the HTML overflow settings
      $(document).on('dialog:afterclose', function () {
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });
      });

      // When the “Select Node” button is clicked inside the modal, close it and trigger change on the original field
      $selectNodeButton.on('click', function () {
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });

        var fieldId = $(this).data('field-id');
        if (fieldId) {
          setTimeout(function () {
            $('#' + fieldId).trigger('change');
          }, 100);
        }
      });

      // If the user clicks the “X” in the top‐right of the dialog, restore HTML overflow
      $(document).on('click', '.ui-dialog-titlebar-close', function () {
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });
      });

      // Observe any child changes inside the dialog and re‐adjust size if needed
      const observer = new MutationObserver(adjustModal);
      $('.ui-dialog-content').each(function () {
        observer.observe(this, { childList: true, subtree: true });
      });
    },
  };
})(jQuery, Drupal, drupalSettings);
