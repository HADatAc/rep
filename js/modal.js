(function (Drupal, $) {
  Drupal.behaviors.repModal = {
    attach: function (context, settings) {
      $('.open-tree-modal', context)
        .not('.repModal-processed')
        .addClass('repModal-processed')
        .on('click', function (e) {
          e.preventDefault();

          const url = $(this).data('url');
          // Always prioritize the clicked trigger field id; stale tree-root state
          // from previous dialogs can otherwise prevent Phase I hierarchy opening.
          const fieldId = $(this).data('field-id') || $('#tree-root').data('field-id');
          const forceFreshHierarchy = fieldId === 'phase1ClinicalProcess';
          const elementtype = $(this).data('elementtype');
          const rawSearchValue = $(this).val();
          // For Phase I clinical process hierarchy, opening pre-filtered by
          // the current field value hides sibling branches and looks like a
          // broken/replaced tree. Open unfiltered instead.
          const searchValue = fieldId === 'phase1ClinicalProcess' ? '' : rawSearchValue;

          $('#tree-root').data('field-id', fieldId);

          // console.log(fieldId);

          if (!drupalSettings.rep_tree) {
            drupalSettings.rep_tree = {};
          }

          drupalSettings.rep_tree.searchValue = searchValue;
          drupalSettings.rep_tree.fieldId = fieldId;

          const $searchField = $('#tree-search');
          const $clearButton = $('#clear-search');
          const openedFromDrupalModal = $(this).closest('#drupal-modal').length > 0;
          const requestedDialogType = $(this).data('dialog-type') || 'modal';
          // Nested modal: avoid replacing the existing #drupal-modal.
          const dialogType = (openedFromDrupalModal && requestedDialogType === 'modal') ? 'dialog' : requestedDialogType;
          const $existingModal = dialogType === 'modal'
            ? $('#drupal-modal.ui-dialog-content')
            : $('#drupal-dialog.ui-dialog-content');

          if (!forceFreshHierarchy &&
            $existingModal.length &&
            JSON.stringify($existingModal.data('elementtype')) === JSON.stringify(elementtype)
          ) {
            $existingModal.dialog('open');

            const $treeRoot = $('#tree-root');
            if ($treeRoot.length) {
              $treeRoot.jstree('close_all');
            }


            if ($searchField.length) {
              const valToSet = drupalSettings.rep_tree.searchValue || '';
              $searchField.val(valToSet);
              $clearButton.show();

              setTimeout(() => {
                const treeInstance = $treeRoot.jstree(true);
                if (treeInstance) {
                  treeInstance.search(valToSet);
                }
              }, 300);
            }
            return;
          }

          const dialogOptions = {
            title: Drupal.t('Knowledge Graph Hierarchy'),
            width: 800,
            // Keep the tree picker modal even when we use the non-modal dialog
            // container (#drupal-dialog) to avoid replacing the parent modal.
            modal: true,
            close: function () {
              const currentelementtype = $(this).data('elementtype') || ['unknown'];
              const $field = $(`[name="${fieldId}"], #${fieldId}`);
              const committed = !!$field.data('rep-tree-committed');

              if (committed) {
                $field.removeData('rep-tree-committed');
                return;
              }

              const initialValue = $(this).data('initial-value');
              if (typeof initialValue !== 'undefined') {
                $field.val(initialValue).trigger('change');
              }
            },
          };

          const separator = String(url).indexOf('?') === -1 ? '?' : '&';
          const modalUrl = `${url}${separator}field_id=${encodeURIComponent(fieldId)}`;
          const $field = $(`[name="${fieldId}"], #${fieldId}`);
          const initialValue = $field.val();

          $field.data('initial-value', initialValue);

          if (typeof Drupal.ajax !== 'function') {
            window.location.href = modalUrl;
            return;
          }

          try {
            Drupal.ajax({
              url: modalUrl,
              dialogType: dialogType,
              dialog: dialogOptions,
            }).execute();
          } catch (err) {
            window.location.href = modalUrl;
            return;
          }

          setTimeout(() => {
            const valToSet = drupalSettings.rep_tree.searchValue || '';
            const $searchField = $('#tree-search');

            if ($searchField.length) {
              $searchField.val(valToSet);
              $searchField.trigger('input');

              if ($searchField.val().length > 0) {
                $clearButton.show();
              } else {
                $clearButton.hide();
              }

              $clearButton.off('click').on('click', function () {
                $searchField.val('');
                $clearButton.hide();

                const $treeRoot = $('#tree-root');
                if ($treeRoot.length) {
                  $treeRoot.jstree('clear_search');
                  $treeRoot.jstree('close_all');
                }
              });
            }
          }, 500);

          setTimeout(() => {
            const $newModal = dialogType === 'modal'
              ? $('#drupal-modal.ui-dialog-content')
              : $('#drupal-dialog.ui-dialog-content');
            if ($newModal.length) {
              $newModal.data('elementtype', elementtype);
            }
          }, 500);
        });
    },
  };

  Drupal.behaviors.repTreeSelection = {
    attach: function (context, settings) {
      const $selectNodeButton = $('#select-tree-node', context);

      $selectNodeButton
        .off('click.repTreeSelection')
        .on('click.repTreeSelection', function (e) {
          e.preventDefault();

          const selectedValue = $(this).data('selected-value');
          const selectedLabel = $(this).data('selected-label');
          const repTreeSettings = (typeof drupalSettings !== 'undefined' && drupalSettings.rep_tree) ? drupalSettings.rep_tree : {};
          const fieldId = $('#tree-root').data('field-id') || $(this).data('field-id') || repTreeSettings.fieldId || '';


          if (fieldId && selectedValue) {
            const $field = $(`[name="${fieldId}"], #${fieldId}`).first();
            let finalValue = selectedValue;

            if (fieldId === 'phase1ClinicalProcess') {
              const raw = String(selectedValue || '').trim();
              const bracketMatch = raw.match(/\[(https?:\/\/[^\]]+)\]\s*$/);
              const selectedUri = bracketMatch && bracketMatch[1]
                ? bracketMatch[1].trim()
                : raw;
              if (bracketMatch && bracketMatch[1]) {
                finalValue = bracketMatch[1].trim();
              }
              else {
                finalValue = selectedUri;
              }

              const displayLabel = String(selectedLabel || selectedUri).trim();
              const displayValue = displayLabel + ' (' + selectedUri + ')';

              if ($field.is('select')) {
                let $opt = $field.find('option').filter(function () {
                  return $(this).val() === finalValue;
                }).first();

                if (!$opt.length) {
                  $opt = $('<option>', {
                    value: finalValue,
                    text: displayValue
                  });
                  $field.append($opt);
                } else {
                  $opt.text(displayValue);
                }

                $field.find('option').prop('selected', false);
                $opt.prop('selected', true);
              }

              // If Core WKF Name is still empty, copy only procedure name (no URI).
              const $wkfName = $('#phase1WkfName');
              if ($wkfName.length) {
                const currentWkfName = String($wkfName.val() || '').trim();
                if (!currentWkfName) {
                  const cleanLabel = String(displayLabel || '')
                    .replace(/\s*\((https?:\/\/[^)]+)\)\s*$/, '')
                    .trim();
                  if (cleanLabel) {
                    $wkfName.val(cleanLabel).trigger('change').trigger('input');
                  }
                }
              }
            }

            $field.val(finalValue).trigger('change').trigger('input');
            $field.data('rep-tree-committed', true);
          }

          // Close only the dialog that contains this tree picker.
          // (Never close all dialogs, otherwise we also close the parent form.)
          const $dialogContent = $(this).closest('.ui-dialog-content');
          if ($dialogContent.length && $dialogContent.data('ui-dialog')) {
            $dialogContent.dialog('close');
          }

          // Prevent any other click handlers from also closing dialogs.
          e.stopImmediatePropagation();
        });
    },
  };

  Drupal.behaviors.repTreeCreateSubNode = {
    attach: function (context, settings) {
      var $createButton = $('#create-sub-node-btn', context);
      if (!$createButton.length) {
        return;
      }

      var $status = $('#create-sub-node-status', context);
      var $parentLabel = $('#create-sub-node-parent-label', context);
      var $parentUri = $('#create-sub-node-parent-uri', context);
      var $selectSuperNodeButton = $('#select-super-node-btn', context);
      var $nameInput = $('#create-sub-node-name', context);
      var defaultButtonText = String($createButton.text() || 'Create Sub-Node').trim();

      function extractUriFromSelection(raw) {
        raw = String(raw || '').trim();
        var match = raw.match(/\[(https?:\/\/[^\]]+)\]\s*$/);
        return match && match[1] ? String(match[1]).trim() : raw;
      }

      function updateSelectedParentFields(raw, label) {
        var uri = extractUriFromSelection(raw);
        if ($parentUri.length) {
          $parentUri.val(uri);
        }
        if ($parentLabel.length) {
          $parentLabel.val(uri ? (label ? label + ' (' + uri + ')' : uri) : '');
        }
        updateCreateButtonState();
        return uri;
      }

      function updateCreateButtonState() {
        var hasParent = !!String($parentUri.val() || '').trim();
        var hasName = !!String($nameInput.val() || '').trim();
        $createButton.prop('disabled', !(hasParent && hasName));
      }

      $createButton.prop('disabled', true);
      $nameInput
        .off('input.repTreeCreateSubNode change.repTreeCreateSubNode')
        .on('input.repTreeCreateSubNode change.repTreeCreateSubNode', updateCreateButtonState);

      $selectSuperNodeButton
        .off('click.repTreeSelectSuperNode')
        .on('click.repTreeSelectSuperNode', function (e) {
          e.preventDefault();

          var selectedRaw = String($selectSuperNodeButton.data('selected-uri') || '').trim();
          var selectedLabel = String($selectSuperNodeButton.data('selected-label') || '').trim();
          var parentUri = updateSelectedParentFields(selectedRaw, selectedLabel);
          if (!parentUri) {
            setStatus('Select a super-node in the hierarchy before using this button.', 'error');
            return;
          }

          setStatus('Super-node selected.', 'success');
        });

      function setStatus(message, kind) {
        if (!$status.length) {
          return;
        }
        $status.removeClass('text-success text-danger text-muted');
        if (kind === 'success') {
          $status.addClass('text-success');
        } else if (kind === 'error') {
          $status.addClass('text-danger');
        } else {
          $status.addClass('text-muted');
        }
        $status.text(String(message || ''));
      }

      $createButton
        .off('click.repTreeCreateSubNode')
        .on('click.repTreeCreateSubNode', function (e) {
          e.preventDefault();

          var endpoint = (typeof drupalSettings !== 'undefined' && drupalSettings.rep_tree && drupalSettings.rep_tree.createProcessStemEndpoint)
            ? String(drupalSettings.rep_tree.createProcessStemEndpoint)
            : '/rep/tree/processstem/create-subnode';

          var $selectButton = $('#select-tree-node');
          var selectedRaw = String($selectButton.data('selected-value') || '').trim();
          var selectedLabel = String($selectButton.data('selected-label') || '').trim();
          var parentUri = String($parentUri.val() || '').trim();
          if (!parentUri) {
            setStatus('Use Select super-node before creating a sub-node.', 'error');
            return;
          }

          var nodeName = String($nameInput.val() || '').trim();
          if (!nodeName) {
            setStatus('Enter the new process stem name.', 'error');
            return;
          }

          var fieldId = $('#tree-root').data('field-id') || $selectButton.data('field-id') || $createButton.data('field-id') || '';
          var $field = fieldId ? $(`[name="${fieldId}"], #${fieldId}`).first() : $();

          setStatus('Creating sub-node...', 'info');
          $createButton.prop('disabled', true).text('Creating...');

          fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              parentUri: parentUri,
              name: nodeName
            })
          })
            .then(function (resp) {
              return resp.json().catch(function () { return {}; }).then(function (data) {
                if (!resp.ok || !data || data.success !== true || !data.node || !data.node.uri) {
                  var msg = data && data.error ? data.error : 'Failed to create sub-node.';
                  throw new Error(msg);
                }
                return data;
              });
            })
            .then(function (data) {
              var uri = String((data.node && data.node.uri) || '').trim();
              var label = String((data.node && data.node.label) || nodeName || '').trim();
              var parentContextUri = parentUri;
              var parentContextLabel = String($parentLabel.val() || selectedLabel || parentContextUri).trim();

              // Keep parent selection context so consecutive creates append
              // siblings under the same parent (instead of nesting under the
              // just-created child).
              $selectButton
                .prop('disabled', false)
                .removeClass('disabled')
                .data('selected-value', parentContextUri || uri)
                .data('selected-label', parentContextLabel || selectedLabel || label)
                .data('field-id', fieldId);

              var $tree = $('#tree-root');
              if ($tree.length && $tree.data('jstree')) {
                var tree = $tree.jstree(true);
                if (tree) {
                  try {
                    var flat = tree.get_json('#', { flat: true }) || [];
                    var parentNode = null;
                    var childNode = null;

                    function nodeUri(node) {
                      if (!node) {
                        return '';
                      }
                      if (node.original && node.original.uri) {
                        return String(node.original.uri).trim();
                      }
                      if (node.data && node.data.originalUri) {
                        return String(node.data.originalUri).trim();
                      }
                      if (node.data && node.data.realUri) {
                        return String(node.data.realUri).trim();
                      }
                      if (node.uri) {
                        return String(node.uri).trim();
                      }
                      return '';
                    }

                    flat.forEach(function (n) {
                      var nUri = nodeUri(n);
                      if (!nUri) {
                        return;
                      }
                      if (!parentNode && parentContextUri && nUri === parentContextUri) {
                        parentNode = n;
                      }
                      if (!childNode && uri && nUri === uri) {
                        childNode = n;
                      }
                    });

                    if (parentNode && !childNode) {
                      tree.create_node(parentNode.id, {
                        text: label,
                        label: label,
                        uri: uri,
                        hasStatus: 'http://hadatac.org/ont/vstoi#Draft',
                        hasSIRManagerEmail: (drupalSettings.rep_tree && drupalSettings.rep_tree.managerEmail) ? drupalSettings.rep_tree.managerEmail : '',
                        children: true,
                        original: {
                          uri: uri,
                          label: label,
                          superUri: parentContextUri,
                          isCategory: false,
                          hasStatus: 'http://hadatac.org/ont/vstoi#Draft',
                          hasSIRManagerEmail: (drupalSettings.rep_tree && drupalSettings.rep_tree.managerEmail) ? drupalSettings.rep_tree.managerEmail : ''
                        },
                        data: {
                          originalLabel: label,
                          originalPrefixLabel: label,
                          originalUri: uri,
                          originalPrefixUri: uri,
                          realUri: uri,
                          typeNamespace: '',
                          comment: '',
                          hasWebDocument: '',
                          hasImageUri: ''
                        }
                      }, 'last');

                      tree.open_node(parentNode.id);
                      tree.deselect_all();
                      tree.select_node(parentNode.id);
                    } else {
                      tree.refresh();
                    }
                  } catch (refreshErr) {
                    // Ignore tree refresh errors.
                  }
                }
              }

              $nameInput.val('');
              setStatus('Sub-node created successfully.', 'success');
            })
            .catch(function (err) {
              setStatus('Create Sub-Node failed: ' + err.message, 'error');
            })
            .finally(function () {
              $createButton.text(defaultButtonText);
              updateCreateButtonState();
            });
        });
    }
  };

  Drupal.behaviors.repTreeReparentSubNode = {
    attach: function (context, settings) {
      var $repairButton = $('#repair-sub-node-btn', context);
      if (!$repairButton.length) {
        return;
      }

      var $status = $('#repair-sub-node-status', context);
      var defaultButtonText = String($repairButton.text() || 'Repair Parent Link').trim();

      function setStatus(message, kind) {
        if (!$status.length) {
          return;
        }
        $status.removeClass('text-success text-danger text-muted');
        if (kind === 'success') {
          $status.addClass('text-success');
        } else if (kind === 'error') {
          $status.addClass('text-danger');
        } else {
          $status.addClass('text-muted');
        }
        $status.text(String(message || ''));
      }

      $repairButton
        .off('click.repTreeReparentSubNode')
        .on('click.repTreeReparentSubNode', function (e) {
          e.preventDefault();

          var endpoint = (typeof drupalSettings !== 'undefined' && drupalSettings.rep_tree && drupalSettings.rep_tree.reparentProcessStemEndpoint)
            ? String(drupalSettings.rep_tree.reparentProcessStemEndpoint)
            : '/rep/tree/processstem/reparent';

          var $selectButton = $('#select-tree-node');
          var selectedRaw = String($selectButton.data('selected-value') || '').trim();
          var match = selectedRaw.match(/\[(https?:\/\/[^\]]+)\]\s*$/);
          var parentUri = match && match[1] ? String(match[1]).trim() : selectedRaw;
          if (!parentUri) {
            setStatus('Select the intended parent node first.', 'error');
            return;
          }

          var $childInput = $('#repair-sub-node-uri');
          var childUri = String($childInput.val() || '').trim();
          if (!childUri) {
            setStatus('Enter the existing child URI to repair.', 'error');
            return;
          }

          if (childUri === parentUri) {
            setStatus('Child URI cannot be the same as parent URI.', 'error');
            return;
          }

          setStatus('Repairing parent link...', 'info');
          $repairButton.prop('disabled', true).text('Repairing...');

          fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              childUri: childUri,
              parentUri: parentUri
            })
          })
            .then(function (resp) {
              return resp.json().catch(function () { return {}; }).then(function (data) {
                if (!resp.ok || !data || data.success !== true || !data.node || !data.node.uri) {
                  var msg = data && data.error ? data.error : 'Failed to repair parent link.';
                  throw new Error(msg);
                }
                return data;
              });
            })
            .then(function (data) {
              var oldParent = String((data.node && data.node.previousSuperUri) || '').trim();
              if (oldParent) {
                setStatus('Parent link repaired successfully.', 'success');
              } else {
                setStatus('Parent link set successfully.', 'success');
              }

              var $tree = $('#tree-root');
              if ($tree.length && $tree.data('jstree')) {
                var tree = $tree.jstree(true);
                if (tree) {
                  try {
                      var fixedChildUri = String((data.node && data.node.uri) || childUri || '').trim();
                      var flat = tree.get_json('#', { flat: true }) || [];
                      var parentNode = null;
                      var childNode = null;

                      function nodeUri(node) {
                        if (!node) {
                          return '';
                        }
                        if (node.original && node.original.uri) {
                          return String(node.original.uri).trim();
                        }
                        if (node.data && node.data.originalUri) {
                          return String(node.data.originalUri).trim();
                        }
                        if (node.data && node.data.realUri) {
                          return String(node.data.realUri).trim();
                        }
                        if (node.uri) {
                          return String(node.uri).trim();
                        }
                        return '';
                      }

                      flat.forEach(function (n) {
                        var nUri = nodeUri(n);
                        if (!nUri) {
                          return;
                        }
                        if (!parentNode && nUri === parentUri) {
                          parentNode = n;
                        }
                        if (!childNode && fixedChildUri && nUri === fixedChildUri) {
                          childNode = n;
                        }
                      });

                      if (parentNode && childNode) {
                        tree.move_node(childNode.id, parentNode.id, 'last');
                        tree.open_node(parentNode.id);
                        tree.deselect_all();
                        tree.select_node(parentNode.id);
                      } else {
                        tree.refresh();
                      }
                  } catch (refreshErr) {
                    // Ignore tree refresh errors.
                  }
                }
              }
            })
            .catch(function (err) {
              setStatus('Repair failed: ' + err.message, 'error');
            })
            .finally(function () {
              $repairButton.prop('disabled', false).text(defaultButtonText);
            });
        });
    }
  };
})(Drupal, jQuery);

