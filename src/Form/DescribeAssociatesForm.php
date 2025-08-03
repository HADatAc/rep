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
class DescribeAssociatesForm extends FormBase {
  protected $element;
  public function getElement() {
    return $this->element;
  }
  public function setElement($object) {
    return $this->element = $object;
  }
  public function getFormId() {
    return "describe_associates_form";
  }
  public function buildForm(array $form, FormStateInterface $form_state) {

  //Code from rep.libraries.yml
  $form['#attached']['library'][] = 'rep/describe_associates';

  // Get the current URL path and decode the URI
  $request = \Drupal::request();
  $pathInfo = $request->getPathInfo();
  $pathElements = explode('/', $pathInfo);
  if (sizeof($pathElements) < 4) {
    \Drupal::messenger()->addError($this->t('URI do elemento não foi fornecida corretamente.'));
    return $form;
  }

  // Decode URI and fetch the element from the API
  $elementuri = $pathElements[3];
  $uri = base64_decode(rawurldecode($elementuri));
  $api = \Drupal::service('rep.api_connector');
  $finalUri = $api->getUri(Utils::plainUri($uri));
  if (!$finalUri) {
    \Drupal::messenger()->addError($this->t('Elemento não encontrado.'));
    return $form;
  }

  // Parse the object returned from the API
  $element = $api->parseObjectResponse($finalUri, 'getUri');
  if (!$element || !isset($element->uri)) {
    \Drupal::messenger()->addError($this->t('O objeto recuperado está vazio ou inválido.'));
    return $form;
  }
  $this->setElement($element);

  // Analyze element properties
  $objectProperties = GenericObject::inspectObject($element);
  $baseUri = $element->uri;
  $baseLabel = $element->label ?? 'Element';

  // Main node
  $baseNode = Utils::buildNode($baseUri, $baseLabel, $element->typeUri ?? null, 'box', 24);
$jsonNodes = json_encode([$baseNode]);

  $data = (array) $element;
  $graph = \Drupal\rep\Utils::buildGraphFromArray($data, function($uri) use ($api) {
  $response = $api->getUri($uri);
  return json_decode($response);
});

$linkedNodes = $graph['nodes'];
$linkedEdges = $graph['edges'];


  // Build nodes and edges for all direct properties
  foreach ($objectProperties['objects'] as $property => $value) {
    if (!empty($value->uri)) {
      $linkedNodes[] = Utils::buildNode(
        $value->uri,
        $value->label ?? $property,
        $value->typeUri ?? null
      );

      $linkedEdges[] = [
        'from' => $baseUri,
        'to' => $value->uri,
        'label' => $property,
        'arrows' => 'to',
        'font' => ['align' => 'middle']
      ];

      // Add children of children (sub-elements)
      $subElementRaw = $api->getUri(Utils::plainUri($value->uri));
      if ($subElementRaw) {
        $subElement = $api->parseObjectResponse($subElementRaw, 'getUri');
        if ($subElement) {
          $subProps = GenericObject::inspectObject($subElement);
          foreach ($subProps['objects'] as $subProp => $subVal) {
            if (!empty($subVal->uri)) {
              $linkedNodes[] = Utils::buildNode(
                $subVal->uri,
                $subVal->label ?? $subProp,
                $subVal->typeUri ?? null
              );

              $linkedEdges[] = [
                'from' => $value->uri,
                'to' => $subVal->uri,
                'label' => $subProp,
                'arrows' => 'to',
                'font' => ['align' => 'middle']
              ];
            } elseif (!empty($subVal->label)) {
              $id = $value->uri . '-' . $subProp;
              $linkedNodes[] = [
                'id' => $id,
                'label' => $subVal->label,
                'shape' => 'ellipse',
                'color' => ['background' => '#28a745', 'border' => '#1e7e34'],
                'font' => ['color' => 'black']
              ];
              $linkedEdges[] = [
                'from' => $value->uri,
                'to' => $id,
                'label' => $subProp,
                'arrows' => 'to',
                'font' => ['align' => 'middle']
              ];
              // THIS IS THE PROCESSING OF GENERAL OBJECT PROPERTIES
              $prettyName = DescribeForm::prettyProperty($propertyName);
              $link = ' ';
              if (isset($propertyValue->label) && isset($propertyValue->uri) &&
                 ($propertyValue->label != NULL) && ($propertyValue->uri != NULL)) {
                $link = Utils::link(UTILS::sanitizeString($propertyValue->label),$propertyValue->uri);
              }
            }
        }
      }
    } elseif (!empty($value->label)) {
      // Literal (non-object) value
      $literalId = $baseUri . '-' . $property;
      $linkedNodes[] = [
        'id' => $literalId,
        'label' => $value->label,
        'shape' => 'ellipse',
        'color' => ['background' => '#28a745', 'border' => '#1e7e34'],
        'font' => ['color' => 'black']
      ];
      $linkedEdges[] = [
        'from' => $baseUri,
        'to' => $literalId,
        'label' => $property,
        'arrows' => 'to',
        'font' => ['align' => 'middle']
      ];
    }
  }
  // Add type_uri node
  if (!empty($element->typeUri)) {
    $typeLabel = $element->hascoTypeLabel ?? $element->typeLabel ?? 'Type';

    $linkedNodes[] = Utils::buildNode(
      $element->typeUri,
      ucfirst($typeLabel),
      $element->typeUri // Assume que OWL::CLAZZ virá aqui se for o caso
    );
  }

  $linkedEdges[] = [
    'from' => $element->uri,
    'to' => $element->typeUri,
    'label' => 'typeUri',
    'arrows' => 'to',
    'font' => ['align' => 'middle']
  ];
}
// Add virtual columns to the graph
if ($element->hascoTypeUri === HASCO::STUDY) {
  $vcRaw = $api->getStudyVCs($element->uri);
  if ($vcRaw) {
    $vcList = $api->parseObjectResponse($vcRaw, 'getStudyVCs');
    if (is_array($vcList)) {
      foreach ($vcList as $vcName => $vcObj) {
        $vcId = !empty($vcObj->uri) ? $vcObj->uri : 'vc-' . md5($vcName);
        $vcLabel = $vcObj->label ?? $vcName;

       $linkedNodes[] = Utils::buildNode(
  $vcId,
  $vcLabel,
  $vcObj->typeUri ?? null
);



        $linkedEdges[] = [
          'from' => $element->uri,
          'to' => $vcId,
          'label' => 'hasVirtualColumn',
          'arrows' => 'to',
          'font' => ['align' => 'middle']
        ];
      }
    }
  }
}


  // Prepare data for JS graph rendering
  $jsonExtraNodes = json_encode($linkedNodes);
  $jsonExtraEdges = json_encode($linkedEdges);
 // Graph display (template previously included)
$form['my_network_graph'] = Utils::buildGraphCanvas(
    json_decode($jsonNodes, true), // baseNodes
    $linkedNodes,                  // extraNodes
    $linkedEdges,                  // extraEdges
    []                             // baseEdges
);


// Graph title
    $form['my_network_graph_title'] = [
      '#type' => 'item',
      '#title' => '<h3>Associated Elements</h3>',
    ];
    // Render properties in form display
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
    . " <span class='graph-toggle' data-node='{$nodeId}' style='cursor:pointer;' title='Show/Hide node'>👁️</span><br><br>",
    ];
  }
}
// Render array-based values
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
    // Process associations by type
    if ($this->getElement()->hascoTypeUri === VSTOI::DEPLOYMENT) {
      AssocDeployment::process($this->getElement(), $form, $form_state);
    } else if ($this->getElement()->hascoTypeUri === SCHEMA::ORGANIZATION) {
      AssocOrganization::process($this->getElement(), $form, $form_state);
    } else if ($this->getElement()->hascoTypeUri === SCHEMA::PLACE) {
      AssocPlace::process($this->getElement(), $form, $form_state);
    } else if ($this->getElement()->hascoTypeUri === VSTOI::PLATFORM) {
      AssocPlatform::process($this->getElement(), $form, $form_state);
    } else if ($this->getElement()->hascoTypeUri === VSTOI::PLATFORM_INSTANCE) {
      AssocPlatformInstance::process($this->getElement(), $form, $form_state);
    } else if ($this->getElement()->hascoTypeUri === HASCO::STREAM) {
      AssocStream::process($this->getElement(), $form, $form_state);
    } else if ($this->getElement()->hascoTypeUri === HASCO::STUDY) {
      AssocStudy::process($this->getElement(), $form, $form_state);
    } else if ($this->getElement()->hascoTypeUri === HASCO::STUDY_OBJECT_COLLECTION) {
      AssocStudyObjectCollection::process($this->getElement(), $form, $form_state);
    } else if ($this->getElement()->typeUri === OWL::CLAZZ) {
      $this->processClass($form, $form_state);
    }
    return $form;
  }

  /**
   * Public reusable method to build graph data from a generic object.
   */
  /**public static function buildGraphFromElement($element, $api) {
    $objectProperties = GenericObject::inspectObject($element);
    $baseUri = $element->uri;
    $baseLabel = $element->label ?? 'Element';

    $nodes = [];
    $edges = [];

    foreach ($objectProperties['objects'] as $property => $value) {
      self::addNodeEdge($value, $baseUri, $property, $nodes, $edges, $api);
    }

    return [
      'nodes' => $nodes,
      'edges' => $edges,
    ];
  }*/

/**
   * Renders the address section in the form.
   */
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

  /**
   * If the current object is an OWL Class, attempts to associate by its hascoType.
   */
  public function processClass(array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    if ($this->getElement() != NULL && $this->getElement()->uri != NULL) {
      $hascoTypeRaw = $api->getHascoType($this->getElement()->uri);
      if ($hascoTypeRaw != NULL) {
        $hascoTypeJSON = $api->parseObjectResponse($hascoTypeRaw,'hascoTypeRaw');
        $response = json_decode($hascoTypeJSON, true);
        $hascoType = $response['hascoType'] ?? null;
        if ($hascoType != NULL && $hascoType == VSTOI::PLATFORM) {
          AssocPlatform::process($this->getElement(), $form, $form_state);
        }
      }
    }
  }
  /**
   * Empty validation handler.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {}

  /**
   * Empty submit handler.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}
}
