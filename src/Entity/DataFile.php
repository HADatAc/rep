<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Utils;

class DataFile {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_status' => t('Status'),
      'element_last_time' => t('Last Process Time'),
      'element_filename' => t('FileName'),
      'element_id' => t('FileId'),
    ];
  
  }

  public static function generateOutput($list) {
    
    dpm($list);

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    $output = array();
    if ($list == NULL) {
      return $output;
    }

    foreach ($list as $element) {
      //dpm($element);
      $uri = ' ';
      if ($element->uri != NULL) {
        $uri = $element->uri;
      }
      $uri = Utils::namespaceUri($uri);
      $filename = ' ';
      if ($element->filename != NULL && $element->filename != '') {
        $filename = $element->filename;
      }
      $id = ' ';
      if ($element->id != NULL && $element->id != '') {
        $id = $element->id;
      }
      $status = ' ';
      if ($element->fileStatus != NULL) {
        $status - $element->status;
      };
      $lastTime = ' ';
      if ($element->lastProcessTime != NULL) {
        $lastTime - $element->lastProcessTime;
      };
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        //'element_status' => t('<b><font style="color:#ff0000;">UNINGESTED</font></b>'),    
        'element_status' => $status,    
        'element_last_time' => $lastTime,    
        'element_filename' => $filename,
        'element_id' => $id,
      ];
    }
    return $output;

  }

}