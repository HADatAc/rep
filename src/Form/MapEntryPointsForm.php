<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\rep\Entity\Tables;

/**
 * Form to browse an ontology and save a mapping.
 *
 * Left column:
 *   - Loads the current ontology tree from the module settings root.
 *
 * Right column:
 *   - Choose an ontology namespace and load/browse its tree.
 *   - Select a node to be saved as the mapping target.
 *
 * On submit, we save a single mapping:
 *   [entry point URI] -> [selected node URI]
 * where entry point defaults to the left-tree root (settings value) or
 * any node the user selects on the left tree.
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
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Load namespaces from DB/service.
    $tables     = new Tables(\Drupal::database());
    $namespaces = $tables->getNamespaces();

    if (!$namespaces) {
      $this->messenger()->addError($this->t('No namespaces found.'));
      return [];
    }

    // Root URI for the LEFT tree, coming from settings.
    $root_from_settings = (string) \Drupal::config('rep.settings')->get('repository_namespace_url');
    $root_label         = (string) \Drupal::config('rep.settings')->get('repository_namespace_prefix') ?: $root_from_settings;
    if ($root_label === '') {
      $root_label = $this->t('Root');
    }
    if ($root_from_settings === '') {
      $this->messenger()->addError($this->t('Missing "repository_namespace_url" in rep.settings.'));
      return [];
    }

    // Build <select> options for namespaces (value = base URI, label = name).
    $ns_options  = array_combine(array_values($namespaces), array_keys($namespaces));
    $selected_ns = $form_state->getValue('namespace') ?: '';

    $form['messages'] = ['#type' => 'status_messages'];

    $form['row'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'mt-0'],
        'id'    => 'map-entry-points-form-wrapper',
      ],
    ];

    // LEFT column: current tree, always starts from settings root.
    $form['row']['left_col'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-6', 'border-end', 'border-4'],
        'id'    => 'left-col-wrapper',
      ],
    ];
    $form['row']['left_col']['current_tree'] = [
      '#type'   => 'markup',
      '#markup' => '<div id="current-tree"'
        . ' data-root-uri="' . Html::escape($root_from_settings) . '"'
        . ' data-root-label="' . Html::escape($root_label) . '"'
        . ' class="border border-1 p-2" style="min-height:300px"></div>',
    ];

    // RIGHT column: namespace selector + load button + tree.
    $form['row']['right_col'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-6', 'row', 'align-self-start'],
        'id'    => 'right-col-wrapper',
        'style' => 'margin-top:0!important;',
      ],
    ];

    $form['row']['right_col']['namespace'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Ontology Namespace'),
      '#description'   => $this->t('Select the base namespace to explore on the right.'),
      '#empty_option'  => $this->t('Select…'),
      '#options'       => $ns_options,
      '#default_value' => $selected_ns,
      '#attributes'    => ['class' => ['map-ontology-select']],
      '#prefix'        => '<div class="col-md-5">',
      '#suffix'        => '</div>',
    ];

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
    ];

    $form['row']['right_col']['ontology_tree'] = [
      '#type'   => 'markup',
      '#markup' => '<div id="ontology-tree" class="border p-2" style="min-height:300px"></div>',
      '#prefix' => '<div class="col-md-12">',
      '#suffix' => '</div>',
    ];

    // Attach JS library and pass endpoints/settings to JS.
    $base = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBaseUrl();
    $form['#attached']['library'][] = 'rep/map_entry_points';
    $form['#attached']['drupalSettings']['repMap'] = [
      'apiTopClassEndpoint' => $base . '/rep/gettopclass?_format=json',
      'apiEndpoint'         => $base . '/rep/getchildren?_format=json',
      'childParam'          => 'nodeUri',
      'currentRootUri'      => $root_from_settings,
      'currentRootLabel'    => $root_label, // <— pass label to JS
    ];

    // Hidden fields used on submit.
    $form['selected_node'] = [
      '#type' => 'hidden',
      '#default_value' => '',
      '#attributes' => ['id' => 'edit-selected-node'],
    ];

    // Entry point to save under: defaults to the LEFT root,
    // but can be updated by clicking a node on the LEFT tree.
    $form['selected_entry_point'] = [
      '#type' => 'hidden',
      '#default_value' => $root_from_settings,
      '#attributes' => ['id' => 'edit-selected-entry-point'],
    ];

    $form['row']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-12', 'mt-3', 'pb-5']],
    ];
    $form['row']['actions']['submit'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Save Mappings'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $tables = new Tables(\Drupal::database());

    $entry_point_uri  = (string) $form_state->getValue('selected_entry_point'); // from LEFT tree
    $selected_node_uri = (string) $form_state->getValue('selected_node');       // from RIGHT tree

    if ($selected_node_uri === '') {
      $this->messenger()->addWarning($this->t('No node selected on the right tree.'));
      return;
    }

    $tables->saveMapping($entry_point_uri, $selected_node_uri);

    $this->messenger()->addStatus($this->t(
      'Saved @node under @ep.',
      ['@node' => $selected_node_uri, '@ep' => $entry_point_uri]
    ));
  }
}
