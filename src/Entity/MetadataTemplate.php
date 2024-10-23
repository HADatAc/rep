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


class MetadataTemplate {

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
      'element_uri' => t('URI'),
      'element_name' => t('Name'),
      'element_filename' => t('FileName'),
      'element_status' => t('Status'),
      'element_log' => t('Log'),
      'element_download' => t('Download'),
    ];

  }

  public static function generateOutput($elementType, $list) {

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
        if ($element->hasDataFile->fileStatus != NULL &&
            $element->hasDataFile->fileStatus != '') {
          if ($element->hasDataFile->fileStatus == Constant::FILE_STATUS_UNPROCESSED) {
            $filestatus = '<b><font style="color:#ff0000;">'.Constant::FILE_STATUS_UNPROCESSED.'</font></b>';
          } else if ($element->hasDataFile->fileStatus == Constant::FILE_STATUS_PROCESSED) {
            $filestatus = '<b><font style="color:#008000;">'.Constant::FILE_STATUS_PROCESSED.'</font></b>';
          } else if ($element->hasDataFile->fileStatus == Constant::FILE_STATUS_WORKING) {
            $filestatus = '<b><font style="color:#ffA500;">'.Constant::FILE_STATUS_WORKING.'</font></b>';
          } else if ($element->hasDataFile->fileStatus == Constant::FILE_STATUS_PROCESSED_STD) {
            $filestatus = '<b><font style="color:#ffA500;">'.Constant::FILE_STATUS_PROCESSED_STD.'</font></b>';
          } else if ($element->hasDataFile->fileStatus == Constant::FILE_STATUS_WORKING_STD) {
            $filestatus = '<b><font style="color:#ffA500;">'.Constant::FILE_STATUS_WORKING_STD.'</font></b>';
            } else {
            $filestatus = ' ';
          }
        }
        if (isset($element->hasDataFile->log) && $element->hasDataFile->log != NULL) {
          $link = $root_url.REPGUI::DATAFILE_LOG.base64_encode($element->hasDataFile->uri);
          $log = '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                 'data-dialog-type="modal" '.
                 'data-dialog-options=\'{"width": 700}\' role="button">Read</a>';

          //$log = '<a href="'.$link.'" class="btn btn-primary btn-sm" role="button">Read</a>';
        }
        $downloadLink = '';
        if ($element->hasDataFile->id != NULL && $element->hasDataFile->id != '') {
          $file_entity = \Drupal\file\Entity\File::load($element->hasDataFile->id);
          if ($file_entity != NULL) {
            $downloadLink = $root_url.REPGUI::DATAFILE_DOWNLOAD.base64_encode($element->hasDataFile->uri);
            $download = '<a href="'.$downloadLink.'" class="btn btn-primary btn-sm" role="button" disabled>Get It</a>';
          }
        }
      }
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),
        'element_name' => t($label),
        'element_filename' => $filename,
        'element_status' => t($filestatus),
        'element_log' => t($log),
        'element_download' => t($download),
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

  public static function generateOutputAsCards($elementType, $list) {
    $output = [];

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    if ($list == NULL) {
        return $output;
    }

    $index = 0;
    foreach ($list as $element) {
      $index++;
      $uri = $element->uri ?? '';
      $label = $element->label ?? '';
      $title = $element->title ?? '';

      $urlComponents = parse_url($uri);

      if (isset($urlComponents['scheme']) && isset($urlComponents['host'])) {
        $url = Url::fromUri($uri);
      } else {
        $url = '';
      }

      if ($element->uri != NULL && $element->uri != "") {
        $previousUrl = base64_encode(\Drupal::request()->getRequestUri());

        $view_da_str = base64_encode(Url::fromRoute('rep.describe_element', ['elementuri' => base64_encode($element->uri)])->toString());
        $view_da_route = 'rep.describe_element';
        $view_da = Url::fromRoute('rep.back_url', [
          'previousurl' => $previousUrl,
          'currenturl' => $view_da_str,
          'currentroute' => 'rep.describe_element'
        ]);

        $edit_da_str = base64_encode(Url::fromRoute('rep.edit_mt', [
          'elementtype' => 'da',
          'elementuri' => base64_encode($element->uri),
          'fixstd' => 'T',
        ])->toString());
        $edit_da = Url::fromRoute('rep.back_url', [
          'previousurl' => $previousUrl,
          'currenturl' => $edit_da_str,
          'currentroute' => 'rep.edit_mt'
        ]);

        $delete_da = Url::fromRoute('rep.delete_element', [
          'elementtype' => 'da',
          'elementuri' => base64_encode($element->uri),
          'currenturl' => $previousUrl,
        ]);

        $download_da = Url::fromRoute('rep.datafile_download', [
          'datafileuri' => base64_encode($element->hasDataFile->uri),
        ]);

      }

      if ($element->hasDataFile->filename != NULL &&
        $element->hasDataFile->filename != '') {
        $filename = $element->hasDataFile->filename;
      }
      if ($element->hasDataFile->fileStatus != NULL &&
          $element->hasDataFile->fileStatus != '') {
        if ($element->hasDataFile->fileStatus == Constant::FILE_STATUS_UNPROCESSED) {
          $filestatus = '<b><font style="color:#ff0000;">'.Constant::FILE_STATUS_UNPROCESSED.'</font></b>';
        } else if ($element->hasDataFile->fileStatus == Constant::FILE_STATUS_PROCESSED) {
          $filestatus = '<b><font style="color:#008000;">'.Constant::FILE_STATUS_PROCESSED.'</font></b>';
        } else if ($element->hasDataFile->fileStatus == Constant::FILE_STATUS_WORKING) {
          $filestatus = '<b><font style="color:#ffA500;">'.Constant::FILE_STATUS_WORKING.'</font></b>';
        } else if ($element->hasDataFile->fileStatus == Constant::FILE_STATUS_PROCESSED_STD) {
          $filestatus = '<b><font style="color:#ffA500;">'.Constant::FILE_STATUS_PROCESSED_STD.'</font></b>';
        } else if ($element->hasDataFile->fileStatus == Constant::FILE_STATUS_WORKING_STD) {
          $filestatus = '<b><font style="color:#ffA500;">'.Constant::FILE_STATUS_WORKING_STD.'</font></b>';
          } else {
          $filestatus = ' ';
        }
      }

      $dd = '(none)';
      if (isset($element->hasDD) && $element->hasDD != NULL) {
        $dd = $element->hasDD->label . ' (' . $element->hasDD->hasDataFile->filename . ') [<b>' . $element->hasDD->hasDataFile->fileStatus . '</b>] ';
      }
      $sdd = '(none)';
      if (isset($element->hasSDD) && $element->hasSDD != NULL) {
        $sdd = $element->hasSDD->label . ' (' . $element->hasSDD->hasDataFile->filename . ') [<b>' . $element->hasSDD->hasDataFile->fileStatus . '</b>] ';
      }

      $properties = ' ';
      if ($elementType == 'da') {
        $properties = '<p class="card-text">'.
          '&nbsp;&nbsp;&nbsp;<b>URI</b>: ' . $uri . '<br>' .
          '&nbsp;&nbsp;&nbsp;<b>File Name</b>: ' . $filename . ' [' . $filestatus . ']<br><br>' .
          'Documentation: <br>' .
          '&nbsp;&nbsp;&nbsp;<b>Data Dictionary</b>: ' . $dd . '<br>' .
          '&nbsp;&nbsp;&nbsp;<b>Semantic Data Dictionary</b>: ' . $sdd . '<br>' .
          '</p>';
      } else {
        $properties = '<p class="card-text">'.
          '&nbsp;&nbsp;&nbsp;<b>URI</b>: ' . $uri . '<br>' .
          '&nbsp;&nbsp;&nbsp;<b>File Name</b>: ' . $filename . ' (' . $filestatus . ')<br>' .
          '</p>';
      }

      $ingest_da = '';
      $uningest_da = '';

      $output[$index] = [
        '#type' => 'container', // Use container instead of html_tag for better semantics
        '#attributes' => [
            'class' => ['card', 'mb-3'],
        ],
        '#prefix' => '<div class="col-md-6">',
        '#suffix' => '</div>',
        'card_body_'.$index => [
            '#type' => 'container', // Use container for the card body
            '#attributes' => [
                'class' => ['card-body'],
            ],
            'title' => [
                '#markup' => '<h5 class="card-title">' . $label . '</h5><br>',
            ],
            'text' => [
                '#markup' => $properties,
            ],
            'link1_'.$index => [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fa-solid fa-eye"></i> View'),
              '#url' => $view_da,
              '#attributes' => [
                'class' => ['btn', 'btn-sm', 'btn-secondary'],
                'style' => 'margin-right: 10px;',
              ],
            ],
            'link2_'.$index => [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fa-solid fa-pen-to-square"></i> Edit'),
              '#url' => $edit_da,
              '#attributes' => [
                'class' => ['btn', 'btn-sm', 'btn-secondary'],
                'style' => 'margin-right: 10px;',
              ],
            ],
            'link3_'.$index => [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fa-solid fa-trash-can"></i> Delete'),
              '#url' => $delete_da,
              '#attributes' => [
                'onclick' => 'if(!confirm("Really Delete?")){return false;}',
                'class' => ['btn', 'btn-sm', 'btn-secondary', 'btn-danger'],
                'style' => 'margin-right: 10px;',
              ],
            ],
            'link4_'.$index => [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fa-solid fa-download"></i> Download'),
              '#url' => $download_da,
              '#attributes' => [
                'onclick' => 'if(!confirm("Really Download?")){return false;}',
                'class' => ['btn', 'btn-sm', 'btn-secondary'],
                'style' => 'margin-right: 10px;',
              ],
            ],
            'link5_'.$index => [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fa-solid fa-arrow-down"></i> Ingest'),
              '#url' => $view_da,
              '#attributes' => [
                'class' => ['btn', 'btn-sm', 'btn-secondary', 'disabled'],
                'style' => 'margin-right: 10px;',
              ],
            ],
            'link6_'.$index => [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fa-solid fa-arrow-up"></i> Uningest'),
              '#url' => $view_da,
              '#attributes' => [
                'class' => ['btn', 'btn-sm', 'btn-secondary', 'disabled'],
              ],
            ],
        ],
      ];

    }

    return $output;
  }

}
