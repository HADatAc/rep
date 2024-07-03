<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Entity\StudyObject;
use Drupal\rep\ListPropertyPage;
use Drupal\rep\Constant;
use Drupal\rep\Utils;

class AssocStudyObjectCollection {

  public static function process($element, array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $t = \Drupal::service('string_translation');
    
    /*
    *    SOC's STUDY OBJECTS
    */
    $rawobjs = $api->studyObjectsBySOCwithPage($element->uri,Constant::TOT_OBJS_PER_PAGE,0);
    if ($rawobjs != NULL) {
      $objs = $api->parseObjectResponse($rawobjs,'studyObjectsBySOCwithPage');
      if ($objs != NULL) {
        $totalobjs = $api->parseTotalResponse($api->sizeStudyObjectsBySOC($element->uri),'sizeStudyObjectsBySOC');
        $form['objs']['begin_objects'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Contains Study Objects (total of " . $totalobjs . "):</b><ul>"),
        ];
        $header = StudyObject::generateHeader();
        $output = StudyObject::generateOutput($objs);
        if ($header != NULL && $output != NULL) {
          $form['objs']['objects_table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#rows' => $output,
            '#empty' => t('No response options found'),
          ];
        }
        if ($totalobjs > Constant::TOT_OBJS_PER_PAGE) {
          $link = ListPropertyPage::link($element,HASCO::IS_MEMBER_OF,NULL,1,20);
          $form['objs']['more_objects'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['objs']['end_objects'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }
    return $form;        
  }
}        
