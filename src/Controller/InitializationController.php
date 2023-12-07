<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rep\Controller\UtilsController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class InitializationController extends ControllerBase{

   /**
   * Settings Variable.
   */
  Const CONFIGNAME = "rep.settings";
  
  public function index() {

    //verify if rep is configured
    $utils_controller = new UtilsController();
    $response = $utils_controller->repipconfigured();
    if ($response instanceof RedirectResponse) {
      return $response;
    }

    $config = $this->config(static::CONFIGNAME);  

    $APIservice = \Drupal::service('rep.api_connector');
    $rep_updated = $APIservice->parseObjectResponse($APIservice->repoInfo(), 'repoInfo');
    $rep_api_version = NULL;
    if ($rep_updated != NULL) {
      $rep_api_version = $rep_updated->hasVersion;
    }

    $rep_gui_version = $config->get("rep_gui_version");     
    
    if ($rep_api_version == NULL) {
      \Drupal::messenger()->addError(t("API service could not retrieve its version number. Check if API IP configuration is correct."));
    } 
    //else {
    //  if($rep_gui_version != $rep_api_version) {
    //    \Drupal::messenger()->addError(t("rep's API and GUI are required to have identical version numbers. API version is " . $rep_api_version . ". GUI version is " . $rep_gui_version . "."));
    //  }
    //}

    $root_url = \Drupal::request()->getBaseUrl();
    $redirect = new RedirectResponse($root_url . '/rep/about');
  
    return $redirect;

  }

  private function repRepoVersion() {
  }

}
