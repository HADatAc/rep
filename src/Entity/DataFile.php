<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Constant;
use Drupal\rep\Utils;

class DataFile {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_status' => t('Status'),
      'element_last_time' => t('Last Process Time'),
      'element_filename' => t('FileName'),
      'element_id' => t('FileId'),
      'element_log' => t('Log'),
      'element_download' => t('Download'),
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
      $filename = ' ';
      if ($element->filename != NULL && $element->filename != '') {
        $filename = $element->filename;
      }
      $id = ' ';
      if ($element->id != NULL && $element->id != '') {
        $id = $element->id;
        $file_entity = \Drupal\file\Entity\File::load($element->id);
        if ($file_entity != NULL) {
          $id .= " (available)";
        } else {
          $id .= " (unavailable)";
        }
      }
      $filestatus = ' ';
      if (isset($element->fileStatus) && $element->fileStatus != NULL) {
        if ($element->fileStatus == Constant::FILE_STATUS_UNPROCESSED) {
          $filestatus = '<b><font style="color:#ff0000;">'.Constant::FILE_STATUS_UNPROCESSED.'</font></b>';
        } else if ($element->fileStatus == Constant::FILE_STATUS_PROCESSED) {
          $filestatus = '<b><font style="color:#008000;">'.Constant::FILE_STATUS_PROCESSED.'</font></b>';
        } else if ($element->fileStatus == Constant::FILE_STATUS_WORKING) {
          $filestatus = '<b><font style="color:#ffA500;">'.Constant::FILE_STATUS_WORKING.'</font></b>';
        } 
      };
      $lastTime = ' ';
      if ($element->lastProcessTime != NULL) {
        $lastTime = $element->lastProcessTime;
      };
      $root_url = \Drupal::request()->getBaseUrl();
      if (isset($element->log) && $element->log != NULL) {
        $showLogLink = $root_url.REPGUI::DATAFILE_LOG.base64_encode($element->uri);
        $log = '<a href="' . $showLogLink . '" class="use-ajax btn btn-primary btn-sm" '.
               'data-dialog-type="modal" '.
               'data-dialog-options=\'{"width": 700}\' role="button">Read</a>';
      } else {
        $log = '<a href="#link" class="btn btn-primary btn-sm" role="button">Read</a>';
      }
      $downloadLink = $root_url.REPGUI::DATAFILE_DOWNLOAD.base64_encode($element->uri);
      $download = '<a href="'.$downloadLink.'" class="btn btn-primary btn-sm" role="button" disabled>Get It</a>';
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),     
        'element_status' => t($filestatus),    
        'element_last_time' => $lastTime,    
        'element_filename' => $filename,
        'element_id' => $id,
        'element_log' => t($log),
        'element_download' => t($download),
      ];
    }
    return $output;

  }

}