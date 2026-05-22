<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\rep\Utils;

class AssocProject {

  public static function process($element, array &$form, FormStateInterface $form_state) {
    $t = \Drupal::service('string_translation');

    // Card layout helpers (logo centering, fixed footer height, etc.).
    $form['#attached']['library'][] = 'rep/associate_cards';

    /** @var \Drupal\rep\ApiConnectorInterface $api */
    $api = \Drupal::service('rep.api_connector');

    // ---- Funding rendered as cards (e.g., "Funding: Horizon Europe") ----
    $fundingItems = [];
    foreach (['funding', 'fundingScheme', 'hasFunding', 'hasFundingScheme'] as $prop) {
      if (empty($element->{$prop})) {
        continue;
      }

      $value = $element->{$prop};
      if (is_array($value)) {
        foreach ($value as $v) {
          if (is_object($v) && !empty($v->uri)) {
            $fundingItems[] = $v;
          }
        }
      }
      elseif (is_object($value) && !empty($value->uri)) {
        $fundingItems[] = $value;
      }
    }

    if (!empty($fundingItems)) {
      static $fundingImageCache = [];
      $cards = '';

      foreach ($fundingItems as $funding) {
        $fundingUri = (string) $funding->uri;
        $label = !empty($funding->label)
          ? (string) $funding->label
          : (!empty($funding->name) ? (string) $funding->name : Utils::namespaceUri($fundingUri));

        // Funding often has no hasImageUri. Ensure we pick the dedicated
        // fundingschemes placeholder instead of falling back to "unknown".
        $placeholder = Utils::placeholderImage(
          $funding->hascoTypeUri ?? ($funding->typeUri ?? ''),
          'fundingschemes',
          '/'
        );

        if (!isset($fundingImageCache[$fundingUri])) {
          $img = $placeholder;

          if (!empty($funding->hasImageUri)) {
            $img = Utils::getAPIImage($fundingUri, (string) $funding->hasImageUri, $placeholder);
          }
          else {
            // Fallback: fetch object by URI and use its hasImageUri.
            $raw = $api->getUri(Utils::plainUri($fundingUri));
            if ($raw) {
              $full = $api->parseObjectResponse($raw, 'getUri');
              if (is_object($full) && !empty($full->hasImageUri)) {
                $img = Utils::getAPIImage($fundingUri, (string) $full->hasImageUri, $placeholder);
              }
            }
          }

          $fundingImageCache[$fundingUri] = $img;
        }

        $safeImg = Html::escape($fundingImageCache[$fundingUri]);
        $safeTitle = Html::escape($label);
        $safeUri = Html::escape($fundingUri);
        $openHref = Html::escape(Url::fromUserInput('/rep/uri/' . base64_encode($fundingUri))->toString());

        $cards .= '<div class="col-12 col-md-4">'
          . '<div class="card h-100">'
          . '  <div class="card-header">' . $safeTitle . '</div>'
          . '  <div class="card-body d-flex flex-column">'
          . '    <div class="mb-3 rep-card-logo-box">'
          . '      <img class="rep-card-logo-img" src="' . $safeImg . '" alt="' . $safeTitle . '" />'
          . '    </div>'
          . '    <a class="btn btn-primary mt-auto" href="' . $openHref . '" target="_blank" rel="noopener noreferrer">Open</a>'
          . '  </div>'
          . '  <div class="card-footer small text-muted rep-card-uri-footer">' . $safeUri . '</div>'
          . '</div>'
          . '</div>';
      }

      if (!empty($cards)) {
        $grid = '<div class="row row-cols-1 row-cols-md-3 g-4">' . $cards . '</div>';
        $form['funding'] = [
          '#type' => 'markup',
          '#markup' => '<h3>' . $t->translate('Funding') . '</h3>' . $grid,
        ];
      }
    }

    // Contributors rendered as cards (3 per row).
    $contributors = [];
    // Prefer full contributor objects when present.
    if (!empty($element->contributors) && is_array($element->contributors)) {
      foreach ($element->contributors as $c) {
        if (is_object($c) && !empty($c->uri)) {
          $contributors[(string) $c->uri] = $c;
        }
      }
    }
    // Use contributorUris only as a fallback when the API did not send full
    // contributor objects. Some backends may populate contributorUris with a
    // much larger set than "contributors".
    if (empty($contributors) && !empty($element->contributorUris) && is_array($element->contributorUris)) {
      foreach ($element->contributorUris as $u) {
        if (is_string($u) && $u !== '') {
          $contributors[$u] = $contributors[$u] ?? (object) ['uri' => $u];
        }
        elseif (is_object($u) && !empty($u->uri)) {
          $contributors[(string) $u->uri] = $contributors[(string) $u->uri] ?? $u;
        }
      }
    }

    if (!empty($contributors)) {
      $allUris = array_values(array_keys($contributors));
      $total = count($allUris);

      $PAGE_SIZE = 9;
      $initialUris = array_slice($allUris, 0, $PAGE_SIZE);

      $cards = '';
      static $imageCache = [];
      static $metaCache = [];
      foreach ($initialUris as $contribUri) {
        $contrib = $contributors[$contribUri] ?? (object) ['uri' => $contribUri];

        $label = (!empty($contrib->label) ? (string) $contrib->label : (!empty($contrib->name) ? (string) $contrib->name : Utils::namespaceUri($contribUri)));
        $typeUri = (string) ($contrib->hascoTypeUri ?? ($contrib->typeUri ?? ''));
        $placeholder = Utils::placeholderImage($typeUri, $label, '/');

        $externalUrl = '';
        $candidate = (string) ($contrib->hasWebDocument ?? ($contrib->hasURL ?? ($contrib->url ?? '')));
        if ($candidate !== '' && UrlHelper::isValid($candidate, TRUE)) {
          $externalUrl = UrlHelper::filterBadProtocol($candidate);
        }

        $typeLabel = (string) ($contrib->hascoTypeLabel ?? ($contrib->typeLabel ?? ''));
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

        // Cache both image and metadata so cards remain consistent across
        // initial render and infinite-scroll appended cards.
        if (!isset($imageCache[$contribUri]) || !isset($metaCache[$contribUri])) {
          $img = $placeholder;

          // Prefer image reference present on the contributor object.
          if (!empty($contrib->hasImageUri)) {
            $img = Utils::getAPIImage($contribUri, (string) $contrib->hasImageUri, $placeholder);
          }

          // Always fetch full object so we can resolve typeLabel/webdoc even
          // when hasImageUri exists (the AJAX endpoint does this).
          $raw = $api->getUri(Utils::plainUri($contribUri));
          if ($raw) {
            $full = $api->parseObjectResponse($raw, 'getUri');
            if (is_object($full)) {
              if (!empty($full->label)) {
                $label = (string) $full->label;
              }
              elseif (!empty($full->name)) {
                $label = (string) $full->name;
              }

              $typeUri = (string) ($full->hascoTypeUri ?? ($full->typeUri ?? $typeUri));
              $typeLabel = (string) ($full->hascoTypeLabel ?? ($full->typeLabel ?? $typeLabel));
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

              $candidate = (string) ($full->hasWebDocument ?? ($full->hasURL ?? ($full->url ?? '')));
              if ($candidate !== '' && UrlHelper::isValid($candidate, TRUE)) {
                $externalUrl = UrlHelper::filterBadProtocol($candidate);
              }

              // Refresh placeholder based on the fully-resolved type+label.
              $placeholder = Utils::placeholderImage($typeUri, $label, '/');

              if (!empty($full->hasImageUri)) {
                $img = Utils::getAPIImage($contribUri, (string) $full->hasImageUri, $placeholder);
              }
              elseif (!empty($contrib->hasImageUri)) {
                $img = Utils::getAPIImage($contribUri, (string) $contrib->hasImageUri, $placeholder);
              }
              else {
                $img = $placeholder;
              }
            }
          }

          $imageCache[$contribUri] = $img;
          $metaCache[$contribUri] = [
            'label' => $label,
            'externalUrl' => $externalUrl,
            'typeLabel' => $typeLabel,
          ];
        }
        else {
          $label = (string) ($metaCache[$contribUri]['label'] ?? $label);
          $externalUrl = (string) ($metaCache[$contribUri]['externalUrl'] ?? $externalUrl);
          $typeLabel = (string) ($metaCache[$contribUri]['typeLabel'] ?? $typeLabel);
        }

        $safeImg = Html::escape($imageCache[$contribUri]);
        $safeTitle = Html::escape($label);
        $safeUri = Html::escape($contribUri);
        $openHref = Html::escape(Url::fromUserInput('/rep/uri/' . base64_encode($contribUri))->toString());

        $cards .= '<div class="col-12 col-md-4">'
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

      if (!empty($cards)) {
        $gridId = 'rep-contrib-grid-' . substr(md5((string) ($element->uri ?? 'contributors')), 0, 10);
        $sentinelId = 'rep-contrib-sentinel-' . substr(md5($gridId), 0, 10);
        $spinnerId = 'rep-contrib-spinner-' . substr(md5($sentinelId), 0, 10);
        $endpoint = Url::fromRoute('rep.contributors.cards')->toString();

        $form['contributors'] = [
          '#type' => 'container',
          '#attached' => [
            'library' => ['rep/contributors_infinite'],
            'drupalSettings' => [
              'rep' => [
                'contributorsInfinite' => [
                  'grids' => [
                    $gridId => [
                      'endpoint' => $endpoint,
                      'uris' => $allUris,
                      'loaded' => count($initialUris),
                      'pageSize' => $PAGE_SIZE,
                      'sentinelId' => $sentinelId,
                      'spinnerId' => $spinnerId,
                    ],
                  ],
                ],
              ],
            ],
          ],
        ];

        $form['contributors']['title'] = [
          '#type' => 'markup',
          '#markup' => '<h3 class="mt-4">' . $t->translate('Contributors') . ' (' . (int) $total . ')</h3>',
        ];

        $form['contributors']['grid'] = [
          '#type' => 'markup',
          '#markup' => '<div id="' . Html::escape($gridId) . '" class="row row-cols-1 row-cols-md-3 g-4">' . $cards . '</div>'
            . '<div id="' . Html::escape($spinnerId) . '" class="rep-contrib-spinner d-flex align-items-center gap-2 mt-3 d-none">'
            . '  <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>'
            . '  <span class="small">Loading…</span>'
            . '</div>'
            . '<div id="' . Html::escape($sentinelId) . '" class="rep-contrib-sentinel"></div>',
        ];
      }
    }

    return $form;
  }


}
