<?php

namespace Drupal\rep\Controller\Social;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Utility\Xss;

/**
 * Provides an autocomplete endpoint that prefers legacy lookups
 * and falls back to the Social API when enabled.
 */
class SocialApiMakerController extends ControllerBase {

  /**
   * GET /api/socialm/autocomplete/{entityType}?q=...
   */
  public function handleAutocomplete(string $entityType, Request $request): JsonResponse {
    $results = [];

    // 1) Read and sanitize the ?q= search term.
    $input = Xss::filter($request->query->get('q', ''));
    if ($input === '') {
      // No input → empty suggestions.
      return new JsonResponse($results);
    }

    /** @var \Drupal\rep\ApiConnectorInterface $api */
    $api = \Drupal::service('rep.api_connector');
    $makers = [];

    // 2) Try the legacy API first.
    try {
      // listByKeyword(elementType, keyword, pageSize, offset)
      $raw   = $api->listByKeyword($entityType, $input, 9999, 0);
      $obj   = json_decode($raw);
      if (!empty($obj->isSuccessful)) {
        // obj->body is a JSON string of items
        $makers = json_decode($obj->body);
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->warning('Legacy autocomplete failed: @m', [
        '@m' => $e->getMessage(),
      ]);
      $makers = [];
    }

    // 3) If no legacy results and Social is enabled, fall back.
    if (empty($makers) && \Drupal::config('rep.settings')->get('social_conf')) {
      try {
        $session = \Drupal::request()->getSession();
        $token   = $session->get('oauth_access_token');

        // Closure to refresh the token via OAuthController.
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
          throw new \Exception('Failed to refresh OAuth token.');
        };

        // Ensure we have a token.
        if (empty($token)) {
          $refreshToken();
          $token = $session->get('oauth_access_token');
        }

        // Build the Social list URL.
        $baseUrl = rtrim(\Drupal::config('social.oauth.settings')->get('oauth_url'), '/');
        $url     = preg_replace('#/oauth/token$#', '/api/socialm/list', $baseUrl);

        // Prepare POST payload.
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
            'total'        => FALSE,
            'pageSize'     => 10,
            'offset'       => 0,
          ],
        ];

        $client   = \Drupal::httpClient();
        $response = $client->request('POST', $url, $options);

        // Retry once on 401 Unauthorized.
        if ($response->getStatusCode() === 401) {
          $refreshToken();
          $newToken = $session->get('oauth_access_token');
          $options['headers']['Authorization'] = "Bearer {$newToken}";
          $options['json']['token']            = $newToken;
          $response = $client->request('POST', $url, $options);
        }

        if ($response->getStatusCode() === 200) {
          $payload = json_decode($response->getBody()->getContents());
          $makers  = $payload->body ?? [];
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('rep')->error('Social autocomplete failed: @m', [
          '@m' => $e->getMessage(),
        ]);
        $makers = [];
      }
    }

    // 4) Format the results for Drupal autocomplete.
    foreach ($makers as $maker) {
      $label = $maker->label ?? '';
      $uri   = $maker->uri   ?? '';
      $results[] = [
        'value' => "$label [$uri]",
        'label' => $label,
      ];
    }

    return new JsonResponse($results);
  }

}
