<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\SIO;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\rep\EntryPoints;
use Drupal\Core\Url;
use Drupal\rep\Utils;

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

    // Toggles
    $hide_draft = $form_state->getValue('hide_draft') ?? true;
    $hide_deprecated = $form_state->getValue('hide_deprecated') ?? true;
    $show_namespace = $form_state->getValue('show_namespace') ?? true;

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
        'label' => 'Annotation Stem',
        'uriNamespace' => EntryPoints::ANNOTATION_STEM
      ],
      [
        'id' => 'attribute',
        'uri' => EntryPoints::ATTRIBUTE,
        'label' => 'Attribute',~
        'uriNamespace' => EntryPoints::ATTRIBUTE
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
        'label' => 'Instrument',
        'uriNamespace' => Utils::namespaceUri(EntryPoints::INSTRUMENT),
      ],
      [
        'id' => 'platform',
        'uri' => EntryPoints::PLATFORM,
        'label' => 'Platform',
        'uriNamespace' => EntryPoints::PLATFORM
      ],
      [
        'id' => 'processstem',
        'uri' => EntryPoints::PROCESS_STEM,
        'label' => 'Process Stem',
        'uriNamespace' => EntryPoints::PROCESS_STEM
      ],
      [
        'id' => 'questionnaire',
        'uri' => EntryPoints::QUESTIONNAIRE,
        'label' => 'Questionnaire',
        'uriNamespace' => EntryPoints::QUESTIONNAIRE,
      ],
      [
        'id' => 'responseoption',
        'uri' => EntryPoints::RESPONSE_OPTION,
        'label' => 'Response Option',~
        'uriNamespace' => EntryPoints::RESPONSE_OPTION,
      ],
      [
        'id' => 'study',
        'uri' => EntryPoints::STUDY,
        'label' => 'Study',
        'uriNamespace' => EntryPoints::STUDY,
      ],
      [
        'id' => 'unit',
        'uri' => EntryPoints::UNIT,
        'label' => 'Unit',
        'uriNamespace' => EntryPoints::UNIT,
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
          'label' => 'Instruments',
          'uriNamespace' => Utils::namespaceUri(EntryPoints::INSTRUMENT),
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
      'username' => \Drupal::currentUser()->getAccountName(),
      'managerEmail' => \Drupal::currentUser()->getEmail(),
      'apiEndpoint' => $base_url . '/rep/getchildren',
      'searchSubClassEndPoint' => $base_url . '/rep/subclasskeyword',
      'searchSuperClassEndPoint' => $base_url . '/rep/getsuperclasses',
      'superclass' => $branches_param[0]["uri"],
      'branches' => $branches_param,
      'outputField' => '[name="' . \Drupal::request()->query->get('field_id') . '"]',
      'elementType' => $elementtype,
      'typeNameSpace' => $branches_param[0]["uriNamespace"],
      'hideDraft' => $hide_draft,
      'hideDeprecated' => $hide_deprecated,
      'showNameSpace' => $show_namespace,
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
          'class' => ['mt-2', 'w-50'],
          'style' => 'float:left',
          'autocomplete' => 'off'
      ],
      '#autocomplete' => 'off'
    ];

    // $form['search_wrapper']['toggle_draft'] = [
    //   '#type' => 'button',
    //   '#value' => $hide_draft ? $this->t('Show Draft') : $this->t('Hide Draft'),
    //   '#attributes' => [
    //     'id' => 'toggle-draft',
    //     'class' => ['btn', 'btn-secondary', 'w-10', 'mt-2', 'mx-3'],
    //     'type' => 'button', // ensures it does not submit the form
    //   ],
    // ];

    // $form['search_wrapper']['toggle_deprecated'] = [
    //   '#type' => 'button',
    //   '#value' => $hide_draft ? $this->t('Show Deprecated') : $this->t('Hide Deprecated'),
    //   '#attributes' => [
    //     'id' => 'toggle-deprecated',
    //     'class' => ['btn', 'btn-secondary', 'w-10', 'mt-2', 'mx-3'],
    //     'type' => 'button', // ensures it does not submit the form
    //   ],
    // ];

    // $form['search_wrapper']['toggle_draft'] = [
    //   '#type' => 'submit',
    //   '#value' => $hide_draft ? $this->t('Show Draft') : $this->t('Hide Draft'),
    //   '#ajax' => [
    //     'callback' => '::toggleDraftCallback',
    //     'wrapper' => 'tree-wrapper',
    //     'method' => 'replace',
    //   ],
    //   '#attributes' => [
    //     'id' => 'toggle-draft',
    //     'class' => ['btn', 'btn-primary', 'w-8', 'mt-2', 'ms-2', 'btn-success'],
    //   ],
    // ];

    // $form['search_wrapper']['toggle_deprecated'] = [
    //   '#type' => 'submit',
    //   '#value' => $hide_deprecated ? $this->t('Show Deprecated') : $this->t('Hide Deprecated'),
    //   '#ajax' => [
    //     'callback' => '::toggleDeprecatedCallback',
    //     'wrapper' => 'tree-wrapper',
    //     'method' => 'replace',
    //   ],
    //   '#attributes' => [
    //     'id' => 'toggle-deprecated',
    //     'class' => ['btn', 'btn-primary', 'w-12', 'mt-2', 'ms-1', 'btn-success'],
    //   ],
    // ];

    $form['search_wrapper']['select_node'] = [
      '#type' => 'inline_template',
      '#attributes' => [
        'id' => 'reset-tree',
        'class' => ['btn', 'btn-primary', 'mt-2'],
        'style' => 'float:right',
      ],
      '#template' => '<button type="button" id="reset-tree" class="btn btn-primary mt-2 ms-2" data-field-id="">'.t('Reset Tree').'</button>'
    ];

    $form['search_wrapper']['filters'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'mt-2', 'mx-0']
      ]
    ];

    $form['search_wrapper']['filters']['toggle_draft'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide Another Users Draft\'s?&nbsp;&nbsp;&nbsp;'),
      '#default_value' => $hide_draft,
      // AJAX to rebuild the tree when toggled
      '#ajax' => [
        'callback' => '::toggleDraftCallback',
        'wrapper' => 'tree-wrapper',
        'method' => 'replace',
      ],
      '#attributes' => [
        'id' => 'toggle-draft',
        // Add any classes you want
        'class' => ['mb-2', 'me-2'],
      ],
    ];

    $form['search_wrapper']['filters']['toggle_deprecated'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide Another Users Deprecated\'s?&nbsp;&nbsp;&nbsp;'),
      '#default_value' => $hide_deprecated,
      '#ajax' => [
        'callback' => '::toggleDeprecatedCallback',
        'wrapper' => 'tree-wrapper',
        'method' => 'replace',
      ],
      '#attributes' => [
        'id' => 'toggle-deprecated',
        'class' => ['mb-2'],
      ],
    ];

    $form['search_wrapper']['filters']['toggle_shownamespace'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide Name Space\'s'),
      '#default_value' => $show_namespace,
      '#ajax' => [
        'callback' => '::toggleShowNameSpaceCallback',
        'wrapper' => 'tree-wrapper',
        'method' => 'replace',
      ],
      '#attributes' => [
        'id' => 'toggle-shownamespace',
        'class' => ['mb-2'],
      ],
    ];

    $form['wait_message'] = [
      '#type' => 'markup',
      '#markup' => '<div id="wait-message" style="text-align: center; font-style: italic; color: grey; margin-top: 10px;" class="mt-3 mb-3 '.($mode == 'modal' ?? 'text-center').'">Wait please...</div>',
    ];

    $form['tree_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'tree-wrapper'],
    ];

    $form['tree_container']['tree_root'] = [
      '#type' => 'markup',
      '#markup' => '<div id="tree-root" data-initial-uri="' . $this->getRootNode()->uri . '" style="display:none;"></div>',
    ];

    $form['hide_draft'] = [
      '#type' => 'hidden',
      '#value' => $hide_draft
    ];

    if ($mode == 'modal')
    {
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
    }

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

  public function toggleDraftCallback(array &$form, FormStateInterface $form_state) {
    // Read the checkbox value directly
    $new = $form_state->getValue('toggle_draft') ? true : false;
    // Store in form state so we can use it elsewhere if needed
    $form_state->setValue('hide_draft', $new);

    // Update drupalSettings
    $form['#attached']['drupalSettings']['rep_tree']['hideDraft'] = $new;

    // Return only the part of the form that needs re-rendering
    return $form['tree_container'];
  }

  public function toggleDeprecatedCallback(array &$form, FormStateInterface $form_state) {
    $new = $form_state->getValue('toggle_deprecated') ? true : false;
    $form_state->setValue('hide_deprecated', $new);

    $form['#attached']['drupalSettings']['rep_tree']['hideDeprecated'] = $new;

    return $form['tree_container'];
  }

  public function toggleShowNameSpaceCallback(array &$form, FormStateInterface $form_state) {
    $new = $form_state->getValue('toggle_shownamespace') ? false : true;
    $form_state->setValue('show_namespace', $new);

    $form['#attached']['drupalSettings']['rep_tree']['showNameSpace'] = $new;

    return $form['tree_container'];
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No submission logic
  }
}
