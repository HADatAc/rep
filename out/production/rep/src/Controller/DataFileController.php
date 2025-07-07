<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\Response;

class DataFileController extends ControllerBase {


  // public function download($datafileuri) {

  //   $dataFileUri = base64_decode($datafileuri);

  //   // RETRIEVE FILE URI
  //   $file_uri = NULL;
  //   if ($dataFileUri != NULL) {
  //     $api = \Drupal::service('rep.api_connector');
  //     $dataFile = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
  //     if ($dataFile != NULL && isset($dataFile->id) && $dataFile->id != NULL) {
  //       $file_entity = File::load($dataFile->id);
  //       if ($file_entity != NULL) {
  //         $file_uri = $file_entity->getFileUri();
  //       }
  //     }
  //   }
  //   if ($file_entity != NULL) {
  //     $file_content = file_get_contents($file_uri);
  //   }

  //   // DOWNLOAD FILE
  //   $excelFilePath = $file_entity->getFilename();
  //   $response = new Response();
  //   $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
  //   ');
  //   $response->headers->set('Content-Disposition', 'containerslot; filename="' . basename($excelFilePath) . '"');
  //   $response->setContent($file_content);
  //   return $response;
  // }
  public function download($datafileuri) {
    // Decode the provided datafile URI.
    $dataFileUri = base64_decode($datafileuri);

    // Initialize variables.
    $file_uri = NULL;
    $file_entity = NULL;

    // Retrieve file details using the API if the data file URI is provided.
    if ($dataFileUri != NULL) {
      $api = \Drupal::service('rep.api_connector');
      $dataFile = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
      // Check if $dataFile is valid and has a non-empty filename.
      if ($dataFile != NULL && isset($dataFile->filename) && !empty($dataFile->filename)) {
        // Query the file entity based on the filename.
        $query = \Drupal::entityQuery('file')
          ->accessCheck(FALSE)
          ->condition('filename', $dataFile->filename);
        $fids = $query->execute();

        if (!empty($fids)) {
          // Load the first matching file entity.
          $fid = reset($fids);
          $file_entity = \Drupal\file\Entity\File::load($fid);
          if ($file_entity) {
            $file_uri = $file_entity->getFileUri();
          }
        }
      }
    }

    // If the file entity is not found, return a 404 response.
    if ($file_entity == NULL) {
      return new Response('File not found.', 404);
    }

    // Read the file contents.
    $file_content = file_get_contents($file_uri);

    // Download the file.
    $excelFilePath = $file_entity->getFilename();
    $response = new Response();
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    // Set Content-Disposition to 'attachment' so the browser prompts the user to download.
    $response->headers->set('Content-Disposition', 'attachment; filename="' . basename($excelFilePath) . '"');
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
