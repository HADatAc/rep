<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Entity\VSTOIInstance;
use Drupal\rep\ListPropertyPage;
use Drupal\rep\Constant;
use Drupal\rep\Utils;

class AssocPlatform {

  public static function process($element, array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $t = \Drupal::service('string_translation');
    
    /*
     *    PLATFORM's PLATFORM INSTANCES
     */

    $rawobjs = $api->platformInstancesByPlatformWithPage($element->uri,Constant::TOT_OBJS_PER_PAGE,0);
    if ($rawobjs != NULL) {
      $objs = $api->parseObjectResponse($rawobjs,'platformInstancesByPlatformWithPage');
      //dpm($objs);
      if ($objs != NULL) {
        $totalobjs = $api->parseTotalResponse($api->sizePlatformInstancesByPlatform($element->uri),'sizePlaformInstanceByPlatform');
        //dpm($totalobjs);
        $form['pltinst']['begin_pltinst'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Has platform instances (total of " . $totalobjs . "):</b><ul>"),
        ];
        $header = VSTOIInstance::generateHeader('platforminstance');
        $output = VSTOIInstance::generateOutput('platforminstance', $objs);
        if ($header != NULL && $output != NULL) {
          $form['pltinst']['pltinst_table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#rows' => $output,
            '#empty' => t('No response options found'),
          ];
        }
        if ($totalobjs > Constant::TOT_OBJS_PER_PAGE) {
          $link = ListPropertyPage::link($element,HASCO::IS_MEMBER_OF,NULL,1,20);
          $form['pltinst']['more_pltinst'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['pltinst']['end_pltinst'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }
    
    return $form;        
  }

}        
