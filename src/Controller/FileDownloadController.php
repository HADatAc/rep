<?php

namespace Drupal\rep\Controller;

use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FileDownloadController {

  public function download($fid) {
    $file = File::load($fid);
    if (!$file) {
      throw new NotFoundHttpException();
    }

    $uri = $file->getFileUri();
    $fs = \Drupal::service('file_system');
    $realpath = $fs->realpath($uri);

    if (!$realpath || !file_exists($realpath)) {
      throw new NotFoundHttpException();
    }

    // --- MIME type robusto (funciona em D9/D10)
    $mime = 'application/octet-stream';

    // 1) Tenta o helper core (estável)
    if (function_exists('file_get_mimetype')) {
      $mime_guess = file_get_mimetype($uri);
      if (!empty($mime_guess)) {
        $mime = $mime_guess;
      }
    } else {
      // 2) Fallback para o serviço, cobrindo nomes de métodos diferentes
      $guesser = \Drupal::service('file.mime_type.guesser');
      if (method_exists($guesser, 'guessMimeType')) {
        $mime = $guesser->guessMimeType($realpath) ?: $mime;
      } elseif (method_exists($guesser, 'guess')) {
        $mime = $guesser->guess($realpath) ?: $mime;
      }
    }

    $response = new BinaryFileResponse($realpath);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $file->getFilename()
    );
    $response->headers->set('Content-Type', $mime);
    $response->headers->set('Cache-Control', 'private, max-age=0, no-cache, no-store, must-revalidate');
    $response->setPrivate();

    return $response;
  }
}
