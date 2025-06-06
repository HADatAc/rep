/**
 * Drupal behavior for rendering a jsTree-based taxonomy/ontology tree.
 * - If drupalSettings.rep_tree.prefix is true, immediately call populateTree() using the searchValue,
 *   then expand the entire tree down to the final node.
 * - Otherwise, perform a normal jsTree search on the searchValue.
 * All comments are in English and include console.log statements for debugging.
 */
(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      // Ensure jsTree initializes only once for #tree-root.
      once('jstree-initialized', '#tree-root', context).forEach((element) => {
        // Cache common selectors.
        const $treeRoot         = $(element);
        const $selectNodeButton = $('#select-tree-node', context);
        const $searchInput      = $('#search_input', context);
        const $clearButton      = $('#clear-search', context);
        const $waitMessage      = $('#wait-message', context);
        const $resetButton      = $('#reset-tree', context);

        // Retrieve initial settings from drupalSettings.
        const initialSearchValue = drupalSettings.rep_tree.searchValue || '';
        const prefixIsActive     = !!drupalSettings.rep_tree.prefix; // true/false
        let hideDraft            = drupalSettings.rep_tree.hideDraft      || false;
        let hideDeprecated       = drupalSettings.rep_tree.hideDeprecated || false;
        let showLabel            = drupalSettings.rep_tree.showLabel      || 'label';

        // console.log('[tree] Initialization: searchValue =', initialSearchValue, ', prefixIsActive =', prefixIsActive);

        // If a searchValue is provided, populate the search input.
        if (initialSearchValue.length > 0) {
          $searchInput.val(initialSearchValue);
        }

        // Delay variables to wait until nodes load before showing the tree.
        let activityTimeout   = null;
        const activityDelay   = 1000;  // 1 second
        let initialSearchDone = false;

        /**
         * Sanitize a string to make a valid HTML ID.
         * Replaces all non-alphanumeric, underscore, hyphen characters with underscore.
         */
        function sanitizeForId(str) {
          return str.replace(/[^A-Za-z0-9_-]/g, '_');
        }

        /**
         * Base64-encode a Unicode string so it can be safely placed in a URL.
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
         * Given a full URI, return its namespace:localName form if any namespace matches.
         * Example: nameSpacesList = { "ABC": "http://abc.org/" }, uri = "http://abc.org/123"
         * returns "ABC:123". Otherwise returns the original URI.
         */
        function namespaceUri(uri) {
          const namespaces = drupalSettings.rep_tree.nameSpacesList || {};
          for (const abbrev in namespaces) {
            if (namespaces.hasOwnProperty(abbrev)) {
              const ns = namespaces[abbrev];
              if (abbrev && ns && uri.startsWith(ns)) {
                return abbrev + ":" + uri.slice(ns.length);
              }
            }
          }
          return uri;
        }

        /**
         * Given a full URI, return only its namespace prefix (e.g. "ABC:") if matched.
         * Example: nameSpacesList = { "ABC": "http://abc.org/" }, uri = "http://abc.org/123"
         * returns "ABC:". Otherwise returns the original URI.
         */
        function namespacePrefixUri(uri) {
          const namespaces = drupalSettings.rep_tree.nameSpacesList || {};
          for (const abbrev in namespaces) {
            if (namespaces.hasOwnProperty(abbrev)) {
              const ns = namespaces[abbrev];
              if (abbrev && ns && uri.startsWith(ns)) {
                return abbrev + ":";
              }
            }
          }
          return uri;
        }

        /**
         * Remove duplicate branch entries by their label from drupalSettings.rep_tree.branches.
         * Prevents root-level duplicates.
         */
        function getFilteredBranches() {
          const seenLabels = new Set();
          return (drupalSettings.rep_tree.branches || []).filter(branch => {
            if (seenLabels.has(branch.label)) {
              console.warn("[tree] Duplicate branch removed:", branch.label);
              return false;
            }
            seenLabels.add(branch.label);
            return true;
          });
        }

        /**
         * Compute the visible "text" for a node based on the current showLabel mode:
         * - "label": display item.label
         * - "labelprefix": prefix + label
         * - "uri": display full URI
         * - "uriprefix": namespaceUri form
         */
        function setNodeText(item) {
          const selectedMode = $('input[name="label_mode"]:checked').val() || 'label';
          switch (selectedMode) {
            case 'labelprefix':
              return namespacePrefixUri(item.uri) + item.label;
            case 'uri':
              return item.uri;
            case 'uriprefix':
              return namespaceUri(item.uri);
            default: // 'label'
              return item.label || item.uri;
          }
        }

        /**
         * Append a status suffix (e.g. "(Draft)", "(Deprecated)", "(Under Review)"),
         * including ownership info if applicable.
         */
        function setTitleSufix(item) {
          let suffix = '';
          const DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
          const DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
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
         * After nodes load or open, wait a moment, then hide the "please wait" message and show the tree.
         * Only runs once on initial load. In prefix mode, do not close the tree.
         */
        function resetActivityTimeout() {
          if (activityTimeout) {
            clearTimeout(activityTimeout);
          }
          activityTimeout = setTimeout(() => {
            if (!initialSearchDone) {
              // console.log("[tree] resetActivityTimeout: Hiding wait message and showing tree.");
              // Only close all if not in prefix mode
              if (!prefixIsActive) {
                $treeRoot.jstree('close_all');
              }
              $waitMessage.hide();
              $treeRoot.show();
              $searchInput.prop('disabled', false);
              initialSearchDone = true;
            }
          }, activityDelay);
        }

        /**
         * Attach event listeners to the jsTree instance:
         * - select_node: enable/disable "Select Node" button based on status & ownership.
         * - hover_node: show comment as tooltip if present.
         */
        function attachTreeEventListeners() {
          $treeRoot.off('select_node.jstree hover_node.jstree load_node.jstree open_node.jstree');
          // Placeholder if load_node or open_node need handling in future.
          $treeRoot.on('load_node.jstree open_node.jstree', function () {});

          $treeRoot.on('select_node.jstree', function (e, data) {
            const selectedNode = data.node.original;
            const DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
            const DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
            const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

            // console.log("[tree] Node selected:", selectedNode.uri, ", status =", selectedNode.hasStatus);

            // By default, disable the button and clear any previous data.
            $selectNodeButton.prop('disabled', true).addClass('disabled').removeData('selected-value');

            // If node is restricted by status and not owned by the current user, keep disabled.
            if (
              (selectedNode.hasStatus === DRAFT_URI     && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail) ||
              (selectedNode.hasStatus === DEPRECATED_URI && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail) ||
              (selectedNode.hasStatus === UNDERREVIEW_URI && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail)
            ) {
              // console.log("[tree] Node cannot be selected (restricted & not owned).");
            }
            // If node is deprecated but owned by user, still disable.
            else if (
              selectedNode.hasStatus === DEPRECATED_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              // console.log("[tree] Node is deprecated and owned by user → still cannot select.");
            }
            // If node is draft and owned by user, enable selection.
            else if (
              selectedNode.hasStatus === DRAFT_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              // console.log("[tree] Node is draft and owned by user → enabling selection.");
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data(
                  'selected-value',
                  selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace
                )
                .data('field-id', $('#tree-root').data('field-id'));
            }
            // If node is under review and owned by user, remain disabled.
            else if (
              selectedNode.hasStatus === UNDERREVIEW_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              // console.log("[tree] Node is under review and owned by user → still cannot select.");
            }
            // Otherwise (normal node or draft by owner), enable selection.
            else {
              // console.log("[tree] Node is normal or draft by user → enabling selection.");
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data(
                  'selected-value',
                  selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace
                )
                .data('field-id', $('#tree-root').data('field-id'));
            }

            // Build HTML to display node details.
            let html = `
              <strong>Label:</strong> ${selectedNode.label}<br/>
              <strong>URI:</strong>
              <a href="${drupalSettings.rep_tree.baseUrl}/rep/uri/${base64EncodeUnicode(selectedNode.uri)}" target="_new">
                ${selectedNode.uri}
              </a><br/>
            `;

            const webDocument = data.node.data.hasWebDocument || "";
            if (webDocument.trim().length > 0) {
              if (webDocument.trim().toLowerCase().startsWith("http")) {
                html += `
                  <strong>Web Document:</strong>
                  <a href="${webDocument}" target="_new">${webDocument}</a><br/>
                `;
              } else {
                const uriPart    = selectedNode.uri.includes('#/') ? selectedNode.uri.split('#/')[1] : selectedNode.uri;
                const downloadUrl = `${drupalSettings.rep_tree.baseUrl}/rep/webdocdownload/${encodeURIComponent(uriPart)}?doc=${encodeURIComponent(webDocument)}`;
                html += `
                  <strong>Web Document:</strong>
                  <a href="#" class="view-media-button" data-view-url="${downloadUrl}">${webDocument}</a><br/>
                `;
              }
            }

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

          // On hover, show the node’s comment as a tooltip if present.
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
         * Build a hierarchical subtree from an array of items returned by searchSuperClassEndPoint.
         * If forcedRootUri is provided, only items up to that URI are included and that URI becomes root.
         * Returns a single root node object (with nested children), or null if none found.
         *
         * @param {Array<Object>} items
         *   Array of objects from the searchSuperClassEndPoint, each having at least:
         *     { uri, label, superUri, hasStatus, hasSIRManagerEmail, comment, typeNamespace, hasWebDocument, hasImageUri }
         *
         * @param {string|null} forcedRootUri
         *   The URI to treat as the “top” of our subtree (typically drupalSettings.rep_tree.elementType).
         *   If this appears in `items`, we stop building any ancestors above and immediately return that node alone.
         *
         * @returns {Object|null}
         *   A single root node object (with nested children[] if not “short‐circuited”), or null if nothing matches.
         */
        function buildHierarchy(items, forcedRootUri = null) {
          // console.log("[tree] buildHierarchy called. items.length =", items.length, ", forcedRootUri =", forcedRootUri);

          // 1) Deduplicate by URI
          const uniqueItems = [];
          const seenUris = new Set();
          items.forEach(item => {
            if (!seenUris.has(item.uri)) {
              uniqueItems.push(item);
              seenUris.add(item.uri);
            }
          });
          // console.log("[tree] After deduplication: uniqueItems.length =", uniqueItems.length);

          // 2) If forcedRootUri is present in uniqueItems, and we want to "short‐circuit"
          //    as soon as we see that elementType, then build exactly that one node and return it.
          if (forcedRootUri) {
            const shortCircuitItem = uniqueItems.find(item => item.uri === forcedRootUri);
            if (shortCircuitItem) {
              // console.log("[tree] Short‐circuit: found forcedRootUri =", forcedRootUri, "– returning single node.");

              // Build nodeText + styling/skip logic exactly as we would below, but with no children.
              let nodeText = setNodeText(shortCircuitItem);
              let a_attr = {};
              const item = shortCircuitItem; // for brevity
              const DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
              const DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
              const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

              // Apply status suffix/a_attr exactly as usual
              if (item.hasStatus === DEPRECATED_URI) {
                if (!(hideDeprecated && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail)) {
                  nodeText += ' (Deprecated)';
                  if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                    nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                    a_attr = { style: 'font-style: italic; color:rgba(141, 141, 141, 0.77);' };
                  } else {
                    nodeText += ' (Another Person)';
                    a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                  }
                }
                // If `hideDeprecated && not owner`, we would skip--but short‐circuit wants to return anyway.
              }
              else if (item.hasStatus === DRAFT_URI) {
                if (!(hideDraft && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail)) {
                  nodeText += ' (Draft)';
                  if (drupalSettings.rep_tree.managerEmail === item.hasSIRManagerEmail) {
                    nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                    a_attr = { style: 'font-style: italic; color:rgba(153, 0, 0, 0.77);' };
                  } else {
                    nodeText += ' (Another Person)';
                    a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                  }
                }
              }
              else if (item.hasStatus === UNDERREVIEW_URI) {
                if (!(hideDraft && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail)) {
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
              // Construct exactly one node object, with no children:
              const singleNode = {
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
                  hasImageUri: item.hasImageUri
                },
                a_attr: a_attr,
                children: []  // no children because we terminate here
              };

              return singleNode;
            }
          }

          // 3) If we did not return early above, now we “slice” items up to forcedRootUri
          let filteredItems = uniqueItems;
          if (forcedRootUri) {
            const forcedIndex = uniqueItems.findIndex(item => item.uri === forcedRootUri);
            if (forcedIndex !== -1) {
              filteredItems = uniqueItems.slice(0, forcedIndex + 1);
              // console.log("[tree] After slicing up to forcedRootUri: filteredItems.length =", filteredItems.length);
            }
          }

          // 4) Build a map of URI → node object, marking “skip” for hidden statuses
          const nodeMap = new Map();
          filteredItems.forEach(item => {
            let nodeText = setNodeText(item);
            let a_attr = {};
            item.skip = false;

            const DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
            const DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
            const UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

            if (item.hasStatus === DEPRECATED_URI) {
              if (hideDeprecated && drupalSettings.rep_tree.managerEmail !== item.hasSIRManagerEmail) {
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
            }
            else if (item.hasStatus === DRAFT_URI) {
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
            }
            else if (item.hasStatus === UNDERREVIEW_URI) {
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
                hasImageUri: item.hasImageUri
              },
              a_attr: a_attr,
              children: []
            });
          });

          // 5) Link each node to its parent.  If forcedRootUri was provided, we treat filteredItems
          //    as a single linear “chain” (ignoring superUri)—otherwise we do a normal superUri link.
          let root = null;
          if (forcedRootUri) {
            // console.log("[tree] Linking chain nodes (forcedRootUri mode).");
            const chain = filteredItems.slice();
            chain.reverse(); // chain[0] is forcedRootUri as the top‐most in our subtree

            chain.forEach((item, index) => {
              const node = nodeMap.get(item.uri);
              if (!node) return;
              if (index === 0) {
                // The forced root of our subtree:
                root = node;
              } else {
                // Every subsequent item goes one level deeper:
                let current = root;
                while (current.children && current.children.length > 0) {
                  current = current.children[0];
                }
                current.children.push(node);
              }
            });
          } else {
            // console.log("[tree] Linking nodes by superUri.");
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
                // No superUri → potential root
                root = node;
              }
            });
          }

          // 6) If forcedRootUri exists in nodeMap, override root (in case chaining logic above didn’t do so).
          if (forcedRootUri && nodeMap.has(forcedRootUri)) {
            root = nodeMap.get(forcedRootUri);
          }
          // 7) If we still have no root, pick the first item without a parent in filteredItems.
          else if (!root) {
            for (const item of filteredItems) {
              if (!item.superUri) {
                root = nodeMap.get(item.uri);
                break;
              }
            }
          }

          // console.log("[tree] buildHierarchy returning root.uri =", root ? root.uri : null);
          return root;
        }

        /**
         * Given a URI, call the searchSuperClassEndPoint to retrieve that node’s ancestors,
         * build a subtree via buildHierarchy(...), then refresh jsTree to contain only that subtree.
         * After refreshing, expand all nodes and select the final node.
         */
function populateTree(uri) {
  // console.log("[tree] → populateTree called with URI =", uri);

  $.ajax({
    url: drupalSettings.rep_tree.searchSuperClassEndPoint,
    type: 'GET',
    data: { uri: encodeURI(uri) },
    dataType: 'json',
    success: function (data) {
      // console.log("[tree] → populateTree AJAX success: returned items.length =", data.length);

      const elementTypeUri = drupalSettings.rep_tree.elementType || null;
      const forcedRootUri  = elementTypeUri  || drupalSettings.rep_tree.superclass || null;
      // console.log("[tree]    elementTypeUri =", elementTypeUri);
      // console.log("[tree]    forcedRootUri  =", forcedRootUri);

      // 1) Monta a sub-árvore em memória
      const rootNode = buildHierarchy(data, forcedRootUri);
      const treeData = rootNode ? [rootNode] : [];
      // console.log("[tree]    rootNode URI =", rootNode ? rootNode.uri : null);

      // 2) Pega instância do jsTree
      const treeInstance = $treeRoot.jstree(true);
      if (!treeInstance) {
        console.warn("[tree]    jsTree instance not found in populateTree → aborting");
        return;
      }

      // 3) Substitui o core.data para conter só a sub-árvore
      // console.log("[tree]    Substituindo core.data e chamando refresh()");
      treeInstance.settings.core.data = treeData;

      // 4) Remove handlers antigos para evitar duplicação
      $treeRoot.off("refresh.jstree.select_last");
      // console.log("[tree]    Listener antigo de refresh.jstree.select_last removido");

      // 5) Adiciona listener para abrir tudo e selecionar o último nó, MAS sem usar callback do open_all
      $treeRoot.one("refresh.jstree.select_last", function () {
        // console.log("[tree] → Evento 'refresh.jstree.select_last' disparado");
        // Espera 50ms antes de acionar o open_all (só para garantir que o DOM do jsTree já foi inserido)
        setTimeout(function () {
          // console.log("[tree]    Dentro de setTimeout(50ms): chamando open_all('#')");
          treeInstance.open_all("#");
          // Não usamos callback do open_all. Em vez disso, aguardamos mais um pouco antes de selecionar.

          // Aguarda mais 150ms para ter certeza de que todos os li já estão no DOM
          setTimeout(function () {
            // console.log("[tree]    Após open_all, buscando todos os nós (flat) e selecionando último");
            const allNodes = treeInstance.get_json('#', { flat: true });
            // console.log("[tree]    allNodes (flat) recebido. total de nós =", allNodes.length);

            if (allNodes.length > 0) {
              const ultimo = allNodes[allNodes.length - 1];
              // console.log("[tree]    Nó mais profundo identificado →", ultimo.id);
              treeInstance.select_node(ultimo.id);
              // console.log("[tree]    select_node chamado para", ultimo.id);

              const $anchor = $('#' + $.escapeSelector(ultimo.id + '_anchor'));
              if ($anchor.length) {
                // console.log("[tree]    Fazendo scrollIntoView para o nó", ultimo.id);
                $anchor[0].scrollIntoView({ block: 'nearest', inline: 'nearest' });
              } else {
                console.warn("[tree]    Anchor do nó não encontrada para scroll:", ultimo.id + "_anchor");
              }
            } else {
              console.warn("[tree]    Nenhum nó encontrado em allNodes");
            }

            // Exibe a árvore e esconde a mensagem de "aguarde", se ainda não foi feito
            if (!initialSearchDone) {
              // console.log("[tree]    Hiding wait message e mostrando a árvore (initialSearchDone ainda false)");
              $waitMessage.hide();
              $treeRoot.show();
              $searchInput.prop("disabled", false);
              initialSearchDone = true;
            } else {
              // console.log("[tree]    initialSearchDone já era true, não altera estado de exibição");
            }
          }, 150); // <-- tempo extra para garantir o render completo de cada <li>
        }, 50); // <-- tempo inicial antes de chamar open_all
      });

      // 6) Dispara o refresh para reconstruir a árvore
      // console.log("[tree]    Chamando treeInstance.refresh() para reconstruir a árvore");
      treeInstance.refresh();
    },
    error: function () {
      console.error("[tree] → Error loading tree data for URI:", uri);
    }
  });
}



        /**
         * Destroy and recreate jsTree in its original “root-level only” state.
         * Clears search input, hides clear button, and loads only top-level branches.
         */
        function resetTree() {
          // console.log("[tree] resetTree called: destroying and re-initializing jsTree.");
          $searchInput.val('');
          $clearButton.hide();
          $treeRoot.jstree('destroy').empty();

          $treeRoot.jstree({
            core: {
              check_callback: true,
              data: function (node, cb) {
                if (node.id === '#') {
                  // Build root-level branches.
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
                        hasImageUri: branch.hasImageUri
                      },
                      icon: 'fas fa-folder',
                      hasStatus: branch.hasStatus,
                      hasSIRManagerEmail: branch.hasSIRManagerEmail,
                      hasWebDocument: branch.hasWebDocument,
                      hasImageUri: branch.hasImageUri,
                      children: true,
                      state: { opened: false }
                    };
                  });
                  // console.log("[tree] resetTree → root data length =", arr.length);
                  cb(arr);
                } else {
                  // Fetch children via AJAX.
                  // console.log("[tree] resetTree fetching children for", node.original.uri);
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      // console.log("[tree] resetTree AJAX success for children of", node.original.uri, "→ items.length =", data.length);
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
                              comment: item.comment || '',
                              typeNamespace: item.typeNamespace || '',
                              hasWebDocument: item.hasWebDocument,
                              hasImageUri: item.hasImageUri
                            },
                            icon: 'fas fa-file-alt',
                            hasStatus: item.hasStatus,
                            hasSIRManagerEmail: item.hasSIRManagerEmail,
                            hasWebDocument: item.hasWebDocument,
                            hasImageUri: item.hasImageUri,
                            children: true,
                            skip: false
                          };

                          const DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
                          const DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
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
                      // console.log("[tree] resetTree → children data length =", temp.length);
                      cb(temp);
                    },
                    error: function () {
                      console.error("[tree] resetTree: error fetching children for", node.original.uri);
                      cb([]);
                    }
                  });
                }
              }
            },
            plugins: ['search', 'wholerow'],
            search: {
              case_sensitive: false,
              show_only_matches: true,
              show_only_matches_children: true,
              search_callback: function (str, node) {
                const term = str.toLowerCase();
                if (node.text.toLowerCase().includes(term)) {
                  return true;
                }
                if (
                  node.data.typeNamespace &&
                  node.data.typeNamespace.toLowerCase().includes(term)
                ) {
                  return true;
                }
                return false;
              }
            }
          });

          $treeRoot.on('ready.jstree', function () {
            // console.log("[tree] resetTree ready.jstree event fired");
            attachTreeEventListeners();
            // After reset, ensure rendering-mode listener is still bound
            bindRenderingModeChange();
          });
        }

        /**
         * Configure autocomplete for the search input:
         * - On input length ≥ 3, call searchSubClassEndPoint
         * - Show suggestions
         * - On suggestion click, call populateTree(uri)
         */
        function setupAutocomplete(inputField) {
          $(inputField).on('input', function () {
            const searchTerm = $(this).val();
            if (searchTerm.length < 3) {
              $('#autocomplete-suggestions').hide();
              return;
            }
            // console.log("[tree] Autocomplete: fetching suggestions for", searchTerm);
            $.ajax({
              url: drupalSettings.rep_tree.searchSubClassEndPoint,
              type: 'GET',
              data: {
                keyword: searchTerm,
                superuri: drupalSettings.rep_tree.superclass,
                typeNameSpace: searchTerm
              },
              dataType: 'json',
              success: function (data) {
                // console.log("[tree] Autocomplete AJAX success: items.length =", data.length);
                const suggestions = data.map(item => ({
                  id: item.nodeId,
                  label: item.label || 'Unnamed Node',
                  uri: item.uri
                }));
                let suggestionBox = $('#autocomplete-suggestions');
                if (suggestionBox.length === 0) {
                  suggestionBox = $('<div id="autocomplete-suggestions"></div>').css({
                    position: 'absolute',
                    border: '1px solid #ccc',
                    background: '#fff',
                    zIndex: 1000,
                    maxHeight: '200px',
                    overflowY: 'auto'
                  }).appendTo('body');
                }
                suggestionBox.empty();
                suggestions.forEach(suggestion => {
                  const suggestionItem = $('<div class="suggestion-item"></div>')
                    .text(suggestion.label)
                    .css({ padding: '5px', cursor: 'pointer' });
                  suggestionItem.on('click', function () {
                    // console.log("[tree] Autocomplete: clicked suggestion, calling populateTree(", suggestion.uri, ")");
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
                  width: $(inputField).outerWidth()
                }).show();
              },
              error: function () {
                console.error("[tree] Autocomplete: error fetching suggestions.");
              }
            });
          });
          $(inputField).on('blur', function () {
            setTimeout(() => $('#autocomplete-suggestions').hide(), 200);
          });
        }

        /**
         * After jsTree is initialized, bind change handler on rendering-mode radios
         * so that whenever the user switches “Rendering mode” (label/labelprefix/uri/uriprefix),
         * we rename all existing nodes accordingly without reloading the tree.
         */
        function bindRenderingModeChange() {
          $('input[name="label_mode"]').on('change', function () {
            const novoModo = $(this).val();
            const tree = $treeRoot.jstree(true);
            if (!tree) {
              return;
            }

            // Get all nodes in a flat list
            const todosNodes = tree.get_json('#', { flat: true });
            todosNodes.forEach(node => {
              let novoTexto = '';
              switch (novoModo) {
                case 'labelprefix':
                  novoTexto = node.data.originalPrefixLabel;
                  break;
                case 'uri':
                  novoTexto = node.data.originalUri;
                  break;
                case 'uriprefix':
                  novoTexto = node.data.originalPrefixUri;
                  break;
                default: // 'label'
                  novoTexto = node.data.originalLabel;
                  break;
              }
              // Rename the node text in jsTree
              tree.rename_node(node.id, novoTexto);
            });
          });
        }

        /**
         * Initialize jsTree:
         * - Hide tree and show wait message
         * - Build root-level branches
         * - Attach listeners on ready
         * - Immediately either call populateTree(initialSearchValue) if prefixIsActive, or perform normal search
         */
        function initializeJstree() {
          // console.log("[tree] initializeJstree() called");
          $treeRoot.jstree({
            core: {
              check_callback: true,
              data: function (node, cb) {
                if (node.id === '#') {
                  // Build root-level branch nodes
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
                        hasImageUri: branch.hasImageUri
                      },
                      icon: 'fas fa-folder',
                      hasStatus: branch.hasStatus,
                      hasSIRManagerEmail: branch.hasSIRManagerEmail,
                      hasWebDocument: branch.hasWebDocument,
                      hasImageUri: branch.hasImageUri,
                      children: true
                    };
                  });
                  // console.log("[tree] jsTree root data length =", arr.length);
                  cb(arr);
                } else {
                  // Fetch children via AJAX
                  // console.log("[tree] jsTree fetching children for", node.original.uri);
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      // console.log("[tree] jsTree AJAX success for children of", node.original.uri, ": items.length =", data.length);
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
                              comment: item.comment || '',
                              typeNamespace: item.typeNamespace || '',
                              hasWebDocument: item.hasWebDocument,
                              hasImageUri: item.hasImageUri
                            },
                            icon: 'fas fa-file-alt',
                            hasStatus: item.hasStatus,
                            hasSIRManagerEmail: item.hasSIRManagerEmail,
                            hasWebDocument: item.hasWebDocument,
                            hasImageUri: item.hasImageUri,
                            children: true,
                            skip: false
                          };

                          const DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
                          const DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
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
                      // console.log("[tree] jsTree children data length =", temp.length);
                      cb(temp);
                    },
                    error: function () {
                      console.error("[tree] jsTree error fetching children for", node.original.uri);
                      cb([]);
                    }
                  });
                }
              }
            },
            plugins: ['search', 'wholerow'],
            search: {
              case_sensitive: false,
              show_only_matches: true,
              show_only_matches_children: true,
              search_callback: function (str, node) {
                const term = str.toLowerCase();
                if (node.text.toLowerCase().includes(term)) return true;
                if (
                  node.data.typeNamespace &&
                  node.data.typeNamespace.toLowerCase().includes(term)
                ) {
                  return true;
                }
                return false;
              }
            }
          });

          // When jsTree is ready, attach listeners, bind rendering-mode handler, and choose populateTree vs. search.
          $treeRoot.on('ready.jstree', function () {
            // console.log("[tree] ready.jstree event fired");
            attachTreeEventListeners();
            bindRenderingModeChange();
            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);
            resetActivityTimeout();

            // If prefixIsActive is true and we have an initialSearchValue, call populateTree().
            if (initialSearchValue.length > 0 && prefixIsActive) {
              // console.log("[tree] prefixIsActive = true → calling populateTree(", initialSearchValue, ")");
              populateTree(initialSearchValue);
            }
            // Otherwise, perform a normal jsTree search.
            else if (initialSearchValue.length > 0) {
              // console.log("[tree] prefixIsActive = false → performing normal search for", initialSearchValue);
              $treeRoot.jstree(true).search(initialSearchValue);
            }
          });

          // If initialSearchValue was set before tree ready, trigger search immediately.
          if (initialSearchValue.length > 0) {
            // console.log("[tree] initialSearchValue is set before ready → immediate search for", initialSearchValue);
            $treeRoot.jstree(true).search(initialSearchValue);
          }
        }

        // Bind the Reset Tree button so it actually calls resetTree().
        $resetButton.on('click', function (e) {
          e.preventDefault();
          // console.log("[tree] Reset button clicked → calling resetTree()");
          resetTree();
        });

        // If the tree container exists, initialize it.
        $treeRoot.hide();
        $waitMessage.show();
        $searchInput.prop('disabled', true);

        if ($treeRoot.length) {
          initializeJstree();
          setupAutocomplete('#search_input');

          // Pressing Enter in the search input triggers a jsTree search.
          $searchInput.on('keypress', function (e) {
            if (e.which === 13) {
              e.preventDefault();
              const term = $searchInput.val().trim();
              // console.log("[tree] Enter pressed in search input → search for", term);
              $treeRoot.jstree(true).search(term);
            }
          });
        } else {
          console.warn("[tree] Tree root not found. Initialization aborted.");
        }

        /**
         * Drupal behavior to fix any jQuery UI dialog so it remains centered and at a comfortable width.
         */
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

            // Adjust on dialog open.
            $(document).on('dialogopen', adjustModal);
            // Adjust on node selection in jsTree, in case content changes size.
            $(document).on('select_node.jstree', function () {
              setTimeout(adjustModal, 100);
            });
            // Restore HTML overflow settings when dialog closes.
            $(document).on('dialog:afterclose', function () {
              $('html').css({
                overflow: '',
                'box-sizing': '',
                'padding-right': '',
              });
            });

            // When “Select Node” is clicked, close the dialog and trigger change on original field.
            $selectNodeButton.on('click', function () {
              $('html').css({
                overflow: '',
                'box-sizing': '',
                'padding-right': '',
              });
              const fieldId = $(this).data('field-id');
              if (fieldId) {
                setTimeout(() => {
                  $('#' + fieldId).trigger('change');
                }, 100);
              }
            });

            // If user clicks the “X” on the dialog title bar, restore overflow.
            $(document).on('click', '.ui-dialog-titlebar-close', function () {
              $('html').css({
                overflow: '',
                'box-sizing': '',
                'padding-right': '',
              });
            });

            // Observe any changes inside the dialog and re-adjust if needed.
            const observer = new MutationObserver(adjustModal);
            $('.ui-dialog-content').each(function () {
              observer.observe(this, { childList: true, subtree: true });
            });
          }
        };
      });
    }
  };
})(jQuery, Drupal, drupalSettings);
