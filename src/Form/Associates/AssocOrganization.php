<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\ListPropertyPage;
use Drupal\rep\Constant;
use Drupal\rep\Utils;
use Drupal\rep\Entity\VSTOIInstance;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;

class AssocOrganization {

  private const INSTANCE_PAGE_SIZE = 5;
  private const INSTANCE_FETCH_PAGE_SIZE = 100;
  private const INSTANCE_FETCH_LIMIT = 100;
  private const INSTANCE_SCAN_LIMIT = 500;

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

  private static function extractPartOfUri($item): string {
    if (!is_object($item) || !isset($item->partOf)) {
      return '';
    }

    $partOf = $item->partOf;
    if (is_string($partOf)) {
      return trim($partOf);
    }

    if (is_object($partOf)) {
      return trim((string) ($partOf->uri ?? $partOf->hasURI ?? ''));
    }

    return '';
  }

  private static function normalizeUriKey(string $uri): string {
    $value = trim($uri);
    if ($value === '') {
      return '';
    }

    if (str_contains($value, '#/')) {
      $value = str_replace('#/', '#', $value);
    }

    return strtolower(rtrim($value, '/'));
  }

  private static function listPlatformInstancesByPartOf($api, string $organizationUri): array {
    $organizationUri = trim($organizationUri);
    if ($organizationUri === '') {
      return [];
    }
    $organizationKey = self::normalizeUriKey($organizationUri);
    $pageSize = self::INSTANCE_FETCH_PAGE_SIZE;
    $offset = 0;
    $indexed = [];

    // Do not trust list-size endpoint as a hard prerequisite.
    // Some deployments return 0 totals while listByKeyword still returns rows.
    $maxPages = max(1, (int) ceil(self::INSTANCE_SCAN_LIMIT / $pageSize));
    for ($page = 0; $page < $maxPages; $page++) {
      $offset = $page * $pageSize;
      $chunk = self::parseListBody($api->listByKeyword('platforminstance', '_', $pageSize, $offset));
      if (empty($chunk)) {
        break;
      }

      foreach ($chunk as $item) {
        if (!is_object($item) || empty($item->uri)) {
          continue;
        }

        $partOf = self::extractPartOfUri($item);
        $partOfKey = self::normalizeUriKey($partOf);
        $matchesScope = ($partOfKey !== '' && $partOfKey === $organizationKey);

        if ($matchesScope) {
          $indexed[(string) $item->uri] = $item;
        }
      }

      if (count($chunk) < $pageSize) {
        break;
      }
    }

    $items = array_values($indexed);
    usort($items, function ($a, $b) {
      $labelA = is_object($a) ? (string) ($a->label ?? '') : '';
      $labelB = is_object($b) ? (string) ($b->label ?? '') : '';
      return strcasecmp($labelA, $labelB);
    });

    return $items;
  }

  private static function isLaboratoryInstance($item): bool {
    if (!is_object($item)) {
      return FALSE;
    }

    $candidates = [];

    if (isset($item->typeUri) && is_string($item->typeUri)) {
      $candidates[] = trim((string) $item->typeUri);
    }
    if (isset($item->hascoTypeUri) && is_string($item->hascoTypeUri)) {
      $candidates[] = trim((string) $item->hascoTypeUri);
    }
    if (isset($item->type) && is_object($item->type)) {
      $candidates[] = trim((string) ($item->type->uri ?? ''));
      $candidates[] = trim((string) ($item->type->label ?? ''));
      $candidates[] = trim((string) ($item->type->name ?? ''));
    }
    if (isset($item->hascoType) && is_object($item->hascoType)) {
      $candidates[] = trim((string) ($item->hascoType->uri ?? ''));
      $candidates[] = trim((string) ($item->hascoType->label ?? ''));
      $candidates[] = trim((string) ($item->hascoType->name ?? ''));
    }

    foreach (['label', 'name', 'comment', 'description'] as $field) {
      if (isset($item->{$field}) && is_string($item->{$field})) {
        $candidates[] = trim((string) $item->{$field});
      }
    }

    foreach ($candidates as $candidate) {
      if ($candidate === '') {
        continue;
      }

      $lower = strtolower($candidate);
      if (str_contains($lower, 'laboratory') || str_contains($lower, 'laborat')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private static function filterLaboratoryInstances(array $items): array {
    $filtered = [];
    foreach ($items as $item) {
      if (!is_object($item) || empty($item->uri)) {
        continue;
      }

      if (self::isLaboratoryInstance($item)) {
        $filtered[(string) $item->uri] = $item;
      }
    }

    return array_values($filtered);
  }

  private static function listAffiliatedPeople($api, string $organizationUri): array {
    $organizationUri = trim($organizationUri);
    if ($organizationUri === '') {
      return [];
    }

    $total = self::parseTotalValue($api->getTotalAffiliations($organizationUri));
    if ($total <= 0) {
      return [];
    }

    $target = min($total, self::INSTANCE_FETCH_LIMIT);
    $pageSize = self::INSTANCE_FETCH_PAGE_SIZE;
    $offset = 0;
    $indexed = [];

    while ($offset < $target) {
      $chunk = self::parseListBody($api->getAffiliations($organizationUri, $pageSize, $offset));
      if (empty($chunk)) {
        break;
      }

      foreach ($chunk as $person) {
        if (!is_object($person) || empty($person->uri)) {
          continue;
        }
        $indexed[(string) $person->uri] = $person;
      }

      if (count($chunk) < $pageSize) {
        break;
      }

      $offset += $pageSize;
    }

    $items = array_values($indexed);
    usort($items, function ($a, $b) {
      return strcasecmp(self::extractPersonLabel($a), self::extractPersonLabel($b));
    });

    return array_slice($items, 0, $target);
  }

  private static function extractPersonLabel($person): string {
    if (!is_object($person)) {
      return '';
    }

    $label = trim((string) ($person->label ?? ''));
    if ($label !== '') {
      return $label;
    }

    $name = self::extractPersonName($person);
    if ($name !== '') {
      return $name;
    }

    $uri = trim((string) ($person->uri ?? ''));
    return ($uri !== '') ? Utils::namespaceUri($uri) : '';
  }

  private static function extractPersonName($person): string {
    if (!is_object($person)) {
      return '';
    }

    $name = trim((string) ($person->name ?? ''));
    if ($name !== '') {
      return $name;
    }

    $givenName = trim((string) ($person->givenName ?? ''));
    $familyName = trim((string) ($person->familyName ?? ''));
    return trim($givenName . ' ' . $familyName);
  }

  private static function extractPersonEmail($person): string {
    if (!is_object($person)) {
      return '';
    }

    foreach (['mbox', 'hasEmail', 'email'] as $field) {
      if (!isset($person->{$field})) {
        continue;
      }

      $value = $person->{$field};
      if (is_string($value)) {
        $email = self::normalizeEmail($value);
        if ($email !== '') {
          return $email;
        }
      }
      elseif (is_array($value)) {
        foreach ($value as $entry) {
          if (!is_string($entry)) {
            continue;
          }
          $email = self::normalizeEmail($entry);
          if ($email !== '') {
            return $email;
          }
        }
      }
    }

    return '';
  }

  private static function fetchOrganizationCurator($api, string $organizationUri) {
    $organizationUri = trim($organizationUri);
    if ($organizationUri === '') {
      return NULL;
    }

    $rawCurator = $api->getCuratorByOrganization($organizationUri);
    $curator = $api->parseObjectResponse($rawCurator, 'getCuratorByOrganization');
    return is_object($curator) ? $curator : NULL;
  }

  private static function projectContainsOrganization($project, string $organizationUri): bool {
    if (!is_object($project)) {
      return FALSE;
    }

    $organizationKey = self::normalizeUriKey($organizationUri);
    if ($organizationKey === '') {
      return FALSE;
    }

    $candidates = [];

    if (isset($project->contributorUris) && is_array($project->contributorUris)) {
      foreach ($project->contributorUris as $contributorUri) {
        if (is_string($contributorUri)) {
          $candidates[] = $contributorUri;
        }
        elseif (is_object($contributorUri) && !empty($contributorUri->uri)) {
          $candidates[] = (string) $contributorUri->uri;
        }
      }
    }

    if (isset($project->contributors) && is_array($project->contributors)) {
      foreach ($project->contributors as $contributor) {
        if (is_object($contributor) && !empty($contributor->uri)) {
          $candidates[] = (string) $contributor->uri;
        }
        elseif (is_string($contributor)) {
          $candidates[] = $contributor;
        }
      }
    }

    foreach ($candidates as $candidateUri) {
      if (self::normalizeUriKey((string) $candidateUri) === $organizationKey) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private static function listProjectsForOrganization($api, string $organizationUri): array {
    $organizationUri = trim($organizationUri);
    if ($organizationUri === '') {
      return [];
    }

    $projects = [];
    $pageSize = self::INSTANCE_FETCH_PAGE_SIZE;
    $offset = 0;

    // Use direct keyword endpoints to avoid OAuth social-list dependency for
    // internal organization membership checks.
    $totalProjects = self::parseTotalValue($api->listSizeByKeyword('project', '_'));
    $target = ($totalProjects > 0) ? min($totalProjects, self::INSTANCE_SCAN_LIMIT) : self::INSTANCE_FETCH_LIMIT;

    while ($offset < $target) {
      $chunk = self::parseListBody($api->listByKeyword('project', '_', $pageSize, $offset));
      if (empty($chunk)) {
        break;
      }

      foreach ($chunk as $project) {
        if (!is_object($project) || empty($project->uri)) {
          continue;
        }
        if (!self::projectContainsOrganization($project, $organizationUri)) {
          continue;
        }
        $projects[(string) $project->uri] = $project;
      }

      if (count($chunk) < $pageSize) {
        break;
      }

      $offset += $pageSize;
    }

    $items = array_values($projects);
    usort($items, function ($a, $b) {
      $labelA = is_object($a) ? (string) ($a->label ?? $a->name ?? $a->uri ?? '') : '';
      $labelB = is_object($b) ? (string) ($b->label ?? $b->name ?? $b->uri ?? '') : '';
      return strcasecmp($labelA, $labelB);
    });

    return $items;
  }

  private static function appendProjectMembershipSection(array &$form, array $projects): void {
    $t = \Drupal::service('string_translation');
    $total = count($projects);

    $form['org_project_membership'] = [
      '#type' => 'container',
    ];

    $statusText = $total > 0 ? $t->translate('Yes') : $t->translate('No');
    $form['org_project_membership']['status'] = [
      '#type' => 'markup',
      '#markup' => $t->translate('<b>Belongs to project</b>: @status<br><br>', [
        '@status' => (string) $statusText,
      ]),
    ];

    if ($total <= 0) {
      return;
    }

    $form['org_project_membership']['begin'] = [
      '#type' => 'markup',
      '#markup' => $t->translate('<b>Projects (total of @total):</b><ul>', [
        '@total' => (string) $total,
      ]),
    ];

    foreach ($projects as $index => $project) {
      if (!is_object($project) || empty($project->uri)) {
        continue;
      }

      $projectUri = (string) $project->uri;
      $projectLabel = trim((string) ($project->label ?? $project->name ?? ''));
      if ($projectLabel === '') {
        $projectLabel = Utils::namespaceUri($projectUri);
      }

      $form['org_project_membership']['project_' . $index] = [
        '#type' => 'markup',
        '#markup' => Markup::create('<li>' . Utils::link($projectLabel, $projectUri) . '</li>'),
      ];
    }

    $form['org_project_membership']['end'] = [
      '#type' => 'markup',
      '#markup' => $t->translate('</ul><br>'),
    ];
  }

  private static function mergeCuratorIntoAffiliations(array $affiliations, $curator): array {
    if (!is_object($curator)) {
      return $affiliations;
    }

    $curatorUri = trim((string) ($curator->uri ?? ''));
    $curatorEmail = self::extractPersonEmail($curator);
    $curatorLabel = strtolower(trim(self::extractPersonLabel($curator)));

    if ($curatorUri === '' && $curatorEmail === '' && $curatorLabel === '') {
      return $affiliations;
    }

    foreach ($affiliations as $person) {
      if (!is_object($person)) {
        continue;
      }

      $personUri = trim((string) ($person->uri ?? ''));
      if ($curatorUri !== '' && $personUri === $curatorUri) {
        return $affiliations;
      }

      $personEmail = self::extractPersonEmail($person);
      if ($curatorEmail !== '' && $personEmail !== '' && $personEmail === $curatorEmail) {
        return $affiliations;
      }

      $personLabel = strtolower(trim(self::extractPersonLabel($person)));
      if ($curatorLabel !== '' && $personLabel !== '' && $personLabel === $curatorLabel) {
        return $affiliations;
      }
    }

    $affiliations[] = $curator;
    usort($affiliations, function ($a, $b) {
      return strcasecmp(self::extractPersonLabel($a), self::extractPersonLabel($b));
    });

    return $affiliations;
  }

  private static function buildPeopleRows(array $items, $curator = NULL): array {
    $rows = [];
    $index = 0;
    $curatorUri = is_object($curator) ? trim((string) ($curator->uri ?? '')) : '';
    $curatorEmail = is_object($curator) ? self::extractPersonEmail($curator) : '';
    $curatorLabel = is_object($curator) ? strtolower(trim(self::extractPersonLabel($curator))) : '';
    $normalizedCuratorUri = self::normalizeUriKey($curatorUri);

    foreach ($items as $person) {
      if (!is_object($person)) {
        continue;
      }

      $uri = trim((string) ($person->uri ?? ''));
      $label = self::extractPersonLabel($person);
      $name = self::extractPersonName($person);
      $email = self::extractPersonEmail($person);

      if ($uri === '' && $label === '' && $name === '' && $email === '') {
        continue;
      }

      if ($label === '') {
        $label = ($uri !== '') ? Utils::namespaceUri($uri) : '';
      }

      $isCurator = 'No';
      if ($normalizedCuratorUri !== '' && $uri !== '' && self::normalizeUriKey($uri) === $normalizedCuratorUri) {
        $isCurator = 'Yes';
      }
      elseif ($curatorEmail !== '' && $email !== '' && $email === $curatorEmail) {
        $isCurator = 'Yes';
      }
      elseif ($curatorLabel !== '' && $label !== '' && strtolower(trim($label)) === $curatorLabel) {
        $isCurator = 'Yes';
      }
      $rowKey = $uri !== '' ? $uri : ('curator_' . $index);
      $index++;

      $uriCell = $uri !== ''
        ? Markup::create(Utils::describeAnchor($uri, Utils::namespaceUri($uri)))
        : Html::escape('-');

      $labelCell = ($uri !== '' && $label !== '')
        ? Markup::create(Utils::describeAnchor($uri, $label))
        : Html::escape($label !== '' ? $label : '-');

      $rows[$rowKey] = [
        'person_uri' => $uriCell,
        'person_label' => $labelCell,
        'person_name' => Html::escape($name),
        'person_email' => Html::escape($email),
        'person_is_curator' => Html::escape($isCurator),
      ];
    }

    return $rows;
  }

  private static function appendPeopleSection(array &$form, string $sectionKey, string $title, array $items, $curator = NULL): void {
    if (empty($items)) {
      return;
    }

    $t = \Drupal::service('string_translation');
    $searchInputId = $sectionKey . '-search-input';
    $searchWrapId = $sectionKey . '-search-wrap';
    $rows = self::buildPeopleRows($items, $curator);

    if (empty($rows)) {
      return;
    }

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
          '@total' => (string) count($rows),
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

    $form[$sectionKey]['table'] = [
      '#type' => 'table',
      '#header' => [
        'person_uri' => t('URI'),
        'person_label' => t('Label'),
        'person_name' => t('Name'),
        'person_email' => t('Email'),
        'person_is_curator' => t('Is Curator'),
      ],
      '#rows' => $rows,
      '#attributes' => [
        'class' => ['rep-instance-table'],
        'data-rep-instance-table' => '1',
        'data-rep-page-size' => (string) self::INSTANCE_PAGE_SIZE,
      ],
      '#empty' => t('No people found'),
    ];

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
    $projects = self::listProjectsForOrganization($api, (string) $element->uri);
    self::appendProjectMembershipSection($form, $projects);

    $affiliations = self::listAffiliatedPeople($api, (string) $element->uri);
    $curator = NULL;
    if (!empty($projects)) {
      // Business rule: only enforce curator when organization is part of at least one project.
      $curator = self::fetchOrganizationCurator($api, (string) $element->uri);
      $affiliations = self::mergeCuratorIntoAffiliations($affiliations, $curator);
    }

    if (!empty($affiliations)) {
      $form['#attached']['library'][] = 'rep/describe_instance_tables';
      self::appendPeopleSection(
        $form,
        'org_affiliated_people',
        'Affiliated People',
        $affiliations,
        $curator
      );
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

    // ORGANIZATION's MANAGER-OWNED instrument instances.
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
    }

    // ORGANIZATION's platform/laboratory instances should be strictly scoped by hasco:partOf.
    $platformInstances = self::listPlatformInstancesByPartOf($api, (string) $element->uri);
    if (!empty($platformInstances)) {
      $form['#attached']['library'][] = 'rep/describe_instance_tables';
      $preferredPlatformIsLaboratory = (stripos((string) $preferredPlatform, 'laboratory') !== FALSE);

      self::appendInstanceSection(
        $form,
        'org_platform_instances',
        'platforminstance',
        'Has ' . $preferredPlatform . ' instances',
        $platformInstances
      );

      // Avoid duplicate sections when preferred platform name is already "Laboratory".
      if (!$preferredPlatformIsLaboratory) {
        $laboratoryInstances = self::filterLaboratoryInstances($platformInstances);
        if (!empty($laboratoryInstances)) {
          self::appendInstanceSection(
            $form,
            'org_laboratory_instances',
            'platforminstance',
            'Has Laboratory instances',
            $laboratoryInstances
          );
        }
      }
    }

    return $form;
  }

}
