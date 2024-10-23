<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;

class DeleteElementController extends ControllerBase {

  /**
   *   Delete Study with given studyurl and redirect to current URL 
   */
  public function exec($elementtype, $elementuri, $currenturl) {
    if ($elementuri == NULL || $currenturl == NULL) {
      $response = new RedirectResponse(Url::fromRoute('rep.home')->toString());
      $response->send();
      return;
    }    

    $uri = base64_decode($elementuri);
    $url = base64_decode($currenturl);

    $elementname = 'element';
    if ($elementtype == 'da') {
      $elementname = 'DA';
    } elseif ($elementtype == 'study') {
      $elementname = 'study';
    } else {
      \Drupal::messenger()->addMessage('Element ' . $elementtype . ' cannot be deleted via controller.');      
      $response = new RedirectResponse($url);
      $response->send();      
      return; 
    }

    // DELETE ELEMENT
    $api = \Drupal::service('rep.api_connector');
    $api->elementDel($elementtype, $uri);
    \Drupal::messenger()->addMessage('Selected ' . $elementname . ' has/have been deleted successfully.');      

    // RETURN TO CURRENT URL
    $response = new RedirectResponse($url);
    $response->send();
    return;
  }

}