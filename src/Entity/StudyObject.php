<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Utils;

class StudyObject {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_soc_name' => t('SOC Name'),
      'element_original_id' => t('Original ID'),
      'element_entity' => t('Entity Type'),
      'element_domain_scope' => t('Domain Scope'),
      'element_time_scope' => t('Time Scope'),
      'element_space_scope' => t('Space Scope'),
    ];
  
  }

  public static function generateOutput($list) {

    //dpm($list);

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    $output = array();
    foreach ($list as $element) {
      $uri = ' ';
      if ($element->uri != NULL) {
        $uri = $element->uri;
      }
      $uri = Utils::namespaceUri($uri);
      $label = ' ';
      if ($element->label != NULL) {
        $label = $element->label;
      }
      $originalId = ' ';
      if ($element->originalId != NULL) {
        $originalId = $element->originalId;
      }
      $socLabel = ' ';
      if ($element->isMemberOf != NULL &&
          $element->isMemberOf->label != NULL) {
        $socLabel = $element->isMemberOf->label;
      }
      $typeLabel = ' ';
      if ($element->typeLabel != NULL) {
        $typeLabel = $element->typeLabel;
      }
      $domainScope = ' ';
      if ($element->scopeUris != NULL && count($element->scopeUris) > 0) {
        $domainScope = implode(', ', $element->scopeUris);
      }
      $timeScope = ' ';
      if ($element->timeScopeUris != NULL && count($element->timeScopeUris) > 0) {
        $timeScope = implode(', ', $element->timeScopeUris);
      }
      $spaceScope = ' ';
      if ($element->spaceScopeUris != NULL && count($element->spaceScopeUris) > 0) {
        $spaceScope = implode(', ', $element->spaceScopeUris);
      }
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        'element_soc_name' => t($socLabel),     
        'element_original_id' => t($originalId),     
        'element_entity' => t($typeLabel),     
        'element_domain_scope' => t($domainScope),     
        'element_time_scope' => t($timeScope),     
        'element_space_scope' => t($spaceScope),     
      ];
    }
    return $output;

  }

}