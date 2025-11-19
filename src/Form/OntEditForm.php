<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\File\FileSystemInterface;

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
    $form['#attached']['library'][] = 'rep/ont_editor_states';

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
      '#value' => $this->t('Save App Ontology File'),
      '#button_type' => 'primary',
      '#attributes' => [
        'id' => 'rep-ont-save',
        'class' => ['mb-5', 'save-button'],
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

  /**
   * {@inheritdoc}
   *
   * Lightweight validation: require an owl:versionIRI in the Turtle content.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $data = (string) $form_state->getValue('rdf_editor_textarea');
    $pattern = '/owl:versionIRI\s+hasco:(\d+(?:\.\d+)?)\s*;/';

    if (!preg_match($pattern, $data)) {
      $form_state->setErrorByName(
        'rdf_editor_textarea',
        $this->t('Version IRI not found. Please include a line like: owl:versionIRI   hasco:1 ;')
      );
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
    if ($main_real !== FALSE) {
      file_put_contents($main_real, $updated);
    }

    $this->messenger()->addStatus($this->t(
      'Ontology archived under version @old and updated to version @new.',
      ['@old' => $cur, '@new' => $next]
    ));
  }

}
