<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;
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

    // -------- Limits exposed to the frontend --------
    $MAX_MEMBERS_PER_SOC = 5;   // how many SOC members to preload / show initially
    $PAGE_SIZE            = 25;  // "Load more..." page size
    $MAX_LIVE_NODES       = 600; // safety cap for visible nodes
    $AUTO_SHOW_ON_FETCH   = 0;   // don't auto-add nodes fetched via AJAX

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
      return $api->parseObjectResponse($response, 'getUri');
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
    // ----- Type edges for the base element (mostrar ambos) -----
if (!empty($element->hascoTypeUri)) {
  $typeHasco = $expandCurie($element->hascoTypeUri);
  $typeHascoLbl = $element->hascoTypeLabel ?? 'HASCO Type';
  $linkedNodes[] = Utils::buildNode($typeHasco, ucfirst($typeHascoLbl), $typeHasco);
  $linkedEdges[] = [
    'from' => $baseUri, 'to' => $typeHasco,
    'label' => 'hascoTypeUri', 'arrows' => 'to',
    'font' => ['align' => 'middle'],
  ];
}
if (!empty($element->typeUri)) {
  $typeStd = $expandCurie($element->typeUri);
  $typeStdLbl = $element->typeLabel ?? 'Type';
  $linkedNodes[] = Utils::buildNode($typeStd, ucfirst($typeStdLbl), $typeStd);
  $linkedEdges[] = [
    'from' => $baseUri, 'to' => $typeStd,
    'label' => 'typeUri', 'arrows' => 'to',
    'font' => ['align' => 'middle'],
  ];
}


    // Determine base type once.
    $baseType = ($element->hascoTypeUri ?? $element->typeUri ?? '');

    // -------------------- Domain additions: when base is a Study --------------------
    $isStudy = ($baseType === HASCO::STUDY);
    if ($isStudy) {
      // 1) Virtual Columns of the Study.
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

      // 2) Study → SOCs (Subject/Sample/Space/Time) + preload limited members for "contains".
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

            // SOC node
            $linkedNodes[] = Utils::buildNode($socUri, $socLabel, $socType);
            $linkedEdges[] = [
              'from'   => $baseUri,
              'to'     => $socUri,
              'label'  => $edgeLabel,
              'arrows' => 'to',
              'font'   => ['align' => 'middle'],
            ];

            // PRELOAD (limited): members of this SOC so the "contains" submenu works immediately.
            $rawObjs = $api->studyObjectsBySOCwithPage($soc->uri, $MAX_MEMBERS_PER_SOC, 0);
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
                    'from'   => $socUri,
                    'to'     => $objUri,
                    'label'  => 'contains',
                    'arrows' => 'to',
                    'font'   => ['align' => 'middle'],
                  ];
                }
              }
            }
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
      // Limited preload so the "contains" submenu opens fast without flooding the canvas.
      $rawObjs = $api->studyObjectsBySOCwithPage($element->uri, $MAX_MEMBERS_PER_SOC, 0);
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
              'label'  => 'contains',
              'arrows' => 'to',
              'font'   => ['align' => 'middle'],
            ];
          }
        }
      }

      // Optional: reverse pointer to the Study for the menu.
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
    // NOTE: This form is sometimes embedded via array union ("+") which can drop
    // root-level #attached. So we also attach critical libraries to the canvas
    // render array (which always bubbles up).
    $form['#attached']['library'][] = 'rep/vis_graph_panel';
    $form['#attached']['library'][] = 'rep/fontawesome';

    // Build the canvas render array.
    $canvas = Utils::buildGraphCanvas(
      [$baseNode],   // baseNodes: visible now
      $linkedNodes,  // extraNodes: cached/hidden
      $linkedEdges,  // extraEdges: cached/hidden
      []             // baseEdges: none initially
    );

    // Make the graph collapse work even when Bootstrap JS isn't present.
    $canvas['#attached']['library'][] = 'rep/graph_collapse';

    // Ensure drupalSettings and expose the lazy endpoint + limits to JS.
    $canvas['#attached']['library'][] = 'core/drupalSettings';
    $canvas['#attached']['drupalSettings']['rep']['socObjectsEndpoint'] =
      Url::fromRoute('rep.graph.expand')->toString();

    $canvas['#attached']['drupalSettings']['rep']['graphLimits'] = [
      'maxMembersPerSOC' => $MAX_MEMBERS_PER_SOC,
      'pageSize'         => $PAGE_SIZE,
      'maxLiveNodes'     => $MAX_LIVE_NODES,
      'autoShowOnFetch'  => $AUTO_SHOW_ON_FETCH,
    ];

    // Graph wrapper (match CienciaPT markup) and default to collapsed.
    $collapseId = 'graph-canvas-collapse-' . substr(md5((string) $baseUri), 0, 10);

    $form['graph_canvas_block'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['graph-canvas-block'],
        'style' => 'margin:20px auto;padding:20px;max-width:100%;border:2px solid #ccc;border-radius:12px;background:#fff;',
        'data-graph-toggle-init' => '1',
      ],
    ];

    $form['graph_canvas_block']['graph_canvas_header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['graph-canvas-header'],
        'style' => 'display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;',
      ],
    ];

    $form['graph_canvas_block']['graph_canvas_header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Graph Visualization'),
      '#attributes' => [
        'style' => 'margin:0;',
      ],
    ];

    $form['graph_canvas_block']['graph_canvas_header']['toggle'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => Markup::create('<span class="graph-toggle-icon" aria-hidden="true">⤢</span><span class="graph-toggle-label">' . $this->t('Show') . '</span>'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['graph-toggle-btn'],
        'aria-expanded' => 'false',
        'aria-controls' => $collapseId,
        'data-canvas-id' => 'my-network',
        'title' => $this->t('Show graph'),
        'style' => 'display:inline-flex;align-items:center;gap:8px;border:1px solid #ddd;background:#f8f9fa;color:#333;border-radius:8px;padding:6px 10px;cursor:pointer;',
      ],
    ];

    $form['graph_canvas_block']['graph_canvas_collapse'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => $collapseId,
        'class' => ['graph-canvas-collapse'],
        'style' => 'display: none;',
      ],
    ];

    $form['graph_canvas_block']['graph_canvas_collapse']['my_network_graph'] = $canvas;

    // Optional title below the canvas.
    $form['my_network_graph_title'] = [
      '#type'   => 'markup',
      '#markup' => '<h3 class="mt-4">Associated Elements</h3>',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {}
  public function submitForm(array &$form, FormStateInterface $form_state) {}

}
