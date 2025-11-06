/**
 * Map Entry Points UI:
 * - LEFT tree: starts from settings root (rep.settings: repository_namespace_url).
 * - RIGHT tree: browse a chosen namespace and pick a node to map.
 *
 * Endpoints (JSON):
 *   - cfg.apiTopClassEndpoint?nodeUri=<URI>
 *   - cfg.apiEndpoint?nodeUri=<URI>
 *
 * Requires jsTree to be available in this library.
 */
(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.mapEntryPoints = {
    attach(context) {
      if (context !== document) return; // run once on full page

      const cfg = drupalSettings.repMap || {};
      const apiTopClassEndpoint = cfg.apiTopClassEndpoint || '';
      const apiEndpoint         = cfg.apiEndpoint || '';
      const childParam          = cfg.childParam || 'nodeUri';

      const $nsSelect = $('.map-ontology-select');
      const $left     = $('#current-tree');

      // --- helpers -----------------------------------------------------------
      function sanitizeForId(str) {
        return String(str || '').replace(/[^A-Za-z0-9_-]/g, '_');
      }
      function extractLabel(uri) {
        const p = String(uri).split(/[#/]/);
        return p[p.length - 1] || uri;
      }

      // ⬇️ add rootLabel param here
      function getCoreData(rootUri, rootLabel) {
        return function (node, cb) {
          const rootId   = 'node_root_' + sanitizeForId(rootUri);
          const rootText = (rootLabel && String(rootLabel).trim()) || extractLabel(rootUri);

          // Initial virtual node
          if (node.id === '#') {
            return cb([{
              id: rootId,
              text: rootText,
              children: true,
              data: { realUri: rootUri, isRoot: true },
              a_attr: { class: 'root-node', title: rootUri }
            }]);
          }

          // Expanding the root: load top classes
          if (node.id === rootId) {
            const uri = node.data.realUri;
            return $.getJSON(apiTopClassEndpoint, { [childParam]: uri })
              .done(data => {
                const items = (data || []).map(item => {
                  const real  = item.uri;
                  const id    = 'node_' + sanitizeForId(rootUri) + '_' + sanitizeForId(real);
                  const label = item.label +" ("+namespaceUri(item.uri) +")" || extractLabel(real);
                  return { id, text: label, children: true, data: { realUri: real } };
                });
                cb(items);
              })
              .fail(() => cb([]));
          }

          // Deeper levels: load children
          const parentReal = node.data.realUri;
          $.getJSON(apiEndpoint, { [childParam]: parentReal })
            .done(data => {
              const children = (data || []).map(item => {
                const real  = item.uri;
                const id    = 'node_' + sanitizeForId(parentReal) + '_' + sanitizeForId(real);
                const label = item.label + " ("+namespaceUri(item.uri) +")" || extractLabel(real);
                return { id, text: label, children: true, data: { realUri: real } };
              });
              cb(children);
            })
            .fail(() => cb([]));
        };
      }

      function drawTree($el, rootUri, rootLabelOverride) {
        if (!$el || !$el.length) return;
        if (!rootUri) {
          $el.empty().append('<div class="text-danger small">Missing root URI.</div>');
          return;
        }
        // prefer explicit override → per-element data → global left-tree label
        const rootLabel = (rootLabelOverride != null && String(rootLabelOverride).trim() !== '')
          ? rootLabelOverride
          : ($el.data('root-label') || (drupalSettings.repMap && drupalSettings.repMap.currentRootLabel) || '');

        const coreData = getCoreData(rootUri, rootLabel);  // <-- pass label here

        if ($el.data('jstree')) {
          $el.jstree(true).settings.core.data = coreData;
          $el.jstree(true).refresh();
        } else {
          $el.jstree({
            core: { data: coreData, check_callback: true, force_text: false },
            plugins: ['wholerow']
          });
        }
      }

      // ----------------------------------------------------------------------

      // 1) Initialize LEFT tree from settings/data attribute.
      if (!$left.data('initialized')) {
        $left.data('initialized', true);
        const initialRoot  = $left.data('root-uri')    || cfg.currentRootUri  || '';
        drawTree($left, initialRoot);
      }

      // Update the entry-point hidden field if user clicks a node on the LEFT.
      $('#current-tree')
        .off('.prevent_root')
        // When a node gets selected, immediately undo if it's the root
        .on('select_node.jstree.prevent_root', (e, data) => {
          if (data.node?.data?.isRoot) {
            data.instance.deselect_node(data.node, true); // true = suppress events
            e.stopImmediatePropagation();
            return false;
          }
          // valid selection: store as entry point
          const uri = data.node?.data?.realUri || '';
          if (uri) $('#edit-selected-entry-point').val(uri);
        })
        // Extra safety: if a selection change slips through, clean it up
        .on('changed.jstree.prevent_root', (e, data) => {
          const inst = $('#current-tree').jstree(true);
          (data.selected || []).forEach(id => {
            const n = inst.get_node(id);
            if (n?.data?.isRoot) inst.deselect_node(n, true);
          });
        })
        // Also block keyboard “activation” on the root (Enter/Space)
        .on('activate_node.jstree.prevent_root', (e, data) => {
          if (data.node?.data?.isRoot) {
            e.stopImmediatePropagation();
            return false;
          }
        });

      // 2) Load RIGHT tree on button click.
      $('#edit-load-tree')
        .off('click')
        .on('click', (e) => {
          e.preventDefault();
          const base = $nsSelect.val() || '';
          if (!base) {
            alert(Drupal.t('Please select a Namespace first.'));
            return;
          }
          $('#edit-selected-node').val(base);
          const nsCode = $nsSelect.find(':selected').text();
          drawTree($('#ontology-tree'), base, nsCode);
        });

      // 3) Keep the selected node URI (RIGHT tree) in a hidden field.
      $('#ontology-tree')
        .off('changed.jstree')
        .on('changed.jstree', (_, data) => {
          if (!data.selected.length) return;
          const inst = $('#ontology-tree').jstree(true);
          const node = inst.get_node(data.selected[0]);
          const uri  = node?.data?.realUri || '';
          $('#edit-selected-node').val(uri);
        });
    }
  };

  function namespaceUri(uri) {
    // Given a full URI, return a prefixed form if it matches a known namespace
    var namespaces = (drupalSettings.repMap && drupalSettings.repMap.nameSpacesList) || {};
    for (var abbrev in namespaces) {
      if (!namespaces.hasOwnProperty(abbrev)) continue;
      var ns = namespaces[abbrev];
      if (abbrev && ns && uri.startsWith(ns)) {
        return abbrev + ":" + uri.slice(ns.length);
      }
    }
    return uri;
  }

})(jQuery, Drupal, drupalSettings);
