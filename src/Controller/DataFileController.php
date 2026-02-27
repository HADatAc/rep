<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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
    $dataFileUri = base64_decode($datafileuri);
    if (empty($dataFileUri)) {
      return new Response('Invalid data file URI.', 400);
    }

    $logger = \Drupal::logger('rep.datafile_download');
    $api = \Drupal::service('rep.api_connector');
    $fs = \Drupal::service('file_system');
    $mime_guesser = \Drupal::service('file.mime_type.guesser');

    $dataFile = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
    if ($dataFile == NULL || empty($dataFile->filename)) {
      $logger->warning('Could not resolve datafile metadata for uri=@uri', ['@uri' => $dataFileUri]);
      return new Response('File metadata not found.', 404);
    }

    $filename = $dataFile->filename;
    $file_entity = NULL;

    // Prefer the fid coming from API payload (if present).
    if (!empty($dataFile->id)) {
      $file_entity = File::load($dataFile->id);
    }

    // Fallback: lookup by filename.
    if ($file_entity == NULL) {
      $query = \Drupal::entityQuery('file')
        ->accessCheck(FALSE)
        ->condition('filename', $filename);
      $fids = $query->execute();
      if (!empty($fids)) {
        $fid = reset($fids);
        $file_entity = File::load($fid);
      }
    }

    // If file exists locally, stream it.
    if ($file_entity) {
      $uri = $file_entity->getFileUri();
      $realpath = $uri ? $fs->realpath($uri) : NULL;
      if ($realpath && is_file($realpath) && filesize($realpath) > 0) {
        $mime = $file_entity->getMimeType() ?: 'application/octet-stream';
        $response = new BinaryFileResponse($realpath);
        $response->setPrivate();
        $response->headers->set('Content-Type', $mime);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file_entity->getFilename());
        return $response;
      }
    }

    // Otherwise, try to fetch from HASCOAPI and cache under private://generated_mt.
    try {
      $api_response = $api->downloadFile($dataFileUri, $filename);
      if (!$api_response) {
        $logger->notice('downloadFile returned no content; trying mtGetGenerated for fn=@fn uri=@uri', [
          '@fn' => $filename,
          '@uri' => $dataFileUri,
        ]);
        // Some generation flows save output at the ingestion root and are
        // downloaded via /hascoapi/api/mt/get/generated/:filename.
        $api_response = $api->downloadGeneratedFile($filename);
      }
    } catch (\Throwable $e) {
      $logger->error('Exception downloading from API for uri=@uri fn=@fn err=@err', [
        '@uri' => $dataFileUri,
        '@fn' => $filename,
        '@err' => $e->getMessage(),
      ]);
      $api_response = NULL;
    }

    if (!$api_response) {
      $logger->warning('All API download attempts failed for fn=@fn uri=@uri', [
        '@fn' => $filename,
        '@uri' => $dataFileUri,
      ]);
      $this->messenger()->addWarning($this->t('Could not download the file from HASCOAPI. If it was just generated, try again in a moment.'));
      $referer = \Drupal::request()->headers->get('referer');
      return new RedirectResponse($referer ?: '/');
    }

    $binary = method_exists($api_response, 'getContent') ? $api_response->getContent() : '';
    if ($binary === '' || $binary === NULL) {
      $this->messenger()->addWarning($this->t('File is not ready yet (empty content). Try again in a moment.'));
      $referer = \Drupal::request()->headers->get('referer');
      return new RedirectResponse($referer ?: '/');
    }

    $remote_mime = 'application/octet-stream';
    if (property_exists($api_response, 'headers')) {
      $remote_mime = $api_response->headers->get('Content-Type') ?: $remote_mime;
    }

    $directory = 'private://generated_mt';
    $fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $destination = $directory . '/' . $filename;
    $saved_uri = $fs->saveData($binary, $destination, FileSystemInterface::EXISTS_REPLACE);

    if (!$saved_uri) {
      $logger->error('Failed to save downloaded file into private storage for uri=@uri fn=@fn', [
        '@uri' => $dataFileUri,
        '@fn' => $filename,
      ]);
      return new Response('Failed to save file.', 500);
    }

    // Ensure a File entity exists and points to the cached file.
    if (!$file_entity) {
      $file_entity = File::create([
        'uri' => $saved_uri,
        'filename' => $filename,
        'status' => FILE_STATUS_PERMANENT,
      ]);
    }
    $file_entity->setFileUri($saved_uri);

    $final_mime = $remote_mime;
    try {
      $guessed = $mime_guesser->guessMimeType($fs->realpath($saved_uri));
      if (!empty($guessed)) {
        $final_mime = $guessed;
      }
    } catch (\Throwable $e) {
      // ignore
    }
    $file_entity->setMimeType($final_mime);
    $file_entity->save();

    $realpath = $fs->realpath($saved_uri);
    $response = new BinaryFileResponse($realpath);
    $response->setPrivate();
    $response->headers->set('Content-Type', $final_mime);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
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
