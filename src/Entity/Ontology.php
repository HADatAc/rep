<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;

class Ontology {

  public static function generateHeader() {

    return $header = [
      'ontology_abbrev' => t('Abbrev'),
      'ontology_uri' => t('NameSpace'),
      'ontology_in_memory' => t('In-Memory'),
      'ontology_name' => t('Source URL'),
      'ontology_mime_type' => t('MIME Type'),
      'ontology_triples' => t('Triples'),
    ];
  
  }

  public static function generateOutput($list) {

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    $output = array();
    foreach ($list as $ontology) {

      $abbrev = ' ';
      if ($ontology->label != NULL) {
        $abbrev = $ontology->label;
      }
      $uri = ' ';
      if ($ontology->uri != NULL) {
        $uri = $ontology->uri;
      }
      $in_memory = "no";
      if ($ontology->permanent) {
        $in_memory = "yes";
      }
      $url = ' ';
      if ($ontology->source != NULL) {
        $url = $ontology->source;
      }
      $mimeType = ' ';
      if ($ontology->sourceMime != NULL) {
        $mimeType = $ontology->sourceMime;
      }
      $triples = ' ';
      if ($ontology->numberOfLoadedTriples != NULL) {
        $triples = $ontology->numberOfLoadedTriples;
      }
      $output[$ontology->label] = [
        'ontology_abbrev' => $abbrev,     
        'ontology_uri' => t('<a href="'.$uri.'">'.$uri.'</a>'),  
        'ontology_in_memory' => $in_memory,   
        'ontology_name' => $url,
        'ontology_mime_type' => $mimeType,
        'ontology_triples' => $triples,
      ];
    }
    return $output;

  }

}