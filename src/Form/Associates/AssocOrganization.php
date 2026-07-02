<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\FOAF;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\ListPropertyPage;
use Drupal\rep\Constant;
use Drupal\rep\Utils;
use Drupal\rep\Entity\VSTOIInstance;

class AssocOrganization {

  private const INSTANCE_PAGE_SIZE = 5;
  private const INSTANCE_FETCH_PAGE_SIZE = 100;
  private const INSTANCE_FETCH_LIMIT = 100;

  private static function normalizeEmail($value): string {
    if (!is_string($value)) {
      return '';
    }

    $email = trim($value);
    if ($email === '') {
      return '';
    }

    if (stripos($email, 'mailto:') === 0) {
      $email = trim(substr($email, 7));
    }

    return strtolower($email);
  }

  private static function extractOrganizationManagerEmails($element): array {
    if (!is_object($element)) {
      return [];
    }

    $emails = [];
    foreach (['hasSIRManagerEmail', 'mbox', 'hasEmail', 'email'] as $field) {
      if (!isset($element->{$field})) {
        continue;
      }

      $value = $element->{$field};
      if (is_string($value)) {
        $email = self::normalizeEmail($value);
        if ($email !== '') {
          $emails[$email] = TRUE;
        }
      }
      elseif (is_array($value)) {
        foreach ($value as $entry) {
          if (!is_string($entry)) {
            continue;
          }
          $email = self::normalizeEmail($entry);
          if ($email !== '') {
            $emails[$email] = TRUE;
          }
        }
      }
    }

    return array_keys($emails);
  }

  private static function parseListBody($raw): array {
    if ($raw === NULL) {
      return [];
    }

    $decoded = NULL;
    if (is_string($raw)) {
      $decoded = json_decode($raw);
      if (!is_object($decoded)) {
        return [];
      }
    }
    elseif (is_object($raw) || is_array($raw)) {
      $decoded = json_decode(json_encode($raw));
      if (!is_object($decoded)) {
        return [];
      }
    }

    if (!is_object($decoded) || empty($decoded->isSuccessful)) {
      return [];
    }

    $body = $decoded->body ?? NULL;
    if (is_string($body)) {
      $body = json_decode($body);
    }

    if (is_array($body)) {
      return $body;
    }

    if (is_object($body) && isset($body->elements) && is_array($body->elements)) {
      return $body->elements;
    }

    if (is_object($body) && !empty($body->uri)) {
      return [$body];
    }

    return [];
  }

  private static function filterByManagerEmail(array $items, string $email): array {
    if ($email === '') {
      return [];
    }

    $filtered = [];
    foreach ($items as $item) {
      if (!is_object($item)) {
        continue;
      }
      $owner = self::normalizeEmail((string) ($item->hasSIRManagerEmail ?? ''));
      if ($owner !== '' && $owner === $email) {
        $filtered[] = $item;
      }
    }

    return $filtered;
  }

  private static function parseTotalValue($raw): int {
    if ($raw === NULL) {
      return 0;
    }

    $decoded = NULL;
    if (is_string($raw)) {
      $decoded = json_decode($raw);
    }
    elseif (is_object($raw) || is_array($raw)) {
      $decoded = json_decode(json_encode($raw));
    }

    if (!is_object($decoded) || empty($decoded->isSuccessful)) {
      return 0;
    }

    $body = $decoded->body ?? NULL;
    if (is_string($body)) {
      $body = json_decode($body);
    }

    if (is_object($body) && isset($body->total) && is_numeric($body->total)) {
      return (int) $body->total;
    }

    if (is_array($body) && isset($body['total']) && is_numeric($body['total'])) {
      return (int) $body['total'];
    }

    return 0;
  }

  private static function listAllByManagerEmail($api, string $elementType, string $email): array {
    if ($email === '') {
      return [];
    }

    $total = self::parseTotalValue($api->listSizeByManagerEmail($elementType, $email));
    if ($total <= 0) {
      return [];
    }

    $target = min($total, self::INSTANCE_FETCH_LIMIT);
    $pageSize = self::INSTANCE_FETCH_PAGE_SIZE;
    $offset = 0;
    $indexed = [];

    while ($offset < $target) {
      $chunk = self::parseListBody($api->listByManagerEmail($elementType, $email, $pageSize, $offset));
      if (empty($chunk)) {
        break;
      }

      foreach ($chunk as $item) {
        if (!is_object($item) || empty($item->uri)) {
          continue;
        }
        $indexed[(string) $item->uri] = $item;
      }

      if (count($chunk) < $pageSize) {
        break;
      }

      $offset += $pageSize;
    }

    return array_values($indexed);
  }

  private static function listManagerOwnedInstances($api, string $elementType, array $emails): array {
    $limit = self::INSTANCE_FETCH_LIMIT;
    $indexed = [];

    foreach ($emails as $email) {
      if ($email === '') {
        continue;
      }

      $items = self::listAllByManagerEmail($api, $elementType, $email);
      if (empty($items)) {
        $all = self::parseListBody($api->listByKeyword($elementType, '_', self::INSTANCE_FETCH_LIMIT, 0));
        $items = self::filterByManagerEmail($all, $email);
      }

      foreach ($items as $item) {
        if (!is_object($item) || empty($item->uri)) {
          continue;
        }
        $indexed[(string) $item->uri] = $item;
      }

      if (count($indexed) >= $limit) {
        break;
      }
    }

    $items = array_values($indexed);
    usort($items, function ($a, $b) {
      $labelA = is_object($a) ? (string) ($a->label ?? '') : '';
      $labelB = is_object($b) ? (string) ($b->label ?? '') : '';
      return strcasecmp($labelA, $labelB);
    });

    return array_slice($items, 0, $limit);
  }

  private static function appendInstanceSection(array &$form, string $sectionKey, string $instanceType, string $title, array $items) {
    if (empty($items)) {
      return;
    }

    $t = \Drupal::service('string_translation');
    $searchInputId = $sectionKey . '-search-input';
    $searchWrapId = $sectionKey . '-search-wrap';

    $form[$sectionKey] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['rep-describe-instance-section'],
        'data-rep-instance-section' => '1',
      ],
    ];

    $form[$sectionKey]['begin'] = [
      '#type' => 'markup',
      '#markup' => $t->translate(
        '<div class="rep-instance-title-row"><b>@title (total of @total):</b><button type="button" class="btn btn-default btn-xs rep-instance-search-toggle" data-rep-instance-search-toggle="1" aria-expanded="false" aria-controls="@controls" title="@searchTitle"><i class="fa fa-search" aria-hidden="true"></i><span class="sr-only">@searchTitle</span></button></div>',
        [
          '@title' => $title,
          '@total' => (string) count($items),
          '@controls' => $searchWrapId,
          '@searchTitle' => (string) $t->translate('Search by label'),
        ]
      ),
    ];

    $form[$sectionKey]['search'] = [
      '#type' => 'markup',
      '#markup' => $t->translate(
        '<div id="@id" class="rep-instance-search-wrap" data-rep-instance-search-wrap="1"><label class="sr-only" for="@inputId">@label</label><input id="@inputId" data-rep-instance-search-input="1" type="text" class="form-control input-sm" placeholder="@placeholder" autocomplete="off"></div>',
        [
          '@id' => $searchWrapId,
          '@inputId' => $searchInputId,
          '@label' => (string) $t->translate('Search by label'),
          '@placeholder' => (string) $t->translate('Search by label'),
        ]
      ),
    ];

    $header = VSTOIInstance::generateHeader($instanceType);
    $output = VSTOIInstance::generateOutput($instanceType, $items);
    if ($header != NULL && $output != NULL) {
      $form[$sectionKey]['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $output,
        '#attributes' => [
          'class' => ['rep-instance-table'],
          'data-rep-instance-table' => '1',
          'data-rep-page-size' => (string) self::INSTANCE_PAGE_SIZE,
        ],
        '#empty' => t('No instances found'),
      ];
    }

    $form[$sectionKey]['pagination'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['rep-instance-pagination'],
        'data-rep-instance-pagination' => '1',
      ],
    ];

    $form[$sectionKey]['end'] = [
      '#type' => 'markup',
      '#markup' => $t->translate('<br>'),
    ];
  }

  public static function process($element, array &$form, FormStateInterface $form_state) {
    if (!is_object($element) || empty($element->uri)) {
      return $form;
    }

    $api = \Drupal::service('rep.api_connector');
    $t = \Drupal::service('string_translation');
    $preferredInstrument = \Drupal::config('rep.settings')->get('preferred_instrument') ?? 'Instrument';
    $preferredPlatform = \Drupal::config('rep.settings')->get('preferred_platform') ?? 'Platform';

    /*
      *    ORGANIZATION's ORGANIZATIONS
      */
    $rawSubOrgs = $api->getSubOrganizations($element->uri,Constant::TOT_PER_PAGE,0);
    if ($rawSubOrgs != NULL) {
      $subOrgs = $api->parseObjectResponse($rawSubOrgs,'getSubOrganizations');
      if ($subOrgs != NULL) {
        $totalSubOrgs = $api->parseTotalResponse($api->getTotalSubOrganizations($element->uri),'getTotalSubOrganizations');
        $form['beginSubOrgs'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>SubOrganizations (total of " . $totalSubOrgs . "):</b><ul>"),
        ];
        foreach ($subOrgs as $propertyNameSubOrgs => $propertyValueSubOrgs) {
          $form[$propertyNameSubOrgs] = [
            '#type' => 'markup',
            '#markup' => $t->translate("<li>" . Utils::link($propertyValueSubOrgs->label,$propertyValueSubOrgs->uri) . " - " . $propertyValueSubOrgs->name . "</li>"),
          ];
        }
        if ($totalSubOrgs > Constant::TOT_PER_PAGE) {
          $link = ListPropertyPage::link($element,SCHEMA::SUB_ORGANIZATION,NULL,1,20);
          $form['moreElements'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm more-button" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['endSubOrgs'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }
    /*
      *    ORGANIZATION's PEOPLE
      */
    $rawAffiliations = $api->getAffiliations($element->uri,Constant::TOT_PER_PAGE,0);
    if ($rawAffiliations != NULL) {
      $affiliations = $api->parseObjectResponse($rawAffiliations,'getAffiliations');
      if ($affiliations != NULL) {
        $totalAffiliations = $api->parseTotalResponse($api->getTotalAffiliations($element->uri),'getTotalAffiliations');
        $form['beginAffiliations'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Affiliated People (total of " . $totalAffiliations . "):</b><ul>"),
        ];
        foreach ($affiliations as $propertyNameAffiliations => $propertyValueAffiliations) {
          $form[$propertyNameAffiliations] = [
            '#type' => 'markup',
            '#markup' => $t->translate("<li>" . Utils::link($propertyValueAffiliations->label,$propertyValueAffiliations->uri) . " - " . $propertyValueAffiliations->name . "</li>"),
          ];
        }
        if ($totalAffiliations > Constant::TOT_PER_PAGE) {
          $link = ListPropertyPage::link($element,FOAF::MEMBER,NULL,1,20);
          $form['moreElements'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm more-button" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['endAffiliations'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }
    /*
     *    ORGANIZATION's POSTAL ADDRESSES
     */

    /*
     $rawContainsPostalAddress = $api->getContainsPostalAddress($element->hasAddress->hasAddressLocalityUri,Constant::TOT_PER_PAGE,0);
    if ($rawContainsPostalAddress != NULL) {
      $containsPostalAddress = $api->parseObjectResponse($rawContainsPostalAddress,'getContainsPostalAddress');
      if ($containsPostalAddress != NULL) {
        $totalContainsPostalAddress = $api->parseTotalResponse($api->getTotalContainsPostalAddress($element->hasAddress->hasAddressLocalityUri),'getTotalContainsPostalAddress');
        $form['postaladdress']['beginContains'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Contains Postal Addresses (total of " . $totalContainsPostalAddress . "):</b><ul>"),
        ];
        foreach ($containsPostalAddress as $propertyNameContainsPostalAddress => $propertyValueContainsPostalAddress) {
          $form['postaladdress'][$propertyNameContainsPostalAddress] = [
            '#type' => 'markup',
            '#markup' => $t->translate("<li>" . Utils::link($propertyValueContainsPostalAddress->label,$propertyValueContainsPostalAddress->uri) . "</li>"),
          ];
        }
        if ($totalContainsPostalAddress > Constant::TOT_PER_PAGE) {
          $link = ListPropertyPage::link($element,SCHEMA::HAS_ADDRESS,NULL,1,20);
          $form['postaladdress']['moreElements'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm more-button" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['postaladdress']['endContains'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }
    */

    // ORGANIZATION's MANAGER-OWNED INSTANCES (simulator/laboratory use case).
    $managerEmails = self::extractOrganizationManagerEmails($element);
    if (!empty($managerEmails)) {
      $form['#attached']['library'][] = 'rep/describe_instance_tables';

      $instrumentInstances = self::listManagerOwnedInstances($api, 'instrumentinstance', $managerEmails);
      self::appendInstanceSection(
        $form,
        'org_instrument_instances',
        'instrumentinstance',
        'Has ' . $preferredInstrument . ' instances',
        $instrumentInstances
      );

      $platformInstances = self::listManagerOwnedInstances($api, 'platforminstance', $managerEmails);
      self::appendInstanceSection(
        $form,
        'org_platform_instances',
        'platforminstance',
        'Has ' . $preferredPlatform . ' instances',
        $platformInstances
      );
    }

    return $form;
  }

}
