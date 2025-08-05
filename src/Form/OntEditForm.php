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

    $form['rdf_editor_textarea'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ontology (Turtle)'),
      // '#description' => $this->t('Edite aqui o TTL. O grafo será atualizado automaticamente ao lado.'),
      '#attributes' => [
        'id'    => 'rdf-editor-textarea',
        'rows'  => 25,
        'style' => 'font-family: monospace;',
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   * Only checks presence of version IRI, skips full RDF parsing to preserve file format.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $data = $form_state->getValue('rdf_editor_textarea');
    $pattern = '/owl:versionIRI\s+hasco:[0-9]+\.[0-9]+\s*;/';
    if (!preg_match($pattern, $data)) {
      $form_state->setErrorByName('rdf_editor_textarea', $this->t('Version IRI pattern not found.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $filename = $form_state->getValue('filename');
    $data = $form_state->getValue('rdf_editor_textarea');
    $fileSystem = \Drupal::service('file_system');

    // INJECT subClassOf
    // 1) Verifica se existe o bloco hasco:Attribute
    if (!preg_match('/^\s*hasco:Attribute\b/m', $data)) {
      $this->messenger()->addError($this->t('Elemento hasco:Attribute não encontrado. Nenhuma alteração foi feita.'));
      // Redireciona de volta ao formulário sem prosseguir
      $form_state->setRedirectUrl(Url::fromRoute('rep.ont_edit', ['filename' => $filename]));
      return;
    }

    // 2) Injeta o novo triplo abaixo do final do bloco (antes do ponto final)
    $data = preg_replace(
      // Captura desde "hasco:Attribute" até o primeiro "." que fecha o bloco
      '/(hasco:Attribute\b[\s\S]*?\.)/m',
      // Reinsere o fechamento e adiciona o novo nó
      "$1\n\n<http://xmlns.com/foaf/0.1/Agent>\n    rdfs:label  \"Agent\" ;\n    rdf:type  owl:Class ;\n    rdfs:comment  \"An agent is an entity that acts, or has the capacity to act.\" ;\n    rdfs:subClassOf  hasco:Attribute .",
      $data,
      1  // apenas a primeira ocorrência
    );

    // Match current version IRI.
    $pattern = '/owl:versionIRI\s+hasco:([0-9]+\.[0-9]+)\s*;/';
    if (!preg_match($pattern, $data, $matches)) {
      $this->messenger()->addError('Version IRI pattern not found. No changes made.');
      $form_state->setRedirectUrl(Url::fromRoute('rep.edit', ['filename' => $filename]));
      return;
    }

    $currentVersion = $matches[1];
    list($major, $minor) = explode('.', $currentVersion);
    $newVersion = $major . '.' . ($minor + 1);

    // Define and prepare version directories.
    $dirCurrent = 'private://ont/' . $currentVersion;
    $dirNew = 'private://ont/' . $newVersion;
    $fileSystem->prepareDirectory($dirCurrent, FileSystemInterface::CREATE_DIRECTORY);
    $fileSystem->prepareDirectory($dirNew, FileSystemInterface::CREATE_DIRECTORY);

    $origDir = $fileSystem->realpath($dirCurrent);
    $newDir  = $fileSystem->realpath($dirNew);
    $baseFile = $fileSystem->realpath('private://ont/' . $filename);

    // Archive original content.
    file_put_contents($origDir . '/' . $filename, $data);

    // Update version IRI in content.
    $newData = preg_replace($pattern, 'owl:versionIRI   hasco:' . $newVersion . ' ;', $data, 1);

    // Update RDF label to reflect new version.
    $labelPattern = '/(rdfs:label\s+")HASCO Ontology v[0-9]+\.[0-9]+("\s*;)/';
    $labelReplacement = '$1HASCO Ontology v' . $newVersion . '$2';
    $newData = preg_replace($labelPattern, $labelReplacement, $newData, 1);

    // Save updated files.
    file_put_contents($newDir . '/' . $filename, $newData);
    file_put_contents($baseFile, $newData);

    $this->messenger()->addStatus('File archived under version ' . $currentVersion . ' and updated to version ' . $newVersion . '.');
    $form_state->setRedirectUrl(Url::fromRoute('rep.ont_edit', ['filename' => $filename]));
  }
}
