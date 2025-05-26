<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\Core\Link;
use Drupal\Core\Url;

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

  public static function generateHeaderStudy() {
    return [
      'element_uri'        => t('URI'),
      'element_datetime'   => t('Execution Time'),
      'element_deployment' => t('Deployment'),
      'element_sdd'        => t('SDD'),
      'element_source'     => t('Source'),
      'element_operations' => t('Operations'),
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
      if (isset($element->semanticDataDictionary) && isset($element->semanticDataDictionary->label)) {
        $sdd = $element->semanticDataDictionary->label;
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

  public static function generateOutputStudy($list) {
    $root_url = \Drupal::request()->getBaseUrl();
    $output   = [];

    foreach ($list as $element) {
      // 1) chave “segura”
      $safe_key = base64_encode($element->uri);

      // 2) link como render array
      $link = t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($element->uri).'">'.UTILS::namespaceUri($element->uri).'</a>');


      // 3) resto dos campos
      $datetime   = '';
      if (isset($element->startedAt)) {
        $dt = new \DateTime($element->startedAt);
        $datetime = $dt->format('F j, Y \a\t g:i A');
      }

      $deployment = $element->deployment->label ?? '';
      $sdd = t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($element->semanticDataDictionary->uri).'">'.UTILS::namespaceUri($element->semanticDataDictionary->label).'</a>');
      $source     = '';
      if ($element->method === 'files') {
        $source = t('Files');
      }
      elseif ($element->method === 'messages') {
        $source = $element->messageProtocol
          ? $element->messageProtocol . ' ' . t('messages')
          : t('Messages');
        if ($element->messageIP) {
          $source .= ' @' . $element->messageIP;
        }
        if ($element->messagePort) {
          $source .= ':' . $element->messagePort;
        }
      }

      $output[$safe_key] = [
        'element_uri'        => $link,
        'element_datetime'   => $datetime,
        'element_deployment' => $deployment,
        'element_sdd'        => $sdd,
        'element_source'     => $source,
        'element_operations' => '',
      ];
    }

    return $output;
  }

}
