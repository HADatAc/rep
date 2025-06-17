  /**
   * @file
   * Behavior to map Entry Points to ontology nodes, fully client-side,
   * with debug‐logging, delete icons, and merged mapped+API children.
   */
  (function ($, Drupal, drupalSettings) {
    'use strict';

    Drupal.behaviors.mapEntryPoints = {
      attach(context) {

        function sanitizeForId(str) {
          return str.replace(/[^A-Za-z0-9_-]/g, '_');
        }

        // only run once on full document load
        if (context !== document) return;
        // console.log('[repMap] attach start (full document)');

        // 1) load config
        const cfg = drupalSettings.repMap || {};
        const {
          entryConstants = {},
          entryMappings = {},
          namespaceBaseUris = {},
          apiEndpoint = '',
          childParam = 'nodeUri'
        } = cfg;
        // console.log('[repMap] cfg', cfg);

        const $epSelect = $('.map-entry-point-select');
        const $nsSelect = $('.map-ontology-select');

        // 2) helper: extract local name from a URI
        function extractLabel(uri) {
          const parts = uri.split(/[#\/]/);
          return parts[parts.length - 1];
        }

        /**
         * 3) returns a jsTree core.data callback bound to our API,
         * merging in any mapped URIs (toOpen) first when opening the root node.
         */
        // function getCoreData(base, rootUri, toOpen=[]) {
        //   return function (node, callback) {
        //     const rootId = 'node_root_' + sanitizeForId(rootUri);
        //     // ---- root‐of‐all pseudo‐node: show only the one root
        //     if (node.id === '#') {
        //       // console.log('[repMap] getCoreData: root node requested →', rootUri);
        //       return callback([{
        //         id:       rootId,
        //         text:     extractLabel(rootUri),
        //         children: true,
        //         data:     { realUri: rootUri }
        //       }]);
        //     }

        //     // ---- special case: expanding the rootUri itself
        //     if (node.id === rootId) {
        //       // console.log('[repMap] getCoreData: expanding ROOT, injecting mapped URIs:', toOpen);
        //       // build mapped‐nodes array:
        //       const mappedNodes = (toOpen || []).map(u => {
        //         const nodeId = 'node_' + sanitizeForId(rootUri) + '_' + sanitizeForId(u);
        //         const label  = extractLabel(u);
        //         return {
        //           id:       nodeId,
        //           // adiciona um span com classe recycle-bin após o label
        //           text:     `${label} <span class="recycle-bin" title="Remover mapeamento">🗑</span>`,
        //           children: true,
        //           data:     { realUri: u }
        //         };
        //       });

        //       // now fetch the API children for the same root
        //       const parentReal = node.data.realUri;
        //       let uri = parentReal;
        //       if (!/^https?:\/\//.test(uri)) {
        //         uri = base.replace(/\/$/, '') + '/' + uri;
        //       }
        //       // console.log('[repMap] getCoreData: AJAX fetch children for', uri);
        //       return $.getJSON(apiEndpoint, { [childParam]: uri })
        //         // .done(data => {
        //         //   // console.log('[repMap] getCoreData AJAX done, data:', data);
        //         //   const apiNodes = $.map(data, item => {
        //         //     let childUri = item.uri;
        //         //     if (!/^https?:\/\//.test(childUri)) {
        //         //       childUri = base.replace(/\/$/, '') + '/' + childUri;
        //         //     }
        //         //     const nodeId = 'node_' + sanitizeForId(rootUri) + '_' + sanitizeForId(childUri);
        //         //     return {
        //         //       id:       nodeId,
        //         //       text:     item.label || extractLabel(childUri),
        //         //       children: true,
        //         //       data:     { realUri: childUri }
        //         //     };
        //         //   });
        //         //   // merge mapped first, then API
        //         //   const apiFiltered = apiNodes.filter(n => !toOpen.includes(n.data.realUri));
        //         //   // e então concatena só os novos
        //         //   callback(mappedNodes.concat(apiFiltered));
        //         // })
        //         .done(data => {
        //           const apiNodes = $.map(data, item => {
        //             // monta o URI absoluto
        //             let childUri = item.uri;
        //             if (!/^https?:\/\//.test(childUri)) {
        //               childUri = base.replace(/\/$/, '') + '/' + childUri;
        //             }
        //             const nodeId = 'node_' + sanitizeForId(rootUri) + '_' + sanitizeForId(childUri);
        //             // label base
        //             const label = item.label || extractLabel(childUri);
        //             // veja se já estava mapeado
        //             const isMapped = toOpen.includes(childUri);
        //             // injete o caixote quando for mapped
        //             const text = isMapped
        //               ? `${label} <span class="recycle-bin" title="Remover mapeamento">🗑</span>`
        //               : label;

        //             return {
        //               id:       nodeId,
        //               text,
        //               children: true,
        //               data:     { realUri: childUri }
        //             };
        //           });

        //           // filtre só os novos e concatene, como antes
        //           const apiFiltered = apiNodes.filter(n => !toOpen.includes(n.data.realUri));
        //           callback(mappedNodes.concat(apiFiltered));
        //         })
        //         .fail(() => {
        //           console.error('[repMap] getCoreData AJAX failed for', uri);
        //           callback(mappedNodes);  // at least show mapped
        //         });
        //     }

        //     // ---- all other nodes: just fetch API children
        //     const parentReal = node.data.realUri;
        //     let uri = parentReal;
        //     if (!/^https?:\/\//.test(uri)) {
        //       uri = base.replace(/\/$/, '') + '/' + parentReal;
        //     }
        //     // console.log('[repMap] getCoreData: AJAX fetch children for', uri);

        //     // $.getJSON(apiEndpoint, { [childParam]: uri })
        //     // .done(data => {
        //     //   // console.log('[repMap] getCoreData AJAX done, data:', data);
        //     //   const children = data.map(item => {
        //     //     // monta o URI absoluto do filho
        //     //     const real = /^https?:\/\//.test(item.uri)
        //     //       ? item.uri
        //     //       : base.replace(/\/$/, '') + '/' + item.uri;
        //     //     // usa sempre o mesmo padrão de ID para esse realUri
        //     //     const nodeId = 'node_' + sanitizeForId(parentReal) + '_' + sanitizeForId(real);
        //     //     return {
        //     //       id:       nodeId,
        //     //       text:     item.label || extractLabel(real),
        //     //       children: true,
        //     //       data:     { realUri: real }
        //     //     };
        //     //   });
        //     //   callback(children);
        //     // })
        //     // .fail(() => {
        //     //   console.error('[repMap] getCoreData AJAX failed for', uri);
        //     //   callback([]);
        //     // });
        //     // ---- all other nodes: just fetch API children
        //     $.getJSON(apiEndpoint, { [childParam]: uri })
        //       .done(data => {
        //         const children = data.map(item => {
        //           // 1) monta o URI absoluto
        //           const real = /^https?:\/\//.test(item.uri)
        //             ? item.uri
        //             : base.replace(/\/$/, '') + '/' + item.uri;
        //           // 2) monta o ID do nó
        //           const nodeId = 'node_' + sanitizeForId(parentReal) + '_' + sanitizeForId(real);
        //           // 3) extrai o label
        //           const label = item.label || extractLabel(real);
        //           // 4) verifica se já está mapeado
        //           const isMapped = toOpen.includes(real);
        //           // 5) injeta o caixote se for mapped
        //           const text = isMapped
        //             ? `${label} <span class="recycle-bin" title="Remover mapeamento">🗑</span>`
        //             : label;

        //           return {
        //             id:       nodeId,
        //             text,
        //             children: true,
        //             data:     { realUri: real }
        //           };
        //         });
        //         callback(children);
        //       })
        //       .fail(() => {
        //         console.error('[repMap] getCoreData AJAX failed for', uri);
        //         callback([]);
        //       });
        //   };
        // }
        function getCoreData(base, rootUri, toOpen = []) {
          return function (node, callback) {
            console.log('=== toOpen para este EP ===', toOpen);
            const rootId = 'node_root_' + sanitizeForId(rootUri);

            // 1) nó “ # ” → desenha só o root
            if (node.id === '#') {
              return callback([{
                id:       rootId,
                text:     extractLabel(rootUri),
                children: true,
                data:     { realUri: rootUri }
              }]);
            }

            // 2) expandindo o próprio rootUri → mescla mappedNodes + API
            if (node.id === rootId) {
              // mapeados “puros”
              const mappedNodes = toOpen.map(u => {
                const id    = 'node_' + sanitizeForId(rootUri) + '_' + sanitizeForId(u);
                const label = extractLabel(u);
                return {
                  id,
                  text,               // apenas o label
                  children: true,
                  data: {
                    realUri: u,
                    mapped: true      // sinaliza que este veio de toOpen
                  },
                  a_attr: {
                    class: 'mapped-node'
                  }
                };
              });

              // fetch API children do root
              let uri = node.data.realUri;
              if (!/^https?:\/\//.test(uri)) {
                uri = base.replace(/\/$/, '') + '/' + uri;
              }
              return $.getJSON(apiEndpoint, { [childParam]: uri })
                .done(data => {
                  const api = data.map(item => {
                    let real = /^https?:\/\//.test(item.uri)
                      ? item.uri
                      : base.replace(/\/$/, '') + '/' + item.uri;
                    const id    = 'node_' + sanitizeForId(rootUri) + '_' + sanitizeForId(real);
                    const label = item.label || extractLabel(real);
                    console.log('comparando', real, 'com', toOpen);
                    const text  = label;
                    const isMapped = toOpen.includes(real);
                    return {
                      id,
                      text,
                      children: true,
                      data: {
                        realUri: real,
                        mapped: isMapped
                      },
                      a_attr: {
                        class: isMapped ? 'mapped-node' : ''
                      }
                    };

                  })
                  // remove da API quem já veio em mappedNodes
                  .filter(n => !toOpen.includes(n.data.realUri));

                  callback(mappedNodes.concat(api));
                })
                .fail(() => {
                  callback(mappedNodes);
                });
            }

            // 3) todos os outros níveis: só API + icon se for mapped
            const parentReal = node.data.realUri;
            let uri = /^https?:\/\//.test(parentReal)
              ? parentReal
              : base.replace(/\/$/, '') + '/' + parentReal;

            $.getJSON(apiEndpoint, { [childParam]: uri })
              .done(data => {
                const children = data.map(item => {
                  let real = /^https?:\/\//.test(item.uri)
                    ? item.uri
                    : base.replace(/\/$/, '') + '/' + item.uri;
                  const id    = 'node_' + sanitizeForId(parentReal) + '_' + sanitizeForId(real);
                  const label = item.label || extractLabel(real);
                  const isMapped = toOpen.includes(real);
                  const text  = isMapped
                    ? `${label} <span class="recycle-bin" title="Remover mapeamento">🗑</span>`
                    : label;

                  console.log('node.text para', real || u, '→', text);
                  return { id, text, children: true, data: { realUri: real } };
                });
                callback(children);
              })
              .fail(() => {
                callback([]);
              });
          };
        }


        /**
         * 4) draw or refresh a jsTree on $el at rootUri,
         * then open any nodes in toOpen[] automatically.
         */
        function drawTree($el, rootUri, toOpen = []) {

          if ($el === null || $el === '') return;

          // console.log('[repMap] drawTree →', rootUri, toOpen);
          const base = $nsSelect.find(':selected').data('base-uri') || '';
          // console.log('[repMap] drawTree: using baseUri:', base);

          // bind our AJAX + mapped loader
          const coreData = getCoreData(base, rootUri, toOpen);

          if ($el.data('jstree')) {
            // console.log('[repMap] drawTree: refresh existing jsTree');
            $el.jstree(true).settings.core.data = coreData;
            $el.jstree(true).refresh();
          } else {
            // console.log('[repMap] drawTree: create new jsTree');
            $el.jstree({
              core: {
                data: coreData,
                check_callback: true,
                force_text: false,
                escape: false
              },
              plugins: ['contextmenu', 'wholerow'],
              contextmenu: {
                items: node => {
                  const mapped = entryMappings[$epSelect.val()] || [];
                  if (mapped.indexOf(node.id) !== -1) {
                    return {
                      deleteMapping: {
                        label: '🗑 Remove mapping',
                        _action: () => Drupal.behaviors.mapEntryPoints.deleteMapping(node)
                      }
                    };
                  }
                  return {};
                }
              }
            });
          }

          // once ready, open the mapped children
          $el.off('ready.jstree.open')
            .on('ready.jstree.open', () => {
              // console.log('[repMap] ready.jstree.open: opening saved nodes', toOpen);
              const inst = $el.jstree(true);
              (toOpen || []).forEach(uri => inst.open_node(uri));
            });
        }

        // 5) annotate EP <option>s with their root URIs
        $('.map-entry-point-select option').each((_, o) => {
          const key = o.value;
          const uri = entryConstants[key] || '';
          $(o).attr('data-root-uri', uri);
        });

        // 6) annotate ontology <option>s with base URIs
        $('.map-ontology-select option').each((_, o) => {
          const key = o.value;
          if (namespaceBaseUris[key]) {
            const baseUri = namespaceBaseUris[key].replace(/[#\/]+$/, '') + '/';
            $(o).attr('data-base-uri', baseUri);
          }
        });

        // 7) initialize LEFT tree once
        const $left = $('#current-tree');
        if (!$left.data('initialized')) {
          $left.data('initialized', true);
          const initialKey  = $epSelect.val();
          const initialRoot = $epSelect.find(':selected').data('root-uri');
          const initialOpen = entryMappings[initialKey] || [];
          // console.log('[repMap] initial EP key:', initialKey);
          // console.log('[repMap] initial root URI:', initialRoot);
          // console.log('[repMap] initial mapped URIs:', initialOpen);
          drawTree($left, initialRoot, initialOpen);
        }

        // depois de inicializar/refrescar a árvore:
        $('#current-tree')
        // intercepta cliques no ícone de lixeira
        .off('click.recycle')
        .on('click.recycle', '.recycle-bin', function(e) {
          e.stopPropagation();  // para não abrir/selecionar o nó
          const $li   = $(this).closest('li');
          const inst  = $('#current-tree').jstree(true);
          const node  = inst.get_node($li.attr('id'));
          // chama sua rotina existing de remoção
          Drupal.behaviors.mapEntryPoints.deleteMapping(node);
        });

        $('#current-tree')
        .off('select_node.jstree')
        .on('select_node.jstree', (e, data) => {
          const uri = data.node.data.realUri;
          // console.log('[repMap] LEFT select_node →', uri);
          // 1) atualiza o hidden do entry-point
          $('#edit-selected-entry-point').val(uri);
          // 2) continua atualizando o selected_node pro nó da direita
          //    (se você faz isso aqui ou mantém do lado direito, tanto faz)
          // $('#edit-selected-node').val(uri_right);
        });

        // 8) when EP changes, redraw LEFT tree
        $epSelect.off('change').on('change', () => {
          const key     = $epSelect.val();
          const root    = $epSelect.find(':selected').data('root-uri');
          const toOpen  = entryMappings[key] || [];
          // console.log('[repMap] EP changed →', key, root, toOpen);
          drawTree($left, root, toOpen);
        });

        // 9) Load RIGHT tree on button click
        $('#edit-load-tree')
          .off('click')
          .on('click', e => {
            e.preventDefault();
            // console.log('[repMap] Load RIGHT tree');
            const base  = $nsSelect.find(':selected').data('base-uri') || '';
            const label = $('#edit-custom-root').val().trim();
            if (!label) {
              return alert(Drupal.t('Please enter a class label, e.g. “Agent”.'));
            }
            const uri = base.replace(/\/$/, '') + '/' + label;
            $('#edit-selected-node').val(uri);
            drawTree($('#ontology-tree'), uri, []);
          });

        // 10) update hidden field on RIGHT selection
        $('#ontology-tree')
          .off('changed.jstree')
          .on('changed.jstree', (_, data) => {
            if (!data.selected.length) return;
            const inst = $('#ontology-tree').jstree(true);
            const node = inst.get_node(data.selected[0]);
            const uri  = node.data.realUri;                     // <<< usa a URI real
            $('#edit-selected-node').val(uri);
          });

        // console.log('[repMap] attach end');
      },

      /**
       * Delete a saved mapping for the given node.
       */
      deleteMapping(node) {
        const ep   = $('.map-entry-point-select').val();
        const uri  = encodeURIComponent(node.id);
        // console.log('[repMap] deleteMapping → EP=', ep, ' URI=', node.id);
        $.ajax({
          method: 'POST',
          url: Drupal.url(`rep/map/delete/${ep}/${uri}`),
          dataType: 'json'
        })
        .done(res => {
          if (res.success) {
            // console.log('[repMap] mapping removed', node.id);
            $('#current-tree').jstree(true).delete_node(node);
          }
          else {
            console.error('[repMap] could not remove mapping:', res.message);
            alert('Could not remove mapping: ' + res.message);
          }
        })
        .fail(() => {
          console.error('[repMap] AJAX error while deleting mapping');
          alert('AJAX error while deleting mapping.');
        });
      }
    };

  })(jQuery, Drupal, drupalSettings);
