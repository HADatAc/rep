<?php

namespace Drupal\rep\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

/**
 * Lazy expansion endpoint para o grafo (vis.js).
 *
 * GET /rep/graph/expand?from=<uri>&label=<opcional>&limit=100&offset=0&debug=1&broad=1
 */
class GraphController extends ControllerBase {

  /**
   * Expande um nó consoante o seu tipo / label pedido.
   */
  public function expand(Request $request): JsonResponse {
    $from   = $request->query->get('from') ?? $request->query->get('uri');
    $label  = $request->query->get('label');
    $limit  = (int) ($request->query->get('limit')  ?? 100);
    $offset = (int) ($request->query->get('offset') ?? 0);
    $debug  = (bool) $request->query->get('debug', false);
    $broad  = (bool) $request->query->get('broad', false);

    if (empty($from)) {
      return new JsonResponse(['error' => 'Missing "from"'], 400);
    }

    /** @var object $api */
    $api = \Drupal::service('rep.api_connector');

    // Normaliza CURIEs ahead:XYZ -> IRI completo.
    $expandCurie = static function (string $v): string {
      return str_starts_with($v, 'ahead:')
        ? 'http://hadatac.org/ont/arrowhead/' . substr($v, 6)
        : $v;
    };

    if (str_starts_with($from, 'ahead:')) {
      $from = $expandCurie($from);
    }

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

    // Nó de origem (sempre incluído).
    $fromLabel = $obj->label ?? Utils::namespaceUri($from);
    $nodes[] = Utils::buildNode($from, $fromLabel, $obj->typeUri ?? ($obj->hascoTypeUri ?? null));

    // Classificação (Study / SOC / outro) — robusto a "/" e "#".
    $typeUri = $obj->hascoTypeUri ?? $obj->typeUri ?? null;
    $t = (string) ($typeUri ?? '');

    $isStudy = ($typeUri === HASCO::STUDY)
      || (bool) preg_match('~hasco[\/#]Study$~', $t)
      || str_ends_with($t, 'Study');

    $isSoc = in_array($typeUri, [
        HASCO::SAMPLE_COLLECTION,
        HASCO::SUBJECT_GROUP,
        HASCO::STUDY_OBJECT_COLLECTION,
        HASCO::SPACE_COLLECTION,
        HASCO::TIME_COLLECTION,
      ], true)
      || (bool) preg_match('~(SampleCollection|SubjectGroup|StudyObjectCollection|SpaceCollection|TimeCollection)$~', $t);

    // ---------------- Fast paths (sem label explícito) ----------------
    if (!$label) {
      // STUDY → listar SOCs por tipo (sample / subject / space / time).
      if ($isStudy) {
        $meta['mode'] = 'study';

        $socRaw = $api->getStudySOCs($from, $limit, $offset);
        if ($socRaw) {
          $socs = $api->parseObjectResponse($socRaw, 'getStudySOCs');
          foreach (($socs ?? []) as $soc) {
            if (empty($soc->uri)) { continue; }

            $pred = match ($soc->typeUri ?? '') {
              HASCO::SAMPLE_COLLECTION => 'hasSampleCollection',
              HASCO::SUBJECT_GROUP, HASCO::STUDY_OBJECT_COLLECTION => 'hasSubjectCollection',
              HASCO::SPACE_COLLECTION  => 'hasSpaceCollection',
              HASCO::TIME_COLLECTION   => 'hasTimeCollection',
              default => 'hasCollection', // só se cair algo fora das classes acima
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
          $meta['study_soc_count'] = is_countable($socs) ? count($socs) : 0;
        }

        // Aresta de tipo (ajuda o toggle "hascoTypeUri" no UI).
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

      // SOC → listar membros (contains) com *fallbacks*.
      if ($isSoc) {
        $meta['mode'] = 'soc';
        $members = [];
        $usedMethod = null;

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
          } catch (\Throwable $e) {}
        }

        if (empty($members) && is_object($obj)) {
          foreach (['members','hasMember','hasStudyObject','objects','items','studyObjects'] as $key) {
            if (!empty($obj->{$key}) && is_array($obj->{$key})) {
              $members    = $obj->{$key};
              $usedMethod = "object.$key";
              break;
            }
          }
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
                $usedMethod = "SPARQL:$runner";
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
                $usedMethod = "SPARQL:$runner";
              }
            } catch (\Throwable $e) {
              \Drupal::logger('rep')->warning('SPARQL fallback failed for @uri: @err', ['@uri' => $from, '@err' => $e->getMessage()]);
            }
          }
        }

        // Aresta de tipo.
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

        $meta['soc_source']        = $usedMethod ?? 'none';
        $meta['soc_members_count'] = count(array_filter($edges, fn($e) => ($e['label'] ?? '') === 'contains'));
        $meta['api_methods']       = get_class_methods($api);

        $out = ['nodes' => $this->dedupeById($nodes), 'edges' => $this->dedupeById($edges)];
        if ($debug) { $out['meta'] = $meta; }
        return new JsonResponse($out);
      }
    }
    // ---------------- Fim dos fast paths ----------------

    // ---------------- Label explícito / walker genérico ----------------
    $properties = (array) $obj;

    // Study → Virtual Columns
    if ($label === 'hasVirtualColumn' && ($obj->hascoTypeUri ?? null) === HASCO::STUDY) {
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
    // Study → SOCs filtradas por label (sample / subject / space / time)
    elseif (
      in_array($label, ['hasSampleCollection','hasSubjectCollection','hasSpaceCollection','hasTimeCollection'], true) &&
      ($obj->hascoTypeUri ?? null) === HASCO::STUDY
    ) {
      $socRaw = $api->getStudySOCs($from, 1000, 0);
      if ($socRaw) {
        $socs = $api->parseObjectResponse($socRaw, 'getStudySOCs');
        foreach (($socs ?? []) as $soc) {
          if (empty($soc->uri)) { continue; }

          $pred = match ($soc->typeUri ?? '') {
            HASCO::SAMPLE_COLLECTION => 'hasSampleCollection',
            HASCO::SUBJECT_GROUP, HASCO::STUDY_OBJECT_COLLECTION => 'hasSubjectCollection',
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
    // Walker genérico por propriedades objeto (e arrays de objetos).
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

    // Aresta de tipo (sempre que exista).
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
   * Remove duplicados por 'id', mantendo o último.
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
