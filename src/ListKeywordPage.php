<?php

namespace Drupal\rep;

use Drupal\rep\Vocabulary\REPGUI;

class ListKeywordPage {

  public static function exec($elementtype, $keyword, $page, $pagesize) {
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
    $api = \Drupal::service('rep.api_connector');
    $elements = $api->parseObjectResponse($api->listByKeyword($elementtype,$keyword,$pagesize,$offset),'listByKeyword');
    return $elements;

  }

  public static function total($elementtype, $keyword) {
    if ($elementtype == NULL) {
      return -1;
    }
    if ($keyword == NULL) {
      $keyword = "_";
    }
        
    $api = \Drupal::service('rep.api_connector');
    
    $response = $api->listSizeByKeyword($elementtype,$keyword);
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

  public static function link($elementtype, $keyword, $page, $pagesize) {
    $root_url = \Drupal::request()->getBaseUrl();
    $module = '';
    if ($elementtype != NULL && $page > 0 && $pagesize > 0) {
      $module = Utils::elementTypeModule($elementtype);
      if ($module == NULL) {
        return '';
      }
      return $root_url . '/' . $module . REPGUI::LIST_PAGE . 
          $elementtype . '/' .
          $keyword . '/' .
          strval($page) . '/' . 
          strval($pagesize);
    }
    return ''; 
  }

}

?>