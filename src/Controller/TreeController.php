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

  /**
   * Formats API list payloads to jsTree-compatible selectable leaves.
   */
  private function formatTreeItems(array $elements, $defaultManagerEmail = '') {
    $items = [];
    foreach ($elements as $el) {
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
   * Builds a flat, selectable list of manager-owned elements for tree modals.
   */
  private function getManagerOwnedItems($elementtype) {
    $managerEmail = \Drupal::currentUser()->getEmail();
    $elements = ListManagerEmailPage::exec($elementtype, $managerEmail, 1, 9999);
    if (!is_array($elements)) {
      $elements = [];
    }

    return $this->formatTreeItems($elements, $managerEmail);
  }

  /**
   * Builds a flat list using keyword API (project-wide) as fallback.
   */
  private function getKeywordItems($elementtype) {
    $api = \Drupal::service('rep.api_connector');
    $elements = $api->parseObjectResponse($api->listByKeyword($elementtype, '_', 9999, 0), 'listByKeyword');
    if (!is_array($elements)) {
      $elements = [];
    }

    return $this->formatTreeItems($elements, \Drupal::currentUser()->getEmail());
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
    if (($fieldId === 'instance_type' || $fieldId === NULL || $fieldId === '')
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
