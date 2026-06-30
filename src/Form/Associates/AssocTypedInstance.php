<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Constant;
use Drupal\rep\Entity\VSTOIInstance;

class AssocTypedInstance {

  public static function process($element, array &$form, FormStateInterface $form_state, $instanceType, $sectionKey, $sectionLabel) {
    if (!isset($element->uri) || $element->uri == NULL || $element->uri === '') {
      return $form;
    }

    $api = \Drupal::service('rep.api_connector');
    $t = \Drupal::service('string_translation');

    $rawobjs = $api->listByKeywordType($instanceType, Constant::TOT_OBJS_PER_PAGE, 0, 'all', '_', $element->uri, '_', '_');
    if ($rawobjs == NULL) {
      return $form;
    }

    $objs = $api->parseObjectResponse($rawobjs, 'listByKeywordType');
    if ($objs == NULL) {
      return $form;
    }

    if (!is_array($objs)) {
      $objs = [$objs];
    }

    if (count($objs) === 0) {
      return $form;
    }

    $totalobjs = self::extractTotal($api, $instanceType, $element->uri, count($objs));

    $form[$sectionKey]['begin'] = [
      '#type' => 'markup',
      '#markup' => $t->translate('<b>@label (total of @total):</b><ul>', ['@label' => $sectionLabel, '@total' => (string) $totalobjs]),
    ];

    $header = VSTOIInstance::generateHeader($instanceType);
    $output = VSTOIInstance::generateOutput($instanceType, $objs);
    if ($header != NULL && $output != NULL) {
      $form[$sectionKey]['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $output,
        '#empty' => t('No instances found'),
      ];
    }

    $form[$sectionKey]['end'] = [
      '#type' => 'markup',
      '#markup' => $t->translate('</ul><br>'),
    ];

    return $form;
  }

  private static function extractTotal($api, $instanceType, $typeUri, $fallbackTotal) {
    $response = $api->listSizeByKeywordType($instanceType, 'all', '_', $typeUri, '_', '_');
    if ($response != NULL) {
      $obj = json_decode($response);
      if (is_object($obj) && !empty($obj->isSuccessful)) {
        $body = $obj->body ?? NULL;
        if (is_string($body)) {
          $obj2 = json_decode($body);
          if (is_object($obj2) && isset($obj2->total)) {
            return (int) $obj2->total;
          }
        }
        elseif (is_object($body) && isset($body->total)) {
          return (int) $body->total;
        }
      }
    }

    return (int) $fallbackTotal;
  }

}
