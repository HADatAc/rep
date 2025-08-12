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
 * Returns:
 *   { nodes:[], edges:[], meta:{} }  (meta only when debug=1)
 *
 * Fast paths:
 *   • Study  -> its collections (sample/subject/space/time) with paging
 *   • SOC    -> its members ("contains") with paging (+ totalGuess if available)
 * For any explicit label, only that property is walked; arrays are paged.
 */
class GraphController extends ControllerBase {

  public function expand(Request $request): JsonResponse {
    $from     = $request->query->get('from') ?? $request->query->get('uri') ?? '';
    $label    = $request->query->get('label');      // e.g., hasVirtualColumn
    $relation = $request->query->get('relation');   // e.g., contains
    $limit    = (int) ($request->query->get('limit')  ?? 50);
    $offset   = (int) ($request->query->get('offset') ?? 0);
    $debug    = (bool) $request->query->get('debug', false);

    if (!$from) {
      return new JsonResponse(['error' => 'Missing "from"'], 400);
    }

    /** @var \Drupal\rep\FusekiAPIConnector $api */
    $api = \Drupal::service('rep.api_connector');

    // Expand ahead:CURIE to full IRI so ids match the frontend cache.
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

    // Determine kind (Study, SOC, Other) — tolerant to "/" or "#".
    $typeUri = $obj->hascoTypeUri ?? $obj->typeUri ?? '';
    $tu = (string) $typeUri;

    $isStudy = ($typeUri === HASCO::STUDY)
      || (bool) preg_match('~hasco[\/#]Study$~', $tu)
      || str_ends_with($tu, 'Study');

    $isSocByType = in_array($typeUri, [
        HASCO::SAMPLE_COLLECTION,
        HASCO::SUBJECT_GROUP,
        HASCO::STUDY_OBJECT_COLLECTION,
        HASCO::SPACE_COLLECTION,
        HASCO::TIME_COLLECTION,
      ], true)
      || (bool) preg_match('~(SampleCollection|SubjectGroup|StudyObjectCollection|SpaceCollection|TimeCollection)$~', $tu);

    // Helper to add a type edge (nice to have for the menu).
    $addTypeEdge = function () use (&$nodes, &$edges, $from, $obj) {
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
    };

    // Helper to try to compute/guess the total number of members for a SOC.
    $getSocTotalGuess = function () use ($api, $from, $obj): ?int {
      // 1) Common fields present on the SOC object itself.
      foreach ([
        'numOfObjects', 'num_objects', 'numOfStudyObjects',
        'collectionSize', 'size', 'count', 'total'
      ] as $prop) {
        if (isset($obj->$prop) && is_numeric($obj->$prop)) {
          return (int) $obj->$prop;
        }
      }

      // 2) Try connector "count" methods (if available).
      foreach ([
        'countSOCMembers', 'getSOCSize', 'getCollectionSize',
        'getStudyObjectCollectionCount', 'countStudyObjects', 'countStudyObjectsFromSOC'
      ] as $m) {
        if (is_callable([$api, $m])) {
          try {
            $res = call_user_func([$api, $m], $from);
            if (is_numeric($res)) {
              return (int) $res;
            }
            if (is_array($res)) {
              // Look for a numeric field.
              foreach (['count','total','size','num','value'] as $k) {
                if (isset($res[$k]) && is_numeric($res[$k])) return (int) $res[$k];
              }
            }
          } catch (\Throwable $e) {
            // ignore and keep trying others
          }
        }
      }

      // 3) SPARQL COUNT fallback.
      $runner = null;
      foreach (['select','sparqlSelect','query','querySelect','runSelect','runQuerySelect','runQuery','querySparql'] as $m) {
        if (is_callable([$api, $m])) { $runner = $m; break; }
      }
      if ($runner) {
        $sparql = "
SELECT (COUNT(?obj) AS ?c) WHERE {
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
}";
        try {
          $rows = $api->$runner($sparql);
          if (isset($rows['results']['bindings'][0]['c']['value'])) {
            return (int) $rows['results']['bindings'][0]['c']['value'];
          }
        } catch (\Throwable $e) {
          // ignore
        }
      }
      return null;
    };

    // -------------------------- FAST PATHS --------------------------

    // 1) STUDY: list its SOCs (paged)
    if ($isStudy && !$relation && !$label) {
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

      $addTypeEdge();
      $meta['count'] = $count;
      $out = ['nodes' => $this->dedupeById($nodes), 'edges' => $this->dedupeById($edges)];
      if ($debug) { $out['meta'] = $meta; }
      return new JsonResponse($out);
    }

    // 2) SOC members (contains). Enter here if:
    //    - relation=contains (preferred by the UI), OR
    //    - label=contains (just in case), OR
    //    - it's a SOC and neither a specific label nor another relation was requested.
    $wantsContains = ($relation === 'contains') || ($label === 'contains') || (!$relation && !$label);
    if ($wantsContains && ($isSocByType || $relation === 'contains' || $label === 'contains')) {
      $meta['mode'] = 'soc';

      $members = [];
      $used    = null;

      // Try connector methods with paging first.
      foreach ([
        'studyObjectsBySOCwithPage',   // many deployments use this (limit, offset OR page)
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

          // Fallback for connectors that treat the 3rd arg as "page number" (not offset)
          if ((empty($parsed) || (is_array($parsed) && count($parsed) === 0)) && $offset > 0) {
            $pageNum = (int) floor($offset / max(1, $limit));
            try {
              $rawMembers2 = call_user_func([$api, $method], $from, $limit, $pageNum);
              $parsed2     = $rawMembers2 ? $api->parseObjectResponse($rawMembers2, $method) : [];
              if (is_array($parsed2) && !empty($parsed2)) {
                $parsed = $parsed2;
              }
            } catch (\Throwable $e) {
              // swallow and continue to next option
            }
          }

          if (is_array($parsed) && !empty($parsed)) {
            $members = $parsed;
            $used    = $method;
            break;
          }
        } catch (\Throwable $e) {
          // keep trying alternatives
        }
      }

      // Fallback SPARQL when connector methods don't return anything.
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

      // Add type edge and totalGuess.
      $addTypeEdge();
      $meta['soc_source']  = $used ?? 'none';
      $guess = $getSocTotalGuess();
      if ($guess !== null) {
        $meta['totalGuess'] = $guess;
      }

      $out = ['nodes' => $this->dedupeById($nodes), 'edges' => $this->dedupeById($edges)];
      if ($debug) { $out['meta'] = $meta; }
      return new JsonResponse($out);
    }

    // ---------------------- LABEL-SPECIFIC / GENERIC ----------------------

    $properties = (array) $obj;

    // Study -> Virtual Columns (paged via array_slice)
    if ($label === 'hasVirtualColumn' && $isStudy) {
      $vcRaw = $api->getStudyVCs($from);
      if ($vcRaw) {
        $vcList = $api->parseObjectResponse($vcRaw, 'getStudyVCs') ?? [];
        $total  = is_array($vcList) ? count($vcList) : 0;
        $slice  = array_slice($vcList, $offset, $limit, true);
        $cnt    = 0;
        foreach ($slice as $vcName => $vcObj) {
          $cnt++;
          $vcId    = !empty($vcObj->uri) ? $expandCurie((string) $vcObj->uri) : 'vc-' . md5((string) $vcName);
          $vcLabel = $vcObj->label ?? (is_string($vcName) ? $vcName : 'Virtual Column');
          $nodes[] = Utils::buildNode($vcId, $vcLabel, $vcObj->typeUri ?? null);
          $edges[] = ['id' => "{$from}_{$vcId}_hasVirtualColumn", 'from' => $from, 'to' => $vcId, 'label' => 'hasVirtualColumn', 'arrows' => 'to'];
        }
        $meta['count']      = $cnt;
        $meta['totalGuess'] = $total;
      }
    }
    // Study -> filter SOCs by desired edge label (paged)
    elseif ($isStudy && in_array($label, ['hasSampleCollection','hasSubjectCollection','hasSpaceCollection','hasTimeCollection'], true)) {
      $socRaw = $api->getStudySOCs($from, $limit, $offset);
      $cnt = 0;
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

          $cnt++;
          $socUri = $expandCurie((string) $soc->uri);
          $nodes[] = Utils::buildNode($socUri, $soc->label ?? Utils::namespaceUri($socUri), $soc->typeUri ?? null);
          $edges[] = ['id' => "{$from}_{$socUri}_{$pred}", 'from' => $from, 'to' => $socUri, 'label' => $pred, 'arrows' => 'to'];
        }
      }
      $meta['count'] = $cnt;
    }
    // Generic walker: only the requested property; arrays paged.
    else {
      if ($label && array_key_exists($label, $properties)) {
        $val = $properties[$label];

        if (is_object($val) && !empty($val->uri)) {
          $childUri = $expandCurie((string) $val->uri);
          $nodes[] = Utils::buildNode($childUri, $val->label ?? Utils::namespaceUri($childUri), $val->typeUri ?? null);
          $edges[] = ['id' => "{$from}_{$childUri}_{$label}", 'from' => $from, 'to' => $childUri, 'label' => $label, 'arrows' => 'to'];
          $meta['count'] = 1;
          $meta['totalGuess'] = 1;
        }
        elseif (is_array($val)) {
          $total = 0;
          foreach ($val as $v) {
            if (is_object($v) && !empty($v->uri)) $total++;
          }
          $i = 0; $added = 0;
          foreach ($val as $v) {
            if (!is_object($v) || empty($v->uri)) continue;
            if ($i++ < $offset) continue;
            if ($added >= $limit) break;

            $childUri = $expandCurie((string) $v->uri);
            $nodes[] = Utils::buildNode($childUri, $v->label ?? Utils::namespaceUri($childUri), $v->typeUri ?? null);
            $edges[] = ['id' => "{$from}_{$childUri}_{$label}", 'from' => $from, 'to' => $childUri, 'label' => $label, 'arrows' => 'to'];
            $added++;
          }
          $meta['count']      = $added;
          $meta['totalGuess'] = $total;
        }
        // literals etc. are ignored for paging
      } else {
        // No explicit label requested: conservative generic walk.
        foreach ($properties as $prop => $val) {
          if (is_object($val) && !empty($val->uri)) {
            $childUri = $expandCurie((string) $val->uri);
            $nodes[] = Utils::buildNode($childUri, $val->label ?? Utils::namespaceUri($childUri), $val->typeUri ?? null);
            $edges[] = ['id' => "{$from}_{$childUri}_{$prop}", 'from' => $from, 'to' => $childUri, 'label' => $prop, 'arrows' => 'to'];
          } elseif (is_array($val)) {
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
