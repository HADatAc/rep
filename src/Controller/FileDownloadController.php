<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams a DataFile binary to the browser.
 *
 * Behavior:
 * - If the physical file exists under private://, stream it immediately.
 * - If it does not exist:
 *     - If origin is 'api'  -> fetch fresh from API, save under private://generated_mt/, update File entity, then stream.
 *     - If origin is 'local'-> do NOT call the API (the API does not own this asset) -> 404 with a clear message.
 *     - If origin unknown   -> be conservative: do not fetch; 404 with a clear message.
 *
 * Notes:
 * - This controller relies on the RepFileOriginManager (service id: rep.file_origin)
 *   to know whether a given fid is 'api' or 'local'.
 * - When saving the refreshed file, we set/update MIME type as best as we can
 *   (prefer local guesser, fallback to remote header).
 */
class FileDownloadController extends ControllerBase {

  /**
   * Directory where API-fetched files are cached.
   */
  private const CACHE_DIR = 'private://generated_mt';

  /**
   * Download endpoint using the File entity id (fid).
   *
   * @param int|string $fid
   *   File entity id.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   Binary response with the file payload or 404 if not resolvable.
   */
  public function download($fid) {
    $logger = \Drupal::logger('rep.file_download');

    /** @var \Drupal\file\Entity\File|null $file */
    $file = File::load($fid);
    if (!$file) {
      $logger->warning('File entity not found for fid=@fid', ['@fid' => $fid]);
      throw new NotFoundHttpException('File entity not found.');
    }

    $fs = \Drupal::service('file_system');
    $mime_guesser = \Drupal::service('file.mime_type.guesser');
    $api = \Drupal::service('rep.api_connector');

    $uri = $file->getFileUri();
    $realpath = $uri ? $fs->realpath($uri) : NULL;
    $needs_fetch = TRUE;

    if ($realpath && is_file($realpath) && filesize($realpath) > 0) {
      $needs_fetch = FALSE;
    }

    if ($needs_fetch) {
      $filename = $file->getFilename();
      if (empty($filename)) {
        $logger->warning('Empty filename in File entity fid=@fid', ['@fid' => $fid]);
        throw new NotFoundHttpException('File name is empty on File entity.');
      }

      $logger->notice('Fetching remote generated file "@fn" for fid=@fid', [
        '@fn' => $filename,
        '@fid' => $fid,
      ]);

      try {
        $api_response = $api->downloadGeneratedFile($filename);
      } catch (\Throwable $e) {
        $logger->error('Exception calling API for "@fn": @err', [
          '@fn' => $filename,
          '@err' => $e->getMessage(),
        ]);
        throw new NotFoundHttpException('Error contacting remote API.');
      }

      if (!$api_response) {
        $logger->warning('API returned NULL for generated file "@fn"', ['@fn' => $filename]);
        throw new NotFoundHttpException('Remote generated file is not available.');
      }

      // Se o teu conector criar um Response sem status code explícito, isto será 200 por defeito.
      $status = method_exists($api_response, 'getStatusCode') ? $api_response->getStatusCode() : 200;
      if ($status !== 200) {
        $logger->warning('API returned HTTP @code for "@fn"', ['@fn' => $filename, '@code' => $status]);
        throw new NotFoundHttpException('Remote generated file unavailable (status).');
      }

      $binary = $api_response->getContent();
      if ($binary === '' || $binary === NULL) {
        $logger->warning('API returned empty body for "@fn"', ['@fn' => $filename]);
        throw new NotFoundHttpException('Empty content returned by remote API.');
      }

      $remote_mime = $api_response->headers->get('Content-Type') ?: 'application/octet-stream';

      $directory = 'private://generated_mt';
      $fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $destination = $directory . '/' . $filename;

      $saved_uri = $fs->saveData($binary, $destination, FileSystemInterface::EXISTS_REPLACE);
      if (!$saved_uri) {
        $logger->error('Failed to save remote file "@fn" into private storage.', ['@fn' => $filename]);
        throw new \RuntimeException('Failed to save file into private:// storage.');
      }

      // Atualiza File entity.
      $file->setFileUri($saved_uri);

      $final_mime = $remote_mime;
      try {
        $guessed = $mime_guesser->guessMimeType($fs->realpath($saved_uri));
        if (!empty($guessed)) {
          $final_mime = $guessed;
        }
      } catch (\Throwable $e) {
        // Ignora falhas ao adivinhar MIME.
      }

      $file->setMimeType($final_mime);
      $file->save();

      $uri = $saved_uri;
      $realpath = $fs->realpath($uri);
      if (!$realpath || !is_file($realpath)) {
        $logger->error('Saved file could not be resolved (fid=@fid, uri="@uri")', [
          '@fid' => $fid,
          '@uri' => $uri,
        ]);
        throw new NotFoundHttpException('Saved file could not be resolved from private storage.');
      }

      $logger->notice('Saved remote file for fid=@fid at "@uri"', ['@fid' => $fid, '@uri' => $uri]);
    }

    $mime = $file->getMimeType() ?: 'application/octet-stream';

    $response = new BinaryFileResponse($realpath);
    $response->setPrivate();
    $response->headers->set('Content-Type', $mime);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $file->getFilename()
    );

    return $response;
  }


}
