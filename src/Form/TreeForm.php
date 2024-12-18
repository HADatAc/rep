<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\SIO;
use Drupal\rep\Vocabulary\VSTOI;
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

    // Validação básica dos parâmetros
    if (empty($mode) || empty($elementtype)) {
      \Drupal::messenger()->addError($this->t('Invalid parameters provided.'));
      return [];
    }

    // Configurações adicionais do formulário
    $form['#attached']['library'][] = 'rep/rep_modal';

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

    // Tipos válidos padrão
    $validTypes = [
      'attribute' => ["Attribute", SIO::ATTRIBUTE],
      'entity' => ["Entity", SIO::ENTITY],
      'unit' => ["Unit", SIO::UNIT],
      'platform' => ["Platform", VSTOI::PLATFORM],
      'instrument' => ["Instrument", VSTOI::INSTRUMENT],
      'detector' => ["Detector", VSTOI::DETECTOR],
      'detectorstem' => ["Detector Stem", VSTOI::DETECTOR_STEM]
    ];

    // Caso o $branches_param não seja fornecido, usamos um padrão
    if ($branches_param === NULL) {
      // Se não tiver sido passado, geramos um array default

    }
    $branches_param = [
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
        'id' => 'latitude',
        'uri' => SIO::LATITUDE,
        'label' => 'Latitude'
      ],
      [
        'id' => 'longitude',
        'uri' => SIO::LONGITUDE,
        'label' => 'Longitude'
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
    ];

    if (array_key_exists($this->getElementType(), $validTypes)) {
      [$elementName, $nodeUri] = $validTypes[$this->getElementType()];
    } else {
      \Drupal::messenger()->addError(t("No valid element type has been provided."));
      return [];
    }

    if (!empty($branches_param) && $elementtype !== NULL) {
      // Filtra apenas o nó correspondente ao $elementtype
      $branches_param = array_values(array_filter($branches_param, function ($branch) use ($elementtype) {
        return $branch['id'] === $elementtype;
      }));
    }

    // Se nenhum filtro corresponder ou $branches_param estiver vazio, define um valor padrão
    if (empty($branches_param)) {
      $branches_param = [
        [
          'id' => 'instrument',
          'uri' => VSTOI::INSTRUMENT,
          'label' => 'Instruments'
        ],
      ];
    }

    // Recupera nó raiz
    $this->setRootNode($api->parseObjectResponse($api->getUri($nodeUri), 'getUri'));
    if ($this->getRootNode() == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve root node " . $nodeUri . "."));
      return [];
    }

    // Caso não seja fornecido o output_field_selector, usa o padrão
    if ($output_field_selector === NULL) {
      $output_field_selector = '#edit-search-keyword--2';
    }

    $form['#attached']['library'][] = 'rep/rep_tree';

    $base_url = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBaseUrl();

    $form['#attached']['drupalSettings']['rep_tree'] = [
      'apiEndpoint' => $base_url . '/rep/getchildren', // Endpoint da API
      'branches' => $branches_param,
      'outputField' => '[name="' . \Drupal::request()->query->get('field_id') . '"]', // Usar o name como seletor
    ];

    $form['title'] = [
        '#type' => 'markup',
        '#markup' => '<h3 class="mt-4 mb-4">Knowledge Graph Hierarchy</h3>',
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

    $form['controls'] = [
      '#type' => 'inline_template',
      '#template' => '
        <div style="margin-bottom: 10px;">
          <button type="button" id="expand-all" class="btn btn-primary btn-sm">Expand All</button>
          <button type="button" id="collapse-all" class="btn btn-secondary btn-sm">Collapse All</button>
        </div>',
    ];

    $form['wait_message'] = [
      '#type' => 'markup',
      '#markup' => '<div id="wait-message" style="text-align: center; font-style: italic; color: grey; margin-top: 10px;">Wait please...</div>',
    ];

    $form['tree_root'] = [
      '#type' => 'markup',
      '#markup' => '<div id="tree-root" data-initial-uri="' . $this->getRootNode()->uri . '" style="display:none;"></div>',
    ];

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

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Sem lógica de submissão
  }
}
