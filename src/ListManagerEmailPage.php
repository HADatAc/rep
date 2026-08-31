<?php

namespace Drupal\rep;

use Drupal\rep\Vocabulary\REPGUI;

class ListManagerEmailPage {

  /**
   * Detect strict PHP runtime budgets (typical local 30s max execution time).
   */
  private static function hasStrictRuntimeBudget(): bool {
    $maxExec = (int) ini_get('max_execution_time');
    return ($maxExec > 0 && $maxExec <= 30);
  }

  private static function wkfDebug(string $stage, array $context = []): void {
    if (empty($context['is_wkf'])) {
      return;
    }

    $payload = [
      '@stage' => $stage,
      '@etype' => (string) ($context['elementtype'] ?? ''),
      '@ltype' => (string) ($context['list_element_type'] ?? ''),
      '@status' => (string) ($context['status'] ?? ''),
      '@manager' => (string) ($context['manager'] ?? ''),
      '@with_current' => !empty($context['with_current']) ? '1' : '0',
      '@page' => (string) ($context['page'] ?? ''),
      '@pagesize' => (string) ($context['pagesize'] ?? ''),
      '@offset' => (string) ($context['offset'] ?? ''),
      '@raw_count' => (string) ($context['raw_count'] ?? ''),
      '@filtered_count' => (string) ($context['filtered_count'] ?? ''),
      '@list_size' => (string) ($context['list_size'] ?? ''),
      '@source' => (string) ($context['source'] ?? ''),
      '@sample_uri' => (string) ($context['sample_uri'] ?? ''),
      '@sample_type' => (string) ($context['sample_type'] ?? ''),
      '@sample_hasco_type' => (string) ($context['sample_hasco_type'] ?? ''),
      '@sample_top_task' => (string) ($context['sample_top_task'] ?? ''),
    ];

    \Drupal::logger('rep')->notice(
      'WKF debug @stage etype=@etype ltype=@ltype status=@status manager=@manager withCurrent=@with_current page=@page pageSize=@pagesize offset=@offset raw=@raw_count filtered=@filtered_count total=@list_size source=@source sampleUri=@sample_uri sampleType=@sample_type sampleHascoType=@sample_hasco_type sampleTopTask=@sample_top_task',
      $payload
    );
  }

  private static function asArray($items): array {
    if ($items === NULL) {
      return [];
    }
    return is_array($items) ? $items : [$items];
  }

  private static function countItems($items): int {
    return count(self::asArray($items));
  }

  private static function sampleItemMeta($items): array {
    $list = self::asArray($items);
    if (count($list) === 0) {
      return [
        'sample_uri' => '',
        'sample_type' => '',
        'sample_hasco_type' => '',
        'sample_top_task' => '',
      ];
    }

    $first = $list[0];
    $topTask = trim((string) self::extractField($first, 'hasTopTaskUri'));
    if ($topTask === '') {
      $topTask = trim((string) self::extractField($first, 'hasTopTask'));
    }

    return [
      'sample_uri' => (string) self::extractField($first, 'uri'),
      'sample_type' => (string) self::extractField($first, 'typeUri'),
      'sample_hasco_type' => (string) self::extractField($first, 'hascoTypeUri'),
      'sample_top_task' => $topTask,
    ];
  }

  private static function normalizeListElementType($elementtype): string {
    // Preserve explicit type so deployments that expose dedicated WKF
    // endpoints continue to return WKF rows (status/manager scoped).
    return (string) $elementtype;
  }

  private static function isWkfType($elementtype): bool {
    return strtolower(trim((string) $elementtype)) === 'wkf';
  }

  private static function isWkfRecord($item): bool {
    if (!is_object($item) && !is_array($item)) {
      return FALSE;
    }

    $uri = strtolower(trim((string) self::extractField($item, 'uri')));
    $typeUri = strtolower(trim((string) self::extractField($item, 'typeUri')));
    $hascoTypeUri = strtolower(trim((string) self::extractField($item, 'hascoTypeUri')));

    if ($typeUri !== '' && strpos($typeUri, 'wkf') !== FALSE) {
      return TRUE;
    }
    if ($hascoTypeUri !== '' && strpos($hascoTypeUri, 'wkf') !== FALSE) {
      return TRUE;
    }

    // Conservative URI fallback for legacy payloads missing explicit type fields.
    if ($uri !== '' && strpos($uri, '/wkf') !== FALSE) {
      return TRUE;
    }

    // Process-based fallback: keep real process workflows with task hierarchy,
    // but avoid generic placeholders such as AnyProcess.
    $isProcessType = ($typeUri !== '' && strpos($typeUri, 'process') !== FALSE)
      || ($hascoTypeUri !== '' && strpos($hascoTypeUri, 'process') !== FALSE);
    if ($isProcessType) {
      if (strpos($uri, 'anyprocess') !== FALSE) {
        return FALSE;
      }
      $topTask = trim((string) self::extractField($item, 'hasTopTaskUri'));
      if ($topTask === '') {
        $topTask = trim((string) self::extractField($item, 'hasTopTask'));
      }
      return $topTask !== '';
    }

    return FALSE;
  }

  private static function filterWkfRecords($items): array {
    if ($items === NULL) {
      return [];
    }

    if (!is_array($items)) {
      $items = [$items];
    }

    return array_values(array_filter($items, function ($item) {
      return self::isWkfRecord($item);
    }));
  }

  /**
   * Best-effort WKF recency sort (newest first) using URI numeric suffix.
   */
  private static function sortWkfByRecency(array $items): array {
    usort($items, function ($a, $b) {
      $uriA = strtolower(trim((string) self::extractField($a, 'uri')));
      $uriB = strtolower(trim((string) self::extractField($b, 'uri')));

      $idA = 0;
      $idB = 0;
      if ($uriA !== '' && preg_match('/(\d+)(?!.*\d)/', $uriA, $mA) === 1) {
        $idA = (int) $mA[1];
      }
      if ($uriB !== '' && preg_match('/(\d+)(?!.*\d)/', $uriB, $mB) === 1) {
        $idB = (int) $mB[1];
      }

      if ($idA !== $idB) {
        return ($idA < $idB) ? 1 : -1;
      }

      // Deterministic fallback for equal/unknown numeric suffixes.
      return strcmp($uriB, $uriA);
    });

    return $items;
  }

  private static function isAllOwnersToken($manageremail): bool {
    $value = strtolower(trim((string) $manageremail));
    return $value === '' || $value === '_' || $value === 'all';
  }

  private static function shouldBypassManagerEndpoint($elementtype): bool {
    $type = strtolower(trim((string) $elementtype));
    return in_array($type, ['instrument', 'instrumentinstance', 'processbasedstudy'], TRUE);
  }

  private static function isProcessBasedStudyType($elementtype): bool {
    return strtolower(trim((string) $elementtype)) === 'processbasedstudy';
  }

  private static function extractField($item, string $field) {
    if (is_object($item) && isset($item->{$field})) {
      return $item->{$field};
    }
    if (is_array($item) && array_key_exists($field, $item)) {
      return $item[$field];
    }
    return NULL;
  }

  private static function normalizeStatusValue($status): string {
    $raw = trim((string) ($status ?? ''));
    if ($raw === '') {
      return '';
    }
    $fragment = parse_url($raw, PHP_URL_FRAGMENT);
    if (is_string($fragment) && $fragment !== '') {
      return strtolower($fragment);
    }
    return strtolower($raw);
  }

  private static function statusMatchesFilter(string $itemStatus, string $targetStatus, bool $withCurrent): bool {
    if ($targetStatus === '' || $targetStatus === '_') {
      return TRUE;
    }

    if ($itemStatus === $targetStatus) {
      return TRUE;
    }

    return $withCurrent && $itemStatus === 'current';
  }

  private static function responseRespectsStatusFilter($items, $status, bool $withCurrent): bool {
    $targetStatus = self::normalizeStatusValue($status);
    if ($targetStatus === '' || $targetStatus === '_') {
      return TRUE;
    }

    if ($items === NULL) {
      return FALSE;
    }

    if (!is_array($items)) {
      $items = [$items];
    }

    foreach ($items as $item) {
      if (!is_object($item) && !is_array($item)) {
        return FALSE;
      }

      $itemStatus = self::normalizeStatusValue(self::extractField($item, 'hasStatus'));
      if (!self::statusMatchesFilter($itemStatus, $targetStatus, $withCurrent)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  private static function fallbackListByKeyword(
    $api,
    $elementtype,
    $manageremail,
    $status = '_',
    bool $withCurrent = FALSE,
    ?int $pageSize = NULL,
    int $offset = 0
  ): array {
    $strictRuntimeBudget = self::hasStrictRuntimeBudget();
    $fallbackBatchSize = ($pageSize === NULL)
      ? ($strictRuntimeBudget ? 200 : 1000)
      : max(1, min((int) $pageSize, 200));
    $fallbackOffset = ($pageSize === NULL) ? 0 : max(0, (int) $offset);

    if (self::isProcessBasedStudyType($elementtype)) {
      $raw = $api->listProcessBasedStudies($fallbackBatchSize, $fallbackOffset);
      if ($raw === NULL) {
        return [];
      }

      $all = $api->parseObjectResponse($raw, 'listProcessBasedStudies');
      if (!is_array($all)) {
        return [];
      }

      $targetManager = strtolower(trim((string) $manageremail));
      $filterManager = ($targetManager !== '' && $targetManager !== '_');
      $targetStatus = self::normalizeStatusValue($status);
      $filterStatus = ($targetStatus !== '' && $targetStatus !== '_');

      $filtered = array_values(array_filter($all, function ($item) use ($filterManager, $targetManager, $filterStatus, $targetStatus, $withCurrent) {
        if (!is_object($item) && !is_array($item)) {
          return FALSE;
        }

        if ($filterManager) {
          $owner = strtolower(trim((string) self::extractField($item, 'hasSIRManagerEmail')));
          if ($owner === '' || $owner !== $targetManager) {
            return FALSE;
          }
        }

        if ($filterStatus) {
          $itemStatus = self::normalizeStatusValue(self::extractField($item, 'hasStatus'));
          if ($itemStatus === $targetStatus) {
            return TRUE;
          }
          if ($withCurrent && $itemStatus === 'current') {
            return TRUE;
          }
          return FALSE;
        }

        return TRUE;
      }));

      if ($pageSize === NULL) {
        return $filtered;
      }

      // Already queried with paging bounds; avoid re-slicing by original offset.
      return array_slice($filtered, 0, max(0, $pageSize));
    }

    $raw = $api->listByKeyword($elementtype, '_', $fallbackBatchSize, $fallbackOffset);
    if ($raw === NULL) {
      return [];
    }

    $all = $api->parseObjectResponse($raw, 'listByKeyword');
    if (!is_array($all)) {
      return [];
    }

    $targetManager = strtolower(trim((string) $manageremail));
    $filterManager = ($targetManager !== '' && $targetManager !== '_');
    $targetStatus = self::normalizeStatusValue($status);
    $filterStatus = ($targetStatus !== '' && $targetStatus !== '_');

    $filtered = array_values(array_filter($all, function ($item) use ($filterManager, $targetManager, $filterStatus, $targetStatus, $withCurrent) {
      if (!is_object($item) && !is_array($item)) {
        return FALSE;
      }

      if ($filterManager) {
        $owner = strtolower(trim((string) self::extractField($item, 'hasSIRManagerEmail')));
        if ($owner === '' || $owner !== $targetManager) {
          return FALSE;
        }
      }

      if ($filterStatus) {
        $itemStatus = self::normalizeStatusValue(self::extractField($item, 'hasStatus'));
        if ($itemStatus === $targetStatus) {
          return TRUE;
        }
        if ($withCurrent && $itemStatus === 'current') {
          return TRUE;
        }
        return FALSE;
      }

      return TRUE;
    }));

    if ($pageSize === NULL) {
      return $filtered;
    }

    // Already queried with paging bounds; avoid re-slicing by original offset.
    return array_slice($filtered, 0, max(0, $pageSize));
  }

  public static function exec($elementtype, $manageremail, $page, $pagesize) {
    if ($elementtype == NULL || $page == NULL || $pagesize == NULL) {
        $resp = array();
        return $resp;
    }

    $offset = -1;
    if ($page <= 1) {
      $offset = 0;
    } else {
      $offset = ($page - 1) * $pagesize;
    }

    $api = \Drupal::service('rep.api_connector');
    $listElementType = self::normalizeListElementType($elementtype);
    $isWkf = self::isWkfType($elementtype);
    $isAllOwners = self::isAllOwnersToken($manageremail);
    if (!$isAllOwners && !self::shouldBypassManagerEndpoint($listElementType)) {
      $raw = $api->listByManagerEmail($listElementType, $manageremail, $pagesize, $offset);
      if ($raw !== NULL) {
        $elements = $api->parseObjectResponse($raw, 'listByManagerEmail');
        if ($elements !== NULL) {
          if ($isWkf) {
            $rawCount = self::countItems($elements);
            $filtered = self::filterWkfRecords($elements);
            self::wkfDebug('exec_manager_primary', array_merge([
              'is_wkf' => TRUE,
              'elementtype' => $elementtype,
              'list_element_type' => $listElementType,
              'manager' => $manageremail,
              'page' => $page,
              'pagesize' => $pagesize,
              'offset' => $offset,
              'raw_count' => $rawCount,
              'filtered_count' => self::countItems($filtered),
              'source' => 'listByManagerEmail',
            ], self::sampleItemMeta($elements)));
            return $filtered;
          }
          return $elements;
        }
      }
    }

    if ($isWkf && $isAllOwners) {
      $bulkSize = max(200, ((int) $pagesize) * 20);
      $bulk = self::fallbackListByKeyword($api, $listElementType, '_', '_', FALSE, $bulkSize, 0);
      $bulk = self::filterWkfRecords($bulk);
      $bulk = self::sortWkfByRecency($bulk);
      $elements = array_slice($bulk, (int) $offset, max(0, (int) $pagesize));
    }
    else {
      $elements = self::fallbackListByKeyword($api, $listElementType, $isAllOwners ? '_' : $manageremail, '_', FALSE, (int) $pagesize, (int) $offset);
    }

    if ($isWkf) {
      $rawCount = self::countItems($elements);
      $filtered = self::filterWkfRecords($elements);
      self::wkfDebug('exec_fallback', array_merge([
        'is_wkf' => TRUE,
        'elementtype' => $elementtype,
        'list_element_type' => $listElementType,
        'manager' => $manageremail,
        'page' => $page,
        'pagesize' => $pagesize,
        'offset' => $offset,
        'raw_count' => $rawCount,
        'filtered_count' => self::countItems($filtered),
        'source' => 'fallbackListByKeyword',
      ], self::sampleItemMeta($elements)));
      return $filtered;
    }

    //dpm($elements);
    return $elements;

  }

  public static function execReview($elementtype, $status, $page, $pagesize) {
    if ($elementtype == NULL || $page == NULL || $pagesize == NULL) {
        $resp = array();
        return $resp;
    }

    // List status URI
    $offset = -1;
    if ($page <= 1) {
      $offset = 0;
    } else {
      $offset = ($page - 1) * $pagesize;
    }

    $api = \Drupal::service('rep.api_connector');
    $elements = $api->parseObjectResponse($api->listByReviewStatus($elementtype,$status,$pagesize,$offset),'listByReviewStatus');

    //dpm($elements);
    return $elements;

  }

  public static function execByStatusManagerEmail($elementtype, $status, $manageremail, $withCurrent, $page, $pagesize) {
    if ($elementtype == NULL || $status == NULL || $manageremail == NULL || $page == NULL || $pagesize == NULL) {
      return [];
    }

    $offset = ($page <= 1) ? 0 : (($page - 1) * $pagesize);

    $api = \Drupal::service('rep.api_connector');
    $listElementType = self::normalizeListElementType($elementtype);
    $isWkf = self::isWkfType($elementtype);
    $isAllOwners = self::isAllOwnersToken($manageremail);
    if (!$isAllOwners && !self::shouldBypassManagerEndpoint($listElementType)) {
      $raw = $api->listByStatusManagerEmail($listElementType, $status, $manageremail, (bool) $withCurrent, $pagesize, $offset);
      if ($raw !== NULL) {
        $elements = $api->parseObjectResponse($raw, 'listByStatusManagerEmail');
        if ($elements !== NULL) {
          if ($isWkf) {
            $rawCount = self::countItems($elements);
            $elements = self::filterWkfRecords($elements);
            self::wkfDebug('exec_status_primary', array_merge([
              'is_wkf' => TRUE,
              'elementtype' => $elementtype,
              'list_element_type' => $listElementType,
              'status' => $status,
              'manager' => $manageremail,
              'with_current' => (bool) $withCurrent,
              'page' => $page,
              'pagesize' => $pagesize,
              'offset' => $offset,
              'raw_count' => $rawCount,
              'filtered_count' => self::countItems($elements),
              'source' => 'listByStatusManagerEmail',
            ], self::sampleItemMeta($elements)));
          }
          if (self::responseRespectsStatusFilter($elements, $status, (bool) $withCurrent)) {
            return $elements;
          }
        }
      }
    }

    $elements = self::fallbackListByKeyword($api, $listElementType, $isAllOwners ? '_' : $manageremail, $status, (bool) $withCurrent, (int) $pagesize, (int) $offset);
    if ($isWkf) {
      $rawCount = self::countItems($elements);
      $filtered = self::filterWkfRecords($elements);
      self::wkfDebug('exec_status_fallback', array_merge([
        'is_wkf' => TRUE,
        'elementtype' => $elementtype,
        'list_element_type' => $listElementType,
        'status' => $status,
        'manager' => $manageremail,
        'with_current' => (bool) $withCurrent,
        'page' => $page,
        'pagesize' => $pagesize,
        'offset' => $offset,
        'raw_count' => $rawCount,
        'filtered_count' => self::countItems($filtered),
        'source' => 'fallbackListByKeyword',
      ], self::sampleItemMeta($elements)));
      return $filtered;
    }
    return $elements;
  }

  public static function total($elementtype, $manageremail) {
    if ($elementtype == NULL) {
      return -1;
    }
    $api = \Drupal::service('rep.api_connector');
    $listElementType = self::normalizeListElementType($elementtype);
    $isWkf = self::isWkfType($elementtype);
    $isAllOwners = self::isAllOwnersToken($manageremail);
    $response = (self::shouldBypassManagerEndpoint($listElementType) || $isAllOwners)
      ? NULL
      : $api->listSizeByManagerEmail($listElementType,$manageremail);
    $listSize = -1;
    if ($response != NULL) {
      $obj = json_decode($response);
      if ($obj != NULL && $obj->isSuccessful) {
        $body = $obj->body;
        if (is_string($body)) {
          $obj2 = json_decode($body);
          if (is_object($obj2) && isset($obj2->total)) {
            $listSize = (int) $obj2->total;
          }
        }
        elseif (is_object($body) && isset($body->total)) {
          $listSize = (int) $body->total;
        }
      }
    }

    if ($listSize < 0) {
      if (self::isProcessBasedStudyType($listElementType)) {
        $response = $api->listProcessBasedStudiesTotal();
        if ($response != NULL) {
          $obj = json_decode($response);
          if ($obj != NULL && !empty($obj->isSuccessful)) {
            $body = $obj->body;
            if (is_string($body)) {
              $obj2 = json_decode($body);
              if (is_object($obj2) && isset($obj2->total)) {
                $listSize = (int) $obj2->total;
              }
            }
            elseif (is_object($body) && isset($body->total)) {
              $listSize = (int) $body->total;
            }
          }
        }
      }

      if ($listSize < 0) {
        if (self::hasStrictRuntimeBudget()) {
          // Avoid expensive fallback full scans under strict runtime budgets.
          $listSize = 0;
        }
      }

      if ($listSize < 0) {
        $items = self::fallbackListByKeyword($api, $listElementType, $isAllOwners ? '_' : $manageremail);
        if ($isWkf) {
          $rawCount = self::countItems($items);
          $items = self::filterWkfRecords($items);
          self::wkfDebug('total_fallback', array_merge([
            'is_wkf' => TRUE,
            'elementtype' => $elementtype,
            'list_element_type' => $listElementType,
            'manager' => $manageremail,
            'raw_count' => $rawCount,
            'filtered_count' => self::countItems($items),
            'source' => 'fallbackListByKeyword',
          ], self::sampleItemMeta($items)));
        }
        $listSize = count($items);
      }
    }

    if ($isWkf) {
      self::wkfDebug('total_result', [
        'is_wkf' => TRUE,
        'elementtype' => $elementtype,
        'list_element_type' => $listElementType,
        'manager' => $manageremail,
        'list_size' => $listSize,
        'source' => ($response != NULL) ? 'listSizeByManagerEmail' : 'fallback/other',
      ]);
    }

    return $listSize;

  }

  public static function totalByStatusManagerEmail($elementtype, $status, $manageremail, $withCurrent) {
    if ($elementtype == NULL || $status == NULL || $manageremail == NULL) {
      return -1;
    }
    $api = \Drupal::service('rep.api_connector');
    $listElementType = self::normalizeListElementType($elementtype);
    $isWkf = self::isWkfType($elementtype);
    $isAllOwners = self::isAllOwnersToken($manageremail);
    $response = (self::shouldBypassManagerEndpoint($listElementType) || $isAllOwners)
      ? NULL
      : $api->listSizeByStatusManagerEmail($listElementType, $status, $manageremail, (bool) $withCurrent);
    $listSize = -1;
    if ($response != NULL) {
      $obj = json_decode($response);
      if ($obj != NULL && $obj->isSuccessful) {
        $body = $obj->body;
        if (is_string($body)) {
          $obj2 = json_decode($body);
          if (is_object($obj2) && isset($obj2->total)) {
            $listSize = (int) $obj2->total;
          }
        }
        elseif (is_object($body) && isset($body->total)) {
          $listSize = (int) $body->total;
        }
      }
    }

    if ($listSize > 0 && !self::shouldBypassManagerEndpoint($listElementType)) {
      $sampleRaw = $api->listByStatusManagerEmail($listElementType, $status, $manageremail, (bool) $withCurrent, 1, 0);
      if ($sampleRaw !== NULL) {
        $sample = $api->parseObjectResponse($sampleRaw, 'listByStatusManagerEmail');
        if (!self::responseRespectsStatusFilter($sample, $status, (bool) $withCurrent)) {
          $listSize = -1;
        }
      }
    }

    if ($listSize < 0) {
      if (self::hasStrictRuntimeBudget()) {
        // Avoid expensive fallback full scans under strict runtime budgets.
        $listSize = 0;
      }
    }

    if ($listSize < 0) {
      $items = self::fallbackListByKeyword($api, $listElementType, $isAllOwners ? '_' : $manageremail, $status, (bool) $withCurrent);
      if ($isWkf) {
        $rawCount = self::countItems($items);
        $items = self::filterWkfRecords($items);
        self::wkfDebug('total_status_fallback', array_merge([
          'is_wkf' => TRUE,
          'elementtype' => $elementtype,
          'list_element_type' => $listElementType,
          'status' => $status,
          'manager' => $manageremail,
          'with_current' => (bool) $withCurrent,
          'raw_count' => $rawCount,
          'filtered_count' => self::countItems($items),
          'source' => 'fallbackListByKeyword',
        ], self::sampleItemMeta($items)));
      }
      $listSize = count($items);
    }

    if ($isWkf) {
      self::wkfDebug('total_status_result', [
        'is_wkf' => TRUE,
        'elementtype' => $elementtype,
        'list_element_type' => $listElementType,
        'status' => $status,
        'manager' => $manageremail,
        'with_current' => (bool) $withCurrent,
        'list_size' => $listSize,
        'source' => ($response != NULL) ? 'listSizeByStatusManagerEmail' : 'fallback/other',
      ]);
    }

    return $listSize;
  }

  public static function totalByReviewStatus($elementtype, $status) {
    if ($elementtype == NULL || $status == NULL) {
      return -1;
    }
    $api = \Drupal::service('rep.api_connector');
    $response = $api->listSizeByReviewStatus($elementtype, $status);
    $listSize = -1;
    if ($response != NULL) {
      $obj = json_decode($response);
      if ($obj != NULL && $obj->isSuccessful) {
        $listSizeStr = $obj->body;
        $obj2 = json_decode($listSizeStr);
        $listSize = $obj2->total;
      }
    }
    return $listSize;
  }

  public static function link($elementtype, $page, $pagesize) {

    //dpr($elementtype.'-'.$page.'-'.$pagesize.'-'.$module);
    $root_url = \Drupal::request()->getBaseUrl();
    $module = '';
    if ($elementtype != NULL && $page > 0 && $pagesize > 0) {
      $module = Utils::elementTypeModule($elementtype);
      if ($module == NULL) {
        return '';
      }
     return $root_url . '/' . $module . REPGUI::SELECT_PAGE .
          $elementtype . '/' .
          strval($page) . '/' .
          strval($pagesize);
    }
    return '';
  }

  public static function linkdpl($elementtype, $page, $pagesize, $module=NULL) {

    //dpr($elementtype.'-'.$page.'-'.$pagesize.'-'.$module);
    $root_url = \Drupal::request()->getBaseUrl();
    if ($elementtype != NULL && $page > 0 && $pagesize > 0) {
      if (self::isWkfType($elementtype)) {
        return $root_url . '/' . $module . REPGUI::SELECT_PAGE .
          'wkf/table/' .
          strval($page) . '/' .
          strval($pagesize) .
          '/none';
      }

      return $root_url . '/' . $module . REPGUI::SELECT_PAGE . 'mt/' .
        $elementtype .
        '/table/' .
        strval($page) . '/' .
        strval($pagesize) .
        '/none';
    }
    return '';
  }

}

?>
