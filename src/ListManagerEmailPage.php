<?php

namespace Drupal\rep;

use Drupal\rep\Vocabulary\REPGUI;

class ListManagerEmailPage {

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
    $elements = $api->parseObjectResponse($api->listByManagerEmail($elementtype,$manageremail,$pagesize,$offset),'listByManagerEmail');

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

  public static function total($elementtype, $manageremail) {
    if ($elementtype == NULL) {
      return -1;
    }
    $api = \Drupal::service('rep.api_connector');
    $response = $api->listSizeByManagerEmail($elementtype,$manageremail);
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
