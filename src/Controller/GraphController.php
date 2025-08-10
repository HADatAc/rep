<?php

namespace Drupal\rep\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

/**
 * Returns incremental nodes/edges for on-demand graph expansion.
 *
 * GET /rep/graph/expand?from=<uri>&label=<predicate_optional>&limit=100&offset=0&debug=1&broad=1
 *
 * Behavior:
 *  - If "label" is provided → expands only that predicate (generic walker) + type edge.
 *  - If no "label":
 *      • Study → returns its SOCs (hasSampleCollection / hasSubjectCollection / hasCollection).
 *      • SOC   → returns its contained members with edge label "contains".
 *      • Else  → generic walker over object-URI properties.
 *  - Always includes the origin node (client will dedupe).
 *  - Edges are deduped by id = from_to_label.
 *
 * Diagnostics:
 *  - Use ?debug=1 to get a "meta" object in the response with mode, counts, source, etc.
 *  - Use ?broad=1 to enable a very permissive SPARQL fallback for SOCs (temporary safety net).
 */
class GraphController extends ControllerBase {

  /**
   * Main endpoint used by the vis.js behavior to expand a node lazily.
   */
  public function expand(Request $request): JsonResponse {
    // Accept both "from" (preferred) and "uri" (back-compat with older callers).
    $from   = $request->query->get('from') ?? $request->query->get('uri');
    $label  = $request->query->get('label');                // optional predicate filter
    $limit  = (int) ($request->query->get('limit') ?? 100);
    $offset = (int) ($request->query->get('offset') ?? 0);
    $debug  = (bool) $request->query->get('debug', false);  // ?debug=1 → returns meta
    $broad  = (bool) $request->query->get('broad', false);  // ?broad=1 → broad SPARQL for SOC

    if (empty($from)) {
      return new JsonResponse(['error' => 'Missing "from"'], 400);
    }

    /** @var object $api Connector service (Fuseki/whatever your backend is) */
    $api = \Drupal::service('rep.api_connector');

    // Helper: expand CURIEs like "ahead:XYZ" to full HTTP IRIs.
    $expandCurie = static function (string $v): string {
      if (str_starts_with($v, 'ahead:')) {
        return 'http://hadatac.org/ont/arrowhead/' . substr($v, 6);
      }
      return $v;
    };
    // Normalize incoming "from" too (sometimes a CURIE sneaks in).
    if (str_starts_with($from, 'ahead:')) {
      $from = $expandCurie($from);
    }

    // Pull the base resource (we need type + label to decide how to expand).
    $raw = $api->getUri($from);
    if (!$raw) {
      return new JsonResponse(['nodes' => [], 'edges' => []]);
    }
    $obj = json_decode($raw);
    if (!$obj) {
      return new JsonResponse(['nodes' => [], 'edges' => []]);
    }

    $nodes = [];
    $edges = [];
    $meta  = ['mode' => 'generic']; // helpful diagnostic payload when ?debug=1

    // Always include origin node (the client code will dedupe).
    $fromLabel = $obj->label ?? Utils::namespaceUri($from);
    $nodes[] = Utils::buildNode($from, $fromLabel, $obj->typeUri ?? ($obj->hascoTypeUri ?? null));

    // Decide the kind of node we're expanding.
    $typeUri = $obj->hascoTypeUri ?? $obj->typeUri ?? null;

    // Be a bit permissive (string match) in case the backend doesn't set constants exactly.
    $t = (string) ($typeUri ?? '');
    $isStudy = ($typeUri === HASCO::STUDY) || str_contains($t, '/hasco/Study');
    $isSoc   = in_array($typeUri, [
      HASCO::SAMPLE_COLLECTION,
      HASCO::SUBJECT_GROUP,
      HASCO::STUDY_OBJECT_COLLECTION,
      HASCO::SPACE_COLLECTION,
      HASCO::TIME_COLLECTION,
    ], true) || str_contains($t, 'SampleCollection') || str_contains($t, 'StudyObjectCollection')
       || str_contains($t, 'SubjectGroup') || str_contains($t, 'SpaceCollection') || str_contains($t, 'TimeCollection');

    // ---------------- Domain fast paths (no explicit predicate requested) ----------------
    if (!$label) {
      // STUDY → list SOCs
      if ($isStudy) {
        $meta['mode'] = 'study';

        $socRaw = $api->getStudySOCs($from, $limit, $offset);
        if ($socRaw) {
          $socs = $api->parseObjectResponse($socRaw, 'getStudySOCs');
          foreach (($socs ?? []) as $soc) {
            if (empty($soc->uri)) { continue; }

            // Choose predicate by SOC type.
            $pred =
              (($soc->typeUri ?? null) === HASCO::SAMPLE_COLLECTION) ? 'hasSampleCollection' :
              (in_array(($soc->typeUri ?? null), [HASCO::SUBJECT_GROUP, HASCO::STUDY_OBJECT_COLLECTION], true)
                ? 'hasSubjectCollection'
                : 'hasCollection');

            $socUri = $expandCurie((string) $soc->uri);
            $nodes[] = Utils::buildNode(
              $socUri,
              $soc->label ?? Utils::namespaceUri($socUri),
              $soc->typeUri ?? null
            );
            $edges[] = [
              'id'     => "{$from}_{$socUri}_{$pred}",
              'from'   => $from,
              'to'     => $socUri,
              'label'  => $pred,
              'arrows' => 'to',
            ];
          }
          $meta['study_soc_count'] = is_countable($socs) ? count($socs) : 0;
        }

        // Type edge (nice to have for the "hascoTypeUri" toggle in the UI).
        if (!empty($obj->typeUri)) {
          $nodes[] = Utils::buildNode(
            $obj->typeUri,
            ucfirst($obj->hascoTypeLabel ?? $obj->typeLabel ?? 'Type'),
            $obj->typeUri
          );
          $edges[] = [
            'id'     => "{$from}_{$obj->typeUri}_hascoTypeUri",
            'from'   => $from,
            'to'     => $obj->typeUri,
            'label'  => 'hascoTypeUri',
            'arrows' => 'to',
          ];
        }

        $out = ['nodes' => $this->dedupeById($nodes), 'edges' => $this->dedupeById($edges)];
        if ($debug) { $out['meta'] = $meta; }
        return new JsonResponse($out);
      }

      // SOC → list contained members (“contains”), with multiple fallbacks.
      if ($isSoc) {
        $meta['mode'] = 'soc';
        $members    = [];
        $usedMethod = null;

        // 1) Try several connector method names (projects differ here).
        foreach ([
          'getSOCObjects',
          'getSOCMembers',
          'getStudyObjectsFromSOC',
          'getCollectionMembers',
          'getStudyObjectCollectionMembers',
          'getMembers',
          'getStudyObjects',
        ] as $method) {
          if (!is_callable([$api, $method])) { continue; }
          try {
            $rawMembers = call_user_func([$api, $method], $from, $limit, $offset);
            $parsed     = $rawMembers ? $api->parseObjectResponse($rawMembers, $method) : [];
            if (is_array($parsed) && !empty($parsed)) {
              $members    = $parsed;
              $usedMethod = $method;
              break;
            }
          } catch (\Throwable $e) {
            // Keep trying other method names.
          }
        }

        // 2) Fallback: common arrays on the SOC object itself (sometimes already embedded).
        if (empty($members) && is_object($obj)) {
          foreach (['members', 'hasMember', 'hasStudyObject', 'objects', 'items', 'studyObjects'] as $key) {
            if (!empty($obj->{$key}) && is_array($obj->{$key})) {
              $members    = $obj->{$key};
              $usedMethod = "object.$key";
              break;
            }
          }
        }

        // 3) SPARQL fallback (narrow) if still empty.
        if (empty($members)) {
          // Pick a SPARQL runner that exists in your connector.
          $runner = null;
          foreach ([
            'select','sparqlSelect','query','querySelect','runSelect',
            'runQuerySelect','runQuery','querySparql'
          ] as $m) {
            if (is_callable([$api, $m])) { $runner = $m; break; }
          }

          if ($runner) {
            // Look for members linked by hasMember/hasStudyObject/contains/hasObject
            // or the inverse isMemberOf/memberOf.
            $sparql = "
PREFIX hasco: <http://hadatac.org/ont/hasco/>
PREFIX rdfs:  <http://www.w3.org/2000/01/rdf-schema#>
SELECT ?obj ?label ?type WHERE {
  {
    <{$from}> ?p ?obj .
    VALUES ?p { hasco:hasMember hasco:hasStudyObject hasco:contains hasco:hasObject }
  }
  UNION
  {
    ?obj ?p <{$from}> .
    VALUES ?p { hasco:isMemberOf hasco:memberOf }
  }
  OPTIONAL { ?obj rdfs:label ?label }
  OPTIONAL { ?obj a ?type }
}
LIMIT {$limit} OFFSET {$offset}
";
            try {
              $rows = $api->$runner($sparql);

              if (isset($rows['results']['bindings']) && is_array($rows['results']['bindings'])) {
                foreach ($rows['results']['bindings'] as $b) {
                  $vObj   = $b['obj']['value']   ?? null;
                  $vLabel = $b['label']['value'] ?? null;
                  $vType  = $b['type']['value']  ?? null;
                  if (!$vObj) { continue; }

                  $mUri = str_starts_with($vObj, 'ahead:')
                    ? 'http://hadatac.org/ont/arrowhead/' . substr($vObj, 6)
                    : $vObj;

                  $nodes[] = Utils::buildNode($mUri, $vLabel ?? Utils::namespaceUri($mUri), $vType);
                  $edges[] = ['id' => "{$from}_{$mUri}_contains", 'from' => $from, 'to' => $mUri, 'label' => 'contains', 'arrows' => 'to'];
                }
                $usedMethod = "SPARQL:$runner";
              } elseif (is_array($rows)) {
                foreach ($rows as $r) {
                  $vObj   = is_array($r) ? ($r['obj']   ?? ($r['obj']['value']   ?? null)) : null;
                  $vLabel = is_array($r) ? ($r['label'] ?? ($r['label']['value'] ?? null)) : null;
                  $vType  = is_array($r) ? ($r['type']  ?? ($r['type']['value']  ?? null)) : null;
                  if (!$vObj) { continue; }

                  $mUri = str_starts_with($vObj, 'ahead:')
                    ? 'http://hadatac.org/ont/arrowhead/' . substr($vObj, 6)
                    : $vObj;

                  $nodes[] = Utils::buildNode($mUri, $vLabel ?? Utils::namespaceUri($mUri), $vType);
                  $edges[] = ['id' => "{$from}_{$mUri}_contains", 'from' => $from, 'to' => $mUri, 'label' => 'contains', 'arrows' => 'to'];
                }
                $usedMethod = "SPARQL:$runner";
              }
            } catch (\Throwable $e) {
              \Drupal::logger('rep')->warning(
                'SPARQL fallback failed for @uri: @err',
                ['@uri' => $from, '@err' => $e->getMessage()]
              );
            }
          }
        }

        // 4) Broad SPARQL fallback (VERY permissive) only if explicitly requested (?broad=1).
        if (empty($members) && $broad) {
          $runner = null;
          foreach (['select','sparqlSelect','query','querySelect','runSelect','runQuerySelect','runQuery','querySparql'] as $m) {
            if (is_callable([$api, $m])) { $runner = $m; break; }
          }
          if ($runner) {
            $sparql = "
SELECT ?obj ?label ?type WHERE {
  {
    <{$from}> ?p ?obj .
    FILTER(isIRI(?obj))
  }
  UNION
  {
    ?obj ?p <{$from}> .
    FILTER(isIRI(?obj))
  }
  OPTIONAL { ?obj <http://www.w3.org/2000/01/rdf-schema#label> ?label }
  OPTIONAL { ?obj a ?type }
}
LIMIT {$limit} OFFSET {$offset}
";
            try {
              $rows = $api->$runner($sparql);

              if (isset($rows['results']['bindings']) && is_array($rows['results']['bindings'])) {
                foreach ($rows['results']['bindings'] as $b) {
                  $vObj   = $b['obj']['value']   ?? null;
                  $vLabel = $b['label']['value'] ?? null;
                  $vType  = $b['type']['value']  ?? null;
                  if (!$vObj) continue;

                  $mUri = str_starts_with($vObj, 'ahead:')
                    ? 'http://hadatac.org/ont/arrowhead/' . substr($vObj, 6)
                    : $vObj;

                  $nodes[] = Utils::buildNode($mUri, $vLabel ?? Utils::namespaceUri($mUri), $vType);
                  $edges[] = ['id' => "{$from}_{$mUri}_contains", 'from' => $from, 'to' => $mUri, 'label' => 'contains', 'arrows' => 'to'];
                }
                $usedMethod = "SPARQL:broad:$runner";
              } elseif (is_array($rows)) {
                foreach ($rows as $r) {
                  $vObj   = is_array($r) ? ($r['obj']   ?? ($r['obj']['value']   ?? null)) : null;
                  $vLabel = is_array($r) ? ($r['label'] ?? ($r['label']['value'] ?? null)) : null;
                  $vType  = is_array($r) ? ($r['type']  ?? ($r['type']['value']  ?? null)) : null;
                  if (!$vObj) continue;

                  $mUri = str_starts_with($vObj, 'ahead:')
                    ? 'http://hadatac.org/ont/arrowhead/' . substr($vObj, 6)
                    : $vObj;

                  $nodes[] = Utils::buildNode($mUri, $vLabel ?? Utils::namespaceUri($mUri), $vType);
                  $edges[] = ['id' => "{$from}_{$mUri}_contains", 'from' => $from, 'to' => $mUri, 'label' => 'contains', 'arrows' => 'to'];
                }
                $usedMethod = "SPARQL:broad:$runner";
              }
            } catch (\Throwable $e) {
              \Drupal::logger('rep')->notice('Broad SPARQL failed @uri: @err', ['@uri' => $from, '@err' => $e->getMessage()]);
            }
          }
        }

        // Type edge (hascoTypeUri)
        if (!empty($obj->typeUri)) {
          $nodes[] = Utils::buildNode(
            $obj->typeUri,
            ucfirst($obj->hascoTypeLabel ?? $obj->typeLabel ?? 'Type'),
            $obj->typeUri
          );
          $edges[] = [
            'id'     => "{$from}_{$obj->typeUri}_hascoTypeUri",
            'from'   => $from,
            'to'     => $obj->typeUri,
            'label'  => 'hascoTypeUri',
            'arrows' => 'to',
          ];
        }

        // Diagnostics for the Network tab (Preview → meta.*)
        $meta['soc_source']        = $usedMethod ?? 'none';
        $meta['soc_members_count'] = count(array_filter($edges, fn($e) => ($e['label'] ?? '') === 'contains'));
        $meta['api_methods']       = get_class_methods($api);
        if ($debug && empty($members)) {
          // Helps you see which properties the base object already exposes.
          $meta['object_keys'] = array_keys((array) $obj);
        }

        $out = ['nodes' => $this->dedupeById($nodes), 'edges' => $this->dedupeById($edges)];
        if ($debug) { $out['meta'] = $meta; }
        return new JsonResponse($out);
      }
    }
    // ---------------- End domain fast paths ----------------

    // ---------------- Explicit label or generic walker ----------------
    $properties = (array) $obj;

    // Study → Virtual Columns (on-demand)
    if ($label === 'hasVirtualColumn' && ($obj->hascoTypeUri ?? null) === HASCO::STUDY) {
      $vcRaw = $api->getStudyVCs($from);
      if ($vcRaw) {
        $vcList = $api->parseObjectResponse($vcRaw, 'getStudyVCs');
        foreach (($vcList ?? []) as $vcName => $vcObj) {
          $vcId    = !empty($vcObj->uri) ? $expandCurie((string) $vcObj->uri) : 'vc-' . md5((string) $vcName);
          $vcLabel = $vcObj->label ?? (is_string($vcName) ? $vcName : 'Virtual Column');
          $nodes[] = Utils::buildNode($vcId, $vcLabel, $vcObj->typeUri ?? null);
          $edges[] = [
            'id'     => "{$from}_{$vcId}_hasVirtualColumn",
            'from'   => $from,
            'to'     => $vcId,
            'label'  => 'hasVirtualColumn',
            'arrows' => 'to',
          ];
        }
      }
    }
    // Study → SOCs filtered by requested label
    elseif (in_array($label, ['hasSampleCollection','hasSubjectCollection'], true)
      && ($obj->hascoTypeUri ?? null) === HASCO::STUDY) {

      $socRaw = $api->getStudySOCs($from, 1000, 0);
      if ($socRaw) {
        $socs = $api->parseObjectResponse($socRaw, 'getStudySOCs');
        foreach (($socs ?? []) as $soc) {
          if (empty($soc->uri)) { continue; }
          $pred = ($soc->typeUri === HASCO::SAMPLE_COLLECTION) ? 'hasSampleCollection' : 'hasSubjectCollection';
          if ($label === $pred) {
            $socUri = $expandCurie((string) $soc->uri);
            $nodes[] = Utils::buildNode($socUri, $soc->label ?? Utils::namespaceUri($socUri), $soc->typeUri ?? null);
            $edges[] = [
              'id'     => "{$from}_{$socUri}_{$pred}",
              'from'   => $from,
              'to'     => $socUri,
              'label'  => $pred,
              'arrows' => 'to',
            ];
          }
        }
      }
    }
    // Generic walker over object-URI-ish properties
    else {
      foreach ($properties as $prop => $val) {
        if ($label && $prop !== $label) { continue; }

        if (is_object($val) && !empty($val->uri)) {
          $childUri = $expandCurie((string) $val->uri);
          $nodes[] = Utils::buildNode($childUri, $val->label ?? Utils::namespaceUri($childUri), $val->typeUri ?? null);
          $edges[] = [
            'id'     => "{$from}_{$childUri}_{$prop}",
            'from'   => $from,
            'to'     => $childUri,
            'label'  => $prop,
            'arrows' => 'to',
          ];
        }
        elseif (is_array($val)) {
          foreach ($val as $v) {
            if (is_object($v) && !empty($v->uri)) {
              $childUri = $expandCurie((string) $v->uri);
              $nodes[] = Utils::buildNode($childUri, $v->label ?? Utils::namespaceUri($childUri), $v->typeUri ?? null);
              $edges[] = [
                'id'     => "{$from}_{$childUri}_{$prop}",
                'from'   => $from,
                'to'     => $childUri,
                'label'  => $prop,
                'arrows' => 'to',
              ];
            }
            // If you also want to accept raw URI strings in arrays, uncomment:
            // elseif (is_string($v) && (str_starts_with($v, 'http://') || str_starts_with($v, 'https://'))) {
            //   $nodes[] = Utils::buildNode($v, Utils::namespaceUri($v), null);
            //   $edges[] = ['id' => "{$from}_{$v}_{$prop}", 'from' => $from, 'to' => $v, 'label' => $prop, 'arrows' => 'to'];
            // }
          }
        }
      }
    }

    // Always add a type edge when available.
    if (!empty($obj->typeUri)) {
      $nodes[] = Utils::buildNode(
        $obj->typeUri,
        ucfirst($obj->hascoTypeLabel ?? $obj->typeLabel ?? 'Type'),
        $obj->typeUri
      );
      $edges[] = [
        'id'     => "{$from}_{$obj->typeUri}_hascoTypeUri",
        'from'   => $from,
        'to'     => $obj->typeUri,
        'label'  => 'hascoTypeUri',
        'arrows' => 'to',
      ];
    }

    $out = [
      'nodes' => $this->dedupeById($nodes),
      'edges' => $this->dedupeById($edges),
    ];
    if ($debug) { $out['meta'] = $meta; }
    return new JsonResponse($out);
  }

  /**
   * Dedupe a list of associative arrays by 'id'.
   * Keeps the last occurrence for each id.
   */
  private function dedupeById(array $items): array {
    $acc = [];
    foreach ($items as $it) {
      if (!is_array($it)) { continue; }
      $id = $it['id'] ?? null;
      if ($id) { $acc[$id] = $it; }
    }
    return array_values($acc);
  }
}
