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

    // 1) Read and sanitize the q= param
    $input = Xss::filter($request->query->get('q', ''));
    \Drupal::logger('rep')->debug('Autocomplete for @et with q="@q"', [
      '@et' => $entityType, '@q' => $input,
    ]);
    if ($input === '') {
      return new JsonResponse($results);
    }

    /** @var \Drupal\rep\ApiConnectorInterface $api */
    $api    = \Drupal::service('rep.api_connector');
    $makers = [];

    // 2) Fetch legacy
    try {
      \Drupal::logger('rep')->debug('Calling legacy listByKeyword(@et,@q)', [
        '@et' => $entityType, '@q' => $input,
      ]);
      $raw = $api->listByKeyword($entityType, $input, 9999, 0);
      \Drupal::logger('rep')->debug('Legacy raw response type: @t', ['@t' => gettype($raw)]);

      // Normalize into $makers array
      if (is_array($raw) || is_object($raw)) {
        $success = is_array($raw)
          ? ($raw['isSuccessful'] ?? FALSE)
          : ($raw->isSuccessful ?? FALSE);
        \Drupal::logger('rep')->debug('Legacy isSuccessful? @s', ['@s' => $success ? 'yes' : 'no']);
        if ($success) {
          $body = is_array($raw) ? ($raw['body'] ?? []) : ($raw->body ?? []);
        }
      }
      elseif (is_string($raw)) {
        $obj = json_decode($raw);
        \Drupal::logger('rep')->debug('Legacy JSON decode success? @e', ['@e'=> json_last_error()==JSON_ERROR_NONE?'yes':'no']);
        $body = (!empty($obj->isSuccessful) ? $obj->body : []);
      }
      else {
        $body = [];
      }

      // Body might be JSON string, array, or object
      if (is_string($body)) {
        $makers = json_decode($body) ?: [];
      }
      elseif (is_array($body)) {
        $makers = $body;
      }
      elseif (is_object($body)) {
        $makers = [$body];
      }
      \Drupal::logger('rep')->debug('Parsed legacy makers count: @c', ['@c'=>count($makers)]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->warning('Legacy autocomplete failed: @m', ['@m'=>$e->getMessage()]);
      $makers = [];
    }

    // 3) If none, fallback to Social
    if (empty($makers) && \Drupal::config('rep.settings')->get('social_conf')) {
      \Drupal::logger('rep')->debug('No legacy results; using Social fallback.');
      try {
        $session = \Drupal::request()->getSession();
        $token   = $session->get('oauth_access_token');
        \Drupal::logger('rep')->debug('Session token: @t', ['@t'=>$token?substr($token,0,8).'…':'(none)']);

        // Refresh closure
        $refreshToken = function() use ($session) {
          \Drupal::logger('rep')->debug('Refreshing OAuth token...');
          $resp = call_user_func(
            \Drupal::service('controller_resolver')
              ->getControllerFromDefinition('Drupal\social\Controller\OAuthController::getAccessToken')
          );
          $pl = json_decode($resp->getContent(), TRUE);
          \Drupal::logger('rep')->debug('OAuthController payload: @p', ['@p'=>print_r($pl,TRUE)]);
          if (!empty($pl['body']['access_token'])) {
            $session->set('oauth_access_token', $pl['body']['access_token']);
            return;
          }
          throw new \Exception('Failed to refresh OAuth token');
        };

        if (empty($token)) {
          $refreshToken();
          $token = $session->get('oauth_access_token');
        }

        // Build URL
        $baseUrl = rtrim(\Drupal::config('social.oauth.settings')->get('oauth_url'), '/');
        $url     = preg_replace('#/oauth/token$#', '/api/socialm/list', $baseUrl);
        \Drupal::logger('rep')->debug('Social list URL: @u', ['@u'=>$url]);

        // Options
        $consumerId = \Drupal::config('social.oauth.settings')->get('client_id');
        $options    = [
          'http_errors'=> FALSE,
          'headers'    => [
            'Authorization'=>"Bearer {$token}",
            'Accept'=>'application/json',
          ],
          'json'       => [
            'token'=>$token,
            'consumer_id'=>$consumerId,
            'elementType'=>$entityType,
            'keyword'=>$input,
            'total'=>FALSE,
            'pageSize'=>9999,
            'offset'=>0,
          ],
        ];
        \Drupal::logger('rep')->debug('Social POST body: @b', ['@b'=>print_r($options['json'],TRUE)]);

        $client   = \Drupal::httpClient();
        $response = $client->request('POST', $url, $options);
        $status   = $response->getStatusCode();
        \Drupal::logger('rep')->debug('Social POST HTTP code: @s', ['@s'=>$status]);

        // Retry 401
        if ($status === 401) {
          \Drupal::logger('rep')->warning('Social POST 401, refreshing token & retrying.');
          $refreshToken();
          $newToken = $session->get('oauth_access_token');
          $options['headers']['Authorization']="Bearer {$newToken}";
          $options['json']['token']=$newToken;
          $response = $client->request('POST', $url, $options);
          $status   = $response->getStatusCode();
          \Drupal::logger('rep')->debug('Retry POST code: @s', ['@s'=>$status]);
        }

        if ($status === 200) {
          $rawBody = $response->getBody()->getContents();
          \Drupal::logger('rep')->debug('Social raw response body: @rb', ['@rb' => substr($rawBody, 0, 1000)]);
          $payload = json_decode($rawBody, FALSE);

          // NEW: handle both array and object payloads
          if (is_array($payload)) {
            // Social returns a bare array
            $makers = $payload;
            \Drupal::logger('rep')->debug('Detected bare array payload, count: @c', ['@c' => count($makers)]);
          }
          elseif (is_object($payload) && isset($payload->body)) {
            // Legacy‐style wrapper: { body: [...] }
            $makers = is_array($payload->body)
              ? $payload->body
              : (is_string($payload->body)
                  ? (json_decode($payload->body) ?: [])
                  : []);
            \Drupal::logger('rep')->debug('Detected wrapped payload, count: @c', ['@c' => count($makers)]);
          }
          else {
            \Drupal::logger('rep')->warning('Unknown payload format: @t', ['@t' => gettype($payload)]);
            $makers = [];
          }
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('rep')->error('Social autocomplete failed: @m', [
          '@m' => $e->getMessage(),
        ]);
      }
    }

    // 4) Build suggestions
    foreach ($makers as $maker) {
      $label = $maker->label ?? '';
      $uri   = $maker->uri   ?? '';
      $results[] = ['value'=>"$label [$uri]", 'label'=>$label];
    }

    \Drupal::logger('rep')->debug('Final makers count: @c', ['@c' => count($makers)]);
    \Drupal::logger('rep')->debug('Final results array: @res', ['@res' => print_r($results, TRUE)]);
    return new JsonResponse($results);

  }

}
