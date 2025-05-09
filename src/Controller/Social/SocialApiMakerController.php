<?php

namespace Drupal\rep\Controller\Social;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Utility\Xss;

/**
 * Provides an autocomplete endpoint using the Social API.
 */
class SocialApiMakerController extends ControllerBase {

  /**
   * GET /api/socialm/autocomplete/{entityType}?q=...
   */
  public function handleAutocomplete(string $entityType, Request $request): JsonResponse {
    $results = [];

    // 1) Read and sanitize the search term.
    $input = Xss::filter($request->query->get('q', ''));
    \Drupal::logger('rep')->debug('Autocomplete called for entityType=@et with q="@q"', [
      '@et' => $entityType,
      '@q'  => $input,
    ]);
    if ($input === '') {
      \Drupal::logger('rep')->debug('No input provided, returning empty results.');
      return new JsonResponse($results);
    }

    $makers = [];

    // 2) Call legacy API first.
    try {
      \Drupal::logger('rep')->debug('Calling legacy listByKeyword(@et,@q)', [
        '@et' => $entityType,
        '@q'  => $input,
      ]);
      /** @var \Drupal\rep\ApiConnectorInterface $api */
      $api  = \Drupal::service('rep.api_connector');
      $raw  = $api->listByKeyword($entityType, $input, 9999, 0);
      \Drupal::logger('rep')->debug('Legacy raw response: @r', ['@r' => $raw]);

      $obj = json_decode($raw);
      if (!empty($obj->isSuccessful)) {
        $makers = json_decode($obj->body) ?: [];
      }
      \Drupal::logger('rep')->debug('Legacy decoded makers count: @c', ['@c' => count($makers)]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->warning('Legacy autocomplete failed: @m', ['@m' => $e->getMessage()]);
    }

    // 3) If no legacy results and Social is enabled, fallback to Social.
    if (empty($makers) && \Drupal::config('rep.settings')->get('social_conf')) {
      \Drupal::logger('rep')->debug('No legacy results; Social fallback is enabled.');
      try {
        $session = \Drupal::request()->getSession();
        $token   = $session->get('oauth_access_token');
        \Drupal::logger('rep')->debug('Session token: @t', ['@t' => $token ? substr($token,0,8).'…' : '(none)']);

        // Token refresh closure.
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
          throw new \Exception('Failed to refresh OAuth token.');
        };

        if (empty($token)) {
          $refreshToken();
          $token = $session->get('oauth_access_token');
          \Drupal::logger('rep')->debug('New token after refresh: @t', ['@t' => substr($token,0,8).'…']);
        }

        // Build Social list URL.
        $baseUrl = rtrim(\Drupal::config('social.oauth.settings')->get('oauth_url'), '/');
        $url     = preg_replace('#/oauth/token$#', '/api/socialm/list', $baseUrl);
        \Drupal::logger('rep')->debug('Social list URL: @u', ['@u' => $url]);

        // Prepare POST options.
        $consumerId = \Drupal::config('social.oauth.settings')->get('client_id');
        $options = [
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
            'pageSize'     => 9999,
            'offset'       => 0,
          ],
        ];
        \Drupal::logger('rep')->debug('Social POST body: @b', ['@b' => print_r($options['json'], TRUE)]);

        // Execute request.
        $client   = \Drupal::httpClient();
        $response = $client->request('POST', $url, $options);
        \Drupal::logger('rep')->debug('Social POST HTTP code: @c', ['@c' => $response->getStatusCode()]);

        // Retry on 401.
        if ($response->getStatusCode() === 401) {
          \Drupal::logger('rep')->warning('Social POST 401, refreshing token and retrying.');
          $refreshToken();
          $newToken = $session->get('oauth_access_token');
          $options['headers']['Authorization'] = "Bearer {$newToken}";
          $options['json']['token']            = $newToken;
          $response = $client->request('POST', $url, $options);
          \Drupal::logger('rep')->debug('Retry POST HTTP code: @c', ['@c' => $response->getStatusCode()]);
        }

        if ($response->getStatusCode() === 200) {
          $payload = json_decode($response->getBody()->getContents());
          $makers  = $payload->body ?? [];
          \Drupal::logger('rep')->debug('Social decoded makers count: @c', ['@c' => count($makers)]);
        }
        else {
          throw new \Exception("Social API returned HTTP {$response->getStatusCode()}");
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('rep')->error('Social autocomplete failed: @m', ['@m' => $e->getMessage()]);
      }
    }

    // 4) Build Drupal autocomplete JSON.
    foreach ($makers as $maker) {
      $label = $maker->label ?? '';
      $uri   = $maker->uri   ?? '';
      $results[] = [
        'value' => "$label [$uri]",
        'label' => $label,
      ];
    }

    \Drupal::logger('rep')->debug('Returning @n suggestions.', ['@n' => count($results)]);
    return new JsonResponse($results);
  }

}
