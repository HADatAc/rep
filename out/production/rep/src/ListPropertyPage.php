<?php

namespace Drupal\rep;

use Drupal\rep\Vocabulary\FOAF;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\SCHEMA;

class ListPropertyPage {

  public static function exec($element, $property, $elementtype, $page, $pagesize) {
    if ($element == NULL || $property == NULL || $page == NULL || $pagesize == NULL) {
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
    if ($element->uri != NULL && $property == SCHEMA::CONTAINS_PLACE) {
      $elements = $api->parseObjectResponse($api->getContains($element->uri,$pagesize,$offset),'getContains');
      return $elements;
    } else if ($element->uri != NULL && $property == SCHEMA::SUB_ORGANIZATION) {
      $elements = $api->parseObjectResponse($api->getSubOrganizations($element->uri,$pagesize,$offset),'getSubOrganizations');
      return $elements;
    } else if ($element->uri != NULL && $property == FOAF::MEMBER) {
      $elements = $api->parseObjectResponse($api->getAffiliations($element->uri,$pagesize,$offset),'getAffiliations');
      return $elements;
    } else if ($element->uri != NULL && $property == HASCO::IS_MEMBER_OF) {
      $elements = $api->parseObjectResponse($api->getStudySOCs($element->uri,$pagesize,$offset),'getStudySOCs');
      return $elements;
    } else if ($element->uri != NULL && $property == SCHEMA::HAS_ADDRESS && $elementtype == NULL) {
      $elements = $api->parseObjectResponse($api->getContainsPostalAddress($element->uri,$pagesize,$offset),'getContainsPostalAddress');
      return $elements;
    } else if ($element->uri != NULL && $property == SCHEMA::HAS_ADDRESS && $elementtype != NULL) {
      $elements = $api->parseObjectResponse($api->getContainsElement($element->uri,$elementtype,$pagesize,$offset),'getContainsPostalAddress');
      return $elements;
    }
    return array();
  }

  public static function total($element, $property, $elementtype) {
    if ($element == NULL) {
      return -1;
    }
    $api = \Drupal::service('rep.api_connector');
    if ($property == SCHEMA::CONTAINS_PLACE) {
      $response = $api->getTotalContains($element->uri);
    } else if ($property == SCHEMA::SUB_ORGANIZATION) {
      $response = $api->getTotalSubOrganizations($element->uri);
    } else if ($property == FOAF::MEMBER) {
      $response = $api->getTotalAffiliations($element->uri);
    } else if ($property == HASCO::IS_MEMBER_OF) {
      $response = $api->getTotalStudySOCs($element->uri);
    } else if ($property == SCHEMA::HAS_ADDRESS && $elementtype == NULL) {
      $response = $api->getTotalContainsPostalAddress($element->uri);
    } else if ($property == SCHEMA::HAS_ADDRESS && $elementtype != NULL) {
      $response = $api->getTotalContainsElement($element->uri,$elementtype);
    }
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

  public static function link($element, $property, $elementtype, $page, $pagesize) {
    $root_url = \Drupal::request()->getBaseUrl();
    $module = '';
    if ($element != NULL) {
      $module = Utils::elementModule($element);
      if ($module == NULL) {
        return '';
      }
      if ($elementtype == NULL) {
        $resp = $root_url . '/' . $module . REPGUI::PROPERTY_PAGE . 
            base64_encode($element->uri) . '/' .
            base64_encode($property) . '/' .
            base64_encode("*NULL*") . '/' .
            strval($page) . '/' . 
            strval($pagesize);
        return $resp;
      } else {
        $resp = $root_url . '/' . $module . REPGUI::PROPERTY_PAGE . 
            base64_encode($element->uri) . '/' .
            base64_encode($property) . '/' .
            base64_encode($elementtype). '/' .
            strval($page) . '/' . 
            strval($pagesize);
        return $resp;
      }
    }
    return ''; 
  }

}

?>