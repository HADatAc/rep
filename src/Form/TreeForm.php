<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class TreeForm extends FormBase {

  protected $rootNode;

  public function getRootNode() {
    return $this->rootNode;
  }

  public function setRootNode($rootNode) {
    return $this->rootNode = $rootNode; 
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tree_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $nodeuri = NULL) {
    $api = \Drupal::service('rep.api_connector');

    // Retrieve root node
    $uri_decode = base64_decode($nodeuri);
    $this->setRootNode($api->parseObjectResponse($api->getUri($uri_decode), 'getUri'));
    if ($this->getRootNode() == NULL) {
        \Drupal::messenger()->addError(t("Failed to retrieve root node " . $uri_decode . "."));
        return [];
    }

    // Attach the JavaScript library
    $form['#attached']['library'][] = 'rep/rep_tree';

    // Form elements
    $form['title'] = [
        '#type' => 'markup',
        '#markup' => '<h1>Browse Tree</h1>',
    ];

    $form['action_reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Tree'),
    ];

    // Form elements
    $form['space_1'] = [
      '#type' => 'markup',
      '#markup' => '<br><br>',
    ];

    $form['tree_root'] = [
        '#type' => 'markup',
        '#markup' => '<div id="tree-root" data-initial-uri="' . $this->getRootNode()->uri . '">'
            . '<ul>'
            . '<li class="node" data-uri="' . $this->getRootNode()->uri . '" '  
            . ' data-node-id="' . $this->getRootNode()->nodeId . '">' 
            . $this->getRootNode()->label
            . '<ul id="children-node-' . $this->getRootNode()->nodeId . '"></ul>'
            . '</li>'
            . '</ul>'
            . '</div>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No submission logic for this example
    // If needed, handle form submissions here
  }
}
