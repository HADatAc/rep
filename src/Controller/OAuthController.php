<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\rep\Constant;
use Symfony\Component\HttpFoundation\JsonResponse;
use GuzzleHttp\Exception\RequestException;

class OAuthController extends ControllerBase {

  /**
   * Calls the OAuth token endpoint using client_credentials grant.
   */
  public function getAccessToken() {
    // \Drupal::logger('oauth_test')->notice('Inside getAccessToken() method');

    // Lê configurações salvas via formulário
    $config = \Drupal::config(Constant::CONFIG_SAGRES);
    $client_id = $config->get('client_id');
    $client_secret = $config->get('client_secret');
    $token_url = $config->get('oauth_url');
    $scope = 'read';

    // \Drupal::logger('oauth_test')->notice('Sending request to: @url with data: @data', [
    //   '@url' => $token_url,
    //   '@data' => print_r([
    //       'grant_type' => 'client_credentials',
    //       'client_id' => $client_id,
    //       'client_secret' => $client_secret,
    //       'scope' => $scope,
    //   ], TRUE),
    // ]);

    try {
      $http_client = \Drupal::httpClient();

      $response = $http_client->request('POST', $token_url, [
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'body' => http_build_query([
          'grant_type' => 'client_credentials',
          'client_id' => $client_id,
          'client_secret' => $client_secret,
          'scope' => $scope,
        ]),
      ]);

      $status = $response->getStatusCode();
      $body = json_decode($response->getBody()->getContents(), true);

      // \Drupal::logger('oauth_test')->notice('Response from OAuth server: @response', [
      //   '@response' => json_encode($body),
      // ]);

      // Guarda o token na sessão
      \Drupal::request()->getSession()->set('oauth_access_token', $body['access_token']);

      return new JsonResponse([
        'success' => true,
        'status' => $status,
        'body' => $body,
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('oauth_test')->error('OAuth token request failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => 'OAuth token request failed',
        'message' => $e->getMessage(),
      ], 500);
    }
  }

}
