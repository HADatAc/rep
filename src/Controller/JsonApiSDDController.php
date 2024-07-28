<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Xss;

/**
 * Class JsonApiSDDController
 * @package Drupal\rep\Controller
 */
class JsonApiSDDController extends ControllerBase{

  /**
   * @return JsonResponse
   */
  public function handleAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q');
    if (!$input) {
      return new JsonResponse($results);
    }
    $keyword = Xss::filter($input);
    $api = \Drupal::service('rep.api_connector');
    $sdd_list = $api->listByKeyword('sdd',$keyword,10,0);
    $obj = json_decode($sdd_list);
    $sdds = [];
    if ($obj->isSuccessful) {
      $sdds = $obj->body;
    }
    foreach ($sdds as $sdd) {
      $results[] = [
        'value' => $sdd->label . ' [' . $sdd->uri . ']',
        'label' => $sdd->label,
      ];
    }
    return new JsonResponse($results);
  }

}