<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FileDownloadController {

  /**
   * Download endpoint for "Get It".
   * - If the file is not on disk yet, fetch from API (by the local File entity's filename),
   *   save into private://..., mark the file entity as permanent, then serve it.
   */
  public function download($fid) {
    /** @var \Drupal\file\Entity\File|null $file */
    $file = File::load($fid);
    if (!$file) {
      throw new NotFoundHttpException('Unknown file id.');
    }

    $uri = $file->getFileUri();
    /** @var \Drupal\Core\File\FileSystemInterface $fs */
    $fs = \Drupal::service('file_system');

    // Resolve element type (first folder in private://{type}/filename.xlsx) for friendly fallback redirect.
    $elementType = NULL;
    try {
      $sw = \Drupal::service('stream_wrapper_manager')->getViaUri($uri);
      $target = method_exists($sw, 'getTarget') ? $sw->getTarget($uri) : $sw->getTarget();
      $elementType = strtok((string) $target, '/'); // first path fragment
    } catch (\Throwable $e) {
      // ignore
    }

    $realpath = $fs->realpath($uri);

    // If not present on disk, try to fetch from API using the LOCAL entity filename.
    if (!$realpath || !file_exists($realpath)) {
      $lock = \Drupal::service('lock.persistent');
      $lockName = 'rep.getit.' . $fid;

      if ($lock->acquire($lockName, 30)) {
        try {
          // Double-check after acquiring the lock.
          $realpath = $fs->realpath($uri);
          if (!$realpath || !file_exists($realpath)) {
            /** @var \Drupal\rep\ApiConnector $api */
            $api = \Drupal::service('rep.api_connector');
            $filename = $file->getFilename();

            // --- Call your existing connector method (returns a Symfony Response).
            // It must only be called if the file is not present locally.
            $apiResponse = $api->downloadGeneratedFile($filename);
            if ($apiResponse === NULL) {
              \Drupal::messenger()->addWarning(t(
                'The file "@name" is not ready yet on the generator. Please try again soon.',
                ['@name' => $filename]
              ));
              return $this->redirectBackOrList($elementType);
            }

            // Extract bytes + content-type from the Response returned by the connector.
            $bytes = method_exists($apiResponse, 'getContent')
              ? $apiResponse->getContent()
              : (string) $apiResponse; // extreme fallback

            if ($bytes === '' || $bytes === NULL) {
              \Drupal::messenger()->addWarning(t(
                'The generator did not return file data for "@name".',
                ['@name' => $filename]
              ));
              return $this->redirectBackOrList($elementType);
            }

            // Ensure destination directory exists (usually already prepared by GenerateForm).
            $fs->prepareDirectory(dirname($uri), FileSystemInterface::CREATE_DIRECTORY);

            // Save into the existing entity's URI (unmanaged write).
            $savedUri = file_unmanaged_save_data($bytes, $uri, FILE_EXISTS_REPLACE);
            if (!$savedUri) {
              throw new \RuntimeException('Failed to save file data to filesystem.');
            }

            // Mark file as permanent and (optionally) update size.
            $file->setPermanent();
            try {
              $real = $fs->realpath($uri);
              if ($real && file_exists($real)) {
                $file->setSize(filesize($real));
              }
            } catch (\Throwable $e) {
              // size update is best-effort
            }
            $file->save();

            // Refresh realpath for the final response.
            $realpath = $fs->realpath($uri);
          }
        }
        finally {
          $lock->release($lockName);
        }
      }
      else {
        // Another request is populating the file; ask user to retry briefly.
        \Drupal::messenger()->addStatus(t('Preparing your download. Please try again in a moment.'));
        return $this->redirectBackOrList($elementType);
      }
    }

    // If still no file, 404.
    if (!$realpath || !file_exists($realpath)) {
      throw new NotFoundHttpException('File is not available yet.');
    }

    // Robust MIME detection (D9/D10).
    $mime = 'application/octet-stream';
    if (function_exists('file_get_mimetype')) {
      $guess = file_get_mimetype($uri);
      if (!empty($guess)) {
        $mime = $guess;
      }
    } else {
      $guesser = \Drupal::service('file.mime_type.guesser');
      if (method_exists($guesser, 'guessMimeType')) {
        $mime = $guesser->guessMimeType($realpath) ?: $mime;
      } elseif (method_exists($guesser, 'guess')) {
        $mime = $guesser->guess($realpath) ?: $mime;
      }
    }

    // Serve file.
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

  /**
   * Redirect helper: back to referer or to the listing as fallback.
   */
  private function redirectBackOrList(?string $elementType) {
    $referer = \Drupal::request()->headers->get('referer');
    if ($referer) {
      return new RedirectResponse($referer);
    }
    $url = Url::fromRoute('rep.select_mt_element', [
      'elementtype' => $elementType ?: 'ins',
      'mode' => 'table',
      'page' => '1',
      'pagesize' => '10',
      'studyuri' => 'none',
    ]);
    return new RedirectResponse($url->toString());
  }
}
