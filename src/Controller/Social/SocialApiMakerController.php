<?php

namespace Drupal\rep\Controller\Social;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Utility\Xss;

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

    // 3) Se houver resultados legacy, ou social_conf OFF, devolve-os já.
    $socialEnabled = \Drupal::config('rep.settings')->get('social_conf');
    if (!empty($makers) || !$socialEnabled) {
      // \Drupal::logger('rep')->debug('Returning legacy results (or social disabled).');
      foreach ($makers as $m) {
        $label = $m->label ?? '';
        $uri   = $m->uri   ?? '';
        $results[] = ['value' => "$label [$uri]", 'label' => $label];
      }
      return new JsonResponse($results);
    }

    // 4) Agora sim: fallback ao Social API porque legacy não devolveu nada.
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
        return new JsonResponse($results, 401);
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

    // 6) Monta retorno final
    foreach ($makers as $m) {
      $label = $m->label ?? '';
      $uri   = $m->uri   ?? '';
      $results[] = ['value' => "$label [$uri]", 'label' => $label];
    }

    // \Drupal::logger('rep')->debug('Returning @n suggestions', ['@n' => count($results)]);
    return new JsonResponse($results);
  }

}
