/**
 * Map Entry Points UI:
 * - LEFT tree: starts from a fixed HASCO root (ClassEntryPoint / InstanceEntryPoint).
 * - RIGHT tree: browse a chosen namespace and select one or more nodes to map.
 *
 * Endpoints (JSON):
 *   - cfg.apiTopClassEndpoint?nodeUri=<URI>
 *   - cfg.apiEndpoint?nodeUri=<URI>
 *   - cfg.apiSubclassKeywordEndpoint?superuri=<URI>&keyword=<KW>
 *
 * Requires jsTree to be available in this library.
 */
(function ($, Drupal, drupalSettings) {
  'use strict';

  const STORAGE_PREFIX = 'repMap.entryPoints';
  const MIN_KEYWORD_LEN = 3;
  const MAX_SEARCH_RESULTS = 200;

  // Module-scoped state so Ajax commands can interact with it safely.
  let selectionMap = new Map(); // uri -> label
  let suppressTreeSync = false;
  let boundEntryPoints = new Set(); // Set of entry point URIs that have bindings

  const DEPRECATED_STATUS_URI = 'http://hadatac.org/ont/vstoi#Deprecated';
  const HIDE_DEPRECATED_STORAGE_KEY = `${STORAGE_PREFIX}.hideDeprecated`;

  let hideDeprecated = false;
  let pendingTreeRequests = 0;
  let pendingSearchRequests = 0;
  let activeSearchToken = 0;

  let $leftTree = null;
  let $rightTree = null;
  let $selectedNodeField = null;
  let $selectedNodesField = null;

  function sanitizeForId(str) {
    return String(str || '').replace(/[^A-Za-z0-9_-]/g, '_');
  }

  function extractLabel(uri) {
    const p = String(uri || '').split(/[#/]/);
    return p[p.length - 1] || String(uri || '');
  }

  function namespaceUri(uri) {
    // Given a full URI, return a prefixed form if it matches a known namespace.
    const namespaces = (drupalSettings.repMap && drupalSettings.repMap.nameSpacesList) || {};
    for (const abbrev in namespaces) {
      if (!Object.prototype.hasOwnProperty.call(namespaces, abbrev)) continue;
      const ns = namespaces[abbrev];
      if (abbrev && ns && String(uri || '').startsWith(ns)) {
        return abbrev + ':' + String(uri).slice(ns.length);
      }
    }
    return String(uri || '');
  }

  function buildDisplayLabel(uri, label) {
    const base = (label && String(label).trim() !== '') ? String(label).trim() : extractLabel(uri);
    return `${base} [${namespaceUri(uri)}]`;
  }

  function readHideDeprecatedPreference() {
    try {
      return localStorage.getItem(HIDE_DEPRECATED_STORAGE_KEY) === '1';
    } catch (_) {
      return false;
    }
  }

  function writeHideDeprecatedPreference(enabled) {
    try {
      localStorage.setItem(HIDE_DEPRECATED_STORAGE_KEY, enabled ? '1' : '0');
    } catch (_) {
      // Ignore.
    }
  }

  function updateBusyUi() {
    const parts = [];
    if (pendingTreeRequests > 0) parts.push(Drupal.t('Loading…'));
    if (pendingSearchRequests > 0) parts.push(Drupal.t('Searching…'));

    const text = parts.join(' ');

    const $busy = $('#rep-map-busy');
    if ($busy.length) {
      $busy.text(text);
    }

    const $searchBtn = $('#rep-map-search-btn');
    if ($searchBtn.length) {
      $searchBtn.prop('disabled', pendingSearchRequests > 0);
    }
  }

  function beginTreeRequest() {
    pendingTreeRequests += 1;
    updateBusyUi();
  }

  function endTreeRequest() {
    pendingTreeRequests = Math.max(0, pendingTreeRequests - 1);
    updateBusyUi();
  }

  function beginSearchRequest() {
    pendingSearchRequests += 1;
    updateBusyUi();
  }

  function endSearchRequest() {
    pendingSearchRequests = Math.max(0, pendingSearchRequests - 1);
    updateBusyUi();
  }

  function setSearchStatus(text) {
    const $el = $('#rep-map-search-status');
    if ($el.length) {
      $el.text(String(text || ''));
    }
  }

  function setSearchProgress(current, total) {
    const $wrap = $('#rep-map-search-progress');
    const $bar = $('#rep-map-search-progress-bar');
    if (!$wrap.length || !$bar.length) return;

    const t = Number(total || 0);
    const c = Number(current || 0);

    if (!t || t <= 0) {
      $wrap.hide();
      $bar.css('width', '0%');
      return;
    }

    const pct = Math.max(0, Math.min(100, Math.round((c / t) * 100)));
    $wrap.show();
    $bar.css('width', pct + '%');
  }

  function getNamespacesMap() {
    return (drupalSettings.repMap && drupalSettings.repMap.nameSpacesList) || {};
  }

  function resolveUriFromInput(raw, fallbackPrefix) {
    const s = String(raw || '').trim();
    if (!s) return '';

    if (/^https?:\/\//i.test(s)) return s;

    const namespaces = getNamespacesMap();

    // CURIE: prefix:local
    let m = s.match(/^([A-Za-z_][A-Za-z0-9_-]*):(.+)$/);
    if (m) {
      const p = m[1].toLowerCase();
      const local = m[2];
      if (namespaces[p]) return String(namespaces[p]) + local;
    }

    // OBO-style: PREFIX_0000000
    m = s.match(/^([A-Za-z_][A-Za-z0-9_-]*)_(\d+)$/);
    if (m) {
      const p = m[1].toLowerCase();
      const local = m[2];
      if (namespaces[p]) return String(namespaces[p]) + local;
    }

    // Only digits: use current namespace.
    m = s.match(/^\d+$/);
    if (m && fallbackPrefix) {
      const p = String(fallbackPrefix).toLowerCase();
      if (namespaces[p]) return String(namespaces[p]) + s;
    }

    return '';
  }

  function isDeprecatedItem(item) {
    const label = String(item?.label || item?.text || '').trim();
    const status = String(item?.hasStatus || item?.data?.hasStatus || '').trim();

    if (item?.deprecated === true || item?.isDeprecated === true) return true;

    if (status) {
      if (status === DEPRECATED_STATUS_URI) return true;
      if (/[#\/]Deprecated$/.test(status)) return true;
    }

    if (/^obsolete\b/i.test(label)) return true;

    return false;
  }

  function maybeFilterDeprecated(items, apply) {
    const arr = Array.isArray(items) ? items : [];
    if (!apply || !hideDeprecated) return arr;
    return arr.filter((it) => !isDeprecatedItem(it));
  }

  function safeJsTree($el) {
    if (!$el || !$el.length) return null;
    if (!$el.data('jstree')) return null;
    try {
      return $el.jstree(true);
    } catch (_) {
      return null;
    }
  }

  function setHiddenSelectionFromMap() {
    if (!$selectedNodesField || !$selectedNodesField.length) return;
    if (!$selectedNodeField || !$selectedNodeField.length) return;

    const uris = Array.from(selectionMap.keys());
    $selectedNodesField.val(JSON.stringify(uris));
    $selectedNodeField.val(uris[0] || '');
  }

  function renderSelectionList() {
    const $count = $('#rep-map-selected-count');
    const $list = $('#rep-map-selected-list');

    if ($count.length) $count.text(String(selectionMap.size));
    if (!$list.length) return;

    $list.empty();

    if (selectionMap.size === 0) {
      $list.append(
        $('<div class="text-muted small"></div>').text(Drupal.t('No nodes selected.'))
      );
      return;
    }

    for (const [uri, label] of selectionMap.entries()) {
      const $row = $('<div class="d-flex align-items-start gap-2 mb-1"></div>');

      const $btn = $('<button type="button" class="btn btn-sm btn-outline-danger"></button>')
        .text(Drupal.t('Remove'))
        .on('click', () => {
          removeSelection(uri, { uncheckTree: true });
        });

      const $txt = $('<span class="small"></span>')
        .text(label || buildDisplayLabel(uri, ''))
        .attr('title', uri);

      $row.append($btn, $txt);
      $list.append($row);
    }
  }

  function addSelection(uri, label) {
    const u = String(uri || '').trim();
    if (!u) return;
    selectionMap.set(u, label || buildDisplayLabel(u, label));
    setHiddenSelectionFromMap();
    renderSelectionList();
  }

  function removeSelection(uri, opts = {}) {
    const u = String(uri || '').trim();
    if (!u) return;

    selectionMap.delete(u);

    if (opts.uncheckTree) {
      const inst = safeJsTree($rightTree);
      if (inst && typeof inst.get_checked === 'function' && typeof inst.uncheck_node === 'function') {
        const checkedNodes = inst.get_checked(true) || [];
        const match = checkedNodes.find(n => n?.data?.realUri === u);
        if (match) {
          suppressTreeSync = true;
          try {
            inst.uncheck_node(match);
          } finally {
            suppressTreeSync = false;
          }
        }
      }
    }

    setHiddenSelectionFromMap();
    renderSelectionList();
  }

  function clearSelection(opts = {}) {
    selectionMap.clear();

    if (opts.uncheckTree) {
      const inst = safeJsTree($rightTree);
      if (inst && typeof inst.uncheck_all === 'function') {
        suppressTreeSync = true;
        try {
          inst.uncheck_all();
          inst.deselect_all();
        } finally {
          suppressTreeSync = false;
        }
      }
    }

    setHiddenSelectionFromMap();
    renderSelectionList();
  }

  function initSelectionFromHidden() {
    if (!$selectedNodesField || !$selectedNodesField.length) return;

    const raw = String($selectedNodesField.val() || '').trim();
    if (!raw) return;

    try {
      const arr = JSON.parse(raw);
      if (Array.isArray(arr)) {
        for (const u of arr) {
          if (typeof u === 'string' && u.trim() !== '') {
            addSelection(u.trim(), buildDisplayLabel(u.trim(), ''));
          }
        }
      }
    } catch (_) {
      // Ignore.
    }
  }

  function injectUiIfNeeded() {
    const $rightCol = $('#right-col-wrapper');
    if (!$rightCol.length) return;

    // Insert search UI just above the ontology tree.
    if (!$('#rep-map-search-wrap').length) {
      const $treeWrapper = $('#ontology-tree').closest('.col-md-12');

      const $search = $(
        '<div class="col-md-12 mb-2" id="rep-map-search-wrap">' +
          '<div class="row g-2">' +
            '<div class="col-md-8">' +
              '<input type="text" class="form-control" id="rep-map-search-keyword" ' +
                'placeholder="' + Drupal.t('Search by label or ID (e.g., uberon:0006259)') + '" />' +
            '</div>' +
            '<div class="col-md-4">' +
              '<button type="button" class="btn btn-secondary w-100" id="rep-map-search-btn">' + Drupal.t('Search') + '</button>' +
            '</div>' +
          '</div>' +
          '<div class="d-flex align-items-center justify-content-between mt-1">' +
            '<div class="form-check">' +
              '<input class="form-check-input" type="checkbox" id="rep-map-hide-deprecated" />' +
              '<label class="form-check-label small" for="rep-map-hide-deprecated">' + Drupal.t('Hide deprecated/obsolete') + '</label>' +
            '</div>' +
            '<div class="small text-muted" id="rep-map-busy"></div>' +
          '</div>' +
          '<div class="small text-muted mt-1" id="rep-map-search-context"></div>' +
          '<div class="small text-muted mt-1" id="rep-map-search-status"></div>' +
          '<div class="mt-1" id="rep-map-search-progress" style="display:none; height:4px; background:#eee;">' +
            '<div id="rep-map-search-progress-bar" style="height:4px; width:0; background:#0d6efd;"></div>' +
          '</div>' +
          '<div class="mt-2" id="rep-map-search-results" style="max-height:220px; overflow:auto;"></div>' +
        '</div>'
      );

      if ($treeWrapper.length) {
        $search.insertBefore($treeWrapper);
      } else {
        $rightCol.append($search);
      }

      const $hide = $('#rep-map-hide-deprecated');
      if ($hide.length && !$hide.data('repMapBound')) {
        $hide.data('repMapBound', true);

        hideDeprecated = readHideDeprecatedPreference();
        $hide.prop('checked', hideDeprecated);

        updateBusyUi();

        $hide.on('change.repMap', () => {
          hideDeprecated = $hide.is(':checked');
          writeHideDeprecatedPreference(hideDeprecated);

          // Refresh the right tree to apply filtering.
          const inst = safeJsTree($rightTree);
          if (inst && typeof inst.refresh === 'function') {
            inst.refresh();
          }

          // Clear search UI.
          setSearchStatus('');
          setSearchProgress(0, 0);
          const $results = $('#rep-map-search-results');
          if ($results.length) $results.empty();
        });
      }
    }

    // Insert selection list just below the ontology tree.
    if (!$('#rep-map-selected-wrap').length) {
      const $treeWrapper = $('#ontology-tree').closest('.col-md-12');

      const $sel = $(
        '<div class="col-md-12 mt-2" id="rep-map-selected-wrap">' +
          '<div class="d-flex align-items-center justify-content-between">' +
            '<div class="small"><strong>' + Drupal.t('Selected to save') + '</strong>: <span id="rep-map-selected-count">0</span></div>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" id="rep-map-clear-selection">' + Drupal.t('Clear') + '</button>' +
          '</div>' +
          '<div class="mt-2" id="rep-map-selected-list" style="max-height:160px; overflow:auto;"></div>' +
        '</div>'
      );

      if ($treeWrapper.length) {
        $sel.insertAfter($treeWrapper);
      } else {
        $rightCol.append($sel);
      }

      $('#rep-map-clear-selection')
        .off('click.repMap')
        .on('click.repMap', (e) => {
          e.preventDefault();
          clearSelection({ uncheckTree: true });
        });
    }

    renderSelectionList();
  }

  function renderSearchResults(items) {
    const $results = $('#rep-map-search-results');
    if (!$results.length) return;

    const src = Array.isArray(items) ? items : [];
    const filtered = maybeFilterDeprecated(src, true);

    $results.empty();

    if (filtered.length === 0) {
      $results.append(
        $('<div class="text-muted small"></div>').text(Drupal.t('No results.'))
      );
      return;
    }

    const limited = filtered.slice(0, MAX_SEARCH_RESULTS);
    if (filtered.length > limited.length) {
      $results.append(
        $('<div class="text-muted small mb-1"></div>').text(
          Drupal.t('Showing @n of @total results.', { '@n': limited.length, '@total': filtered.length })
        )
      );
    }

    limited.forEach((item) => {
      const uri = String(item?.uri || '').trim();
      if (!uri) return;
      const label = buildDisplayLabel(uri, item?.label);

      const selected = selectionMap.has(uri);
      const $row = $('<div class="d-flex align-items-start gap-2 mb-1"></div>');
      const $btn = $('<button type="button" class="btn btn-sm"></button>')
        .addClass(selected ? 'btn-outline-danger' : 'btn-outline-primary')
        .text(selected ? Drupal.t('Remove') : Drupal.t('Add'))
        .on('click', () => {
          if (selectionMap.has(uri)) {
            removeSelection(uri, { uncheckTree: true });
          } else {
            addSelection(uri, label);
          }
          // Update this button state.
          renderSearchResults(src);
        });

      const $txt = $('<span class="small"></span>').text(label).attr('title', uri);
      $row.append($btn, $txt);
      $results.append($row);
    });
  }

  function setSearchContext(label) {
    const $ctx = $('#rep-map-search-context');
    if (!$ctx.length) return;

    if (!label) {
      $ctx.text(Drupal.t('Search context: (select a node on the right tree)'));
      return;
    }

    $ctx.text(Drupal.t('Search context: @label', { '@label': label }));
  }

  /**
   * Fetch bound entry points from the API and store in boundEntryPoints Set.
   */
  function fetchBoundEntryPoints(callback) {
    const base = (window.location.protocol === 'https:' ? 'https://' : 'http://')
      + window.location.host
      + (drupalSettings.path?.baseUrl || '');
    
    const url = base + '/rep/bound-entry-points?_format=json';
    
    $.getJSON(url)
      .done((data) => {
        boundEntryPoints.clear();
        if (data && Array.isArray(data.bound)) {
          data.bound.forEach((uri) => {
            boundEntryPoints.add(String(uri));
          });
        }
        if (callback) callback(true);
      })
      .fail(() => {
        if (callback) callback(false);
      });
  }

  /**
   * Apply color-coding to left tree nodes based on binding status.
   * - GREEN: entry point has bindings (bound)
   * - RED: entry point has no bindings (unbound)
   * Only applies to entry points (URIs ending with "EntryPoint"),
   * not to bound terms or their descendants.
   */
  function updateLeftTreeColors() {
    if (!$leftTree || !$leftTree.length) return;
    
    const inst = safeJsTree($leftTree);
    if (!inst) return;

    // Get all nodes in the tree
    const allNodes = inst.get_json('#', { flat: true });
    
    allNodes.forEach((node) => {
      if (node.data?.isRoot) return; // Skip root node
      
      const uri = node.data?.realUri;
      if (!uri) return;
      
      const $nodeElement = $('#' + node.id + ' > a.jstree-anchor');
      if (!$nodeElement.length) return;
      
      // Remove existing classes
      $nodeElement.removeClass('entry-point-bound entry-point-unbound');
      
      // Only apply colors to entry points (URIs ending with "EntryPoint")
      if (uri.endsWith('EntryPoint')) {
        // This is an entry point - apply color based on binding status
        if (boundEntryPoints.has(uri)) {
          $nodeElement.addClass('entry-point-bound');
        } else {
          $nodeElement.addClass('entry-point-unbound');
        }
      }
      // For all other nodes (bound terms and their descendants), no color is applied
    });
  }

  // jQuery plugin invoked by the server-side Ajax callback.
  if (!$.fn.repMapAfterSave) {
    $.fn.repMapAfterSave = function () {
      // Refresh bound entry points and update colors
      fetchBoundEntryPoints(() => {
        // Refresh LEFT tree so newly ingested mappings show up
        const leftInst = safeJsTree($leftTree);
        if (leftInst && typeof leftInst.refresh === 'function') {
          leftInst.refresh();
        }
      });

      // Clear RIGHT selection and our internal list.
      clearSelection({ uncheckTree: true });

      return this;
    };
  }

  Drupal.behaviors.mapEntryPoints = {
    attach(context) {
      if (context !== document) return; // run once on full page

      const cfg = drupalSettings.repMap || {};
      const apiTopClassEndpoint = cfg.apiTopClassEndpoint || '';
      const apiEndpoint = cfg.apiEndpoint || '';
      const apiSubclassKeywordEndpoint = cfg.apiSubclassKeywordEndpoint || '';
      const apiNodeEndpoint = cfg.apiNodeEndpoint || '';
      const childParam = cfg.childParam || 'nodeUri';

      const $nsSelect = $('.map-ontology-select');

      $leftTree = $('#current-tree');
      $rightTree = $('#ontology-tree');
      $selectedNodeField = $('#edit-selected-node');
      $selectedNodesField = $('#edit-selected-nodes');

      initSelectionFromHidden();
      injectUiIfNeeded();

      let currentRightRootUri = '';
      let searchContextUri = '';
      let searchContextLabel = '';

      function getCoreData(rootUri, rootLabel, side) {
        return function (node, cb) {
          const rootId = 'node_root_' + sanitizeForId(rootUri);
          const rootText = (rootLabel && String(rootLabel).trim()) || extractLabel(rootUri);
          const applyFilter = side === 'right';

          if (node.id === '#') {
            return cb([
              {
                id: rootId,
                text: rootText,
                children: true,
                data: { realUri: rootUri, isRoot: true },
                a_attr: { class: 'root-node', title: rootUri },
              },
            ]);
          }

          if (node.id === rootId) {
            const uri = node.data.realUri;

            beginTreeRequest();
            return $.getJSON(apiTopClassEndpoint, { [childParam]: uri })
              .done((data) => {
                const raw = maybeFilterDeprecated(data, applyFilter);
                const items = raw.map((item) => {
                  const real = item.uri;
                  const id = 'node_' + sanitizeForId(rootUri) + '_' + sanitizeForId(real);
                  const text = buildDisplayLabel(real, item.label);
                  return {
                    id,
                    text,
                    children: true,
                    data: { realUri: real, hasStatus: item.hasStatus || null },
                    a_attr: { title: real },
                  };
                });
                cb(items);
              })
              .fail(() => cb([]))
              .always(() => endTreeRequest());
          }

          const parentReal = node.data.realUri;
          beginTreeRequest();
          return $.getJSON(apiEndpoint, { [childParam]: parentReal })
            .done((data) => {
              const raw = maybeFilterDeprecated(data, applyFilter);
              const children = raw.map((item) => {
                const real = item.uri;
                const id = 'node_' + sanitizeForId(parentReal) + '_' + sanitizeForId(real);
                const text = buildDisplayLabel(real, item.label);
                return {
                  id,
                  text,
                  children: true,
                  data: { realUri: real, hasStatus: item.hasStatus || null },
                  a_attr: { title: real },
                };
              });
              cb(children);
            })
            .fail(() => cb([]))
            .always(() => endTreeRequest());
        };
      }

      function initTree($el, rootUri, rootLabelOverride, side) {
        if (!$el || !$el.length) return;

        if (!rootUri) {
          $el.empty().append(
            $('<div class="text-danger small"></div>').text(Drupal.t('Missing root URI.'))
          );
          return;
        }

        const rootLabel = (rootLabelOverride != null && String(rootLabelOverride).trim() !== '')
          ? rootLabelOverride
          : ($el.data('root-label') || (cfg.currentRootLabel || ''));

        const coreData = getCoreData(rootUri, rootLabel, side);
        const stateKey = `${STORAGE_PREFIX}.state.${side}.${sanitizeForId(rootUri)}`;

        const baseConfig = {
          core: {
            data: coreData,
            check_callback: true,
            force_text: true, // prevent XSS via labels
            multiple: false,
          },
          plugins: ['wholerow', 'state'],
          state: {
            key: stateKey,
          },
        };

        if (side === 'right') {
          baseConfig.plugins = ['wholerow', 'checkbox', 'state'];
          baseConfig.checkbox = {
            three_state: false,
            cascade: '',
            tie_selection: false,
          };
        }

        if ($el.data('jstree')) {
          // Update data and refresh.
          const inst = $el.jstree(true);
          inst.settings.core.data = coreData;
          inst.settings.state.key = stateKey;
          inst.refresh();
          return;
        }

        $el.jstree(baseConfig);
      }

      // 1) Initialize LEFT tree.
      if ($leftTree.length && !$leftTree.data('initialized')) {
        $leftTree.data('initialized', true);
        const initialRoot = $leftTree.data('root-uri') || cfg.currentRootUri || '';
        
        // Attach event handlers BEFORE initializing tree
        $leftTree
          .off('.repMap')
          .on('select_node.jstree.repMap', (e, data) => {
            if (data.node?.data?.isRoot) {
              data.instance.deselect_node(data.node, true);
              e.stopImmediatePropagation();
              return false;
            }

            const uri = data.node?.data?.realUri || '';
            if (uri) {
              $('#edit-selected-entry-point').val(uri);
            }
          })
          .on('changed.jstree.repMap', (_e, data) => {
            const inst = safeJsTree($leftTree);
            if (!inst) return;

            (data.selected || []).forEach((id) => {
              const n = inst.get_node(id);
              if (n?.data?.isRoot) inst.deselect_node(n, true);
            });
          })
          .on('activate_node.jstree.repMap', (e, data) => {
            if (data.node?.data?.isRoot) {
              e.stopImmediatePropagation();
              return false;
            }
          })
          .on('ready.jstree.repMap', () => {
            // Fetch bound status and apply colors when tree is ready
            fetchBoundEntryPoints(() => {
              updateLeftTreeColors();
            });
          })
          .on('refresh.jstree.repMap', () => {
            // Fetch bound status and apply colors when tree is refreshed
            fetchBoundEntryPoints(() => {
              updateLeftTreeColors();
            });
          })
          .on('open_node.jstree.repMap', () => {
            // Apply colors when nodes are expanded (in case new nodes are loaded)
            setTimeout(() => updateLeftTreeColors(), 100);
          });
        
        // Now initialize tree (ready event will fire and apply colors)
        initTree($leftTree, initialRoot, null, 'left');
      }

      // 2) LEFT tree selection handlers are already attached above.

      // 3) Load RIGHT tree based on selected namespace.
      function loadRightTree() {
        const base = $nsSelect.val() || '';
        if (!base) {
          alert(Drupal.t('Please select a Namespace first.'));
          return;
        }

        const nsCode = $nsSelect.find(':selected').text();

        // If root changed, destroy for a clean init (keeps per-root state keys).
        if ($rightTree.data('jstree') && currentRightRootUri && currentRightRootUri !== base) {
          try {
            $rightTree.jstree('destroy');
          } catch (_) {
            // Ignore.
          }
          $rightTree.empty();
        }

        currentRightRootUri = base;
        initTree($rightTree, base, nsCode, 'right');
      }

      // Persist namespace selection per left-root (class vs instance pages).
      const nsStorageKey = `${STORAGE_PREFIX}.namespace.${sanitizeForId(cfg.currentRootUri || $leftTree.data('root-uri') || 'default')}`;

      let restoredNs = false;
      try {
        if ($nsSelect.length && (!$nsSelect.val() || String($nsSelect.val()).trim() === '')) {
          const saved = localStorage.getItem(nsStorageKey);
          if (saved) {
            $nsSelect.val(saved);
            if ($nsSelect.val() === saved) {
              restoredNs = true;
            }
          }
        }
      } catch (_) {
        // Ignore.
      }

      if (restoredNs && $nsSelect.val()) {
        loadRightTree();
      }

      $nsSelect
        .off('change.repMap')
        .on('change.repMap', () => {
          const v = $nsSelect.val() || '';
          try {
            if (v) localStorage.setItem(nsStorageKey, v);
          } catch (_) {
            // Ignore.
          }
          loadRightTree();
        });

      $('#edit-load-tree')
        .off('click.repMap')
        .on('click.repMap', (e) => {
          e.preventDefault();
          loadRightTree();
        });

      // 4) RIGHT tree events: search context + multi-selection via checkboxes.
      $rightTree
        .off('.repMap')
        .on('select_node.jstree.repMap', (e, data) => {
          if (data.node?.data?.isRoot) {
            data.instance.deselect_node(data.node, true);
            e.stopImmediatePropagation();
            return false;
          }

          searchContextUri = data.node?.data?.realUri || '';
          searchContextLabel = data.node?.text || '';
          setSearchContext(searchContextLabel);
        })
        .on('check_node.jstree.repMap', (e, data) => {
          if (suppressTreeSync) return;

          if (data.node?.data?.isRoot) {
            suppressTreeSync = true;
            try {
              data.instance.uncheck_node(data.node);
            } finally {
              suppressTreeSync = false;
            }
            e.stopImmediatePropagation();
            return false;
          }

          const uri = data.node?.data?.realUri || '';
          const text = data.node?.text || buildDisplayLabel(uri, '');
          if (uri) {
            addSelection(uri, text);

            // Convenience: if no search context is set yet, use the first checked node.
            if (!searchContextUri) {
              searchContextUri = uri;
              searchContextLabel = text;
              setSearchContext(searchContextLabel);
            }
          }
        })
        .on('uncheck_node.jstree.repMap', (_e, data) => {
          if (suppressTreeSync) return;
          const uri = data.node?.data?.realUri || '';
          if (uri) removeSelection(uri);
        })
        .on('ready.jstree.repMap', () => {
          // If a node is already selected (state plugin), show it as context.
          const inst = safeJsTree($rightTree);
          if (!inst) {
            setSearchContext('');
            return;
          }

          const sel = inst.get_selected(true) || [];
          const node = sel[0];
          if (node && !node.data?.isRoot) {
            searchContextUri = node.data?.realUri || '';
            searchContextLabel = node.text || '';
            setSearchContext(searchContextLabel);
          } else {
            setSearchContext('');
          }
        });

      // Ensure selection UI is consistent.
      setHiddenSelectionFromMap();
      renderSelectionList();

      // 5) Search (API-backed; no need to expand nodes).
      $('#rep-map-search-btn')
        .off('click.repMap')
        .on('click.repMap', async (e) => {
          e.preventDefault();

          // Cancel any previous search-in-flight.
          activeSearchToken += 1;
          const token = activeSearchToken;

          const $results = $('#rep-map-search-results');
          if ($results.length) {
            $results.empty().append(
              $('<div class="text-muted small"></div>').text(Drupal.t('Searching...'))
            );
          }
          setSearchStatus('');
          setSearchProgress(0, 0);

          const keyword = String($('#rep-map-search-keyword').val() || '').trim();
          if (keyword.length < MIN_KEYWORD_LEN) {
            if ($results.length) {
              $results.empty().append(
                $('<div class="text-muted small"></div>').text(
                  Drupal.t('Please enter at least @n characters.', { '@n': MIN_KEYWORD_LEN })
                )
              );
            }
            return;
          }

          const nsPrefix = String($nsSelect.find(':selected').text() || '').trim().toLowerCase();
          const resolvedUri = resolveUriFromInput(keyword, nsPrefix);

          // 5.1) Direct lookup for IDs/CURIEs (fastest).
          if (resolvedUri && apiNodeEndpoint) {
            setSearchStatus(Drupal.t('Looking up @id…', { '@id': keyword }));
            beginSearchRequest();
            try {
              const node = await $.getJSON(apiNodeEndpoint, { [childParam]: resolvedUri });
              if (token !== activeSearchToken) return;

              if (node && node.uri) {
                if (hideDeprecated && isDeprecatedItem(node)) {
                  if ($results.length) $results.empty();
                  setSearchStatus(Drupal.t('Result is deprecated/obsolete and hidden by the filter.'));
                  return;
                }

                renderSearchResults([node]);
                setSearchStatus(Drupal.t('Found 1 result.'));
                return;
              }
            }
            catch (_) {
              // Fall through to keyword search.
            }
            finally {
              endSearchRequest();
            }

            if (token !== activeSearchToken) return;
          }

          // 5.2) Keyword search under selected context (fast).
          if (apiSubclassKeywordEndpoint && searchContextUri) {
            setSearchStatus(Drupal.t('Searching under selected context…'));
            beginSearchRequest();
            try {
              const data = await $.getJSON(apiSubclassKeywordEndpoint, {
                superuri: searchContextUri,
                keyword,
              });
              if (token !== activeSearchToken) return;

              renderSearchResults(data || []);
              setSearchStatus(Drupal.t('Done.'));
            }
            catch (_) {
              if (token !== activeSearchToken) return;

              renderSearchResults([]);
              if ($results.length) {
                $results.empty().append(
                  $('<div class="text-danger small"></div>').text(Drupal.t('Search request failed.'))
                );
              }
              setSearchStatus(Drupal.t('Search failed.'));
            }
            finally {
              endSearchRequest();
            }
            return;
          }

          // 5.3) Global keyword search across top classes (slower).
          if (!currentRightRootUri) {
            if ($results.length) {
              $results.empty().append(
                $('<div class="text-muted small"></div>').text(
                  Drupal.t('Load the ontology tree first (or pick a context node) to search.')
                )
              );
            }
            setSearchStatus('');
            return;
          }

          if (!apiTopClassEndpoint || !apiSubclassKeywordEndpoint) {
            if ($results.length) {
              $results.empty().append(
                $('<div class="text-muted small"></div>').text(Drupal.t('Search is not available.'))
              );
            }
            return;
          }

          setSearchStatus(Drupal.t('Global search: loading top classes…'));

          let topClasses = [];
          beginSearchRequest();
          try {
            topClasses = await $.getJSON(apiTopClassEndpoint, { [childParam]: currentRightRootUri });
          }
          catch (_) {
            topClasses = [];
          }
          finally {
            endSearchRequest();
          }

          if (token !== activeSearchToken) return;

          const rawTop = maybeFilterDeprecated(topClasses, true);
          const TOP_LIMIT = 40;
          const slice = rawTop.slice(0, TOP_LIMIT);

          if (!slice.length) {
            if ($results.length) {
              $results.empty().append(
                $('<div class="text-muted small"></div>').text(Drupal.t('No top classes available.'))
              );
            }
            setSearchStatus('');
            return;
          }

          const resultsMap = new Map();
          setSearchProgress(0, slice.length);

          for (let i = 0; i < slice.length; i += 1) {
            if (token !== activeSearchToken) return;

            const superuri = slice[i]?.uri;
            if (!superuri) continue;

            setSearchStatus(Drupal.t('Global search: @i/@n…', { '@i': i + 1, '@n': slice.length }));
            setSearchProgress(i + 1, slice.length);

            beginSearchRequest();
            try {
              const data = await $.getJSON(apiSubclassKeywordEndpoint, { superuri, keyword });
              const arr = maybeFilterDeprecated(data, true);
              arr.forEach((it) => {
                if (it && it.uri) resultsMap.set(it.uri, it);
              });
              if (resultsMap.size >= MAX_SEARCH_RESULTS) break;
            }
            catch (_) {
              // Ignore.
            }
            finally {
              endSearchRequest();
            }
          }

          if (token !== activeSearchToken) return;

          setSearchProgress(0, 0);

          const out = Array.from(resultsMap.values());
          renderSearchResults(out);
          setSearchStatus(Drupal.t('Found @n results.', { '@n': out.length }));
        });
    },
  };

})(jQuery, Drupal, drupalSettings);
