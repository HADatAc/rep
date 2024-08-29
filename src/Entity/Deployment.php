<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;

class Deployment {

  public static function generateHeader() {
    return $header = [
      'element_uri' => t('URI'),
      'element_designedAt' => t('Design Time'),
      'element_startedAt' => t('Execution Time'),
      'element_platform_instance' => t('Platform Instance'),
      'element_instrument_instance' => t('Instrument Instance'),
    ];
  }

  public static function generateHeaderState($state) {

    if ($state == 'design') {
      return $header = [
        'element_uri' => t('URI'),
        'element_datetime' => t('Design Time'),
        'element_platform_instance' => t('Platform Instance'),
        'element_instrument_instance' => t('Instrument Instance'),
      ];
    } else {
      return $header = [
        'element_uri' => t('URI'),
        'element_datetime' => t('Execution Time'),
        'element_platform_instance' => t('Platform Instance'),
        'element_instrument_instance' => t('Instrument Instance'),
      ];
    }

  }

  public static function generateOutputState($state, $list) {

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
      $platformInstance = ' ';
      if (isset($element->platformInstance) && isset($element->platformInstance->label)) {
        $platformInstance = $element->platformInstance->label;
      }
      $instrumentInstance = ' ';
      if (isset($element->instrumentInstance) && isset($element->instrumentInstance->label)) {
        $instrumentInstance = $element->instrumentInstance->label;
      }
      $datetime = ' ';
      if ($state == 'design') {
        if (isset($element->designedAt)) {
          $dateTimeRaw = new \DateTime($element->designedAt);
          $datetime = $dateTimeRaw->format('F j, Y \a\t g:i A');
        }
      } else {
        if (isset($element->startedAt)) {
          $dateTimeRaw = new \DateTime($element->startedAt);
          $datetime = $dateTimeRaw->format('F j, Y \a\t g:i A');
        }
      }

      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        'element_datetime' => $datetime,     
        'element_platform_instance' => $platformInstance,
        'element_instrument_instance' => $instrumentInstance,
      ];
    }
    return $output;

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
      $platformInstance = ' ';
      if (isset($element->platformInstance) && isset($element->platformInstance->label)) {
        $platformInstance = $element->platformInstance->label;
      }
      $instrumentInstance = ' ';
      if (isset($element->instrumentInstance) && isset($element->instrumentInstance->label)) {
        $instrumentInstance = $element->instrumentInstance->label;
      }
      $designedAt = ' ';
      if (isset($element->designedAt)) {
        $designedAtRaw = new \DateTime($element->designedAt);
        $designedAt = $designedAtRaw->format('F j, Y \a\t g:i A');
      }
      $startedAt = ' ';
      if (isset($element->startedAt)) {
        $startedAtRaw = new \DateTime($element->startedAt);
        $startedAt = $startedAtRaw->format('F j, Y \a\t g:i A');
      }

      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        'element_designedAt' => $designedAt,     
        'element_startedAt' => $startedAt,     
        'element_platform_instance' => $platformInstance,
        'element_instrument_instance' => $instrumentInstance,
      ];
    }
    return $output;

  }

}