<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Formulário principal do Workflow Canvas (vis-network).
 */
class VisWorkflowForm extends FormBase {

  public function getFormId() {
    return 'rep_workflow_canvas';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    // 1) Dados base (exemplo simples)
    $graphData = [
      'nodes' => [
        ['id' => 'T:Root',   'label' => 'Root Task', 'type' => 'task'],
        ['id' => 'T:Login',  'label' => 'Login',     'type' => 'task'],
        ['id' => 'T:Browse', 'label' => 'Browse',    'type' => 'task'],
      ],
      'edges' => [
        ['from' => 'T:Root',  'to' => 'T:Login'],
        ['from' => 'T:Login', 'to' => 'T:Browse', 'label' => '>>'],
      ],
    ];

    // 2) Anexa library + injeta dados e endpoint
    $form['#attached']['library'][] = 'rep/workflow_canvas';
    $form['#attached']['drupalSettings']['workflowData'] = $graphData;
    $form['#attached']['drupalSettings']['rep']['workflowEndpoint'] =
      Url::fromRoute('rep.workflow.expand')->toString();

    // 3) Container do canvas (ID único)
   $form['canvas'] = [
  '#type' => 'container',
  '#attributes' => [
    'id' => 'workflow-canvas',
    'style' => 'width: 60%; height: 450px; border: 1px solid #ddd; background: #fafafa; margin-top: 10px;'
  ],
];


    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {}
}
