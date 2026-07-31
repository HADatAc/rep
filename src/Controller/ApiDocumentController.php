<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rep\Utils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApiDocumentController extends ControllerBase {

  public function document(string $element, string $document): Response {
    $elementUri = Utils::base64urlDecode($element);
    $documentRef = Utils::base64urlDecode($document);

    // Basic hardening: avoid absurdly large decoded inputs.
    if ($elementUri === '' || $documentRef === '' || strlen($elementUri) > 4096 || strlen($documentRef) > 4096) {
      throw new NotFoundHttpException();
    }

    // Prefer local private resources first.
    $localPath = Utils::resolvePrivateResourcePath($elementUri, $documentRef, ['webdoc', 'webdocument', 'image']);
    if (!empty($localPath)) {
      $content = @file_get_contents($localPath);
      if ($content !== FALSE) {
        $mime = $this->inferDocumentMime($documentRef, '', (string) $content);
        return new Response($content, 200, [
          'Content-Type' => $mime,
          'Cache-Control' => 'private, max-age=86400',
          'Content-Disposition' => $this->buildInlineDisposition($documentRef),
        ]);
      }
    }

    /** @var \Drupal\rep\ApiConnectorInterface $api */
    $api = \Drupal::service('rep.api_connector');

    // Try legacy download first.
    $resp = $api->downloadFile($elementUri, $documentRef);
    $status = ($resp && method_exists($resp, 'getStatusCode')) ? $resp->getStatusCode() : NULL;

    // Social fallback when OAuth social settings are configured.
    if ((!$resp || $status !== 200) && $this->shouldTrySocialDownload()) {
      $resp = $api->downloadFileSocial($elementUri, $documentRef);
      $status = ($resp && method_exists($resp, 'getStatusCode')) ? $resp->getStatusCode() : NULL;
    }

    if (!$resp || $status !== 200) {
      throw new NotFoundHttpException();
    }

    if (method_exists($resp, 'getContent')) {
      $content = $resp->getContent();
    }
    elseif (method_exists($resp, 'getBody')) {
      $content = $resp->getBody()->getContents();
    }
    else {
      throw new NotFoundHttpException();
    }

    $mime = 'application/octet-stream';
    if (isset($resp->headers)) {
      $h = $resp->headers->get('Content-Type');
      if (!empty($h)) {
        $mime = $h;
      }
    }
    elseif (method_exists($resp, 'getHeaderLine')) {
      $h = $resp->getHeaderLine('Content-Type');
      if (!empty($h)) {
        $mime = $h;
      }
    }

    $mime = $this->inferDocumentMime($documentRef, $mime, (string) $content);

    return new Response($content, 200, [
      'Content-Type' => $mime,
      'Cache-Control' => 'private, max-age=86400',
      'Content-Disposition' => $this->buildInlineDisposition($documentRef),
    ]);
  }

  private function shouldTrySocialDownload(): bool {
    $repConfig = \Drupal::config('rep.settings');
    if (!(bool) $repConfig->get('social_conf') || !(bool) $repConfig->get('social_oauth_enabled')) {
      return FALSE;
    }

    $oauthConfig = \Drupal::config('social.oauth.settings');
    $oauthUrl = trim((string) $oauthConfig->get('oauth_url'));
    $clientId = trim((string) $oauthConfig->get('client_id'));
    return $oauthUrl !== '' && $clientId !== '';
  }

  private function inferDocumentMime(string $documentRef, string $candidate = '', string $content = ''): string {
    $candidate = trim((string) $candidate);
    if ($candidate !== '') {
      $candidate = strtolower(trim(explode(';', $candidate, 2)[0]));
      if ($candidate !== '' && $candidate !== 'application/octet-stream') {
        return $candidate;
      }
    }

    $lower = strtolower($documentRef);
    if (str_ends_with($lower, '.pdf')) {
      return 'application/pdf';
    }
    if (str_ends_with($lower, '.doc')) {
      return 'application/msword';
    }
    if (str_ends_with($lower, '.docx')) {
      return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }
    if (str_ends_with($lower, '.xls')) {
      return 'application/vnd.ms-excel';
    }
    if (str_ends_with($lower, '.xlsx')) {
      return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
    if (str_ends_with($lower, '.ppt')) {
      return 'application/vnd.ms-powerpoint';
    }
    if (str_ends_with($lower, '.pptx')) {
      return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    }
    if (str_ends_with($lower, '.csv')) {
      return 'text/csv';
    }
    if (str_ends_with($lower, '.txt')) {
      return 'text/plain';
    }
    if (str_ends_with($lower, '.json')) {
      return 'application/json';
    }
    if (str_ends_with($lower, '.xml')) {
      return 'application/xml';
    }
    if (str_ends_with($lower, '.zip')) {
      return 'application/zip';
    }
    if (str_ends_with($lower, '.rtf')) {
      return 'application/rtf';
    }
    if (str_ends_with($lower, '.odt')) {
      return 'application/vnd.oasis.opendocument.text';
    }
    if (str_ends_with($lower, '.ods')) {
      return 'application/vnd.oasis.opendocument.spreadsheet';
    }
    if (str_ends_with($lower, '.odp')) {
      return 'application/vnd.oasis.opendocument.presentation';
    }
    if (str_ends_with($lower, '.png')) {
      return 'image/png';
    }
    if (str_ends_with($lower, '.jpg') || str_ends_with($lower, '.jpeg')) {
      return 'image/jpeg';
    }
    if (str_ends_with($lower, '.gif')) {
      return 'image/gif';
    }
    if (str_ends_with($lower, '.webp')) {
      return 'image/webp';
    }
    if (str_ends_with($lower, '.svg')) {
      return 'image/svg+xml';
    }

    if ($content !== '') {
      try {
        if (class_exists('finfo')) {
          $finfo = new \finfo(FILEINFO_MIME_TYPE);
          $detected = strtolower(trim((string) $finfo->buffer($content)));
          if ($detected !== '' && $detected !== 'application/octet-stream') {
            return $detected;
          }
        }
      }
      catch (\Throwable $e) {
        // Ignore and continue to fallbacks.
      }

      if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($content);
        if (is_array($info) && !empty($info['mime'])) {
          $imgMime = strtolower((string) $info['mime']);
          if (str_starts_with($imgMime, 'image/')) {
            return $imgMime;
          }
        }
      }
    }

    return 'application/octet-stream';
  }

  private function buildInlineDisposition(string $documentRef): string {
    $filename = basename(str_replace('\\', '/', $documentRef));
    if ($filename === '') {
      $filename = 'document';
    }

    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    if (!is_string($safe) || $safe === '') {
      $safe = 'document';
    }

    return 'inline; filename="' . $safe . '"';
  }

}
