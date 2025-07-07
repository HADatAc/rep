<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Xss;

/**
 * Class JsonApiDDController
 * @package Drupal\rep\Controller
 */
class JsonApiDDController extends ControllerBase{

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
    $dd_list = $api->listByKeyword('dd',$keyword,10,0);
    $obj = json_decode($dd_list);
    $dds = [];
    if ($obj->isSuccessful) {
      $dds = $obj->body;
    }
    foreach ($dds as $dd) {
      $results[] = [
        'value' => $dd->label . ' [' . $dd->uri . ']',
        'label' => $dd->label,
      ];
    }
    return new JsonResponse($results);
  }

}