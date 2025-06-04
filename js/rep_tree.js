/**
 * Drupal behavior for rendering and interacting with a jsTree-based taxonomy or ontology tree.
 * This behavior initializes the tree, handles search, filtering, and node selection logic.
 */
(function ($, Drupal, drupalSettings) {
  // Define a new Drupal behavior named 'tree'
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      // Use the 'once' method to ensure jsTree is only initialized once per page load on the #tree-root element
      once('jstree-initialized', '#tree-root', context).forEach((element) => {

        // If a previous search value was stored in drupalSettings, populate the search input field with it
        if (drupalSettings.rep_tree && drupalSettings.rep_tree.searchValue) {
          $('#search_input', context).val(drupalSettings.rep_tree.searchValue);
        }

        /**
         * Given a full URI string, returns its prefixed form if a matching namespace is found.
         * For example, if the namespaces list contains { "sio": "http://semanticscience.org/" },
         * and uri = "http://semanticscience.org/SIO_001013", it returns "sio:SIO_001013".
         *
         * @param {string} uri - The full URI to convert to prefixed form.
         * @returns {string} - The prefixed URI string, or the original URI if no namespace matches.
         */
        function namespacePrefixUri(uri) {
          const namespaces = drupalSettings.rep_tree.nameSpacesList;
          for (const abbrev in namespaces) {
            if (namespaces.hasOwnProperty(abbrev)) {
              const ns = namespaces[abbrev];
              // If the URI starts with this namespace URI, strip it and prepend the prefix
              if (abbrev && ns && uri.startsWith(ns)) {
                return abbrev + ":" + uri.slice(ns.length);
              }
            }
          }
          // Return original URI if no matching namespace found
          return uri;
        }

        /**
         * Given a full URI string, returns the namespace-URI form for a prefixed URI.
         * This function is not directly used in search, but provided for potential future needs.
         *
         * @param {string} uri - The URI to convert to namespace-URI form.
         * @returns {string} - The namespace-URI string, or the original URI if no mapping applies.
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
         * Sanitize an arbitrary string to be a valid DOM element ID.
         * Replaces any character that is not alphanumeric, underscore, or hyphen with an underscore.
         *
         * @param {string} str - The string to sanitize.
         * @returns {string} - A sanitized string safe for use as an HTML ID.
         */
        function sanitizeForId(str) {
          return str.replace(/[^A-Za-z0-9_-]/g, '_');
        }

        /**
         * Remove duplicate branch entries by label to avoid multiple root-level nodes with identical labels.
         * Logs a warning for each removed duplicate.
         *
         * @returns {Array} - An array of unique branch objects.
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

        // Cache selectors and state variables for performance and convenience
        const $treeRoot = $(element);                        // The root container for jsTree
        const $selectNodeButton = $('#select-tree-node', context); // Button to confirm node selection
        const $searchInput = $('#search_input', context);    // The text input for search queries
        const $clearButton = $('#clear-search', context);    // Button to clear the search input
        const $waitMessage = $('#wait-message', context);    // "Please wait" message shown until tree is ready

        // Retrieve the initial search value from drupalSettings (if any)
        var inicial = drupalSettings.rep_tree.searchValue;

        // If an initial search value exists, fill the search field with it
        if (inicial && inicial.length > 0) {
          $('#search_input', context).val(inicial);
        }

        // Variables to manage delayed showing of the tree until initial nodes are loaded
        let activityTimeout = null;
        const activityDelay = 1000;      // Time in milliseconds to wait before hiding the loading message
        let initialSearchDone = false;   // Flag to indicate whether the first search has been completed

        // Flags to control visibility of draft/deprecated nodes based on user settings
        let hideDraft = drupalSettings.rep_tree.hideDraft || false;
        let hideDeprecated = drupalSettings.rep_tree.hideDeprecated || false;

        // The current rendering mode determines how node labels are displayed (e.g., "label", "labelprefix", "uri", or "uriprefix")
        let showLabel = drupalSettings.rep_tree.showLabel || 'label';

        // When the "Hide Draft" checkbox is toggled, update the state and rebuild the tree
        $('#toggle-draft').on('change', function () {
          hideDraft = $(this).is(':checked');
          resetTree();
        });

        // When the "Hide Deprecated" checkbox is toggled, update the state and rebuild the tree
        $('#toggle-deprecated').on('change', function () {
          hideDeprecated = $(this).is(':checked');
          resetTree();
        });

        /**
         * When the rendering mode radio buttons change, update the displayed text of every node.
         * Possible modes:
         *   - 'label': show the plain label text
         *   - 'labelprefix': show namespace-prefixed URI concatenated with label
         *   - 'uri': show the full URI string
         *   - 'uriprefix': show the namespace-URI version (prefix:localName)
         */
        $(document).on('change', 'input[name="label_mode"]', function () {
          const selectedValue = $(this).val();
          const treeInstance = $('#tree-root').jstree(true);
          if (!treeInstance) {
            return;
          }
          // Iterate through all nodes in the tree model
          for (let nodeId in treeInstance._model.data) {
            if (nodeId === '#') continue; // Skip the root placeholder
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
              // Only rename if the new text differs from the existing label
              if (node.text !== nodeText) {
                treeInstance.rename_node(nodeId, nodeText);
              }
            }
          }
        });

        /**
         * Compute and return the display text for a node based on the currently selected rendering mode.
         * If mode is 'label', show item.label; if 'labelprefix', show prefix + label; etc.
         *
         * @param {Object} item - The tree item object containing at least { uri, label }.
         * @returns {string} - The computed display text for the node.
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
         * Append a status suffix (e.g., "(Draft)", "(Deprecated)", "(Under Review)") to the node label string,
         * based on the item's hasStatus value and whether the current user is owner/manager.
         *
         * @param {Object} item - The item object containing at least { hasStatus, hasSIRManagerEmail }.
         * @returns {string} - A status suffix to append to the node's visible label.
         */
        function setTitleSufix(item) {
          let suffix = '';
          // Define constants for the known status URIs
          const DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
          const DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
          const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

          // If node is marked as Deprecated, append "(Deprecated)" and indicate ownership
          if (item.hasStatus === DEPRECATED_URI) {
            suffix += ' (Deprecated)';
            if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
              suffix += ' (' + drupalSettings.rep_tree.username + ')';
            } else {
              suffix += ' (Another Person)';
            }
          }
          // If node is marked as Draft, append "(Draft)" and indicate ownership
          if (item.hasStatus === DRAFT_URI) {
            suffix += ' (Draft)';
            if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
              suffix += ' (' + drupalSettings.rep_tree.username + ')';
            } else {
              suffix += ' (Another Person)';
            }
          }
          // If node is marked as Under Review, append "(Under Review)" and indicate ownership
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
         * Throttled callback to hide the “waiting” message and display the tree
         * once initial nodes have loaded or when nodes have been opened.
         * Ensures the tree is not shown until essential data is available.
         */
        function resetActivityTimeout() {
          if (activityTimeout) {
            clearTimeout(activityTimeout);
          }
          activityTimeout = setTimeout(() => {
            if (!initialSearchDone) {
              $treeRoot.jstree('close_all');  // Close all expanded nodes
              $waitMessage.hide();             // Hide the loading message
              $treeRoot.show();                // Show the tree container
              treeReady = true;
              $searchInput.prop('disabled', false);
              const initialSearch = $searchInput.val().trim();
              if (initialSearch.length > 0) {
                // After the first search result arrives, we can detach event handlers related to initial loading
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
         * Attach event listeners to the jsTree instance for node selection, hover, loading, and opening.
         * Handles enabling/disabling of the "Select Node" button based on node status and ownership,
         * as well as populating the details panel when a node is clicked.
         */
        function attachTreeEventListeners() {
          // First, unbind any previously attached events to avoid duplicates
          $treeRoot.off('select_node.jstree hover_node.jstree load_node.jstree open_node.jstree');

          // Placeholder for load_node and open_node if future logic is needed
          $treeRoot.on('load_node.jstree open_node.jstree', function () {});

          // When a node is selected in jsTree
          $treeRoot.on('select_node.jstree', function (e, data) {
            const selectedNode = data.node.original;
            const DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
            const DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
            const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

            // If node has a status of Draft/Deprecated/Under Review and the current user is not the manager, disable selection
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
            // If node is Deprecated but owned by current user, still disable (cannot pick deprecated)
            else if (
              selectedNode.hasStatus === DEPRECATED_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value');
            }
            // If node is Draft and owned by current user, allow selection
            else if (
              selectedNode.hasStatus === DRAFT_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data(
                  'selected-value',
                  selectedNode.uri
                    ? selectedNode.text + " [" + selectedNode.uri + "]"
                    : selectedNode.typeNamespace
                )
                .data('field-id', $('#tree-root').data('field-id'));
            }
            // If node is Under Review but owned by current user, disable selection
            else if (
              selectedNode.hasStatus === UNDERREVIEW_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              $selectNodeButton
                .prop('disabled', true)
                .addClass('disabled')
                .removeData('selected-value');
            }
            // Otherwise (normal node or Draft owned by user), enable selection
            else {
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data(
                  'selected-value',
                  selectedNode.uri
                    ? selectedNode.text + " [" + selectedNode.uri + "]"
                    : selectedNode.typeNamespace
                )
                .data('field-id', $('#tree-root').data('field-id'));
            }

            // Construct HTML to display selected node's details below the tree
            let html = `
              <strong>Label:</strong> ${selectedNode.label}<br/>
              <strong>URI:</strong>
              <a href="${drupalSettings.rep_tree.baseUrl}/rep/uri/${base64EncodeUnicode(selectedNode.uri)}" target="_new">
                ${selectedNode.uri}
              </a><br/>
            `;

            // If the node has a 'webDocument' property, show a link to view/download it
            const webDocument = data.node.data.hasWebDocument || "";
            if (webDocument.trim().length > 0) {
              if (webDocument.trim().toLowerCase().startsWith("http")) {
                // If the URL is absolute (starts with "http"), link directly
                html += `
                  <strong>Web Document:</strong>
                  <a href="${webDocument}" target="_new">${webDocument}</a><br/>
                `;
              } else {
                // Otherwise, generate a download link relative to baseUrl
                const uriPart = selectedNode.uri.includes('#/') ? selectedNode.uri.split('#/')[1] : selectedNode.uri;
                const downloadUrl = `${drupalSettings.rep_tree.baseUrl}/rep/webdocdownload/${encodeURIComponent(uriPart)}?doc=${encodeURIComponent(webDocument)}`;
                html += `
                  <strong>Web Document:</strong>
                  <a href="#" class="view-media-button" data-view-url="${downloadUrl}">${webDocument}</a><br/>
                `;
              }
            }

            // If the node has a description/comment, display it as well
            const comment = data.node.data.comment || "";
            if (comment.trim().length > 0) {
              html += `
                <br/>
                <strong>Description:</strong><br/>
                ${comment}
              `;
            }

            // Insert the constructed HTML into the '#node-comment-display' container and make it visible
            $('#node-comment-display').html(html).show();
          });

          // On hover over a node, set the 'title' attribute on its anchor so a tooltip appears with the comment
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
         * Base64-encode a Unicode string so it can be safely placed in a URL.
         * Steps:
         *   1. Encode the string as UTF-8 bytes.
         *   2. Convert bytes to a binary string of ASCII characters.
         *   3. Use btoa() to get a Base64-encoded string.
         *
         * @param {string} str - The Unicode string to encode.
         * @returns {string} - The Base64-encoded representation.
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
         * Initialize jsTree with root-level branches and set up AJAX callbacks for loading child nodes.
         * The tree supports searching, wholerow selection, and dynamic loading of children.
         */
        function initializeJstree() {
          $treeRoot.jstree({
            core: {
              check_callback: true,  // Allow dynamic modifications if needed
              data: function (node, cb) {
                // If node.id === '#', we are at the root: return the top-level branches
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
                        // Store original forms (label, prefix+label, URI) for later switching of display modes
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
                      icon: 'fas fa-folder',          // Use a folder icon for branch nodes
                      hasStatus: branch.hasStatus,    // Custom attribute to know if node is Draft/Deprecated/UnderReview
                      hasSIRManagerEmail: branch.hasSIRManagerEmail,  // Email of the manager/owner
                      hasWebDocument: branch.hasWebDocument,
                      hasImageUri: branch.hasImageUri,
                      children: true,                 // Indicates that this node has (potential) children to load
                    };
                  });
                  cb(arr);
                } else {
                  // For non-root nodes, perform an AJAX request to fetch children
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
                        // Deduplicate children by URI (case-insensitive)
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
                            icon: 'fas fa-file-alt',       // Use a file icon for leaf nodes
                            hasStatus: item.hasStatus,
                            hasSIRManagerEmail: item.hasSIRManagerEmail,
                            hasWebDocument: item.hasWebDocument,
                            hasImageUri: item.hasImageUri,
                            children: true,                // Assume further children may exist
                            skip: false                    // Flag to indicate whether to skip rendering this node
                          };

                          // Apply logic to decide if the node should be hidden/skipped based on its status and ownership
                          const DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
                          const DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
                          const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

                          if (item.hasStatus === DEPRECATED_URI) {
                            if (hideDeprecated && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                              nodeObj.skip = true;
                            } else {
                              // Append "(Deprecated)" and style text accordingly
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
                              // Append "(Draft)" and style text accordingly
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
                              // Append "(Under Review)" and style text accordingly
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

                          // Only include the node if it is not marked as 'skip'
                          if (!nodeObj.skip) {
                            temp.push(nodeObj);
                          }
                        }
                      });
                      // Pass the array of child nodes to jsTree's callback
                      cb(temp);
                    },
                    error: function () {
                      // On error, return an empty list of children
                      cb([]);
                    },
                  });
                }
              },
            },
            plugins: ['search', 'wholerow'], // Enable search and whole-row selection
            search: {
              case_sensitive: false,
              show_only_matches: true,           // Only show nodes that match the search
              show_only_matches_children: true,  // Show children of matching nodes
            },
          });

          // Once jsTree is fully ready, attach event listeners and manage initial loading behavior
          $treeRoot.on('ready.jstree', function () {
            attachTreeEventListeners();
            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);
            resetActivityTimeout();

            // If there is an initial search value, populate the tree up to that node
            if (inicial && inicial.length > 0) {
              populateTree(inicial);
            }
          });

          // If a search term was entered before jsTree was ready, perform the search now
          if (inicial && inicial.length > 0) {
            $treeRoot.jstree(true).search(inicial);
          }
        }

        /**
         * Build a hierarchical subtree of nodes given an array of items, optionally forcing a specific URI as root.
         * This is used by 'populateTree' when drilling down to a specific node in the hierarchy.
         *
         * @param {Array} items - Array of objects, each representing a node with properties:
         *   { uri, label, comment, superUri, typeNamespace, hasStatus, hasSIRManagerEmail, hasWebDocument, hasImageUri }
         * @param {string|null} forcedRootUri - If provided, treat this URI as the root of the subtree.
         * @returns {Object|null} - A tree node object representing the root, including nested 'children' arrays, or null if no root found.
         */
        function buildHierarchy(items, forcedRootUri = null) {
          // Step 1: Remove duplicates by URI
          const uniqueItems = [];
          const seenUris = new Set();
          items.forEach(item => {
            if (!seenUris.has(item.uri)) {
              uniqueItems.push(item);
              seenUris.add(item.uri);
            }
          });

          // Step 1.1: If forcedRootUri is provided, only include items up to (and including) that URI
          let filteredItems = uniqueItems;
          if (forcedRootUri) {
            const forcedIndex = uniqueItems.findIndex(item => item.uri === forcedRootUri);
            if (forcedIndex !== -1) {
              filteredItems = uniqueItems.slice(0, forcedIndex + 1);
            }
          }

          // Step 2: Build a Map from URI to a node object (initialize each with children = [])
          const nodeMap = new Map();
          filteredItems.forEach(item => {
            let nodeText = setNodeText(item);
            let a_attr = {};    // HTML attributes (e.g., styling) for the node's anchor
            item.skip = false;  // Initialize skip flag

            // Apply status-based logic (Deprecated, Draft, UnderReview) to determine styling or skipping
            const DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
            const DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
            const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

            if (item.hasStatus === DEPRECATED_URI) {
              if (hideDeprecated && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
                // Skip if user chose to hide deprecated and current user is not the owner
                item.skip = true;
              } else {
                // Append "(Deprecated)" and style text accordingly
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
                // Append "(Draft)" and style text accordingly
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
                // Append "(Under Review)" and style text accordingly
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

            // Compute the namespace-prefixed form of the URI for display
            const prefixed = namespacePrefixUri(item.uri);

            // Create the initial node object
            nodeMap.set(item.uri, {
              id: item.uri,            // Use the full URI as the unique ID
              text: nodeText,          // Computed display text
              label: item.label,
              uri: item.uri,
              superUri: item.superUri || null,  // Parent relationship
              typeNamespace: item.typeNamespace || '',
              icon: 'fas fa-file-alt', // Default icon for tree nodes
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
              a_attr: a_attr,   // Anchor attributes (e.g., inline CSS)
              children: []      // Will hold references to child nodes
            });
          });

          // Step 3: Link each node to its parent's children array, ignoring skipped nodes
          let root = null;
          if (forcedRootUri) {
            // If forcedRootUri is provided, assume items form a linear chain
            const chain = filteredItems.slice(); // copy the array
            chain.reverse(); // In a chain, the last item in filteredItems is the top-level root
            chain.forEach((item, index) => {
              const node = nodeMap.get(item.uri);
              if (!node) return;
              if (index === 0) {
                // First after reversing is the forced root
                root = node;
              } else {
                // Append each subsequent node as the sole child of the previous
                let current = root;
                while (current.children && current.children.length > 0) {
                  current = current.children[0];
                }
                current.children.push(node);
              }
            });
          } else {
            // Standard hierarchical linking via superUri property
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
                // If no superUri or parent, consider this the root (if none assigned yet)
                root = node;
              }
            });
          }

          // Step 4: If forcedRootUri is present in nodeMap, override root with that node
          if (forcedRootUri && nodeMap.has(forcedRootUri)) {
            root = nodeMap.get(forcedRootUri);
          } else if (!root) {
            // If no root found yet, pick the first item without a superUri as root
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
         * Given a URI, fetch ancestors/subclasses via the 'searchSuperClassEndPoint', build a subtree,
         * and refresh the jsTree to display only that branch up to the specified node.
         *
         * @param {string} uri - The URI of the node whose ancestry chain should be displayed.
         */
        function populateTree(uri) {
          $.ajax({
            url: drupalSettings.rep_tree.searchSuperClassEndPoint,
            type: 'GET',
            data: { uri: encodeURI(uri) },
            dataType: 'json',
            success: function (data) {
              // Determine if a forced root is set in drupalSettings
              const forcedRootUri = drupalSettings.rep_tree.superclass;
              // Build the subtree hierarchy given the returned data
              const rootNode = buildHierarchy(data, forcedRootUri);
              const treeData = rootNode ? [rootNode] : [];
              const treeInstance = $treeRoot.jstree(true);
              if (!treeInstance) {
                return;
              }

              // Replace the core data of jsTree with our newly built subtree and refresh
              treeInstance.settings.core.data = treeData;
              treeInstance.refresh();

              /**
               * Once the 'refresh.jstree' event fires, expand all nodes and then select the initially requested node.
               * This ensures the tree opens to show the path to the target node, then highlights it.
               */
              $treeRoot.one('refresh.jstree', function () {
                // For debugging, log the raw prefix setting
                console.log('prefix raw value:', drupalSettings.rep_tree.prefix);

                // Determine if prefix display is active (could be 1, '1', or true)
                const prefixVal = drupalSettings.rep_tree.prefix;
                const prefixIsActive = (prefixVal === 1 || prefixVal === '1' || prefixVal === true);

                console.log('prefixIsActive?', prefixIsActive);

                if (inicial && inicial.length > 0 && prefixIsActive) {
                  const ti = $treeRoot.jstree(true);

                  // Expand all nodes in the tree before selecting the target node
                  ti.open_all('#', function () {
                    ti.select_node(inicial);
                  });
                }
              });
            },
            error: function () {
              console.error('Error loading tree data for URI:', uri);
            },
          });
        }

        /**
         * Destroy and recreate the entire jsTree instance to reset it to the initial root-level state.
         * Clears the search field, hides the clear button, and reinitializes the tree.
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
                  // Re-generate root-level branches
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
                      state: { opened: false }, // Ensure root nodes start closed
                    };
                  });
                  cb(arr);
                } else {
                  // AJAX request to load child nodes
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

                          // Apply hide logic for Deprecated, Draft, Under Review statuses
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
              /**
               * Custom search callback to match against multiple node properties:
               * 1) node.text (the visible label)
               * 2) node.data.typeNamespace
               * 3) node.data.originalPrefixUri
               * 4) node.data.prefix
               *
               * @param {string} str - The search term.
               * @param {Object} node - The jsTree node object to test.
               * @returns {boolean} - True if the node matches the search, False otherwise.
               */
              search_callback: function (str, node) {
                const searchTerm = str.toLowerCase();

                // 1) Match against the visible node text
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

                // 4) Match against the simple prefix field
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

          // Once jsTree is ready after resetting, attach event listeners and handle initial waiting state
          $treeRoot.on('ready.jstree', function () {
            attachTreeEventListeners();
            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);
            resetActivityTimeout();
          });

          // If an initial search term exists, perform the search after resetting
          if (inicial && inicial.length > 0) {
            $treeRoot.jstree(true).search(inicial);
          }
        }

        // When the "Reset Tree" button is clicked, prevent default behavior and call resetTree()
        $('#reset-tree', context).on('click', function (e) {
          e.preventDefault();
          resetTree();
        });

        // Hide the tree container initially and show a loading message until the tree is ready
        $treeRoot.hide();
        $waitMessage.show();
        $searchInput.prop('disabled', true);

        // If the #tree-root element exists on the page, initialize jsTree and set up autocomplete
        if ($treeRoot.length) {
          initializeJstree();
          setupAutocomplete('#search_input');

          // Bind the Enter key in the search input to trigger a jsTree search
          $('#search_input').on('keypress', function (e) {
            if (e.which === 13) {
              e.preventDefault();
              const term = $searchInput.val().trim();
              $treeRoot.jstree(true).search(term);
            }
          });
        } else {
          console.warn('Tree root not found. Initialization aborted.');
        }

        /**
         * Configure autocomplete suggestions when typing in the search input.
         * Makes an AJAX call to 'searchSubClassEndPoint', shows a dropdown of suggestions,
         * and on click, repopulates the tree with that node’s ancestry.
         *
         * @param {string} inputField - The selector for the search input field.
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
                // Map server data into suggestion objects: { id, label, uri }
                const suggestions = data.map(item => ({
                  id: item.nodeId,
                  label: item.label || 'Unnamed Node',
                  uri: item.uri,
                }));
                let suggestionBox = $('#autocomplete-suggestions');
                if (suggestionBox.length === 0) {
                  // Create the suggestions container if not present
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
                    // When a suggestion is clicked, repopulate the tree with that node's hierarchy
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
          // Hide the suggestion box when the input loses focus (with slight delay to allow click handling)
          $(inputField).on('blur', function () {
            setTimeout(() => $('#autocomplete-suggestions').hide(), 200);
          });
        }
      });
    },
  };
})(jQuery, Drupal, drupalSettings);

/**
 * Drupal behavior to adjust modal dialog dimensions and position,
 * ensuring the jsTree selection modal is responsive and correctly positioned.
 */
(function ($, Drupal) {
  Drupal.behaviors.modalFix = {
    attach: function (context, settings) {
      const $selectNodeButton = $('#select-tree-node');

      /**
       * Adjust the CSS of any open jQuery UI dialog to be centered,
       * occupy 50% of the viewport width, and positioned 10% from the top.
       */
      function adjustModal() {
        $('.ui-dialog').each(function () {
          $(this).css({
            width: 'calc(100% - 50%)',  // Dialog width is 50% of viewport
            left: '25%',                // Center horizontally
            right: '25%',
            transform: 'none',
            top: '10%',                 // 10% from top of viewport
          });
        });
      }

      // Whenever any dialog opens, adjust its dimensions
      $(document).on('dialogopen', adjustModal);

      // Also adjust modal if a node is selected in jsTree, since content/height may change
      $(document).on('select_node.jstree', function () {
        setTimeout(adjustModal, 100);
      });

      // When a dialog closes, restore the HTML overflow settings to allow scrolling
      $(document).on('dialog:afterclose', function () {
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });
      });

      // When the "Select Node" button inside the modal is clicked, close the modal and trigger change on the original field
      $selectNodeButton.on('click', function () {
        // Restore HTML overflow settings
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });

        var fieldId = $(this).data('field-id');
        if (fieldId) {
          setTimeout(function () {
            // Trigger a change event on the hidden original form field to notify Drupal of the new value
            $('#' + fieldId).trigger('change');
          }, 100);
        }
      });

      // If the user clicks the "X" (close) button in the dialog title bar, restore HTML overflow settings
      $(document).on('click', '.ui-dialog-titlebar-close', function () {
        $('html').css({
          overflow: '',
          'box-sizing': '',
          'padding-right': '',
        });
      });

      // Observe any changes inside the dialog's content and re-adjust size if needed
      const observer = new MutationObserver(adjustModal);
      $('.ui-dialog-content').each(function () {
        observer.observe(this, { childList: true, subtree: true });
      });
    },
  };
})(jQuery, Drupal);
