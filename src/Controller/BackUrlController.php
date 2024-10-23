<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;

class BackUrlController extends ControllerBase {

  /**
   *   Record the previous URL and redirect to the following URL 
   */
  public function previous($previousurl, $currenturl, $currentroute) {
    if ($previousurl == NULL || $currenturl == NULL || $currentroute == NULL) {
      $response = new RedirectResponse(Url::fromRoute('rep.home')->toString());
      $response->send();
      return;
    }    

    $baseUrl = Utils::baseUrl();
    $previousUrl = base64_decode($previousurl);
    $currentUrl = base64_decode($currenturl);
    $url = Url::fromUri($baseUrl.$currentUrl)->toString();
    //dpm('Previous: ['.$previousUrl.']  Current: ['.$url.']');

    $uid = \Drupal::currentUser()->id();
    Utils::trackingStoreUrls($uid, $previousUrl, $currentroute);
    $response = new RedirectResponse($url);
    $response->send();
    return;
  }

}