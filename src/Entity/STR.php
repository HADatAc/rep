<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Constant;
use Drupal\rep\Utils;

class STR {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_name' => t('Name'),
      'element_filename' => t('FileName'),
      'element_status' => t('Status'),
      'element_n_attributes' => t('# Attr.'),
      'element_n_objects' => t('# Obj.'),
      'element_log' => t('Log'),
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
      $label = ' ';
      if ($element->label != NULL) {
        $label = $element->label;
      }
      $name = ' ';
      if ($element->label != NULL && $element->label != '') {
        $name = $element->label;
      }
      $totalAttributes = -1;
      if (isset($element->totalSDDA)) {
        $totalAttributes = $element->totalSDDA;
      }
      $totalObjects = -1;
      if (isset($element->totalSDDO)) {
        $totalObjects = $element->totalSDDO;
      }
      $filename = ' ';
      $filestatus = ' ';
      $log = 'N/A';
      $root_url = \Drupal::request()->getBaseUrl();
      if ($element->dataFile != NULL) {
        if ($element->dataFile->filename != NULL && 
            $element->dataFile->filename != '') {
          $filename = $element->dataFile->filename;
        }
        if ($element->dataFile->fileStatus != NULL && 
            $element->dataFile->fileStatus != '') {
          if ($element->dataFile->fileStatus == Constant::FILE_STATUS_UNPROCESSED) {
            $filestatus = '<b><font style="color:#ff0000;">'.Constant::FILE_STATUS_UNPROCESSED.'</font></b>';
          } else if ($element->dataFile->fileStatus == Constant::FILE_STATUS_PROCESSED) {
            $filestatus = '<b><font style="color:#008000;">'.Constant::FILE_STATUS_PROCESSED.'</font></b>';
          } else if ($element->dataFile->fileStatus == Constant::FILE_STATUS_WORKING) {
            $filestatus = '<b><font style="color:#ffA500;">'.Constant::FILE_STATUS_WORKING.'</font></b>';
          } else {
            $filestatus = ' ';
          }
        }
        if (isset($element->dataFile->log) && $element->dataFile->log != NULL) {
          $link = $root_url.REPGUI::DATAFILE_LOG.base64_encode($element->dataFile->uri);
          $log = '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                 'data-dialog-type="modal" '.
                 'data-dialog-options=\'{"width": 700}\' role="button">Read</a>';
  
          //$log = '<a href="'.$link.'" class="btn btn-primary btn-sm" role="button">Read</a>';
        } else {
          $log = '<a href="#link" class="btn btn-primary btn-sm" role="button" disabled>Read</a>';
        }
      }
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        'element_name' => t($label),    
        'element_filename' => $filename,
        'element_status' => t($filestatus),
        'element_n_attributes' => $totalAttributes,
        'element_n_objects' => $totalObjects,
        'element_log' => t($log),
      ];
    }
    return $output;

  }

}