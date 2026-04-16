<?php

namespace Drupal\rep\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class GraphController extends ControllerBase {

  public function expand(Request $request): JsonResponse {
    $from     = $request->query->get('from') ?? $request->query->get('uri') ?? '';
    $label    = $request->query->get('label');
    $relation = $request->query->get('relation');
    $limit    = (int) ($request->query->get('limit')  ?? 50);
    $offset   = (int) ($request->query->get('offset') ?? 0);
    $debug    = (bool) $request->query->get('debug', false);

    if (!$from) { return new JsonResponse(['error' => 'Missing "from"'], 400); }

    /** @var \Drupal\rep\FusekiAPIConnector $api */
    $api = \Drupal::service('rep.api_connector');

    $expandCurie = static function (string $v): string {
      return str_starts_with($v, 'ahead:')
        ? 'http://hadatac.org/ont/arrowhead/' . substr($v, 6)
        : $v;
    };

    $looksLikeUri = static function ($v): bool {
      if (!is_string($v)) return false;
      $s = trim($v);
      if ($s === '') return false;
      if (str_starts_with($s, 'ahead:')) return true;
      if (str_starts_with($s, 'http://') || str_starts_with($s, 'https://')) return true;
      if (preg_match('/^urn:/i', $s)) return true;
      return false;
    };

    $from = $expandCurie($from);

    try {
      $raw = $api->getUri($from);
      if (!$raw) {
        return new JsonResponse(['nodes' => [], 'edges' => [], 'meta' => ['error' => 'Element not found']]);
      }
      $obj = $api->parseObjectResponse($raw, 'getUri');
      if (!$obj || !is_object($obj)) {
        return new JsonResponse(['nodes' => [], 'edges' => [], 'meta' => ['error' => 'Invalid element payload']]);
      }
    }
    catch (\Throwable $e) {
      // Never throw 500 to the frontend graph: return an empty payload so the UI keeps working.
      $out = ['nodes' => [], 'edges' => []];
      if ($debug) {
        $out['meta'] = [
          'error' => 'Service unavailable',
          'message' => $e->getMessage(),
        ];
      }
      return new JsonResponse($out);
    }

    $nodes = [];
    $edges = [];
    $meta  = ['mode' => 'generic'];

    $fromLabel = $obj->label ?? Utils::namespaceUri($from);
    $nodes[] = Utils::buildNode($from, $fromLabel, $obj->typeUri ?? ($obj->hascoTypeUri ?? null));

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
    ], true) || (bool) preg_match('~(SampleCollection|SubjectGroup|StudyObjectCollection|SpaceCollection|TimeCollection)$~', $tu);

    // ---- add BOTH type edges when available ----
    $addTypeEdges = function () use (&$nodes, &$edges, $from, $obj, $expandCurie) {
      // hascoTypeUri
      if (!empty($obj->hascoTypeUri)) {
        $t = $expandCurie((string) $obj->hascoTypeUri);
        $nodes[] = Utils::buildNode($t, ucfirst($obj->hascoTypeLabel ?? 'HASCO Type'), $t);
        $edges[] = [
          'id'     => "{$from}_{$t}_hascoTypeUri",
          'from'   => $from,
          'to'     => $t,
          'label'  => 'hascoTypeUri',
          'arrows' => 'to',
        ];
      }
      // typeUri
      if (!empty($obj->typeUri)) {
        $t2 = $expandCurie((string) $obj->typeUri);
        $nodes[] = Utils::buildNode($t2, ucfirst($obj->typeLabel ?? 'Type'), $t2);
        $edges[] = [
          'id'     => "{$from}_{$t2}_typeUri",
          'from'   => $from,
          'to'     => $t2,
          'label'  => 'typeUri',
          'arrows' => 'to',
        ];
      }
    };

    // ---- helper for SOC total (igual ao anterior) ----
    $getSocTotalGuess = function () use ($api, $from, $obj): ?int {
      foreach (['numOfObjects','num_objects','numOfStudyObjects','collectionSize','size','count','total'] as $p) {
        if (isset($obj->$p) && is_numeric($obj->$p)) return (int) $obj->$p;
      }
      foreach (['countSOCMembers','getSOCSize','getCollectionSize','getStudyObjectCollectionCount','countStudyObjects','countStudyObjectsFromSOC'] as $m) {
        if (is_callable([$api,$m])) {
          try {
            $res = call_user_func([$api,$m], $from);
            if (is_numeric($res)) return (int) $res;
            if (is_array($res)) foreach (['count','total','size','num','value'] as $k) if (isset($res[$k]) && is_numeric($res[$k])) return (int) $res[$k];
          } catch (\Throwable $e) {}
        }
      }
      $runner = null;
      foreach (['select','sparqlSelect','query','querySelect','runSelect','runQuerySelect','runQuery','querySparql'] as $m) if (is_callable([$api,$m])) { $runner=$m; break; }
      if ($runner) {
        $sparql = "
SELECT (COUNT(?obj) AS ?c) WHERE {
  { <{$from}> ?p ?obj . VALUES ?p {
    <http://hadatac.org/ont/hasco/hasMember>
    <http://hadatac.org/ont/hasco/hasStudyObject>
    <http://hadatac.org/ont/hasco/contains>
    <http://hadatac.org/ont/hasco/hasObject>
    <http://hadatac.org/ont/hasco#hasMember>
    <http://hadatac.org/ont/hasco#hasStudyObject>
    <http://hadatac.org/ont/hasco#contains>
    <http://hadatac.org/ont/hasco#hasObject> } }
  UNION
  { ?obj ?p <{$from}> . VALUES ?p {
    <http://hadatac.org/ont/hasco/isMemberOf>
    <http://hadatac.org/ont/hasco/memberOf>
    <http://hadatac.org/ont/hasco#isMemberOf>
    <http://hadatac.org/ont/hasco#memberOf> } }
}";
        try {
          $rows = $api->$runner($sparql);
          if (isset($rows['results']['bindings'][0]['c']['value'])) return (int) $rows['results']['bindings'][0]['c']['value'];
        } catch (\Throwable $e) {}
      }
      return null;
    };

    // ---------------- FAST PATHS ----------------

    // Study -> SOCs
    if ($isStudy && !$relation && !$label) {
      $meta['mode'] = 'study';
      $socRaw = $api->getStudySOCs($from, $limit, $offset);
      $count  = 0;
      if ($socRaw) {
        $socs = $api->parseObjectResponse($socRaw, 'getStudySOCs');
        foreach (($socs ?? []) as $soc) {
          if (empty($soc->uri)) continue;
          $count++;
          $pred = match ($soc->typeUri ?? '') {
            HASCO::SAMPLE_COLLECTION => 'hasSampleCollection',
            HASCO::SUBJECT_GROUP,
            HASCO::STUDY_OBJECT_COLLECTION => 'hasSubjectCollection',
            HASCO::SPACE_COLLECTION => 'hasSpaceCollection',
            HASCO::TIME_COLLECTION  => 'hasTimeCollection',
            default => 'hasCollection',
          };
          $socUri = $expandCurie((string) $soc->uri);
          $nodes[] = Utils::buildNode($socUri, $soc->label ?? Utils::namespaceUri($socUri), $soc->typeUri ?? null);
          $edges[] = ['id' => "{$from}_{$socUri}_{$pred}", 'from' => $from, 'to' => $socUri, 'label' => $pred, 'arrows' => 'to'];
        }
      }
      $addTypeEdges();
      $meta['count'] = $count;
      $out = ['nodes' => $this->dedupeById($nodes), 'edges' => $this->dedupeById($edges)];
      if ($debug) $out['meta'] = $meta;
      return new JsonResponse($out);
    }

    // SOC -> contains
    $wantsContains = ($relation === 'contains') || ($label === 'contains') || (!$relation && !$label);
    if ($wantsContains && ($isSocByType || $relation === 'contains' || $label === 'contains')) {
      $meta['mode'] = 'soc';

      $members = [];
      $used = null;

      foreach ([
        'studyObjectsBySOCwithPage','getSOCObjects','getSOCMembers','getStudyObjectsFromSOC',
        'getCollectionMembers','getStudyObjectCollectionMembers','getMembers','getStudyObjects',
      ] as $method) {
        if (!is_callable([$api, $method])) continue;
        try {
          $rawMembers = call_user_func([$api, $method], $from, $limit, $offset);
          $parsed = $rawMembers ? $api->parseObjectResponse($rawMembers, $method) : [];
          if ((empty($parsed) || (is_array($parsed) && count($parsed) === 0)) && $offset > 0) {
            $pageNum = (int) floor($offset / max(1, $limit));
            try {
              $rawMembers2 = call_user_func([$api, $method], $from, $limit, $pageNum);
              $parsed2 = $rawMembers2 ? $api->parseObjectResponse($rawMembers2, $method) : [];
              if (is_array($parsed2) && !empty($parsed2)) $parsed = $parsed2;
            } catch (\Throwable $e) {}
          }
          if (is_array($parsed) && !empty($parsed)) { $members = $parsed; $used = $method; break; }
        } catch (\Throwable $e) {}
      }

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
                $vObj = $b['obj']['value'] ?? null;
                $vLabel = $b['label']['value'] ?? null;
                $vType = $b['type']['value'] ?? null;
                if (!$vObj) continue;
                $mUri = $expandCurie($vObj);
                $nodes[] = Utils::buildNode($mUri, $vLabel ?? Utils::namespaceUri($mUri), $vType);
                $edges[] = ['id' => "{$from}_{$mUri}_contains", 'from' => $from, 'to' => $mUri, 'label' => 'contains', 'arrows' => 'to'];
              }
              $used = "SPARQL:$runner";
              $meta['count'] = count($rows['results']['bindings']);
            } elseif (is_array($rows)) {
              $tmp = 0;
              foreach ($rows as $r) {
                $vObj = is_array($r) ? ($r['obj'] ?? ($r['obj']['value'] ?? null)) : null;
                $vLabel = is_array($r) ? ($r['label'] ?? ($r['label']['value'] ?? null)) : null;
                $vType = is_array($r) ? ($r['type'] ?? ($r['type']['value'] ?? null)) : null;
                if (!$vObj) continue;
                $mUri = $expandCurie($vObj);
                $nodes[] = Utils::buildNode($mUri, $vLabel ?? Utils::namespaceUri($mUri), $vType);
                $edges[] = ['id' => "{$from}_{$mUri}_contains", 'from' => $from, 'to' => $mUri, 'label' => 'contains', 'arrows' => 'to'];
                $tmp++;
              }
              $used = "SPARQL:$runner"; $meta['count'] = $tmp;
            }
          } catch (\Throwable $e) {
            \Drupal::logger('rep')->warning('SPARQL fallback failed for @uri: @err', ['@uri' => $from, '@err' => $e->getMessage()]);
          }
        }
      }

      if (!empty($members)) {
        $cnt = 0;
        foreach ($members as $m) {
          if (empty($m->uri)) continue;
          $cnt++;
          $mUri = $expandCurie((string) $m->uri);
          $nodes[] = Utils::buildNode($mUri, $m->label ?? Utils::namespaceUri($mUri), $m->typeUri ?? null);
          $edges[] = ['id' => "{$from}_{$mUri}_contains", 'from' => $from, 'to' => $mUri, 'label' => 'contains', 'arrows' => 'to'];
        }
        $meta['count'] = $cnt;
      }

      $addTypeEdges();
      $meta['soc_source']  = $used ?? 'none';
      $guess = $getSocTotalGuess();
      if ($guess !== null) $meta['totalGuess'] = $guess;

      $out = ['nodes' => $this->dedupeById($nodes), 'edges' => $this->dedupeById($edges)];
      if ($debug) $out['meta'] = $meta;
      return new JsonResponse($out);
    }

    // ---------------- LABEL-SPECIFIC / GENERIC ----------------

    $properties = (array) $obj;

    // Support several variants for the direct "super" (parent) pointer.
    $superKeySet = [
      'superuri',
      'hassuperuri',
      'superclassuri',
      'hassuperclassuri',
    ];
    $isSuperKey = static function (string $k) use ($superKeySet): bool {
      return in_array(strtolower(trim($k)), $superKeySet, true);
    };
    $isSuperLabel = static function (?string $lbl) use ($superKeySet): bool {
      if ($lbl === null) return false;
      $l = strtolower(trim($lbl));
      return $l === 'super' || in_array($l, $superKeySet, true);
    };
    $extractSuperUri = static function ($obj) use ($superKeySet, $expandCurie): ?string {
      if (!is_object($obj)) return null;
      $vars = (array) $obj;
      $map = [];
      foreach ($vars as $k => $v) {
        $lk = strtolower((string) $k);
        if (!array_key_exists($lk, $map)) {
          $map[$lk] = $v;
        }
      }

      foreach ($superKeySet as $wanted) {
        if (!array_key_exists($wanted, $map)) continue;
        $v = $map[$wanted];

        if (is_string($v) && trim($v) !== '') return $expandCurie(trim($v));
        if (is_object($v) && !empty($v->uri)) return $expandCurie((string) $v->uri);
        if (is_array($v)) {
          foreach ($v as $item) {
            if (is_object($item) && !empty($item->uri)) return $expandCurie((string) $item->uri);
            if (is_string($item) && trim($item) !== '') return $expandCurie(trim($item));
          }
        }
      }
      return null;
    };

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
        $meta['count'] = $cnt; $meta['totalGuess'] = $total;
      }
    }
    elseif ($isStudy && in_array($label, ['hasSampleCollection','hasSubjectCollection','hasSpaceCollection','hasTimeCollection'], true)) {
      $socRaw = $api->getStudySOCs($from, $limit, $offset);
      $cnt = 0;
      if ($socRaw) {
        $socs = $api->parseObjectResponse($socRaw, 'getStudySOCs');
        foreach (($socs ?? []) as $soc) {
          if (empty($soc->uri)) continue;
          $pred = match ($soc->typeUri ?? '') {
            HASCO::SAMPLE_COLLECTION => 'hasSampleCollection',
            HASCO::SUBJECT_GROUP,
            HASCO::STUDY_OBJECT_COLLECTION => 'hasSubjectCollection',
            HASCO::SPACE_COLLECTION => 'hasSpaceCollection',
            HASCO::TIME_COLLECTION  => 'hasTimeCollection',
            default => 'hasCollection',
          };
          if ($label !== $pred) continue;
          $cnt++;
          $socUri = $expandCurie((string) $soc->uri);
          $nodes[] = Utils::buildNode($socUri, $soc->label ?? Utils::namespaceUri($socUri), $soc->typeUri ?? null);
          $edges[] = ['id' => "{$from}_{$socUri}_{$pred}", 'from' => $from, 'to' => $socUri, 'label' => $pred, 'arrows' => 'to'];
        }
      }
      $meta['count'] = $cnt;
    }
    elseif ($label === 'children') {
      // OWL/HASCO class hierarchy: immediate subclasses of a class URI.
      $rawChildren = $api->getChildren($from);
      $children = $rawChildren ? $api->parseObjectResponse($rawChildren, 'getChildren') : [];
      if (!is_array($children)) {
        $children = [];
      }

      $total = count($children);
      $slice = array_slice($children, $offset, $limit);
      $cnt = 0;
      foreach ($slice as $ch) {
        if (!is_object($ch) || empty($ch->uri)) continue;
        $cnt++;
        $chUri = $expandCurie((string) $ch->uri);
        $nodes[] = Utils::buildNode($chUri, $ch->label ?? Utils::namespaceUri($chUri), $ch->typeUri ?? null);
        $edges[] = [
          'id'     => "{$from}_{$chUri}_children",
          'from'   => $from,
          'to'     => $chUri,
          'label'  => 'children',
          'arrows' => 'to',
        ];
      }
      $meta['count'] = $cnt;
      $meta['totalGuess'] = $total;
      $addTypeEdges();
    }
    elseif ($isSuperLabel($label)) {
      // Direct parent ("super") pointer when available (supports superUri/superURI variants).
      $meta['mode'] = 'super';

      $sup = $extractSuperUri($obj);
      if ($sup) {
        $nodes[] = Utils::buildNode($sup, Utils::namespaceUri($sup), null);
        $edges[] = [
          'id'     => "{$from}_{$sup}_super",
          'from'   => $from,
          'to'     => $sup,
          'label'  => 'super',
          'arrows' => 'to',
        ];
        $meta['count'] = 1;
        $meta['totalGuess'] = 1;
      }
      else {
        // Fallback for class hierarchy: ask HASCOAPI for superclasses.
        $rawSuper = $api->getSuperClasses($from);
        $supers = $rawSuper ? $api->parseObjectResponse($rawSuper, 'getSuperClasses') : [];
        if (!is_array($supers)) {
          $supers = [];
        }

        $total = count($supers);
        $slice = array_slice($supers, $offset, $limit);
        $cnt = 0;
        foreach ($slice as $s) {
          if (!is_object($s) || empty($s->uri)) continue;
          $cnt++;
          $sUri = $expandCurie((string) $s->uri);
          $nodes[] = Utils::buildNode($sUri, $s->label ?? Utils::namespaceUri($sUri), $s->typeUri ?? null);
          $edges[] = [
            'id'     => "{$from}_{$sUri}_super",
            'from'   => $from,
            'to'     => $sUri,
            'label'  => 'super',
            'arrows' => 'to',
          ];
        }
        $meta['count'] = $cnt;
        $meta['totalGuess'] = $total;
      }

      $addTypeEdges();
    }
    else {
      if ($label && array_key_exists($label, $properties)) {
        $val = $properties[$label];

        if (is_object($val) && !empty($val->uri)) {
          $childUri = $expandCurie((string) $val->uri);
          $nodes[] = Utils::buildNode($childUri, $val->label ?? Utils::namespaceUri($childUri), $val->typeUri ?? null);
          $edges[] = ['id' => "{$from}_{$childUri}_{$label}", 'from' => $from, 'to' => $childUri, 'label' => $label, 'arrows' => 'to'];
          $meta['count'] = 1; $meta['totalGuess'] = 1;
        } elseif (is_string($val) && $looksLikeUri($val)) {
          $childUri = $expandCurie(trim((string) $val));
          $nodes[] = Utils::buildNode($childUri, Utils::namespaceUri($childUri), null);
          $edges[] = ['id' => "{$from}_{$childUri}_{$label}", 'from' => $from, 'to' => $childUri, 'label' => $label, 'arrows' => 'to'];
          $meta['count'] = 1; $meta['totalGuess'] = 1;
        } elseif (is_array($val)) {
          $total = 0;
          foreach ($val as $v) {
            if (is_object($v) && !empty($v->uri)) $total++;
            elseif (is_string($v) && $looksLikeUri($v)) $total++;
          }

          $i = 0; $added = 0;
          foreach ($val as $v) {
            $childUri = null;
            $childLbl = null;
            $childType = null;

            if (is_object($v) && !empty($v->uri)) {
              $childUri = $expandCurie((string) $v->uri);
              $childLbl = $v->label ?? Utils::namespaceUri($childUri);
              $childType = $v->typeUri ?? null;
            } elseif (is_string($v) && $looksLikeUri($v)) {
              $childUri = $expandCurie(trim((string) $v));
              $childLbl = Utils::namespaceUri($childUri);
              $childType = null;
            } else {
              continue;
            }

            if ($i++ < $offset) continue;
            if ($added >= $limit) break;

            $nodes[] = Utils::buildNode($childUri, $childLbl, $childType);
            $edges[] = ['id' => "{$from}_{$childUri}_{$label}", 'from' => $from, 'to' => $childUri, 'label' => $label, 'arrows' => 'to'];
            $added++;
          }
          $meta['count'] = $added; $meta['totalGuess'] = $total;
        }
      } else {
        foreach ($properties as $prop => $val) {
          $edgeLabel = $isSuperKey((string) $prop) ? 'super' : $prop;

          if (is_object($val) && !empty($val->uri)) {
            $childUri = $expandCurie((string) $val->uri);
            $nodes[] = Utils::buildNode($childUri, $val->label ?? Utils::namespaceUri($childUri), $val->typeUri ?? null);
            $edges[] = ['id' => "{$from}_{$childUri}_{$edgeLabel}", 'from' => $from, 'to' => $childUri, 'label' => $edgeLabel, 'arrows' => 'to'];
          } elseif (is_string($val) && $looksLikeUri($val)) {
            $childUri = $expandCurie(trim((string) $val));
            $nodes[] = Utils::buildNode($childUri, Utils::namespaceUri($childUri), null);
            $edges[] = ['id' => "{$from}_{$childUri}_{$edgeLabel}", 'from' => $from, 'to' => $childUri, 'label' => $edgeLabel, 'arrows' => 'to'];
          } elseif (is_array($val)) {
            $i = 0;
            $added = 0;
            foreach ($val as $v) {
              $childUri = null;
              $childLbl = null;
              $childType = null;

              if (is_object($v) && !empty($v->uri)) {
                $childUri = $expandCurie((string) $v->uri);
                $childLbl = $v->label ?? Utils::namespaceUri($childUri);
                $childType = $v->typeUri ?? null;
              } elseif (is_string($v) && $looksLikeUri($v)) {
                $childUri = $expandCurie(trim((string) $v));
                $childLbl = Utils::namespaceUri($childUri);
                $childType = null;
              } else {
                continue;
              }

              if ($i++ < $offset) continue;
              if ($added >= $limit) break;

              $nodes[] = Utils::buildNode($childUri, $childLbl, $childType);
              $edges[] = ['id' => "{$from}_{$childUri}_{$edgeLabel}", 'from' => $from, 'to' => $childUri, 'label' => $edgeLabel, 'arrows' => 'to'];
              $added++;
            }
          }
        }
      }
      // add both type edges here as well
      $addTypeEdges();
    }

    $out = [
      'nodes' => $this->dedupeById($nodes),
      'edges' => $this->dedupeById($edges),
    ];
    if ($debug) { $out['meta'] = $meta; }
    return new JsonResponse($out);
  }

  public function node(Request $request): JsonResponse {
    $uri   = $request->query->get('uri') ?? $request->query->get('id') ?? $request->query->get('from') ?? '';
    $debug = (bool) $request->query->get('debug', false);

    if (!$uri) {
      return new JsonResponse(['error' => 'Missing "uri"'], 400);
    }

    /** @var \Drupal\rep\FusekiAPIConnector $api */
    $api = \Drupal::service('rep.api_connector');

    $expandCurie = static function (string $v): string {
      return str_starts_with($v, 'ahead:')
        ? 'http://hadatac.org/ont/arrowhead/' . substr($v, 6)
        : $v;
    };

    $uri = $expandCurie((string) $uri);

    try {
      $raw = $api->getUri($uri);
      if (!$raw) {
        return new JsonResponse(['error' => 'Element not found'], 404);
      }
      $obj = $api->parseObjectResponse($raw, 'getUri');
      if (!$obj || !is_object($obj)) {
        return new JsonResponse(['error' => 'Invalid element payload'], 500);
      }
    }
    catch (\Throwable $e) {
      $out = ['error' => 'Service unavailable'];
      if ($debug) {
        $out['message'] = $e->getMessage();
      }
      return new JsonResponse($out, 503);
    }

    $superKeySet = [
      'superuri',
      'hassuperuri',
      'superclassuri',
      'hassuperclassuri',
    ];

    $extractSuperUri = static function ($obj) use ($superKeySet, $expandCurie): ?string {
      if (!is_object($obj)) return null;
      $vars = (array) $obj;
      $map = [];
      foreach ($vars as $k => $v) {
        $lk = strtolower((string) $k);
        if (!array_key_exists($lk, $map)) {
          $map[$lk] = $v;
        }
      }

      foreach ($superKeySet as $wanted) {
        if (!array_key_exists($wanted, $map)) continue;
        $v = $map[$wanted];

        if (is_string($v) && trim($v) !== '') return $expandCurie(trim($v));
        if (is_object($v) && !empty($v->uri)) return $expandCurie((string) $v->uri);
        if (is_array($v)) {
          foreach ($v as $item) {
            if (is_object($item) && !empty($item->uri)) return $expandCurie((string) $item->uri);
            if (is_string($item) && trim($item) !== '') return $expandCurie(trim($item));
          }
        }
      }
      return null;
    };

    $outUri   = $expandCurie((string) ($obj->uri ?? $uri));
    $outLabel = Utils::sanitizeString((string) ($obj->label ?? Utils::namespaceUri($outUri)));

    $fields = [];
    $links  = [];
    $lists  = [];

    $skipKeys = [
      'uri',
      'label',
      'typeUri',
      'typeLabel',
      'hascoTypeUri',
      'hascoTypeLabel',
    ];

    $maxFields = 12;
    $maxLinks  = 12;
    $maxLists  = 12;

    $looksLikeUri = static function ($v): bool {
      if (!is_string($v)) return false;
      $s = trim($v);
      if ($s === '') return false;
      if (str_starts_with($s, 'ahead:')) return true;
      if (str_starts_with($s, 'http://') || str_starts_with($s, 'https://')) return true;
      if (preg_match('/^urn:/i', $s)) return true;
      return false;
    };

    $normalizePredKey = static function (string $k) use ($superKeySet): string {
      $lk = strtolower(trim($k));
      return in_array($lk, $superKeySet, true) ? 'super' : $k;
    };

    $predicates = [];

    foreach (((array) $obj) as $k => $v) {
      if (!is_string($k) || $k === '') continue;
      if (in_array($k, $skipKeys, true)) continue;
      if ($v === null) continue;

      // Object reference
      if (is_object($v) && !empty($v->uri)) {
        $predKey = $normalizePredKey($k);
        if (!isset($predicates[$predKey])) {
          $predicates[$predKey] = ['key' => $predKey, 'kind' => 'link', 'count' => 1];
        }

        if (count($links) < $maxLinks) {
          $linkUri = $expandCurie((string) $v->uri);
          $linkLbl = !empty($v->label) ? Utils::sanitizeString((string) $v->label) : Utils::namespaceUri($linkUri);
          $links[] = [
            'key' => $k,
            'uri' => $linkUri,
            'label' => $linkLbl,
            'typeUri' => !empty($v->typeUri) ? $expandCurie((string) $v->typeUri) : null,
          ];
        }
        continue;
      }

      // String URI reference
      if (is_string($v) && $looksLikeUri($v)) {
        $predKey = $normalizePredKey($k);
        if (!isset($predicates[$predKey])) {
          $predicates[$predKey] = ['key' => $predKey, 'kind' => 'link', 'count' => 1];
        }

        if (count($links) < $maxLinks) {
          $linkUri = $expandCurie(trim((string) $v));
          $links[] = [
            'key' => $k,
            'uri' => $linkUri,
            'label' => Utils::namespaceUri($linkUri),
            'typeUri' => null,
          ];
        }
        continue;
      }

      // Scalar
      if (is_string($v) || is_int($v) || is_float($v) || is_bool($v)) {
        if (count($fields) >= $maxFields) continue;

        $sv = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        $sv = Utils::sanitizeString($sv);

        // Keep full comment text (used by the frontend to show a 2-3 line preview + modal).
        if (strtolower($k) === 'comment') {
          if (strlen($sv) > 8000) {
            $sv = substr($sv, 0, 8000) . '…';
          }
          $fields[] = ['key' => $k, 'value' => $sv];
          continue;
        }

        if (strlen($sv) > 240) {
          $sv = substr($sv, 0, 240) . '…';
        }
        $fields[] = ['key' => $k, 'value' => $sv];
        continue;
      }

      // Array
      if (is_array($v)) {
        $total = count($v);

        $expandable = false;
        $sample = array_slice($v, 0, 25);
        foreach ($sample as $it) {
          if (is_object($it) && !empty($it->uri)) { $expandable = true; break; }
          if (is_string($it) && $looksLikeUri($it)) { $expandable = true; break; }
        }

        if (count($lists) < $maxLists) {
          $lists[] = ['key' => $k, 'count' => $total, 'expandable' => $expandable];
        }

        if ($expandable) {
          $predKey = $normalizePredKey($k);
          $predicates[$predKey] = ['key' => $predKey, 'kind' => 'list', 'count' => $total];
        }
        continue;
      }
    }

    $predicateList = array_values($predicates);
    usort($predicateList, static function ($a, $b) {
      return strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
    });

    $payload = [
      'uri' => $outUri,
      'label' => $outLabel,
      'typeUri' => !empty($obj->typeUri) ? $expandCurie((string) $obj->typeUri) : null,
      'hascoTypeUri' => !empty($obj->hascoTypeUri) ? $expandCurie((string) $obj->hascoTypeUri) : null,
      'superUri' => $extractSuperUri($obj),
      'fields' => $fields,
      'links' => $links,
      'lists' => $lists,
      'predicates' => $predicateList,
    ];

    if ($debug) {
      $payload['meta'] = [
        'fields' => count($fields),
        'links' => count($links),
        'lists' => count($lists),
        'predicates' => count($predicateList),
      ];
    }

    return new JsonResponse($payload);
  }

  private function dedupeById(array $items): array {
    $acc = [];
    foreach ($items as $it) {
      if (!is_array($it)) continue;
      $id = $it['id'] ?? null;
      if ($id) { $acc[$id] = $it; }
    }
    return array_values($acc);
  }
}
