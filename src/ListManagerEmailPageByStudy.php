<?php

namespace Drupal\rep;

use Drupal\rep\Vocabulary\REPGUI;

class ListManagerEmailPageByStudy {

  public static function exec($studyuri, $elementtype, $manageremail, $page, $pagesize) {
    if ($studyuri == NULL || $elementtype == NULL || $page == NULL || $pagesize == NULL) {
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
    $elements = $api->parseObjectResponse($api->listByManagerEmailByStudy($studyuri,$elementtype,$manageremail,$pagesize,$offset),'listByManagerEmail');
    return $elements;

  }

  public static function total($studyuri, $elementtype, $manageremail) {
    if ($studyuri == NULL || $elementtype == NULL) {
      return -1;
    }
    $api = \Drupal::service('rep.api_connector');
    $response = $api->listSizeByManagerEmailByStudy($studyuri,$elementtype,$manageremail);
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

  public static function link($studyuri, $elementtype, $page, $pagesize) {
    $root_url = \Drupal::request()->getBaseUrl();
    $module = '';
    if ($studyuri != NULL && $elementtype != NULL && $page > 0 && $pagesize > 0) {
      $module = Utils::elementTypeModule($elementtype);
      if ($module == NULL) {
        return '';
      }
     return $root_url . '/' . $module . REPGUI::SELECT_PAGE_BYSTUDY . 
          base64_encode($studyuri) . '/' . 
          $elementtype . '/' .
          strval($page) . '/' . 
          strval($pagesize);
    }
    return ''; 
  }

}

?>