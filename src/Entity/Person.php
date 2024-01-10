<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Constant;
use Drupal\rep\Utils;

class Person {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_status' => t('Name'),
    ];  
  }

  public static function generateOutput($list) {
    
    //dpm($list);

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    $output = array();
    if ($list == NULL) {
      return $output;
    }

    foreach ($list as $element) {
      $uri = ' ';
      if ($element->uri != NULL) {
        $uri = $element->uri;
      }
      $uri = Utils::namespaceUri($uri);
      $name = ' ';
      if ($element->name != NULL && $element->name != '') {
        $name = $element->name;
      }
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        'element_name' => t($name),    
      ];
    }
    return $output;

  }

}