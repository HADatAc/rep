<?php

namespace Drupal\rep\Controller\Social;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Utility\Xss;

/**
 * Provides an autocomplete endpoint that always queries the Social API.
 */
class SocialApiMakerController extends ControllerBase {

  /**
   * GET /api/socialm/autocomplete/{entityType}?q=...
   */
  public function handleAutocomplete(string $entityType, Request $request): JsonResponse {
    $results = [];

    // 1) Read and sanitize the search term from the "q" query parameter.
    $input = Xss::filter($request->query->get('q', ''));
    if ($input === '') {
      // No input → empty suggestions.
      return new JsonResponse($results);
    }

    // 2) Prepare to call the Social API with OAuth2.
    $session = \Drupal::request()->getSession();
    $token   = $session->get('oauth_access_token');

    // Closure to fetch/refresh the token via Social's OAuthController.
    $refreshToken = function() use ($session) {
      /** @var \Symfony\Component\HttpFoundation\JsonResponse $resp */
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

    // 3) Ensure we have a valid token.
    if (empty($token)) {
      try {
        $refreshToken();
        $token = $session->get('oauth_access_token');
      }
      catch (\Throwable $e) {
        \Drupal::logger('rep')->error('Autocomplete token refresh failed: @m', [
          '@m' => $e->getMessage(),
        ]);
        return new JsonResponse($results);
      }
    }

    // 4) Build the Social‐list endpoint URL.
    $baseUrl = rtrim(\Drupal::config('social.oauth.settings')->get('oauth_url'), '/');
    $url     = preg_replace('#/oauth/token$#', '/api/socialm/list', $baseUrl);

    // 5) Prepare the POST payload.
    $consumerId = \Drupal::config('social.oauth.settings')->get('client_id');
    $options    = [
      'http_errors' => FALSE,
      'headers'     => [
        'Authorization' => "Bearer {$token}",
        'Accept'        => 'application/json',
      ],
      'json'        => [
        'token'        => $token,
        'consumer_id'  => $consumerId,
        'elementType'  => $entityType,
        'keyword'      => $input,
        'total'        => FALSE,  // we want the items themselves
        'pageSize'     => 10,
        'offset'       => 0,
      ],
    ];

    try {
      $client   = \Drupal::httpClient();
      $response = $client->request('POST', $url, $options);

      // 6) Retry once on 401 Unauthorized.
      if ($response->getStatusCode() === 401) {
        $refreshToken();
        $newToken = $session->get('oauth_access_token');
        $options['headers']['Authorization'] = "Bearer {$newToken}";
        $options['json']['token']            = $newToken;
        $response = $client->request('POST', $url, $options);
      }

      if ($response->getStatusCode() !== 200) {
        throw new \Exception("Social API returned HTTP {$response->getStatusCode()}");
      }

      // 7) Decode the response body.
      $payload = json_decode($response->getBody()->getContents());
      $makers  = $payload->body ?? [];

      // 8) Format each item into Drupal autocomplete JSON.
      foreach ($makers as $maker) {
        $label = $maker->label ?? '';
        $uri   = $maker->uri   ?? '';
        $results[] = [
          'value' => "$label [$uri]",
          'label' => $label,
        ];
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->error('Social autocomplete failed: @m', [
        '@m' => $e->getMessage(),
      ]);
    }

    return new JsonResponse($results);
  }

}
