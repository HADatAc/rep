<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rep\Entity\GenericObject;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class VisGraphBaseForm extends FormBase {

  /** @var object|null */
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

    // Normalize CURIEs like "ahead:XYZ" into full IRIs.
    $expandCurie = static function (?string $v): ?string {
      return (is_string($v) && str_starts_with($v, 'ahead:'))
        ? 'http://hadatac.org/ont/arrowhead/' . substr($v, 6)
        : $v;
    };

    // Base node id/label.
    $baseUri   = $expandCurie($element->uri);
    $baseLabel = $element->label ?? 'Element';

    // Base node (visible at load).
    $baseNode = Utils::buildNode(
      $baseUri,
      $baseLabel,
      $element->typeUri ?? ($element->hascoTypeUri ?? null),
      'box',
      24
    );

    /** @var \Drupal\rep\FusekiAPIConnector $api */
    $api = \Drupal::service('rep.api_connector');

    // Generic graph walk from the inspected object (server-side).
    $data  = (array) $element;
    $graph = Utils::buildGraphFromArray($data, function ($uri) use ($api, $expandCurie) {
      $response = $api->getUri($expandCurie($uri));
      return json_decode($response);
    });

    $linkedNodes = $graph['nodes'] ?? [];
    $linkedEdges = $graph['edges'] ?? [];

    // Inspect object properties for direct links we want to expose in the menu.
    $objectProperties = GenericObject::inspectObject($element);

    // Avoid mixing generic and domain-specific predicates here.
    $skipPreds = [
      'hasVirtualColumn',
      'hasSampleCollection',
      'hasSubjectCollection',
      'hasSpaceCollection',
      'hasTimeCollection',
      'hasCollection',
      'contains',
      'hascoTypeUri',
      'typeUri',
    ];

    // Generic properties → cached nodes/edges.
    foreach ($objectProperties['objects'] as $property => $value) {
      if (in_array($property, $skipPreds, true)) {
        continue;
      }

      if (!empty($value->uri)) {
        $childUri = $expandCurie($value->uri);
        if ($childUri === $baseUri) {
          continue; // avoid self-loop
        }

        $linkedNodes[] = Utils::buildNode(
          $childUri,
          $value->label ?? $property,
          $value->typeUri ?? null
        );

        $linkedEdges[] = [
          'from'   => $baseUri,
          'to'     => $childUri,
          'label'  => $property,
          'arrows' => 'to',
          'font'   => ['align' => 'middle'],
        ];
      }
      elseif (!empty($value->label)) {
        // Literals as green ellipses (synthetic ids).
        $literalId = $baseUri . '-' . $property;
        $linkedNodes[] = [
          'id'    => $literalId,
          'label' => $value->label,
          'shape' => 'ellipse',
          'color' => ['background' => '#28a745', 'border' => '#1e7e34'],
          'font'  => ['color' => 'black'],
        ];
        $linkedEdges[] = [
          'from'   => $baseUri,
          'to'     => $literalId,
          'label'  => $property,
          'arrows' => 'to',
          'font'   => ['align' => 'middle'],
        ];
      }
    }

    // Type edge for the base element (menu key must be 'hascoTypeUri').
    $typeUriValue = $element->typeUri ?? ($element->hascoTypeUri ?? null);
    if (!empty($typeUriValue)) {
      $typeUri   = $expandCurie($typeUriValue);
      $typeLabel = $element->hascoTypeLabel ?? $element->typeLabel ?? 'Type';

      $linkedNodes[] = Utils::buildNode($typeUri, ucfirst($typeLabel), $typeUri);
      $linkedEdges[] = [
        'from'   => $baseUri,
        'to'     => $typeUri,
        'label'  => 'hascoTypeUri',
        'arrows' => 'to',
        'font'   => ['align' => 'middle'],
      ];
    }

    // Determine base type once.
    $baseType = ($element->hascoTypeUri ?? $element->typeUri ?? '');

    // -------------------- Domain additions: when base is a Study --------------------
    $isStudy = ($baseType === HASCO::STUDY);
    if ($isStudy) {
      // Virtual Columns attached to the Study.
      $vcRaw = $api->getStudyVCs($element->uri);
      if ($vcRaw) {
        $vcList = $api->parseObjectResponse($vcRaw, 'getStudyVCs');
        if (is_array($vcList)) {
          foreach ($vcList as $vcName => $vcObj) {
            $vcId    = !empty($vcObj->uri) ? $expandCurie($vcObj->uri) : 'vc-' . md5((string) $vcName);
            $vcLabel = $vcObj->label ?? $vcName;

            $linkedNodes[] = Utils::buildNode($vcId, $vcLabel, $vcObj->typeUri ?? null);
            $linkedEdges[] = [
              'from'   => $baseUri,
              'to'     => $vcId,
              'label'  => 'hasVirtualColumn',
              'arrows' => 'to',
              'font'   => ['align' => 'middle'],
            ];
          }
        }
      }

      // Study → SOCs (Subject/Sample/Space/Time)
      $socRaw = $api->getStudySOCs($element->uri, 1000, 0);
      if ($socRaw) {
        $socs = $api->parseObjectResponse($socRaw, 'getStudySOCs');
        if (is_array($socs)) {
          foreach ($socs as $soc) {
            if (empty($soc->uri)) { continue; }

            $socUri   = $expandCurie($soc->uri);
            $socLabel = $soc->label ?? Utils::namespaceUri($socUri);
            $socType  = $soc->typeUri ?? '';

            // Map type → edge label expected by the JS menu.
            $edgeLabel = null;
            if ($socType === HASCO::SAMPLE_COLLECTION) {
              $edgeLabel = 'hasSampleCollection';
            } elseif ($socType === HASCO::SPACE_COLLECTION) {
              $edgeLabel = 'hasSpaceCollection';
            } elseif ($socType === HASCO::TIME_COLLECTION) {
              $edgeLabel = 'hasTimeCollection';
            } elseif ($socType === HASCO::SUBJECT_GROUP || $socType === HASCO::STUDY_OBJECT_COLLECTION) {
              $edgeLabel = 'hasSubjectCollection';
            } else {
              continue;
            }

            $linkedNodes[] = Utils::buildNode($socUri, $socLabel, $socType);
            $linkedEdges[] = [
              'from'   => $baseUri,
              'to'     => $socUri,
              'label'  => $edgeLabel,
              'arrows' => 'to',
              'font'   => ['align' => 'middle'],
            ];
          }
        }
      }
    }

    // -------------------- Domain additions: when base is a SOC --------------------
    $isSOC = in_array($baseType, [
      HASCO::SUBJECT_GROUP,
      HASCO::STUDY_OBJECT_COLLECTION,
      HASCO::SAMPLE_COLLECTION,
      HASCO::SPACE_COLLECTION,
      HASCO::TIME_COLLECTION,
    ], true);

    if ($isSOC) {
      // 1) Preload member Study Objects so the "contains" toggle works immediately.
      $rawObjs = $api->studyObjectsBySOCwithPage($element->uri, 1000, 0);
      if ($rawObjs) {
        $objs = $api->parseObjectResponse($rawObjs, 'studyObjectsBySOCwithPage');
        if (is_array($objs)) {
          foreach ($objs as $o) {
            if (empty($o->uri)) { continue; }

            $objUri   = $expandCurie($o->uri);
            $objLabel = $o->label ?? Utils::namespaceUri($objUri);
            $objType  = $o->typeUri ?? null;

            $linkedNodes[] = Utils::buildNode($objUri, $objLabel, $objType);
            $linkedEdges[] = [
              'from'   => $baseUri,
              'to'     => $objUri,
              'label'  => 'contains',   // <- what the front-end expects
              'arrows' => 'to',
              'font'   => ['align' => 'middle'],
            ];
          }
        }
      }

      // 2) Optional: keep reverse pointer to the Study (so "isMemberOf" appears in menu).
      if (!empty($objectProperties['objects']['isMemberOf']?->uri)) {
        $studyUri = $expandCurie($objectProperties['objects']['isMemberOf']->uri);
        $studyLbl = $objectProperties['objects']['isMemberOf']->label ?? Utils::namespaceUri($studyUri);

        $linkedNodes[] = Utils::buildNode($studyUri, $studyLbl, HASCO::STUDY);
        $linkedEdges[] = [
          'from'   => $baseUri,
          'to'     => $studyUri,
          'label'  => 'isMemberOf',
          'arrows' => 'to',
          'font'   => ['align' => 'middle'],
        ];
      }
    }

    // Final normalization pass (safety net).
    foreach ($linkedNodes as &$n) {
      if (isset($n['id'])) { $n['id'] = $expandCurie($n['id']); }
      if (isset($n['typeUri'])) { $n['typeUri'] = $expandCurie($n['typeUri']); }
    }
    unset($n);
    foreach ($linkedEdges as &$e) {
      if (isset($e['from'])) { $e['from'] = $expandCurie($e['from']); }
      if (isset($e['to']))   { $e['to']   = $expandCurie($e['to']); }
    }
    unset($e);

    // Attach behavior libraries (vis.js + icons).
    $form['#attached']['library'][] = 'rep/vis_graph_panel';
    $form['#attached']['library'][] = 'rep/fontawesome';

    // Build the canvas render array.
    $canvas = Utils::buildGraphCanvas(
      [$baseNode],   // baseNodes: visible now
      $linkedNodes,  // extraNodes: cached/hidden
      $linkedEdges,  // extraEdges: cached/hidden
      []             // baseEdges: none initially
    );

    // Ensure drupalSettings and expose the lazy endpoint to JS.
    $canvas['#attached']['library'][] = 'core/drupalSettings';
    $canvas['#attached']['drupalSettings']['rep']['socObjectsEndpoint'] =
      Url::fromRoute('rep.graph.expand')->toString();

    // Place the canvas on the form.
    $form['my_network_graph'] = $canvas;

    // Optional title below the canvas.
    $form['my_network_graph_title'] = [
      '#type'   => 'markup',
      '#markup' => '<h3>Associated Elements</h3>',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {}
  public function submitForm(array &$form, FormStateInterface $form_state) {}
}
