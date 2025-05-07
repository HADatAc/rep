<?php

namespace Drupal\rep;

use Drupal\rep\Vocabulary\REPGUI;

class ListKeywordTypePage {

  public static function exec($elementtype, $page, $pagesize, $project = '_', $keyword = '_', $type = '_', $manageremail = '_', $status = '_') {
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

    if ($project == NULL) {
      $project = "_";
    }
    if ($keyword == NULL) {
      $keyword = "_";
    }
    //dpm("E=".$elementtype.", PR=".$project.", K=".$keyword.", T=".$type.", M=".$manageremail.", S=".$status.", P=".$page.", O=".$pagesize);

    $api = \Drupal::service('rep.api_connector');
    $elements = $api->parseObjectResponse($api->listByKeywordType($elementtype,$pagesize,$offset,$project,$keyword,$type,$manageremail,$status),'listByKeywordType');

    return $elements;

  }

  public static function execReview($elementtype, $page, $pagesize, $manageremail) {
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

  public static function total($elementtype, $project = 'all', $keyword = '_', $type = '_', $manageremail = '_', $status = '_') {
    if ($elementtype == NULL) {
      return -1;
    }
    if ($keyword == NULL) {
      $keyword = "_";
    }

    $api = \Drupal::service('rep.api_connector');

    $response = $api->listSizeByKeywordType($elementtype,$project,$keyword,$type,$manageremail,$status);
    $listSize = -1;
    \Drupal::logger('rep')->debug('ListKeywordTypePage::total() response: ' . $response);

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

  public static function link($elementtype, $page, $pagesize, $project = 'all', $keyword = '_', $type = '_', $manageremail = '_', $status = '_') {
    $root_url = \Drupal::request()->getBaseUrl();
    $module = '';
    if ($elementtype != NULL && $page > 0 && $pagesize > 0) {
      $module = Utils::elementTypeModule($elementtype);
      if ($module == NULL) {
        return '';
      }
      return $root_url . '/' . $module . REPGUI::LIST_PAGE .
          $elementtype . '/' .
          $project . '/' .
          $keyword . '/' .
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
