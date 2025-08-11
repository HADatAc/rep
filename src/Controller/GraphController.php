<?php

namespace Drupal\rep\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

/**
 * Lazy-expansion endpoint used by the vis.js canvas.
 *
 * GET /rep/graph/expand?from=<uri>&relation=contains&limit=5&offset=0&debug=1
 *
 * Notes:
 * - Always includes the "from" node in the result.
 * - Provides two fast paths:
 *     • Study  -> returns its collections (sample/subject/space/time) with paging
 *     • SOC    -> returns its members ("contains") with paging
 * - Falls back to generic walking when a specific "label" is requested.
 * - Returns { nodes:[], edges:[], meta:{} } (meta only when debug=1).
 */
class GraphController extends ControllerBase {

  public function expand(Request $request): JsonResponse {
    $from     = $request->query->get('from') ?? $request->query->get('uri') ?? '';
    $label    = $request->query->get('label');      // optional explicit property (e.g., hasVirtualColumn)
    $relation = $request->query->get('relation');   // e.g., "contains" (used by the UI for SOC members)
    $limit    = (int) ($request->query->get('limit')  ?? 50);
    $offset   = (int) ($request->query->get('offset') ?? 0);
    $debug    = (bool) $request->query->get('debug', false);

    if (!$from) {
      return new JsonResponse(['error' => 'Missing "from"'], 400);
    }

    /** @var \Drupal\rep\FusekiAPIConnector $api */
    $api = \Drupal::service('rep.api_connector');

    // Expand ahead:CURIE to full IRI so ids match front-end cache.
    $expandCurie = static function (string $v): string {
      return str_starts_with($v, 'ahead:')
        ? 'http://hadatac.org/ont/arrowhead/' . substr($v, 6)
        : $v;
    };
    $from = $expandCurie($from);

    // Fetch the source object.
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
    $meta  = ['mode' => 'generic'];

    // Always include the origin node.
    $fromLabel = $obj->label ?? Utils::namespaceUri($from);
    $nodes[] = Utils::buildNode($from, $fromLabel, $obj->typeUri ?? ($obj->hascoTypeUri ?? null));

    // Determine kind (Study, SOC, Other). Be tolerant to "/" or "#".
    $typeUri = $obj->hascoTypeUri ?? $obj->typeUri ?? '';
    $tu = (string) $typeUri;

    $isStudy = ($typeUri === HASCO::STUDY)
      || (bool) preg_match('~hasco[\/#]Study$~', $tu)
      || str_ends_with($tu, 'Study');

    $isSoc = in_array($typeUri, [
        HASCO::SAMPLE_COLLECTION,
        HASCO::SUBJECT_GROUP,
        HASCO::STUDY_OBJECT_COLLECTION,
        HASCO::SPACE_COLLECTION,
        HASCO::TIME_COLLECTION,
      ], true)
      || (bool) preg_match('~(SampleCollection|SubjectGroup|StudyObjectCollection|SpaceCollection|TimeCollection)$~', $tu);

    // -------------------------- FAST PATHS --------------------------

    // 1) STUDY: list its SOCs (paged)
    if (!$label && $isStudy) {
      $meta['mode'] = 'study';

      $socRaw = $api->getStudySOCs($from, $limit, $offset);
      $count  = 0;
      if ($socRaw) {
        $socs = $api->parseObjectResponse($socRaw, 'getStudySOCs');
        foreach (($socs ?? []) as $soc) {
          if (empty($soc->uri)) { continue; }
          $count++;

          $pred = match ($soc->typeUri ?? '') {
            HASCO::SAMPLE_COLLECTION => 'hasSampleCollection',
            HASCO::SUBJECT_GROUP,
            HASCO::STUDY_OBJECT_COLLECTION => 'hasSubjectCollection',
            HASCO::SPACE_COLLECTION  => 'hasSpaceCollection',
            HASCO::TIME_COLLECTION   => 'hasTimeCollection',
            default => 'hasCollection',
          };

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
      }

      // Also add a type edge for convenience in the UI.
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

      $meta['count'] = $count; // number returned in *this* page
      $out = ['nodes' => $this->dedupeById($nodes), 'edges' => $this->dedupeById($edges)];
      if ($debug) { $out['meta'] = $meta; }
      return new JsonResponse($out);
    }

    // 2) SOC: list its members (paged). UI calls with relation=contains.
    if (!$label && $isSoc) {
      $meta['mode'] = 'soc';

      // Only handle "contains"-style relations here.
      if (!$relation || $relation === 'contains') {
        $members   = [];
        $used      = null;

        // Try connector methods that support paging first.
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
              $members = $parsed;
              $used    = $method;
              break;
            }
          } catch (\Throwable $e) {
            // swallow and keep trying alternatives
          }
        }

        // If connector methods didn't return, try SPARQL as a last resort.
        if (empty($members)) {
          $runner = null;
          foreach (['select','sparqlSelect','query','querySelect','runSelect','runQuerySelect','runQuery','querySparql'] as $m) {
            if (is_callable([$api, $m])) { $runner = $m; break; }
          }
          if ($runner) {
            $sparql = "
SELECT ?obj ?label ?type WHERE {
  {
    <{$from}> ?p ?obj .
    VALUES ?p {
      <http://hadatac.org/ont/hasco/hasMember>
      <http://hadatac.org/ont/hasco/hasStudyObject>
      <http://hadatac.org/ont/hasco/contains>
      <http://hadatac.org/ont/hasco/hasObject>
      <http://hadatac.org/ont/hasco#hasMember>
      <http://hadatac.org/ont/hasco#hasStudyObject>
      <http://hadatac.org/ont/hasco#contains>
      <http://hadatac.org/ont/hasco#hasObject>
    }
  }
  UNION
  {
    ?obj ?p <{$from}> .
    VALUES ?p {
      <http://hadatac.org/ont/hasco/isMemberOf>
      <http://hadatac.org/ont/hasco/memberOf>
      <http://hadatac.org/ont/hasco#isMemberOf>
      <http://hadatac.org/ont/hasco#memberOf>
    }
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

                  $mUri = $expandCurie($vObj);
                  $nodes[] = Utils::buildNode($mUri, $vLabel ?? Utils::namespaceUri($mUri), $vType);
                  $edges[] = ['id' => "{$from}_{$mUri}_contains", 'from' => $from, 'to' => $mUri, 'label' => 'contains', 'arrows' => 'to'];
                }
                $used = "SPARQL:$runner";
                $meta['count'] = count($rows['results']['bindings']);
              } elseif (is_array($rows)) {
                // Some connectors may return a simplified array.
                $tmpCount = 0;
                foreach ($rows as $r) {
                  $vObj   = is_array($r) ? ($r['obj']   ?? ($r['obj']['value']   ?? null)) : null;
                  $vLabel = is_array($r) ? ($r['label'] ?? ($r['label']['value'] ?? null)) : null;
                  $vType  = is_array($r) ? ($r['type']  ?? ($r['type']['value']  ?? null)) : null;
                  if (!$vObj) continue;

                  $mUri = $expandCurie($vObj);
                  $nodes[] = Utils::buildNode($mUri, $vLabel ?? Utils::namespaceUri($mUri), $vType);
                  $edges[] = ['id' => "{$from}_{$mUri}_contains", 'from' => $from, 'to' => $mUri, 'label' => 'contains', 'arrows' => 'to'];
                  $tmpCount++;
                }
                $used = "SPARQL:$runner";
                $meta['count'] = $tmpCount;
              }
            } catch (\Throwable $e) {
              \Drupal::logger('rep')->warning('SPARQL fallback failed for @uri: @err', ['@uri' => $from, '@err' => $e->getMessage()]);
            }
          }
        }

        // If a connector returned members, add them now.
        if (!empty($members)) {
          $cnt = 0;
          foreach ($members as $m) {
            if (empty($m->uri)) { continue; }
            $cnt++;

            $mUri = $expandCurie((string) $m->uri);
            $nodes[] = Utils::buildNode($mUri, $m->label ?? Utils::namespaceUri($mUri), $m->typeUri ?? null);
            $edges[] = ['id' => "{$from}_{$mUri}_contains", 'from' => $from, 'to' => $mUri, 'label' => 'contains', 'arrows' => 'to'];
          }
          $meta['count'] = $cnt;
        }

        // Also add a type edge for convenience in the UI.
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

        $meta['soc_source'] = $used ?? 'none'; // helpful for debugging
      }

      $out = ['nodes' => $this->dedupeById($nodes), 'edges' => $this->dedupeById($edges)];
      if ($debug) { $out['meta'] = $meta; }
      return new JsonResponse($out);
    }

    // ---------------------- LABEL-SPECIFIC / GENERIC ----------------------

    $properties = (array) $obj;

    // Study -> Virtual Columns
    if ($label === 'hasVirtualColumn' && $isStudy) {
      $vcRaw = $api->getStudyVCs($from);
      if ($vcRaw) {
        $vcList = $api->parseObjectResponse($vcRaw, 'getStudyVCs');
        foreach (($vcList ?? []) as $vcName => $vcObj) {
          $vcId    = !empty($vcObj->uri) ? $expandCurie((string) $vcObj->uri) : 'vc-' . md5((string) $vcName);
          $vcLabel = $vcObj->label ?? (is_string($vcName) ? $vcName : 'Virtual Column');
          $nodes[] = Utils::buildNode($vcId, $vcLabel, $vcObj->typeUri ?? null);
          $edges[] = ['id' => "{$from}_{$vcId}_hasVirtualColumn", 'from' => $from, 'to' => $vcId, 'label' => 'hasVirtualColumn', 'arrows' => 'to'];
        }
      }
    }
    // Study -> Filter SOCs by desired edge label (sample/subject/space/time)
    elseif ($isStudy && in_array($label, ['hasSampleCollection','hasSubjectCollection','hasSpaceCollection','hasTimeCollection'], true)) {
      $socRaw = $api->getStudySOCs($from, 1000, 0);
      if ($socRaw) {
        $socs = $api->parseObjectResponse($socRaw, 'getStudySOCs');
        foreach (($socs ?? []) as $soc) {
          if (empty($soc->uri)) { continue; }

          $pred = match ($soc->typeUri ?? '') {
            HASCO::SAMPLE_COLLECTION => 'hasSampleCollection',
            HASCO::SUBJECT_GROUP,
            HASCO::STUDY_OBJECT_COLLECTION => 'hasSubjectCollection',
            HASCO::SPACE_COLLECTION  => 'hasSpaceCollection',
            HASCO::TIME_COLLECTION   => 'hasTimeCollection',
            default => 'hasCollection',
          };
          if ($label !== $pred) { continue; }

          $socUri = $expandCurie((string) $soc->uri);
          $nodes[] = Utils::buildNode($socUri, $soc->label ?? Utils::namespaceUri($socUri), $soc->typeUri ?? null);
          $edges[] = ['id' => "{$from}_{$socUri}_{$pred}", 'from' => $from, 'to' => $socUri, 'label' => $pred, 'arrows' => 'to'];
        }
      }
    }
    // Generic walker: traverse object properties (and arrays of objects).
    else {
      foreach ($properties as $prop => $val) {
        if ($label && $prop !== $label) { continue; }

        if (is_object($val) && !empty($val->uri)) {
          $childUri = $expandCurie((string) $val->uri);
          $nodes[] = Utils::buildNode($childUri, $val->label ?? Utils::namespaceUri($childUri), $val->typeUri ?? null);
          $edges[] = ['id' => "{$from}_{$childUri}_{$prop}", 'from' => $from, 'to' => $childUri, 'label' => $prop, 'arrows' => 'to'];
        }
        elseif (is_array($val)) {
          foreach ($val as $v) {
            if (is_object($v) && !empty($v->uri)) {
              $childUri = $expandCurie((string) $v->uri);
              $nodes[] = Utils::buildNode($childUri, $v->label ?? Utils::namespaceUri($childUri), $v->typeUri ?? null);
              $edges[] = ['id' => "{$from}_{$childUri}_{$prop}", 'from' => $from, 'to' => $childUri, 'label' => $prop, 'arrows' => 'to'];
            }
          }
        }
      }
    }

    // Add a type edge when available.
    if (!empty($obj->typeUri)) {
      $nodes[] = Utils::buildNode(
        $obj->typeUri,
        ucfirst($obj->hascoTypeLabel ?? $obj->typeLabel ?? 'Type'),
        $obj->typeUri
      );
      $edges[] = ['id' => "{$from}_{$obj->typeUri}_hascoTypeUri", 'from' => $from, 'to' => $obj->typeUri, 'label' => 'hascoTypeUri', 'arrows' => 'to'];
    }

    $out = [
      'nodes' => $this->dedupeById($nodes),
      'edges' => $this->dedupeById($edges),
    ];
    if ($debug) { $out['meta'] = $meta; }
    return new JsonResponse($out);
  }

  /**
   * Deduplicate array of node/edge arrays by 'id', keeping the last occurrence.
   *
   * @param array $items Each item is an associative array with at least 'id'
   * @return array
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
