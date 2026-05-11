<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rep\Utils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Component\Utility\UrlHelper;

class ApiImageController extends ControllerBase {

  public function image(string $element, string $image): Response {
    $elementUri = Utils::base64urlDecode($element);
    $imageRef = Utils::base64urlDecode($image);

    $placeholderEnc = (string) \Drupal::request()->query->get('ph', '');
    $placeholderUrl = $placeholderEnc !== '' ? Utils::base64urlDecode($placeholderEnc) : '';

    // Basic hardening: avoid absurdly large decoded inputs.
    if ($elementUri === '' || $imageRef === '' || strlen($elementUri) > 4096 || strlen($imageRef) > 4096) {
      throw new NotFoundHttpException();
    }

    // Prefer local private resources first (used by most non-social forms).
    $localPath = Utils::resolvePrivateResourcePath($elementUri, $imageRef, ['image']);
    if (!empty($localPath)) {
      $content = @file_get_contents($localPath);
      if ($content !== FALSE) {
        return new Response($content, 200, [
          'Content-Type' => $this->inferImageMime($imageRef),
          'Cache-Control' => 'private, max-age=86400',
          'X-Content-Type-Options' => 'nosniff',
        ]);
      }
    }

    /** @var \Drupal\rep\ApiConnectorInterface $api */
    $api = \Drupal::service('rep.api_connector');

    // Try legacy download first.
    $resp = $api->downloadFile($elementUri, $imageRef);
    $status = ($resp && method_exists($resp, 'getStatusCode')) ? $resp->getStatusCode() : NULL;

    // Social fallback if enabled.
    if (!$resp || $status !== 200) {
      $socialEnabled = (bool) \Drupal::config('rep.settings')->get('social_conf');
      if ($socialEnabled) {
        $resp = $api->downloadFileSocial($elementUri, $imageRef);
        $status = ($resp && method_exists($resp, 'getStatusCode')) ? $resp->getStatusCode() : NULL;
      }
    }

    if (!$resp || $status !== 200) {
      // Fallback to placeholder when provided (prevents broken-image icons).
      if ($placeholderUrl !== '' && str_starts_with($placeholderUrl, '/') && !str_contains($placeholderUrl, '..')) {
        return new RedirectResponse($placeholderUrl, 302, [
          // Keep placeholder cache short so newly-uploaded images appear quickly.
          'Cache-Control' => 'private, max-age=60',
        ]);
      }
      // As a last resort, allow a safe absolute URL.
      if ($placeholderUrl !== '' && UrlHelper::isValid($placeholderUrl, TRUE)) {
        return new RedirectResponse(UrlHelper::filterBadProtocol($placeholderUrl), 302, [
          'Cache-Control' => 'private, max-age=3600',
        ]);
      }

      throw new NotFoundHttpException();
    }

    // Extract bytes.
    if (method_exists($resp, 'getContent')) {
      $content = $resp->getContent();
    }
    elseif (method_exists($resp, 'getBody')) {
      $content = $resp->getBody()->getContents();
    }
    else {
      throw new NotFoundHttpException();
    }

    // Determine mime.
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

    // If upstream didn't provide a useful image mime, infer from filename.
    // This avoids browsers refusing to render due to X-Content-Type-Options: nosniff.
    $mime = $this->inferImageMime($imageRef, $mime);

    $out = new Response($content, 200, [
      'Content-Type' => $mime,
      // Let the browser cache, but keep it user-private.
      'Cache-Control' => 'private, max-age=86400',
      'X-Content-Type-Options' => 'nosniff',
    ]);

    return $out;
  }

  private function inferImageMime(string $imageRef, string $candidate = ''): string {
    $candidate = trim((string) $candidate);
    if ($candidate !== '') {
      $candidate = strtolower(trim(explode(';', $candidate, 2)[0]));
      if (str_starts_with($candidate, 'image/')) {
        return $candidate;
      }
    }

    $lower = strtolower($imageRef);
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

    return 'application/octet-stream';
  }

}
