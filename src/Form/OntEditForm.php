<?php
// modules/custom/REP/src/Form/OntEditForm.php
namespace Drupal\REP\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\File\FileSystemInterface;

/**
 * Provides a form to edit RDF files in the private ont/ directory.
 */
class OntEditForm extends FormBase {
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_ont_edit_form';
  }

  /**
   * Builds the RDF editor form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $filename = NULL) {

    $form['#attached']['library'][] = 'rep/rdf_graph_editor';

    $form['#attached']['drupalSettings']['repRdfEditor'] = [
      'filename' => $filename,
      'getUrl'   => Url::fromRoute('rep.api.get', ['filename' => $filename])->toString(),
      'saveUrl'  => Url::fromRoute('rep.api.save', ['filename' => $filename])->toString(),
    ];

    $file_path = 'private://ont/' . $filename;
    $realpath = \Drupal::service('file_system')->realpath($file_path);

    if (!file_exists($realpath)) {
      return [
        '#markup' => $this->t('File not found: @file', ['@file' => $filename]),
      ];
    }

    $content = file_get_contents($realpath);

    $form['filename'] = [
      '#type' => 'hidden',
      '#value' => $filename,
    ];

    $form['injest_button'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'justify-content-end', 'mb-3'],
      ],
    ];
    $form['injest_button']['view_application_ontology'] = [
      '#type' => 'link',
      '#title' => $this->t('View Application Ontology'),
      '#url' => Url::fromRoute('rep.ont_load', ['filename' => $filename]),
      '#attributes' => [
        'class' => ['btn', 'button', 'button--primary', 'view-button', 'text-align-center', 'mx-2'],
        'target' => '_new',
        'rel' => 'noopener noreferrer',
      ],
    ];
    $form['injest_button']['injest_application_ontology'] = [
      '#type' => 'link',
      '#title' => $this->t('Injest Application Ontology'),
      '#url' => Url::fromRoute('rep.ont_injest'),
      '#attributes' => [
        'class' => ['btn', 'button', 'button--warning', 'ingest_mt-button', 'text-align-center'],
      ],
    ];

    $form['rdf_editor_textarea'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ontology (Turtle)'),
      '#attributes' => [
        'id'    => 'rdf-editor-textarea',
        'rows'  => 25,
        'style' => 'font-family: monospace;',
      ],
      '#wrapper_attributes' => [
        'style' => 'min-height: 400px; overflow: auto;',
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Application ontology File'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['mb-5', 'save-button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   * Only checks presence of version IRI, skips full RDF parsing to preserve file format.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Look for a line like:
    //   owl:versionIRI   hadatac:1 ;
    $data = $form_state->getValue('rdf_editor_textarea');
    $versionPattern = '/owl:versionIRI\s+hadatac:(\d+(?:\.\d+)?)\s*;/';

    if (!preg_match($versionPattern, $data)) {
      // Prevent submission if no valid version IRI is found.
      $form_state->setErrorByName(
        'rdf_editor_textarea',
        $this->t('Version IRI pattern not found. Please include a line like “owl:versionIRI   hadatac:1 ;”.')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve filename and textarea contents.
    $filename = $form_state->getValue('filename');
    $originalData = $form_state->getValue('rdf_editor_textarea');
    $fileSystem = \Drupal::service('file_system');

    // 1) Find the current version number (e.g. "1" or "2.3").
    $versionPattern = '/owl:versionIRI\s+hadatac:(\d+(?:\.\d+)?)\s*;/';
    if (!preg_match($versionPattern, $originalData, $matches)) {
      $this->messenger()->addError($this->t('Version IRI pattern not found; no changes were made.'));
      $form_state->setRedirectUrl(Url::fromRoute('rep.ont_edit', ['filename' => $filename]));
      return;
    }
    $currentVersion = $matches[1];
    // Increment the integer part; adjust logic if you need different versioning.
    $newVersion = intval($currentVersion) + 1;

    // 2) Replace the version IRI line.
    $updatedData = preg_replace(
      $versionPattern,
      'owl:versionIRI   hadatac:' . $newVersion . ' ;',
      $originalData,
      1
    );

    // 3) Replace the rdfs:label line to match the new version.
    $labelPattern = '/rdfs:label\s+"HADATAC Ontology v\d+"\s*;/';
    $updatedData = preg_replace(
      $labelPattern,
      'rdfs:label       "HADATAC Ontology v' . $newVersion . '" ;',
      $updatedData,
      1
    );

    // 4) Prepare directories for archiving.
    $dirCurrent = 'private://ont/' . $currentVersion;
    $dirNew     = 'private://ont/' . $newVersion;
    $fileSystem->prepareDirectory($dirCurrent, FileSystemInterface::CREATE_DIRECTORY);
    $fileSystem->prepareDirectory($dirNew, FileSystemInterface::CREATE_DIRECTORY);

    $origDir  = $fileSystem->realpath($dirCurrent);
    $newDir   = $fileSystem->realpath($dirNew);
    $baseFile = $fileSystem->realpath('private://ont/' . $filename);

    // 5) Archive the original file under its version directory.
    file_put_contents($origDir . '/' . $filename, $originalData);

    // 6) Save the updated ontology into the new version folder and overwrite the base file.
    file_put_contents($newDir . '/' . $filename, $updatedData);
    file_put_contents($baseFile, $updatedData);

    // 7) Notify the user of success.
    $this->messenger()->addStatus(
      $this->t(
        'Ontology archived under version @old and updated to version @new.',
        ['@old' => $currentVersion, '@new' => $newVersion]
      )
    );

    // Redirect back to the edit form for this file.
    $form_state->setRedirectUrl(Url::fromRoute('rep.ont_edit', ['filename' => $filename]));
  }
}
