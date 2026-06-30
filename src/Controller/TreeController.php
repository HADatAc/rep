<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\rep\Entity\Tables;
use Drupal\rep\EntryPoints;
use Drupal\rep\ListManagerEmailPage;
use Drupal\rep\Vocabulary\VSTOI;

class TreeController extends ControllerBase {

  private const VSTOI_PHYSICAL_INSTRUMENT = 'http://hadatac.org/ont/vstoi#PhysicalInstrument';
  private const PMSR_PHYSICAL_INSTRUMENT = 'http://pmsr.net/ont/pmsr#PhysicalInstrument';

  /**
   * Formats API list payloads to jsTree-compatible selectable leaves.
   */
  private function formatTreeItems(array $elements, $defaultManagerEmail = '') {
    $items = [];
    foreach ($elements as $el) {
      if (is_array($el)) {
        $el = (object) $el;
      }
      if (!is_object($el)) {
        continue;
      }
      if (empty($el->uri)) {
        continue;
      }
      $items[] = (object) [
        'uri' => $el->uri,
        'label' => $el->label ?? $el->hasContent ?? $el->uri,
        'comment' => $el->comment ?? '',
        'typeNamespace' => $el->typeNamespace ?? '',
        'hasStatus' => $el->hasStatus ?? NULL,
        'hasSIRManagerEmail' => $el->hasSIRManagerEmail ?? $defaultManagerEmail,
        'hasWebDocument' => $el->hasWebDocument ?? '',
        'hasImageUri' => $el->hasImageUri ?? '',
        'superUri' => $el->superUri ?? '',
        'superClassLabel' => $el->superClassLabel ?? '',
        'isCategory' => false,
        // Leaf nodes in the tree.
        'children' => false,
      ];
    }

    usort($items, function($a, $b) {
      return strcasecmp((string) $a->label, (string) $b->label);
    });

    return $items;
  }

  /**
   * Returns manager-owned list payloads without tree formatting.
   */
  private function getManagerOwnedRawItems($elementtype): array {
    $managerEmail = \Drupal::currentUser()->getEmail();
    $elements = ListManagerEmailPage::exec($elementtype, $managerEmail, 1, 9999);
    if (!is_array($elements)) {
      $elements = [];
    }
    return $elements;
  }

  /**
   * Builds a flat, selectable list of manager-owned elements for tree modals.
   */
  private function getManagerOwnedItems($elementtype) {
    return $this->formatTreeItems($this->getManagerOwnedRawItems($elementtype), \Drupal::currentUser()->getEmail());
  }

  /**
   * Returns project-wide list payloads without tree formatting.
   */
  private function getKeywordRawItems($elementtype): array {
    $api = \Drupal::service('rep.api_connector');
    $elements = $api->parseObjectResponse($api->listByKeyword($elementtype, '_', 9999, 0), 'listByKeyword');
    if (!is_array($elements)) {
      $elements = [];
    }
    return $elements;
  }

  /**
   * Builds a flat list using keyword API (project-wide) as fallback.
   */
  private function getKeywordItems($elementtype) {
    return $this->formatTreeItems($this->getKeywordRawItems($elementtype), \Drupal::currentUser()->getEmail());
  }

  /**
   * Creates a readable label from a URI fragment/path.
   */
  private function labelFromUri(string $uri): string {
    $fragment = parse_url($uri, PHP_URL_FRAGMENT);
    if (is_string($fragment) && $fragment !== '') {
      return rawurldecode($fragment);
    }

    $path = parse_url($uri, PHP_URL_PATH);
    if (is_string($path) && $path !== '') {
      $parts = explode('/', trim($path, '/'));
      $last = end($parts);
      if (is_string($last) && $last !== '') {
        return rawurldecode($last);
      }
    }

    return $uri;
  }

  /**
   * Builds a category-first hierarchy for instance_type instrument selector.
   *
   * Returns NULL when there is no list payload available so callers can
   * continue with the ontology-class fallback behavior.
   */
  private function getInstrumentInstanceHierarchyItems($nodeUri): ?array {
    $managerEmail = \Drupal::currentUser()->getEmail();

    $elements = $this->getManagerOwnedRawItems('instrument');
    if (empty($elements)) {
      $elements = $this->getKeywordRawItems('instrument');
    }
    if (empty($elements)) {
      return NULL;
    }

    $itemsByUri = [];
    foreach ($elements as $el) {
      if (is_array($el)) {
        $el = (object) $el;
      }
      if (!is_object($el) || empty($el->uri)) {
        continue;
      }

      $uri = (string) $el->uri;
      $itemsByUri[$uri] = (object) [
        'uri' => $uri,
        'label' => $el->label ?? $el->hasContent ?? $uri,
        'comment' => $el->comment ?? '',
        'typeNamespace' => $el->typeNamespace ?? '',
        'hasStatus' => $el->hasStatus ?? NULL,
        'hasSIRManagerEmail' => $el->hasSIRManagerEmail ?? $managerEmail,
        'hasWebDocument' => $el->hasWebDocument ?? '',
        'hasImageUri' => $el->hasImageUri ?? '',
        'superUri' => $el->superUri ?? '',
        'superClassLabel' => $el->superClassLabel ?? '',
        'isCategory' => false,
        'children' => false,
      ];
    }

    if (empty($itemsByUri)) {
      return NULL;
    }

    $childrenCount = [];
    foreach ($itemsByUri as $item) {
      $superUri = trim((string) ($item->superUri ?? ''));
      if ($superUri === '') {
        continue;
      }
      $childrenCount[$superUri] = ($childrenCount[$superUri] ?? 0) + 1;
    }

    if ($nodeUri === EntryPoints::CLASS_EP_INSTRUMENT) {
      $root = [];

      foreach ($itemsByUri as $item) {
        $superUri = trim((string) ($item->superUri ?? ''));

        if ($superUri === '') {
          $item->children = !empty($childrenCount[$item->uri]);
          $root[$item->uri] = $item;
          continue;
        }

        if (!isset($itemsByUri[$superUri])) {
          if (!isset($root[$superUri])) {
            $hintLabel = trim((string) ($item->superClassLabel ?? ''));
            $root[$superUri] = (object) [
              'uri' => $superUri,
              'label' => $hintLabel !== '' ? $hintLabel : $this->labelFromUri($superUri),
              'comment' => '',
              'typeNamespace' => '',
              'hasStatus' => NULL,
              'hasSIRManagerEmail' => '',
              'hasWebDocument' => '',
              'hasImageUri' => '',
              'superUri' => '',
              'superClassLabel' => '',
              'isCategory' => true,
              'children' => true,
            ];
          }
        }
      }

      $items = array_values($root);
      usort($items, function($a, $b) {
        return strcasecmp((string) ($a->label ?? ''), (string) ($b->label ?? ''));
      });
      return $items;
    }

    // If this URI is not a known parent in the instance list hierarchy,
    // allow caller fallback to ontology-class exploration.
    if (!isset($childrenCount[$nodeUri])) {
      return NULL;
    }

    $children = [];
    foreach ($itemsByUri as $item) {
      $superUri = trim((string) ($item->superUri ?? ''));
      if ($superUri !== $nodeUri) {
        continue;
      }

      $item->children = !empty($childrenCount[$item->uri]);
      $children[$item->uri] = $item;
    }

    $items = array_values($children);
    usort($items, function($a, $b) {
      return strcasecmp((string) ($a->label ?? ''), (string) ($b->label ?? ''));
    });

    return $items;
  }

  /**
   * Resolves a canonical VSTOI Physical Instrument node for class trees.
   */
  private function resolveVstoiPhysicalInstrumentNode($api) {
    $node = NULL;

    $candidates = $api->parseObjectResponse(
      $api->getSubclassesKeyword(VSTOI::INSTRUMENT, 'instrument'),
      'getSubclassesKeyword'
    );

    if (is_array($candidates)) {
      foreach ($candidates as $candidate) {
        if (!is_object($candidate) || empty($candidate->uri)) {
          continue;
        }
        if ($candidate->uri !== self::VSTOI_PHYSICAL_INSTRUMENT) {
          continue;
        }
        if (!empty($candidate->superUri) && $candidate->superUri !== VSTOI::INSTRUMENT) {
          continue;
        }
        $node = $candidate;
        break;
      }
    }

    if (!$node) {
      $node = (object) [
        'uri' => self::VSTOI_PHYSICAL_INSTRUMENT,
        'label' => 'Physical Instrument',
        'superUri' => VSTOI::INSTRUMENT,
        'typeNamespace' => self::VSTOI_PHYSICAL_INSTRUMENT,
      ];
    }

    $sub = $api->parseObjectResponse($api->getChildren(self::VSTOI_PHYSICAL_INSTRUMENT), 'getChildren');
    $node->children = is_array($sub) && !empty($sub);
    return $node;
  }

  /**
   * Keeps the Instrument branch aligned with canonical VSTOI classes.
   */
  private function normalizeInstrumentClassChildren($api, array $children): array {
    $pool = [];
    foreach ($children as $item) {
      if (!is_object($item) || empty($item->uri)) {
        continue;
      }

      // Avoid ambiguous duplicate "Physical Instrument" nodes at this level.
      if ($item->uri === self::PMSR_PHYSICAL_INSTRUMENT) {
        continue;
      }

      $pool[$item->uri] = $item;
    }

    if (!isset($pool[self::VSTOI_PHYSICAL_INSTRUMENT])) {
      $pool[self::VSTOI_PHYSICAL_INSTRUMENT] = $this->resolveVstoiPhysicalInstrumentNode($api);
    }

    return array_values($pool);
  }

  /**
   * Enriches canonical vstoi:PhysicalInstrument branch with PMSR-specific children.
   */
  private function normalizePhysicalInstrumentClassChildren($api, array $children): array {
    $pool = [];
    foreach ($children as $item) {
      if (!is_object($item) || empty($item->uri)) {
        continue;
      }
      $pool[$item->uri] = $item;
    }

    $legacyChildren = $api->parseObjectResponse(
      $api->getChildren(self::PMSR_PHYSICAL_INSTRUMENT),
      'getChildren'
    );
    if (is_array($legacyChildren)) {
      foreach ($legacyChildren as $item) {
        if (!is_object($item) || empty($item->uri)) {
          continue;
        }
        $pool[$item->uri] = $item;
      }
    }

    return array_values($pool);
  }

  // public function getChildren(Request $request) {
  //   $api = \Drupal::service('rep.api_connector');

  //   $nodeUri = $request->query->get('nodeUri');
  //   $data = $api->parseObjectResponse($api->getChildren($nodeUri),'getChildren');

  //   // Validate and format the data
  //   if (!is_array($data)) {
  //     $data = [];
  //   }

  //   // Return a JSON response
  //   return new JsonResponse($data);
  // }

  public function getChildren(Request $request) {
    $api     = \Drupal::service('rep.api_connector');
    $nodeUri = $request->query->get('nodeUri');
    $fieldId = $request->query->get('field_id');

    // Optional hint sent by the tree widget.
    // When selecting a Component for a ContainerSlot we want instances (created components),
    // not the ontology class hierarchy (Detector/Actuator/etc.).
    $elementtype = $request->query->get('elementtype');

    // When selecting a Workflow Stem (a.k.a. ProcessStem), we want manager-owned instances,
    // not just the ontology class hierarchy.
    if (in_array($elementtype, ['processstem', 'workflowstem'], true) && $nodeUri === VSTOI::PROCESS_STEM) {
      return new JsonResponse($this->getManagerOwnedItems($elementtype));
    }

    // Context-specific selectors that should prefer manager-owned definitions
    // at class roots, with keyword fallback when manager-scoped list is empty.
    $instanceFieldRoots = [
      'component' => EntryPoints::CLASS_EP_COMPONENT,
      'instrument' => EntryPoints::CLASS_EP_INSTRUMENT,
      'platform' => EntryPoints::CLASS_EP_PLATFORM,
    ];

    // Organize instrument models by category/stem chain in instance selector.
    if ($fieldId === 'instance_type' && $elementtype === 'instrument') {
      $items = $this->getInstrumentInstanceHierarchyItems($nodeUri);
      if ($items !== NULL) {
        return new JsonResponse($items);
      }
    }

    // Only force instance listing for the instance-type selector modal.
    // In browse pages (e.g. /sir/list or /rep/hierarchy/browse/*) field_id is empty and we
    // want the ontology class hierarchy (InstrumentEntryPoint → Instrument → ...), not a flat
    // list of instances.
    if ($fieldId === 'instance_type'
      && isset($instanceFieldRoots[$elementtype])
      && $nodeUri === $instanceFieldRoots[$elementtype]) {
      $items = $this->getManagerOwnedItems($elementtype);
      if (!empty($items)) {
        return new JsonResponse($items);
      }

      // If manager-scoped list is empty, use project-wide listing so instance
      // creation can still select existing definitions.
      $items = $this->getKeywordItems($elementtype);
      if (!empty($items)) {
        return new JsonResponse($items);
      }

      // Fallback to ontology classes when manager has no owned elements.
    }

    if ($fieldId === 'component_isAttributeOf'
      && $elementtype === 'componentattribute'
      && $nodeUri === EntryPoints::CLASS_EP_COMPONENT_ATTRIBUTE) {
      $items = $api->parseObjectResponse($api->getChildren($nodeUri), 'getChildren');
      if (!is_array($items)) {
        $items = [];
      }

      // Keep ontology semantics: selector is for ComponentAttribute classes.
      // If a returned class has no subclasses, mark it as a leaf so it can be
      // selected directly in the modal tree.
      foreach ($items as $item) {
        if (empty($item->uri)) {
          continue;
        }
        $sub = $api->parseObjectResponse($api->getChildren($item->uri), 'getChildren');
        $item->children = is_array($sub) && !empty($sub);
      }

      usort($items, function($a, $b) {
        return strcasecmp((string) ($a->label ?? ''), (string) ($b->label ?? ''));
      });

      return new JsonResponse($items);
    }

    if ($elementtype === 'component' &&
        $nodeUri === EntryPoints::CLASS_EP_COMPONENT &&
        $fieldId === 'containerslot_component') {
      return new JsonResponse($this->getManagerOwnedItems('component'));
    }

    if ($elementtype === 'componentinstance' && $nodeUri === EntryPoints::INSTANCE_EP_COMPONENT) {
      return new JsonResponse($this->getManagerOwnedItems('componentinstance'));
    }

    if ($elementtype === 'instrumentinstance' && $nodeUri === EntryPoints::INSTANCE_EP_INSTRUMENT) {
      return new JsonResponse($this->getManagerOwnedItems('instrumentinstance'));
    }

    if ($elementtype === 'platforminstance' && $nodeUri === EntryPoints::INSTANCE_EP_PLATFORM) {
      return new JsonResponse($this->getManagerOwnedItems('platforminstance'));
    }

    $children = $api->parseObjectResponse($api->getChildren($nodeUri), 'getChildren');
    if (!is_array($children)) {
      $children = [];
    }

    if ($nodeUri === VSTOI::INSTRUMENT && $fieldId !== 'instance_type') {
      $children = $this->normalizeInstrumentClassChildren($api, $children);
    }

    if ($nodeUri === self::VSTOI_PHYSICAL_INSTRUMENT && $fieldId !== 'instance_type') {
      $children = $this->normalizePhysicalInstrumentClassChildren($api, $children);
    }

    // Some ontology entry points can be present as roots but have no direct
    // children in /api/children. In these cases, resolve the corresponding
    // VSTOI class as an intermediate node to preserve production-like shape:
    // EntryPoint -> Class -> subclasses.
    if (empty($children)) {
      $entryPointFallbacks = [
        EntryPoints::CLASS_EP_INSTRUMENT => VSTOI::INSTRUMENT,
        EntryPoints::CLASS_EP_COMPONENT => VSTOI::COMPONENT,
        EntryPoints::CLASS_EP_COMPONENT_STEM => VSTOI::COMPONENT_STEM,
      ];
      $entryPointFallbackLabels = [
        EntryPoints::CLASS_EP_INSTRUMENT => 'Instrument',
        EntryPoints::CLASS_EP_COMPONENT => 'Component',
        EntryPoints::CLASS_EP_COMPONENT_STEM => 'Component Stem',
      ];
      if (isset($entryPointFallbacks[$nodeUri])) {
        $fallbackRootUri = $entryPointFallbacks[$nodeUri];
        $sub = $api->parseObjectResponse($api->getChildren($fallbackRootUri), 'getChildren');
        $hasSub = is_array($sub) && !empty($sub);

        // Some local graphs expose class hierarchies but do not resolve the
        // class itself via /api/uri (which would surface a user-facing error).
        // Build a synthetic intermediate node unconditionally here.
        $fallbackRoot = (object) [
          'uri' => $fallbackRootUri,
          'label' => $entryPointFallbackLabels[$nodeUri] ?? $fallbackRootUri,
          'superUri' => $nodeUri,
          'typeNamespace' => $fallbackRootUri,
        ];

        $fallbackRoot->children = $hasSub;
        $children = [$fallbackRoot];
      }
    }

    if (empty($children) && $nodeUri === EntryPoints::CLASS_EP_COMPONENT_ATTRIBUTE) {
      $fallback = $api->parseObjectResponse($api->getUri(VSTOI::COMPONENT_ATTRIBUTE), 'getUri');
      if (is_object($fallback) && !empty($fallback->uri)) {
        $sub = $api->parseObjectResponse($api->getChildren($fallback->uri), 'getChildren');
        $fallback->children = is_array($sub) && !empty($sub);
        $children = [$fallback];
      }
    }

    $tables       = new Tables(\Drupal::database());
    $all_mappings = $tables->getAllMappings();
    $mapped_nodes = [];
    if (isset($all_mappings[$nodeUri])) {
      $mappedUri = $all_mappings[$nodeUri];
      if ($obj = $api->parseObjectResponse($api->getUri($mappedUri), 'getUri')) {
        $mapped_nodes[] = $obj;
      }
    }

    $pool = [];
    foreach (array_merge($children, $mapped_nodes) as $item) {
      $pool[$item->uri] = $item;
    }

    foreach ($pool as $uri => $item) {
      $item->isMapped = (isset($all_mappings[$nodeUri]) && $all_mappings[$nodeUri] === $uri);
    }

    $items = array_values($pool);
    usort($items, function($a, $b) {
      return strcasecmp($a->label, $b->label);
    });
    return new JsonResponse(array_values($pool));
  }

  public function getNode(Request $request) {
    $api = \Drupal::service('rep.api_connector');

    $nodeUri = $request->query->get('nodeUri');
    if ($nodeUri === NULL || trim((string) $nodeUri) === '') {
      $nodeUri = $request->query->get('uri');
    }
    if ($nodeUri === NULL || trim((string) $nodeUri) === '') {
      return new JsonResponse(['error' => 'Missing required query parameter: nodeUri'], 400);
    }

    $data = $api->parseObjectResponse($api->getUri($nodeUri),'getUri');

    // Return a JSON response
    return new JsonResponse($data);
  }

  public function getSubclassesKeyword(Request $request) {
    $api = \Drupal::service('rep.api_connector');

    $superUri = $request->query->get('superuri');
    $keyword = $request->query->get('keyword');

    $data = $api->parseObjectResponse($api->getSubclassesKeyword($superUri, $keyword),'getSubclassesKeyword');

    // Validate and format the data
    if (!is_array($data)) {
      $data = [];
    }

    // Return a JSON response
    return new JsonResponse($data);
  }

  public function getSuperClasses(Request $request) {
    $api = \Drupal::service('rep.api_connector');

    $superUri = $request->query->get('uri');
    $data = $api->parseObjectResponse($api->getSuperClasses($superUri),'getSuperClasses');

    // Validate and format the data
    if (!is_array($data)) {
      $data = [];
    }

    // Return a JSON response
    return new JsonResponse($data);
  }

  /**
   * Forces the download of a private file.
   *
   * Receives the instrument URI part from the route and the file name via the "doc" query parameter.
   *
   * @param string $instrumenturi
   *   The instrument URI part from the route.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The file download response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown if the file parameter is missing or the file cannot be found.
   */
  public function downloadFile($instrumenturi, Request $request) {
    // Retrieve the file name from the query parameter 'doc'.
    $doc = $request->query->get('doc');
    if (!$doc) {
      throw new NotFoundHttpException('File not specified.');
    }

    // Build the file URI for the private file.
    $file_uri = "private://resources/{$instrumenturi}/webdoc/{$doc}";
    // Get the real file path on the server.
    $file_system = \Drupal::service('file_system');
    $file_path = $file_system->realpath($file_uri);

    if (!file_exists($file_path)) {
      throw new NotFoundHttpException('File not found.');
    }

    // Create a BinaryFileResponse to force the file download.
    $response = new BinaryFileResponse($file_path);
    // Set Content-Disposition to attachment so that the browser downloads the file.
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $doc
    );
    return $response;
  }

  public function getEntryPointMappings() {
    $tables = new Tables(\Drupal::database());
    // getAllMappings retorna [ entry_point_uri => node_uri, … ].
    $mapping = $tables->getAllMappings();

    return new JsonResponse($mapping);
  }

  public function getTopClass(Request $request) {
    $api     = \Drupal::service('rep.api_connector');
    $nodeUri = $request->query->get('nodeUri');
    if ($nodeUri === NULL || trim((string) $nodeUri) === '') {
      $nodeUri = $request->query->get('uri');
    }
    if ($nodeUri === NULL || trim((string) $nodeUri) === '') {
      return new JsonResponse(['error' => 'Missing required query parameter: nodeUri'], 400);
    }

    $topNode = $api->parseObjectResponse($api->getUri($nodeUri), 'getUri');
    // kint($topNode, 'Top Node');
    // $abbrev = strstr($topNode->uriNamespace, ':', true);
    $children = $api->parseObjectResponse($api->repoTopClassNamespaces($nodeUri), 'repoTopClassNamespaces');
    if (!is_array($children)) {
      $children = [];
    }

    $tables       = new Tables(\Drupal::database());
    $all_mappings = $tables->getAllMappings();
    $mapped_nodes = [];
    if (isset($all_mappings[$nodeUri])) {
      $mappedUri = $all_mappings[$nodeUri];
      if ($obj = $api->parseObjectResponse($api->getUri($mappedUri), 'getUri')) {
        $mapped_nodes[] = $obj;
      }
    }

    $pool = [];
    foreach (array_merge($children, $mapped_nodes) as $item) {
      $pool[$item->uri] = $item;
    }

    foreach ($pool as $uri => $item) {
      $item->isMapped = (isset($all_mappings[$nodeUri]) && $all_mappings[$nodeUri] === $uri);
    }

    $items = array_values($pool);
    usort($items, function($a, $b) {
      return strcasecmp($a->label, $b->label);
    });
    return new JsonResponse(array_values($pool));
  }

}
