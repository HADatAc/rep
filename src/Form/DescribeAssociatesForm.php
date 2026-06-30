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
    $typeUri = $element->hascoTypeUri ?? ($element->typeUri ?? '');
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

      if ($workflowExists) {
        $form['workflow_canvas_block']['workflow_canvas_header']['fullscreen'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Fullscreen'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['workflow-preview-fullscreen-btn'],
            'data-workflow-preview-fullscreen' => '1',
            'aria-pressed' => 'false',
          ],
        ];
      }

      $form['workflow_canvas_block']['workflow_canvas_header']['open_stable_editor'] = [
        '#type' => 'link',
        '#title' => $this->t('Open stable editor'),
        '#url' => Url::fromUserInput('/ctt/editor', [
          'query' => [
            'processUri' => $baseUri,
            'execution' => '1',
          ],
        ]),
        '#attributes' => [
          'class' => ['workflow-preview-open-editor-btn'],
          'title' => $this->t('Use this route if embedded canvas remains on API connection.'),
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
      case VSTOI::PLATFORM_INSTANCE:
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
      $hascoTypeRaw = $api->getHascoType($element->uri);
      if ($hascoTypeRaw) {
        $hascoTypeJSON = $api->parseObjectResponse($hascoTypeRaw, 'hascoTypeRaw');
        $response = json_decode($hascoTypeJSON, true);
        $hascoType = $response['hascoType'] ?? null;
        if ($hascoType === VSTOI::PLATFORM) {
          AssocPlatform::process($element, $form, $form_state);
        }
      }
    }
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {}
  public function submitForm(array &$form, FormStateInterface $form_state) {}

}
