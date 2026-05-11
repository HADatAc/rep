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
        $mime = $this->inferImageMime($imageRef, '', (string) $content);
        $headers = [
          'Content-Type' => $mime,
          'Cache-Control' => 'private, max-age=86400',
        ];
        if (str_starts_with(strtolower($mime), 'image/')) {
          $headers['X-Content-Type-Options'] = 'nosniff';
        }

        return new Response($content, 200, $headers);
      }
    }

    /** @var \Drupal\rep\ApiConnectorInterface $api */
    $api = \Drupal::service('rep.api_connector');

    $resp = NULL;
    $status = NULL;

    // For social-like terms, prefer folder-based API lookup first so we avoid
    // an always-failing legacy request and noisy warnings.
    if ($this->shouldPreferFolderFallback($elementUri)) {
      $resp = $this->tryDownloadFromSocialMediaFolders($api, $elementUri, $imageRef);
      $status = ($resp && method_exists($resp, 'getStatusCode')) ? $resp->getStatusCode() : NULL;
    }

    // Try legacy download path.
    if (!$resp || $status !== 200) {
      $resp = $api->downloadFile($elementUri, $imageRef);
      $status = ($resp && method_exists($resp, 'getStatusCode')) ? $resp->getStatusCode() : NULL;
    }

    // Social/KGR fallback without OAuth: some API deployments keep social
    // media in folder-based resources (initiative, organizations, etc.)
    // instead of resources/{uriTerm}/.
    if (!$resp || $status !== 200) {
      $resp = $this->tryDownloadFromSocialMediaFolders($api, $elementUri, $imageRef);
      $status = ($resp && method_exists($resp, 'getStatusCode')) ? $resp->getStatusCode() : NULL;
    }

    // Social fallback: also allow OAuth-backed social downloads even when
    // rep.settings.social_conf is disabled (new PMSR landing scenario).
    if (!$resp || $status !== 200) {
      if ($this->shouldTrySocialDownload()) {
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
    $mime = $this->inferImageMime($imageRef, $mime, (string) $content);

    $headers = [
      'Content-Type' => $mime,
      // Let the browser cache, but keep it user-private.
      'Cache-Control' => 'private, max-age=86400',
    ];
    if (str_starts_with(strtolower($mime), 'image/')) {
      $headers['X-Content-Type-Options'] = 'nosniff';
    }

    $out = new Response($content, 200, $headers);

    return $out;
  }

  private function shouldTrySocialDownload(): bool {
    // Social download endpoint depends on OAuth social settings.
    $oauthConfig = \Drupal::config('social.oauth.settings');
    $oauthUrl = trim((string) $oauthConfig->get('oauth_url'));
    $clientId = trim((string) $oauthConfig->get('client_id'));
    return $oauthUrl !== '' && $clientId !== '';
  }

  private function inferImageMime(string $imageRef, string $candidate = '', string $content = ''): string {
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

    if ($content !== '') {
      try {
        if (class_exists('finfo')) {
          $finfo = new \finfo(FILEINFO_MIME_TYPE);
          $detected = strtolower(trim((string) $finfo->buffer($content)));
          if (str_starts_with($detected, 'image/')) {
            return $detected;
          }
        }
      }
      catch (\Throwable $e) {
        // Ignore and continue to additional fallbacks.
      }

      if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($content);
        if (is_array($info) && !empty($info['mime']) && str_starts_with(strtolower((string) $info['mime']), 'image/')) {
          return strtolower((string) $info['mime']);
        }
      }
    }

    return 'application/octet-stream';
  }

  private function tryDownloadFromSocialMediaFolders($api, string $elementUri, string $imageRef) {
    $candidates = $this->resolveMediaFolderCandidates($api, $elementUri, $imageRef);
    foreach ($candidates as $folder) {
      $resp = $api->downloadFile($folder, $imageRef);
      $status = ($resp && method_exists($resp, 'getStatusCode')) ? $resp->getStatusCode() : NULL;
      if ($status === 200) {
        return $resp;
      }
    }
    return NULL;
  }

  private function shouldPreferFolderFallback(string $elementUri): bool {
    $term = strtoupper((string) Utils::extractResourceFolderFromUri($elementUri));
    foreach (['PJT', 'FSC', 'ORG', 'PS', 'PLC', 'PA'] as $prefix) {
      if (str_starts_with($term, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function resolveMediaFolderCandidates($api, string $elementUri, string $imageRef): array {
    $candidates = [];

    // If callers pass "folder/filename", keep the folder hint.
    $normalized = str_replace('\\', '/', trim($imageRef));
    if (str_contains($normalized, '/')) {
      $parts = array_values(array_filter(explode('/', trim($normalized, '/')), 'strlen'));
      if (count($parts) > 1) {
        $candidates[] = strtolower((string) $parts[0]);
      }
    }

    $term = strtoupper((string) Utils::extractResourceFolderFromUri($elementUri));
    if (str_starts_with($term, 'PJT') || str_starts_with($term, 'FSC')) {
      $candidates[] = 'initiative';
    }
    if (str_starts_with($term, 'ORG') || str_starts_with($term, 'PS')) {
      $candidates[] = 'organizations';
    }
    if (str_starts_with($term, 'PLC') || str_starts_with($term, 'PA')) {
      $candidates[] = 'countries';
      $candidates[] = 'distritos';
      $candidates[] = 'concelhos';
    }

    if (empty($candidates)) {
      try {
        $raw = $api->getUri($elementUri);
        $obj = $api->parseObjectResponse($raw, 'getUri');
        if (is_object($obj)) {
          $typeText = strtolower((string) (($obj->hascoTypeUri ?? '') . ' ' . ($obj->typeUri ?? '') . ' ' . ($obj->hascoTypeLabel ?? '') . ' ' . ($obj->typeLabel ?? '')));

          if (str_contains($typeText, 'project') || str_contains($typeText, 'fundingscheme') || str_contains($typeText, 'funding scheme')) {
            $candidates[] = 'initiative';
          }
          if (str_contains($typeText, 'organization') || str_contains($typeText, 'person') || str_contains($typeText, 'collegeoruniversity')) {
            $candidates[] = 'organizations';
          }
          if (str_contains($typeText, 'place') || str_contains($typeText, 'country') || str_contains($typeText, 'postaladdress')) {
            $candidates[] = 'countries';
            $candidates[] = 'distritos';
            $candidates[] = 'concelhos';
          }
        }
      }
      catch (\Throwable $e) {
        // Ignore and rely on static folder fallbacks.
      }
    }

    // Keep common social media folders as final fallback.
    $candidates[] = 'initiative';
    $candidates[] = 'organizations';
    $candidates[] = 'countries';
    $candidates[] = 'distritos';
    $candidates[] = 'concelhos';

    $candidates = array_map(static function ($v) {
      return strtolower(trim((string) $v));
    }, $candidates);

    $candidates = array_values(array_unique(array_filter($candidates, static function ($v) {
      return $v !== '' && preg_match('/^[a-z0-9_-]+$/', $v);
    })));

    return $candidates;
  }

}
