<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Entity\Tables;
use Drupal\rep\EntryPoints;
use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\SettingsCommand;

/**
 * Form to map Entry Points to Ontology nodes.
 *
 * Left column:
 *   - select an entry-point constant
 *   - display its current mapping tree
 *
 * Right column:
 *   - select an ontology namespace (6-column width)
 *   - enter an ontology entry-point class label (3-column)
 *   - load & browse the ontology tree from that point (3-column)
 */
class MapEntryPointsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_map_entry_points_form';
  }

  /**
   * {@inheritdoc}
   *
   * Build the mapping form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // 1) Services & constants
    $tables     = new Tables(\Drupal::database());
    $namespaces = $tables->getNamespaces();

    $reflection = new \ReflectionClass(EntryPoints::class);
    $constants  = $reflection->getConstants();

    // 1a) Load any saved mapping so we can override the default constant URI.
    //     getAllMappings() returns [ entry_point_uri => node_uri, … ].
    $all_mappings = $tables->getAllMappings();

    // Build a key=>uri map for drupalSettings
    $entry_root_uris = [];
    foreach ($constants as $const => $uri) {
      $key = strtolower($const);
      // if you’ve saved something in DB, use that; else use the constant
      $entry_root_uris[$key] = $all_mappings[$uri] ?? $uri;
    }

    // 2) Entry-point dropdown options
    $entry_options = [];
    foreach ($constants as $const => $uri) {
      $entry_options[strtolower($const)] = $this->t(
        ucwords(strtolower(str_replace('_', ' ', $const)))
      );
    }

    // pick up the user’s selection if it exists; else default to the very first constant
    if ($form_state->hasValue('entry_point') && $form_state->getValue('entry_point') !== NULL) {
      $selected_ep_key = $form_state->getValue('entry_point');
    }
    else {
      // $selected_ep_key = key($entry_options);
      $selected_ep_key = '';
    }

    // now derive the constant URI and DB mappings for *that* key
    $constant_uri    = $constants[strtoupper($selected_ep_key)];
    $mapped_nodes    = $tables->getMappingsForEntryPoint($constant_uri);

    // dpm($mapped_nodes);


    // debug temporário
    // \Drupal::logger('rep')->debug('Mapped nodes for @ep: <pre>@nodes</pre>', [
    //   '@ep'    => $constant_uri,
    //   '@nodes' => print_r($mapped_nodes, TRUE),
    // ]);

    // 3) Namespace dropdown options
    $ns_options  = array_combine(array_values($namespaces), array_keys($namespaces));

    // preserve current or default to first namespace
    $selected_ns = $form_state->getValue('namespace') ?: '';
    // key($ns_options)

    // 4) Messages placeholder
    $form['messages'] = ['#type' => 'status_messages'];

    // 5) Outer wrapper for AJAX
    $form['row'] = [
      '#type'       => 'container',
      '#attributes' => [
        'class' => ['row', 'mt-0'],
        'id'    => 'map-entry-points-form-wrapper',
      ],
    ];

    // 6) LEFT COLUMN: entry-point + current mapping tree
    $form['row']['left_col'] = [
      '#type'       => 'container',
      '#attributes' => [
        'class' => ['col-md-6', 'border-end', 'border-4'],
        'id'    => 'left-col-wrapper',
      ],
    ];
    $form['row']['left_col']['entry_point'] = [
      '#type'               => 'select',
      '#title'              => $this->t('Entry Point'),
      '#empty_option'       => $this->t('Select please'),
      '#options'            => $entry_options,
      '#default_value'      => $selected_ep_key,
      '#attributes'         => ['class' => ['map-entry-point-select']],
      '#options_attributes' => (function () use ($entry_root_uris) {
        $attrs = [];
        foreach ($entry_root_uris as $key => $uri) {
          // now this uses the DB‐override URI (or constant if no override)
          $attrs[$key] = ['data-root-uri' => $uri];
        }
        return $attrs;
      })(),
      // '#ajax' => [
      //   'callback' => '::ajaxRefreshLeft',
      //   'wrapper'  => 'left-col-wrapper',
      //   'progress' => [
      //     'type'     => 'none',      // <— desliga o progress indicator
      //   ],
      // ],
    ];

    $form['row']['left_col']['current_tree'] = [
      '#type'   => 'markup',
      '#markup' => '<div id="current-tree" '
        . 'data-root-uri="' . Html::escape($constant_uri) . '" '
        . 'class="border border-1 p-2" style="min-height:300px"></div>',
    ];


    // 7) RIGHT COLUMN: namespace + custom root + load + tree
    $form['row']['right_col'] = [
      '#type'       => 'container',
      '#attributes' => [
        'class' => ['col-md-6', 'row', 'align-self-start'],
        'id'    => 'right-col-wrapper',
        'style' => 'margin-top:0!important;',
      ],
    ];
    // a) Namespace dropdown (6 cols)
    $form['row']['right_col']['namespace'] = [
      '#type'               => 'select',
      '#title'              => $this->t('Ontology Namespace'),
      '#description'        => $this->t('Select the Namespace from the list.'),
      '#empty_option'       => $this->t('Select please'),
      '#options'            => $ns_options,
      '#default_value'      => $selected_ns,
      '#attributes'         => ['class' => ['map-ontology-select']],
      '#prefix'             => '<div class="col-md-5">',
      '#suffix'             => '</div>',
    ];
    // b) Entry-point textfield (3 cols)
    $form['row']['right_col']['custom_root'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Entry Point'),
      '#description'   => $this->t('Type only the class label (e.g. "Agent").'),
      '#default_value' => $form_state->getValue('custom_root') ?: '',
      '#attributes'    => ['id' => 'edit-custom-root'],
      '#prefix'        => '<div class="col-md-4">',
      '#suffix'        => '</div>',
    ];
    // c) Load-tree button (3 cols)
    $form['row']['right_col']['load_tree'] = [
      '#type'       => 'button',
      '#value'      => $this->t('Load Ontology Tree'),
      '#attributes' => [
        'style' => 'margin-bottom:20px;',
        'class' => ['btn'],
        'id'    => 'edit-load-tree',
      ],
      '#prefix'     => '<div class="col-md-3 align-self-center">',
      '#suffix'     => '</div>',
      // '#ajax'       => [
      //   'callback' => '::ajaxRefreshRight',
      //   'wrapper'  => 'right-col-wrapper',
      // ],
    ];
    // d) Tree container (full width)
    $form['row']['right_col']['ontology_tree'] = [
      '#type'   => 'markup',
      '#markup' => '<div id="ontology-tree" class="border p-2" style="min-height:300px"></div>',
      '#prefix' => '<div class="col-md-12">',
      '#suffix' => '</div>',
    ];

    // 8) Attach libraries & pass settings to JS
    $base = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBaseUrl();
    // $form['#attached']['library'][] = 'rep/rep_tree';
    $form['#attached']['library'][] = 'rep/map_entry_points';
    $form['#attached']['drupalSettings']['repMap'] = [
      'apiEndpoint'     => $base . '/rep/getchildren?_format=json',
      'childParam'      => 'nodeUri',
      // the constant URI root for the currently selected entry point
      'currentRootUri' => $constant_uri,
      'mappedNodes'    => $mapped_nodes,
      // map of select‐option keys → constant URIs (never overridden)
      'entryConstants'  => array_combine(
        array_map('strtolower', array_keys($constants)),
        array_values($constants)
      ),
      'namespaceBaseUris'=> $namespaces,
    ];

    // Depois de definir drupalSettings['repMap']…
    $entryMappings = [];
    foreach ($constants as $const => $uri) {
      $key = strtolower($const);
      $entryMappings[$key] = $tables->getMappingsForEntryPoint($uri);
    }
    $form['#attached']['drupalSettings']['repMap']['entryMappings'] = $entryMappings;

    // 9) Hidden node + Save button
    $form['selected_node'] = [
      '#type' => 'hidden',
      '#default_value' => $constant_uri,
      // this gives it name="selected_node" so Form API picks it up
      '#attributes' => ['id' => 'edit-selected-node'],
      // you *can* set default_value here, but it’s not required
    ];

    $form['selected_entry_point'] = [
      '#type' => 'hidden',
      '#default_value' => $constant_uri,    // inicializa com o root padrão
      '#attributes' => ['id' => 'edit-selected-entry-point'],
    ];

    $form['row']['actions'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['col-12', 'mt-3', 'pb-5']],
    ];
    $form['row']['actions']['submit'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Save Mappings'),
      '#button_type' => 'primary',
      // '#ajax'        => [
      //   'callback' => '::ajaxSubmit',
      //   'wrapper'  => 'map-entry-points-form-wrapper',
      // ],
    ];

    return $form;
  }

  /**
   * AJAX callback: refresh only the right column.
   */
  public function ajaxRefreshRight(array $form, FormStateInterface $form_state) {
    return $form['row']['right_col'];
  }

  /**
   * AJAX callback: refresh only the left column.
   */
  public function ajaxRefreshLeft(array $form, FormStateInterface $form_state) {
    // 1) Rebuild todo o form para recomputar drupalSettings
    $new_form = $this->buildForm([], $form_state);

    // 2) Renderizar apenas a coluna da esquerda
    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = \Drupal::service('renderer');
    $left_html = $renderer->renderRoot($new_form['row']['left_col']);

    // 3) Pegar as novas configurações que montamos em buildForm()
    //    aqui repMap contém o novo currentRootUri e mappedNodes para o EP selecionado
    $new_settings = $new_form['#attached']['drupalSettings']['repMap'];

    // 4) Construir a resposta AJAX
    $response = new AjaxResponse();
    // — substituir o HTML antigo pelo novo
    $response->addCommand(new HtmlCommand('#left-col-wrapper', $left_html));
    // — empurrar o novo drupalSettings.repMap para o JS
    $response->addCommand(new SettingsCommand(['repMap' => $new_settings]));

    return $response;
  }

  /**
   * AJAX submit: save mapping, then rebuild the form.
   */
  public function ajaxSubmit(array $form, FormStateInterface $form_state) {
    $this->submitForm($form, $form_state);
    return $this->buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * On final submit, persist the selected node URI under the chosen entry point.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $reflection = new \ReflectionClass(EntryPoints::class);
    $constants  = $reflection->getConstants();
    $tables     = new Tables(\Drupal::database());

    $epUri = $form_state->getValue('selected_entry_point');
    $selectedNodeUri = $form_state->getValue('selected_node');

    // dpm($selectedNodeUri);return false;

    // Persist exactly one node per entry point.
    $tables->saveMapping($epUri, $selectedNodeUri);

    $this->messenger()->addStatus($this->t(
      'Saved @node under @ep',
      ['@node' => $selectedNodeUri, '@ep' => $epUri]
    ));
  }

}
