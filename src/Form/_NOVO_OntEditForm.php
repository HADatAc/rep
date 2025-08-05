<?php
// modules/custom/REP/src/Form/OntEditForm.php
namespace Drupal\REP\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a form embedding an RDF graph editor component (CodeMirror + Cytoscape).
 */
class OntEditForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_ont_edit_form';
  }

  /**
   * Builds the RDF graph editor form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $filename = NULL) {
    // 1) Anexa a library que carrega CodeMirror, N3.js e Cytoscape + nosso init.
    $form['#attached']['library'][] = 'rep/rdf_graph_editor';

    // 2) Passa URLs de API e filename para o JS.
    $form['#attached']['drupalSettings']['repRdfEditor'] = [
      'filename' => $filename,
      'getUrl'   => Url::fromRoute('rep.api.get', ['filename' => $filename])->toString(),
      'saveUrl'  => Url::fromRoute('rep.api.save', ['filename' => $filename])->toString(),
    ];

    // 3) Textarea para o editor de Turtle (CodeMirror irá “transformar” em rich editor).
    $form['rdf_editor_textarea'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ontology (Turtle)'),
      '#description' => $this->t('Edite aqui o TTL. O grafo será atualizado automaticamente ao lado.'),
      '#attributes' => [
        'id'    => 'rdf-editor-textarea',
        'rows'  => 15,
        'style' => 'font-family: monospace;',
      ],
    ];

    // 4) Container para o grafo (Cytoscape irá renderizar dentro deste div).
    $form['rdf_graph'] = [
      '#type' => 'container',
      '#attributes' => [
        'id'    => 'rdf-editor-graph',
        'style' => 'width:100%; height:500px; border:1px solid #ccc; margin-top:1em;',
      ],
    ];

    return $form;
  }

  /**
   * Intentionally left blank: all saving happens via JS fetch -> rep.api.save
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Sem submissão PHP normal
  }
}
