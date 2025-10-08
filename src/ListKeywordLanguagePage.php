<?php

namespace Drupal\rep;

use Drupal\rep\Vocabulary\REPGUI;

class ListKeywordLanguagePage {

  public static function exec($elementtype, $keyword, $language, $type, $manageremail, $status, $page, $pagesize) {
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

    if ($keyword == NULL) {
      $keyword = "_";
    }
    if ($language == NULL) {
      $language = "_";
    }
    if ($type == NULL) {
      $type = "_";
    }
    if ($manageremail == NULL) {
      $manageremail = "_";
    }
    if ($status == NULL) {
      $status = "_";
    }

    // dpm("ListKeywordLanguagePage::exec: elementtype=$elementtype, keyword=$keyword, language=$language, type=$type, manageremail=$manageremail, status=$status, page=$page, pagesize=$pagesize");

    $api = \Drupal::service('rep.api_connector');
    $elements = $api->parseObjectResponse($api->listByKeywordAndLanguage($elementtype,$keyword,$language,$type,$manageremail,$status,$pagesize,$offset),'listByKeywordAndLanguage');

    return $elements;

  }

  public static function execReview($elementtype, $manageremail, $page, $pagesize) {
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

  public static function total($elementtype, $keyword, $language, $type, $manageremail, $status) {
    if ($elementtype == NULL) {
      return -1;
    }
    if ($keyword == NULL) {
      $keyword = "_";
    }
    if ($language == NULL) {
      $language = "_";
    }
    if ($type == NULL) {
      $type = "_";
    }
    if ($manageremail == NULL) {
      $manageremail = "_";
    }
    if ($status == NULL) {
      $status = "_";
    }
    // dpm("ListKeywordLanguagePage::total: elementtype=$elementtype, keyword=$keyword, language=$language, type=$type, manageremail=$manageremail, status=$status");


    $api = \Drupal::service('rep.api_connector');

    $response = $api->listSizeByKeywordAndLanguage($elementtype,$keyword,$language,$type,$manageremail,$status);
    $listSize = -1;
    if ($response != null) {
      $obj = json_decode($response);
      if ($obj->isSuccessful) {
        $listSizeStr = $obj->body;
        $obj2 = json_decode($listSizeStr);
        $listSize = $obj2->total;
      }
    }
    return $listSize;

  }

  public static function link($elementtype, $keyword, $language, $type, $manageremail, $status, $page, $pagesize) {
    $root_url = \Drupal::request()->getBaseUrl();
    $module = '';
    if ($elementtype != NULL && $page > 0 && $pagesize > 0) {
      $module = Utils::elementTypeModule($elementtype);
      if ($module == NULL) {
        return '';
      }
      if ($type == NULL) {
        $type = "_";
      }
      if ($manageremail == NULL) {
        $manageremail = "_";
      }
      if ($status == NULL) {
        $status = "_";
      }
      return $root_url . '/' . $module . REPGUI::LIST_PAGE .
          $elementtype . '/' .
          $keyword . '/' .
          $language . '/' .
          $type . '/' .
          $manageremail . '/' .
          $status . '/' .
          strval($page) . '/' .
          strval($pagesize);
    }
    return '';
  }

}

?>
