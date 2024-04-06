<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\Response;

class DataFileController extends ControllerBase {
  

  public function download($datafileuri) {

    $dataFileUri = base64_decode($datafileuri);

    // RETRIEVE FILE URI
    $file_uri = NULL;
    if ($dataFileUri != NULL) {
      $api = \Drupal::service('rep.api_connector');  
      $dataFile = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
      if ($dataFile != NULL && isset($dataFile->id) && $dataFile->id != NULL) {
        $file_entity = File::load($dataFile->id);
        if ($file_entity != NULL) {
          $file_uri = $file_entity->getFileUri();
        }
      }  
    }
    if ($file_entity != NULL) {
      $file_content = file_get_contents($file_uri);
    }

    // DOWNLOAD FILE
    $excelFilePath = $file_entity->getFilename();
    $response = new Response();
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
    ');
    $response->headers->set('Content-Disposition', 'containerslot; filename="' . basename($excelFilePath) . '"');
    $response->setContent($file_content);
    return $response;
  }

  public function showLog($datafileuri) {

    $dataFileUri = base64_decode($datafileuri);

    // READ LOG
    $log_content = ' ';
    if ($dataFileUri != NULL) {
      $api = \Drupal::service('rep.api_connector');  
      $dataFile = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
      if ($dataFile != NULL && isset($dataFile->log) && $dataFile->log != NULL) {
        $log_content = str_replace("<br>", "\n", $dataFile->log);
      }  
    }

    $form['log'] = [
      '#type' => 'textarea',
      '#title' => t('Log Content'),
      '#description' => t('Log of datafile ' . $dataFileUri),
      '#value' => t($log_content),
      '#attributes' => [
        'readonly' => 'readonly',
      ],
      '#description_display' => 'after', 
    ];

    return $form;
  }

}
