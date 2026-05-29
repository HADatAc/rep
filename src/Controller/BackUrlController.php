<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;

class BackUrlController extends ControllerBase {

  /**
   * Decode back URL route parameters supporting standard and URL-safe base64.
   */
  private function decodeBackUrlParam(string $value): ?string {
    $decoded = $this->decodeBase64Variant($value);
    if ($decoded === NULL) {
      return NULL;
    }

    // If we already have a path/URL, do not decode again.
    if (str_starts_with($decoded, '/') || str_starts_with($decoded, 'http://') || str_starts_with($decoded, 'https://')) {
      return $decoded;
    }

    // Some callers double-encode values; decode one extra layer when needed.
    $doubleDecoded = $this->decodeBase64Variant($decoded);
    if ($doubleDecoded !== NULL && (str_starts_with($doubleDecoded, '/') || str_starts_with($doubleDecoded, 'http://') || str_starts_with($doubleDecoded, 'https://'))) {
      return $doubleDecoded;
    }

    return $decoded;
  }

  /**
   * Decode one layer of base64, accepting URL-safe variants without padding.
   */
  private function decodeBase64Variant(string $value): ?string {
    $decoded = base64_decode($value, TRUE);
    if ($decoded !== FALSE) {
      return $decoded;
    }

    $normalized = strtr($value, '-_', '+/');
    $remainder = strlen($normalized) % 4;
    if ($remainder > 0) {
      $normalized .= str_repeat('=', 4 - $remainder);
    }

    $decoded = base64_decode($normalized, TRUE);
    return ($decoded === FALSE) ? NULL : $decoded;
  }

  /**
   *   Record the previous URL and redirect to the following URL 
   */
  public function previous($previousurl, $currenturl, $currentroute) {
    if ($previousurl == NULL || $currenturl == NULL || $currentroute == NULL) {
      return new RedirectResponse(Url::fromRoute('rep.home')->toString());
    }

    $previousUrl = $this->decodeBackUrlParam((string) $previousurl);
    $currentUrl = $this->decodeBackUrlParam((string) $currenturl);
    if ($currentUrl === NULL) {
      return new RedirectResponse(Url::fromRoute('rep.home')->toString());
    }

    // Accept only local path-style redirects.
    if (!str_starts_with($currentUrl, '/')) {
      return new RedirectResponse(Url::fromRoute('rep.home')->toString());
    }

    $baseUrl = Utils::baseUrl();
    $url = Url::fromUri($baseUrl . $currentUrl)->toString();

    $uid = \Drupal::currentUser()->id();
    Utils::trackingStoreUrls($uid, $previousUrl ?? '/', $currentroute);
    return new RedirectResponse($url);
  }

}