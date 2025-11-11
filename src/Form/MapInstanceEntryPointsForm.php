<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\rep\Entity\Tables;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Drupal\rep\Controller\OntController;

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
class MapInstanceEntryPointsForm extends FormBase {

  /**
   * Paths and filenames used for ontology storage and versioning.
   * Adjust if your OntEditForm uses different conventions.
   */
  private const ONT_ROOT_DIR       = 'private://ont';
  private const ONT_TTL_FILENAME   = 'hasco.ttl';     // root TTL file
  private const VERSIONS_SUBDIR    = 'versions';          // incremental versions
  private const VERSION_PREFIX     = 'v';                 // e.g., v0001, v0002, ...

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

    // Root URI for the LEFT tree, coming from settings (example fallback here).
    $root_from_settings = (string) 'http://hadatac.org/ont/hasco/EntryPoint';
    $root_label         = (string) 'HASCO';
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
        . ' class="border border-1 p-2 treeMOL"></div>',
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
      // '#title'         => $this->t('Ontology Namespace'),
      '#description'   => $this->t('Select the base namespace to explore on the right.'),
      '#empty_option'  => $this->t('Select…'),
      '#options'       => $ns_options,
      '#default_value' => $selected_ns,
      '#attributes'    => ['class' => ['map-ontology-select']],
      '#prefix'        => '<div class="col-md-8">',
      '#suffix'        => '</div>',
    ];

    $form['row']['right_col']['load_tree'] = [
      '#type'       => 'submit',
      '#value'      => $this->t('Load Ontology Tree'),
      '#attributes' => [
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

    $tables = new Tables;

    // Attach JS library and pass endpoints/settings to JS.
    $base = (\Drupal::request()->headers->get('x-forwarded-proto') === 'https' ? 'https://':'http://'). \Drupal::request()->getHost() . \Drupal::request()->getBaseUrl();
    $form['#attached']['library'][] = 'rep/map_entry_points';
    $form['#attached']['drupalSettings']['repMap'] = [
      'apiTopClassEndpoint' => $base . '/rep/gettopclass?_format=json',
      'apiEndpoint'         => $base . '/rep/getchildren?_format=json',
      'childParam'          => 'nodeUri',
      'currentRootUri'      => $root_from_settings,
      'currentRootLabel'    => $root_label,
      'nameSpacesList' => $tables->getNamespaces(),
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
      '#attributes'  => [
        'id'    => 'rep-map-entry-points-submit',
        'class' => ['save-button'],
      ],
    ];

    $form['row']['actions']['ingest_application_ontology'] = [
      '#type' => 'link',
      '#title' => $this->t('Ingest App Ontology'),
      '#url' => Url::fromRoute('rep.ont_injest',
        ['returnPathway' => 'rep.map_entry_points']
      ),
      '#attributes' => [
        'id' => 'rep-ont-ingest',
        'class' => ['btn', 'button', 'button--warning', 'ingest_mt-button'],
      ],
    ];

    $form['row']['notes'] = [
      '#type' => 'markup',
      '#markup' => '<div class="mt-1 mb-5"><em>' . $this->t('<strong>Information</strong>: The "Saving Mappings" button will automatically ingest the App Ontology, but the "Ingest Application Ontology" button won\'t save the mappings.') . '</em></div>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entry_point_uri   = (string) $form_state->getValue('selected_entry_point');
    $selected_node_uri = (string) $form_state->getValue('selected_node');

    if ($selected_node_uri === '') {
      $this->messenger()->addWarning($this->t('No node selected on the right tree.'));
      return;
    }

    $fs = \Drupal::service('file_system');

    // Ensure ontology root directory exists (argument MUST be passed by reference).
    $ontRootDir = self::ONT_ROOT_DIR; // <-- use a variable, not a constant directly
    $fs->prepareDirectory($ontRootDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Resolve TTL file URI and path.
    $ttl_uri  = self::ONT_ROOT_DIR . '/' . self::ONT_TTL_FILENAME;
    $ttl_path = $fs->realpath($ttl_uri);

    // If TTL does not exist, create an empty file with a header marker.
    if (!file_exists($ttl_path)) {
      $header = "# Ontology file created by MapInstanceEntryPointsForm\n";
      file_put_contents($ttl_path, $header);
    }

    // Read current TTL content (used for @prefix check and version backup).
    $ttl_content_before = file_get_contents($ttl_path);

    // Create an incremental version folder and copy the current TTL there.
    try {
      $version_dir_uri = $this->createIncrementalVersionDirectory(self::ONT_ROOT_DIR, self::VERSIONS_SUBDIR);
      $fs->copy($ttl_uri, $version_dir_uri . '/' . self::ONT_TTL_FILENAME, FileSystemInterface::EXISTS_REPLACE);
    } catch (\Throwable $e) {
      $this->messenger()->addWarning($this->t('Versioning failed. Proceeding to write the mapping. Error: @e', ['@e' => $e->getMessage()]));
    }

    // Build Turtle entry.
    $subject = $this->formatTurtleTerm($selected_node_uri);
    $object  = $this->formatTurtleTerm($entry_point_uri);

    $new_map_entry  = "\n# --- Mapping appended by MapInstanceEntryPointsForm ---\n";
    $new_map_entry .= $subject . "\n\ta rdfs:Class;";
    $new_map_entry .= "\n\trdfs:subClassOf " . $object . " .\n";

    // Prefix check for selected_node_uri.
    $missing_prefix_warning = '';
    $maybe_prefix = $this->extractCompactPrefix($selected_node_uri);
    if ($maybe_prefix !== null && !$this->ttlHasPrefix($ttl_content_before, $maybe_prefix)) {
      $missing_prefix_warning = $this->t('Heads up: prefix "@p:" was NOT found in the @prefix header. The mapping was saved, but you must add that @prefix to the TTL file manually.', ['@p' => $maybe_prefix]);
    }

    // Append mapping.
    $ok = (bool) file_put_contents($ttl_path, $new_map_entry, FILE_APPEND | LOCK_EX);

    if ($ok) {

      // --- Dispara a ingestão via sub-request à route rep.ont_injest ---
      try {
        $ontController = new OntController();
        $ontController->injest();
      }
      catch (\Throwable $e) {
        $this->messenger()->addWarning($this->t('Error failed Auto Ingestion: @msg', ['@msg' => $e->getMessage()]));
      }

      // $this->messenger()->addStatus($this->t(
      //   'Mapping saved: @node -> @ep (appended at the end of @file).',
      //   ['@node' => $selected_node_uri, '@ep' => $entry_point_uri, '@file' => self::ONT_TTL_FILENAME]
      // ));
      if ($missing_prefix_warning) {
        $this->messenger()->addWarning($missing_prefix_warning);
      }

    } else {
      $this->messenger()->addError($this->t('Failed to append the mapping to the TTL file.'));
    }
  }


  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Format a Turtle term:
   * - If it looks like a full URI (http(s):// or urn:), wrap with <...>
   * - Otherwise, return as-is (assumed CURIE or QName with a known prefix).
   */
  private function formatTurtleTerm(string $term): string {
    $t = trim($term);
    if (preg_match('#^(https?://|urn:)#i', $t)) {
      // Avoid double-wrapping if user already provided <...>
      if ($t[0] !== '<') {
        return '<' . $t . '>';
      }
      return $t;
    }
    // Likely a prefixed name like envo:Class
    return $t;
  }

  /**
   * If the term is a compact prefixed name (e.g., envo:Something) and NOT a
   * full URI, returns the prefix (e.g., "envo"). Otherwise returns null.
   */
  private function extractCompactPrefix(string $term): ?string {
    $t = trim($term);
    // Ignore full URIs
    if (stripos($t, '://') !== false) {
      return null;
    }
    // Match prefix:suffix (where prefix starts with a letter or underscore)
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
   * Example structure:
   *   private://ont/versions/v0001/
   *   private://ont/versions/v0002/
   *
   * Returns the created directory URI (e.g., "private://ont/versions/v0003").
   */
  private function createIncrementalVersionDirectory(string $ontRootUri, string $versionsSubdir): string {
    $fs = \Drupal::service('file_system');

    // Ensure versions base directory exists: private://ont/versions
    $versions_base = rtrim($ontRootUri, '/') . '/' . trim($versionsSubdir, '/');
    $versionsBaseRef = $versions_base; // pass-by-ref requirement
    $fs->prepareDirectory($versionsBaseRef, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $real_versions_base = $fs->realpath($versions_base);
    $entries = @scandir($real_versions_base) ?: [];
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

    // Create that specific version directory (again, pass a variable by ref).
    $versionDirRef = $version_dir_uri;
    $fs->prepareDirectory($versionDirRef, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    return $version_dir_uri;
  }


}
