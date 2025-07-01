<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
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
}
