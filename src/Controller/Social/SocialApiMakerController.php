<?php

namespace Drupal\rep\Controller\Social;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Utility\Xss;

/**
 * Autocomplete by proxying to the Social “/api/socialm/autocomplete” endpoint.
 */
class SocialApiMakerController extends ControllerBase {

  /**
   * GET /api/socialm/autocomplete/{entityType}?q=...
   */
  public function handleAutocomplete(string $entityType, Request $request): JsonResponse {
    $results = [];

    // 1) Read & sanitize user input.
    $input = Xss::filter($request->query->get('q', ''));
    \Drupal::logger('rep')->debug('Autocomplete @et called with q="@q"', [
      '@et' => $entityType, '@q' => $input,
    ]);
    if ($input === '') {
      \Drupal::logger('rep')->debug('Empty query, returning no suggestions.');
      return new JsonResponse($results);
    }

    // 2) Ensure the user has a valid OAuth token
    $session = \Drupal::request()->getSession();
    $token   = $session->get('oauth_access_token');
    // Closure to refresh via OAuthController:
    $refreshToken = function() use ($session) {
      \Drupal::logger('rep')->debug('Refreshing OAuth token...');
      $resp = call_user_func(
        \Drupal::service('controller_resolver')
          ->getControllerFromDefinition('Drupal\social\Controller\OAuthController::getAccessToken')
      );
      $pl = json_decode($resp->getContent(), TRUE);
      \Drupal::logger('rep')->debug('OAuthController returned: @p', ['@p' => print_r($pl, TRUE)]);
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
    \Drupal::logger('rep')->debug('Using OAuth token starting with: @t', [
      '@t' => substr($token, 0, 8) . '…',
    ]);

    // 3) Build the Social‐module’s autocomplete URL
    $baseUrl = rtrim(\Drupal::config('social.oauth.settings')->get('oauth_url'), '/');
    $url     = preg_replace('#/oauth/token$#', '/api/socialm/autocomplete', $baseUrl);
    \Drupal::logger('rep')->debug('Proxying to Social endpoint @u', ['@u' => $url]);

    // 4) Prepare the POST body
    $consumerId = \Drupal::config('social.oauth.settings')->get('client_id');
    $body = [
      'token'       => $token,
      'consumer_id' => $consumerId,
      'elementType' => $entityType,
      'keyword'     => $input,
      'pageSize'    => 10,
      'offset'      => 0,
    ];
    \Drupal::logger('rep')->debug('Social POST body: @b', ['@b' => print_r($body, TRUE)]);

    // 5) Do the POST, retry once on 401
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
      \Drupal::logger('rep')->debug('Social POST HTTP code: @c', ['@c' => $code]);

      if ($code === 401) {
        \Drupal::logger('rep')->warning('Got 401, refreshing token and retrying...');
        $refreshToken();
        $newToken = $session->get('oauth_access_token');
        $options['headers']['Authorization'] = "Bearer {$newToken}";
        $options['json']['token']            = $newToken;
        $response = $client->request('POST', $url, $options);
        $code     = $response->getStatusCode();
        \Drupal::logger('rep')->debug('Retry POST HTTP code: @c', ['@c' => $code]);
      }

      if ($code !== 200) {
        throw new \Exception("Social endpoint returned HTTP {$code}");
      }

      // 6) Inspect raw JSON
      $rawBody = $response->getBody()->getContents();
      \Drupal::logger('rep')->debug('Social raw response body: @rb', ['@rb' => substr($rawBody, 0, 1000)]);

      $payload = json_decode($rawBody, FALSE);
      \Drupal::logger('rep')->debug('Social decoded payload type: @t', ['@t' => gettype($payload)]);

      // 7) Normalize into $makers array
      if (is_array($payload)) {
        $makers = $payload;
      }
      elseif (is_object($payload) && isset($payload->body)) {
        $makers = is_array($payload->body)
          ? $payload->body
          : (is_string($payload->body)
              ? (json_decode($payload->body) ?: [])
              : []);
      }
      else {
        throw new \Exception('Unknown payload format');
      }
      \Drupal::logger('rep')->debug('Normalized makers count: @c', ['@c' => count($makers)]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->error('Social autocomplete proxy failed: @m', ['@m' => $e->getMessage()]);
      // FALLBACK to legacy, if you want:
      // $makers = json_decode($api->listByKeyword(...));
      $makers = [];
    }

    // 8) Build Drupal autocomplete response
    foreach ($makers as $m) {
      $label = $m->label ?? '';
      $uri   = $m->uri   ?? '';
      $results[] = [
        'value' => "$label [$uri]",
        'label' => $label,
      ];
    }

    \Drupal::logger('rep')->debug('Returning @n suggestions', ['@n' => count($results)]);
    return new JsonResponse($results);
  }

}
