<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Utils;
use Drupal\rep\Entity\GenericObject;
use Drupal\rep\Vocabulary\HASCO;

class VisGraphBaseForm extends FormBase {

  protected $visElement;

  public function getFormId() {
    return 'vis_graph_base_form';
  }

  public function setVisElement($element) {
    $this->visElement = $element;
  }

  public function getVisElement() {
    return $this->visElement;
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $element = $this->getVisElement();

    if (!$element || !isset($element->uri)) {
      \Drupal::messenger()->addError($this->t('Invalid element for the graph.'));
      return $form;
    }

    $objectProperties = GenericObject::inspectObject($element);
    $baseUri = $element->uri;
    $baseLabel = $element->label ?? 'Element';

    $baseNode = Utils::buildNode($baseUri, $baseLabel, $element->typeUri ?? null, 'box', 24);
    $jsonNodes = json_encode([$baseNode]);

    $api = \Drupal::service('rep.api_connector');
    $data = (array) $element;
    $graph = Utils::buildGraphFromArray($data, function($uri) use ($api) {
      $response = $api->getUri($uri);
      return json_decode($response);
    });

    $linkedNodes = $graph['nodes'];
    $linkedEdges = $graph['edges'];

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
      } elseif (!empty($value->label)) {
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

    if (!empty($element->typeUri)) {
      $typeLabel = $element->hascoTypeLabel ?? $element->typeLabel ?? 'Type';
      $linkedNodes[] = Utils::buildNode($element->typeUri, ucfirst($typeLabel), $element->typeUri);
      $linkedEdges[] = [
        'from' => $element->uri,
        'to' => $element->typeUri,
        'label' => 'typeUri',
        'arrows' => 'to',
        'font' => ['align' => 'middle']
      ];
    }

    // Add virtual columns and sample collections
    if ($element->hascoTypeUri === HASCO::STUDY) {
      $vcRaw = $api->getStudyVCs($element->uri);
      if ($vcRaw) {
        $vcList = $api->parseObjectResponse($vcRaw, 'getStudyVCs');
        if (is_array($vcList)) {
          foreach ($vcList as $vcName => $vcObj) {
            $vcId = !empty($vcObj->uri) ? $vcObj->uri : 'vc-' . md5($vcName);
            $vcLabel = $vcObj->label ?? $vcName;
            $linkedNodes[] = Utils::buildNode($vcId, $vcLabel, $vcObj->typeUri ?? null);
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

      $socRaw = $api->getStudySOCs($element->uri, 1000, 0);
      if ($socRaw) {
        $socs = $api->parseObjectResponse($socRaw, 'getStudySOCs');
        if (is_array($socs)) {
          foreach ($socs as $soc) {
            if (!empty($soc->uri)) {
              $socLabel = $soc->label ?? Utils::namespaceUri($soc->uri);
              $linkedNodes[] = Utils::buildNode($soc->uri, $socLabel, $soc->typeUri);

              if ($soc->typeUri === HASCO::SAMPLE_COLLECTION) {
                $linkedEdges[] = [
                  'from' => $element->uri,
                  'to' => $soc->uri,
                  'label' => 'hasSampleCollection',
                  'arrows' => 'to',
                  'font' => ['align' => 'middle']
                ];
              } elseif ($soc->typeUri === HASCO::SUBJECT_GROUP || $soc->typeUri === HASCO::STUDY_OBJECT_COLLECTION) {
                $linkedEdges[] = [
                  'from' => $element->uri,
                  'to' => $soc->uri,
                  'label' => 'hasSubjectCollection',
                  'arrows' => 'to',
                  'font' => ['align' => 'middle']
                ];
              }
            }
          }
        }
      }
    }

    $form['#attached']['library'][] = 'rep/vis_graph_panel';

    $form['my_network_graph_title'] = [
      '#type' => 'item',
      '#title' => '<h3>Associated Elements</h3>',
    ];

    $form['my_network_graph'] = Utils::buildGraphCanvas(
      json_decode($jsonNodes, true),
      $linkedNodes,
      $linkedEdges,
      []
    );

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {}
  public function submitForm(array &$form, FormStateInterface $form_state) {}

}
