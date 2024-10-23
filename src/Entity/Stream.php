<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;

class Stream {

  public static function generateHeader() {
    return $header = [
      'element_uri' => t('URI'),
      'element_name' => t('Name'),
      'element_version' => t('Version'),
    ];
  }

  public static function generateHeaderState($state) {
    if ($state == 'design') {
      return $header = [
        'element_uri' => t('URI'),
        'element_datetime' => t('Design Time'),
        'element_deployment' => t('Deployment'),
        'element_study' => t('Study'),
        'element_sdd' => t('SDD'),
        'element_source' => t('Source'),
      ];
    } else {
      return $header = [
        'element_uri' => t('URI'),
        'element_datetime' => t('Execution Time'),
        'element_deployment' => t('Deployment'),
        'element_study' => t('Study'),
        'element_sdd' => t('SDD'),
        'element_source' => t('Source'),
      ];
    }
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
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        'element_name' => $label,     
        'element_version' => $version,
      ];
    }
    return $output;
  }

  public static function generateOutputState($state, $list) {
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
      $deployment = ' ';
      if (isset($element->deployment) && isset($element->deployment->label)) {
        $deployment = $element->deployment->label;
      }
      $study = ' ';
      if (isset($element->study) && isset($element->study->label)) {
        $study = $element->study->label;
      }
      $sdd = ' ';
      if (isset($element->sdd) && isset($element->sdd->label)) {
        $sdd = $element->sdd->label;
      }
      $source = ' ';
      if ($element->method != NULL) {
        if ($element->method == 'files') {
          $source = "Files ";
        } 
        if ($element->method == 'messages') {
          $source = "Messages ";
          if ($element->messageProtocol != NULL) {
            $source = $element->messageProtocol . " messages";
          }
          if ($element->messageIP != NULL) {
            $source .= " @" . $element->messageIP;
          }
          if ($element->messagePort != NULL) {
            $source .= ":" . $element->messagePort;
          }
        }
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
        'element_deployment' => $deployment,
        'element_study' => $study,
        'element_sdd' => $sdd,
        'element_source' => $source,
      ];
    }
    return $output;
  }

}