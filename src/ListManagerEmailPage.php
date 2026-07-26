<?php

namespace Drupal\rep;

use Drupal\rep\Vocabulary\REPGUI;

class ListManagerEmailPage {

  private static function isAllOwnersToken($manageremail): bool {
    $value = strtolower(trim((string) $manageremail));
    return $value === '' || $value === '_' || $value === 'all';
  }

  private static function shouldBypassManagerEndpoint($elementtype): bool {
    $type = strtolower(trim((string) $elementtype));
    return in_array($type, ['instrument', 'instrumentinstance'], TRUE);
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
    $raw = $api->listByKeyword($elementtype, '_', 5000, 0);
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

    return array_slice($filtered, max(0, $offset), max(0, $pageSize));
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
    $isAllOwners = self::isAllOwnersToken($manageremail);
    if (!$isAllOwners && !self::shouldBypassManagerEndpoint($elementtype)) {
      $raw = $api->listByManagerEmail($elementtype, $manageremail, $pagesize, $offset);
      if ($raw !== NULL) {
        $elements = $api->parseObjectResponse($raw, 'listByManagerEmail');
        if ($elements !== NULL) {
          return $elements;
        }
      }
    }

    $elements = self::fallbackListByKeyword($api, $elementtype, $isAllOwners ? '_' : $manageremail, '_', FALSE, (int) $pagesize, (int) $offset);

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
    $isAllOwners = self::isAllOwnersToken($manageremail);
    if (!$isAllOwners && !self::shouldBypassManagerEndpoint($elementtype)) {
      $raw = $api->listByStatusManagerEmail($elementtype, $status, $manageremail, (bool) $withCurrent, $pagesize, $offset);
      if ($raw !== NULL) {
        $elements = $api->parseObjectResponse($raw, 'listByStatusManagerEmail');
        if ($elements !== NULL && self::responseRespectsStatusFilter($elements, $status, (bool) $withCurrent)) {
          return $elements;
        }
      }
    }

    $elements = self::fallbackListByKeyword($api, $elementtype, $isAllOwners ? '_' : $manageremail, $status, (bool) $withCurrent, (int) $pagesize, (int) $offset);
    return $elements;
  }

  public static function total($elementtype, $manageremail) {
    if ($elementtype == NULL) {
      return -1;
    }
    $api = \Drupal::service('rep.api_connector');
    $isAllOwners = self::isAllOwnersToken($manageremail);
    $response = (self::shouldBypassManagerEndpoint($elementtype) || $isAllOwners)
      ? NULL
      : $api->listSizeByManagerEmail($elementtype,$manageremail);
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
      $listSize = count(self::fallbackListByKeyword($api, $elementtype, $isAllOwners ? '_' : $manageremail));
    }

    return $listSize;

  }

  public static function totalByStatusManagerEmail($elementtype, $status, $manageremail, $withCurrent) {
    if ($elementtype == NULL || $status == NULL || $manageremail == NULL) {
      return -1;
    }
    $api = \Drupal::service('rep.api_connector');
    $isAllOwners = self::isAllOwnersToken($manageremail);
    $response = (self::shouldBypassManagerEndpoint($elementtype) || $isAllOwners)
      ? NULL
      : $api->listSizeByStatusManagerEmail($elementtype, $status, $manageremail, (bool) $withCurrent);
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

    if ($listSize > 0 && !self::shouldBypassManagerEndpoint($elementtype)) {
      $sampleRaw = $api->listByStatusManagerEmail($elementtype, $status, $manageremail, (bool) $withCurrent, 1, 0);
      if ($sampleRaw !== NULL) {
        $sample = $api->parseObjectResponse($sampleRaw, 'listByStatusManagerEmail');
        if (!self::responseRespectsStatusFilter($sample, $status, (bool) $withCurrent)) {
          $listSize = -1;
        }
      }
    }

    if ($listSize < 0) {
      $listSize = count(self::fallbackListByKeyword($api, $elementtype, $isAllOwners ? '_' : $manageremail, $status, (bool) $withCurrent));
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
     return $root_url . '/' . $module . REPGUI::SELECT_PAGE . 'mt/' .
          $elementtype .
          '/table' . '/' .
          strval($page) . '/' .
          strval($pagesize) .
          '/none';
    }
    return '';
  }

}

?>
