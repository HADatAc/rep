<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Utils;
use Drupal\rep\Form\Associates\AssocDeployment;
use Drupal\rep\Form\Associates\AssocOrganization;
use Drupal\rep\Form\Associates\AssocPlace;
use Drupal\rep\Form\Associates\AssocPlatform;
use Drupal\rep\Form\Associates\AssocPlatformInstance;
use Drupal\rep\Form\Associates\AssocStream;
use Drupal\rep\Form\Associates\AssocStudy;
use Drupal\rep\Form\Associates\AssocStudyObjectCollection;
use Drupal\rep\Form\Associates\AssocProject;
use Drupal\rep\Form\Associates\AssocTypedInstance;
use Drupal\rep\Entity\GenericObject;
use Drupal\rep\Vocabulary\FOAF;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\OWL;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\rep\Form\VisGraphBaseForm;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;

class DescribeAssociatesForm extends FormBase {

  protected $element;

  public function getFormId() {
    return "describe_associates_form";
  }

  public function getElement() {
    return $this->element;
  }

  public function setElement($object) {
    return $this->element = $object;
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'rep/fontawesome';

    
    $request = \Drupal::request();
    $pathInfo = $request->getPathInfo();
    $pathElements = explode('/', $pathInfo);

    if (count($pathElements) < 4) {
      \Drupal::messenger()->addError($this->t('The element URI was not provided correctly.'));
      return $form;
    }

    $elementuri = $pathElements[3];
    $uri = base64_decode(rawurldecode($elementuri));
    $api = \Drupal::service('rep.api_connector');
    $finalUri = $api->getUri(Utils::plainUri($uri));

    if (!$finalUri) {
      \Drupal::messenger()->addError($this->t('Element not found.'));
      return $form;
    }

    $element = $api->parseObjectResponse($finalUri, 'getUri');
    if (!$element || !isset($element->uri)) {
      \Drupal::messenger()->addError($this->t('The recovery object is empty or invalid.'));
      return $form;
    }

    // Determine element type early so we can customize rendering.
    $typeUri = !empty($element->hascoTypeUri) ? $element->hascoTypeUri : ($element->typeUri ?? '');
    $preferredInstrument = \Drupal::config('rep.settings')->get('preferred_instrument') ?? 'Instrument';
    $preferredComponent = \Drupal::config('rep.settings')->get('preferred_component') ?? 'Component';
    $isProject = ($typeUri === SCHEMA::PROJECT);
    $isWorkflow = in_array($typeUri, [VSTOI::PROCESS, VSTOI::WORKFLOW], true);
    $baseUri = $element->uri;

    if ($isWorkflow && \Drupal::moduleHandler()->moduleExists('ctt') && \Drupal::currentUser()->hasPermission('access ctt editor')) {
      $basePath = rtrim(\Drupal::request()->getBasePath() ?: '/', '/');
      $drupalBaseUrl = ($basePath === '' ? '/' : $basePath . '/');
      $currentUser = \Drupal::currentUser();
      $editorPreviewUrl = Url::fromUserInput('/ctt/editor', [
        'query' => [
          'processUri' => $baseUri,
          'execution' => '1',
        ],
      ])->toString();
      $createModeUrl = Url::fromUserInput('/ctt/editor', [
        'query' => [
          'create' => '1',
        ],
      ])->toString();

      $workflowExists = true;
      $workflowProbeError = '';
      if (\Drupal::hasService('ctt.hasco_client')) {
        try {
          $probe = \Drupal::service('ctt.hasco_client')->getByUri((string) $baseUri);
          if (!is_array($probe) || !empty($probe['error'])) {
            $workflowExists = false;
            $workflowProbeError = is_array($probe) ? (string) ($probe['error'] ?? '') : '';
          }
        }
        catch (\Throwable $e) {
          $workflowExists = false;
          $workflowProbeError = $e->getMessage();
        }
      }

      $form['#attached']['library'][] = 'rep/workflow_preview';

      $form['workflow_canvas_block'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['workflow-canvas-block'],
        ],
      ];

      if ($workflowExists) {
        $form['workflow_canvas_block']['#attributes']['data-workflow-preview-block'] = '1';
      }

      $form['workflow_canvas_block']['workflow_canvas_header'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['workflow-canvas-header'],
        ],
      ];

      $form['workflow_canvas_block']['workflow_canvas_header']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Workflow Canvas'),
        '#attributes' => [
          'class' => ['workflow-canvas-title'],
        ],
      ];

      $form['workflow_canvas_block']['workflow_canvas_header']['actions'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['workflow-canvas-actions'],
        ],
      ];

      if ($workflowExists) {
        $collapseTitle = (string) $this->t('Collapse workflow canvas');
        $fullscreenTitle = (string) $this->t('Enter fullscreen');

        $form['workflow_canvas_block']['workflow_canvas_header']['actions']['collapse'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => Markup::create('<i class="fa fa-chevron-up" aria-hidden="true"></i><span class="workflow-preview-sr">' . $this->t('Collapse workflow canvas') . '</span>'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['workflow-preview-collapse-btn', 'workflow-preview-icon-btn'],
            'data-workflow-preview-collapse' => '1',
            'aria-expanded' => 'true',
            'aria-label' => $collapseTitle,
            'title' => $collapseTitle,
          ],
        ];

        $form['workflow_canvas_block']['workflow_canvas_header']['actions']['fullscreen'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => Markup::create('<i class="fa fa-expand" aria-hidden="true"></i><span class="workflow-preview-sr">' . $this->t('Enter fullscreen') . '</span>'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['workflow-preview-fullscreen-btn', 'workflow-preview-icon-btn'],
            'data-workflow-preview-fullscreen' => '1',
            'aria-pressed' => 'false',
            'aria-label' => $fullscreenTitle,
            'title' => $fullscreenTitle,
          ],
        ];
      }

      $openStableLabel = (string) $this->t('Open stable editor');
      $form['workflow_canvas_block']['workflow_canvas_header']['actions']['open_stable_editor'] = [
        '#type' => 'link',
        '#title' => Markup::create('<i class="fa fa-external-link" aria-hidden="true"></i><span class="workflow-preview-sr">' . $this->t('Open stable editor') . '</span>'),
        '#url' => Url::fromUserInput('/ctt/editor', [
          'query' => [
            'processUri' => $baseUri,
            'execution' => '1',
          ],
        ]),
        '#attributes' => [
          'class' => ['workflow-preview-open-editor-btn', 'workflow-preview-icon-btn'],
          'aria-label' => $openStableLabel,
          'title' => $openStableLabel,
        ],
      ];

      if (!$workflowExists) {
        $warningMessage = (string) $this->t('This workflow URI is not available in HASCOAPI right now, so embedded canvas cannot be rendered.');
        if ($workflowProbeError !== '') {
          $warningMessage .= ' ' . (string) $this->t('Backend detail: @detail', ['@detail' => $workflowProbeError]);
        }

        $form['workflow_canvas_block']['workflow_canvas_unavailable'] = [
          '#type' => 'markup',
          '#markup' => '<div class="alert alert-warning workflow-preview-unavailable" role="alert">'
            . '<h4 class="alert-heading" style="margin-top:0;">' . $this->t('Workflow canvas unavailable') . '</h4>'
            . '<p>' . $warningMessage . '</p>'
            . '<p><small>URI: ' . Html::escape((string) $baseUri) . '</small></p>'
            . '<div class="workflow-preview-unavailable-actions">'
            . '<a class="workflow-preview-open-editor-btn" href="' . Html::escape($editorPreviewUrl) . '">' . $this->t('Open stable editor') . '</a> '
            . '<a class="workflow-preview-open-editor-btn workflow-preview-open-editor-btn-secondary" href="' . Html::escape($createModeUrl) . '">' . $this->t('Open editor in create mode') . '</a>'
            . '</div>'
            . '</div>',
        ];
      }
      else {
        $form['#attached']['library'][] = 'ctt/ctt-editor-init';

        $existingCttSettings = $form['#attached']['drupalSettings']['ctt'] ?? [];
        $form['#attached']['drupalSettings']['ctt'] = array_replace_recursive($existingCttSettings, [
          'drupalBaseUrl' => $drupalBaseUrl,
          'apiBaseUrl' => $drupalBaseUrl . 'workflow/api',
          'hascoApiUrl' => $drupalBaseUrl . 'workflow',
          'csrfToken' => \Drupal::csrfToken()->get('rest'),
          'processUri' => $baseUri,
          'currentUser' => [
            'id' => (string) $currentUser->id(),
            'name' => $currentUser->getDisplayName(),
            'email' => (string) $currentUser->getEmail(),
          ],
          'execution' => [
            'mode' => 'execution',
            'daUri' => NULL,
            'dataFileUri' => NULL,
            'studyUri' => NULL,
            'processUri' => $baseUri,
            'readOnlyPreview' => true,
          ],
          'readOnlyPreview' => true,
        ]);

        $form['workflow_canvas_block']['workflow_canvas_body'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['workflow-canvas-body'],
          ],
        ];

        $form['workflow_canvas_block']['workflow_canvas_body']['workflow_canvas'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'ctt-workflow-app',
            'class' => ['ctt-workflow-preview-app'],
            'data-ctt-min-height' => '520',
            'data-workflow-preview-editor-url' => $editorPreviewUrl,
          ],
        ];

        $form['workflow_canvas_block']['workflow_canvas_body']['workflow_canvas']['loading'] = [
          '#type' => 'markup',
          '#markup' => '<div class="ctt-loading-indicator"><div class="ctt-loading-content"><div class="ajax-progress ajax-progress-throbber"><div class="throbber">&nbsp;</div></div><p class="ctt-loading-text">' . $this->t('Loading workflow canvas...') . '</p></div></div>',
        ];
      }
    }

     // ✅ Insert the graph as a panel using VisGraphBaseForm
    $graphForm = new VisGraphBaseForm();
    $graphForm->setVisElement($element);
    $form += \Drupal::formBuilder()->getForm($graphForm);

    $this->setElement($element);
    $objectProperties = GenericObject::inspectObject($element);

    // For Projects, render Associated Elements (cards) right after the graph/title.
    $projectAssociationsRendered = false;
    if ($typeUri === SCHEMA::PROJECT) {
      AssocProject::process($element, $form, $form_state);
      $projectAssociationsRendered = true;
    }

   

    // ✅ Properties (with eye icon)
    foreach ($objectProperties['objects'] as $propertyName => $propertyValue) {
      // Project Funding is rendered as cards by AssocProject.
      if ($isProject && in_array($propertyName, ['funding', 'fundingScheme', 'hasFunding', 'hasFundingScheme'], true)) {
        continue;
      }
      if ($propertyName === 'hasAddress') {
        $this->processPropertyAddress($propertyValue, $form, $form_state);
      } else {
        $prettyName = DescribeForm::prettyProperty($propertyName);
        $label = $propertyValue->label ?? '';
        $nodeId = $propertyValue->uri ?? ($baseUri . '-' . $propertyName);
        $form[$propertyName] = [
          '#type' => 'markup',
          '#markup' => '<b>' . $prettyName . '</b>: '
            . Utils::link($label, $propertyValue->uri)
            . " <span class='graph-toggle' data-node='{$nodeId}' style='cursor:pointer;' title='Show/Hide node'><i class='fa fa-eye'></i></span><br><br>",
        ];
      }
    }

    // ✅ Arrays (lists of values)
    foreach ($objectProperties['arrays'] as $propertyName => $propertyValue) {
      // Project contributors are rendered as cards by AssocProject.
      if ($propertyName === 'contributors' || $propertyName === 'contributorUris') {
        continue;
      }
      // Project Funding is rendered as cards by AssocProject.
      if ($isProject && in_array($propertyName, ['funding', 'fundingScheme', 'fundings', 'fundingSchemes'], true)) {
        continue;
      }
      if (!empty($propertyValue)) {
        $prettyName = DescribeForm::prettyProperty($propertyName);
        $list_items = '<ul>';
        foreach ($propertyValue as $item) {
          $item_str = is_object($item) ? $item->uri : (is_array($item) ? implode(', ', $item) : $item);
          $list_items .= '<li>' . $item_str . '</li>';
        }
        $list_items .= '</ul>';
        $form[$propertyName] = [
          '#type' => 'markup',
          '#markup' => '<b>' . $prettyName . '</b>: ' . $list_items . '<br>',
        ];
      }
    }

    // Render Anatomy in the Associated Elements panel for instrument/component.
    if (in_array($typeUri, [VSTOI::INSTRUMENT, VSTOI::COMPONENT], true)) {
      $this->renderAnatomyAssociations($element, $form, $form_state);
    }

    if ($typeUri === HASCO::INSTRUMENT_INSTANCE) {
      $this->appendAssociatedComponentInstances($form, $api, $element);
    }

    // ✅ Process associations by object type

    switch ($typeUri) {
      case VSTOI::DEPLOYMENT:
        AssocDeployment::process($element, $form, $form_state);
        break;
      case SCHEMA::PROJECT:
        if (!$projectAssociationsRendered) {
          AssocProject::process($element, $form, $form_state);
        }
        break;
      case SCHEMA::ORGANIZATION:
        AssocOrganization::process($element, $form, $form_state);
        break;
      case SCHEMA::PLACE:
        AssocPlace::process($element, $form, $form_state);
        break;
      case VSTOI::PLATFORM:
        AssocPlatform::process($element, $form, $form_state);
        break;
      case VSTOI::INSTRUMENT:
        AssocTypedInstance::process(
          $element,
          $form,
          $form_state,
          'instrumentinstance',
          'typed_instrument_instances',
          'Has ' . $preferredInstrument . ' instances'
        );
        break;
      case VSTOI::COMPONENT:
        AssocTypedInstance::process(
          $element,
          $form,
          $form_state,
          'componentinstance',
          'typed_component_instances',
          'Has ' . $preferredComponent . ' instances'
        );
        break;
      case HASCO::PLATFORM_INSTANCE:
        AssocPlatformInstance::process($element, $form, $form_state);
        break;
      case HASCO::STREAM:
        AssocStream::process($element, $form, $form_state);
        break;
      case HASCO::STUDY:
        AssocStudy::process($element, $form, $form_state);
        break;
      case HASCO::STUDY_OBJECT_COLLECTION:
        AssocStudyObjectCollection::process($element, $form, $form_state);
        break;
      case OWL::CLAZZ:
        $this->processClass($form, $form_state);
        break;
    }

    // Fallback for subclasses (for example, Laboratory) whose type URI is not the
    // canonical VSTOI::PLATFORM but resolves to Platform via hascoType.
    if ($typeUri !== OWL::CLAZZ && !isset($form['pltinst'])) {
      $resolvedHascoType = $this->resolveHascoTypeUri($api, $element);
      if ($resolvedHascoType === VSTOI::PLATFORM) {
        AssocPlatform::process($element, $form, $form_state);
      }
    }

    return $form;
  }

  public function processPropertyAddress($addressObject, array &$form, FormStateInterface $form_state) {
    $addressProperties = GenericObject::inspectObject($addressObject);
    $form['labelAddress'] = [
      '#type' => 'markup',
      '#markup' => $this->t("<b>Postal Address</b>:<br>"),
    ];
    $form['fullAddress'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<ul><b>'
        . $addressProperties['literals']['hasStreetAddress'] . '<br />'
        . $addressProperties['literals']['hasPostalCode'] . ' '
        . Utils::link($addressProperties['objects']['hasAddressLocality']->label, $addressProperties['objects']['hasAddressLocality']->uri) . ', '
        . Utils::link($addressProperties['objects']['hasAddressRegion']->label, $addressProperties['objects']['hasAddressRegion']->uri) . ' - '
        . Utils::link($addressProperties['objects']['hasAddressCountry']->label, $addressProperties['objects']['hasAddressCountry']->uri)
        .'</b></ul><br />'
      ),
    ];
  }

  public function processClass(array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $element = $this->getElement();
    if ($element && $element->uri) {
      $hascoType = $this->resolveHascoTypeUri($api, $element);
      if ($hascoType === VSTOI::PLATFORM) {
        AssocPlatform::process($element, $form, $form_state);
      }
    }
  }

  private function resolveHascoTypeUri($api, $element) {
    if (!is_object($element) || empty($element->uri)) {
      return NULL;
    }

    try {
      $hascoTypeRaw = $api->getHascoType($element->uri);
      if (!$hascoTypeRaw) {
        return NULL;
      }

      $parsed = $api->parseObjectResponse($hascoTypeRaw, 'getHascoType');

      if (is_object($parsed) && isset($parsed->hascoType)) {
        return (string) $parsed->hascoType;
      }

      if (is_array($parsed) && isset($parsed['hascoType'])) {
        return (string) $parsed['hascoType'];
      }

      if (is_string($parsed) && $parsed !== '') {
        $decoded = json_decode($parsed, TRUE);
        if (is_array($decoded) && isset($decoded['hascoType'])) {
          return (string) $decoded['hascoType'];
        }
      }
    }
    catch (\Throwable $e) {
      return NULL;
    }

    return NULL;
  }

  private function renderAnatomyAssociations($element, array &$form, FormStateInterface $form_state) {
    $anatomyUris = $this->extractAnatomyUris($element);
    if (count($anatomyUris) === 0) {
      return;
    }

    $api = \Drupal::service('rep.api_connector');
    $items = '';

    foreach ($anatomyUris as $uri) {
      $label = $this->resolveAnatomyLabel($api, $uri);
      $items .= '<li>'
        . Html::escape($label)
        . ' (' . Utils::link($uri, $uri) . ')'
        . '</li>';
    }

    $form['associated_anatomy'] = [
      '#type' => 'markup',
      '#markup' => '<b>Anatomy</b>:<ul>' . $items . '</ul><br>',
    ];
  }

  private function extractAnatomyUris($element): array {
    $candidates = [];

    if (is_object($element) && isset($element->hasAnatomyUris) && is_array($element->hasAnatomyUris)) {
      foreach ($element->hasAnatomyUris as $value) {
        if (is_string($value)) {
          $candidates[] = $value;
        }
      }
    }

    if (is_object($element) && isset($element->hasAnatomy) && is_string($element->hasAnatomy)) {
      $parts = preg_split('/[;,\n\r]+/', $element->hasAnatomy);
      if (is_array($parts)) {
        foreach ($parts as $part) {
          if (is_string($part)) {
            $candidates[] = $part;
          }
        }
      }
    }

    $uris = [];
    $seen = [];
    foreach ($candidates as $candidate) {
      $uri = trim((string) $candidate);
      if ($uri === '' || filter_var($uri, FILTER_VALIDATE_URL) === false || isset($seen[$uri])) {
        continue;
      }
      $seen[$uri] = true;
      $uris[] = $uri;
    }

    return $uris;
  }

  private function resolveAnatomyLabel($api, string $uri): string {
    try {
      $raw = $api->getUri(Utils::plainUri($uri));
      if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw);
        if (is_object($decoded) && !empty($decoded->isSuccessful) && isset($decoded->body) && is_object($decoded->body)) {
          if (!empty($decoded->body->label) && is_string($decoded->body->label)) {
            return $decoded->body->label;
          }
          if (!empty($decoded->body->title) && is_string($decoded->body->title)) {
            return $decoded->body->title;
          }
        }
      }
    }
    catch (\Throwable $e) {
      // Keep URI itself as fallback label.
    }

    // Fallback to a readable token when the term is not available in KG.
    if (preg_match('#/([A-Za-z]+)_([0-9]+)$#', $uri, $matches)) {
      return strtoupper($matches[1]) . '_' . $matches[2];
    }

    return $uri;
  }

  /**
   * Render associated component instances for an instrument instance page.
   */
  private function appendAssociatedComponentInstances(array &$form, $api, $instrumentInstance): void {
    $rows = [];
    $items = $this->loadAssociatedComponentInstances($api, $instrumentInstance);

    if (count($items) === 0) {
      return;
    }

    foreach ($items as $item) {
      $label = (string) ($item->label ?? Utils::namespaceUri((string) ($item->uri ?? '')));
      $uri = (string) ($item->uri ?? '');
      $typeUri = (string) ($item->hascoTypeUri ?? ($item->typeUri ?? HASCO::COMPONENT_INSTANCE));
      $status = isset($item->hasStatus) ? Utils::plainStatus((string) $item->hasStatus) : '';

      $rows[] = [
        ['data' => Markup::create(Utils::link($label, $uri))],
        ['data' => Markup::create(Utils::link($uri, $uri))],
        ['data' => Markup::create(Utils::link($typeUri, $typeUri))],
        Html::escape($status),
      ];
    }

    $form['associated_component_instances_header'] = [
      '#type' => 'item',
      '#title' => '<h3>Associated Component Instances</h3>',
    ];

    $form['associated_component_instances_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Label'),
        $this->t('URI'),
        $this->t('Type URI'),
        $this->t('Status'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No associated component instances found.'),
    ];

    $form['associated_component_instances_space'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<br>'),
    ];
  }

  /**
   * Load associated component instances using INI token embedded in CPI URIs.
   */
  private function loadAssociatedComponentInstances($api, $instrumentInstance): array {
    if (!is_object($instrumentInstance) || empty($instrumentInstance->uri)) {
      return [];
    }

    $instanceToken = $this->extractLocalIdFromUri((string) $instrumentInstance->uri);
    if ($instanceToken === '') {
      return [];
    }

    $candidateUris = [];
    $pageSize = 500;
    $maxPages = 6;

    for ($page = 0; $page < $maxPages; $page++) {
      $offset = $page * $pageSize;
      $raw = $api->listByKeywordType('componentinstance', $pageSize, $offset, 'all', '_', '_', '_', '_');
      $objects = $api->parseObjectResponse($raw, 'listByKeywordType');

      if ($objects == NULL) {
        break;
      }

      if (!is_array($objects)) {
        $objects = [$objects];
      }

      if (count($objects) === 0) {
        break;
      }

      foreach ($objects as $object) {
        if (!is_object($object) || empty($object->uri)) {
          continue;
        }

        $uri = (string) $object->uri;
        if (strpos($uri, $instanceToken) !== FALSE) {
          $candidateUris[] = $uri;
        }
      }

      if (count($objects) < $pageSize) {
        break;
      }
    }

    $componentUris = array_values(array_unique($candidateUris));

    $instances = [];
    foreach ($componentUris as $componentUri) {
      $componentObj = $api->parseObjectResponse($api->getUri($componentUri), 'getUri');
      if (!is_object($componentObj)) {
        continue;
      }

      $componentType = (string) ($componentObj->hascoTypeUri ?? ($componentObj->typeUri ?? ''));
      if ($componentType !== HASCO::COMPONENT_INSTANCE) {
        continue;
      }

      $instances[] = $componentObj;
    }

    return $instances;
  }

  /**
   * Extract local identifier token from a full URI.
   */
  private function extractLocalIdFromUri(string $uri): string {
    $trimmed = trim($uri);
    if ($trimmed === '') {
      return '';
    }

    $parts = explode('/', $trimmed);
    return trim((string) end($parts));
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {}
  public function submitForm(array &$form, FormStateInterface $form_state) {}

}
