<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rep\Entity\Tables;
use Symfony\Component\HttpFoundation\JsonResponse;

class MapController extends ControllerBase {
  public function deleteMapping($entryPoint, $mappedUri) {
    $mappedUri = urldecode($mappedUri);
    // perform your DB delete; e.g.:
    $deleted = \Drupal::database()->delete('your_mapping_table')
      ->condition('entry_point', $entryPoint)
      ->condition('mapped_uri', $mappedUri)
      ->execute();

    if ($deleted) {
      return new JsonResponse(['success' => TRUE]);
    }
    return new JsonResponse([
      'success' => FALSE,
      'message' => 'Mapping not found or could not be deleted.'
    ]);
  }

  /**
   * Rebuild class entry-point mappings from current KG state.
   *
   * Strategy:
   * - Load class entry points under hasco:ClassEntryPoint.
   * - For each entry point, pick one stable mapped node from KG children
   *   (alphabetically by label/URI).
   * - Replace table contents with freshly derived mappings.
   */
  public function resyncMappingsFromKg() {
    $api = \Drupal::service('rep.api_connector');
    $db = \Drupal::database();
    $tables = new Tables($db);

    $root = 'http://hadatac.org/ont/hasco/ClassEntryPoint';

    try {
      $entry_points = $api->parseObjectResponse($api->getChildren($root), 'getChildren');
      if (!is_array($entry_points)) {
        $entry_points = [];
      }

      $derived = [];
      foreach ($entry_points as $ep) {
        if (!is_object($ep) || empty($ep->uri)) {
          continue;
        }

        $entry_uri = trim((string) $ep->uri);
        if ($entry_uri === '') {
          continue;
        }

        $children = $api->parseObjectResponse($api->getChildren($entry_uri), 'getChildren');
        if (!is_array($children) || empty($children)) {
          continue;
        }

        $candidates = [];
        foreach ($children as $child) {
          if (!is_object($child) || empty($child->uri)) {
            continue;
          }

          $uri = trim((string) $child->uri);
          if ($uri === '') {
            continue;
          }

          $label = trim((string) ($child->label ?? ''));
          $candidates[] = [
            'uri' => $uri,
            'label' => $label,
          ];
        }

        if (empty($candidates)) {
          continue;
        }

        usort($candidates, static function(array $a, array $b): int {
          $la = $a['label'] !== '' ? $a['label'] : $a['uri'];
          $lb = $b['label'] !== '' ? $b['label'] : $b['uri'];
          return strcasecmp($la, $lb);
        });

        // Use one canonical mapped node per entry point.
        $derived[$entry_uri] = $candidates[0]['uri'];
      }

      $tx = $db->startTransaction();
      $db->truncate('rep_entry_point_mapping')->execute();
      foreach ($derived as $entry_uri => $node_uri) {
        $tables->saveMapping($entry_uri, $node_uri);
      }
      unset($tx);

      return new JsonResponse([
        'success' => TRUE,
        'root' => $root,
        'entry_points_seen' => count($entry_points),
        'mappings_rebuilt' => count($derived),
      ]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->error('Failed to resync entry-point mappings from KG: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to resync entry-point mappings from KG.',
        'error' => $e->getMessage(),
      ], 500);
    }
  }
}
