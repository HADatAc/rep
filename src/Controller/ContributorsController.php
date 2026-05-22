<?php

namespace Drupal\rep\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Drupal\rep\Utils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ContributorsController {

  public function cards(Request $request): JsonResponse {
    $payload = [];
    $raw = (string) $request->getContent();
    if ($raw !== '') {
      $decoded = json_decode($raw, TRUE);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }

    $uris = $payload['uris'] ?? [];
    if (!is_array($uris)) {
      $uris = [];
    }

    $uris = array_values(array_filter($uris, static function ($u) {
      return is_string($u) && $u !== '';
    }));

    // Safety cap.
    $uris = array_slice($uris, 0, 9);

    /** @var \Drupal\rep\ApiConnectorInterface $api */
    $api = \Drupal::service('rep.api_connector');

    $html = '';
    foreach ($uris as $uri) {
      $label = Utils::namespaceUri($uri);
      $typeUri = '';
      $typeLabel = '';
      $img = Utils::placeholderImage('', $label, '/');
      $externalUrl = '';

      $objRaw = $api->getUri(Utils::plainUri($uri));
      if ($objRaw) {
        $obj = $api->parseObjectResponse($objRaw, 'getUri');
        if (is_object($obj)) {
          if (!empty($obj->label)) {
            $label = (string) $obj->label;
          }
          elseif (!empty($obj->name)) {
            $label = (string) $obj->name;
          }

          $typeUri = (string) ($obj->hascoTypeUri ?? ($obj->typeUri ?? ''));
          $typeLabel = (string) ($obj->hascoTypeLabel ?? ($obj->typeLabel ?? ''));

          // Keep layout consistent even when the API does not provide a
          // human-readable type label.
          if ($typeLabel === '' && $typeUri !== '') {
            $frag = parse_url($typeUri, PHP_URL_FRAGMENT);
            if (!empty($frag)) {
              $typeLabel = (string) $frag;
            }
            else {
              $path = parse_url($typeUri, PHP_URL_PATH);
              $base = $path ? basename((string) $path) : '';
              if ($base !== '') {
                $typeLabel = (string) $base;
              }
            }
          }
          $placeholder = Utils::placeholderImage($typeUri, $label, '/');

          if (!empty($obj->hasImageUri)) {
            $img = Utils::getAPIImage($uri, (string) $obj->hasImageUri, $placeholder);
          }
          else {
            $img = $placeholder;
          }

          $candidate = (string) ($obj->hasWebDocument ?? ($obj->hasURL ?? ($obj->url ?? '')));
          if ($candidate !== '' && UrlHelper::isValid($candidate, TRUE)) {
            $externalUrl = UrlHelper::filterBadProtocol($candidate);
          }
        }
      }

      $safeImg = Html::escape($img);
      $safeTitle = Html::escape($label);
      $safeUri = Html::escape($uri);
      $openHref = Html::escape(Url::fromUserInput('/rep/uri/' . base64_encode($uri))->toString());

      $html .= '<div class="col-12 col-md-4">'
        . '<div class="card h-100">'
        . '  <div class="card-header">' . $safeTitle . '</div>'
        . '  <div class="card-body d-flex flex-column">'
        . '    <div class="mb-3 rep-card-logo-box">'
        . '      <img class="rep-card-logo-img" src="' . $safeImg . '" alt="' . $safeTitle . '" />'
        . '    </div>'
        . '    <div class="d-flex gap-2 mt-auto">'
        . '      <a class="btn btn-primary flex-grow-1" href="' . $openHref . '" target="_blank" rel="noopener noreferrer">Open</a>'
        . '    </div>'
        . '  </div>'
        . '  <div class="card-footer small text-muted rep-card-uri-footer">' . $safeUri . '</div>'
        . '</div>'
        . '</div>';
    }

    return new JsonResponse([
      'html' => $html,
      'count' => count($uris),
    ]);
  }

}
