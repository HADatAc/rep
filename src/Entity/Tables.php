<?php

namespace Drupal\rep\Entity;

class Tables {
  
  public function getNamespaces() {
    $APIservice = \Drupal::service('rep.api_connector');
    $namespaces = $APIservice->parseObjectResponse($APIservice->namespaceList(), 'namespaceList');
    if ($namespaces == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($namespaces as $namespace) {
      $results[$namespace->label] = $namespace->uri;
    }
    return $results;
  }

  public function getLanguages() {
    $APIservice = \Drupal::service('rep.api_connector');
    $languages = $APIservice->parseObjectResponse($APIservice->languageList(), 'languageList');
    if ($languages == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($languages as $language) {
      $results[$language->code] = $language->value;
    }
    return $results;
  }

  public function getInformants() {
    $APIservice = \Drupal::service('rep.api_connector');
    $informants = $APIservice->parseObjectResponse($APIservice->informantList(), 'informantList');
    if ($informants == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($informants as $informant) {
      $results[$informant->url] = $informant->value;
    }
    return $results;
  }

  public function getGenerationActivities() {
    $APIservice = \Drupal::service('rep.api_connector');
    $generationActivities = $APIservice->parseObjectResponse($APIservice->generationActivityList(), 'generationActivityList');
    if ($generationActivities == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($generationActivities as $generationActivity) {
      $results[$generationActivity->url] = $generationActivity->value;
    }
    return $results;
  }

  public function getInstrumentPositions() {
    $APIservice = \Drupal::service('rep.api_connector');
    $positions = $APIservice->parseObjectResponse($APIservice->instrumentPositionList(), 'instrumentPositionList');
    if ($positions == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($positions as $position) {
      $results[$position->url] = $position->value;
    }
    return $results;
  }

  public function getSubcontainerPositions() {
    $APIservice = \Drupal::service('rep.api_connector');
    $positions = $APIservice->parseObjectResponse($APIservice->subcontainerPositionList(), 'subcontainerPositionList');
    if ($positions == NULL) {
      return NULL;
    }
    $results = array();
    foreach ($positions as $position) {
      $results[$position->url] = $position->value;
    }
    return $results;
  }

}