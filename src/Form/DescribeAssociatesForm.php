<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Utils;
use Drupal\rep\Form\Associates\AssocDeployment;
use Drupal\rep\Form\Associates\AssocOrganization;
use Drupal\rep\Form\Associates\AssocPlace;
use Drupal\rep\Form\Associates\AssocPlatform;
use Drupal\rep\Form\Associates\AssocPlatforminstance;
use Drupal\rep\Form\Associates\AssocStream;
use Drupal\rep\Form\Associates\AssocStudy;
use Drupal\rep\Form\Associates\AssocStudyObjectCollection;
use Drupal\rep\Entity\GenericObject;
use Drupal\rep\Vocabulary\FOAF;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\OWL;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\rep\Form\VisGraphBaseForm;

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
      \Drupal::messenger()->addError($this->t('URI do elemento não foi fornecida corretamente.'));
      return $form;
    }

    $elementuri = $pathElements[3];
    $uri = base64_decode(rawurldecode($elementuri));
    $api = \Drupal::service('rep.api_connector');
    $finalUri = $api->getUri(Utils::plainUri($uri));

    if (!$finalUri) {
      \Drupal::messenger()->addError($this->t('Elemento não encontrado.'));
      return $form;
    }

    $element = $api->parseObjectResponse($finalUri, 'getUri');
    if (!$element || !isset($element->uri)) {
      \Drupal::messenger()->addError($this->t('O objeto recuperado está vazio ou inválido.'));
      return $form;
    }

    $this->setElement($element);
    $objectProperties = GenericObject::inspectObject($element);
    $baseUri = $element->uri;

    // ✅ INSERE O GRAFO COMO UM PAINEL USANDO VisGraphBaseForm
    $graphForm = new VisGraphBaseForm();
    $graphForm->setVisElement($element);
    $form += \Drupal::formBuilder()->getForm($graphForm);

    // ✅ PROPRIEDADES (com olhinho)
    foreach ($objectProperties['objects'] as $propertyName => $propertyValue) {
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

    // ✅ ARRAYS (listas de valores)
    foreach ($objectProperties['arrays'] as $propertyName => $propertyValue) {
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

    // ✅ PROCESSA ASSOCIAÇÕES por tipo do objeto
    $typeUri = $element->hascoTypeUri ?? $element->typeUri ?? '';

    switch ($typeUri) {
      case VSTOI::DEPLOYMENT:
        AssocDeployment::process($element, $form, $form_state);
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
