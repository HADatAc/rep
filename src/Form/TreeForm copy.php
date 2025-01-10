<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\SIO;
use Drupal\rep\Vocabulary\VSTOI;

class TreeForm extends FormBase {

  protected $elementType;

  protected $rootNode;

  public function getElementType() {
    return $this->elementType;
  }

  public function setElementType($elementType) {
    return $this->elementType = $elementType;
  }

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
  public function buildForm(array $form, FormStateInterface $form_state, $mode=NULL, $elementtype=NULL) {
    $api = \Drupal::service('rep.api_connector');

    // Retrieve mode
    if ($mode == NULL || $mode == '') {
      \Drupal::messenger()->addError(t("A mode is required to inspect a concept hierarchy."));
      return [];
    }
    if ($mode != 'browse' && $mode != 'select') {
      \Drupal::messenger()->addError(t("A valid mode is required to inspect a concept hierarchy."));
      return [];
    }

    // Retrieve element type
    if ($elementtype == NULL || $elementtype == '') {
      \Drupal::messenger()->addError(t("An element type is required to inspect a concept hierarchy."));
      return [];
    }
    $this->setElementType($elementtype);
    if ($this->getElementType() == 'attribute') {
      $elementName = "Attribute";
      $nodeUri = SIO::ATTRIBUTE;
    } else if ($this->getElementType() == 'entity') {
      $elementName = "Entity";
      $nodeUri = SIO::ENTITY;
    } else if ($this->getElementType() == 'unit') {
      $elementName = "Unit";
      $nodeUri = SIO::UNIT;
    } else if ($this->getElementType() == 'platform') {
      $elementName = "Platform";
      $nodeUri = VSTOI::PLATFORM;
    } else if ($this->getElementType() == 'instrument') {
      $elementName = "Instrument";
      $nodeUri = VSTOI::INSTRUMENT;
    } else if ($this->getElementType() == 'detector') {
      $elementName = "Detector";
      $nodeUri = VSTOI::DETECTOR;
    } else if ($this->getElementType() == 'detectorstem') {
      $elementName = "Detector Stem";
      $nodeUri = VSTOI::DETECTOR_STEM;
    } else {
      \Drupal::messenger()->addError(t("No valid element type has been provided."));
      return [];
    }

    // Retrieve root node
    $this->setRootNode($api->parseObjectResponse($api->getUri($nodeUri), 'getUri'));
    if ($this->getRootNode() == NULL) {
        \Drupal::messenger()->addError(t("Failed to retrieve root node " . $nodeUri . "."));
        return [];
    }

    // Attach the JavaScript library
    $form['#attached']['library'][] = 'rep/rep_tree';

    // Form elements
    $form['title'] = [
        '#type' => 'markup',
        '#markup' => '<h3>' . $elementName . ' Hierarchy</h3>',
    ];

    $form['action_reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Tree'),
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'reset-button'],
      ],
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
