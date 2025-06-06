(function ($) {
  // ===================================================================
  // 1) Patch no widget de diálogo do jQuery UI para suprimir erros
  //    “cannot call methods on dialog prior to initialization”.
  // ===================================================================
  var originalDialog = $.ui.dialog.prototype;
  $.widget("ui.dialog", $.ui.dialog, {
    _setOption: function (key, value) {
      // Se o elemento NÃO tiver dados “ui-dialog” (não foi inicializado), interrompe.
      if (!this.element.data("ui-dialog")) {
        return;
      }
      // Caso contrário, comporta-se normalmente.
      originalDialog._setOption.apply(this, arguments);
    }
  });
})(jQuery);



(function ($, Drupal, drupalSettings) {
  // =============================================================================
  // 2) Drupal.behaviors.tree
  //    - Responsável por inicializar/destroir o jsTree toda vez que o modal abrir.
  //    - Trata fallback “prefix” → “normal” e mostra “No results” quando necessário.
  // =============================================================================

  Drupal.behaviors.tree = {
    attach: function (context, settings) {
      console.log('[tree] → BEGIN Drupal.behaviors.tree.attach', { context: context, settings: settings });

      // ------------------------------------------------------
      // 2.1) Captura o clique em qualquer .open-tree-modal
      // ------------------------------------------------------
      $(context).find('.open-tree-modal').each(function () {
        var $trigger = $(this);
        // Garante que só se ligue UMA VEZ por elemento
        if ($trigger.data('tree-capture-bound')) {
          return;
        }
        $trigger.data('tree-capture-bound', true);

        $trigger.on('click', function (e) {
          var passedValue = $trigger.data('search-value') || '';
          console.log('[tree] .open-tree-modal clicked → data-search-value =', passedValue);

          // Remove mensagem “No results” antiga
          $('#no-results-message').remove();

          // Garanta que drupalSettings.rep_tree exista
          if (!drupalSettings.rep_tree) {
            drupalSettings.rep_tree = {};
          }
          drupalSettings.rep_tree.searchValue = passedValue;
          // Reseta flag interna para forçar re-inicialização do jsTree
          drupalSettings.rep_tree._initialSearchDone = false;

          // Se já havia um jsTree ativo, destrua-o e limpe a marcação
          var $treeRoot = $('#tree-root');
          if ($treeRoot.length && $treeRoot.data('tree-initialized')) {
            console.log('[tree] Clearing previous jsTree instance on #tree-root');
            $treeRoot.jstree('destroy');
            $treeRoot.removeData('tree-initialized');
            $treeRoot.hide();
          }

          // Força o valor do #search_input para o valor passado
          if ($('#search_input').length) {
            $('#search_input').val(passedValue);
            console.log('[tree] Forçando #search_input.val(', passedValue, ') no clique.');
            console.log('[tree] Estado do prefix ', drupalSettings.rep_tree.prefix);
          }
        });
      });

      // ------------------------------------------------------
      // 2.2) Inicializa/destrói o jsTree dentro de #tree-root
      // ------------------------------------------------------
      $(context).find('#tree-root').each(function () {
        var $treeRoot = $(this);

        // Se já inicializamos este #tree-root nesta instância do modal, pule
        if ($treeRoot.data('tree-initialized')) {
          console.log('[tree] jsTree already initialized on this #tree-root, skipping.');
          return;
        }
        $treeRoot.data('tree-initialized', true);
        console.log('[tree] → Initializing jsTree behavior for element:', $treeRoot);

        // ----------------------------
        // Helpers (sanitizeForId, base64EncodeUnicode, namespaceUri, namespacePrefixUri)
        // ----------------------------
        function sanitizeForId(str) {
          return str.replace(/[^A-Za-z0-9_-]/g, '_');
        }
        function base64EncodeUnicode(str) {
          var utf8Bytes = new TextEncoder().encode(str);
          var asciiStr = '';
          for (var i = 0; i < utf8Bytes.length; i++) {
            asciiStr += String.fromCharCode(utf8Bytes[i]);
          }
          return btoa(asciiStr);
        }
        function namespaceUri(uri) {
          var namespaces = (drupalSettings.rep_tree && drupalSettings.rep_tree.nameSpacesList) || {};
          for (var abbrev in namespaces) {
            if (namespaces.hasOwnProperty(abbrev)) {
              var ns = namespaces[abbrev];
              if (abbrev && ns && uri.startsWith(ns)) {
                return abbrev + ":" + uri.slice(ns.length);
              }
            }
          }
          return uri;
        }
        function namespacePrefixUri(uri) {
          var namespaces = (drupalSettings.rep_tree && drupalSettings.rep_tree.nameSpacesList) || {};
          for (var abbrev2 in namespaces) {
            if (namespaces.hasOwnProperty(abbrev2)) {
              var ns2 = namespaces[abbrev2];
              if (abbrev2 && ns2 && uri.startsWith(ns2)) {
                return abbrev2 + ":";
              }
            }
          }
          return uri;
        }

        // ----------------------------
        // getFilteredBranches()
        // Remove ramos duplicados por label.
        // ----------------------------
        function getFilteredBranches() {
          var seenLabels = new Set();
          var branches = (drupalSettings.rep_tree && drupalSettings.rep_tree.branches) || [];
          return branches.filter(function(branch) {
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
        // Decide o texto final do nó, baseado em showLabel(label/labelprefix/uri/uriprefix)
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
        // setTitleSufix(item)
        // Adiciona “(Draft)”, “(Deprecated)”, etc., e indica propriedade.
        // ----------------------------
        function setTitleSufix(item) {
          var suffix = '';
          var DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
          var DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
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
        // Converte “obo:PATO_0002370” → URI completo usando drupalSettings.rep_tree.nameSpacesList
        // ----------------------------
        function expandPrefix(maybePrefixed) {
          if (/^https?:\/\//.test(maybePrefixed)) {
            return maybePrefixed;
          }
          var parts = maybePrefixed.split(':', 2);
          if (parts.length === 2) {
            var prefix = parts[0];
            var local  = parts[1];
            var nsList = (drupalSettings.rep_tree && drupalSettings.rep_tree.nameSpacesList) || {};
            if (nsList[prefix]) {
              return nsList[prefix] + local;
            }
          }
          return maybePrefixed;
        }

        // --------------------------------------------------------------
        // Converte o searchValue prefixado em URI completo:
        // --------------------------------------------------------------
        var rawSearchValue     = (drupalSettings.rep_tree && drupalSettings.rep_tree.searchValue) || '';
        var initialSearchValue = expandPrefix(rawSearchValue);
        if (!drupalSettings.rep_tree) {
          drupalSettings.rep_tree = {};
        }
        drupalSettings.rep_tree.searchValue = initialSearchValue;
        console.log('[tree] Initialization: initialSearchValue =', initialSearchValue);

        // --------------------------------------------------------------
        // Outras flags iniciais:
        // --------------------------------------------------------------
        var prefixIsActive     = !!(drupalSettings.rep_tree && drupalSettings.rep_tree.prefix);
        var hideDraft          = (drupalSettings.rep_tree && drupalSettings.rep_tree.hideDraft) || false;
        var hideDeprecated     = (drupalSettings.rep_tree && drupalSettings.rep_tree.hideDeprecated) || false;
        var showLabel          = (drupalSettings.rep_tree && drupalSettings.rep_tree.showLabel) || 'label';
        console.log('[tree] Initialization: prefixIsActive =', prefixIsActive,
                    ', hideDraft =', hideDraft,
                    ', hideDeprecated =', hideDeprecated,
                    ', showLabel =', showLabel);

        if (initialSearchValue.length > 0) {
          console.log('[tree] Setting initialSearchValue in #search_input →', initialSearchValue);
          $('#search_input').val(initialSearchValue);
        }

        // --------------------------------------------------------------
        // Variáveis de “espera” inicial:
        // --------------------------------------------------------------
        var activityTimeout   = null;
        var activityDelay     = 1000; // 1s
        var initialSearchDone = false;

        // --------------------------------------------------------------
        // resetActivityTimeout(): esconde “wait-message” e mostra árvore
        // --------------------------------------------------------------
        function resetActivityTimeout() {
          if (activityTimeout) {
            clearTimeout(activityTimeout);
          }
          activityTimeout = setTimeout(function () {
            if (!initialSearchDone) {
              console.log("[tree] resetActivityTimeout: Hiding wait message and showing tree.");
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
        // attachTreeEventListeners(): habilita/desabilita botão e tooltip
        // --------------------------------------------------------------
        function attachTreeEventListeners() {
          $treeRoot.off('select_node.jstree hover_node.jstree load_node.jstree open_node.jstree');

          $treeRoot.on('load_node.jstree open_node.jstree', function () {
            // pode tratar algo aqui se quiser
          });

          // Ao selecionar nó:
          $treeRoot.on('select_node.jstree', function (e, data) {
            var selectedNode = data.node.original;
            var DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
            var DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
            var UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

            console.log("[tree] Node selected:", selectedNode.uri, ", status =", selectedNode.hasStatus);

            var $selectNodeButton = $('#select-tree-node');
            $selectNodeButton.prop('disabled', true)
                             .addClass('disabled')
                             .removeData('selected-value');

            // Regra 1: nó com status restrito e não é do usuário → mantém desabilitado
            if (
              (selectedNode.hasStatus === DRAFT_URI     && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail) ||
              (selectedNode.hasStatus === DEPRECATED_URI && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail) ||
              (selectedNode.hasStatus === UNDERREVIEW_URI && selectedNode.hasSIRManagerEmail !== drupalSettings.rep_tree.managerEmail)
            ) {
              console.log("[tree] Node cannot be selected (restricted & not owned).");
            }
            // Regra 2: nó deprecated e é do usuário → mas mesmo assim desabilita
            else if (
              selectedNode.hasStatus === DEPRECATED_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              console.log("[tree] Node is deprecated and owned by user → still cannot select.");
            }
            // Regra 3: nó draft e é do usuário → habilita
            else if (
              selectedNode.hasStatus === DRAFT_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              console.log("[tree] Node is draft and owned by user → enabling selection.");
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data(
                  'selected-value',
                  selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace
                )
                .data('field-id', $('#tree-root').data('field-id'));
            }
            // Regra 4: nó under review e é do usuário → mantém desabilitado
            else if (
              selectedNode.hasStatus === UNDERREVIEW_URI &&
              selectedNode.hasSIRManagerEmail === drupalSettings.rep_tree.managerEmail
            ) {
              console.log("[tree] Node is under review and owned by user → still cannot select.");
            }
            // Regra 5: normal → habilita
            else {
              console.log("[tree] Node is normal or draft by user → enabling selection.");
              $selectNodeButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data(
                  'selected-value',
                  selectedNode.uri ? selectedNode.text + " [" + selectedNode.uri + "]" : selectedNode.typeNamespace
                )
                .data('field-id', $('#tree-root').data('field-id'));
            }

            // Monta HTML com detalhes do nó
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
                var uriPart    = selectedNode.uri.includes('#/') ? selectedNode.uri.split('#/')[1] : selectedNode.uri;
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

          // Hover para tooltip de comentário
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
        //   - Constrói árvore de ancestrais (ou único nó forçado)
        // --------------------------------------------------------------
        function buildHierarchy(items, forcedRootUri) {
          console.log("[tree] buildHierarchy called. items.length =", items.length, ", forcedRootUri =", forcedRootUri);

          // 1) Remove duplicados pelo URI
          var uniqueItems = [];
          var seenUris = new Set();
          items.forEach(function(item) {
            if (!seenUris.has(item.uri)) {
              uniqueItems.push(item);
              seenUris.add(item.uri);
            }
          });
          console.log("[tree] After deduplication: uniqueItems.length =", uniqueItems.length);

          // 2) Se forcedRootUri aparece em uniqueItems, retorna só ele como nó único
          if (forcedRootUri) {
            var shortCircuitItem = uniqueItems.find(function(item) {
              return item.uri === forcedRootUri;
            });
            if (shortCircuitItem) {
              console.log("[tree] Short‐circuit: found forcedRootUri =", forcedRootUri, "– returning single node.");

              var itemSC = shortCircuitItem;
              var nodeText = setNodeText(itemSC);
              var a_attr = {};
              var DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
              var DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
              var UNDERREVIEW_URI = 'http://hadatac.org/ont/vstoi#UnderReview';

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
                  originalLabel: itemSC.label + setTitleSufix(itemSC),
                  originalPrefixLabel: namespacePrefixUri(itemSC.uri) + itemSC.label + setTitleSufix(itemSC),
                  originalUri: itemSC.uri + setTitleSufix(itemSC),
                  originalPrefixUri: namespaceUri(itemSC.uri) + setTitleSufix(itemSC),
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

          // 3) Se não “short‐circuit”, filtrar até forcedRootUri (se existir)
          var filteredItems = uniqueItems;
          if (forcedRootUri) {
            var forcedIndex = uniqueItems.findIndex(function(item) {
              return item.uri === forcedRootUri;
            });
            if (forcedIndex !== -1) {
              filteredItems = uniqueItems.slice(0, forcedIndex + 1);
              console.log("[tree] After slicing up to forcedRootUri: filteredItems.length =", filteredItems.length);
            }
          }

          // 4) Cria um Map URI→nó, marcando skip para status ocultos
          var nodeMap = new Map();
          filteredItems.forEach(function(item) {
            var nodeText = setNodeText(item);
            var a_attr = {};
            item.skip = false;

            var DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
            var DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
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

          // 5) Conecta cada nó ao seu pai (forçado ou normal)
          var root = null;
          if (forcedRootUri) {
            console.log("[tree] Linking chain nodes (forcedRootUri mode).");
            var chain = filteredItems.slice();
            chain.reverse(); // primeiro é forcedRootUri

            chain.forEach(function(item, index) {
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
            console.log("[tree] Linking nodes by superUri.");
            filteredItems.forEach(function(item) {
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

          // 6) Se forcedRootUri existe, sobrescreve root
          if (forcedRootUri && nodeMap.has(forcedRootUri)) {
            root = nodeMap.get(forcedRootUri);
          }
          // 7) Se ainda não há root, pega o primeiro sem superUri
          else if (!root) {
            for (var idx = 0; idx < filteredItems.length; idx++) {
              var it = filteredItems[idx];
              if (!it.superUri) {
                root = nodeMap.get(it.uri);
                break;
              }
            }
          }

          console.log("[tree] buildHierarchy returning root.uri =", root ? root.uri : null);
          return root;
        }

        // --------------------------------------------------------------
        // populateTree(uri)
        //    - Chama searchSuperClassEndPoint, monta hierarquia e popula o jsTree.
        // --------------------------------------------------------------
        function populateTree(uri) {
          console.log("[tree] → populateTree called with URI =", uri);

          $.ajax({
            url: drupalSettings.rep_tree.searchSuperClassEndPoint,
            type: 'GET',
            data: { uri: encodeURI(uri) },
            dataType: 'json',
            success: function (data) {
              console.log("[tree] → populateTree AJAX success; items.length =", data.length);

              $('#no-results-message').remove();

              var elementTypeUri = drupalSettings.rep_tree.elementType || null;
              var forcedRootUri  = elementTypeUri || drupalSettings.rep_tree.superclass || null;
              var rootNode = buildHierarchy(data, forcedRootUri);

              // —— Caso A: sem resultados
              if (data.length === 0) {
                var $message = $('<div id="no-results-message" style="color: #b00; margin-bottom: 10px;">No results found for “' + uri + '”.</div>');
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

              // —— Caso B: há resultados ou prefixIsActive=false
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

              console.log("[tree] → Calling treeInstance.refresh() with new data.");
              treeInstance.refresh();
            },
            error: function () {
              console.error("[tree] → populateTree: error fetching data for URI", uri);

              $('#no-results-message').remove();
              var $errorMsg = $('<div id="no-results-message" style="color: #b00; margin-bottom: 10px;">Error loading data. Please try again.</div>');
              $treeRoot.before($errorMsg);
            }
          });
        }

        // --------------------------------------------------------------
        // resetTree()
        //    - Destrói/recria jsTree no estado “top-level only”.
        // --------------------------------------------------------------
        function resetTree() {
          console.log("[tree] resetTree called: destroying and re-initializing jsTree.");
          $('#search_input').val('');
          $('#clear-search').hide();
          $treeRoot.jstree('destroy').empty();

          $treeRoot.jstree({
            core: {
              check_callback: true,
              data: function (node, cb) {
                if (node.id === '#') {
                  var branches = getFilteredBranches();
                  var arr = branches.map(function(branch) {
                    var prefixed = namespacePrefixUri(branch.uri);
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
                  console.log("[tree] resetTree → root data length =", arr.length);
                  cb(arr);
                } else {
                  console.log("[tree] resetTree fetching children for", node.original.uri);
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      console.log("[tree] resetTree AJAX success for children of", node.original.uri, "→ items.length =", data.length);
                      var temp = [];
                      var seen = new Set();
                      data.forEach(function(item) {
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

                          var DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
                          var DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
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

                          if (!nodeObj.skip) {
                            temp.push(nodeObj);
                          }
                        }
                      });
                      console.log("[tree] resetTree → children data length =", temp.length);
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
            console.log("[tree] resetTree ready.jstree event fired");
            attachTreeEventListeners();
            bindRenderingModeChange();
          });
        }

        // --------------------------------------------------------------
        // setupAutocomplete(inputField)
        //   - Fetcha sugestões a partir de searchSubClassEndPoint
        //   - Exibe lista de sugestões e trata clique
        // --------------------------------------------------------------
        function setupAutocomplete(inputField) {
          console.log("[tree] setupAutocomplete called for", inputField);
          $(inputField).on('input', function () {
            var searchTerm = $(this).val();
            if (searchTerm.length < 3) {
              $('#autocomplete-suggestions').hide();
              return;
            }
            console.log("[tree] Autocomplete: fetching suggestions for", searchTerm);
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
                console.log("[tree] Autocomplete AJAX success: items.length =", data.length);
                var suggestions = data.map(function(item) {
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
                suggestions.forEach(function(suggestion) {
                  var suggestionItem = $('<div class="suggestion-item"></div>')
                    .text(suggestion.label)
                    .css({ padding: '5px', cursor: 'pointer' });
                  suggestionItem.on('click', function () {
                    console.log("[tree] Autocomplete: clicked suggestion, calling populateTree(", suggestion.uri, ")");
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
        //   - Quando o usuário muda o rádio “label/labelprefix/uri/uriprefix”,
        //     atualiza todos os nós sem recarregar.
        // --------------------------------------------------------------
        function bindRenderingModeChange() {
          console.log("[tree] bindRenderingModeChange called");
          $('input[name="label_mode"]').on('change', function () {
            var novoModo = $(this).val();
            console.log("[tree] Rendering mode changed to:", novoModo);
            var tree = $treeRoot.jstree(true);
            if (!tree) {
              return;
            }

            var todosNodes = tree.get_json('#', { flat: true });
            todosNodes.forEach(function(node) {
              var novoTexto = '';
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
              tree.rename_node(node.id, novoTexto);
            });
          });
        }

        // --------------------------------------------------------------
        // initializeJstree()
        //   - Monta o jsTree inicialmente, exibe “wait-message” e depois chama
        //     populateTree(initialSearchValue) se necessário, ou faz busca normal.
        // --------------------------------------------------------------
        function initializeJstree() {
          console.log("[tree] initializeJstree() called");
          $treeRoot.jstree({
            core: {
              check_callback: true,
              data: function (node, cb) {
                if (node.id === '#') {
                  var branches = getFilteredBranches();
                  var arr = branches.map(function(branch) {
                    var prefixed = namespacePrefixUri(branch.uri);
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
                  console.log("[tree] jsTree root data length =", arr.length);
                  cb(arr);
                } else {
                  console.log("[tree] jsTree fetching children for", node.original.uri);
                  $.ajax({
                    url: drupalSettings.rep_tree.apiEndpoint,
                    type: 'GET',
                    data: { nodeUri: node.original.uri },
                    dataType: 'json',
                    success: function (data) {
                      console.log("[tree] jsTree AJAX success for children of", node.original.uri, ": items.length =", data.length);
                      var temp = [];
                      var seen = new Set();
                      data.forEach(function(item) {
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

                          var DRAFT_URI       = 'http://hadatac.org/ont/vstoi#Draft';
                          var DEPRECATED_URI  = 'http://hadatac.org/ont/vstoi#Deprecated';
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

                          if (!nodeObj.skip) {
                            temp.push(nodeObj);
                          }
                        }
                      });
                      console.log("[tree] jsTree children data length =", temp.length);
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
                var term = str.toLowerCase();
                if (node.text.toLowerCase().includes(term)) return true;
                if (node.data.typeNamespace && node.data.typeNamespace.toLowerCase().includes(term)) {
                  return true;
                }
                return false;
              }
            }
          });

          // Quando pronto, anexa listeners e decide populateTree vs. busca
          $treeRoot.on('ready.jstree', function () {
            console.log("[tree] ready.jstree event fired");
            attachTreeEventListeners();
            bindRenderingModeChange();
            $treeRoot.on('load_node.jstree', resetActivityTimeout);
            $treeRoot.on('open_node.jstree', resetActivityTimeout);
            resetActivityTimeout();

            if (initialSearchValue.length > 0 && prefixIsActive) {
              console.log("[tree] prefixIsActive = true → calling populateTree(", initialSearchValue, ")");
              populateTree(initialSearchValue);
            }
            else if (initialSearchValue.length > 0) {
              console.log("[tree] prefixIsActive = false → performing normal search for", initialSearchValue);
              $treeRoot.jstree(true).search(initialSearchValue);
            }
          });

          if (initialSearchValue.length > 0) {
            console.log("[tree] initialSearchValue is set before ready → immediate search for", initialSearchValue);
            $treeRoot.jstree(true).search(initialSearchValue);
          }
        }

        // --------------------------------------------------------------
        // Ligar botão “Reset Tree”
        // --------------------------------------------------------------
        $('#reset-tree').on('click', function (e) {
          e.preventDefault();
          console.log("[tree] Reset button clicked → calling resetTree()");
          resetTree();
        });

        // Esconde árvore e mostra “wait message”
        $treeRoot.hide();
        $('#wait-message').show();
        $('#search_input').prop('disabled', true);

        if ($treeRoot.length) {
          initializeJstree();
          setupAutocomplete('#search_input');

          // Enter no campo dispara busca
          $('#search_input').on('keypress', function (e) {
            if (e.which === 13) {
              e.preventDefault();
              var term = $(this).val().trim();
              console.log("[tree] Enter pressed in search_input → searching for", term);
              $treeRoot.jstree(true).search(term);
            }
          });
        } else {
          console.warn("[tree] Tree root not found. Initialization aborted.");
        }
      }); // fim do each #tree-root
    }
  };

  // =============================================================================
  // 3) Drupal.behaviors.modalFix
  //    - Restaura overflow do <html> e destrói modal/jsTree ao fechar.
  // =============================================================================

  Drupal.behaviors.modalFix = {
    attach: function (context, settings) {
      if (!window._modalFixInitialized) {
        window._modalFixInitialized = true;

        // Quando QUALQUER diálogo dispara ‘dialogclose’:
        $(document).on('dialogclose', function (event) {
          var $dialogContent = $(event.target);

          // Só prossegue se for container de jQuery UI Dialog (classe ui-dialog-content)
          if (!$dialogContent.hasClass('ui-dialog-content')) {
            return;
          }

          // 1) Restaura overflow do <html> imediatamente
          $('html').css({
            overflow: '',
            'box-sizing': '',
            'padding-right': ''
          });

          // 2) Se o jsTree ainda existir, destrói
          var $treeRoot = $('#tree-root');
          if ($treeRoot.length && $treeRoot.data('jstree')) {
            $treeRoot.jstree('destroy');
          }
          // Remove o próprio <div id="tree-root">
          $treeRoot.remove();

          // 3) Destrói o widget de diálogo, só se tiver sido inicializado
          if ($dialogContent.data('ui-dialog')) {
            $dialogContent.dialog('destroy');
          }

          // 4) Remove do DOM o próprio container do diálogo injetado
          $dialogContent.remove();
        });

        // Também escuta ‘dialog:afterclose’, só para garantir
        $(document).on('dialog:afterclose', function () {
          $('html').css({
            overflow: '',
            'box-sizing': '',
            'padding-right': ''
          });
        });

        // Se clicar no “X” (titlebar-close), restaura também
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
