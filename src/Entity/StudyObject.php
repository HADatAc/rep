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
      if ($element->studyObjectCollection != NULL &&
          $element->studyObjectCollection->label != NULL) {
        $socLabel = $element->studyObjectCollection->label;
      }
      $typeLabel = ' ';
      if ($element->typeLabel != NULL) {
        $typeLabel = $element->typeLabel;
      }
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        'element_soc_name' => t($socLabel),     
        'element_original_id' => t($originalId),     
        'element_entity' => t($typeLabel),     
      ];
    }
    return $output;

  }

}