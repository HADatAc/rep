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

  private const CLASS_ENTRY_POINT_ROOT = 'http://hadatac.org/ont/hasco/ClassEntryPoint';
  private const VSTOI_PHYSICAL_INSTRUMENT = 'http://hadatac.org/ont/vstoi#PhysicalInstrument';
  private const PMSR_PHYSICAL_INSTRUMENT = 'https://pmsr.net/ont/PhysicalInstrument';
  private const MAX_INSTANCE_LABEL_URI_LOOKUPS = 40;
  private const PMSR_ONTOLOGY_PREFIX = 'https://pmsr.net/ont/';
  private const REQUIRED_CLASS_ENTRY_POINT_LOCAL_NAMES = [
    'AnnotationStemEntryPoint',
    'AnatomicalPartEntryPoint',
    'AttributeEntryPoint',
    'CodeBookEntryPoint',
    'ComponentEntryPoint',
    'ComponentAttributeEntryPoint',
    'ComponentStemEntryPoint',
    'EntityEntryPoint',
    'GroupEntryPoint',
    'InstrumentEntryPoint',
    'MedicalDeviceEntryPoint',
    'OrganizationEntryPoint',
    'PersonEntryPoint',
    'PlaceEntryPoint',
    'PlatformEntryPoint',
    'QuestionnaireEntryPoint',
    'ResponseOptionEntryPoint',
    'StudyEntryPoint',
    'TaskEntryPoint',
    'TaskTemporalDependencyEntryPoint',
    'UnitEntryPoint',
    'WorkflowEntryPoint',
    'WorkflowStemEntryPoint',
  ];
  private const CLASS_ENTRY_POINT_URI_ALIASES = [
    'http://hadatac.org/ont/hasco/AnnotationEntryPoint' => 'http://hadatac.org/ont/hasco/AnnotationStemEntryPoint',
    'http://hadatac.org/ont/hasco/CodebookEntryPoint' => 'http://hadatac.org/ont/hasco/CodeBookEntryPoint',
  ];
  private const CLASS_ENTRY_POINT_LABELS = [
    'AnnotationStemEntryPoint' => 'Annotation Stem Entry Point',
    'AnatomicalPartEntryPoint' => 'Anatomical Part Entry Point',
    'AttributeEntryPoint' => 'Attribute Entry Point',
    'CodeBookEntryPoint' => 'Code Book Entry Point',
    'ComponentEntryPoint' => 'Component Entry Point',
    'ComponentAttributeEntryPoint' => 'Component Attribute Entry Point',
    'ComponentStemEntryPoint' => 'Component Stem Entry Point',
    'EntityEntryPoint' => 'Entity Entry Point',
    'GroupEntryPoint' => 'Group Entry Point',
    'InstrumentEntryPoint' => 'Instrument Entry Point',
    'MedicalDeviceEntryPoint' => 'Medical Device Entry Point',
    'OrganizationEntryPoint' => 'Organization Entry Point',
    'PersonEntryPoint' => 'Person Entry Point',
    'PlaceEntryPoint' => 'Place Entry Point',
    'PlatformEntryPoint' => 'Platform Entry Point',
    'QuestionnaireEntryPoint' => 'Questionnaire Entry Point',
    'ResponseOptionEntryPoint' => 'Response Option Entry Point',
    'StudyEntryPoint' => 'Study Entry Point',
    'TaskEntryPoint' => 'Task Entry Point',
    'TaskTemporalDependencyEntryPoint' => 'Task Temporal Dependency Entry Point',
    'UnitEntryPoint' => 'Unit Entry Point',
    'WorkflowEntryPoint' => 'Workflow Entry Point',
    'WorkflowStemEntryPoint' => 'Workflow Stem Entry Point',
  ];
  private array $instanceLabelCache = [];
  private int $instanceLabelLookupCount = 0;

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
   * Resolves the grouping parent URI for instance selector organization.
   */
  private function getInstanceGroupingParentUri($element, string $elementtype): string {
    if ($elementtype === 'component') {
      return trim((string) ($element->hasComponentStem ?? $element->typeUri ?? ''));
    }

    return trim((string) ($element->superUri ?? ''));
  }

  /**
   * Resolves the grouping parent label hint for instance selector organization.
   */
  private function resolveLabelFromUriSilently($api, string $uri): string {
    $uri = trim($uri);
    if ($uri === '') {
      return '';
    }

    if (array_key_exists($uri, $this->instanceLabelCache)) {
      return $this->instanceLabelCache[$uri];
    }

    if ($this->instanceLabelLookupCount >= self::MAX_INSTANCE_LABEL_URI_LOOKUPS) {
      $this->instanceLabelCache[$uri] = '';
      return '';
    }

    $this->instanceLabelLookupCount++;

    $label = '';

    try {
      $raw = $api->getUri($uri);
      $decoded = NULL;

      if (is_string($raw)) {
        $decoded = json_decode($raw);
      }
      elseif (is_object($raw) || is_array($raw)) {
        $decoded = $raw;
      }

      if (is_object($decoded) && !empty($decoded->isSuccessful) && isset($decoded->body)) {
        $body = $decoded->body;
        if (is_array($body) && !empty($body)) {
          $body = reset($body);
        }

        if (is_object($body)) {
          $label = trim((string) ($body->label ?? $body->hasContent ?? $body->localName ?? ''));
        }
        elseif (is_array($body)) {
          $label = trim((string) ($body['label'] ?? $body['hasContent'] ?? $body['localName'] ?? ''));
        }
      }
      elseif (is_object($decoded)) {
        $label = trim((string) ($decoded->label ?? $decoded->hasContent ?? $decoded->localName ?? ''));
      }
      elseif (is_array($decoded)) {
        $label = trim((string) ($decoded['label'] ?? $decoded['hasContent'] ?? $decoded['localName'] ?? ''));
      }
    }
    catch (\Throwable $t) {
      $label = '';
    }

    $this->instanceLabelCache[$uri] = $label;
    return $label;
  }

  private function normalizeComponentCategoryLabel(string $label): string {
    $label = trim($label);
    if ($label === '') {
      return '';
    }

    $label = preg_replace('/\s*--\s*CB:.*$/i', '', $label);
    return trim((string) $label);
  }

  private function stripLanguageTagSuffix(string $label): string {
    $label = trim($label);
    if ($label === '') {
      return '';
    }

    $label = preg_replace('/@([a-z]{2}(?:-[a-z0-9]+)?)$/i', '', $label);
    return trim((string) $label);
  }

  private function isTechnicalCategoryLabel(string $label): bool {
    $normalized = strtoupper(trim($label));
    if ($normalized === '') {
      return true;
    }

    return (bool) preg_match('/^(CSM|COM|INS|PLT)\d+$/', $normalized);
  }

  private function normalizePlatformCategoryLabel(string $label): string {
    $label = $this->stripLanguageTagSuffix($label);

    if (strcasecmp($label, 'site') === 0) {
      return 'Site';
    }

    return $label;
  }

  private function normalizeCategoryLabelByType(string $label, string $elementtype): string {
    $label = $this->stripLanguageTagSuffix($label);
    if ($elementtype === 'platform') {
      return $this->normalizePlatformCategoryLabel($label);
    }
    if ($elementtype === 'component') {
      return $this->normalizeComponentCategoryLabel($label);
    }

    return $label;
  }

  private function getInstanceGroupingParentLabel($element, string $elementtype): string {
    if ($elementtype === 'component') {
      if (isset($element->componentStem)) {
        $stem = $element->componentStem;
        if (is_object($stem) && !empty($stem->label)) {
          return $this->normalizeCategoryLabelByType((string) $stem->label, $elementtype);
        }
        if (is_array($stem) && !empty($stem['label'])) {
          return $this->normalizeCategoryLabelByType((string) $stem['label'], $elementtype);
        }
      }
      if (!empty($element->typeLabel)) {
        return $this->normalizeCategoryLabelByType((string) $element->typeLabel, $elementtype);
      }

      return '';
    }

    $label = trim((string) ($element->superClassLabel ?? ''));
    return $this->normalizeCategoryLabelByType($label, $elementtype);
  }

  /**
   * Builds a category-first hierarchy for instance_type selectors.
   *
   * Returns NULL when there is no list payload available so callers can
   * continue with the ontology-class fallback behavior.
   */
  private function getInstanceHierarchyItems($nodeUri, string $elementtype, string $entryPointUri): ?array {
    $managerEmail = \Drupal::currentUser()->getEmail();
    $api = \Drupal::service('rep.api_connector');

    $elements = $this->getManagerOwnedRawItems($elementtype);
    if (empty($elements)) {
      $elements = $this->getKeywordRawItems($elementtype);
    }
    if (empty($elements)) {
      return NULL;
    }

    $itemsByUri = [];
    $parentUriByItem = [];
    $parentLabelByItem = [];
    $fallbackCategoryLabelByParentUri = [];
    foreach ($elements as $el) {
      if (is_array($el)) {
        $el = (object) $el;
      }
      if (!is_object($el) || empty($el->uri)) {
        continue;
      }

      $uri = (string) $el->uri;
      $parentUri = $this->getInstanceGroupingParentUri($el, $elementtype);
      $parentLabel = $this->getInstanceGroupingParentLabel($el, $elementtype);

      $parentUriByItem[$uri] = $parentUri;
      $parentLabelByItem[$uri] = $parentLabel;

      if ($elementtype === 'component' && $parentUri !== '') {
        $candidate = $this->normalizeComponentCategoryLabel((string) ($el->label ?? ''));
        if ($candidate !== '' && !isset($fallbackCategoryLabelByParentUri[$parentUri])) {
          $fallbackCategoryLabelByParentUri[$parentUri] = $candidate;
        }
      }

      $itemsByUri[$uri] = (object) [
        'uri' => $uri,
        'label' => $el->label ?? $el->hasContent ?? $uri,
        'comment' => $el->comment ?? '',
        'typeNamespace' => $el->typeNamespace ?? '',
        'hasStatus' => $el->hasStatus ?? NULL,
        'hasSIRManagerEmail' => $el->hasSIRManagerEmail ?? $managerEmail,
        'hasWebDocument' => $el->hasWebDocument ?? '',
        'hasImageUri' => $el->hasImageUri ?? '',
        'superUri' => $parentUri,
        'superClassLabel' => $parentLabel,
        'isCategory' => false,
        'children' => false,
      ];
    }

    if (empty($itemsByUri)) {
      return NULL;
    }

    $childrenCount = [];
    foreach ($itemsByUri as $item) {
      $superUri = trim((string) ($parentUriByItem[$item->uri] ?? ''));
      if ($superUri === '') {
        continue;
      }
      $childrenCount[$superUri] = ($childrenCount[$superUri] ?? 0) + 1;
    }

    if ($nodeUri === $entryPointUri) {
      $root = [];

      foreach ($itemsByUri as $item) {
        $superUri = trim((string) ($parentUriByItem[$item->uri] ?? ''));

        if ($superUri === '') {
          $item->children = !empty($childrenCount[$item->uri]);
          $root[$item->uri] = $item;
          continue;
        }

        if (!isset($itemsByUri[$superUri])) {
          if (!isset($root[$superUri])) {
            $hintLabel = trim((string) ($parentLabelByItem[$item->uri] ?? ''));
            if ($elementtype === 'component') {
              if ($hintLabel === '' && isset($fallbackCategoryLabelByParentUri[$superUri])) {
                $hintLabel = $fallbackCategoryLabelByParentUri[$superUri];
              }
              elseif ($this->isTechnicalCategoryLabel($hintLabel) && isset($fallbackCategoryLabelByParentUri[$superUri])) {
                $hintLabel = $fallbackCategoryLabelByParentUri[$superUri];
              }
            }

            if (($hintLabel === '' || $this->isTechnicalCategoryLabel($hintLabel)) && $superUri !== '') {
              $resolvedHint = $this->resolveLabelFromUriSilently($api, $superUri);
              if ($resolvedHint !== '') {
                $hintLabel = $this->normalizeCategoryLabelByType($resolvedHint, $elementtype);
              }
            }

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
      $superUri = trim((string) ($parentUriByItem[$item->uri] ?? ''));
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
   * Keep Phase I clinical-process hierarchy focused on PMSR process stems.
   *
   * @param array<int, object> $items
   * @return array<int, object>
   */
  private function filterPhase1ClinicalProcessItems(array $items): array {
    $filtered = [];

    foreach ($items as $item) {
      if (!is_object($item) || empty($item->uri)) {
        continue;
      }

      $uri = trim((string) $item->uri);
      $isCategory = !empty($item->isCategory);

      if ($isCategory) {
        // Keep only meaningful category anchors for WKF clinical process selection.
        if ($uri === VSTOI::PROCESS_STEM || str_starts_with($uri, self::PMSR_ONTOLOGY_PREFIX)) {
          $filtered[] = $item;
        }
        continue;
      }

      // Selectable process stems should be PMSR resources.
      if (str_starts_with($uri, self::PMSR_ONTOLOGY_PREFIX)) {
        $filtered[] = $item;
      }
    }

    return !empty($filtered) ? $filtered : $items;
  }

  /**
   * Build the same top-class node pool used by getTopClass().
   */
  private function buildTopClassPool(string $nodeUri): array {
    $api = \Drupal::service('rep.api_connector');

    // Same child retrieval path used by getTopClass.
    $children = $api->parseObjectResponse($api->getChildren($nodeUri), 'getChildren');
    if (!is_array($children)) {
      $children = [];
    }

    // Same mapping merge used by getTopClass.
    $tables = new Tables(\Drupal::database());
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
      if (is_object($item) && isset($item->uri)) {
        $pool[$item->uri] = $item;
      }
    }

    // Keep class entry points deterministic: normalize aliases and synthesize
    // missing required roots so mappings are stable across reload variations.
    if ($nodeUri === self::CLASS_ENTRY_POINT_ROOT) {
      $pool = $this->stabilizeClassEntryPointPool($pool);
    }

    foreach ($pool as $uri => $item) {
      $item->isMapped = (isset($all_mappings[$nodeUri]) && $all_mappings[$nodeUri] === $uri);
    }

    $items = array_values($pool);
    usort($items, function($a, $b) {
      return strcasecmp($a->label, $b->label);
    });

    return $items;
  }

  /**
   * Normalize and complete the class entry point root set.
   */
  private function stabilizeClassEntryPointPool(array $pool): array {
    $normalized = [];

    // 1) Normalize legacy/alternate URIs to canonical ones.
    foreach ($pool as $uri => $item) {
      if (!is_object($item) || empty($uri)) {
        continue;
      }

      $canonicalUri = self::CLASS_ENTRY_POINT_URI_ALIASES[$uri] ?? $uri;
      $localName = $this->localNameFromUri($canonicalUri);
      $canonicalLabel = self::CLASS_ENTRY_POINT_LABELS[$localName] ?? (string) ($item->label ?? $localName);

      $item->uri = $canonicalUri;
      if (empty($item->label) || isset(self::CLASS_ENTRY_POINT_LABELS[$localName])) {
        $item->label = $canonicalLabel;
      }
      if (empty($item->superUri)) {
        $item->superUri = self::CLASS_ENTRY_POINT_ROOT;
      }

      $normalized[$canonicalUri] = $item;
    }

    // 2) Ensure all required class entry points exist.
    foreach (self::REQUIRED_CLASS_ENTRY_POINT_LOCAL_NAMES as $localName) {
      $uri = 'http://hadatac.org/ont/hasco/' . $localName;
      if (isset($normalized[$uri])) {
        continue;
      }

      $normalized[$uri] = (object) [
        'uri' => $uri,
        'label' => self::CLASS_ENTRY_POINT_LABELS[$localName] ?? $localName,
        'superUri' => self::CLASS_ENTRY_POINT_ROOT,
        'typeNamespace' => $uri,
      ];
    }

    return $normalized;
  }

  /**
   * Extracts the local name from a URI (fragment or last path segment).
   */
  private function localNameFromUri(string $uri): string {
    $fragment = parse_url($uri, PHP_URL_FRAGMENT);
    if (is_string($fragment) && $fragment !== '') {
      return $fragment;
    }

    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
      return $uri;
    }

    $parts = explode('/', trim($path, '/'));
    $last = end($parts);
    return is_string($last) && $last !== '' ? $last : $uri;
  }

  /**
   * Load a node's children through the same runtime path used by tree expansion.
   */
  private function loadTreeChildren(string $nodeUri): array {
    $childRequest = Request::create('/rep/getchildren', 'GET', ['nodeUri' => $nodeUri]);
    $childResponse = $this->getChildren($childRequest);
    $decoded = json_decode($childResponse->getContent());

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Checks whether a root URI has at least one descendant.
   *
   * Uses bounded traversal with cycle protection and short-circuits as soon
   * as the first reachable child is found.
   */
  private function hasTreeDescendant(string $rootUri, int $maxDepth = 6, int $maxVisited = 300): bool {
    $visited = [$rootUri => TRUE];
    $queue = [[$rootUri, 0]];
    $visitedCount = 1;

    while (!empty($queue) && $visitedCount <= $maxVisited) {
      [$currentUri, $depth] = array_shift($queue);
      if ($depth >= $maxDepth) {
        continue;
      }

      $children = $this->loadTreeChildren($currentUri);
      foreach ($children as $child) {
        if (!is_object($child) || empty($child->uri)) {
          continue;
        }

        $childUri = (string) $child->uri;
        if (isset($visited[$childUri])) {
          continue;
        }

        // First reachable child proves this entry point is bound.
        return TRUE;
      }

      foreach ($children as $child) {
        if (!is_object($child) || empty($child->uri)) {
          continue;
        }

        $childUri = (string) $child->uri;
        if (isset($visited[$childUri])) {
          continue;
        }

        $visited[$childUri] = TRUE;
        $visitedCount++;
        $queue[] = [$childUri, $depth + 1];
      }
    }

    return FALSE;
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

    // When selecting a Workflow Stem (a.k.a. ProcessStem), prefer manager-owned
    // instances, but fallback to project-wide listing when empty. Also support
    // both entry-point roots used in local deployments.
    //
    // Phase I clinical-process modal expects a grouped hierarchy (not a flat
    // list), so skip this shortcut for that field and let instance hierarchy
    // handling below shape categories/children.
    if (in_array($elementtype, ['processstem', 'workflowstem'], true)
      && in_array($nodeUri, [VSTOI::PROCESS_STEM, EntryPoints::CLASS_EP_PMSR], true)
      && $fieldId !== 'phase1ClinicalProcess') {
      $items = $this->getManagerOwnedItems($elementtype);
      if (!empty($items)) {
        return new JsonResponse($items);
      }

      $items = $this->getKeywordItems($elementtype);
      if (!empty($items)) {
        return new JsonResponse($items);
      }

      return new JsonResponse([]);
    }

    // Context-specific selectors that should prefer manager-owned definitions
    // at class roots, with keyword fallback when manager-scoped list is empty.
    $instanceFieldRoots = [
      'component' => EntryPoints::CLASS_EP_COMPONENT,
      'instrument' => EntryPoints::CLASS_EP_INSTRUMENT,
      'platform' => EntryPoints::CLASS_EP_PLATFORM,
    ];

    // Organize model selectors by category/stem chain in instance selector.
    $instanceHierarchyRoots = [
      'component' => EntryPoints::CLASS_EP_COMPONENT,
      'instrument' => EntryPoints::CLASS_EP_INSTRUMENT,
      'platform' => EntryPoints::CLASS_EP_PLATFORM,
      'workflowstem' => EntryPoints::CLASS_EP_PMSR,
      'processstem' => VSTOI::PROCESS_STEM,
    ];
    if (in_array($fieldId, ['instance_type', 'phase1ClinicalProcess'], true)
      && isset($instanceHierarchyRoots[$elementtype])) {
      $items = $this->getInstanceHierarchyItems($nodeUri, $elementtype, $instanceHierarchyRoots[$elementtype]);
      if ($items !== NULL) {
        if ($fieldId === 'phase1ClinicalProcess' && in_array($elementtype, ['workflowstem', 'processstem'], true)) {
          $items = $this->filterPhase1ClinicalProcessItems($items);
        }
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
      // Build a synthetic fallback node instead of calling /api/uri, because
      // some local graphs expose children for this class but do not resolve the
      // class itself, which would surface a user-facing KG error.
      $sub = $api->parseObjectResponse($api->getChildren(VSTOI::COMPONENT_ATTRIBUTE), 'getChildren');
      $fallback = (object) [
        'uri' => VSTOI::COMPONENT_ATTRIBUTE,
        'label' => 'Component Attribute',
        'superUri' => EntryPoints::CLASS_EP_COMPONENT_ATTRIBUTE,
        'typeNamespace' => VSTOI::COMPONENT_ATTRIBUTE,
        'children' => is_array($sub) && !empty($sub),
      ];
      $children = [$fallback];
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
      if (!is_object($item) || empty($item->uri)) {
        continue;
      }
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

  /**
   * Get list of entry point URIs that have bindings.
   * 
   * Returns an array of entry point URIs that have at least one mapping,
   * either in the database (from interactive mapping) or in the triplestore
   * (from automatic ingestion or RDF data).
   */
  public function getBoundEntryPoints() {
    $bound_entry_points = [];
    
    // 1. Get mappings from database (interactive mappings via MapEntryPointsForm)
    $tables = new Tables(\Drupal::database());
    $all_mappings = $tables->getAllMappings();
    $bound_entry_points = array_keys($all_mappings);
    
    // 2. Discover bound entry points using the same tree-loading flow as the UI:
    // root via getTopClass(), then children via getChildren().
    // This preserves all getChildren() fallback logic (entry-point normalization, etc.).
    try {
      // Entry points are children of hasco:ClassEntryPoint.
      $entry_point_root = 'http://hadatac.org/ont/hasco/ClassEntryPoint';
      $entry_points = $this->buildTopClassPool($entry_point_root);
      
        if (is_array($entry_points)) {
          // For each entry point, walk descendants through the same tree path.
        foreach ($entry_points as $entry_point) {
          if (!isset($entry_point->uri)) {
            continue;
          }
          
          $entry_point_uri = $entry_point->uri;

          // Isolate each entry-point lookup: a single bad branch must not zero all results.
          try {
              $hasDescendant = $this->hasTreeDescendant($entry_point_uri);

              // If this entry point has at least one descendant (child or deeper), it's bound.
              if ($hasDescendant && !in_array($entry_point_uri, $bound_entry_points, TRUE)) {
              $bound_entry_points[] = $entry_point_uri;
            }
          }
          catch (\Throwable $inner) {
            \Drupal::logger('rep')->warning('Skipping entry-point during bound detection: @uri (@error)', [
              '@uri' => $entry_point_uri,
              '@error' => $inner->getMessage(),
            ]);
          }
        }
      }
    } catch (\Exception $e) {
      // Log error but continue with database mappings only
      \Drupal::logger('rep')->error('Error querying triplestore for bound entry points: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return new JsonResponse([
      'bound' => array_values(array_unique($bound_entry_points)),
      'count' => count(array_unique($bound_entry_points)),
    ]);
  }

  public function getTopClass(Request $request) {
    $nodeUri = $request->query->get('nodeUri');
    if ($nodeUri === NULL || trim((string) $nodeUri) === '') {
      $nodeUri = $request->query->get('uri');
    }
    if ($nodeUri === NULL || trim((string) $nodeUri) === '') {
      return new JsonResponse(['error' => 'Missing required query parameter: nodeUri'], 400);
    }

    return new JsonResponse($this->buildTopClassPool($nodeUri));
  }

}
