<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\rep\Controller\OntController;
use EasyRdf\Graph;
use EasyRdf\Parser\Turtle;

/**
 * Form to edit the Application Ontology RDF (TTL) file under private://ont.
 *
 * This editor always works on the local HASCO ontology file:
 *   private://ont/hasco.ttl
 * There is no configuration toggle anymore; local editing is always enabled
 * as long as the ontology file exists.
 */
class OntEditForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_ont_edit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // The ontology filename is now fixed and always local.
    $filename = (string) 'hasco.ttl';

    // Attach editor libraries (JS/CSS).
    $form['#attached']['library'][] = 'rep/rdf_graph_editor';

    $form['messages'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'rep-ont-edit-messages'],
      'status' => ['#type' => 'status_messages'],
    ];

    // Pass endpoints to JS via drupalSettings.
    $form['#attached']['drupalSettings']['repRdfEditor'] = [
      'filename' => $filename,
      'getUrl'   => Url::fromRoute('rep.api.get', ['filename' => $filename])->toString(),
      'saveUrl'  => Url::fromRoute('rep.api.save', ['filename' => $filename])->toString(),
    ];

    // Load file contents from private://ont/hasco.ttl.
    $file_uri = 'private://ont/' . $filename;
    $realpath = \Drupal::service('file_system')->realpath($file_uri);

    if ($realpath === FALSE || !file_exists($realpath)) {
      // If the file does not exist, show an error and stop here.
      $this->messenger()->addError($this->t('Ontology file not found: @file', ['@file' => $filename]));
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        'msg' => [
          '#markup' => $this->t('Ontology file not found: @file', ['@file' => $filename]),
        ],
      ];
    }

    $content = (string) file_get_contents($realpath);

    // Pass editor state and history to JS (for diff/revert).
    $form['#attached']['drupalSettings']['repRdfEditor']['currentVersion'] = $this->extractOntologyVersion($content);
    $form['#attached']['drupalSettings']['repRdfEditor']['history'] = $this->buildOntologyHistory($filename);

    // Store the filename as a hidden field so we can reuse it on submit.
    $form['filename'] = [
      '#type' => 'hidden',
      '#value' => $filename,
    ];

    // 0 = clean; 1 = dirty (your JS toggles this value).
    $form['is_dirty'] = [
      '#type' => 'hidden',
      '#value' => '0',
    ];

    // Top-right actions (view and ingest ontology).
    $form['actions_top'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['d-flex', 'justify-content-end', 'mb-3']],
    ];

    $form['actions_top']['view_application_ontology'] = [
      '#type' => 'link',
      '#title' => $this->t('View App Ontology'),
      '#url' => Url::fromRoute('rep.ont_view'),
      '#attributes' => [
        'class' => ['btn', 'button', 'button--primary', 'mx-2', 'view-button'],
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
    ];

    $form['actions_top']['ingest_application_ontology'] = [
      '#type' => 'link',
      '#title' => $this->t('Ingest App Ontology'),
      '#url' => Url::fromRoute('rep.ont_injest'),
      '#attributes' => [
        'id' => 'rep-ont-ingest',
        'class' => ['btn', 'button', 'button--warning', 'ingest_mt-button'],
      ],
    ];

    // JS injects linting, history, diff and revert controls here.
    $form['tools'] = [
      '#type' => 'markup',
      '#markup' => '<div id="rep-ont-tools" class="mb-2"></div>',
    ];

    // Main textarea with the Turtle content.
    $form['rdf_editor_textarea'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ontology (Turtle)'),
      '#default_value' => $content,
      '#attributes' => [
        'id'    => 'rdf-editor-textarea',
        'rows'  => 25,
        'style' => 'font-family: monospace;',
      ],
      '#wrapper_attributes' => [
        'style' => 'min-height: 400px; overflow: auto;',
      ],
    ];

    // Bottom actions (Save button).
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save & Ingest App Ontology'),
      '#button_type' => 'primary',
      '#attributes' => [
        'id' => 'rep-ont-save',
        'class' => ['mb-5', 'save-button'],
      ],
      '#ajax' => [
        'callback' => '::ajaxSave',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Saving and ingesting the App Ontology...'),
        ],
      ],
      // Button only enabled when is_dirty == 1 (controlled via JS).
      '#states' => [
        'disabled' => [
          ':input[name="is_dirty"]' => ['value' => '0'],
        ],
      ],
    ];

    return $form;
  }

  private function extractOntologyVersion(string $ttl): ?string {
    if (preg_match('/owl:versionIRI\s+hasco:(\d+(?:\.\d+)?)\s*;/', $ttl, $m)) {
      return (string) $m[1];
    }
    return null;
  }

  private function buildOntologyHistory(string $filename): array {
    $fs = \Drupal::service('file_system');
    $root = $fs->realpath('private://ont');
    if ($root === FALSE || !is_dir($root)) {
      return ['versions' => [], 'snapshots' => []];
    }

    $versions = [];
    foreach (@scandir($root) ?: [] as $entry) {
      if (!preg_match('/^\d+$/', $entry)) {
        continue;
      }
      $candidate = $root . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . $filename;
      if (is_file($candidate)) {
        $versions[(int) $entry] = [
          'ref' => $entry,
          'label' => (string) $this->t('Version @v', ['@v' => $entry]),
        ];
      }
    }
    krsort($versions, SORT_NUMERIC);

    $snapshots = [];
    $snapBase = $root . DIRECTORY_SEPARATOR . 'versions';
    if (is_dir($snapBase)) {
      foreach (@scandir($snapBase) ?: [] as $entry) {
        if (!preg_match('/^v\d{4}$/', $entry)) {
          continue;
        }
        $candidate = $snapBase . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . $filename;
        if (is_file($candidate)) {
          $snapshots[$entry] = [
            'ref' => 'versions/' . $entry,
            'label' => (string) $this->t('Snapshot @v', ['@v' => $entry]),
          ];
        }
      }
      krsort($snapshots, SORT_NATURAL);
    }

    return [
      'versions' => array_values($versions),
      'snapshots' => array_values($snapshots),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Lightweight validation: require an owl:versionIRI in the Turtle content.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $ttl = (string) $form_state->getValue('rdf_editor_textarea');

    if ($this->extractOntologyVersion($ttl) === null) {
      $form_state->setErrorByName(
        'rdf_editor_textarea',
        $this->t('Version IRI not found. Please include a line like: owl:versionIRI   hasco:1 ;')
      );
      return;
    }

    // Turtle syntax validation (best-effort; depends on EasyRdf being available).
    // Client-side validation (N3.js) already blocks the Save button on parse errors.
    if (class_exists(Graph::class) && class_exists(Turtle::class)) {
      $graph = new Graph();
      $parser = new Turtle();
      try {
        $parser->parse($graph, $ttl, 'turtle', '');
      }
      catch (\Throwable $e) {
        $form_state->setErrorByName(
          'rdf_editor_textarea',
          $this->t('Turtle parse error: @msg', ['@msg' => $e->getMessage()])
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Increments the ontology version and keeps an archive per version:
   *  - private://ont/{oldVersion}/hasco.ttl   => original content
   *  - private://ont/{newVersion}/hasco.ttl   => updated content
   *  - private://ont/hasco.ttl                => updated content (current)
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $filename     = (string) $form_state->getValue('filename');
    $originalData = (string) $form_state->getValue('rdf_editor_textarea');
    $fs = \Drupal::service('file_system');

    // Extract current version from owl:versionIRI.
    $pattern = '/owl:versionIRI\s+hasco:(\d+(?:\.\d+)?)\s*;/';
    if (!preg_match($pattern, $originalData, $m)) {
      $form_state->set('rep_ont_saved_ok', false);
      $this->messenger()->addError($this->t('Version IRI not found; no changes were saved.'));
      return;
    }

    $cur  = $m[1];
    $next = (string) (intval($cur) + 1);

    // Update owl:versionIRI to the next version.
    $updated = preg_replace($pattern, 'owl:versionIRI   hasco:' . $next . ' ;', $originalData, 1);

    // Update label to reflect new version (if present).
    $labelPattern = '/rdfs:label\s+"Hasco APP Ontology v\d+"\s*;/';
    $updated = preg_replace($labelPattern, 'rdfs:label       "HASCO Ontology v' . $next . '" ;', $updated, 1);

    // Prepare directories for old and new versions.
    $dirOld = 'private://ont/' . $cur;
    $dirNew = 'private://ont/' . $next;

    $fs->prepareDirectory($dirOld, FileSystemInterface::CREATE_DIRECTORY);
    $fs->prepareDirectory($dirNew, FileSystemInterface::CREATE_DIRECTORY);

    // Archive original content under the old version directory.
    $old_real = $fs->realpath($dirOld);
    if ($old_real !== FALSE) {
      file_put_contents($old_real . '/' . $filename, $originalData);
    }

    // Save updated content under the new version directory.
    $new_real = $fs->realpath($dirNew);
    if ($new_real !== FALSE) {
      file_put_contents($new_real . '/' . $filename, $updated);
    }

    // Overwrite the main ontology file with the updated content.
    $main_real = $fs->realpath('private://ont/' . $filename);
    if ($main_real === FALSE || @file_put_contents($main_real, $updated) === FALSE) {
      $form_state->set('rep_ont_saved_ok', false);
      $this->messenger()->addError($this->t('Failed to write the ontology file.'));
      return;
    }

    $form_state->set('rep_ont_saved_ok', true);
    $form_state->set('rep_ont_new_version', $next);

    $this->messenger()->addStatus($this->t(
      'Ontology archived under version @old and updated to version @new.',
      ['@old' => $cur, '@new' => $next]
    ));

    // Ingest immediately after saving.
    try {
      $ontController = new OntController();
      $ontController->injest('rep.ont_edit');
    }
    catch (\Throwable $e) {
      $this->messenger()->addWarning($this->t('Ingestion failed: @msg', ['@msg' => $e->getMessage()]));
    }
  }

  /**
   * AJAX callback: keep editor state (no reload) and update messages.
   */
  public function ajaxSave(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // Update status messages in-place.
    $rendered_messages = (string) \Drupal::service('renderer')->renderRoot($form['messages']);
    $response->addCommand(new ReplaceCommand('#rep-ont-edit-messages', $rendered_messages));

    $saved_ok = (bool) $form_state->get('rep_ont_saved_ok');
    if ($saved_ok) {
      $new_version = (string) $form_state->get('rep_ont_new_version');
      $response->addCommand(new InvokeCommand('body', 'repOntAfterSave', [$new_version]));
    }

    return $response;
  }

}
