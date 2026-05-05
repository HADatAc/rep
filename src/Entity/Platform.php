<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\Core\Render\Markup;

class Platform {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_name' => t('Name'),
      'element_version' => t('Version'),
    ];

  }

  public static function generateOutput($list) {

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
      $version = ' ';
      if ($element->hasVersion != NULL) {
        $version = $element->hasVersion;
      }
      $output[$element->uri] = [
        'element_uri' => Markup::create(Utils::describeAnchor((string) ($element->uri ?? ''), (string) $uri)),
        'element_name' => $label,
        'element_version' => $version,
      ];
    }
    return $output;

  }

  public static function generateCardOutput($list) {

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
      $version = ' ';
      if ($element->hasVersion != NULL) {
        $version = $element->hasVersion;
      }
      $output[$element->uri] = [
        'element_uri' => Markup::create(Utils::describeAnchor((string) ($element->uri ?? ''), (string) $uri)),
        'element_name' => $label,
        'element_version' => $version,
        'element_image' => $element->hasImageUri,
        'element_hascotypeuri' => $element->hascoTypeUri
      ];
    }
    return $output;

  }

}
