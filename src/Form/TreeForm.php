<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\SIO;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\rep\EntryPoints;
use Drupal\Core\Url;

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

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param null|string $mode Ex: 'browse' ou 'select'
   * @param null|string $elementtype Ex: 'unit', 'attribute', etc.
   * @param array|null $branches_param Ex: [
   *    ['id' => 'unit', 'uri' => SIO::UNIT, 'label' => 'Units']
   * ]
   * @param string|null $output_field_selector Ex: '#my-custom-field'
   */
  public function buildForm(array $form, FormStateInterface $form_state, $mode = NULL, $elementtype = NULL, array $branches_param = NULL, $output_field_selector = NULL) {

    // basic validation of parameters
    if (empty($mode) || empty($elementtype)) {
      \Drupal::messenger()->addError($this->t('Invalid parameters provided.'));
      return [];
    }

    // Additional form settings
    if ($mode === 'modal')
      $form['#attached']['library'][] = 'rep/rep_modal';

    $api = \Drupal::service('rep.api_connector');

    if ($mode == NULL || $mode == '') {
      \Drupal::messenger()->addError(t("A mode is required to inspect a concept hierarchy."));
      return [];
    }
    if ($mode != 'modal' && $mode != 'browse' && $mode != 'select') {
      \Drupal::messenger()->addError(t("A valid mode is required to inspect a concept hierarchy."));
      return [];
    }

    if ($elementtype == NULL || $elementtype == '') {
      \Drupal::messenger()->addError(t("An element type is required to inspect a concept hierarchy."));
      return [];
    }

    //$this->setElementType($elementtype);

    // Valid types
    $validTypes = [
      'annotationstem' => ["Annotation Stem", EntryPoints::ANNOTATION_STEM],
      'attribute' => ["Attribute", EntryPoints::ATTRIBUTE],
      'detectorstem' => ["Detector Stem", EntryPoints::DETECTOR_STEM],
      'entity' => ["Entity", EntryPoints::ENTITY],
      'group' => ["Group", EntryPoints::GROUP],
      'instrument' => ["Instrument", EntryPoints::INSTRUMENT],
      'organization' => ["Organization", EntryPoints::ORGANIZATION],
      'person' => ["Person", EntryPoints::PERSON],
      'platform' => ["Platform", EntryPoints::PLATFORM],
      'processstem' => ["Process Stem", EntryPoints::PROCESS_STEM],
      'questionnaire' => ["Questionnaire", EntryPoints::QUESTIONNAIRE],
      'responseoption' => ["Response Option", EntryPoints::RESPONSE_OPTION],
      'study' => ["Study", EntryPoints::STUDY],
      'unit' => ["Unit", EntryPoints::UNIT],
      'detectorattribute' => ["Detector Attribute", EntryPoints::DETECTOR_ATTRIBUTE],
    ];

    $branches_param = [
      [
        'id' => 'annotationstem',
        'uri' => EntryPoints::ANNOTATION_STEM,
        'label' => 'Annotation Stem'
      ],
      [
        'id' => 'attribute',
        'uri' => EntryPoints::ATTRIBUTE,
        'label' => 'Attribute'
      ],
      [
        'id' => 'detectorstem',
        'uri' => EntryPoints::DETECTOR_STEM,
        'label' => 'Detector Stem',
        'typeNamespace' => EntryPoints::DETECTOR_STEM,
        'uriNamespace' => EntryPoints::DETECTOR_STEM
      ],
      [
        'id' => 'detectorattribute',
        'uri' => EntryPoints::DETECTOR_ATTRIBUTE,
        'label' => 'Detector Attribute',
        'typeNamespace' => EntryPoints::DETECTOR_ATTRIBUTE,
        'uriNamespace' => EntryPoints::DETECTOR_ATTRIBUTE
      ],
      [
        'id' => 'entity',
        'uri' => EntryPoints::ENTITY,
        'label' => 'Entity'
      ],
      [
        'id' => 'group',
        'uri' => EntryPoints::GROUP,
        'label' => 'Group'
      ],
      [
        'id' => 'instrument',
        'uri' => EntryPoints::INSTRUMENT,
        'label' => 'Instrument'
      ],
      [
        'id' => 'platform',
        'uri' => EntryPoints::PLATFORM,
        'label' => 'Platform'
      ],
      [
        'id' => 'processstem',
        'uri' => EntryPoints::PROCESS_STEM,
        'label' => 'Process Stem'
      ],
      [
        'id' => 'questionnaire',
        'uri' => EntryPoints::QUESTIONNAIRE,
        'label' => 'Questionnaire'
      ],
      [
        'id' => 'responseoption',
        'uri' => EntryPoints::RESPONSE_OPTION,
        'label' => 'Response Option'
      ],
      [
        'id' => 'study',
        'uri' => EntryPoints::STUDY,
        'label' => 'Study'
      ],
      [
        'id' => 'unit',
        'uri' => EntryPoints::UNIT,
        'label' => 'Unit'
      ],

    ];

    // Divide string $elementtype into an array
    $elementtypesArray = explode(',', $elementtype);

    // Filter valid types from $validTypes array
    $validElementtypes = array_filter($elementtypesArray, function ($type) use ($validTypes) {
        return array_key_exists($type, $validTypes);
    });

    // Check if any valid type was found
    if (empty($validElementtypes)) {
        \Drupal::messenger()->addError(t("No valid element type has been provided."));
        return [];
    }

    // Prepare branches based on valid types
    $branches_param = array_values(array_filter($branches_param, function ($branch) use ($validElementtypes) {
        return in_array($branch['id'], $validElementtypes);
    }));

    // Set the primary element (optional, based on the first valid type)
    $firstType = reset($validElementtypes);
    if ($firstType && array_key_exists($firstType, $validTypes)) {
        [$elementName, $nodeUri] = $validTypes[$firstType];
    } else {
        \Drupal::messenger()->addError(t("Failed to determine the primary element type."));
        return [];
    }

    // Split $elementtype and remove spaces
    $elementtypesArray = array_map('trim', explode(',', $elementtype));

    // Filter branches based on $elementtypesArray
    $branches_param = array_filter($branches_param, function ($branch) use ($elementtypesArray) {
      return in_array($branch['id'], $elementtypesArray);
    });

    // Reindex and ensure clean array
    $branches_param = array_values($branches_param);

    // If empty, we can replace with a default
    if (empty($branches_param)) {
      $branches_param = [
        [
          'id' => 'instrument',
          'uri' => VSTOI::INSTRUMENT,
          'label' => 'Instruments'
        ],
      ];
    }
    //dpm($elementtype, 'Debug $elementtype');           // See which string is arriving
    //dpm($branches_param, 'Debug $branches_param');     // See the final array of branches

    // Retrieve root node
    $this->setRootNode($api->parseObjectResponse($api->getUri($nodeUri), 'getUri'));
    if ($this->getRootNode() == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve root node " . $nodeUri . "."));
      return [];
    }

    // If output_field_selector is not provided, use the default
    if ($output_field_selector === NULL) {
      $output_field_selector = '#edit-search-keyword--2';
    }

    $form['#attached']['library'][] = 'rep/rep_tree';

    $base_url = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBaseUrl();

    $form['#attached']['drupalSettings']['rep_tree'] = [
      'baseUrl' => $base_url,
      'apiEndpoint' => $base_url . '/rep/getchildren',
      'searchSubClassEndPoint' => $base_url . '/rep/subclasskeyword',
      'searchSuperClassEndPoint' => $base_url . '/rep/getsuperclasses',
      'superclass' => $branches_param[0]["uri"],
      'branches' => $branches_param,
      'outputField' => '[name="' . \Drupal::request()->query->get('field_id') . '"]',
      'elementType' => $elementtype,
    ];

    if ($mode == 'browse')
    {
      $form['title'] = [
          '#type' => 'markup',
          '#markup' => '<h3 class="mt-4 mb-4">'.$elementName.' Graph Hierarchy</h3>',
      ];
    }

    $form['search_wrapper'] = [
      '#type' => 'container',
    ];

    $form['search_wrapper']['search_input'] = [
      '#type' => 'textfield',
      //'#title' => $this->t('Search'),
      '#placeholder' => $this->t('Search'),
      //'#autocomplete_route_name' => 'rep.get_subclasskeyword',
      '#attributes' => [
          'id' => 'search_input',
          'class' => ['mt-2', 'w-75'],
          'style' => 'float:left',
          'autocomplete' => 'off'
      ],
      '#autocomplete' => 'off'
    ];

    $form['search_wrapper']['select_node'] = [
      '#type' => 'inline_template',
      '#attributes' => [
        'id' => 'reset-tree',
        'class' => ['btn', 'btn-primary', 'mt-4'],
        'style' => 'float:right',
      ],
      '#template' => '<button type="button" id="reset-tree" class="btn btn-primary mt-1 mx-3" data-field-id="">'.t('Reset').'</button>'
    ];

    // $form['search_wrapper'] = [
    //   '#type' => 'inline_template',
    //   '#template' => '
    //     <div style="position: relative; max-width: 350px;" class="js-form-wrapper form-wrapper mt-3" id="edit-search-wrapper" data-drupal-selector="edit-search-wrapper">
    //       <div class="js-form-item js-form-type-textfield form-type-textfield js-form-item-search-input form-item-search-input form-no-label">"
    //         <input id="tree-search" class="form-control" placeholder="Search..." style="padding-right: 30px; margin-bottom: 10px;" autocomplete="on" data-drupal-selector="edit-search-input" type="text" name="search_input" value="" size="60" maxlength="128">
    //       </div>
    //       <button id="clear-search" type="button" style="position: absolute; top: 40%; right: 5px; transform: translateY(-50%); background: transparent; border: none; font-size: 16px; color: #888; cursor: pointer; display: none;" data-drupal-selector="edit-clear-button">×</button>
    //     </div>
    //   ',
    // ];

    $form['wait_message'] = [
      '#type' => 'markup',
      '#markup' => '<div id="wait-message" style="text-align: center; font-style: italic; color: grey; margin-top: 10px;" class="mt-3 mb-3 '.($mode == 'modal' ?? 'text-center').'">Wait please...</div>',
    ];

    $form['tree_root'] = [
      '#type' => 'markup',
      '#markup' => '<div id="tree-root" data-initial-uri="' . $this->getRootNode()->uri . '" style="display:none;"></div>',
    ];

    $form['node_comment_display'] = [
      '#type' => 'container',
      '#text' => '',
      '#attributes' => [
          'id' => 'node-comment-display',
          'class' => ['mt-2', 'w-100'],
          'style' => 'display:none;'
          //'style' => 'float:left',
      ],
    ];

    if ($mode == 'modal')
    {
      $form['select_node'] = [
        '#type' => 'inline_template',
        '#attributes' => [
          'id' => 'select-tree-node',
          'class' => ['btn', 'btn-primary', 'mt-3', 'mb-3', 'disabled'],
        ],
        '#template' => '
          <div style="margin-bottom: 10px;">
            <button type="button" id="select-tree-node" class="btn btn-primary btn-sm" data-field-id="' . (\Drupal::request()->query->get('field_id') ?? '').'">'.t('Select Node').'</button>
          </div>'
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No submission logic
  }
}
