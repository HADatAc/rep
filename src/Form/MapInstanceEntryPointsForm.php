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

/**
 * Form to browse an ontology and save an INSTANCE mapping.
 *
 * LEFT column:
 *   - Loads the current ontology tree from a fixed HASCO InstanceEntryPoint.
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
class MapInstanceEntryPointsForm extends FormBase {

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
    return 'rep_map_instance_entry_points_form';
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

    // Root URI for the LEFT tree (HASCO InstanceEntryPoint).
    $root_from_settings = (string) 'http://hadatac.org/ont/hasco/InstanceEntryPoint';
    $root_label         = (string) 'HASCO INSTANCES';
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

    // LEFT column: current tree, always from the fixed HASCO InstanceEntryPoint.
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
      $header = "# Ontology file created by MapInstanceEntryPointsForm\n";
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

      $append .= "\n# --- Mapping appended by MapInstanceEntryPointsForm ---\n";
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

      // Ingest updated hasco.ttl via allowed namespace-ingest path.
      try {
        $api = \Drupal::service('rep.api_connector');
        $file_content = (string) file_get_contents($ttl_path);
        $ingest_result = $api->repoIngestNamespaceOntology(
          'hasco',
          'http://hadatac.org/ont/hasco/',
          $file_content,
          'text/turtle',
          'rep-map-entrypoints'
        );
        $ingest_data = json_decode($ingest_result);
        if (!$ingest_data || empty($ingest_data->isSuccessful)) {
          $this->messenger()->addWarning($this->t('Automatic ingestion warning: @msg', [
            '@msg' => $ingest_data->body ?? 'Unknown error while ingesting hasco.ttl',
          ]));
        }
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

}
