<?php

namespace Drupal\rep;

use Drupal\rep\Utils;

class ListUsage {

  public static function exec($uri) {
    if ($uri == NULL) {
        $resp = array();
        return $resp;
    }
    $api = \Drupal::service('rep.api_connector');
    $elements = $api->parseObjectResponse($api->getUsage($uri),'getUsage');
    return $elements;
  }

  public static function fromDetectorToHtml($detectorslots) {
    $html = "<ul>";
    if (sizeof($detectorslots) <= 0) {
      $html .= "<li>NONE</li>";
    } else {
      foreach ($detectorslots as $detectorslot) {
        $instrument = ListUsage::getInstrument($detectorslot->belongsTo);
        if ($instrument != NULL) {
          //dpm($detectorslot);
          $html .= "<li>Position " . $detectorslot->hasPriority . " in Questionnaire " . $instrument->label . " (" . Utils::repUriLink($instrument->uri) . ")</li>"; 
        }
      }     
    }
    $html .= "</ul>";
    return $html;
  }

  public static function getInstrument($uri) {
    $api = \Drupal::service('rep.api_connector');
    $instrument = $api->parseObjectResponse($api->getUri($uri),'getUri');
    return $instrument;
  }

}

?>