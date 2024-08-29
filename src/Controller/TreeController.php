<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class TreeController extends ControllerBase {

  public function getChildren(Request $request) {
    $api = \Drupal::service('rep.api_connector');
    
    $nodeUri = $request->query->get('nodeUri');
    $data = $api->parseObjectResponse($api->getChildren($nodeUri),'getChildren');

    // Validate and format the data
    if (!is_array($data)) {
      $data = [];
    }

    // Return a JSON response
    return new JsonResponse($data);
  }

  public function getNode(Request $request) {
    $api = \Drupal::service('rep.api_connector');
    
    $nodeUri = $request->query->get('nodeUri');
    $data = $api->parseObjectResponse($api->getUri($nodeUri),'getUri');

    // Return a JSON response
    return new JsonResponse($data);
  }

}
