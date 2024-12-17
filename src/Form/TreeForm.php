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

  public function getFormId() {
    return 'tree_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $mode=NULL, $elementtype=NULL) {

    $api = \Drupal::service('rep.api_connector');

    if ($mode == NULL || $mode == '') {
      \Drupal::messenger()->addError(t("A mode is required to inspect a concept hierarchy."));
      return [];
    }
    if ($mode != 'browse' && $mode != 'select') {
      \Drupal::messenger()->addError(t("A valid mode is required to inspect a concept hierarchy."));
      return [];
    }

    if ($elementtype == NULL || $elementtype == '') {
      \Drupal::messenger()->addError(t("An element type is required to inspect a concept hierarchy."));
      return [];
    }
    $this->setElementType($elementtype);

    $validTypes = [
      'attribute' => ["Attribute", SIO::ATTRIBUTE],
      'entity' => ["Entity", SIO::ENTITY],
      'unit' => ["Unit", SIO::UNIT],
      'platform' => ["Platform", VSTOI::PLATFORM],
      'instrument' => ["Instrument", VSTOI::INSTRUMENT],
      'detector' => ["Detector", VSTOI::DETECTOR],
      'detectorstem' => ["Detector Stem", VSTOI::DETECTOR_STEM]
    ];

    if (array_key_exists($this->getElementType(), $validTypes)) {
      [$elementName, $nodeUri] = $validTypes[$this->getElementType()];
    } else {
      \Drupal::messenger()->addError(t("No valid element type has been provided."));
      return [];
    }

    $this->setRootNode($api->parseObjectResponse($api->getUri($nodeUri), 'getUri'));
    if ($this->getRootNode() == NULL) {
        \Drupal::messenger()->addError(t("Failed to retrieve root node " . $nodeUri . "."));
        return [];
    }

    $form['#attached']['library'][] = 'rep/rep_tree';

    $form['#attached']['drupalSettings']['rep_tree'] = [
      'apiEndpoint' => '/drupal/web/rep/getchildren', // Endpoint da API para carregamento dinâmico
      'branches' => [
        [
          'id' => 'attribute',
          'uri' => SIO::ATTRIBUTE,
          'label' => 'Attributes'
        ],
        [
          'id' => 'entity',
          'uri' => SIO::ENTITY,
          'label' => 'Entities'
        ],
        [
          'id' => 'unit',
          'uri' => SIO::UNIT,
          'label' => 'Units'
        ],
        [
          'id' => 'instrument',
          'uri' => VSTOI::INSTRUMENT,
          'label' => 'Instruments'
        ],
        [
          'id' => 'detectorstem',
          'uri' => VSTOI::DETECTOR_STEM,
          'label' => 'Detector Stems'
        ],
        [
          'id' => 'detector',
          'uri' => VSTOI::DETECTOR,
          'label' => 'Detectors'
        ],
        [
          'id' => 'platform',
          'uri' => VSTOI::PLATFORM,
          'label' => 'Platforms'
        ],
        [
          'id' => 'codebook',
          'uri' => VSTOI::CODEBOOK,
          'label' => 'Codebook'
        ],
        [
          'id' => 'response_options',
          'uri' => VSTOI::RESPONSE_OPTION,
          'label' => 'Response Options'
        ],
        [
          'id' => 'annotation_stems',
          'uri' => VSTOI::ANNOTATION_STEM,
          'label' => 'Annotation Stems'
        ],
        [
          'id' => 'annotations',
          'uri' => VSTOI::ANNOTATION,
          'label' => 'Annotations'
        ],
      ]
    ];

    $form['title'] = [
        '#type' => 'markup',
        //'#markup' => '<h3>' . $elementName . ' Hierarchy</h3>',
        '#markup' => '<h3 class="mt-4 mb-4">Knowledge Graph Hierarchy</h3>',
    ];

    $form['controls'] = [
      '#type' => 'inline_template',
      '#template' => '
        <div style="margin-bottom: 10px;">
          <button type="button" id="expand-all" class="btn btn-primary btn-sm">Expand All</button>
          <button type="button" id="collapse-all" class="btn btn-secondary btn-sm">Collapse All</button>
          <button type="button" id="select-all" class="btn btn-success btn-sm">Select All</button>
          <button type="button" id="unselect-all" class="btn btn-danger btn-sm">Unselect All</button>
        </div>',
    ];

    $form['search_wrapper'] = [
      '#type' => 'inline_template',
      '#template' => '
        <div style="position: relative; max-width: 350px;" class="js-form-wrapper form-wrapper" id="edit-search-wrapper" data-drupal-selector="edit-search-wrapper">
          <div class="js-form-item js-form-type-textfield form-type-textfield js-form-item-search-input form-item-search-input form-no-label">
            <input id="tree-search" class="form-control" placeholder="Search..." style="padding-right: 30px; margin-bottom: 10px;" autocomplete="off" data-drupal-selector="edit-search-input" type="text" name="search_input" value="" size="60" maxlength="128">
          </div>
          <button id="clear-search" type="button" style="position: absolute; top: 40%; right: 5px; transform: translateY(-50%); background: transparent; border: none; font-size: 16px; color: #888; cursor: pointer; display: none;" data-drupal-selector="edit-clear-button">×</button>
        </div>
      ',
    ];

    $form['tree_root'] = [
        '#type' => 'markup',
        '#markup' => '<div id="tree-root" data-initial-uri="' . $this->getRootNode()->uri . '"></div>',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No submission logic for this example
  }
}
