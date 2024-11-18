<?php

namespace Drupal\rep\Entity;

use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Entity\DataFile;
use Drupal\rep\Constant;
use Drupal\rep\Utils;
use Drupal\Core\Render\Markup;


class Publication {

  protected $preservedMT;

  protected $preservedDF;

  // Constructor
  public function __construct() {
  }

  public function getPreservedMT() {
    return $this->preservedMT;
  }

  public function setPreservedMT($mt) {
    if ($this->preservedMT == NULL) {
      $this->preservedMT = new MetadataTemplate();
    }
    $this->preservedMT->uri = $mt->uri;
    $this->preservedMT->label = $mt->label;
    $this->preservedMT->typeUri = $mt->typeUri;
    $this->preservedMT->hascoTypeUri = $mt->hascoTypeUri;
    $this->preservedMT->hasDataFileUri = $mt->hasDataFileUri;
    $this->preservedMT->comment = $mt->comment;
    $this->preservedMT->hasSIRManagerEmail = $mt->hasSIRManagerEmail;
  }

  public function getPreservedDF() {
    return $this->preservedDF;
  }

  public function setPreservedDF($df) {
    if ($this->preservedDF == NULL) {
      $this->preservedDF = new DataFile();
    }
    $this->preservedDF->uri = $df->uri;
    $this->preservedDF->label = $df->label;
    $this->preservedDF->filename = $df->filename;
    $this->preservedDF->id = $df->id;
    $this->preservedDF->fileStatus = $df->fileStatus;
    $this->preservedDF->hasSIRManagerEmail = $df->hasSIRManagerEmail;
  }

  public static function generateHeader() {

    return $header = [
      'element_filename' => t('FileName'),
      'element_download' => t('Operations'),
    ];

  }

  private static function generateOutput($elementType, $list) {

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
      $filename = ' ';
      $filestatus = ' ';
      $log = ' ';
      $download = ' ';
      $root_url = \Drupal::request()->getBaseUrl();
      if ($element->hasDataFile != NULL) {

        // RETRIEVE DATAFILE BY URI
        //$api = \Drupal::service('rep.api_connector');
        //$dataFile = $api->parseObjectResponse($api->getUri($element->hasDataFile),'getUri');

        if ($element->hasDataFile->filename != NULL &&
            $element->hasDataFile->filename != '') {
          $filename = $element->hasDataFile->filename;
        }
        $downloadLink = '';
        if ($element->hasDataFile->id != NULL && $element->hasDataFile->id != '') {
          $file_entity = \Drupal\file\Entity\File::load($element->hasDataFile->id);
          if ($file_entity != NULL) {
            $downloadLink = $root_url.REPGUI::DATAFILE_DOWNLOAD.base64_encode($element->hasDataFile->uri);
            $download = '<a href="'.$downloadLink.'" class="btn btn-primary btn-sm download-button" role="button" disabled>Get It</a>';
          }
        }
      }
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_filename' => $filename,
        'element_operations' => t($download),
      ];
    }

    return $output;
  }

  public function savePreservedMT($elementType) {

    if ($this->getPreservedMT() == NULL || $this->getPreservedDF() == NULL) {
      return FALSE;
    }

    try {
      $datafileJSON = '{"uri":"'. $this->getPreservedDF()->uri .'",'.
          '"typeUri":"'.HASCO::DATAFILE.'",'.
          '"hascoTypeUri":"'.HASCO::DATAFILE.'",'.
          '"label":"'.$this->getPreservedDF()->label.'",'.
          '"filename":"'.$this->getPreservedDF()->filename.'",'.
          '"id":"'.$this->getPreservedDF()->id.'",'.
          '"fileStatus":"'.Constant::FILE_STATUS_UNPROCESSED.'",'.
          '"hasSIRManagerEmail":"'.$this->getPreservedDF()->hasSIRManagerEmail.'"}';

      $mtJSON = '{"uri":"'. $this->getPreservedMT()->uri .'",'.
          '"typeUri":"'.$this->getPreservedMT()->typeUri.'",'.
          '"hascoTypeUri":"'.$this->getPreservedMT()->hascoTypeUri.'",'.
          '"label":"'.$this->getPreservedMT()->label.'",'.
          '"hasDataFileUri":"'.$this->getPreservedMT()->hasDataFileUri.'",'.
          '"comment":"'.$this->getPreservedMT()->comment.'",'.
          '"hasSIRManagerEmail":"'.$this->getPreservedMT()->hasSIRManagerEmail.'"}';

      $api = \Drupal::service('rep.api_connector');

      // ADD DATAFILE
      $msg1 = NULL;
      $msg2 = NULL;
      $dfRaw = $api->datafileAdd($datafileJSON);
      if ($dfRaw != NULL) {
        $msg1 = $api->parseObjectResponse($dfRaw,'datafileAdd');

        // ADD MT
        $mtRaw = $api->elementAdd($elementType, $mtJSON);
        if ($mtRaw != NULL) {
          $msg2 = $api->parseObjectResponse($mtRaw,'elementAdd');
        }
      }

      if ($msg1 != NULL && $msg2 != NULL) {
        return TRUE;
      } else {
        return FALSE;
      }

    } catch(\Exception $e) {}
  }

  return $output;

}
