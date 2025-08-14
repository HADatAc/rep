(function ($) {
  // ===================================================================
  // 1) Patch jQuery UI Dialog widget to suppress “cannot call methods
  //    on dialog prior to initialization” errors.
  // ===================================================================
  var originalDialog = $.ui.dialog.prototype;
  $.widget("ui.dialog", $.ui.dialog, {
    _setOption: function (key, value) {
      // If this element does NOT have “ui-dialog” data (not initialized),
      // simply return without calling the original method.
      if (!this.element.data("ui-dialog")) {
        return;
      }
      // Otherwise, proceed normally.
      originalDialog._setOption.apply(this, arguments);
    }
  });
})(jQuery);


(function ($, Drupal, drupalSettings) {
  // =============================================================================
  // 2) Drupal.behaviors.tree
  //    - Responsible for initializing/destroying the jsTree each time the modal opens.
  //    - Handles “prefix” → “normal” fallback and shows “No results” when needed.
  //    - Handles “Hide Other User’s Draft?” and “Hide Other User’s Deprecated?” toggles.
  // =============================================================================

  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      // // console.log('[tree] → BEGIN Drupal.behaviors.tree.attach', { context: context, settings: settings });

      // ------------------------------------------------------
      // 2.1) Capture clicks on any .open-tree-modal trigger
      // ------------------------------------------------------
      $(context).find('.open-tree-modal').each(function () {
        var $trigger = $(this);
        // Ensure we only bind once per element by checking a data attribute
        if ($trigger.data('tree-capture-bound')) {
          return;
        }
        $trigger.data('tree-capture-bound', true);

        $trigger.on('click', function (e) {
          var passedValue = $trigger.data('search-value') || '';
          // // console.log('[tree] .open-tree-modal clicked → data-search-value =', passedValue);

          // Remove any old “No results” message
          $('#no-results-message').remove();

          // Ensure drupalSettings.rep_tree exists
          if (!drupalSettings.rep_tree) {
            drupalSettings.rep_tree = {};
          }
          drupalSettings.rep_tree.searchValue = passedValue;
          // Reset internal flag to force re-initialization of jsTree
          drupalSettings.rep_tree._initialSearchDone = false;

          // If a jsTree was already active, destroy it and hide its container
          var $treeRoot = $('#tree-root');
          if ($treeRoot.length && $treeRoot.data('tree-initialized')) {
            // // console.log('[tree] Clearing previous jsTree instance on #tree-root');
            $treeRoot.jstree('destroy');
            $treeRoot.removeData('tree-initialized');
            $treeRoot.hide();
          }

          // Force the #search_input value to the passedValue
          if ($('#search_input').length) {
            $('#search_input').val(passedValue);
            // // console.log('[tree] Forcing #search_input.val(', passedValue, ') on click.');
            // // console.log('[tree] Current prefix state:', drupalSettings.rep_tree.prefix);
          }
        });
      });

      // ------------------------------------------------------
      // 2.2) Initialize/destroy jsTree inside #tree-root
      // ------------------------------------------------------
      $(context).find('#tree-root').each(function () {
        var $treeRoot = $(this);

        // If we already initialized this #tree-root in this modal instance, skip
        if ($treeRoot.data('tree-initialized')) {
          // // console.log('[tree] jsTree already initialized on this #tree-root, skipping.');
          return;
        }
        $treeRoot.data('tree-initialized', true);
        // // console.log('[tree] → Initializing jsTree behavior for element:', $treeRoot);

        // ----------------------------
        // Helper Functions
        // ----------------------------
        function sanitizeForId(str) {
          // Replace any character that is not A-Z, a-z, 0-9, underscore, or hyphen with underscore
          return str.replace(/[^A-Za-z0-9_-]/g, '_');
        }

        function base64EncodeUnicode(str) {
          // Encode a Unicode string as UTF-8 and then to base64
          var utf8Bytes = new TextEncoder().encode(str);
          var asciiStr = '';
          for (var i = 0; i < utf8Bytes.length; i++) {
            asciiStr += String.fromCharCode(utf8Bytes[i]);
          }
          return btoa(asciiStr);
        }

        function namespaceUri(uri) {
          // Given a full URI, return a prefixed form if it matches a known namespace
          var namespaces = (drupalSettings.rep_tree && drupalSettings.rep_tree.nameSpacesList) || {};
          for (var abbrev in namespaces) {
            if (!namespaces.hasOwnProperty(abbrev)) continue;
            var ns = namespaces[abbrev];
            if (abbrev && ns && uri.startsWith(ns)) {
              return abbrev + ":" + uri.slice(ns.length);
            }
          }
          return uri;
        }

        function namespacePrefixUri(uri) {
          // Given a full URI, return just the prefix if it matches a known namespace
          var namespaces = (drupalSettings.rep_tree && drupalSettings.rep_tree.nameSpacesList) || {};
          for (var abbrev2 in namespaces) {
            if (!namespaces.hasOwnProperty(abbrev2)) continue;
            var ns2 = namespaces[abbrev2];
            if (abbrev2 && ns2 && uri.startsWith(ns2)) {
              return abbrev2 + ":";
            }
          }
          return uri;
        }

        // ----------------------------
        // getFilteredBranches()
        //   Remove duplicate branches by label.
        // ----------------------------
        function getFilteredBranches() {
          var seenLabels = new Set();
          var branches = (drupalSettings.rep_tree && drupalSettings.rep_tree.branches) || [];
          return branches.filter(function (branch) {
            if (seenLabels.has(branch.label)) {
              console.warn("[tree] Duplicate branch removed:", branch.label);
              return false;
            }
            seenLabels.add(branch.label);
            return true;
          });
        }

        // ----------------------------
        // setNodeText(item)
        //   Determine the display text for a node based on the “label_mode” radio.
        // ----------------------------
        function setNodeText(item) {
          var selectedMode = $('input[name="label_mode"]:checked').val() || 'label';
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

        // ----------------------------
        // setTitleSuffix(item)
        //   Append “(Draft)”, “(Deprecated)”, etc., and indicate ownership.
        // ----------------------------
        function setTitleSuffix(item) {
          var suffix = '';
          var DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
          var DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
          var UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

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

        // ----------------------------
        // expandPrefix(maybePrefixed)
        //   Convert “obo:PATO_0002370” → full URI using drupalSettings.rep_tree.nameSpacesList
        // ----------------------------
        function expandPrefix(maybePrefixed) {
          // If it already looks like a URI, return as-is
          if (/^https?:\/\//.test(maybePrefixed)) {
            return maybePrefixed;
          }
          var parts = maybePrefixed.split(':', 2);
          if (parts.length === 2) {
            var prefix = parts[0];
            var local = parts[1];
            var nsList = (drupalSettings.rep_tree && drupalSettings.rep_tree.nameSpacesList) || {};
            if (nsList[prefix]) {
              return nsList[prefix] + local;
            }
          }
          return maybePrefixed;
        }

        // --------------------------------------------------------------
        // Convert the prefixed searchValue into a full URI:
        // --------------------------------------------------------------
        var rawSearchValue = (drupalSettings.rep_tree && drupalSettings.rep_tree.searchValue) || '';
        var initialSearchValue = expandPrefix(rawSearchValue);
        if (!drupalSettings.rep_tree) {
          drupalSettings.rep_tree = {};
        }
        drupalSettings.rep_tree.searchValue = initialSearchValue;
        // // console.log('[tree] Initialization: initialSearchValue =', initialSearchValue);

        // --------------------------------------------------------------
        // Initial flags:
        // --------------------------------------------------------------
        var prefixIsActive = !!(drupalSettings.rep_tree && drupalSettings.rep_tree.prefix);
        var hideDraft = (drupalSettings.rep_tree && drupalSettings.rep_tree.hideDraft) || false;
        var hideDeprecated = (drupalSettings.rep_tree && drupalSettings.rep_tree.hideDeprecated) || false;
        var showLabel = (drupalSettings.rep_tree && drupalSettings.rep_tree.showLabel) || 'label';
        // // console.log('[tree] Initialization: prefixIsActive =', prefixIsActive,
        //             ', hideDraft =', hideDraft,
        //             ', hideDeprecated =', hideDeprecated,
        //             ', showLabel =', showLabel);

        if (initialSearchValue.length > 0) {
          // console.log('[tree] Setting initialSearchValue in #search_input →', initialSearchValue);
          $('#search_input').val(initialSearchValue);
        }

        // --------------------------------------------------------------
        // Bind “Hide Other User’s Draft?” and “Hide Other User’s Deprecated?” toggles:
        // --------------------------------------------------------------
        // HTML must contain:
        //   <input type="checkbox" id="toggle-draft" /> Hide Other User’s Draft?
        //   <input type="checkbox" id="toggle-deprecated" /> Hide Other User’s Deprecated?
        //
        // Before binding, remove any previous handlers to avoid duplicates.

        $('#toggle-draft', context).off('change').on('change', function () {
          hideDraft = $(this).is(':checked');
          // console.log('[tree] toggle-draft changed → hideDraft =', hideDraft);
          resetTree();
        });

        $('#toggle-deprecated', context).off('change').on('change', function () {
          hideDeprecated = $(this).is(':checked');
          // console.log('[tree] toggle-deprecated changed → hideDeprecated =', hideDeprecated);
          resetTree();
        });

        // --------------------------------------------------------------
        // “Wait” variables for initial loading:
        // --------------------------------------------------------------
        var activityTimeout = null;
        var activityDelay = 1000; // 1s
        var initialSearchDone = false;

        // --------------------------------------------------------------
        // resetActivityTimeout(): hide “wait-message” and show tree
        // --------------------------------------------------------------
        function resetActivityTimeout() {
          if (activityTimeout) {
            clearTimeout(activityTimeout);
          }
          activityTimeout = setTimeout(function () {
            if (!initialSearchDone) {
              // console.log("[tree] resetActivityTimeout: Hiding wait message and showing tree.");
              if (!prefixIsActive) {
                $treeRoot.jstree('close_all');
              }
              $('#wait-message').hide();
              $treeRoot.show();
              $('#search_input').prop('disabled', false);
              initialSearchDone = true;
            }
          }, activityDelay);
        }

        // --------------------------------------------------------------
        // attachTreeEventListeners(): enable/disable “Select” button and show tooltip
        // --------------------------------------------------------------
        function attachTreeEventListeners() {
          $treeRoot.off('select_node.jstree hover_node.jstree load_node.jstree open_node.jstree');

          $treeRoot.on('load_node.jstree open_node.jstree', function () {
            // We could handle custom logic here if needed
          });

          // When a node is selected:
          $treeRoot.on('select_node.jstree', function (e, data) {
            var selectedNode = data.node.original;
            var DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
            var DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
            var UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

            // console.log("[tree] Node selected:", selectedNode.uri, ", status =", selectedNode.hasStatus);

            var $selectNodeButton = $('#select-tree-node');
            $selectNodeButton.prop('disabled', true)
                             .addClass('disabled')
                             .removeData('selected-value');

            // Rule 1: Node is restricted (Draft/Deprecated/UnderReview) and not owned by current user → keep disabled
            if (
              (selectedNode.hasStatus === DRAFT_URI     && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail) ||
              (selectedNode.hasStatus === DEPRECATED_URI && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail) ||
              (selectedNode.hasStatus === UNDERREVIEW_URI && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail)
            ) {
              // console.log("[tree] Node cannot be selected (restricted & not owned).");
            }
            // Rule 2: Node is Deprecated and is owned by current user → still disabled
            else if (
              selectedNode.hasStatus === DEPRECATED_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              // console.log("[tree] Node is deprecated and owned by user → still cannot select.");
            }
            // Rule 3: Node is Draft and owned by current user → enable selection
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
            // Rule 4: Node is UnderReview and is owned by current user → still disabled
            else if (
              selectedNode.hasStatus === UNDERREVIEW_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              // console.log("[tree] Node is under review and owned by user → still cannot select.");
            }
            // Rule 5: Otherwise (normal or Draft by user) → enable
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

            // Build HTML for node details (Label, URI, Web Document, Description)
            var html = ''
              + '<strong>Label:</strong> ' + selectedNode.label + '<br/>'
              + '<strong>URI:</strong> '
              + '<a href="' + drupalSettings.rep_tree.baseUrl + '/rep/uri/' + base64EncodeUnicode(selectedNode.uri) + '" target="_new">'
              + selectedNode.uri + '</a><br/>';

            var webDocument = data.node.data.hasWebDocument || "";
            if (webDocument.trim().length > 0) {
              if (webDocument.trim().toLowerCase().startsWith("http")) {
                html += ''
                  + '<strong>Web Document:</strong> '
                  + '<a href="' + webDocument + '" target="_new">' + webDocument + '</a><br/>';
              } else {
                var uriPart = selectedNode.uri.includes('#/') ? selectedNode.uri.split('#/')[1] : selectedNode.uri;
                var downloadUrl = drupalSettings.rep_tree.baseUrl
                  + '/rep/webdocdownload/' + encodeURIComponent(uriPart)
                  + '?doc=' + encodeURIComponent(webDocument);
                html += ''
                  + '<strong>Web Document:</strong> '
                  + '<a href="#" class="view-media-button" data-view-url="' + downloadUrl + '">'
                  + webDocument + '</a><br/>';
              }
            }

            var comment = data.node.data.comment || "";
            if (comment.trim().length > 0) {
              html += '<br/><strong>Description:</strong><br/>' + comment;
            }

            $('#node-comment-display').html(html).show();
          });

          // Hover to show tooltip with comment
          $treeRoot.on('hover_node.jstree', function (e, data) {
            var comment = data.node.data.comment || '';
            var nodeAnchor = $('#' + $.escapeSelector(data.node.id + '_anchor'));
            if (comment) {
              nodeAnchor.attr('title', comment);
            } else {
              nodeAnchor.removeAttr('title');
            }
          });
        }

        // --------------------------------------------------------------
        // buildHierarchy(items, forcedRootUri)
        //   - Builds an ancestry tree (or a single forced node).
        // --------------------------------------------------------------
        function buildHierarchy(items, forcedRootUri) {
          // console.log("[tree] buildHierarchy called. items.length =", items.length, ", forcedRootUri =", forcedRootUri);

          // 1) Remove duplicates by URI
          var uniqueItems = [];
          var seenUris = new Set();
          items.forEach(function (item) {
            if (!seenUris.has(item.uri)) {
              uniqueItems.push(item);
              seenUris.add(item.uri);
            }
          });
          // console.log("[tree] After deduplication: uniqueItems.length =", uniqueItems.length);

          // 2) If forcedRootUri appears in uniqueItems, return just that node as a single root
          if (forcedRootUri) {
            var shortCircuitItem = uniqueItems.find(function (item) {
              return item.uri === forcedRootUri;
            });
            if (shortCircuitItem) {
              // console.log("[tree] Short‐circuit: found forcedRootUri =", forcedRootUri, "– returning single node.");

              var itemSC = shortCircuitItem;
              var nodeText = setNodeText(itemSC);
              var a_attr = {};
              var DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
              var DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
              var UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

              // Apply hideDeprecated/hideDraft logic even for the single node
              if (itemSC.hasStatus === DEPRECATED_URI) {
                if (!(hideDeprecated && drupalSettings.rep_tree.managerEmail !== itemSC.hasSIRManagerEmail)) {
                  nodeText += ' (Deprecated)';
                  if (drupalSettings.rep_tree.managerEmail === itemSC.hasSIRManagerEmail) {
                    nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                    a_attr = { style: 'font-style: italic; color:rgba(141, 141, 141, 0.77);' };
                  } else {
                    nodeText += ' (Another Person)';
                    a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                  }
                }
              }
              else if (itemSC.hasStatus === DRAFT_URI) {
                if (!(hideDraft && drupalSettings.rep_tree.managerEmail !== itemSC.hasSIRManagerEmail)) {
                  nodeText += ' (Draft)';
                  if (drupalSettings.rep_tree.managerEmail === itemSC.hasSIRManagerEmail) {
                    nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                    a_attr = { style: 'font-style: italic; color:rgba(153, 0, 0, 0.77);' };
                  } else {
                    nodeText += ' (Another Person)';
                    a_attr = { style: 'font-style: italic; color:rgba(109, 18, 112, 0.77);' };
                  }
                }
              }
              else if (itemSC.hasStatus === UNDERREVIEW_URI) {
                if (!(hideDraft && drupalSettings.rep_tree.managerEmail !== itemSC.hasSIRManagerEmail)) {
                  nodeText += ' (Under Review)';
                  if (drupalSettings.rep_tree.managerEmail === itemSC.hasSIRManagerEmail) {
                    nodeText += ' (' + drupalSettings.rep_tree.username + ')';
                    a_attr = { style: 'font-style: italic; color:rgb(172, 164, 164);' };
                  } else {
                    nodeText += ' (Another Person)';
                    a_attr = { style: 'font-style: italic; color:rgba(206, 103, 19, 0.77);' };
                  }
                }
              }

              var prefixed = namespacePrefixUri(itemSC.uri);
              var singleNode = {
                id: itemSC.uri,
                text: nodeText,
                label: itemSC.label,
                uri: itemSC.uri,
                superUri: itemSC.superUri || null,
                typeNamespace: itemSC.typeNamespace || '',
                icon: 'fas fa-file-alt',
                hasStatus: itemSC.hasStatus,
                hasSIRManagerEmail: itemSC.hasSIRManagerEmail,
                hasWebDocument: itemSC.hasWebDocument,
                hasImageUri: itemSC.hasImageUri,
                data: {
                  originalLabel: itemSC.label + setTitleSuffix(itemSC),
                  originalPrefixLabel: namespacePrefixUri(itemSC.uri) + itemSC.label + setTitleSuffix(itemSC),
                  originalUri: itemSC.uri + setTitleSuffix(itemSC),
                  originalPrefixUri: namespaceUri(itemSC.uri) + setTitleSuffix(itemSC),
                  prefix: prefixed,
                  comment: itemSC.comment || '',
                  typeNamespace: itemSC.typeNamespace || '',
                  hasWebDocument: itemSC.hasWebDocument,
                  hasImageUri: itemSC.hasImageUri
                },
                a_attr: a_attr,
                children: []
              };

              return singleNode;
            }
          }

          // 3) If not short‐circuited, filter up to forcedRootUri (if provided)
          var filteredItems = uniqueItems;
          if (forcedRootUri) {
            var forcedIndex = uniqueItems.findIndex(function (item) {
              return item.uri === forcedRootUri;
            });
            if (forcedIndex !== -1) {
              filteredItems = uniqueItems.slice(0, forcedIndex + 1);
              // console.log("[tree] After slicing up to forcedRootUri: filteredItems.length =", filteredItems.length);
            }
          }

          // 4) Create a Map URI→node, marking skip for hidden statuses
          var nodeMap = new Map();
          filteredItems.forEach(function (item) {
            var nodeText = setNodeText(item);
            var a_attr = {};
            item.skip = false;

            var DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
            var DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
            var UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

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

            var prefixed = namespacePrefixUri(item.uri);
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
                originalLabel: item.label + setTitleSuffix(item),
                originalPrefixLabel: namespacePrefixUri(item.uri) + item.label + setTitleSuffix(item),
                originalUri: item.uri + setTitleSuffix(item),
                originalPrefixUri: namespaceUri(item.uri) + setTitleSuffix(item),
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

          // 5) Link each node to its parent (forced chain or normal superUri)
          var root = null;
          if (forcedRootUri) {
            // console.log("[tree] Linking chain nodes (forcedRootUri mode).");
            var chain = filteredItems.slice();
            chain.reverse(); // The first after reverse is forcedRootUri

            chain.forEach(function (item, index) {
              var node = nodeMap.get(item.uri);
              if (!node) return;
              if (index === 0) {
                root = node;
              } else {
                var current = root;
                while (current.children && current.children.length > 0) {
                  current = current.children[0];
                }
                current.children.push(node);
              }
            });
          } else {
            // console.log("[tree] Linking nodes by superUri.");
            filteredItems.forEach(function (item) {
              if (item.skip) return;
              var node = nodeMap.get(item.uri);
              if (!node) return;
              if (item.superUri && !item.skip) {
                var parent = nodeMap.get(item.superUri);
                if (parent && !parent.skip) {
                  parent.children.push(node);
                }
              } else {
                root = node;
              }
            });
          }

          // 6) If forcedRootUri exists, override root
          if (forcedRootUri && nodeMap.has(forcedRootUri)) {
            root = nodeMap.get(forcedRootUri);
          }
          // 7) If still no root, pick the first item without a superUri
          else if (!root) {
            for (var idx = 0; idx < filteredItems.length; idx++) {
              var it = filteredItems[idx];
              if (!it.superUri) {
                root = nodeMap.get(it.uri);
                break;
              }
            }
          }

          // console.log("[tree] buildHierarchy returning root.uri =", root ? root.uri : null);
          return root;
        }

        // --------------------------------------------------------------
        // populateTree(uri)
        //    - Calls searchSuperClassEndPoint, builds the hierarchy, and populates jsTree.
        // --------------------------------------------------------------
        function populateTree(uri) {
          // console.log("[tree] → populateTree called with URI =", uri);

          $.ajax({
            url: drupalSettings.rep_tree.searchSuperClassEndPoint,
            type: 'GET',
            data: { uri: encodeURI(uri) },
            dataType: 'json',
            success: function (data) {
              // console.log("[tree] → populateTree AJAX success; items.length =", data.length);

              $('#no-results-message').remove();

              var elementTypeUri = drupalSettings.rep_tree.elementType || null;
              var forcedRootUri = elementTypeUri || drupalSettings.rep_tree.superclass || null;
              var rootNode = buildHierarchy(data, forcedRootUri);

              // —— Case A: no results
              if (data.length === 0) {
                var $message = $(
                  '<div id="no-results-message" style="color: #b00; margin-bottom: 10px;">' +
                  'No results found for “' + uri + '”.</div>'
                );
                $treeRoot.before($message);

                if (prefixIsActive && !rootNode) {
                  console.warn("[tree] → prefixIsActive && no rootNode (null). Falling back to normal tree.");

                  $treeRoot.jstree('destroy');
                  $treeRoot.removeData('tree-initialized');
                  resetTree();
                  return;
                }

                $treeRoot.hide();
                $('#wait-message').hide();
                return;
              }

              // —— Case B: results exist or prefixIsActive=false
              var treeData = rootNode ? [rootNode] : [];
              var treeInstance = $treeRoot.jstree(true);
              if (!treeInstance) {
                console.warn("[tree] → jsTree instance not found (it may have been destroyed). Aborting populateTree.");
                return;
              }

              treeInstance.settings.core.data = treeData;
              $treeRoot.off("refresh.jstree.select_last");

              $treeRoot.one("refresh.jstree.select_last", function () {
                setTimeout(function () {
                  treeInstance.open_all("#");

                  setTimeout(function () {
                    var allNodes = treeInstance.get_json('#', { flat: true });
                    if (allNodes.length > 0) {
                      var lastNode = allNodes[allNodes.length - 1];
                      treeInstance.select_node(lastNode.id);
                      var $anchor = $('#' + $.escapeSelector(lastNode.id + '_anchor'));
                      if ($anchor.length) {
                        $anchor[0].scrollIntoView({ block: 'nearest', inline: 'nearest' });
                      }
                    }
                    if (!initialSearchDone) {
                      $('#wait-message').hide();
                      $treeRoot.show();
                      $('#search_input').prop("disabled", false);
                      initialSearchDone = true;
                    }
                  }, 150);
                }, 50);
              });

              // console.log("[tree] → Calling treeInstance.refresh() with new data.");
              treeInstance.refresh();
            },
            error: function () {
              console.error("[tree] → populateTree: error fetching data for URI", uri);

              $('#no-results-message').remove();
              var $errorMsg = $(
                '<div id="no-results-message" style="color: #b00; margin-bottom: 10px;">' +
                'Error loading data. Please try again.</div>'
              );
              $treeRoot.before($errorMsg);
            }
          });
        }

        // --------------------------------------------------------------
        // resetTree()
        //    - Destroys/recreates jsTree in “top-level only” state.
        // --------------------------------------------------------------
        function resetTree() {
          // console.log("[tree] resetTree called: destroying and re-initializing jsTree.");
          $('#search_input').val('');
          $('#clear-search').hide();
          $treeRoot.jstree('destroy').empty();

          $treeRoot.jstree({
            core: {
              check_callback: true,
              data: function (node, cb) {
                if (node.id === '#') {
                  // Top-level (branches)
                  var branches = getFilteredBranches();
                  var arr = branches.map(function (branch) {
                    var prefixed = namespacePrefixUri(branch.uri);
                    return {
                      id: branch.id,
                      text: setNodeText(branch),
                      label: branch.label,
                      uri: branch.uri,
                      typeNamespace: branch.typeNamespace || '',
                      data: {
                        originalLabel: branch.label + setTitleSuffix(branch),
                        originalPrefixLabel: namespacePrefixUri(branch.uri) + branch.label + setTitleSuffix(branch),
                        originalUri: branch.uri + setTitleSuffix(branch),
                        originalPrefixUri: namespaceUri(branch.uri) + setTitleSuffix(branch),
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
                  // Fetch children via AJAX
                  // console.log("[tree] resetTree fetching children for", node.original.uri);
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      // console.log("[tree] resetTree AJAX success for children of", node.original.uri, "→ items.length =", data.length);
                      var temp = [];
                      var seen = new Set();
                      data.forEach(function (item) {
                        var normalizedUri = item.uri.trim().toLowerCase();
                        if (!seen.has(normalizedUri)) {
                          seen.add(normalizedUri);

                          var prefixed = namespacePrefixUri(item.uri);
                          var nodeObj = {
                            id: 'node_' + sanitizeForId(item.uri),
                            text: setNodeText(item),
                            label: item.label,
                            uri: item.uri,
                            typeNamespace: item.typeNamespace || '',
                            comment: item.comment || '',
                            data: {
                              originalLabel: item.label + setTitleSuffix(item),
                              originalPrefixLabel: namespacePrefixUri(item.uri) + item.label + setTitleSuffix(item),
                              originalUri: item.uri + setTitleSuffix(item),
                              originalPrefixUri: namespaceUri(item.uri) + setTitleSuffix(item),
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

                          // monta um ID único combinando pai + filho
                          var parentIdSafe = sanitizeForId(node.id);
                          var childIdSafe  = sanitizeForId(item.uri);
                          nodeObj.id = 'node_' + parentIdSafe + '_' + childIdSafe;

                          // preserve a URI real em data
                          nodeObj.data = nodeObj.data || {};
                          nodeObj.data.realUri = item.uri;

                          var DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
                          var DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
                          var UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

                          // Apply hideDeprecated/hideDraft to each child node
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
            plugins: ['search', 'wholerow', 'sort'],
            sort: function (a, b) {
              var ta = this.get_node(a).text.toLowerCase();
              var tb = this.get_node(b).text.toLowerCase();
              return ta > tb ? 1 : (ta < tb ? -1 : 0);
            },
            search: {
              case_sensitive: false,
              show_only_matches: true,
              show_only_matches_children: true,
              search_callback: function (str, node) {
                var term = str.toLowerCase();
                if (node.text.toLowerCase().includes(term)) return true;
                if (node.data.typeNamespace && node.data.typeNamespace.toLowerCase().includes(term)) {
                  return true;
                }
                return false;
              }
            }
          });

          $treeRoot.on('ready.jstree', function () {
            // console.log("[tree] resetTree ready.jstree event fired");
            attachTreeEventListeners();
            bindRenderingModeChange();
          });
        }

        // --------------------------------------------------------------
        // setupAutocomplete(inputField)
        //   - Fetches suggestions from searchSubClassEndPoint.
        //   - Displays suggestion list and handles clicks.
        // --------------------------------------------------------------
        function setupAutocomplete(inputField) {
          // console.log("[tree] setupAutocomplete called for", inputField);
          $(inputField).on('input', function () {
            var searchTerm = $(this).val();
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
                var suggestions = data.map(function (item) {
                  return {
                    id: item.nodeId,
                    label: item.label || 'Unnamed Node',
                    uri: item.uri
                  };
                });
                var suggestionBox = $('#autocomplete-suggestions');
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
                suggestions.forEach(function (suggestion) {
                  var suggestionItem = $('<div class="suggestion-item"></div>')
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
                var offset = $(inputField).offset();
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
            setTimeout(function () {
              $('#autocomplete-suggestions').hide();
            }, 200);
          });
        }

        // --------------------------------------------------------------
        // bindRenderingModeChange()
        //   - When the user changes the radio “label/labelprefix/uri/uriprefix”,
        //     update all nodes without reloading.
        // --------------------------------------------------------------
        function bindRenderingModeChange() {
          // console.log("[tree] bindRenderingModeChange called");
          $('input[name="label_mode"]').on('change', function () {
            var newMode = $(this).val();
            // console.log("[tree] Rendering mode changed to:", newMode);
            var tree = $treeRoot.jstree(true);
            if (!tree) {
              return;
            }

            var allNodes = tree.get_json('#', { flat: true });
            allNodes.forEach(function (node) {
              var newText = '';
              switch (newMode) {
                case 'labelprefix':
                  newText = node.data.originalPrefixLabel;
                  break;
                case 'uri':
                  newText = node.data.originalUri;
                  break;
                case 'uriprefix':
                  newText = node.data.originalPrefixUri;
                  break;
                default: // 'label'
                  newText = node.data.originalLabel;
                  break;
              }
              tree.rename_node(node.id, newText);
            });
          });
        }

        // --------------------------------------------------------------
        // initializeJstree()
        //   - Builds the initial jsTree, shows “wait-message”, then either
        //     calls populateTree(initialSearchValue) if prefix is active,
        //     or does a normal search.
        // --------------------------------------------------------------
        function initializeJstree() {
          // console.log("[tree] initializeJstree() called");
          $treeRoot.jstree({
            core: {
              check_callback: true,
              data: function (node, cb) {
                if (node.id === '#') {
                  var branches = getFilteredBranches();
                  var arr = branches.map(function (branch) {
                    var prefixed = namespacePrefixUri(branch.uri);
                    return {
                      id: branch.id,
                      text: setNodeText(branch),
                      label: branch.label,
                      uri: branch.uri,
                      typeNamespace: branch.typeNamespace || '',
                      data: {
                        originalLabel: branch.label + setTitleSuffix(branch),
                        originalPrefixLabel: namespacePrefixUri(branch.uri) + branch.label + setTitleSuffix(branch),
                        originalUri: branch.uri + setTitleSuffix(branch),
                        originalPrefixUri: namespaceUri(branch.uri) + setTitleSuffix(branch),
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
                  // console.log("[tree] jsTree fetching children for", node.original.uri);
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      // console.log("[tree] jsTree AJAX success for children of", node.original.uri, ": items.length =", data.length);
                      var temp = [];
                      var seen = new Set();
                      data.forEach(function (item) {
                        var normalizedUri = item.uri.trim().toLowerCase();
                        if (!seen.has(normalizedUri)) {
                          seen.add(normalizedUri);
                          var prefixed = namespacePrefixUri(item.uri);
                          var parentIdSafe = sanitizeForId(node.id);
                          var childIdSafe  = sanitizeForId(item.uri);
                          var nodeObj = {
                            id: 'node_' + parentIdSafe + '_' + childIdSafe,
                            text: setNodeText(item),
                            label: item.label,
                            uri: item.uri,
                            typeNamespace: item.typeNamespace || '',
                            comment: item.comment || '',
                            data: {
                              originalLabel: item.label + setTitleSuffix(item),
                              originalPrefixLabel: namespacePrefixUri(item.uri) + item.label + setTitleSuffix(item),
                              originalUri: item.uri + setTitleSuffix(item),
                              originalPrefixUri: namespaceUri(item.uri) + setTitleSuffix(item),
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

                          var DRAFT_URI = 'http://hadatac.org/ont/vstoi#Draft';
                          var DEPRECATED_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
                          var UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

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

                          nodeObj.data = nodeObj.data || {};
                          nodeObj.data.realUri = item.uri;

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
            plugins: ['search', 'wholerow', 'sort'],
            sort: function (a, b) {
              var ta = this.get_node(a).text.toLowerCase();
              var tb = this.get_node(b).text.toLowerCase();
              return ta > tb ? 1 : (ta < tb ? -1 : 0);
            },
            search: {
              case_sensitive: false,
              show_only_matches: true,
              show_only_matches_children: true,
              search_callback: function (str, node) {
                var term = str.toLowerCase();
                if (node.text.toLowerCase().includes(term)) return true;
                if (node.data.typeNamespace && node.data.typeNamespace.toLowerCase().includes(term)) {
                  return true;
                }
                return false;
              }
            }
          });

          // Once jsTree is ready, attach listeners and decide populateTree vs. search
          $treeRoot.on('ready.jstree', function () {
            // console.log("[tree] ready.jstree event fired");
            attachTreeEventListeners();
            bindRenderingModeChange();
            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);
            resetActivityTimeout();

            if (initialSearchValue.length > 0 && prefixIsActive) {
              // console.log("[tree] prefixIsActive = true → calling populateTree(", initialSearchValue, ")");
              populateTree(initialSearchValue);
            }
            else if (initialSearchValue.length > 0) {
              // console.log("[tree] prefixIsActive = false → performing normal search for", initialSearchValue);
              $treeRoot.jstree(true).search(initialSearchValue);
            }
          });

          // If a search value is already set before ready, perform search immediately
          if (initialSearchValue.length > 0) {
            // console.log("[tree] initialSearchValue is set before ready → immediate search for", initialSearchValue);
            $treeRoot.jstree(true).search(initialSearchValue);
          }
        }

        // --------------------------------------------------------------
        // Bind “Reset Tree” button
        // --------------------------------------------------------------
        $('#reset-tree').on('click', function (e) {
          e.preventDefault();
          // console.log("[tree] Reset button clicked → calling resetTree()");
          resetTree();
        });

        // Hide the tree and show the “wait message” at first
        $treeRoot.hide();
        $('#wait-message').show();
        $('#search_input').prop('disabled', true);

        if ($treeRoot.length) {
          initializeJstree();
          setupAutocomplete('#search_input');

          // Pressing Enter in the search field triggers a search
          $('#search_input').on('keypress', function (e) {
            if (e.which === 13) {
              e.preventDefault();
              var term = $(this).val().trim();
              // console.log("[tree] Enter pressed in search_input → searching for", term);
              $treeRoot.jstree(true).search(term);
            }
          });
        } else {
          console.warn("[tree] Tree root not found. Initialization aborted.");
        }
      }); // end of each #tree-root
    }
  };

  // =============================================================================
  // 3) Drupal.behaviors.modalFix
  //    - Restores <html> overflow and destroys modal/jsTree on close.
  // =============================================================================

  Drupal.behaviors.modalFix = {
    attach: function (context, settings) {
      if (!window._modalFixInitialized) {
        window._modalFixInitialized = true;

        // When ANY dialog fires ‘dialogclose’:
        $(document).on('dialogclose', function (event) {
          var $dialogContent = $(event.target);

          // Only proceed if it’s a jQuery UI Dialog container (class ui-dialog-content)
          if (!$dialogContent.hasClass('ui-dialog-content')) {
            return;
          }

          // 1) Immediately restore <html> overflow properties
          $('html').css({
            overflow: '',
            'box-sizing': '',
            'padding-right': ''
          });

          // 2) If jsTree still exists, destroy it
          var $treeRoot = $('#tree-root');
          if ($treeRoot.length && $treeRoot.data('jstree')) {
            $treeRoot.jstree('destroy');
          }
          // Remove the <div id="tree-root"> element itself
          $treeRoot.remove();

          // 3) If the dialog widget is initialized, destroy it
          if ($dialogContent.data('ui-dialog')) {
            $dialogContent.dialog('destroy');
          }

          // 4) Remove the dialog container from the DOM
          $dialogContent.remove();
        });

        // Also listen to ‘dialog:afterclose’ just to be sure
        $(document).on('dialog:afterclose', function () {
          $('html').css({
            overflow: '',
            'box-sizing': '',
            'padding-right': ''
          });
        });

        // If user clicks the “X” (titlebar-close), restore overflow as well
        $(document).on('click', '.ui-dialog-titlebar-close', function () {
          $('html').css({
            overflow: '',
            'box-sizing': '',
            'padding-right': ''
          });
        });
      }
    }
  };

})(jQuery, Drupal, drupalSettings);
