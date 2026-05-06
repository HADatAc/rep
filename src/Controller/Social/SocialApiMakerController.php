<?php

namespace Drupal\rep\Controller\Social;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Utility\Xss;
use Drupal\rep\Vocabulary\VSTOI;

/**
 * Autocomplete that uses legacy first, then (optionally) Social API.
 */
class SocialApiMakerController extends ControllerBase {

  /**
   * GET /api/socialm/autocomplete/{entityType}?q=...
   */
  public function handleAutocomplete(string $entityType, Request $request): JsonResponse {
    $results = [];

    // 1) Read & sanitize the 'q' query param.
    $input = Xss::filter($request->query->get('q', ''));
    // \Drupal::logger('rep')->debug('Autocomplete @et called with q="@q"', [
    //   '@et' => $entityType,
    //   '@q'  => $input,
    // ]);
    if ($input === '') {
      return new JsonResponse($results);
    }

    // Combined lookup used by DPL owner/maintainer fields.
    if ($entityType === 'agent') {
      $organizationResults = json_decode($this->handleAutocomplete('organization', $request)->getContent(), TRUE) ?: [];
      $personResults = json_decode($this->handleAutocomplete('person', $request)->getContent(), TRUE) ?: [];

      $seen = [];
      $merged = [];
      foreach (array_merge($organizationResults, $personResults) as $item) {
        if (!is_array($item) || empty($item['value'])) {
          continue;
        }

        $uriKey = (string) $item['value'];
        if (preg_match('/\[([^\]]+)\]$/', (string) $item['value'], $match)) {
          $uriKey = $match[1];
        }

        if (isset($seen[$uriKey])) {
          continue;
        }
        $seen[$uriKey] = TRUE;
        $merged[] = $item;
      }

      return new JsonResponse($merged);
    }

    // 2) Legacy lookup via listByKeyword().
    /** @var \Drupal\rep\ApiConnectorInterface $api */
    $api    = \Drupal::service('rep.api_connector');
    $makers = [];
    try {
      $raw    = $api->listByKeyword($entityType, $input, 9999, 0);
      $obj    = is_string($raw) ? json_decode($raw) : (is_object($raw) ? $raw : json_decode(json_encode($raw)));
      if (!empty($obj->isSuccessful) && !empty($obj->body)) {
        // body pode vir já como array ou JSON-string.
        $makers = is_string($obj->body)
          ? (json_decode($obj->body) ?: [])
          : (is_array($obj->body) ? $obj->body : []);
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->warning('Legacy autocomplete failed: @m', ['@m' => $e->getMessage()]);
      $makers = [];
    }

    // \Drupal::logger('rep')->debug('Legacy makers count: @c', ['@c' => count($makers)]);
    $legacyMakers = $makers;

    // Prefer CURRENT elements, but keep non-CURRENT when there is no CURRENT match.
    $makersFiltered = array_values(array_filter($makers, function ($m) {
      if (is_array($m)) {
        $m = (object) $m;
      }
      if (!is_object($m)) {
        return FALSE;
      }
      if (isset($m->hasStatus) && $m->hasStatus !== NULL && $m->hasStatus !== '' && $m->hasStatus !== VSTOI::CURRENT) {
        return FALSE;
      }
      return TRUE;
    }));
    if (!empty($makersFiltered)) {
      $makers = $makersFiltered;
    }

    // 3) If legacy returned results, use them immediately.
    $socialEnabled = \Drupal::config('rep.settings')->get('social_conf');
    if (!empty($makers)) {
      foreach ($makers as $m) {
        if (is_array($m)) {
          $m = (object) $m;
        }
        if (!is_object($m)) {
          continue;
        }
        $uri = (string) ($m->uri ?? '');
        if ($uri === '') {
          continue;
        }
        $label = (string) ($m->label ?? ($m->name ?? $uri));
        $results[] = ['value' => "$label [$uri]", 'label' => $label];
      }
      return new JsonResponse($results);
    }

    // 4) When social_conf is OFF, try keywordtype fallback before giving up.
    if (!$socialEnabled) {
      return new JsonResponse($this->keywordTypeFallback($api, $entityType, $input));
    }

    // 5) social_conf is ON and legacy did not return items: use Social API fallback.
    // \Drupal::logger('rep')->debug('No legacy results; using Social fallback.');

    // --- token/session logic (igual ao anterior) ---
    $session = \Drupal::request()->getSession();
    $token   = $session->get('oauth_access_token');
    $refreshToken = function() use ($session) {
      // \Drupal::logger('rep')->debug('Refreshing OAuth token...');
      $resp = call_user_func(
        \Drupal::service('controller_resolver')
          ->getControllerFromDefinition('Drupal\social\Controller\OAuthController::getAccessToken')
      );
      $pl = json_decode($resp->getContent(), TRUE);
      if (!empty($pl['body']['access_token'])) {
        $session->set('oauth_access_token', $pl['body']['access_token']);
        return;
      }
      throw new \Exception('Failed to refresh OAuth token');
    };
    if (empty($token)) {
      try {
        $refreshToken();
        $token = $session->get('oauth_access_token');
      }
      catch (\Throwable $e) {
        \Drupal::logger('rep')->error('Token refresh failed: @m', ['@m' => $e->getMessage()]);
        return new JsonResponse($this->keywordTypeFallback($api, $entityType, $input));
      }
    }

    // 5) Monta URL + POST body para o Social autocomplete
    $baseUrl    = rtrim(\Drupal::config('social.oauth.settings')->get('oauth_url'), '/');
    $url        = preg_replace('#/oauth/token$#', '/api/socialm/autocomplete', $baseUrl);
    $consumerId = \Drupal::config('social.oauth.settings')->get('client_id');
    $body       = [
      'token'       => $token,
      'consumer_id' => $consumerId,
      'elementType' => $entityType,
      'keyword'     => $input,
      'pageSize'    => 10,
      'offset'      => 0,
    ];

    // \Drupal::logger('rep')->debug('Social POST to @u with body: @b', [
    //   '@u' => $url,
    //   '@b' => print_r($body, TRUE),
    // ]);

    $client  = \Drupal::httpClient();
    $options = [
      'http_errors' => FALSE,
      'headers'     => [
        'Authorization' => "Bearer {$token}",
        'Accept'        => 'application/json',
      ],
      'json'        => $body,
    ];

    try {
      $response = $client->request('POST', $url, $options);
      $code     = $response->getStatusCode();
      // \Drupal::logger('rep')->debug('Social POST HTTP code: @c', ['@c' => $code]);

      if ($code === 401) {
        $refreshToken();
        $newToken = $session->get('oauth_access_token');
        $options['headers']['Authorization'] = "Bearer {$newToken}";
        $options['json']['token']            = $newToken;
        $response = $client->request('POST', $url, $options);
        $code     = $response->getStatusCode();
        // \Drupal::logger('rep')->debug('Retry POST HTTP code: @c', ['@c' => $code]);
      }

      if ($code !== 200) {
        throw new \Exception("Social endpoint returned HTTP {$code}");
      }

      $rawBody = $response->getBody()->getContents();
      // \Drupal::logger('rep')->debug('Social raw response body: @rb', ['@rb' => substr($rawBody, 0, 1000)]);

      $payload = json_decode($rawBody, FALSE);
      if (is_array($payload)) {
        $makers = $payload;
      }
      elseif (is_object($payload) && isset($payload->body)) {
        $makers = is_array($payload->body)
          ? $payload->body
          : (is_string($payload->body) ? (json_decode($payload->body) ?: []) : []);
      }
      else {
        throw new \Exception('Unknown payload format');
      }

      // \Drupal::logger('rep')->debug('Social makers count: @c', ['@c' => count($makers)]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->error('Social autocomplete failed: @m', ['@m' => $e->getMessage()]);
      $makers = [];
    }

    if (empty($makers) && !empty($legacyMakers)) {
      $makers = $legacyMakers;
    }

    if (empty($makers)) {
      $fallback = $this->keywordTypeFallback($api, $entityType, $input);
      if (!empty($fallback)) {
        return new JsonResponse($fallback);
      }
    }

    // Prefer CURRENT after Social fallback, but keep non-CURRENT when needed.
    $makersFiltered = array_values(array_filter($makers, function ($m) {
      if (is_array($m)) {
        $m = (object) $m;
      }
      if (!is_object($m)) {
        return FALSE;
      }
      if (isset($m->hasStatus) && $m->hasStatus !== NULL && $m->hasStatus !== '' && $m->hasStatus !== VSTOI::CURRENT) {
        return FALSE;
      }
      return TRUE;
    }));
    if (!empty($makersFiltered)) {
      $makers = $makersFiltered;
    }

    // 6) Monta retorno final
    foreach ($makers as $m) {
      if (is_array($m)) {
        $m = (object) $m;
      }
      if (!is_object($m)) {
        continue;
      }
      $uri = (string) ($m->uri ?? '');
      if ($uri === '') {
        continue;
      }
      $label = (string) ($m->label ?? ($m->name ?? $uri));
      $results[] = ['value' => "$label [$uri]", 'label' => $label];
    }

    // \Drupal::logger('rep')->debug('Returning @n suggestions', ['@n' => count($results)]);
    return new JsonResponse($results);
  }

  /**
   * Build fallback suggestions using listByKeywordType.
   */
  private function keywordTypeFallback($api, string $entityType, string $input): array {
    $results = [];
    $typedMakers = [];

    try {
      $status = in_array($entityType, ['person', 'organization'], TRUE) ? VSTOI::CURRENT : '_';
      $typedRaw = $api->listByKeywordType($entityType, 100, 0, 'all', $input, '_', '_', $status);
      $typedObj = is_string($typedRaw)
        ? json_decode($typedRaw)
        : (is_object($typedRaw) ? $typedRaw : json_decode(json_encode($typedRaw)));

      if (is_object($typedObj) && !empty($typedObj->body)) {
        $typedMakers = is_string($typedObj->body)
          ? (json_decode($typedObj->body) ?: [])
          : (is_array($typedObj->body) ? $typedObj->body : []);
      }
      elseif (is_array($typedRaw)) {
        $typedMakers = $typedRaw;
      }
    }
    catch (\Throwable $e) {
      $typedMakers = [];
    }

    $typedMakersFiltered = array_values(array_filter($typedMakers, function ($m) {
      if (is_array($m)) {
        $m = (object) $m;
      }
      if (!is_object($m)) {
        return FALSE;
      }
      if (isset($m->hasStatus) && $m->hasStatus !== NULL && $m->hasStatus !== '' && $m->hasStatus !== VSTOI::CURRENT) {
        return FALSE;
      }
      return TRUE;
    }));
    if (!empty($typedMakersFiltered)) {
      $typedMakers = $typedMakersFiltered;
    }

    foreach ($typedMakers as $m) {
      if (is_array($m)) {
        $m = (object) $m;
      }
      if (!is_object($m)) {
        continue;
      }
      $label = $m->label ?? ($m->name ?? '');
      $uri = $m->uri ?? '';
      if ($uri === '') {
        continue;
      }
      if ($label === '') {
        $label = $uri;
      }
      $results[] = ['value' => "$label [$uri]", 'label' => (string) $label];
    }

    return $results;
  }

}
