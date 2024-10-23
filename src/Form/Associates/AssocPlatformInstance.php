<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Entity\Deployment;
use Drupal\rep\ListPropertyPage;
use Drupal\rep\Constant;
use Drupal\rep\Utils;

class AssocPlatformInstance {

  public static function process($element, array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $t = \Drupal::service('string_translation');
    
    /*
     *    PLATFORM INSTANCE's (ACTIVE) DEPLOYMENTS
     */

    //dpm($element);
    $rawobjs = $api->deploymentsByPlatformInstanceWithPage($element->uri,Constant::TOT_OBJS_PER_PAGE,0);
    if ($rawobjs != NULL) {
      $objs = $api->parseObjectResponse($rawobjs,'deploymentsByPlatformInstanceWithPage');
      if ($objs != NULL) {
        $totalobjs = $api->parseTotalResponse($api->sizeDeploymentsByPlatformInstance($element->uri),'sizeDeploymentsByPlatformInstance');
        $form['deployments']['begin_deployments'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Has deployments (total of " . $totalobjs . "):</b><ul>"),
        ];
        $header = Deployment::generateHeader();
        $output = Deployment::generateOutput($objs);
        if ($header != NULL && $output != NULL) {
          $form['deployments']['deployments_table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#rows' => $output,
            '#empty' => t('No response options found'),
          ];
        }
        if ($totalobjs > Constant::TOT_OBJS_PER_PAGE) {
          $link = ListPropertyPage::link($element,HASCO::IS_MEMBER_OF,NULL,1,20);
          $form['deployments']['more_deployments'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['deployments']['end_deployments'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }
    
    /*
     *    PLATFORM INSTANCE's STREAMS
     */

    return $form;        
  }

}        
