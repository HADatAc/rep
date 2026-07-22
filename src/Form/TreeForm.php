<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\SIO;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\rep\EntryPoints;
use Drupal\Core\Url;
use Drupal\rep\Utils;
use Drupal\rep\Entity\Tables;

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
  public function buildForm(array $form, FormStateInterface $form_state, $mode = NULL, $elementtype = NULL, array $branches_param = NULL, $output_field_selector = NULL, $silent = false, $prefix = false) {

    $form['#cache']['max-age'] = 0;

    // Prefered name
    $preferred_instrument = \Drupal::config('rep.settings')->get('preferred_instrument') ?? 'instrument';
    $preferred_component = \Drupal::config('rep.settings')->get('preferred_component') ?? 'component';
    $preferred_process = \Drupal::config('rep.settings')->get('preferred_process') ?? 'workflow';
    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';
    $preferred_platform = \Drupal::config('rep.settings')->get('preferred_platform') ?? 'platform';

    // Toggles
    $hide_draft = $form_state->getValue('hide_draft') ?? true;
    $hide_deprecated = $form_state->getValue('hide_deprecated') ?? true;
    $show_namespace = $form_state->getValue('show_namespace') ?? true;
    $show_prefix = $form_state->getValue('show_prefix') ?? true;

    $show_label = $form_state->getValue('show_label') ?? 'label';

    $silent = filter_var($silent, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($silent === null && is_string($silent)) {
      $silent = strtolower($silent) === 'false' ? false : true;
    }
    $prefix = filter_var($prefix, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

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

    // dpm($elementtype, 'Debug $elementtype received');

    //$this->setElementType($elementtype);

    // Valid types
    $validTypes = [
      'annotationstem' => ["Annotation Stem", EntryPoints::CLASS_EP_ANNOTATION_STEM],
      'attribute' => ["Attribute", EntryPoints::CLASS_EP_ATTRIBUTE],
      'componentstem' => [ucfirst($preferred_component)." Stem", EntryPoints::CLASS_EP_COMPONENT_STEM],
      'entity' => ["Entity", EntryPoints::CLASS_EP_ENTITY],
      'group' => ["Group", EntryPoints::CLASS_EP_GROUP],
      'instrument' => [ucfirst($preferred_instrument), EntryPoints::CLASS_EP_INSTRUMENT],
      'organization' => ["Organization", EntryPoints::CLASS_EP_ORGANIZATION],
      'person' => ["Person", EntryPoints::CLASS_EP_PERSON],
      'place' => ["Place", EntryPoints::CLASS_EP_PLACE],
      'platform' => [ucfirst($preferred_platform), EntryPoints::CLASS_EP_PLATFORM],
      'processstem' => [ucfirst($preferred_process)." Stem", VSTOI::PROCESS_STEM],
      'workflowstem' => [ucfirst($preferred_process)." Stem", EntryPoints::CLASS_EP_PMSR],
      // 'questionnaire' => ["Questionnaire", EntryPoints::EP_QUESTIONNAIRE],
      'responseoption' => ["Response Option", EntryPoints::CLASS_EP_RESPONSE_OPTION],
      'study' => [ucfirst($preferred_study), EntryPoints::CLASS_EP_STUDY],
      'task' => ["Task Type", EntryPoints::CLASS_EP_TASK],
      'tasktemporaldependency' => ["Task Temporal Dependency", EntryPoints::CLASS_EP_TASK_TEMPORAL_DEPENDENCY],
      'unit' => ["Unit", EntryPoints::INSTANCE_EP_UNIT],
      'componentattribute' => [ucfirst($preferred_component)." Attribute", EntryPoints::CLASS_EP_COMPONENT_ATTRIBUTE],
      'component' => [ucfirst($preferred_component), EntryPoints::CLASS_EP_COMPONENT],
      'person' => ["Person", EntryPoints::CLASS_EP_PERSON],
      'place' => ["Place", EntryPoints::CLASS_EP_PLACE],
      'organization' => ["Organization", EntryPoints::CLASS_EP_ORGANIZATION],
      'ncit' => ["Procedure Type (NCIT)", EntryPoints::CLASS_EP_NCIT],
      'uberon' => ["Anatomical Category (UBERON)", EntryPoints::CLASS_EP_UBERON],
      'anatomicalpart' => ["Anatomical Part", EntryPoints::CLASS_EP_ANATOMICAL_PART],
      'process' => ["Process", EntryPoints::CLASS_EP_PROCESS],
      'medicaldevice' => ["Medical Device", EntryPoints::CLASS_EP_MEDICAL_DEVICE],
    ];

    $branches_param = [
      [
        'id' => 'annotationstem',
        'uri' => EntryPoints::CLASS_EP_ANNOTATION_STEM,
        'label' => 'Annotation Stem',
        'uriNamespace' => EntryPoints::CLASS_EP_ANNOTATION_STEM
      ],
      [
        'id' => 'attribute',
        'uri' => EntryPoints::CLASS_EP_ATTRIBUTE,
        'label' => 'Attribute',
        'uriNamespace' => EntryPoints::CLASS_EP_ATTRIBUTE
      ],
      [
        'id' => 'componentstem',
        'uri' => EntryPoints::CLASS_EP_COMPONENT_STEM,
        'label' => ucfirst($preferred_component).' Stem',
        'typeNamespace' => EntryPoints::CLASS_EP_COMPONENT_STEM,
        'uriNamespace' => EntryPoints::CLASS_EP_COMPONENT_STEM
      ],
      [
        'id' => 'component',
        'uri' => EntryPoints::CLASS_EP_COMPONENT,
        'label' => ucfirst($preferred_component),
        'typeNamespace' => EntryPoints::CLASS_EP_COMPONENT,
        'uriNamespace' => EntryPoints::CLASS_EP_COMPONENT
      ],
      [
        'id' => 'componentattribute',
        'uri' => EntryPoints::CLASS_EP_COMPONENT_ATTRIBUTE,
        'label' => ucfirst($preferred_component).' Attribute',
        'uriNamespace' => EntryPoints::CLASS_EP_COMPONENT_ATTRIBUTE
      ],
      [
        'id' => 'entity',
        'uri' => EntryPoints::CLASS_EP_ENTITY,
        'label' => 'Entity',
        'uriNamespace' => Utils::namespaceUri(EntryPoints::CLASS_EP_ENTITY),
      ],
      [
        'id' => 'group',
        'uri' => EntryPoints::CLASS_EP_GROUP,
        'label' => 'Group'
      ],
      [
        'id' => 'instrument',
        'uri' => EntryPoints::CLASS_EP_INSTRUMENT,
        'label' => ucfirst($preferred_instrument),
        'uriNamespace' => Utils::namespaceUri(EntryPoints::CLASS_EP_INSTRUMENT),
      ],
      [
        'id' => 'organization',
        'uri' => EntryPoints::CLASS_EP_ORGANIZATION,
        'label' => 'Organization',
        'uriNamespace' => EntryPoints::CLASS_EP_ORGANIZATION,
      ],
      [
        'id' => 'place',
        'uri' => EntryPoints::CLASS_EP_PLACE,
        'label' => 'Place',
        'uriNamespace' => EntryPoints::CLASS_EP_PLACE,
      ],
      [
        'id' => 'platform',
        'uri' => EntryPoints::CLASS_EP_PLATFORM,
        'label' => ucfirst($preferred_platform),
        'uriNamespace' => EntryPoints::CLASS_EP_PLATFORM
      ],
      [
        'id' => 'processstem',
        'uri' => VSTOI::PROCESS_STEM,
        'label' => ucfirst($preferred_process).' Stem',
        'uriNamespace' => VSTOI::PROCESS_STEM
      ],
      [
        'id' => 'workflowstem',
        'uri' => EntryPoints::CLASS_EP_PMSR,
        'label' => ucfirst($preferred_process).' Stem',
        'uriNamespace' => EntryPoints::CLASS_EP_PMSR,
      ],
      // [
      //   'id' => 'questionnaire',
      //   'uri' => EntryPoints::CLASS_EP_QUESTIONNAIRE,
      //   'label' => 'Questionnaire',
      //   'uriNamespace' => EntryPoints::CLASS_EP_QUESTIONNAIRE,
      // ],
      [
        'id' => 'responseoption',
        'uri' => EntryPoints::CLASS_EP_RESPONSE_OPTION,
        'label' => 'Response Option',
        'uriNamespace' => EntryPoints::CLASS_EP_RESPONSE_OPTION,
      ],
      [
        'id' => 'study',
        'uri' => EntryPoints::CLASS_EP_STUDY,
        'label' => ucfirst($preferred_study),
        'uriNamespace' => EntryPoints::CLASS_EP_STUDY,
      ],
      [
        'id' => 'task',
        'uri' => EntryPoints::CLASS_EP_TASK,
        'label' => 'Task Type',
        'uriNamespace' => EntryPoints::CLASS_EP_TASK,
      ],
      [
        'id' => 'tasktemporaldependency',
        'uri' => EntryPoints::CLASS_EP_TASK_TEMPORAL_DEPENDENCY,
        'label' => 'Task Temporal Dependency',
        'uriNamespace' => EntryPoints::CLASS_EP_TASK_TEMPORAL_DEPENDENCY,
      ],
      [
        'id' => 'unit',
        'uri' => EntryPoints::INSTANCE_EP_UNIT,
        'label' => 'Unit',
        'uriNamespace' => EntryPoints::INSTANCE_EP_UNIT,
      ],
      [
        'id' => 'ncit',
        'uri' => EntryPoints::CLASS_EP_NCIT,
        'label' => 'Procedure Type (NCIT)',
        'uriNamespace' => EntryPoints::CLASS_EP_NCIT,
      ],
      [
        'id' => 'uberon',
        'uri' => EntryPoints::CLASS_EP_UBERON,
        'label' => 'Anatomical Category (UBERON)',
        'uriNamespace' => EntryPoints::CLASS_EP_UBERON,
      ],
      [
        'id' => 'anatomicalpart',
        'uri' => EntryPoints::CLASS_EP_ANATOMICAL_PART,
        'label' => 'Anatomical Part',
        'uriNamespace' => EntryPoints::CLASS_EP_ANATOMICAL_PART,
      ],
      [
        'id' => 'process',
        'uri' => EntryPoints::CLASS_EP_PROCESS,
        'label' => 'Process',
        'uriNamespace' => EntryPoints::CLASS_EP_PROCESS,
      ],
      [
        'id' => 'medicaldevice',
        'uri' => EntryPoints::CLASS_EP_MEDICAL_DEVICE,
        'label' => 'Medical Device',
        'uriNamespace' => EntryPoints::CLASS_EP_MEDICAL_DEVICE,
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
          'uri' =>EntryPoints::CLASS_EP_INSTRUMENT,
          'label' => ucfirst($preferred_instrument),
          'uriNamespace' => Utils::namespaceUri(EntryPoints::CLASS_EP_INSTRUMENT),
        ],
      ];
    }
    //dpm($elementtype, 'Debug $elementtype');           // See which string is arriving
    //dpm($branches_param, 'Debug $branches_param');     // See the final array of branches

    // 1) Leia o valor que veio pela URL (se existir)
    $search_value = \Drupal::request()->query->get('search_value');
    // Se não vir nada, pode ficar como string vazia:
    if ($search_value === NULL) {
      $search_value = '';
    }

    // Retrieve root node
    // dpm($api->getUri($nodeUri), 'Debug $nodeUri'); // See the URI being used
    // dpm($api->parseObjectResponse($api->getUri($nodeUri), 'getUri'), 'Debug $api->parseObjectResponse'); // See the response from the API
    $this->setRootNode($api->parseObjectResponse($api->getUri($nodeUri), 'getUri'));
    if ($this->getRootNode() == NULL && $firstType === 'workflowstem') {
      $nodeUri = EntryPoints::CLASS_EP_PMSR;
      $this->setRootNode($api->parseObjectResponse($api->getUri($nodeUri), 'getUri'));
      if (!empty($branches_param)) {
        $branches_param[0]['uri'] = EntryPoints::CLASS_EP_PMSR;
        $branches_param[0]['uriNamespace'] = EntryPoints::CLASS_EP_PMSR;
      }
    }
    if ($this->getRootNode() == NULL && $firstType === 'processstem') {
      $nodeUri = VSTOI::PROCESS_STEM;
      $this->setRootNode($api->parseObjectResponse($api->getUri($nodeUri), 'getUri'));
      if (!empty($branches_param)) {
        $branches_param[0]['uri'] = VSTOI::PROCESS_STEM;
        $branches_param[0]['uriNamespace'] = VSTOI::PROCESS_STEM;
      }
    }
    if ($this->getRootNode() == NULL) {
      $this->setRootNode((object) ['uri' => $nodeUri]);
      \Drupal::messenger()->addWarning($this->t('Could not resolve root node metadata for @uri. Loading tree from this URI directly.', ['@uri' => $nodeUri]));
    }

    // If output_field_selector is not provided, use the default
    if ($output_field_selector === NULL) {
      $output_field_selector = '#edit-search-keyword--2';
    }

    $form['#attached']['library'][] = 'rep/rep_tree';

    $field_id = \Drupal::request()->query->get('field_id') ?? '';

    $tables = new Tables;

    // Load default expanded nodes configuration
    $config = \Drupal::config('rep.settings');
    $default_expanded_nodes = [];
    
    // Get element-specific expanded nodes configuration
    if ($elementtype) {
      $config_key = 'tree_default_expanded_' . strtolower($elementtype);
      $default_expanded_nodes = $config->get($config_key) ?? [];
      
      // Hardcoded fallback for instrument hierarchy if config is empty
      if (empty($default_expanded_nodes) && strtolower($elementtype) === 'instrument') {
        $default_expanded_nodes = [
          'http://hadatac.org/ont/hasco/InstrumentEntryPoint',
          'http://hadatac.org/ont/vstoi#Instrument',
          'http://hadatac.org/ont/vstoi#Model',
          'http://hadatac.org/ont/vstoi#PhysicalInstrument',
        ];
      }
    }

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
      'outputField' => '[name="' . $field_id . '"]',
      'fieldId' => $field_id,
      'elementType' => $elementtype,
      'typeNameSpace' => $branches_param[0]["uriNamespace"],
      'hideDraft' => $hide_draft,
      'hideDeprecated' => $hide_deprecated,
      'showLabel' => $show_label,
      'nameSpacesList' => $tables->getNamespaces(),
      'searchValue' => $search_value,
      'prefix' => $prefix,
      'defaultExpandedNodes' => $default_expanded_nodes,
    ];

    if ($mode == 'browse')
    {
      $form['title'] = [
          '#type' => 'markup',
          '#markup' => '<h3 class="mb-4">Available <font style="color:DarkGreen;">'.$elementName.'</font> Graph Hierarchy</h3>',
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
        'class' => ['d-flex', 'mt-2', 'mx-0'],
        'id' => 'edit-filters'
      ]
    ];

    $form['search_wrapper']['filters']['toggle_draft'] = [
      '#type' => 'checkbox',
      '#prefix' => '<div class="filter-label">Content to be shown:&nbsp;&nbsp;</div>',
      '#title' => $this->t('Hide Other User\'s Draft?&nbsp;&nbsp;&nbsp;'),
      '#default_value' => $hide_draft,
      // AJAX to rebuild the tree when toggled
      '#ajax' => [
        'callback' => '::toggleDraftCallback',
        'wrapper' => 'tree-wrapper',
        'method' => 'replace',
      ],
      '#attributes' => [
        'id' => 'toggle-draft',
        'class' => ['mb-2', 'me-2'],
        'title' => $this->t('Click to show/hide Another User\'s Draft elements'),
      ],
    ];

    $form['search_wrapper']['filters']['toggle_deprecated'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide Other User\'s Deprecated?&nbsp;&nbsp;&nbsp;'),
      '#default_value' => $hide_deprecated,
      '#ajax' => [
        'callback' => '::toggleDeprecatedCallback',
        'wrapper' => 'tree-wrapper',
        'method' => 'replace',
      ],
      '#attributes' => [
        'id' => 'toggle-deprecated',
        'class' => ['mb-2'],
        'title' => $this->t('Click to show/hide Another User\'s Deprecated elements'),
      ],
    ];

    $form['search_wrapper']['labels'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'mt-2', 'mx-0'],
        'id' => 'edit-labels'
      ]
    ];

    $form['search_wrapper']['labels']['label_mode'] = [
      '#type' => 'radios',
      '#prefix' => '<div>Rendering mode:&nbsp;',
      '#suffix' => '</div>',
      '#options' => [
        'label' => $this->t('Just Label'),
        'labelprefix' => $this->t('Prefix:Label'),
        'uri' => $this->t('URI'),
        'uriprefix' => $this->t('Prefix:URI'),
      ],
      '#default_value' => $show_label ?? 'label',
      '#ajax' => [
        'callback' => '::toggleLabelModeCallback',
        'wrapper' => 'tree-wrapper',
        'method' => 'replace',
        'event' => 'change',
      ],
      '#attributes' => [
        'id' => 'toggle-label-mode',
        'class' => ['radios-inline'],
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

    if ($mode === 'modal') {
      // Determine who opened this modal.
      $caller = \Drupal::request()->query->get('caller');

      // Base button classes.
      $button_classes = ['btn', 'btn-primary', 'mt-3', 'mb-3'];

      // Only add the automatic data-dialog-close if NOT called from the AddTaskForm.
      $auto_close = ($caller !== 'add_task_form');

      // dpm($silent, 'Debug $silent'); // Check if silent mode is set
      // Mostra o botão quando NÃO está em modo silencioso
      if ($silent === false) {
        $form['select_node'] = [
          '#type'     => 'inline_template',
          '#template' => '
            <div style="margin-bottom: 10px;">
              <button type="button"
                      id="select-tree-node"
                      class="{{ classes|join(" ") }}"
                      data-field-id="{{ field_id }}"
                      disabled="disabled"
                      {% if auto_close %}data-dialog-close="true"{% endif %}>
                {{ label }}
              </button>
            </div>',
          '#context'  => [
            'classes'    => $button_classes,
            'field_id'   => \Drupal::request()->query->get('field_id'),
            'auto_close' => $auto_close,
            'label'      => $this->t('Select Node'),
          ],
        ];
      }
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

  public function toggleShowPrefixCallback(array &$form, FormStateInterface $form_state) {
    $new = $form_state->getValue('toggle_showprefix') ? false : true;
    $form_state->setValue('show_prefix', $new);

    $form['#attached']['drupalSettings']['rep_tree']['showPrefix'] = $new;

    return $form['tree_container'];
  }

  public function toggleLabelModeCallback(array &$form, FormStateInterface $form_state) {
    // The name of the radio group is 'label_mode' in the example above
    $value = $form_state->getValue('label_mode');

    $form['#attached']['drupalSettings']['rep_tree']['showLabel'] = $value;

    return $form['tree_container'];
  }


  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No submission logic
  }
}
