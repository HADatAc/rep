<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\rep\Entity\Tables;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\rep\Controller\OntController;
use Drupal\rep\Controller\TreeController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Form to browse an ontology and save a mapping.
 *
 * LEFT column:
 *   - Loads the current ontology tree from a known root (from settings or
 *     hard-coded as HASCO ClassEntryPoint).
 *
 * RIGHT column:
 *   - Choose an ontology namespace and load/browse its tree.
 *   - Select a node to be saved as the mapping target.
 *
 * On submit, we save a single mapping:
 *   [entry point URI] -> [selected node URI]
 * and append it to the local ontology TTL file.
 *
 * IMPORTANT:
 *   This form ALWAYS works on a local ontology file under private://ont:
 *      private://ont/hasco.ttl
 *   There is no "remote vs local" toggle anymore.
 */
class MapEntryPointsForm extends FormBase {

  /**
   * Paths and filenames used for ontology storage and versioning.
   * These are local-only and aligned with the current ontology setup.
   */
  private const ONT_ROOT_DIR     = 'private://ont';
  private const ONT_TTL_FILENAME = 'hasco.ttl';  // main local TTL file
  private const VERSIONS_SUBDIR  = 'versions';   // incremental versions subdir
  private const VERSION_PREFIX   = 'v';          // e.g., v0001, v0002, ...

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

    // Root URI for the LEFT tree.
    // In a more advanced setup, this could come from configuration.
    $root_from_settings = (string) 'http://hadatac.org/ont/hasco/ClassEntryPoint';
    $root_label         = (string) 'HASCO CLASSES';
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

    $form['messages'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'rep-map-entry-points-messages'],
      'status' => ['#type' => 'status_messages'],
    ];

    $form['row'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'mt-0'],
        'id'    => 'map-entry-points-form-wrapper',
      ],
    ];

    // LEFT column: current tree, always from the fixed HASCO entry point.
    $form['row']['left_col'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-6', 'border-end', 'border-4'],
        'id'    => 'left-col-wrapper',
      ],
    ];
    $form['row']['left_col']['left_col_actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'justify-content-end', 'mb-2', 'rep-map-refresh-actions'],
      ],
    ];
    $form['row']['left_col']['left_col_actions']['refresh_left_tree'] = [
      '#type' => 'button',
      '#value' => $this->t('Refresh'),
      '#attributes' => [
        'type' => 'button',
        'id' => 'edit-refresh-left-tree',
        'class' => ['btn', 'button', 'button--secondary', 'rep-map-refresh-button'],
      ],
    ];
    $form['row']['left_col']['left_col_actions']['resync_from_kg'] = [
      '#type' => 'submit',
      '#value' => $this->t('Resync from KG'),
      '#limit_validation_errors' => [],
      '#submit' => ['::submitResyncFromKg'],
      '#ajax' => [
        'callback' => '::ajaxResyncFromKg',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Resyncing entry-point mappings from KG...'),
        ],
      ],
      '#attributes' => [
        'id' => 'edit-resync-from-kg',
        'class' => ['btn', 'button', 'button--secondary', 'ms-2', 'rep-map-refresh-button'],
      ],
    ];
    $form['row']['left_col']['current_tree'] = [
      '#type'   => 'markup',
      '#markup' => '<div id="current-tree"'
        . ' data-root-uri="' . Html::escape($root_from_settings) . '"'
        . ' data-root-label="' . Html::escape($root_label) . '"'
        . ' class="border border-1 p-2 treeMOL"></div>',
    ];

    // RIGHT column: namespace selector + load button + ontology tree.
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
      '#description'   => $this->t('Select the base namespace to explore on the right.'),
      '#empty_option'  => $this->t('Select…'),
      '#options'       => $ns_options,
      '#default_value' => $selected_ns,
      '#attributes'    => ['class' => ['map-ontology-select']],
      '#prefix'        => '<div class="col-md-8">',
      '#suffix'        => '</div>',
    ];

    $form['row']['right_col']['load_tree'] = [
      '#type'       => 'button',
      '#value'      => $this->t('Load Ontology Tree'),
      '#attributes' => [
        'type'  => 'button',
        'style' => 'margin-bottom:35px',
        'class' => ['btn', 'load-more-button'],
        'id'    => 'edit-load-tree',
      ],
      '#prefix'     => '<div class="col-md-4 align-self-center">',
      '#suffix'     => '</div>',
    ];

    $form['row']['right_col']['ontology_tree'] = [
      '#type'   => 'markup',
      '#markup' => '<div id="ontology-tree" class="border pt-2 ps-2 pe-2 pb-0 treeMO"></div>',
      '#prefix' => '<div class="col-md-12">',
      '#suffix' => '</div>',
      '#wrapper_attributes' => ['style' => "min-height:300px; max-height:500px;"],
      '#attributes' => ['style' => 'min-height:300px; max-height:500px'],
    ];

    // Reuse Tables instance for JS settings.
    $tables = new Tables(\Drupal::database());

    // Attach JS library and pass endpoints/settings to JS.
    $base = (\Drupal::request()->headers->get('x-forwarded-proto') === 'https' ? 'https://' : 'http://')
      . \Drupal::request()->getHttpHost()
      . \Drupal::request()->getBaseUrl();

    $form['#attached']['library'][] = 'rep/map_entry_points';
    $form['#attached']['drupalSettings']['repMap'] = [
      'apiTopClassEndpoint'        => $base . '/rep/gettopclass?_format=json',
      'apiEndpoint'                => $base . '/rep/getchildren?_format=json',
      'apiSubclassKeywordEndpoint' => $base . '/rep/subclasskeyword?_format=json',
      'apiNodeEndpoint'            => $base . '/rep/getnode?_format=json',
      'childParam'                 => 'nodeUri',
      'currentRootUri'             => $root_from_settings,
      'currentRootLabel'           => $root_label,
      'nameSpacesList'             => $tables->getNamespaces(),
    ];

    // Hidden fields used on submit.
    $form['selected_node'] = [
      '#type' => 'hidden',
      '#default_value' => '',
      '#attributes' => ['id' => 'edit-selected-node'],
    ];

    // Multi-select support: JSON array of selected node URIs.
    $form['selected_nodes'] = [
      '#type' => 'hidden',
      '#default_value' => '[]',
      '#attributes' => ['id' => 'edit-selected-nodes'],
    ];

    // Entry point to save under: defaults to the LEFT root,
    // but JS may update it when the user clicks a node on the LEFT tree.
    $form['selected_entry_point'] = [
      '#type' => 'hidden',
      '#default_value' => $root_from_settings,
      '#attributes' => ['id' => 'edit-selected-entry-point'],
    ];

    // Actions row (bottom).
    $form['row']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-12', 'mt-3', 'pb-5']],
    ];

    $form['row']['actions']['submit'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Save Mappings'),
      '#button_type' => 'primary',
      '#attributes'  => [
        'id'    => 'rep-map-entry-points-submit',
        'class' => ['save-button'],
      ],
      '#ajax' => [
        'callback' => '::ajaxSave',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Saving mappings and ingesting the App Ontology...'),
        ],
      ],
    ];

    $form['row']['actions']['ingest_application_ontology'] = [
      '#type' => 'link',
      '#title' => $this->t('Ingest App Ontology'),
      '#url' => Url::fromRoute(
        'rep.ont_injest',
        ['returnPathway' => 'rep.map_entry_points']
      ),
      '#attributes' => [
        'id' => 'rep-ont-ingest',
        'class' => ['btn', 'button', 'button--warning', 'ingest_mt-button'],
      ],
    ];

    $form['row']['notes'] = [
      '#type' => 'markup',
      '#markup' => '<div class="mt-1 mb-5"><em>' . $this->t('<strong>Information</strong>: The "Save Mappings" button will automatically ingest the App Ontology, but the "Ingest App Ontology" button will not save the mappings.') . '</em></div>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entry_point_uri = (string) $form_state->getValue('selected_entry_point');

    // Multi-select support: read a JSON array from the hidden field.
    $node_uris = [];

    $selected_nodes_json = $form_state->getValue('selected_nodes');
    if (is_string($selected_nodes_json) && $selected_nodes_json !== '') {
      $decoded = json_decode($selected_nodes_json, TRUE);
      if (is_array($decoded)) {
        foreach ($decoded as $u) {
          if (is_string($u) && trim($u) !== '') {
            $node_uris[] = trim($u);
          }
        }
      }
    }

    // Backward-compat: single selection.
    $selected_node_uri = (string) $form_state->getValue('selected_node');
    if (empty($node_uris) && trim($selected_node_uri) !== '') {
      $node_uris[] = trim($selected_node_uri);
    }

    $node_uris = array_values(array_unique($node_uris));

    if (empty($node_uris)) {
      $this->messenger()->addWarning($this->t('No node selected on the right tree.'));
      return;
    }

    // Basic hardening: prevent Turtle injection via hidden fields.
    $entry_point_uri = trim($entry_point_uri);
    if ($entry_point_uri === '' || preg_match('/[\s<>"{}|^`\\\\]/', $entry_point_uri)) {
      $this->messenger()->addError($this->t('Invalid entry point URI.'));
      return;
    }

    $safe_node_uris = [];
    foreach ($node_uris as $u) {
      if ($u === '' || preg_match('/[\s<>"{}|^`\\\\]/', $u)) {
        continue;
      }
      $safe_node_uris[] = $u;
    }

    $safe_node_uris = array_values(array_unique($safe_node_uris));
    if (empty($safe_node_uris)) {
      $this->messenger()->addError($this->t('No valid nodes were selected.'));
      return;
    }

    // VERIFICATION: Check ontology triple counts and entry point mappings
    $verification_result = $this->verifyOntologiesAndEntryPoints();
    if (!$verification_result['success']) {
      $this->messenger()->addError($this->t('Ontology verification failed:'));
      foreach ($verification_result['errors'] as $error) {
        $this->messenger()->addError($error);
      }
      if (!empty($verification_result['warnings'])) {
        foreach ($verification_result['warnings'] as $warning) {
          $this->messenger()->addWarning($warning);
        }
      }
      return;
    }
    
    // Display verification success messages
    if (!empty($verification_result['messages'])) {
      foreach ($verification_result['messages'] as $message) {
        $this->messenger()->addStatus($message);
      }
    }

    $fs = \Drupal::service('file_system');

    // Ensure ontology root directory exists (argument must be passed by reference).
    $ontRootDir = self::ONT_ROOT_DIR;
    $fs->prepareDirectory(
      $ontRootDir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );

    // TTL file URI and path.
    $ttl_uri  = self::ONT_ROOT_DIR . '/' . self::ONT_TTL_FILENAME;
    $ttl_path = $fs->realpath($ttl_uri);

    // If TTL does not exist, create it with a simple header.
    if ($ttl_path === FALSE || !file_exists($ttl_path)) {
      $header = "# Ontology file created by MapEntryPointsForm\n";
      $saved_uri = $fs->saveData($header, $ttl_uri, FileSystemInterface::EXISTS_REPLACE);
      if ($saved_uri === FALSE) {
        $this->messenger()->addError($this->t('Failed to create ontology TTL file at @uri.', ['@uri' => $ttl_uri]));
        return;
      }
      $ttl_path = $fs->realpath($ttl_uri);
      if ($ttl_path === FALSE) {
        $this->messenger()->addError($this->t('Could not resolve real path for ontology TTL file at @uri.', ['@uri' => $ttl_uri]));
        return;
      }
    }

    // Read current TTL content (used for @prefix check and version backup).
    $ttl_content_before = (string) file_get_contents($ttl_path);

    // Create an incremental version folder and copy the current TTL there.
    try {
      $version_dir_uri = $this->createIncrementalVersionDirectory(self::ONT_ROOT_DIR, self::VERSIONS_SUBDIR);
      $fs->copy($ttl_uri, $version_dir_uri . '/' . self::ONT_TTL_FILENAME, FileSystemInterface::EXISTS_REPLACE);
    }
    catch (\Throwable $e) {
      $this->messenger()->addWarning($this->t('Versioning failed. Proceeding to write the mapping. Error: @e', ['@e' => $e->getMessage()]));
    }

    // Build Turtle entries: each selected node becomes a subclass of the entry point.
    $append = '';
    $missing_prefixes = [];

    $object = $this->formatTurtleTerm($entry_point_uri);

    foreach ($safe_node_uris as $node_uri) {
      $subject = $this->formatTurtleTerm($node_uri);

      $append .= "\n# --- Mapping appended by MapEntryPointsForm ---\n";
      $append .= $subject . "\n\ta rdfs:Class;";
      $append .= "\n\trdfs:subClassOf " . $object . " .\n";

      $maybe_prefix = $this->extractCompactPrefix($node_uri);
      if ($maybe_prefix !== null && !$this->ttlHasPrefix($ttl_content_before, $maybe_prefix)) {
        $missing_prefixes[$maybe_prefix] = true;
      }
    }

    // Append all mappings in one write.
    $ok = (bool) file_put_contents($ttl_path, $append, FILE_APPEND | LOCK_EX);

    if ($ok) {
      $form_state->set('rep_map_saved_ok', true);

      $this->messenger()->addStatus($this->t('@count mapping(s) saved.', [
        '@count' => count($safe_node_uris),
      ]));

      // Trigger ontology ingestion (same behavior as clicking "Ingest App Ontology").
      try {
        $ontController = new OntController();
        $ontController->injest();
      }
      catch (\Throwable $e) {
        $this->messenger()->addWarning($this->t('Error during automatic ingestion: @msg', ['@msg' => $e->getMessage()]));
      }

      if (!empty($missing_prefixes)) {
        $this->messenger()->addWarning($this->t(
          'Heads up: missing @prefix declarations for: @list. The mappings were saved, but you must add those @prefix lines to the TTL header manually.',
          ['@list' => implode(', ', array_keys($missing_prefixes))]
        ));
      }
    }
    else {
      $form_state->set('rep_map_saved_ok', false);
      $this->messenger()->addError($this->t('Failed to append the mapping to the TTL file.'));
    }
  }

  /**
   * AJAX callback: keep the page state (trees/expansions) while showing messages.
   */
  public function ajaxSave(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // Update status messages in-place.
    $rendered_messages = (string) \Drupal::service('renderer')->renderRoot($form['messages']);
    $response->addCommand(new ReplaceCommand('#rep-map-entry-points-messages', $rendered_messages));

    $saved_ok = (bool) $form_state->get('rep_map_saved_ok');
    if ($saved_ok) {
      // Let the client-side code refresh trees / clear selections safely.
      $response->addCommand(new InvokeCommand('body', 'repMapAfterSave', []));
    }

    return $response;
  }

  /**
   * Submit handler for "Resync from KG" button.
   */
  public function submitResyncFromKg(array &$form, FormStateInterface $form_state): void {
    $result = $this->rebuildClassEntryPointMappingsFromKg();

    if (!$result['success']) {
      $form_state->set('rep_map_resync_ok', false);
      $this->messenger()->addError($this->t('Failed to resync mappings from KG: @msg', [
        '@msg' => $result['message'],
      ]));
      return;
    }

    $form_state->set('rep_map_resync_ok', true);
    $this->messenger()->addStatus($this->t(
      'Resync completed: @rebuilt mapping(s) rebuilt from KG/TTL (entry points seen: @seen). Kept existing valid: @kept. Auto-selected ambiguous: @amb. Recovered from EntryPoint class: @epclass. Recovered from TTL: @ttl. Skipped no descendant: @nod. Bound coverage: @before -> @after.',
      [
        '@rebuilt' => (int) $result['mappings_rebuilt'],
        '@seen' => (int) $result['entry_points_seen'],
        '@kept' => (int) ($result['kept_existing'] ?? 0),
        '@amb' => (int) ($result['auto_selected_ambiguous'] ?? 0),
        '@epclass' => (int) ($result['recovered_from_entrypoint_class'] ?? 0),
        '@ttl' => (int) ($result['recovered_from_ttl'] ?? 0),
        '@nod' => (int) ($result['skipped_no_descendant'] ?? 0),
        '@before' => (int) ($result['bound_before'] ?? 0),
        '@after' => (int) ($result['bound_after'] ?? 0),
      ]
    ));

    if (!empty($result['skipped_no_descendant']) && (int) $result['skipped_no_descendant'] > 0) {
      $this->messenger()->addWarning($this->t(
        '@count entry point(s) had no descendant candidates and were left unchanged. Map them manually with Save Mappings if needed.',
        ['@count' => (int) $result['skipped_no_descendant']]
      ));
    }
  }

  /**
   * AJAX callback for "Resync from KG" button.
   */
  public function ajaxResyncFromKg(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    $rendered_messages = (string) \Drupal::service('renderer')->renderRoot($form['messages']);
    $response->addCommand(new ReplaceCommand('#rep-map-entry-points-messages', $rendered_messages));

    if ((bool) $form_state->get('rep_map_resync_ok')) {
      $response->addCommand(new InvokeCommand('body', 'repMapAfterResync', []));
    }

    return $response;
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Format a Turtle term:
   * - If it looks like a full URI (http(s):// or urn:), wrap with <...>.
   * - Otherwise, return as-is (assumed CURIE or QName with a known prefix).
   */
  private function formatTurtleTerm(string $term): string {
    $t = trim($term);
    if (preg_match('#^(https?://|urn:)#i', $t)) {
      // Avoid double-wrapping if user already provided <...>.
      if ($t[0] !== '<') {
        return '<' . $t . '>';
      }
      return $t;
    }
    // Likely a prefixed name like prefix:ClassName.
    return $t;
  }

  /**
   * Rebuild entry-point mappings table from KG class entry-point branches.
   */
  private function rebuildClassEntryPointMappingsFromKg(): array {
    $db = \Drupal::database();
    $tables = new Tables($db);
    $root = 'http://hadatac.org/ont/hasco/ClassEntryPoint';
    $ttl_mappings = $this->loadTtlMappingsByEntryPoint();
    $default_mappings = $this->loadDefaultHascoMappingsByEntryPoint();
    $api = \Drupal::service('rep.api_connector');

    try {
      /** @var \Drupal\rep\Controller\TreeController $tree_controller */
      $tree_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(TreeController::class);

      // Baseline for regression guard: how many entry points are currently bound.
      $before_bound = 0;
      try {
        $bound_res_before = $tree_controller->getBoundEntryPoints();
        $bound_json_before = json_decode($bound_res_before->getContent(), true);
        if (is_array($bound_json_before) && isset($bound_json_before['count'])) {
          $before_bound = (int) $bound_json_before['count'];
        }
      }
      catch (\Throwable $ignored) {
        // If baseline cannot be calculated, continue without guard baseline.
        $before_bound = 0;
      }

      // Use exactly the same path the UI uses for LEFT root expansion.
      $top_req = Request::create('/rep/gettopclass', 'GET', ['nodeUri' => $root]);
      $top_res = $tree_controller->getTopClass($top_req);
      $entry_points = json_decode($top_res->getContent());
      if (!is_array($entry_points)) {
        $entry_points = [];
      }

      $mappings_rebuilt = 0;
      $kept_existing = 0;
      $skipped_no_descendant = 0;
      $auto_selected_ambiguous = 0;
      $recovered_from_ttl = 0;
      $recovered_from_entrypoint_class = 0;

      $tx = $db->startTransaction();

      foreach ($entry_points as $ep) {
        if (!is_object($ep) || empty($ep->uri)) {
          continue;
        }

        $entry_uri = trim((string) $ep->uri);
        $entry_label = trim((string) ($ep->label ?? ''));
        if ($entry_label === '') {
          $entry_label = $this->entryPointShortName($entry_uri);
        }

        if ($entry_uri === '') {
          continue;
        }

        $candidates = $this->collectEntryPointDescendants($tree_controller, $entry_uri, 6, 500);

        // Preserve existing mapping(s) first to avoid regressions.
        $existing_uris = $tables->getMappingsForEntryPoint($entry_uri);
        $existing_uris = array_values(array_filter(array_map('strval', $existing_uris)));

        if (empty($candidates)) {
          // If DB already has a mapping for this entry point, keep it unchanged.
          if (!empty($existing_uris)) {
            $kept_existing++;
            continue;
          }

          // Canonical fallback: map <XxxEntryPoint> to <Xxx> when class exists.
          $canonical_class_uri = $this->deriveClassUriFromEntryPoint($entry_uri);
          if ($canonical_class_uri !== NULL) {
            $class_obj = $api->parseObjectResponse($api->getUri($canonical_class_uri), 'getUri');
            if ($class_obj && is_object($class_obj) && !empty($class_obj->uri)) {
              $db->delete('rep_entry_point_mapping')
                ->condition('entry_point_uri', $entry_uri)
                ->execute();
              $tables->saveMapping($entry_uri, $canonical_class_uri);

              $mappings_rebuilt++;
              $recovered_from_entrypoint_class++;
              continue;
            }
          }

          // No descendants and no DB mapping: recover from local TTL knowledge.
          $ttl_candidates = $ttl_mappings[$entry_uri] ?? [];
          if (!empty($ttl_candidates)) {
            sort($ttl_candidates, SORT_NATURAL | SORT_FLAG_CASE);
            $selected_uri = (string) $ttl_candidates[0];

            $db->delete('rep_entry_point_mapping')
              ->condition('entry_point_uri', $entry_uri)
              ->execute();
            $tables->saveMapping($entry_uri, $selected_uri);

            $mappings_rebuilt++;
            $recovered_from_ttl++;
            continue;
          }

          // Last-resort canonical recovery: bundled HASCO default mappings.
          $default_candidates = $default_mappings[$entry_uri] ?? [];
          if (!empty($default_candidates)) {
            sort($default_candidates, SORT_NATURAL | SORT_FLAG_CASE);
            $selected_uri = (string) $default_candidates[0];

            $db->delete('rep_entry_point_mapping')
              ->condition('entry_point_uri', $entry_uri)
              ->execute();
            $tables->saveMapping($entry_uri, $selected_uri);

            $mappings_rebuilt++;
            $recovered_from_ttl++;
            continue;
          }

          $skipped_no_descendant++;
          continue;
        }

        $candidate_by_uri = [];
        foreach ($candidates as $c) {
          $candidate_by_uri[$c['uri']] = $c;
        }

        $kept_uri = '';
        foreach ($existing_uris as $existing_uri) {
          if (isset($candidate_by_uri[$existing_uri])) {
            $kept_uri = $existing_uri;
            break;
          }
        }

        if ($kept_uri !== '') {
          $kept_existing++;
          continue;
        }

        // If existing mapping exists but was not discovered in this pass,
        // keep it to avoid accidental churn/regression.
        if (!empty($existing_uris)) {
          $kept_existing++;
          continue;
        }

        // Pick from closest descendants; skip when multiple closest choices exist.
        $min_depth = NULL;
        foreach ($candidates as $c) {
          if ($min_depth === NULL || $c['depth'] < $min_depth) {
            $min_depth = $c['depth'];
          }
        }

        $closest = array_values(array_filter($candidates, static function(array $c) use ($min_depth): bool {
          return $c['depth'] === $min_depth;
        }));

        usort($closest, static function(array $a, array $b): int {
          $la = $a['label'] !== '' ? $a['label'] : $a['uri'];
          $lb = $b['label'] !== '' ? $b['label'] : $b['uri'];
          return strcasecmp($la, $lb);
        });

        if (count($closest) > 1) {
          // Deterministic tie-breaker: keep first entry after alphabetical sort.
          $auto_selected_ambiguous++;
        }

        $selected_uri = $closest[0]['uri'];

        // Non-destructive update: only change this entry-point row.
        $db->delete('rep_entry_point_mapping')
          ->condition('entry_point_uri', $entry_uri)
          ->execute();
        $tables->saveMapping($entry_uri, $selected_uri);
        $mappings_rebuilt++;
      }

      // Regression guard: if bound coverage decreases, rollback and abort.
      $after_bound = $before_bound;
      try {
        $bound_res_after = $tree_controller->getBoundEntryPoints();
        $bound_json_after = json_decode($bound_res_after->getContent(), true);
        if (is_array($bound_json_after) && isset($bound_json_after['count'])) {
          $after_bound = (int) $bound_json_after['count'];
        }
      }
      catch (\Throwable $ignored) {
        // Keep previous value if post-check fails.
        $after_bound = $before_bound;
      }

      if ($after_bound < $before_bound) {
        throw new \RuntimeException(sprintf(
          'Resync aborted by regression guard: bound entry points would decrease (%d -> %d).',
          $before_bound,
          $after_bound
        ));
      }

      unset($tx);

      \Drupal::logger('rep')->notice('Entry-point resync summary: seen=@seen, rebuilt=@rebuilt, kept=@kept, auto_selected_ambiguous=@amb, skipped_no_descendant=@nod, bound_before=@before, bound_after=@after', [
        '@seen' => count($entry_points),
        '@rebuilt' => $mappings_rebuilt,
        '@kept' => $kept_existing,
        '@amb' => $auto_selected_ambiguous,
        '@nod' => $skipped_no_descendant,
        '@before' => $before_bound,
        '@after' => $after_bound,
      ]);

      return [
        'success' => true,
        'entry_points_seen' => count($entry_points),
        'mappings_rebuilt' => $mappings_rebuilt,
        'kept_existing' => $kept_existing,
        'auto_selected_ambiguous' => $auto_selected_ambiguous,
        'recovered_from_ttl' => $recovered_from_ttl,
        'recovered_from_entrypoint_class' => $recovered_from_entrypoint_class,
        'skipped_no_descendant' => $skipped_no_descendant,
        'bound_before' => $before_bound,
        'bound_after' => $after_bound,
        'message' => '',
      ];
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->error('Failed to resync entry-point mappings from KG (form action): @error', [
        '@error' => $e->getMessage(),
      ]);

      return [
        'success' => false,
        'entry_points_seen' => 0,
        'mappings_rebuilt' => 0,
        'kept_existing' => 0,
        'auto_selected_ambiguous' => 0,
        'recovered_from_ttl' => 0,
        'recovered_from_entrypoint_class' => 0,
        'skipped_no_descendant' => 0,
        'bound_before' => 0,
        'bound_after' => 0,
        'message' => $e->getMessage(),
      ];
    }
  }

  /**
   * Load saved entry-point mappings from local hasco.ttl blocks.
   *
   * Returns: [ entry_point_uri => [subject_uri1, subject_uri2, ...] ]
   */
  private function loadTtlMappingsByEntryPoint(): array {
    $fs = \Drupal::service('file_system');
    $ttl_uri = self::ONT_ROOT_DIR . '/' . self::ONT_TTL_FILENAME;
    $ttl_path = $fs->realpath($ttl_uri);

    if ($ttl_path === FALSE || !file_exists($ttl_path)) {
      return [];
    }

    $content = (string) @file_get_contents($ttl_path);
    if ($content === '') {
      return [];
    }

    $matches = [];
    preg_match_all('/<([^>]+)>\s*a\s+rdfs:Class\s*;\s*rdfs:subClassOf\s*<([^>]+)>\s*\./mi', $content, $matches, PREG_SET_ORDER);

    $out = [];
    foreach ($matches as $m) {
      $subject = trim((string) ($m[1] ?? ''));
      $entry = trim((string) ($m[2] ?? ''));
      if ($subject === '' || $entry === '') {
        continue;
      }
      if (!isset($out[$entry])) {
        $out[$entry] = [];
      }
      $out[$entry][] = $subject;
    }

    foreach ($out as $entry => $subjects) {
      $out[$entry] = array_values(array_unique($subjects));
    }

    return $out;
  }

  /**
   * Load canonical class-to-entry-point mappings from bundled hasco_default.ttl.
   *
   * Returns: [ entry_point_uri => [class_uri1, class_uri2, ...] ]
   */
  private function loadDefaultHascoMappingsByEntryPoint(): array {
    $file = '/opt/homebrew/var/www/drupal/web/modules/custom/rep/resources/hasco_default.ttl';
    if (!file_exists($file)) {
      return [];
    }

    $content = (string) @file_get_contents($file);
    if ($content === '') {
      return [];
    }

    // Build namespace map from @prefix declarations.
    $prefix_map = [];
    if (preg_match_all('/@prefix\s+([A-Za-z_][A-Za-z0-9_-]*)\s*:\s*<([^>]+)>\s*\./i', $content, $pfx, PREG_SET_ORDER)) {
      foreach ($pfx as $row) {
        $prefix_map[strtolower((string) $row[1])] = (string) $row[2];
      }
    }

    // Matches blocks like:
    // vstoi:CodeBook a rdfs:Class; rdfs:subClassOf hasco:CodeBookEntryPoint.
    $matches = [];
    preg_match_all('/([A-Za-z_][A-Za-z0-9_-]*)\:([A-Za-z0-9_]+)\s+[^.]*?rdfs:subClassOf\s+hasco:([A-Za-z0-9_]+EntryPoint)\s*\./mis', $content, $matches, PREG_SET_ORDER);

    $out = [];
    foreach ($matches as $m) {
      $class_prefix = strtolower(trim((string) ($m[1] ?? '')));
      $class_local = trim((string) ($m[2] ?? ''));
      $entry_local = trim((string) ($m[3] ?? ''));
      if ($class_prefix === '' || $class_local === '' || $entry_local === '') {
        continue;
      }

      if (!isset($prefix_map[$class_prefix]) || $prefix_map[$class_prefix] === '') {
        continue;
      }

      $entry_uri = 'http://hadatac.org/ont/hasco/' . $entry_local;
      $class_uri = $prefix_map[$class_prefix] . $class_local;

      if (!isset($out[$entry_uri])) {
        $out[$entry_uri] = [];
      }
      $out[$entry_uri][] = $class_uri;
    }

    foreach ($out as $entry => $classes) {
      $out[$entry] = array_values(array_unique($classes));
    }

    return $out;
  }

  /**
   * Derive canonical class URI from EntryPoint URI.
   *
   * Example:
   *  http://hadatac.org/ont/hasco/CodeBookEntryPoint ->
   *  http://hadatac.org/ont/hasco/CodeBook
   */
  private function deriveClassUriFromEntryPoint(string $entry_uri): ?string {
    $entry_uri = trim($entry_uri);
    if ($entry_uri === '') {
      return NULL;
    }

    if (substr($entry_uri, -10) !== 'EntryPoint') {
      return NULL;
    }

    return substr($entry_uri, 0, -10);
  }

  /**
   * Collect descendant candidates for an entry-point via TreeController flow.
   */
  private function collectEntryPointDescendants(TreeController $tree_controller, string $entry_uri, int $max_depth = 6, int $max_visited = 500): array {
    $seen = [];
    $queue = [[$entry_uri, 0]];
    $seen[$entry_uri] = true;
    $visited = 1;
    $candidates = [];

    while (!empty($queue) && $visited <= $max_visited) {
      [$current_uri, $depth] = array_shift($queue);
      if ($depth >= $max_depth) {
        continue;
      }

      $child_req = Request::create('/rep/getchildren', 'GET', ['nodeUri' => $current_uri]);
      $child_res = $tree_controller->getChildren($child_req);
      $children = json_decode($child_res->getContent());
      if (!is_array($children)) {
        continue;
      }

      foreach ($children as $child) {
        if (!is_object($child) || empty($child->uri)) {
          continue;
        }

        $child_uri = trim((string) $child->uri);
        if ($child_uri === '') {
          continue;
        }

        if (!isset($seen[$child_uri])) {
          $seen[$child_uri] = true;
          $visited++;
          $queue[] = [$child_uri, $depth + 1];
        }

        // Candidate for mapping: any descendant that is not itself an entry point.
        if ($this->isEntryPointUri($child_uri)) {
          continue;
        }

        $label = trim((string) ($child->label ?? ''));
        if (!isset($candidates[$child_uri])) {
          $candidates[$child_uri] = [
            'uri' => $child_uri,
            'label' => $label,
            'depth' => $depth + 1,
          ];
        }
        elseif (($depth + 1) < $candidates[$child_uri]['depth']) {
          $candidates[$child_uri]['depth'] = $depth + 1;
        }
      }
    }

    return array_values($candidates);
  }

  /**
   * Best-effort check whether a URI is itself an entry-point class URI.
   */
  private function isEntryPointUri(string $uri): bool {
    return (bool) preg_match('/EntryPoint$/', $uri);
  }

  /**
   * Extracts a short local identifier from a URI for display.
   */
  private function entryPointShortName(string $uri): string {
    $parts = preg_split('/[#\/]/', $uri);
    if (!is_array($parts) || empty($parts)) {
      return $uri;
    }
    $last = end($parts);
    return is_string($last) && $last !== '' ? $last : $uri;
  }

  /**
   * If the term is a compact prefixed name (e.g., envo:Something) and NOT a
   * full URI, returns the prefix (e.g., "envo"). Otherwise returns null.
   */
  private function extractCompactPrefix(string $term): ?string {
    $t = trim($term);
    // Ignore full URIs.
    if (stripos($t, '://') !== false) {
      return null;
    }
    // Match prefix:suffix (where prefix starts with a letter or underscore).
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_\-]*)\:/', $t, $m)) {
      return $m[1];
    }
    return null;
  }

  /**
   * Searches the TTL content for an @prefix declaration of the given prefix.
   * Loose match: "@prefix <prefix>:" at the beginning of a line (ignoring spaces).
   */
  private function ttlHasPrefix(string $ttlContent, string $prefix): bool {
    $pattern = '/^\s*@prefix\s+' . preg_quote($prefix, '/') . '\s*\:/mi';
    return (bool) preg_match($pattern, $ttlContent);
  }

  /**
   * Create the next incremental version directory under the ontology root.
   *
   * Example structure:
   *   private://ont/versions/v0001/
   *   private://ont/versions/v0002/
   *
   * Returns the created directory URI (e.g., "private://ont/versions/v0003").
   */
  private function createIncrementalVersionDirectory(string $ontRootUri, string $versionsSubdir): string {
    $fs = \Drupal::service('file_system');

    // Ensure versions base directory exists: private://ont/versions.
    $versions_base = rtrim($ontRootUri, '/') . '/' . trim($versionsSubdir, '/');
    $versionsBaseRef = $versions_base;
    $fs->prepareDirectory(
      $versionsBaseRef,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );

    $real_versions_base = $fs->realpath($versions_base);
    $entries = $real_versions_base ? (@scandir($real_versions_base) ?: []) : [];
    $max = 0;

    foreach ($entries as $entry) {
      if (preg_match('/^' . preg_quote(self::VERSION_PREFIX, '/') . '(\d{4})$/', $entry, $m)) {
        $n = (int) $m[1];
        if ($n > $max) {
          $max = $n;
        }
      }
    }

    $next = $max + 1;
    $version_dir_name = self::VERSION_PREFIX . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    $version_dir_uri  = $versions_base . '/' . $version_dir_name;

    // Create that specific version directory.
    $versionDirRef = $version_dir_uri;
    $fs->prepareDirectory(
      $versionDirRef,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );

    return $version_dir_uri;
  }

  /**
   * Verify ontology triple counts and entry point mappings.
   * 
   * Checks:
   * 1. PMSR, UBERON, and NCIT ontologies have the expected triple counts
   * 2. Entry point mappings exist for all three ontologies
   * 
   * @return array
   *   Array with keys: 'success' (bool), 'errors' (array), 'warnings' (array), 'messages' (array)
   */
  private function verifyOntologiesAndEntryPoints(): array {
    $result = [
      'success' => true,
      'errors' => [],
      'warnings' => [],
      'messages' => [],
    ];

    // Expected triple counts for each ontology
    $expected_counts = [
      'pmsr' => ['min' => 1800, 'max' => 1900, 'label' => 'PMSR'],
      'uberon' => ['min' => 180000, 'max' => 181000, 'label' => 'UBERON'],
      'ncit' => ['min' => 21000, 'max' => 22000, 'label' => 'NCIT'],
    ];

    // Expected entry point mappings
    $expected_mappings = [
      'pmsr' => [
        'uri' => 'http://pmsr.net/ont/pmsr#SimulationProcessStem',
        'entry_point' => 'http://hadatac.org/ont/hasco/ProcessEntryPoint',
        'label' => 'PMSR Simulation Process Stem',
      ],
      'uberon' => [
        'uri' => 'http://purl.obolibrary.org/obo/UBERON_0001062',
        'entry_point' => 'http://hadatac.org/ont/hasco/AnatomicalPartEntryPoint',
        'label' => 'UBERON Anatomical Entity',
      ],
      'ncit' => [
        'uri' => 'http://purl.obolibrary.org/obo/NCIT_C97325',
        'entry_point' => 'http://hadatac.org/ont/hasco/MedicalDeviceEntryPoint',
        'label' => 'NCIT Manufactured Object',
      ],
    ];

    // Step 1: Verify triple counts using namespace API
    try {
      $api = \Drupal::service('rep.api_connector');
      $namespace_response = $api->repoNamespacesByLabel();
      $namespace_data = json_decode($namespace_response);

      if (!$namespace_data || !$namespace_data->isSuccessful || !is_array($namespace_data->body)) {
        $result['success'] = false;
        $result['errors'][] = $this->t('Failed to retrieve namespace data from API.');
        return $result;
      }

      // Build a map of namespace label -> triple count
      $namespace_counts = [];
      foreach ($namespace_data->body as $ns) {
        if (isset($ns->label) && isset($ns->numberOfLoadedTriples)) {
          $namespace_counts[strtolower($ns->label)] = $ns->numberOfLoadedTriples;
        }
      }

      // Verify each ontology's triple count
      foreach ($expected_counts as $key => $expected) {
        if (!isset($namespace_counts[$key])) {
          $result['success'] = false;
          $result['errors'][] = $this->t('@label ontology not found in namespace registry.', [
            '@label' => $expected['label'],
          ]);
          continue;
        }

        $actual_count = $namespace_counts[$key];
        
        if ($actual_count == 0) {
          $result['success'] = false;
          $result['errors'][] = $this->t('@label ontology has 0 triples. Please ingest the ontology first.', [
            '@label' => $expected['label'],
          ]);
        } elseif ($actual_count < $expected['min'] || $actual_count > $expected['max']) {
          $result['warnings'][] = $this->t('@label ontology has @count triples (expected @min-@max). This may indicate an incomplete ingestion.', [
            '@label' => $expected['label'],
            '@count' => number_format($actual_count),
            '@min' => number_format($expected['min']),
            '@max' => number_format($expected['max']),
          ]);
        } else {
          $result['messages'][] = $this->t('✓ @label ontology verified: @count triples', [
            '@label' => $expected['label'],
            '@count' => number_format($actual_count),
          ]);
        }
      }

    } catch (\Exception $e) {
      $result['success'] = false;
      $result['errors'][] = $this->t('Error verifying triple counts: @msg', ['@msg' => $e->getMessage()]);
      return $result;
    }

    // Step 2: Verify entry point mappings exist in hasco.ttl
    try {
      $fs = \Drupal::service('file_system');
      $ttl_uri = self::ONT_ROOT_DIR . '/' . self::ONT_TTL_FILENAME;
      $ttl_path = $fs->realpath($ttl_uri);

      if ($ttl_path === FALSE || !file_exists($ttl_path)) {
        $result['success'] = false;
        $result['errors'][] = $this->t('Application ontology file (hasco.ttl) not found. Please ingest the PMSR ontologies first.');
        return $result;
      }

      $ttl_content = file_get_contents($ttl_path);
      $mappings_found = 0;

      foreach ($expected_mappings as $key => $mapping) {
        // Look for the mapping in various formats:
        // Full URI format: <uri> rdfs:subClassOf <entrypoint>
        // Compact format: prefix:ClassName rdfs:subClassOf hasco:EntryPoint
        $uri_escaped = preg_quote($mapping['uri'], '/');
        $ep_escaped = preg_quote($mapping['entry_point'], '/');
        
        // Check for full URI format or compact format
        $pattern = '/<' . $uri_escaped . '>.*rdfs:subClassOf.*<' . $ep_escaped . '>/s';
        
        if (preg_match($pattern, $ttl_content)) {
          $mappings_found++;
          $result['messages'][] = $this->t('✓ Entry point mapping found: @label', [
            '@label' => $mapping['label'],
          ]);
        } else {
          $result['warnings'][] = $this->t('Entry point mapping not found for @label (@uri → @ep)', [
            '@label' => $mapping['label'],
            '@uri' => $mapping['uri'],
            '@ep' => $mapping['entry_point'],
          ]);
        }
      }

      if ($mappings_found === 0) {
        $result['success'] = false;
        $result['errors'][] = $this->t('No entry point mappings found. Please use "Ingest PMSR Ontologies" to create them.');
      } elseif ($mappings_found < count($expected_mappings)) {
        $result['warnings'][] = $this->t('Only @found of @total expected entry point mappings were found.', [
          '@found' => $mappings_found,
          '@total' => count($expected_mappings),
        ]);
      }

    } catch (\Exception $e) {
      $result['success'] = false;
      $result['errors'][] = $this->t('Error verifying entry point mappings: @msg', ['@msg' => $e->getMessage()]);
      return $result;
    }

    return $result;
  }

}
