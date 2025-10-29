<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\File\FileSystemInterface;
use Drupal\rep\Utils;

/**
 * Form to edit the Application Ontology RDF (TTL) file under private://ont.
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
    $config  = $this->config('rep.settings');
    $enabled = (bool) $config->get('localAppOntology');

    /**
     * GUARD CLAUSE:
     * If the feature is disabled in settings, show a red alert and STOP here.
     * No editor, no assets, nothing else is rendered.
     */
    if (!$enabled) {
      // Also push a system message (will appear via status_messages).
      $this->messenger()->addError($this->t('Local APP Ontology editing is disabled in settings. Please enable it to access the editor.'));
      $baseUrl = (\Drupal::request()->headers->get('x-forwarded-proto') === 'https' ? 'https://':'http://'). \Drupal::request()->getHost() . \Drupal::request()->getBaseUrl();

      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        'msg' => [
          '#markup' => $this->t('<p>&nbsp;</p><h4> <i class="fa-solid fa-circle-info"></i> INFO:</h4><h5><br />Local APP Ontology editing is disabled in settings.</h5><br />Please enable it in \'<a href="'.$baseUrl.'/admin/config/rep">Repository > Configurations</a>\' to access the editor.'),
        ],
      ];
    }

    // ------- From here down we render the editor (only when enabled). -------

    $filename = (string) $config->get('repository_namespace_prefix') . '.ttl';

    // Attach your editor libraries.
    $form['#attached']['library'][] = 'rep/rdf_graph_editor';
    $form['#attached']['library'][] = 'rep/ont_editor_states';

    // Pass endpoints to JS.
    $form['#attached']['drupalSettings']['repRdfEditor'] = [
      'filename' => $filename,
      'getUrl'   => Url::fromRoute('rep.api.get', ['filename' => $filename])->toString(),
      'saveUrl'  => Url::fromRoute('rep.api.save', ['filename' => $filename])->toString(),
    ];

    // Load file contents.
    $file_uri = 'private://ont/' . $filename;
    $realpath = \Drupal::service('file_system')->realpath($file_uri);

    if ($realpath === FALSE || !file_exists($realpath)) {
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

    $form['filename'] = [
      '#type' => 'hidden',
      '#value' => $filename,
    ];

    // 0 = clean; 1 = dirty (your JS toggles this).
    $form['is_dirty'] = [
      '#type' => 'hidden',
      '#value' => '0',
    ];

    // Top-right actions.
    $form['actions_top'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['d-flex', 'justify-content-end', 'mb-3']],
    ];

    $form['actions_top']['view_application_ontology'] = [
      '#type' => 'link',
      '#title' => $this->t('View Application Ontology'),
      '#url' => Url::fromRoute('rep.ont_view'),
      '#attributes' => [
        'class' => ['btn', 'button', 'button--primary', 'mx-2'],
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
    ];

    $form['actions_top']['ingest_application_ontology'] = [
      '#type' => 'link',
      '#title' => $this->t('Ingest Application Ontology'),
      '#url' => Url::fromRoute('rep.ont_injest'),
      '#attributes' => [
        'id' => 'rep-ont-ingest',
        'class' => ['btn', 'button', 'button--warning'],
      ],
    ];

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

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Application Ontology File'),
      '#button_type' => 'primary',
      '#attributes' => [
        'id' => 'rep-ont-save',
        'class' => ['mb-5'],
      ],
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
   * Lightweight validation: require an owl:versionIRI.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $data = (string) $form_state->getValue('rdf_editor_textarea');
    $pattern = '/owl:versionIRI\s+hadatac:(\d+(?:\.\d+)?)\s*;/';
    if (!preg_match($pattern, $data)) {
      $form_state->setErrorByName(
        'rdf_editor_textarea',
        $this->t('Version IRI not found. Please include a line like: owl:versionIRI   hadatac:1 ;')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $filename     = (string) $form_state->getValue('filename');
    $originalData = (string) $form_state->getValue('rdf_editor_textarea');
    $fs = \Drupal::service('file_system');

    $pattern = '/owl:versionIRI\s+hadatac:(\d+(?:\.\d+)?)\s*;/';
    if (!preg_match($pattern, $originalData, $m)) {
      $this->messenger()->addError($this->t('Version IRI not found; no changes were saved.'));
      return;
    }
    $cur = $m[1];
    $next = (string) (intval($cur) + 1);

    $updated = preg_replace($pattern, 'owl:versionIRI   hadatac:' . $next . ' ;', $originalData, 1);
    $labelPattern = '/rdfs:label\s+"HADATAC Ontology v\d+"\s*;/';
    $updated = preg_replace($labelPattern, 'rdfs:label       "HADATAC Ontology v' . $next . '" ;', $updated, 1);

    $dirOld = 'private://ont/' . $cur;
    $dirNew = 'private://ont/' . $next;
    $fs->prepareDirectory($dirOld, FileSystemInterface::CREATE_DIRECTORY);
    $fs->prepareDirectory($dirNew, FileSystemInterface::CREATE_DIRECTORY);

    file_put_contents($fs->realpath($dirOld) . '/' . $filename, $originalData);
    file_put_contents($fs->realpath($dirNew) . '/' . $filename, $updated);
    file_put_contents($fs->realpath('private://ont/' . $filename), $updated);

    $this->messenger()->addStatus($this->t(
      'Ontology archived under version @old and updated to version @new.',
      ['@old' => $cur, '@new' => $next]
    ));
  }
}
