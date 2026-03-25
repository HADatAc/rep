<?php

namespace Drupal\rep;

use Drupal\Core\Http\ClientFactory;
use Drupal\rep\JWT;
use Drupal\rep\Vocabulary\VSTOI;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use Exception;
use Symfony\Component\HttpFoundation\Response;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Http\Message\ResponseInterface;

class FusekiAPIConnector {

  /**
   * Normalize UI-facing element types into the canonical HASCOAPI element types.
   *
   * The HASCOAPI SIRElementAPI supports "process"/"processstem" (not
   * "workflow"/"workflowstem") for several list endpoints.
   */
  private function normalizeHascoApiElementType($elementType) {
    if ($elementType === NULL) {
      return $elementType;
    }

    $normalized = strtolower(trim((string) $elementType));
    switch ($normalized) {
      case 'workflow':
        return 'process';
      case 'workflowstem':
        return 'processstem';
      default:
        return $elementType;
    }
  }
  private $client;
  private $query;
  private $error;
  private $error_message;
  private $bearer;

  /**
   * Debug info from the most recent HTTP call.
   *
   * @var int|null
   */
  private $last_status_code;

  /**
   * @var string|null
   */
  private $last_response_body;

  /**
   * @var string|null
   */
  private $last_request_url;

  /**
   * Settings Variable.
   */
  Const CONFIGNAME = "rep.settings";

  public function __construct(ClientFactory $client){
  }

  /**
   *   GENERIC
   */

  // public function getUri($uri) {
  //   $endpoint = "/hascoapi/api/uri/".rawurlencode($uri);
  //   $method = "GET";
  //   $api_url = $this->getApiUrl();
  //   $data = $this->getHeader();
  //   return $this->perform_http_request($method,$api_url.$endpoint,$data);
  // }
  /**
   * Fetch a resource by URI, first from the legacy API then (if needed) from Social.
   *
   * @param string $uri
   *   The full resource URI to fetch.
   *
   * @return object|null
   *   Decoded JSON object from either legacy or Social API, or NULL on failure.
   */
  /**
   * Fetch a resource by URI, first from the legacy API then (if needed) from Social.
   *
   * @param string $uri
   *   The full resource URI to fetch.
   *
   * @return string|null
   *   A JSON‐encoded string from either the legacy or Social API, or NULL on failure.
   */
  public function getUri(string $uri) {
    // 1) LEGACY GET
    $endpoint = "/hascoapi/api/uri/".rawurlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    // \Drupal::logger('rep')->debug('Legacy GET: @url', ['@url' => $legacyUrl]);

    $rawLegacy = $this->perform_http_request($method,$api_url.$endpoint,$data);
    // \Drupal::logger('rep')->debug('Legacy raw response: @r', ['@r' => print_r($rawLegacy, TRUE)]);

    // 2) Decode only if it's a string
    if (is_string($rawLegacy)) {
      $decodedLegacy = json_decode($rawLegacy);
    }
    elseif (is_array($rawLegacy)) {
      $decodedLegacy = json_decode(json_encode($rawLegacy));
    }
    else {
      $decodedLegacy = $rawLegacy;
    }
    // \Drupal::logger('rep')->debug('Decoded legacy payload: @d', ['@d' => print_r($decodedLegacy, TRUE)]);
    // dpm($decodedLegacy); // Added dpm() to debug the decoded legacy

    // 3) If legacy succeeded, return JSON string
    if (isset($decodedLegacy->isSuccessful) && $decodedLegacy->isSuccessful === TRUE) {
      // \Drupal::logger('rep')->debug('Legacy successful, returning its JSON.');
      return is_string($rawLegacy)
        ? $rawLegacy
        : json_encode($decodedLegacy);
    }
    // \Drupal::logger('rep')->debug('Legacy not successful, falling back to Social POST.');

    // 4) Fallback enabled?
    if (!\Drupal::config('rep.settings')->get('social_conf')) {
      // \Drupal::logger('rep')->debug('Social fallback disabled, returning legacy JSON.');
      return is_string($rawLegacy)
        ? $rawLegacy
        : json_encode($decodedLegacy);
    }

    // 5) Ensure OAuth token in session
    $session = \Drupal::request()->getSession();
    $token   = $session->get('oauth_access_token');
    // \Drupal::logger('rep')->debug('Session token: @t', ['@t' => $token ? substr($token,0,8).'…' : '(none)']);

    $refresh = function() use ($session) {
      // \Drupal::logger('rep')->debug('Refreshing token…');
      $ctrl = \Drupal::service('controller_resolver')
        ->getControllerFromDefinition('Drupal\social\Controller\OAuthController::getAccessToken');
      $resp = call_user_func($ctrl);
      if ($resp instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
        $pl = json_decode($resp->getContent(), TRUE);
        // \Drupal::logger('rep')->debug('OAuthController payload: @p', ['@p'=>print_r($pl,TRUE)]);
        if (!empty($pl['body']['access_token'])) {
          $session->set('oauth_access_token', $pl['body']['access_token']);
          // \Drupal::logger('rep')->debug('New token saved.');
          return;
        }
      }
      throw new \Exception('Failed to refresh OAuth token.', 401);
    };

    if (empty($token)) {
      try {
        $refresh();
        $token = $session->get('oauth_access_token');
        // \Drupal::logger('rep')->debug('Token after refresh: @t', ['@t'=>substr($token,0,8).'…']);
      }
      catch (\Exception $e) {
        \Drupal::logger('rep')->error('Token refresh failed: @m', ['@m'=>$e->getMessage()]);
        // Return legacy JSON to keep parseObjectResponse happy
        return is_string($rawLegacy)
          ? $rawLegacy
          : json_encode($decodedLegacy);
      }
    }

    // 6) Build Social POST URL exactly like listByKeywordType
    $oauthUrl      = rtrim(\Drupal::config('social.oauth.settings')->get('oauth_url'), '/');
    $socialPostUrl = preg_replace('#/oauth/token$#', '/api/socialm/geturi', $oauthUrl);
    // \Drupal::logger('rep')->debug('Social POST URL: @u', ['@u'=>$socialPostUrl]);

    // 7) Prepare POST body
    $consumerId = \Drupal::config('social.oauth.settings')->get('client_id');
    $postOptions= [
      'http_errors'=> FALSE,
      'headers'    => [
        'Authorization'=> "Bearer {$token}",
        'Accept'       => 'application/json',
      ],
      'json'       => [
        'token'       => $token,
        'consumer_id' => $consumerId,
        'uri'         => $uri,
      ],
    ];
    // \Drupal::logger('rep')->debug('Social POST body: @b', ['@b'=>print_r($postOptions['json'],TRUE)]);

    // 8) Execute POST
    $client   = \Drupal::httpClient();
    $response = $client->request('POST', $socialPostUrl, $postOptions);
    $code     = $response->getStatusCode();
    $body     = $response->getBody()->getContents();
    // \Drupal::logger('rep')->debug('Social POST code: @c', ['@c'=>$code]);
    // \Drupal::logger('rep')->debug('Social POST body: @b', ['@b'=>print_r($body, TRUE)]);

    // 9) Retry once on 401
    if ($code === 401
        || (is_string($body) && json_decode($body) === NULL && stripos($body,'denied')!==FALSE)
    ) {
      // \Drupal::logger('rep')->warning('Unauthorized, refreshing token & retrying…');
      try {
        $refresh();
        $newToken = $session->get('oauth_access_token');
        $postOptions['headers']['Authorization'] = "Bearer {$newToken}";
        $postOptions['json']['token']            = $newToken;
        $response = $client->request('POST', $socialPostUrl, $postOptions);
        $code     = $response->getStatusCode();
        $body     = $response->getBody()->getContents();
        // \Drupal::logger('rep')->debug('Retry code: @c', ['@c'=>$code]);
        // \Drupal::logger('rep')->debug('Retry body: @b', ['@b'=>substr($body,0,200)]);
      }
      catch (\Exception $e) {
        \Drupal::logger('rep')->error('Social retry failed: @m', ['@m'=>$e->getMessage()]);
        return is_string($rawLegacy)
          ? $rawLegacy
          : json_encode($decodedLegacy);
      }
    }

    // 10) HTTP >=400, give up
    if ($code >= 400) {
      \Drupal::logger('rep')->error('Social POST getUri HTTP @c error: @m', [
        '@c'=>$code,'@m'=>substr($body,0,200)
      ]);
      return is_string($rawLegacy)
        ? $rawLegacy
        : json_encode($decodedLegacy);
    }

    // 11) SUCCESS → decode JSON to stdClass and return
    // \Drupal::logger('rep')->debug('Social getUri decoding JSON to object.');
    $data = json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE) {
      \Drupal::logger('rep')->error(
        'Invalid JSON from Social POST getUri: @e',
        ['@e' => json_last_error_msg()]
      );
      return NULL;
    }
    // dpm($data, 'Decoded Social getUri response');
    return $data;
  }

  public function getUsage($uri) {
    $endpoint = "/hascoapi/api/usage/".rawurlencode($uri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getDerivation($uri) {
    $endpoint = "/hascoapi/api/derivation/".rawurlencode($uri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getChildren($uri) {
    $endpoint = "/hascoapi/api/children/".
      rawurlencode($uri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getSubclassesKeyword($superuri, $keyword) {
    $endpoint = "/hascoapi/api/subclasses/keyword/".
      rawurlencode($superuri) . '/' . rawurlencode($keyword);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getSuperClasses($uri) {
    $endpoint = "/hascoapi/api/superclasses/".
      rawurlencode($uri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getHascoType($uri) {
    $endpoint = "/hascoapi/api/hascotype/".rawurlencode($uri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "component", "codebook", "workflow", "responseoption"
  public function listByKeywordAndLanguage($elementType, $keyword, $language, $type, $manageremail, $status, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/keywordlanguage/".
      rawurlencode($keyword)."/".
      rawurlencode($language)."/".
      rawurlencode($type)."/".
      rawurlencode($manageremail)."/".
      rawurlencode($status)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    // dpm($api_url.$endpoint);
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "component", "codebook", "workflow", "responseoption"
  public function listSizeByKeywordAndLanguage($elementType, $keyword, $language, $type, $manageremail, $status) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/keywordlanguage/total/".
      rawurlencode($keyword)."/".
      rawurlencode($language)."/".
      rawurlencode($type)."/".
      rawurlencode($manageremail)."/".
      rawurlencode($status);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method, $api_url.$endpoint, $data);
  }

  public function listByKeyword($elementType, $keyword, $pageSize, $offset) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/keyword/".
      rawurlencode($keyword)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method, $api_url.$endpoint, $data);
  }

  public function listSizeByKeyword($elementType, $keyword) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/keyword/total/".
      rawurlencode($keyword);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "component", "codebook", "workflow", "responseoption"
  public function listByManagerEmail($elementType, $manageremail, $pageSize, $offset) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/manageremail/".
      rawurlencode($manageremail)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "component", "codebook", "workflow", "responseoption"
  public function listByReviewStatus($elementType, $status, $pageSize, $offset) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/status/".
      rawurlencode($status)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "component", "codebook", "workflow", "responseoption"
  public function listSizeByManagerEmail($elementType, $manageremail, ) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType .
      "/manageremail/total/" .
      rawurlencode($manageremail);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function listByManagerEmailByStudy($studyuri, $elementType, $manageremail, $pageSize, $offset) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/manageremailbystudy/".
      rawurlencode($studyuri)."/".
      rawurlencode($manageremail)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // valid values for elementType: "instrument", "component", "codebook", "workflow", "responseoption"
  public function listSizeByManagerEmailByStudy($studyuri, $elementType, $manageremail, ) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType .
      "/manageremailbystudy/total/" .
      rawurlencode($studyuri)."/".
      rawurlencode($manageremail);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function listByManagerEmailBySOC($socuri, $elementType, $manageremail, $pageSize, $offset) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/manageremailbysoc/".
      rawurlencode($socuri)."/".
      rawurlencode($manageremail)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function listSizeByManagerEmailBySOC($socuri, $elementType, $manageremail, ) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType .
      "/manageremailbysoc/total/" .
      rawurlencode($socuri)."/".
      rawurlencode($manageremail);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function listByManagerEmailByContainer($containeruri, $elementType, $manageremail, $pageSize, $offset) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/manageremailbycontainer/".
      rawurlencode($containeruri)."/".
      rawurlencode($manageremail)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function listSizeByManagerEmailByContainer($containeruri, $elementType, $manageremail, ) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/".
      $elementType .
      "/manageremailbycontainer/total/" .
      rawurlencode($containeruri)."/".
      rawurlencode($manageremail);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // /hascoapi/api/$elementType<[^/]+>/keywordtype/$keyword<[^/]+>/$type<[^/]+>/$manageremail<[^/]+>/$status<[^/]+>/$pageSize<[^/]+>/$offset<[^/]+>
  public function listByKeywordType(
      $elementType,
      $pageSize,
      $offset,
      $project      = 'all',
      $keyword      = '_',
      $type         = '_',
      $manageremail = '_',
      $status       = '_'
  ) {
    // 1. If social integration is disabled, call the fallback API and return immediately.
    $socialEnabled = \Drupal::config('rep.settings')->get('social_conf');
    if (! $socialEnabled) {
        $endpoint = "/hascoapi/api/{$elementType}/keywordtype/"
            . rawurlencode($project)      . '/'
            . rawurlencode($keyword)      . '/'
            . rawurlencode($type)         . '/'
            . rawurlencode($manageremail) . '/'
            . rawurlencode($status)       . '/'
            . $pageSize                   . '/'
            . $offset;
        $url  = $this->getApiUrl() . $endpoint;
        $opts = ['headers' => $this->getHeader() ?? []];
        return $this->perform_http_request('GET', $url, $opts);
    }

    // 2. Retrieve the current OAuth token from the session.
    $session = \Drupal::request()->getSession();
    $token   = $session->get('oauth_access_token');

    // 3. Define a local closure to refresh the token on demand.
    $refreshToken = function() use ($session) {
        // Resolve and call the OAuthController::getAccessToken() method.
        $controller = \Drupal::service('controller_resolver')
            ->getControllerFromDefinition('Drupal\social\Controller\OAuthController::getAccessToken');
        $response = call_user_func($controller);

        // If we got a JSON response, extract and save the new access token.
        if ($response instanceof JsonResponse) {
            $payload = json_decode($response->getContent(), TRUE);
            if (!empty($payload['body']['access_token'])) {
                $session->set('oauth_access_token', $payload['body']['access_token']);
                return;
            }
        }

        // If anything went wrong, throw to trigger the retry logic.
        throw new \Exception('Failed to refresh OAuth token.', 401);
    };

    // 4. If there is no token yet, attempt to refresh it immediately.
    if (empty($token)) {
      $refreshToken();
      $token = $session->get('oauth_access_token');
      if (empty($token)) {
          // If refresh failed, we cannot proceed.
          return 'Unauthorized to get social content.';
      }
    }

    // 5. Build the Social API endpoint URL and base request options.
    $consumerId = \Drupal::config('social.oauth.settings')->get('client_id');
    $baseUrl    = rtrim(\Drupal::config('social.oauth.settings')->get('oauth_url'), '/');
    $url        = preg_replace('#/oauth/token$#', '/api/socialm/list', $baseUrl);

    // \Drupal::logger('rep')->notice('Social API URL: @url', ['@url' => $url]);

    $baseOptions = [
        // Prevent Guzzle from throwing exceptions on 4xx/5xx so we can handle status manually.
        'http_errors' => FALSE,
        'headers'     => [
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ],
        'json'        => [
            'token'       => $token,
            'consumer_id' => $consumerId,
            'elementType' => $elementType,
            'keyword'     => $keyword,
            'type'        => $type,
            'manageremail'=> $manageremail,
            'status'      => $status,
            // …add any other required payload fields here…
        ],
    ];

    // 6. Define a closure to perform the HTTP request, check status, and decode JSON.
    $doRequest = function() use ($url, &$baseOptions) {
        $client   = \Drupal::httpClient();
        $response = $client->request('POST', $url, $baseOptions);
        $status   = $response->getStatusCode();
        $raw      = $response->getBody()->getContents();

        // Log the raw response for debugging (truncate to 500 chars).
        // \Drupal::logger('rep')->debug(
        //     'Social API raw (status @s): <pre>@r</pre>',
        //     ['@s' => $status, '@r' => substr($raw, 0, 500)]
        // );

        // 6.a If we got a 401 or a “denied” message in a malformed payload, treat as expired/revoked.
        if (
            $status === 401
            || (json_decode($raw) === null && stripos($raw, 'denied') !== FALSE)
        ) {
            throw new \Exception('Unauthorized or token revoked', 401);
        }

        // 6.b For any other HTTP error (4xx/5xx), log and return an empty stdClass.
        if ($status >= 400) {
            \Drupal::logger('rep')->error(
                'Social API HTTP @s error: @r',
                ['@s' => $status, '@r' => substr($raw, 0, 200)]
            );
            return new \stdClass();
        }

        // 6.c Decode the JSON response into a stdClass object.
        $data = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            \Drupal::logger('rep')->error(
                'Social API invalid JSON (error code @e): @r',
                ['@e' => json_last_error(), '@r' => substr($raw, 0, 200)]
            );
            throw new \Exception('Invalid JSON payload', 400);
        }

        // If decode returned null, fall back to an empty object.
        return $data ?? new \stdClass();
    };

    // 7. Execute the request, retrying once if we catch a 401 exception.
    try {
        // First attempt
        return $doRequest();
    }
    catch (\Exception $e) {
        if ((int) $e->getCode() === 401) {
            \Drupal::logger('rep')->warning('Token invalid, refreshing & retrying.');

            // 7.a Refresh the token and update the request options.
            $refreshToken();
            $newToken = $session->get('oauth_access_token');
            $baseOptions['headers']['Authorization'] = "Bearer {$newToken}";
            $baseOptions['json']['token']            = $newToken;

            // 7.b Retry the request one more time.
            try {
                return $doRequest();
            }
            catch (\Exception $e2) {
                \Drupal::logger('rep')->error(
                    'Second attempt failed after refresh: @m',
                    ['@m' => $e2->getMessage()]
                );
                return new \stdClass();
            }
        }

        // 7.c Any other exception: log and return an empty object.
        \Drupal::logger('rep')->error('Social API request failed: @m', ['@m' => $e->getMessage()]);
        return new \stdClass();
    }
  }

  // /hascoapi/api/$elementType<[^/]+>/keywordtype/total/$keyword<[^/]+>/$type<[^/]+>/$manageremail<[^/]+>/$status<[^/]+>
  public function listSizeByKeywordType(
    $elementType,
    $project      = '_',
    $keyword      = '_',
    $type         = '_',
    $manageremail = '_',
    $status       = '_'
  ) {
    // 1. Fallback to legacy API if social is disabled
    $socialEnabled = \Drupal::config('rep.settings')->get('social_conf');
    if (! $socialEnabled) {
        $endpoint = "/hascoapi/api/{$elementType}/keywordtype/total/"
            . rawurlencode($project)      . '/'
            . rawurlencode($keyword)      . '/'
            . rawurlencode($type)         . '/'
            . rawurlencode($manageremail) . '/'
            . rawurlencode($status);
        $url  = $this->getApiUrl() . $endpoint;
        $opts = ['headers' => $this->getHeader() ?? []];
        return $this->perform_http_request('GET', $url, $opts);
    }

    // 2. Get or refresh token
    $session = \Drupal::request()->getSession();
    $token   = $session->get('oauth_access_token');

    $refreshToken = function() use ($session) {
        // call OAuthController to fetch a new token
        $controller = \Drupal::service('controller_resolver')
            ->getControllerFromDefinition('Drupal\social\Controller\OAuthController::getAccessToken');
        $response = call_user_func($controller);
        if ($response instanceof JsonResponse) {
            $payload = json_decode($response->getContent(), TRUE);
            if (! empty($payload['body']['access_token'])) {
                $session->set('oauth_access_token', $payload['body']['access_token']);
                return;
            }
        }
        throw new \Exception('Failed to refresh OAuth token.', 401);
    };

    if (empty($token)) {
        $refreshToken();
        $token = $session->get('oauth_access_token');
        if (empty($token)) {
            // can't proceed without a token
            return json_encode([
                'isSuccessful' => false,
                'body'         => json_encode(['total' => 0]),
            ]);
        }
    }

    // 3. Build Social API request
    $consumerId = \Drupal::config('social.oauth.settings')->get('client_id');
    $baseUrl    = rtrim(\Drupal::config('social.oauth.settings')->get('oauth_url'), '/');
    $url        = preg_replace('#/oauth/token$#', '/api/socialm/list', $baseUrl);

    $baseOptions = [
        'http_errors' => FALSE,
        'headers'     => [
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ],
        'json'        => [
            'token'        => $token,
            'consumer_id'  => $consumerId,
            'elementType'  => $elementType,
            'project'      => $project,
            'keyword'      => $keyword,
            'type'         => $type,
            'manageremail' => $manageremail,
            'status'       => $status,
            'total'        => TRUE,  // flag to ask for count only
        ],
    ];

    $doRequest = function() use ($url, &$baseOptions) {
        $client   = \Drupal::httpClient();
        $response = $client->request('POST', $url, $baseOptions);
        $status   = $response->getStatusCode();
        $raw      = $response->getBody()->getContents();

        // expired or revoked token
        if ($status === 401
            || (json_decode($raw) === null && stripos($raw, 'denied') !== FALSE)
        ) {
            throw new \Exception('Unauthorized or token revoked', 401);
        }

        // other HTTP errors → empty object
        if ($status >= 400) {
            \Drupal::logger('rep')->error(
                'Social API HTTP @s error: @r',
                ['@s' => $status, '@r' => substr($raw, 0, 200)]
            );
            return new \stdClass();
        }

        $data = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON payload', 400);
        }
        return $data;
    };

    // 4. Execute + retry once on 401
    try {
        $result = $doRequest();
    }
    catch (\Exception $e) {
        if ((int) $e->getCode() === 401) {
            $refreshToken();
            $newToken = $session->get('oauth_access_token');
            $baseOptions['headers']['Authorization'] = "Bearer {$newToken}";
            $baseOptions['json']['token']            = $newToken;

            try {
                $result = $doRequest();
            }
            catch (\Exception $e2) {
                $result = new \stdClass();
            }
        }
        else {
            $result = new \stdClass();
        }
    }

    // 5. Normalize into the SAME structure your total() expects
    //    { isSuccessful: bool, body: JSON-string-of-{total:X} }
    $count = 0;
    if (is_array($result)) {
        $count = count($result);
    }
    elseif (isset($result->body) && is_object($result->body) && isset($result->body->total)) {
        $count = (int) $result->body->total;
    }
    elseif (isset($result->total)) {
        $count = (int) $result->total;
    }

    $payload = [
        'isSuccessful' => true,
        'body'         => json_encode(['total' => $count]),
    ];

    return json_encode($payload);
  }

  public function uningestMT($metadataTemplateUri) {
    $endpoint = "/hascoapi/api/uningest/mt/" . rawurlencode($metadataTemplateUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function elementAdd($elementType, $elementJson) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/" .
      $elementType .
      "/create/".
      rawurlencode($elementJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function elementDel($elementType, $elementUri) {
    $elementType = $this->normalizeHascoApiElementType($elementType);
    $endpoint = "/hascoapi/api/" .
      $elementType .
      "/delete/" .
      rawurlencode($elementUri);
    // hascoapi's Play CSRF filter blocks POST deletes (403) in some deployments.
    // hascoapi exposes GET delete routes as well; use GET for compatibility.
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/:elementtype/bysoc/:socuri/:pageSize/:offset                                  org.hascoapi.console.controllers.restapi.StudyObjectCollectionAPI.getElementsBySOC(socuri : String, elementtype: String, pageSize : Integer, offset : Integer)
  public function listElementsBySOC($elementType, $socuri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/".
      $elementType.
      "/bysoc/".
      rawurlencode($socuri)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }
  // GET     /hascoapi/api/:elementtype/bysoc/total/:socuri                                              org.hascoapi.console.controllers.restapi.StudyObjectCollectionAPI.getTotalElementsBySOC(socuri : String, elementtype : String)
  public function listSizeElementsBySOC($elementType, $socuri) {
    $endpoint = "/hascoapi/api/".
      $elementType .
      "/bysoc/total/" .
      rawurlencode($socuri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /******************************************************************************
   *
   *                             E L E M E N T S
   *
   ******************************************************************************/

  /**
   *   ANNOTATION
   */

   public function annotationAdd($annotationJson) {
    $endpoint = "/hascoapi/api/annotation/create/".rawurlencode($annotationJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function annotationDel($annotationUri) {
    $endpoint = "/hascoapi/api/annotation/delete/".rawurlencode($annotationUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function annotationByContainerAndPosition($containerUri,$positionUri) {
    $endpoint = "/hascoapi/api/annotationsbycontainerposition/".rawurlencode($containerUri)."/".rawurlencode($positionUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   ANNOTATION STEMS
   */

   public function annotationStemAdd($annotationStemJson) {
    $endpoint = "/hascoapi/api/annotationstem/create/".rawurlencode($annotationStemJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function annotationStemDel($annotationStemUri) {
    $endpoint = "/hascoapi/api/annotationstem/delete/".rawurlencode($annotationStemUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   CODEBOOK
   */

   public function codebookAdd($codebookJson) {
    $endpoint = "/hascoapi/api/codebook/create/".rawurlencode($codebookJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function codebookDel($codebookUri) {
    $endpoint = "/hascoapi/api/codebook/delete/".rawurlencode($codebookUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   CODEBOOK SLOT
   */

  public function codebookSlotList($codebookUri) {
    $endpoint = "/hascoapi/api/slots/bycodebook/".rawurlencode($codebookUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function codebookSlotAdd($codebookUri,$totalCodebookSlots) {
    $endpoint = "/hascoapi/api/slots/codebook/create/".rawurlencode($codebookUri)."/".rawurlencode($totalCodebookSlots);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function codebookSlotDel($containerUri) {
    $endpoint = "/hascoapi/api/slots/codebook/delete/".rawurlencode($containerUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function codebookSlotReset($containerSlotUri) {
    $endpoint = "/hascoapi/api/slots/codebook/detach/".rawurlencode($containerSlotUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   PROCESS
   */

  public function processAdd($processJson) {
    $endpoint = "/hascoapi/api/process/create/".rawurlencode($processJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function processDel($workflowUri) {
    $endpoint = "/hascoapi/api/process/delete/".rawurlencode($workflowUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function processInstrumentAdd($workflowUri, array $instrumentUris) {
    $endpoint = "/hascoapi/api/process/instruments";
    $method = "POST";
    $api_url = $this->getApiUrl();

    if ($this->bearer == NULL) {
        $this->bearer = "Bearer " . JWT::jwt();
    }

    // Estrutura o payload JSON no formato esperado pela API
    $payload = json_encode([
        'processuri' => $workflowUri,
        'instrumenturis' => $instrumentUris
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);  // Facilita a leitura no log

    $data = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => $this->bearer
        ],
        'body' => $payload
    ];

    // 📊 Log do Payload JSON
    \Drupal::logger('rep')->notice('Payload enviado para API: @payload', [
        '@payload' => $payload
    ]);

    // Envia a requisição para a API
    $response = $this->perform_http_request($method, $api_url . $endpoint, $data);

    return $response;
  }

  public function processInstrumentUpdate(array $processData) {
    $endpoint = "/hascoapi/api/process/instrument";
    $method = "POST";
    $api_url = $this->getApiUrl();

    $payload = json_encode($processData);

    \Drupal::logger('rep')->notice('Enviando instrumentos para o processo: @payload', [
        '@payload' => $payload,
    ]);

    $data = [
      'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => $this->bearer
      ],
      'body' => json_encode($processData)
    ];

    $response = $this->perform_http_request($method, $api_url . $endpoint, $data);

    return $response;
  }

  public function processInstrumentDel($workflowUri, $componentUri) {
    $endpoint = "/hascoapi/api/process/instrument/remove/".rawurlencode($workflowUri).'/'.rawurlencode($componentUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function processComponentAdd($workflowUri, $componentUri) {
    $endpoint = "/hascoapi/api/process/component/add/".rawurlencode($workflowUri).'/'.rawurlencode($componentUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function processComponentDel($workflowUri, $instrumentUri) {
    $endpoint = "/hascoapi/api/process/component/remove/".rawurlencode($workflowUri).'/'.rawurlencode($instrumentUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET /hascoapi/api/workflow/deletewithtasks/:processUri org.hascoapi.console.controllers.restapi.ProcessAPI.deleteWithTasks(processUri: String)
  public function workflowDeleteWithTasks($workflowUri) {
    $endpoint = "/hascoapi/api/workflow/deletewithtasks/".rawurlencode($workflowUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *    TASKS
   */

  public function taskSetRequiredInstruments(array $payload) {
    $endpoint = "/hascoapi/api/task/instruments";
    $method   = "POST";
    $api_url = $this->getApiUrl();

    if ($this->bearer === NULL) {
        $this->bearer = "Bearer " . JWT::jwt();
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $data = [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => $this->bearer,
        ],
        'body'    => $json,
    ];

    try {
        // $ch = curl_init($url);
        // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        // curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(
        //     fn($k, $v) => "$k: $v",
        //     array_keys($options['headers']),
        //     $options['headers']
        // ));
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);

        // $resp     = curl_exec($ch);
        // $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // $curlErr  = curl_error($ch);
        // curl_close($ch);

        // $decoded = json_decode($resp, true);

        // return $decoded;
        $response = $this->perform_http_request($method, $api_url . $endpoint, $data);

        return $response;
    }
    catch (\Exception $e) {
        \Drupal::logger('rep')->error('Exception em taskSetRequiredInstruments(): @msg', [
          '@msg' => $e->getMessage()
        ]);
        throw $e;
    }
  }

  // POST /hascoapi/api/task/deletewithtasks/$taskUri<[^/]+> org.hascoapi.console.controllers.restapi.TaskAPI.deleteWithTasks(taskUri:String)
  public function taskDeleteWithTasks($taskUri) {
    $endpoint = "/hascoapi/api/task/deletewithtasks/".rawurlencode($taskUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *    PROJECTS
   */

   public function projectMemberUpdate(array $projectData) {
    $endpoint = "/hascoapi/api/project/member";
    $method = "POST";
    $api_url = $this->getApiUrl();

    $payload = json_encode($projectData);

    \Drupal::logger('rep')->notice('Sending member data to project: @payload', [
        '@payload' => $payload,
    ]);

    $data = [
      'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => $this->bearer
      ],
      'body' => json_encode($projectData)
    ];

    $response = $this->perform_http_request($method, $api_url . $endpoint, $data);

    return $response;
  }

  /**
   *    CONTAINER SLOTS
   */

  public function containerslotAdd($containerUri,$totalContainerSlots) {
    $endpoint = "/hascoapi/api/slots/container/create/".rawurlencode($containerUri)."/".rawurlencode($totalContainerSlots);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function containerslotDel($containerUri) {
    $endpoint = "/hascoapi/api/slots/container/delete/".rawurlencode($containerUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function containerslotReset($containerslotUri) {
    $endpoint = "/hascoapi/api/slots/container/detach/".rawurlencode($containerslotUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function containerslotAttach($componentUri,$containerslotUri) {
    $endpoint = "/hascoapi/api/slots/container/attach/".rawurlencode($componentUri)."/".rawurlencode($containerslotUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   DATAFILE
   */

   public function datafileAdd($datafileJson) {
    $endpoint = "/hascoapi/api/datafile/create/".rawurlencode($datafileJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function datafileDel($datafileUri) {
    $endpoint = "/hascoapi/api/datafile/delete/".rawurlencode($datafileUri);
    // Use GET to avoid Play CSRF 403 on POST delete.
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   DEPLOYMENT
   */

   public function deploymentByStateEmail($state, $email, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/deployment/".
      $state."/".
      $email."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function deploymentSizeByStateEmail($state, $email) {
    $endpoint = "/hascoapi/api/deployment/total/".
      $state."/".
      $email."/";
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function deploymentsByPlatformInstanceWithPage($platformInstanceUri,$pageSize,$offset) {
    $endpoint = "/hascoapi/api/deploymentbyplatforminstance/".
      rawurlencode($platformInstanceUri).'/'.
      $pageSize.'/'.
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function sizeDeploymentsByPlatformInstance($platformInstanceUri) {
    $endpoint = "/hascoapi/api/deploymentbyplatforminstance/total/".
      rawurlencode($platformInstanceUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   COMPONENTS
   */

  public function componentAdd($componentJson) {
    $endpoint = "/hascoapi/api/component/create/".rawurlencode($componentJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function componentDel($componentUri) {
    $endpoint = "/hascoapi/api/component/delete/".rawurlencode($componentUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function componentAttach($componentUri,$containerslotUri) {
    $endpoint = "/hascoapi/api/slots/container/attach/".rawurlencode($componentUri)."/".rawurlencode($containerslotUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   COMPONENT STEMS
   */

   public function componentStemAdd($componentStemJson) {
    $endpoint = "/hascoapi/api/componentstem/create/".rawurlencode($componentStemJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function componentStemDel($componentStemUri) {
    $endpoint = "/hascoapi/api/componentstem/delete/".rawurlencode($componentStemUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   PROCESS STEMS
   */

   public function processStemAdd($processStemJson) {
    $endpoint = "/hascoapi/api/processstem/create/".rawurlencode($processStemJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function processStemDel($processStemUri) {
    $endpoint = "/hascoapi/api/processstem/delete/".rawurlencode($processStemUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // Workflow methods (aliases for process methods)
  public function workflowstemAdd($workflowstemJson) {
    return $this->processStemAdd($workflowstemJson);
  }

  public function workflowstemDel($workflowstemUri) {
    return $this->processStemDel($workflowstemUri);
  }

  /**
   *   INSTRUMENTS
   */

   public function instrumentRendering($type,$instrumentUri) {
    if ($type == 'fhir' || $type == 'rdf') {
      $endpoint = "/hascoapi/api/instrument/to".$type."/".rawurlencode($instrumentUri);
    } else {
      $endpoint = "/hascoapi/api/instrument/totext/".$type."/".rawurlencode($instrumentUri);
    }
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function instrumentAdd($instrumentJson) {
    $endpoint = "/hascoapi/api/instrument/create/".rawurlencode($instrumentJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function instrumentDel($instrumentUri) {
    $endpoint = "/hascoapi/api/instrument/delete/".rawurlencode($instrumentUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function reviewRecursive($instrumentUri,$status = VSTOI::UNDER_REVIEW) {
    $endpoint = "/hascoapi/api/review/recursive/".rawurlencode($instrumentUri)."/".rawurlencode($status);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   ORGANIZATION
   */

   public function organizationAdd($organizationJson) {
    $endpoint = "/hascoapi/api/organization/create/".rawurlencode($organizationJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function organizationDel($organizationUri) {
    $endpoint = "/hascoapi/api/organization/delete/".rawurlencode($organizationUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getSubOrganizations($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/organization/suborganizations/".
      urlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalSubOrganizations($uri) {
    $endpoint = "/hascoapi/api/organization/suborganizations/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getAffiliations($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/organization/affiliations/".
      urlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalAffiliations($uri) {
    $endpoint = "/hascoapi/api/organization/affiliations/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   PERSON
   */

   public function personAdd($personJson) {
    $endpoint = "/hascoapi/api/person/create/".rawurlencode($personJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function personDel($personUri) {
    $endpoint = "/hascoapi/api/person/delete/".rawurlencode($personUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   PLACE
   */

   public function placeAdd($placeJson) {
    $endpoint = "/hascoapi/api/place/create/".rawurlencode($placeJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function placeDel($placeUri) {
    $endpoint = "/hascoapi/api/place/delete/".rawurlencode($placeUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getContains($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/place/contains/place/".
      rawurlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalContains($uri) {
    $endpoint = "/hascoapi/api/place/contains/place/total/".
      rawurlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   PLATFORM INSTANCE
   */

   public function platforminstancesByPlatformwithPage($platformUri,$pageSize,$offset) {
    $endpoint = "/hascoapi/api/platforminstance/byplatform/".rawurlencode($platformUri).'/'.$pageSize.'/'.$offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    //dpm($api_url.$endpoint);
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function sizePlatforminstancesByPlatform($platformUri) {
    $endpoint = "/hascoapi/api/platforminstance/byplatform/total/".rawurlencode($platformUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   POSTAL ADDRESS
   */

   public function postalAddressAdd($postalAddressJson) {
    $endpoint = "/hascoapi/api/postaladdress/create/".rawurlencode($postalAddressJson);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function postalAddressDel($postalAddressUri) {
    $endpoint = "/hascoapi/api/postaladdress/delete/".rawurlencode($postalAddressUri);
    $method = 'POST';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getContainsPostalAddress($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/place/contains/postaladdress/".
      rawurlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalContainsPostalAddress($uri) {
    $endpoint = "/hascoapi/api/place/contains/postaladdress/total/".
      rawurlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getContainsElement($uri, $elementtype, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/place/contains/element/".
      rawurlencode($uri)."/".
      $elementtype."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalContainsElement($uri, $elementtype) {
    $endpoint = "/hascoapi/api/place/contains/element/total/".
      rawurlencode($uri)."/".
      $elementtype;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   RESPONSE OPTION
   */

  public function responseOptionAdd($responseoptionJSON) {
    $endpoint = "/hascoapi/api/responseoption/create/".rawurlencode($responseoptionJSON);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function responseOptionDel($responseOptionUri) {
    $endpoint = "/hascoapi/api/responseoption/delete/".rawurlencode($responseOptionUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function responseOptionAttach($responseOptionUri,$containerSlotUri) {
    $endpoint = "/hascoapi/api/slots/codebook/attach/".rawurlencode($responseOptionUri)."/".rawurlencode($containerSlotUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // ATTACH AND CHANGE R.O. STATUS
  public function responseOptionAttachStatus($responseOptionUri,$containerSlotUri,$status = VSTOI::DRAFT) {
    $endpoint = "/hascoapi/api/slots/codebook/attach/status/".rawurlencode($responseOptionUri)."/".rawurlencode($containerSlotUri)."/".rawurlencode($status);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   SEMANTIC VARIABLE
   */

   public function semanticVariableAdd($semanticVariableJson) {
    $endpoint = "/hascoapi/api/semanticvariable/create/".rawurlencode($semanticVariableJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function semanticVariableDel($semanticVariableUri) {
    $endpoint = "/hascoapi/api/semanticvariable/delete/".rawurlencode($semanticVariableUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   SLOT ELEMENT
   */

   public function slotElements($containerUri) {
    $endpoint = "/hascoapi/api/slotelements/bycontainer/".rawurlencode($containerUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   STREAM
   */

  public function streamByStudyState( $studyuri, $state, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/stream/bystudy/".
      rawurlencode($studyuri)."/".
      rawurlencode($state)."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function streamSizeByStudyState( $studyuri, $state) {
    $endpoint = "/hascoapi/api/stream/bystudy/total/".
      rawurlencode($studyuri)."/".
      rawurlencode($state);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function streamByStateEmail($state, $email, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/stream/bystateemail/".
      $state."/".
      $email."/".
      $pageSize."/".
      $offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function streamSizeByStateEmail($state, $email) {
    $endpoint = "/hascoapi/api/stream/bystateemail/total/".
      $state."/".
      $email."/".
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   * STREAM TOPIC
   */

  // GET     /hascoapi/api/topic/subscribed org.hascoapi.console.controllers.restapi.StreamTopicAPI.findActive()
  public function streamTopicSubscribed() {
    $endpoint = "/hascoapi/api/topic/subscribed";
      // $state."/".
      // $email."/".
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }
  // GET     /hascoapi/api/topic/subscribe/:topicUri org.hascoapi.console.controllers.restapi.StreamTopicAPI.subscribe(topicUri: String)
  public function streamTopicSubscribe($topicuri) {
    $endpoint = "/hascoapi/api/topic/subscribe/".
      rawurlencode($topicuri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }
  // GET     /hascoapi/api/topic/unsubscribe/:topicUri org.hascoapi.console.controllers.restapi.StreamTopicAPI.unsubscribe(topicUri: String)
  public function streamTopicUnsubscribe($topicuri) {
    $endpoint = "/hascoapi/api/topic/unsubscribe/".
      rawurlencode($topicuri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }
  // GET     /hascoapi/api/topic/setstatus/:topicUri/:status org.hascoapi.console.controllers.restapi.StreamTopicAPI.setStatus(topicUri: String, status: String)
  public function streamTopicSetStatus($topicuri, $status) {
    $endpoint = "/hascoapi/api/topic/setstatus/".
      rawurlencode($topicuri)."/".
      rawurlencode($status);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET /hascoapi/api/topic/latest/$topicUri<[^/]+>org.hascoapi.console.controllers.restapi.StreamTopicAPI.getLatestValue(topicUri:String)
  public function streamTopicLatestMessage($topicuri) {
    $endpoint = "/hascoapi/api/topic/latest/".
      rawurlencode($topicuri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/dataacquisition/bytopic/:uri/:pageSize/:offset                org.hascoapi.console.controllers.restapi.DAAPI.findDAsByStreamTopic(uri: String, pageSize : Integer, offset : Integer)
  public function getDAsByStreamTopic($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/dataacquisition/bytopic/".rawurlencode($uri)."/".$pageSize."/".$offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/dataacquisition/bytopic/total/:uri                            org.hascoapi.console.controllers.restapi.DAAPI.findTotalDAsByStreamTopic(uri: String)
  public function getTotalDAsByStreamTopic($uri) {
    $endpoint = "/hascoapi/api/dataacquisition/bytopic/total/".rawurlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   STUDY
   */

  public function getStudyVCs($uri) {
    $endpoint = "/hascoapi/api/study/virtualcolumns/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getStudySOCs($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/study/socs/".
      urlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getStudySTRs($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/study/streams/".
      urlencode($uri)."/".
      $pageSize."/".
      $offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // public function getTotalStudyDAs($uri) {
  //   $endpoint = "/hascoapi/api/study/dataacquisitions/total/".
  //     urlencode($uri);
  //   $method = "GET";
  //   $api_url = $this->getApiUrl();
  //   $data = $this->getHeader();
  //   return $this->perform_http_request($method,$api_url.$endpoint,$data);
  // }

  // GET     /hascoapi/api/dataacquisition/bystream/:uri/:pageSize/:offset
  public function getStudyDAsByStream($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/dataacquisition/bystream/".rawurlencode($uri)."/".$pageSize."/".$offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/dataacquisition/bystream/total/:uri^
  public function getTotalStudyDAsByStream($uri) {
    $endpoint = "/hascoapi/api/dataacquisition/bystream/total/".rawurlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/dataacquisition/bystudy/:uri/:pageSize/:offset
  public function getStudyDAsByStudy($uri, $pageSize, $offset) {
    $endpoint = "/hascoapi/api/dataacquisition/bystudy/".rawurlencode($uri)."/".$pageSize."/".$offset;
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/dataacquisition/bystudy/total/:uri
  public function getTotalStudyDAsByStudy($uri) {
    $endpoint = "/hascoapi/api/dataacquisition/bystudy/total/".rawurlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudyRoles($uri) {
    $endpoint = "/hascoapi/api/study/studyroles/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudyVCs($uri) {
    $endpoint = "/hascoapi/api/study/virtualcolumns/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudySOCs($uri) {
    $endpoint = "/hascoapi/api/study/socs/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudySOs($uri) {
    $endpoint = "/hascoapi/api/study/studyobjects/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function getTotalStudySTRs($uri) {
    $endpoint = "/hascoapi/api/study/streams/total/".
      urlencode($uri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   STUDY OBJECT COLLECTION
   */

  public function studyObjectCollectionsByStudy($studyUri) {
    $endpoint = "/hascoapi/api/studyobjectcollection/bystudy/".rawurlencode($studyUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   STUDY OBJECT
   */

  public function studyObjectsBySOCwithPage($socUri,$pageSize,$offset) {
    $endpoint = "/hascoapi/api/studyobject/bysoc/".rawurlencode($socUri).'/'.$pageSize.'/'.$offset;
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function sizeStudyObjectsBySOC($socUri) {
    $endpoint = "/hascoapi/api/studyobject/bysoc/total/".rawurlencode($socUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   SUBCONTAINERS
   */

   public function subcontainerAdd($subcontainerJson) {
    $endpoint = "/hascoapi/api/subcontainer/create/".rawurlencode($subcontainerJson);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function subcontainerDel($subcontainerUri) {
    $endpoint = "/hascoapi/api/subcontainer/delete/".rawurlencode($subcontainerUri);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function subcontainerUpdate($json) {
    $endpoint = "/hascoapi/api/subcontainer/update/".rawurlencode($json);
    $method = "POST";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   VIRTUAL COLUMN
   */

  public function virtualColumnsByStudy($studyUri) {
    $endpoint = "/hascoapi/api/virtualcolumn/bystudy/".rawurlencode($studyUri);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /***************************************************************************
   *
   *                          R E P O S I T O R Y
   *
   ***************************************************************************/

  public function repoInfo() {
    $endpoint = "/hascoapi/api/repo";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoInfoNewIP($api_url) {
    $endpoint = "/hascoapi/api/repo";
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoUpdateLabel($api_url, $label) {
    $endpoint = "/hascoapi/api/repo/label/".rawurlencode($label);
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoUpdateTitle($api_url, $title) {
    $endpoint = "/hascoapi/api/repo/title/".rawurlencode($title);
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoUpdateURL($api_url, $url) {
    $endpoint = "/hascoapi/api/repo/url/".rawurlencode($url);
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoUpdateDescription($api_url, $description) {
    $endpoint = "/hascoapi/api/repo/description/".rawurlencode($description);
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoUpdateNamespace($api_url, $prefix, $url, $mime, $source) {
    if ($mime == '') {
      $mime = '_';
    }
    if ($source == '') {
      $source = '_';
    }
    $endpoint = "/hascoapi/api/repo/namespace/default/".
      rawurlencode($prefix)."/".
      rawurlencode($url)."/".
      rawurlencode($mime)."/".
      rawurlencode($source);
    $method = "GET";
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoResetNamespaces() {
    $endpoint = "/hascoapi/api/repo/namespace/reset/";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoReloadNamespaceTriples() {
    $endpoint = "/hascoapi/api/repo/ont/load";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoReloadSelectedNamespaceTriples(array $namespaces) {
    // HASCO API does not expose a "reload selected ontologies" route.
    // Implement it directly via Fuseki:
    //  1) CLEAR GRAPH <namespaceUri>
    //  2) PUT triples to graph store endpoint (?graph=<namespaceUri>)

    $namespaces = array_values(array_filter($namespaces, static fn($v) => is_string($v) && trim($v) !== ''));
    if (empty($namespaces)) {
      return json_encode([
        'isSuccessful' => false,
        'body' => 'No namespaceUris have been provided.',
      ]);
    }

    // Load current namespace list to map URI -> source + mime.
    $rawList = $this->namespaceList();
    $list = $rawList ? $this->parseObjectResponse($rawList, 'namespaceList') : NULL;
    if (!is_array($list)) {
      return json_encode([
        'isSuccessful' => false,
        'body' => 'Could not retrieve namespace list from the API.',
      ]);
    }

    $byUri = [];
    foreach ($list as $nsObj) {
      if (is_object($nsObj) && !empty($nsObj->uri)) {
        $byUri[(string) $nsObj->uri] = $nsObj;
      }
    }

    // 1) Clear selected graphs.
    $clearSparql = '';
    foreach ($namespaces as $graphUri) {
      $graphUri = (string) $graphUri;
      $clearSparql .= 'CLEAR SILENT GRAPH <' . $graphUri . ">;\n";
    }

    $clearResult = $this->fusekiSparqlUpdate($clearSparql);
    if (empty($clearResult['ok'])) {
      return json_encode([
        'isSuccessful' => false,
        'body' => $clearResult['message'] ?? 'Failed to clear selected graphs in Fuseki.',
      ]);
    }

    // 2) Reload triples for each namespace.
    $loaded = 0;
    $skipped = 0;
    $errors = [];

    foreach ($namespaces as $graphUri) {
      $graphUri = (string) $graphUri;
      $nsObj = $byUri[$graphUri] ?? NULL;
      if (!$nsObj || empty($nsObj->source)) {
        $skipped++;
        continue;
      }

      $source = (string) $nsObj->source;
      $mime = !empty($nsObj->sourceMime) ? (string) $nsObj->sourceMime : 'text/turtle';

      $contentResult = $this->fetchRemoteContent($source, $mime);
      if (empty($contentResult['ok'])) {
        $errors[] = 'Failed to fetch ' . $source . ': ' . ($contentResult['message'] ?? 'unknown error');
        continue;
      }

      $putResult = $this->fusekiPutGraph($graphUri, (string) $contentResult['body'], $mime);
      if (empty($putResult['ok'])) {
        $errors[] = 'Failed to load graph <' . $graphUri . '>: ' . ($putResult['message'] ?? 'unknown error');
        continue;
      }
      $loaded++;
    }

    $msg = "Reload requested for selected ontologies. Loaded=$loaded Skipped=$skipped";
    if (!empty($errors)) {
      $msg .= '. Errors: ' . implode(' | ', array_slice($errors, 0, 5));
      if (count($errors) > 5) {
        $msg .= ' | ...';
      }
    }

    return json_encode([
      'isSuccessful' => true,
      'body' => $msg,
    ]);
  }

  public function repoDeleteSelectedNamespaceTriples(array $namespaces) {
    $namespaces = array_values(array_filter($namespaces, static fn($v) => is_string($v) && trim($v) !== ''));
    if (empty($namespaces)) {
      return json_encode([
        'isSuccessful' => false,
        'body' => 'No namespaceUris have been provided.',
      ]);
    }

    $sparql = '';
    foreach ($namespaces as $graphUri) {
      $graphUri = (string) $graphUri;
      $sparql .= 'CLEAR SILENT GRAPH <' . $graphUri . ">;\n";
    }

    $result = $this->fusekiSparqlUpdate($sparql);
    if (empty($result['ok'])) {
      return json_encode([
        'isSuccessful' => false,
        'body' => $result['message'] ?? 'Failed to delete triples from selected graphs in Fuseki.',
      ]);
    }

    return json_encode([
      'isSuccessful' => true,
      'body' => 'Selected ontology triples have been requested to be DELETED.',
    ]);
  }

  private function getFusekiUpdateUrlCandidates(): array {
    $config = \Drupal::config(static::CONFIGNAME);
    $explicit = $config->get('fuseki_update_url');
    $candidates = [];

    if (is_string($explicit) && trim($explicit) !== '') {
      $candidates[] = trim($explicit);
    }

    $apiUrl = (string) ($this->getApiUrl() ?? '');
    $parts = $apiUrl ? @parse_url($apiUrl) : false;
    $scheme = (is_array($parts) && !empty($parts['scheme'])) ? $parts['scheme'] : 'http';
    $host = (is_array($parts) && !empty($parts['host'])) ? $parts['host'] : 'localhost';

    foreach ([3030, 6060] as $port) {
      $candidates[] = $scheme . '://' . $host . ':' . $port . '/store/update';
    }

    // Common Docker service name.
    $candidates[] = 'http://fuseki:3030/store/update';
    $candidates[] = 'http://localhost:3030/store/update';

    // De-dup while preserving order.
    $out = [];
    foreach ($candidates as $c) {
      if (!in_array($c, $out, true)) {
        $out[] = $c;
      }
    }
    return $out;
  }

  private function getFusekiGraphStoreUrlCandidates(): array {
    $config = \Drupal::config(static::CONFIGNAME);
    $explicit = $config->get('fuseki_gsp_url');
    $candidates = [];

    if (is_string($explicit) && trim($explicit) !== '') {
      $candidates[] = trim($explicit);
    }

    $apiUrl = (string) ($this->getApiUrl() ?? '');
    $parts = $apiUrl ? @parse_url($apiUrl) : false;
    $scheme = (is_array($parts) && !empty($parts['scheme'])) ? $parts['scheme'] : 'http';
    $host = (is_array($parts) && !empty($parts['host'])) ? $parts['host'] : 'localhost';

    foreach ([3030, 6060] as $port) {
      $candidates[] = $scheme . '://' . $host . ':' . $port . '/store/data';
    }

    $candidates[] = 'http://fuseki:3030/store/data';
    $candidates[] = 'http://localhost:3030/store/data';

    $out = [];
    foreach ($candidates as $c) {
      if (!in_array($c, $out, true)) {
        $out[] = $c;
      }
    }
    return $out;
  }

  private function fusekiSparqlUpdate(string $sparql): array {
    $client = new Client();
    $last = ['url' => null, 'status' => null, 'body' => null];

    foreach ($this->getFusekiUpdateUrlCandidates() as $url) {
      try {
        $res = $client->request('POST', $url, [
          'http_errors' => false,
          'headers' => [
            'Content-Type' => 'application/sparql-update',
            'Accept' => 'application/json',
          ],
          'body' => $sparql,
        ]);

        $status = (int) $res->getStatusCode();
        $body = (string) $res->getBody();
        $last = ['url' => $url, 'status' => $status, 'body' => $body];

        if ($status === 200 || $status === 204) {
          return ['ok' => true, 'url' => $url, 'status' => $status, 'body' => $body];
        }
      }
      catch (\Throwable $e) {
        $last = ['url' => $url, 'status' => null, 'body' => $e->getMessage()];
        continue;
      }
    }

    $msg = 'Failed to execute SPARQL Update in Fuseki.';
    if (!empty($last['url'])) {
      $msg .= ' Last tried: ' . $last['url'];
    }
    if (!empty($last['status'])) {
      $msg .= ' (HTTP ' . $last['status'] . ')';
    }
    if (!empty($last['body'])) {
      $msg .= ' Response: ' . substr(preg_replace('/\s+/', ' ', (string) $last['body']), 0, 500);
    }

    return ['ok' => false, 'message' => $msg];
  }

  private function fetchRemoteContent(string $url, string $preferredMime = 'text/turtle'): array {
    $client = new Client();
    try {
      $res = $client->request('GET', $url, [
        'http_errors' => false,
        'allow_redirects' => true,
        'headers' => [
          'Accept' => $preferredMime ?: '*/*',
        ],
      ]);
      $status = (int) $res->getStatusCode();
      $body = (string) $res->getBody();
      if ($status >= 200 && $status < 300 && $body !== '') {
        return ['ok' => true, 'body' => $body, 'status' => $status];
      }
      return ['ok' => false, 'message' => 'HTTP ' . $status . ' when fetching ' . $url];
    }
    catch (\Throwable $e) {
      return ['ok' => false, 'message' => $e->getMessage()];
    }
  }

  private function fusekiPutGraph(string $graphUri, string $content, string $contentType): array {
    $client = new Client();
    $last = ['url' => null, 'status' => null, 'body' => null];

    foreach ($this->getFusekiGraphStoreUrlCandidates() as $base) {
      $url = rtrim($base, '/') . '?graph=' . rawurlencode($graphUri);
      try {
        $res = $client->request('PUT', $url, [
          'http_errors' => false,
          'headers' => [
            'Content-Type' => $contentType ?: 'text/turtle',
            'Accept' => 'application/json',
          ],
          'body' => $content,
        ]);

        $status = (int) $res->getStatusCode();
        $body = (string) $res->getBody();
        $last = ['url' => $url, 'status' => $status, 'body' => $body];

        if ($status === 200 || $status === 201 || $status === 204) {
          return ['ok' => true, 'url' => $url, 'status' => $status, 'body' => $body];
        }
      }
      catch (\Throwable $e) {
        $last = ['url' => $url, 'status' => null, 'body' => $e->getMessage()];
        continue;
      }
    }

    $msg = 'Failed to load triples into Fuseki graph store.';
    if (!empty($last['url'])) {
      $msg .= ' Last tried: ' . $last['url'];
    }
    if (!empty($last['status'])) {
      $msg .= ' (HTTP ' . $last['status'] . ')';
    }
    if (!empty($last['body'])) {
      $msg .= ' Response: ' . substr(preg_replace('/\s+/', ' ', (string) $last['body']), 0, 500);
    }
    return ['ok' => false, 'message' => $msg];
  }

  public function repoDeleteSelectedNamespace($abbreviation) {
    $endpoint = "/hascoapi/api/repo/namespace/delete/".rawurlencode($abbreviation);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoCreateNamespace($json) {
    $endpoint = "/hascoapi/api/repo/namespace/create/".rawurlencode($json);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function repoDeleteNamespaceTriples() {
    $endpoint = "/hascoapi/api/repo/ont/delete";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/repo/namespace/topclasses/:abbreviation org.hascoapi.console.controllers.restapi.RepoPage.getTopClasses(abbreviation : String)
  public function repoTopClassNamespaces($abbreviation) {
    $endpoint = "/hascoapi/api/repo/namespace/topclasses/".rawurlencode($abbreviation);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**************************************************************************
   *
   *                     E R R O R     M E T H O D S
   *
   **************************************************************************/

   public function getError() {
    return $this->error;
  }

  public function getErrorMessage() {
    return $this->error_message;
  }

  /**
   *   AUXILIARY TABLES
   */

  public function namespaceList() {
    $endpoint = "/hascoapi/api/repo/table/namespaces";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function informantList() {
    $endpoint = "/hascoapi/api/repo/table/informants";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function languageList() {
    $endpoint = "/hascoapi/api/repo/table/languages";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function generationActivityList() {
    $endpoint = "/hascoapi/api/repo/table/generationactivities";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function instrumentPositionList() {
    $endpoint = "/hascoapi/api/repo/table/instrumentpositions";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  public function subcontainerPositionList() {
    $endpoint = "/hascoapi/api/repo/table/subcontainerpositions";
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  /**
   *   AUXILIATY METHODS
   */

  public function getApiUrl() {
    $config = \Drupal::config(static::CONFIGNAME);
    return $config->get("api_url");
  }

  public function getHeader() {
    if ($this->bearer == NULL) {
      $this->bearer = "Bearer " . JWT::jwt();
    }
    return ['headers' =>
      [
        'Authorization' => $this->bearer
      ]
    ];
  }

  public function uploadTemplate($concept,$template,$status) {

    // VALIDATE STATUS
    if ($status != "_" && $status != VSTOI::DRAFT && $status != VSTOI::CURRENT) {
      \Drupal::messenger()->addError(t('UploadTemplate: Invalid value for status: [' . $status . ']'));
      return FALSE;
    }

    // CHECK IF FILE ID EXISTS TO DETERMINE WHICH APPROACH TO USE
    if (isset($template->hasDataFile->id) && $template->hasDataFile->id != NULL) {
      // TWO-STEP APPROACH: First upload file, then trigger ingestion
      
      // STEP 1: Upload the file to hascoapi
      \Drupal::logger('rep')->notice('UploadTemplate: Uploading file first for template: @uri', [
        '@uri' => $template->uri,
      ]);
      
      $uploadResult = $this->uploadFile($template->hasDataFileUri, $template->hasDataFile->id);
      if ($uploadResult == NULL) {
        \Drupal::messenger()->addError(t('Could not upload file to API before ingestion for template: @uri', ['@uri' => $template->uri]));
        return FALSE;
      }
      
      \Drupal::logger('rep')->notice('UploadTemplate: File uploaded successfully, now triggering ingestion for template: @uri', [
        '@uri' => $template->uri,
      ]);
    } else {
      // SIMPLIFIED APPROACH: NO FILE CONTENT UPLOAD
      \Drupal::logger('rep')->notice('UploadTemplate: Using simplified approach without file content for template: @uri', [
        '@uri' => $template->uri,
      ]);
    }

    // STEP 2: Trigger ingestion (without file content in body)
    $endpoint = "/hascoapi/api/ingest/".rawurlencode($status)."/".$concept."/".rawurlencode($template->uri);

    // MAKE CALL TO API ENDPOINT
    $api_url = $this->getApiUrl();
    $client = new Client();
    try {
      $request_options = [
        'headers' => [
          'Content-Type' => 'application/json',
          // 'Authorization' => $this->bearer
        ],
      ];

      $res = $client->post($api_url.$endpoint, $request_options);
    } catch(ConnectException $e){
      $this->error="CON";
      $this->error_message = "Connection error the following message: " . $e->getMessage();
      \Drupal::messenger()->addError(t('UploadTemplate: Connection error: [' . $this->error_message . ']'));
      return(NULL);
    } catch(ClientException $e){
      $res = $e->getResponse();
      if($res->getStatusCode() != '200') {
        $this->error=$res->getStatusCode();
        $this->error_message = "API request returned the following status code: " . $res->getStatusCode();
        \Drupal::messenger()->addError(t('UploadTemplate: API error: [' . $this->error_message . ']'));
        return(NULL);
      }
    }
    return($res->getBody());
  }

  public function simplifiedUpload($concept,$template,$status) {

    // VALIDATE STATUS
    if ($status != "_" && $status != VSTOI::DRAFT && $status != VSTOI::CURRENT) {
      \Drupal::messenger()->addError(t('SimplifiedUpload: Invalid value for status: [' . $status . ']'));
      return FALSE;
    }

    // APPEND DATAFILE URI AND STATUS TO ENDPOINT'S URL
    $endpoint = "/hascoapi/api/ingest/".rawurlencode($status)."/".$concept."/".rawurlencode($template->uri);

    // MAKE CALL TO API ENDPOINT WITHOUT FILE CONTENT
    $api_url = $this->getApiUrl();
    $client = new Client();
    try {
      $res = $client->post($api_url.$endpoint, [
        'headers' => [
          'Content-Type' => 'application/json',
          // 'Authorization' => $this->bearer
        ],
      ]);
    } catch(ConnectException $e){
      $this->error="CON";
      $this->error_message = "Connection error the following message: " . $e->getMessage();
      \Drupal::messenger()->addError(t('SimplifiedUpload: Connection error: [' . $this->error_message . ']'));
      return(NULL);
    } catch(ClientException $e){
      $res = $e->getResponse();
      if($res->getStatusCode() != '200') {
        $this->error=$res->getStatusCode();
        $this->error_message = "API request returned the following status code: " . $res->getStatusCode();
        \Drupal::messenger()->addError(t('SimplifiedUpload: API error: [' . $this->error_message . ']'));
        return(NULL);
      }
    }
    return($res->getBody());
  }

  public function perform_http_request($method, $url, $data = false) {
    $client = new Client();
    $res = NULL;
    $this->error = NULL;
    $this->error_message = "";
    $this->last_status_code = NULL;
    $this->last_response_body = NULL;
    $this->last_request_url = $url;

    $options = [];
    if (is_array($data)) {
      $options = $data;
    }

    // Always capture the status/body instead of throwing exceptions.
    if (!array_key_exists('http_errors', $options)) {
      $options['http_errors'] = FALSE;
    }

    try {
      $res = $client->request($method, $url, $options);
    }
    catch (ConnectException $e) {
      $this->error = "CON";
      $this->error_message = "Connection error the following message: " . $e->getMessage();
      try {
        \Drupal::logger('rep.api')->error('API connection error for {method} {url}: {message}', [
          'method' => $method,
          'url' => $url,
          'message' => $e->getMessage(),
        ]);
      }
      catch (\Throwable $t) {
        // Ignore logging failures.
      }
      return NULL;
    }
    catch (RequestException $e) {
      // For completeness; should be rare when http_errors=FALSE.
      $res = $e->getResponse();
      if ($res === NULL) {
        $this->error = "REQ";
        $this->error_message = "Request error the following message: " . $e->getMessage();
        try {
          \Drupal::logger('rep.api')->error('API request error for {method} {url}: {message}', [
            'method' => $method,
            'url' => $url,
            'message' => $e->getMessage(),
          ]);
        }
        catch (\Throwable $t) {
          // Ignore logging failures.
        }
        return NULL;
      }
    }

    $status = (int) $res->getStatusCode();
    $body = (string) $res->getBody();
    $this->last_status_code = $status;
    $this->last_response_body = $body;

    if ($status !== 200) {
      $this->error = (string) $status;
      $snippet = substr(preg_replace('/\s+/', ' ', $body), 0, 500);
      $this->error_message = "API request returned the following status code: " . $status;
      if ($snippet !== '') {
        $this->error_message .= "; response: " . $snippet;
      }

      // Always log non-200 responses so they are visible even when UI
      // messenger messages are not.
      try {
        $context = [
          'method' => $method,
          'url' => $url,
          'status' => $status,
          'snippet' => $snippet ?: '(empty)',
        ];
        if ($status >= 500) {
          \Drupal::logger('rep.api')->error('API {method} {url} returned HTTP {status}: {snippet}', $context);
        }
        else {
          \Drupal::logger('rep.api')->warning('API {method} {url} returned HTTP {status}: {snippet}', $context);
        }
      }
      catch (\Throwable $t) {
        // Ignore logging failures.
      }
      return NULL;
    }

    return $body;
  }

  /**
   *  If anything goes wrong, this method will return NULL and issue a Drupal error message fowrarding the message provided by
   *  the HASCO API.
   */
//   public function parseObjectResponse($response, $methodCalled) {
//     if ($this->error != NULL) {
//       if ($this->error == 'CON') {
//         \Drupal::messenger()->addError(t("Connection with API is broken. Either the Internet is down, the API is down or the API IP configuration is incorrect."));
//       } else {
//         \Drupal::messenger()->addError(t("API ERROR " . $this->error . ". Message: " . $this->error_message));
//       }
//       return NULL;
//     }
//     if ($response == NULL || $response == "") {
//         \Drupal::messenger()->addError(t("API service has returned no response: called " . $methodCalled));
//         return NULL;
//     }

//     // Se já veio um array (já decodificado), devolve-o logo
//     if (is_array($response)) {
//       return $response;
//     }

//     // 4) If it's a stream or other object with __toString(), cast to string.
//     // if (!is_string($response) && method_exists($response, '__toString')) {
//     //   $response = (string) $response;
//     // }
//     if (!is_string($response) && is_object($response) && method_exists($response, '__toString')) {
//       $response = (string) $response;
//     }

//     $obj = json_decode($response);
//     if ($obj == NULL) {
//       \Drupal::messenger()->addError(t("API service has failed with following RAW message: [" . $response . "]"));
//       return NULL;
//     }
//     if ($obj->isSuccessful) {
//       return $obj->body;
//     }
//     $message = $obj->body;
//     if ($message != NULL && is_string($message) &&
//         str_starts_with($message,"No") && str_ends_with($message,"has been found")) {
//       return array();
//     }
//     \Drupal::messenger()->addError(t("API service has failed with following message: " . $obj->body));
//     return NULL;
//   }

  public function parseObjectResponse($response, $methodCalled) {
    // 1) Any prior connection or HTTP error?
    if ($this->error !== NULL) {
      if ($this->error === 'CON') {
        \Drupal::messenger()->addError(t('Connection with API is broken. Either the Internet is down, the API is down or the API IP configuration is incorrect.'));
      }
      else {
        \Drupal::messenger()->addError(t('API ERROR @code. Message: @msg', [
          '@code' => $this->error,
          '@msg'  => $this->error_message,
        ]));
      }
      return NULL;
    }

    // 2) Empty response?
    if ($response === NULL || $response === '') {
      \Drupal::messenger()->addError(t('API service has returned no response: called @method', [
        '@method' => $methodCalled,
      ]));
      return NULL;
    }

    // 3) If already decoded into an array or object, return it immediately.
    if (is_array($response) || is_object($response)) {
      return $response;
    }

    // 4) If it's a stream or other object with __toString(), cast to string.
    if (!is_string($response) && is_object($response) && method_exists($response, '__toString')) {
      $response = (string) $response;
    }

    // 5) Now decode the JSON string.
    $obj = json_decode($response);
    if ($obj === NULL) {
      $raw = is_string($response) ? $response : json_encode($response);
      $snippet = substr(preg_replace('/\s+/', ' ', (string) $raw), 0, 500);
      $status = $this->last_status_code !== NULL ? (string) $this->last_status_code : 'unknown';
      $url = $this->last_request_url ?: 'unknown';
      
      \Drupal::messenger()->addError(t('API service returned a non-JSON response for @method (HTTP @status). URL: @url Response: @snippet', [
        '@method' => $methodCalled,
        '@status' => $status,
        '@url' => $url,
        '@snippet' => $snippet ?: '(empty)',
      ]));
      return NULL;
    }

    // 6) If the call indicated success, return its body.
    if (!empty($obj->isSuccessful)) {
      return $obj->body;
    }

    // 7) Handle “no results” case specifically.
    $message = $obj->body ?? '';
    if (is_string($message)
        && str_starts_with($message, 'No')
        && str_ends_with($message, 'has been found')
    ) {
      return [];
    }

    // 8) Otherwise surface the API error.
    \Drupal::messenger()->addError(t('API service has failed with following message: @msg', [
      '@msg' => $obj->body,
    ]));
    return NULL;
  }

  /**
   *  If anything goes wrong, this method will return NULL and issue a Drupal error message forwarding the message provided by
   *  the HASCO API.
   */
  public function parseTotalResponse($response, $methodCalled) {
    if ($this->error != NULL) {
      if ($this->error == 'CON') {
        \Drupal::messenger()->addError(t("Connection with API is broken. Either the Internet is down, the API is down or the API IP configuration is incorrect."));
      } else {
        \Drupal::messenger()->addError(t("API ERROR " . $this->error . ". Message: " . $this->error_message));
      }
      return NULL;
    }
    if ($response == NULL || $response == "") {
        \Drupal::messenger()->addError(t("API service has returned no response: called " . $methodCalled));
        return NULL;
    }
    $totalValue = -1;
    $obj = json_decode($response);
    if ($obj == NULL) {
      \Drupal::messenger()->addError(t("API service has failed with following RAW message: [" . $response . "]"));
      return NULL;
    }
    if ($obj->isSuccessful) {
      $totalStr = $obj->body;
      $obj2 = json_decode($totalStr);
      $totalValue = $obj2->total;
    }
    return $totalValue;
  }

  // Return List of Component elements from Instrument to Fill on Workflow
  public function componentListFromInstrument($instrumentUri) {
    $endpoint = "/hascoapi/api/instrument/components/".rawurlencode($instrumentUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // Return List of Component Containerslots to fill the Workflow Tasks
  public function containersListFromInstrument($instrumentUri) {
    $endpoint = "/hascoapi/api/instrument/containerslots/".rawurlencode($instrumentUri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GENERATE MT METHODS
  // GET     /hascoapi/api/mt/gen/perstatus/:elementtype/:datafileuri/:status/:filename/:mediafolder/:verifyuri                   org.hascoapi.console.controllers.restapi.IngestionAPI.mtGenByStatus(elementtype : String, datafileuri : String, status: String, filename: String, mediafolder : String, verifyuri : String)
  // Per status (KGR)
  public function generateMTKGRPerStatus($elementtype, $datafileuri, $status, $filename, $mediafolder, $verifyuri) {
    $endpoint = "/hascoapi/api/mt/gen/perstatus/".rawurlencode($elementtype)."/".rawurlencode($datafileuri)."/".rawurlencode($status)."/".rawurlencode($filename)."/".rawurlencode($mediafolder)."/".rawurlencode($verifyuri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }
  // Per status
  public function generateMTPerStatus($elementtype, $datafileuri, $status, $filename, $mediafolder, $verifyuri) {
    $endpoint = "/hascoapi/api/mt/gen/perstatus/".rawurlencode($elementtype)."/".rawurlencode($datafileuri)."/".rawurlencode($status)."/".rawurlencode($filename)."/".rawurlencode($mediafolder)."/".rawurlencode($verifyuri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/mt/gen/perelement/:elementtype/:datafileuri/:elementuri/:filename/:mediafolder/:verifyuri              org.hascoapi.console.controllers.restapi.IngestionAPI.mtGenByElement(elementtype : String, datafileuri : String, elementuri: String, filename: String, mediafolder : String, verifyuri : String)
  public function generateMTPerElement($elementtype, $datafileuri, $elementUri, $filename, $mediafolder, $verifyuri) {
    $endpoint = "/hascoapi/api/mt/gen/perelement/".rawurlencode($elementtype)."/".rawurlencode($datafileuri)."/".rawurlencode($elementUri)."/".rawurlencode($filename)."/".rawurlencode($mediafolder)."/".rawurlencode($verifyuri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/mt/gen/peruser/:elementtype/:datafileuri/:useremail/:status/:filename/:mediafolder/:verifyuri          org.hascoapi.console.controllers.restapi.IngestionAPI.mtGenByManager(elementtype : String, datafileuri : String, useremail: String, status: String, filename: String, mediafolder : String, verifyuri : String)
  public function generateMTPerUserStatus($elementtype,$datafileuri, $userEmail, $status, $filename, $mediafolder, $verifyuri, $datafileUri) {
    $endpoint = "/hascoapi/api/mt/gen/peruser/".rawurlencode($elementtype)."/".rawurlencode($datafileuri)."/".rawurlencode($userEmail)."/".rawurlencode($status)."/".rawurlencode($filename)."/".rawurlencode($mediafolder)."/".rawurlencode($verifyuri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/mt/gen/perfundingscheme/:elementtype/:fundingschemeuri/:filename
  // Per Funding Scheme
  public function generateMTPerFundingScheme($elementtype, $fundingSchemeUri, $filename, $mediafolder, $verifyuri) {
    $endpoint = "/hascoapi/api/mt/gen/perfundingscheme/".rawurlencode($elementtype)."/".rawurlencode($fundingSchemeUri)."/".rawurlencode($filename)."/".rawurlencode($mediafolder)."/".rawurlencode($verifyuri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/mt/gen/perproject/:elementtype/:projecturi/:filename
  // Per Project
  public function generateMTPerProject($elementtype, $projectUri, $filename, $mediafolder, $verifyuri) {
    $endpoint = "/hascoapi/api/mt/gen/perproject/".rawurlencode($elementtype)."/".rawurlencode($projectUri)."/".rawurlencode($filename)."/".rawurlencode($mediafolder)."/".rawurlencode($verifyuri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // GET     /hascoapi/api/mt/gen/perorganization/:elementtype/:organizationuri/:filename
  // Per Organization
  public function generateMTPerOrganization($elementtype, $organizationUri, $filename, $mediafolder, $verifyuri) {
    $endpoint = "/hascoapi/api/mt/gen/perorganization/".rawurlencode($elementtype)."/".rawurlencode($organizationUri)."/".rawurlencode($filename)."/".
      rawurlencode($mediafolder)."/".rawurlencode($verifyuri);
    $method = "GET";
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method,$api_url.$endpoint,$data);
  }

  // POST     /hascoapi/api/mt/get/generated/:filename
  public function downloadGeneratedFile($filename) {
    // Ensure bearer token is initialized even if this is the first API call
    // in the request lifecycle.
    $this->getHeader();

    $endpoint = "/hascoapi/api/mt/get/generated/" . rawurlencode($filename);
    $api_url = $this->getApiUrl();
    $client = new Client();

    try {
      $res = $client->post($api_url . $endpoint, [
        'headers' => [
          'Authorization' => $this->bearer,
        ],
        'http_errors' => false, // don't throw on 404/204
      ]);

      $status = $res->getStatusCode();
      if ($status !== 200) {
        $this->last_status_code = (int) $status;
        $this->last_request_url = $api_url . $endpoint;
        $this->last_response_body = (string) $res->getBody();
        $snippet = substr(preg_replace('/\s+/', ' ', (string) $this->last_response_body), 0, 500);
        $this->error = (string) $status;
        $this->error_message = "API request returned the following status code: " . $status;
        if ($snippet !== '') {
          $this->error_message .= "; response: " . $snippet;
        }
        try {
          \Drupal::logger('rep.api')->warning('API POST {url} returned HTTP {status}: {snippet}', [
            'url' => $this->last_request_url,
            'status' => (int) $status,
            'snippet' => $snippet ?: '(empty)',
          ]);
        }
        catch (\Throwable $t) {
          // Ignore logging failures.
        }
        return NULL;
      }

      $file_content = $res->getBody()->getContents();
      $content_type = $res->getHeaderLine('Content-Type') ?: 'application/octet-stream';
    }
    catch (\Exception $e) {
      return NULL;
    }

    $response = new Response($file_content);
    $response->headers->set('Content-Type', $content_type);
    return $response;
  }

  // POST    /hascoapi/api/uploadFile/:elementuri  org.hascoapi.console.controllers.restapi.DataFileAPI.uploadFile(elementuri: String, request: play.mvc.Http.Request)
  public function uploadFile($elementuri, $fileId) {
    // RETRIEVE FILE CONTENT FROM FID
    $file_entity = \Drupal\file\Entity\File::load($fileId);
    if ($file_entity == NULL) {
      \Drupal::messenger()->addError(t('Could not retrive file with following FID: [' . $fileId . ']'));
      return FALSE;
    }

    $filename = $file_entity->getFilename();
    $file_uri = $file_entity->getFileUri();
    $file_content = file_get_contents($file_uri);

    if ($file_content == NULL) {
      \Drupal::messenger()->addError(t('Could not retrive file content from file with following FID: [' . $fileId . ']'));
      return FALSE;
    }

    // APPEND ELEMENT URI ENDPOINT'S URL
    $endpoint = "/hascoapi/api/uploadFile/".rawurlencode($elementuri). "/" . rawurlencode($filename);;

    // MAKE CALL TO API ENDPOINT
    $api_url = $this->getApiUrl();
    $client = new Client();
    try {
      $res = $client->post($api_url.$endpoint, [
        'headers' => [
          'Content-Type' => $file_entity->getMimeType(),
          'Authorization' => $this->bearer
        ],
        'body' => $file_content,
      ]);
    } catch(ConnectException $e){
      $this->error="CON";
      $this->error_message = "Connection error the following message: " . $e->getMessage();
      \Drupal::messenger()->addError(t('Upload: Invalid value for status: [' . $this->error_message . ']'));
      return(NULL);
    } catch(ClientException $e){
      $res = $e->getResponse();
      if($res->getStatusCode() != '200') {
        $this->error=$res->getStatusCode();
        $this->error_message = "API request returned the following status code: " . $res->getStatusCode();
        \Drupal::messenger()->addError(t('Upload: Invalid value for status: [' . $this->error_message . ']'));
        return(NULL);
      }
    }
    return($res->getBody());
  }

  // POST    /hascoapi/api/downloadFile/:elementuri/:filename  org.hascoapi.console.controllers.restapi.DataFileAPI.downloadFile(elementuri: String, filename: String)
  public function downloadFile($elementuri, $filename) {
    // Ensure bearer token is initialized even if this is the first API call
    // in the request lifecycle.
    $this->getHeader();

    $endpoint = "/hascoapi/api/downloadFile/" . rawurlencode($elementuri) . "/" . rawurlencode($filename);
    $api_url = $this->getApiUrl();
    $client = new Client();

    try {
      $res = $client->post($api_url . $endpoint, [
        'headers' => [
          'Authorization' => $this->bearer,
        ],
        'http_errors' => false,
      ]);

      $status = $res->getStatusCode();
      if ($status !== 200) {
        $this->last_status_code = (int) $status;
        $this->last_request_url = $api_url . $endpoint;
        $this->last_response_body = (string) $res->getBody();
        $snippet = substr(preg_replace('/\s+/', ' ', (string) $this->last_response_body), 0, 500);
        $this->error = (string) $status;
        $this->error_message = "API request returned the following status code: " . $status;
        if ($snippet !== '') {
          $this->error_message .= "; response: " . $snippet;
        }
        try {
          \Drupal::logger('rep.api')->warning('API POST {url} returned HTTP {status}: {snippet}', [
            'url' => $this->last_request_url,
            'status' => (int) $status,
            'snippet' => $snippet ?: '(empty)',
          ]);
        }
        catch (\Throwable $t) {
          // Ignore logging failures.
        }
        return NULL;
      }

      $file_content = $res->getBody()->getContents();
      $content_type = $res->getHeaderLine('Content-Type') ?: 'application/octet-stream';
    }
    catch (\Exception $e) {
      // \Drupal::messenger()->addError(t('Error Downloading image: @msg', ['@msg' => $e->getMessage()]));
      return NULL;
    }

    // Make http reply
    $response = new Response($file_content);
    $response->headers->set('Content-Type', $content_type);
    return $response;
  }

  // /hascoapi/api/uploadMedia/:foldername/:filename
  public function uploadMediaFile($fileId, $foldername) {
    // RETRIEVE FILE CONTENT FROM FID
    $file_entity = \Drupal\file\Entity\File::load($fileId);
    if ($file_entity == NULL) {
      \Drupal::messenger()->addError(t('Could not retrive file with following FID: [' . $fileId . ']'));
      return FALSE;
    }

    $filename = $file_entity->getFilename();
    $file_uri = $file_entity->getFileUri();
    $file_content = file_get_contents($file_uri);

    if ($file_content == NULL) {
      \Drupal::messenger()->addError(t('Could not retrive file content from file with following FID: [' . $fileId . ']'));
      return FALSE;
    }

    // APPEND ELEMENT URI ENDPOINT'S URL
    $endpoint = "/hascoapi/api/uploadMedia/".rawurlencode($foldername) . '/' . rawurlencode($filename);

    // MAKE CALL TO API ENDPOINT
    $api_url = $this->getApiUrl();
    $client = new Client();
    try {
      $res = $client->post($api_url.$endpoint, [
        'headers' => [
          'Content-Type' => $file_entity->getMimeType(),
          'Authorization' => $this->bearer
        ],
        'body' => $file_content,
      ]);
    } catch(ConnectException $e){
      $this->error="CON";
      $this->error_message = "Connection error the following message: " . $e->getMessage();
      \Drupal::messenger()->addError(t('Upload: Invalid value for status: [' . $this->error_message . ']'));
      return(NULL);
    } catch(ClientException $e){
      $res = $e->getResponse();
      if($res->getStatusCode() != '200') {
        $this->error=$res->getStatusCode();
        $this->error_message = "API request returned the following status code: " . $res->getStatusCode();
        \Drupal::messenger()->addError(t('Upload: Invalid value for status: [' . $this->error_message . ']'));
        return(NULL);
      }
    }
    return($res->getBody());
  }

  /**
   * Download a “file” (e.g. image) via the Social API.
   *
   * @param string $uri
   *   The resource URI.
   * @param string $fileName
   *   The file path/identifier as returned by the KG.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   The Guzzle response with binary content, or NULL on failure.
   */
  /**
 * Attempt to download a file (e.g. image) via the Social API.
 *
 * @param string $uri
 *   The full resource URI in the KG.
 * @param string $fileName
 *   The image filename or path returned by the KG.
 *
 * @return \Psr\Http\Message\ResponseInterface|null
 *   A PSR-7 response containing the binary data on success, or NULL on failure.
 */
  public function downloadFileSocial(string $uri, string $fileName) {
    // 1) Entry log
    // \Drupal::logger('rep')->debug('downloadFileSocial(): uri=@u, file=@f', [
    //   '@u' => $uri,
    //   '@f' => $fileName,
    // ]);

    // 2) Ensure OAuth token in session (refresh if needed).
    $session = \Drupal::request()->getSession();
    $token   = $session->get('oauth_access_token');
    // \Drupal::logger('rep')->debug('downloadFileSocial(): session token before refresh: @t', [
    //   '@t' => $token ? substr($token, 0, 8) . '…' : '(none)',
    // ]);

    $refresh = function() use ($session) {
      // \Drupal::logger('rep')->debug('downloadFileSocial(): refreshing OAuth token...');
      $controller = \Drupal::service('controller_resolver')
        ->getControllerFromDefinition('Drupal\social\Controller\OAuthController::getAccessToken');
      $response = call_user_func($controller);
      if ($response instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
        $payload = json_decode($response->getContent(), TRUE);
        // \Drupal::logger('rep')->debug('downloadFileSocial(): OAuthController payload: @p', [
        //   '@p' => print_r($payload, TRUE),
        // ]);
        if (!empty($payload['body']['access_token'])) {
          $session->set('oauth_access_token', $payload['body']['access_token']);
          // \Drupal::logger('rep')->debug('downloadFileSocial(): new token saved.');
          return;
        }
      }
      throw new \Exception('Failed to refresh OAuth token.', 401);
    };

    if (empty($token)) {
      try {
        $refresh();
        $token = $session->get('oauth_access_token');
        // \Drupal::logger('rep')->debug('downloadFileSocial(): token after refresh: @t', [
        //   '@t' => substr($token, 0, 8) . '…',
        // ]);
      }
      catch (\Exception $e) {
        \Drupal::logger('rep')->error('downloadFileSocial(): token refresh failed: @m', [
          '@m' => $e->getMessage(),
        ]);
        return NULL;
      }
    }

    // 3) Build the Social download URL using the same base as listByKeywordType/getUri.
    $oauthUrl    = rtrim(\Drupal::config('social.oauth.settings')->get('oauth_url'), '/');
    $downloadUrl = preg_replace('#/oauth/token$#', '/api/socialm/downloadfile', $oauthUrl);
    // \Drupal::logger('rep')->debug('downloadFileSocial(): POST URL is @url', [
    //   '@url' => $downloadUrl,
    // ]);

    // 4) Prepare the POST options.
    $consumerId = \Drupal::config('social.oauth.settings')->get('client_id');
    $options    = [
      'http_errors' => FALSE,
      'headers'     => [
        'Authorization' => "Bearer {$token}",
        'Accept'        => 'application/octet-stream',
      ],
      'json'        => [
        'token'       => $token,
        'consumer_id' => $consumerId,
        'uri'         => $uri,
        'file'        => $fileName,
      ],
    ];
    // \Drupal::logger('rep')->debug('downloadFileSocial(): POST body @b', [
    //   '@b' => print_r($options['json'], TRUE),
    // ]);

    // 5) Execute the POST request, retrying once on 401.
    $client = \Drupal::httpClient();
    try {
      $response = $client->request('POST', $downloadUrl, $options);
      $status   = $response->getStatusCode();
      // \Drupal::logger('rep')->debug('downloadFileSocial(): first attempt HTTP @s', [
      //   '@s' => $status,
      // ]);

      if ($status === 401) {
        // Token expired or revoked: refresh and retry once.
        \Drupal::logger('rep')->warning('downloadFileSocial(): 401 received, refreshing token & retrying...');
        $refresh();
        $newToken                 = $session->get('oauth_access_token');
        $options['headers']['Authorization'] = "Bearer {$newToken}";
        $options['json']['token']            = $newToken;
        $response = $client->request('POST', $downloadUrl, $options);
        $status   = $response->getStatusCode();
        // \Drupal::logger('rep')->debug('downloadFileSocial(): retry attempt HTTP @s', [
        //   '@s' => $status,
        // ]);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('rep')->error('downloadFileSocial(): exception during request: @m', [
        '@m' => $e->getMessage(),
      ]);
      return NULL;
    }

    // 6) Return the response only if HTTP 200, else NULL.
    if (isset($status) && $status === 200) {
      // \Drupal::logger('rep')->debug('downloadFileSocial(): successful download.');
      return $response;
    }

    \Drupal::logger('rep')->error('downloadFileSocial(): download failed with HTTP @s', [
      '@s' => $status ?? 'none',
    ]);
    return NULL;
  }

  // POST    /hascoapi/api/repo/namespace/app/          org.hascoapi.console.controllers.restapi.RepoPage.ingestAppOnt(request: play.mvc.Http.Request)
  public function uploadOntology(): ?ResponseInterface {
    $logger = \Drupal::logger('rep_upload');
    $file_system = \Drupal::service('file_system');
    $config = \Drupal::config('rep.settings');
    $guesser = \Drupal::service('file.mime_type.guesser');

    $ns = (string) 'hasco';
    $private_uri = 'private://ont/' . $ns . '.ttl';
    $path = $file_system->realpath($private_uri);

    if ($path === FALSE || !file_exists($path)) {
      $logger->error('Ontology file not found or realpath failed: {uri}', ['uri' => $private_uri]);
      return null;
    }

    $file_content = @file_get_contents($path);
    if ($file_content === FALSE) {
      $logger->error('Unable to read file contents: {path}', ['path' => $path]);
      return null;
    }

    $mime_type = $guesser->guessMimeType($path) ?: 'text/turtle; charset=UTF-8';
    $url = rtrim($this->getApiUrl(), '/') . '/hascoapi/api/repo/namespace/app';

    $authHeader = $this->bearer ?? '';
    if ($authHeader !== '' && stripos($authHeader, 'Bearer ') !== 0) {
      $authHeader = 'Bearer ' . $authHeader;
    }

    $client = new Client([
      'timeout' => 20,
      'connect_timeout' => 10,
      'http_errors' => false, // keep 4xx/5xx as responses, not exceptions
    ]);

    try {
      $response = $client->post($url, [
        'headers' => [
          'Content-Type'  => $mime_type,
          'Content-Disposition' => 'attachment; filename="'.$ns . '.ttl"',
          'Accept'        => 'application/json, text/plain;q=0.5, */*;q=0.1',
          'Authorization' => $authHeader,
        ],
        'body' => $file_content,
      ]);

      return $response; // <-- devolve o ResponseInterface “cru”
    } catch (RequestException $e) {
      $logger->error('Upload exception: {msg}', ['msg' => $e->getMessage()]);
      // If the server returned a response, you can still return it:
      if ($e->hasResponse()) {
        return $e->getResponse();
      }
      return null;
    }
  }


  /* INSTANCES  */
  /* Units, etc */

  // GET /hascoapi/api/instances/keyword/:classuri/:keyword
  public function listInstancesByKeyword($keyword) {
    $endpoint = "/hascoapi/api/instances/keyword/http%3A%2F%2Fqudt.org%2Fschema%2Fqudt%2FUnit/".
      rawurlencode($keyword);
    $method = 'GET';
    $api_url = $this->getApiUrl();
    $data = $this->getHeader();
    return $this->perform_http_request($method, $api_url.$endpoint, $data);
  }



}




