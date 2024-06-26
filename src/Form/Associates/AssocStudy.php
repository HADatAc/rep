<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\ListPropertyPage;
use Drupal\rep\Constant;
use Drupal\rep\Utils;

class AssocStudy {

  public static function process($element, array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $t = \Drupal::service('string_translation');
    
    /*
     *    STUDY's SOC
     */
    $rawsocs = $api->getSOCs($element->uri,Constant::TOT_SOCS_PER_PAGE,0);
    if ($rawsocs != NULL) {
      $socs = $api->parseObjectResponse($rawsocs,'getSOCs');
      if ($socs != NULL) {
        $totalsocs = $api->parseTotalResponse($api->getTotalSOCs($element->uri),'getTotalSOCs');

        /* 
         *    SUBJECT SOCs
         */
        $form['socs']['begin_subjects'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Contains Subject Collections (total of " . $totalsocs . "):</b><ul>"),
        ];
        $uriType = array();
        $header = self::buildSOCGenericHeader();
        $output = self::populateSOCs($socs,'subjects');
        if ($header != NULL && $output != NULL) {
          $form['socs']['subjects_table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#rows' => $output,
            '#empty' => t('No response options found'),
          ];
        }
        if ($totalsocs > Constant::TOT_SOCS_PER_PAGE) {
          $link = ListPropertyPage::link($element,HASCO::IS_MEMBER_OF,NULL,1,20);
          $form['socs']['more_subjects'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['socs']['end_subjects'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];

        /* 
         *    SAMPLES SOCs
         */
        $form['socs']['begin_samples'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Contains Sample Collections (total of " . $totalsocs . "):</b><ul>"),
        ];
        $uriType = array();
        $header = self::buildSOCGenericHeader();
        $output = self::populateSOCs($socs,'samples');
        if ($header != NULL && $output != NULL) {
          $form['socs']['samples_table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#rows' => $output,
            '#empty' => t('No response options found'),
          ];
        }
        if ($totalsocs > Constant::TOT_SOCS_PER_PAGE) {
          $link = ListPropertyPage::link($element,HASCO::IS_MEMBER_OF,NULL,1,20);
          $form['socs']['more_samples'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['socs']['end_samples'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];

        /* 
         *    SAMPLES SOCs
         */
        $form['socs']['begin_spaces'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Contains Space Collections (total of " . $totalsocs . "):</b><ul>"),
        ];
        $uriType = array();
        $header = self::buildSOCSpatialHeader();
        $output = self::populateSpatialSOCs($socs);
        if ($header != NULL && $output != NULL) {
          $form['socs']['space_table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#rows' => $output,
            '#empty' => t('No response options found'),
          ];
        }
        if ($totalsocs > Constant::TOT_SOCS_PER_PAGE) {
          $link = ListPropertyPage::link($element,HASCO::IS_MEMBER_OF,NULL,1,20);
          $form['socs']['more_spaces'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['socs']['end_spaces'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];

        /* 
         *    TIMES SOCs
         */
        $form['socs']['begin_times'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Contains Time Collections (total of " . $totalsocs . "):</b><ul>"),
        ];
        $uriType = array();
        $header = self::buildSOCTemporalHeader();
        $output = self::populateTemporalSOCs($socs);
        if ($header != NULL && $output != NULL) {
          $form['socs']['times_table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#rows' => $output,
            '#empty' => t('No response options found'),
          ];
        }
        if ($totalsocs > Constant::TOT_SOCS_PER_PAGE) {
          $link = ListPropertyPage::link($element,HASCO::IS_MEMBER_OF,NULL,1,20);
          $form['socs']['more_times'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['socs']['end_times'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];

      }
    }
    return $form;        
  }
        
  # BUILD SOC GENERIC HEADER
  public static function buildSOCGenericHeader() {
    $header = [
      'soc_uri' => t('URI'),
      'soc_label' => t('Label'),
      'soc_grounding_label' => t('Grounding Label'),
      'soc_reference' => t('Reference'),
      'soc_role_label' => t('Role Label'),
      'soc_has_scope' => t('Has Scope'),
      'soc_has_space_scopes' => t('Has Space Scopes'),
      'soc_has_time_scopes' => t('Has Time Scopes'),
      'soc_operations' => t('Operations'),
    ];
    return $header;
  }

  # BUILD SOC SPATIAL HEADER
  public static function buildSOCSpatialHeader() {
    $header = [
      'soc_uri' => t('URI'),
      'soc_label' => t('Label'),
      'soc_grounding_label' => t('Grounding Label'),
      'soc_reference' => t('Reference'),
      'soc_role_label' => t('Role Label'),
      'soc_has_space_scopes' => t('Has Space Scopes'),
      'soc_operations' => t('Operations'),
    ];
    return $header;
  }

  # BUILD SOC TEMPORAL HEADER
  public static function buildSOCTemporalHeader() {
    $header = [
      'soc_uri' => t('URI'),
      'soc_label' => t('Label'),
      'soc_grounding_label' => t('Grounding Label'),
      'soc_reference' => t('Reference'),
      'soc_role_label' => t('Role Label'),
      'soc_has_time_scopes' => t('Has Time Scopes'),
      'soc_operations' => t('Operations'),
    ];
    return $header;
  }

  # POPULATE SOC GENERIC DATA
  public static function populateSOCs($socs,$type) {
    $root_url = \Drupal::request()->getBaseUrl();

    $output = array();
    if ($socs != NULL) {
      foreach ($socs as $soc) {
        if ($soc->uri != NULL && $soc->uri != "") {
          $linkObjects = $root_url.REPGUI::MANAGE_STUDY_OBJECTS.base64_encode($soc->uri);
          $button = '<a href="' . $linkObjects . '" class="btn btn-primary btn-sm" '.
            ' role="button">View Objects</a>';
          $spaceScopes = ' '; 
          if ($soc->spaceScopeUris != array()) {
            // DO SOMETHING WITH SPACE SCOPES
          }
          $timeScopes = ' ';  
          if ($soc->timeScopeUris != array()) {
            // DO SOMETHING WITH TIME SCOPES
          }
          $output[$soc->uri] = [
            'soc_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($soc->uri).'">'.
              Utils::namespaceUri($soc->uri).'</a>'),         
            'soc_label' => $soc->label,     
            'soc_grounding_label' => $soc->groundingLabel,
            'soc_reference' => $soc->socreference,     
            'soc_role_label' => $soc->roleLabel,     
            'soc_has_scope' => $soc->hasScopeUri,     
            'soc_has_space_scopes' => $spaceScopes,     
            'soc_has_time_scopes' => $timeScopes,     
            'soc_operations' => t($button),     
          ];
        }
      }
    }
    return $output;
  }

  # POPULATE SOC SPATIAL DATA
  public static function populateSpatialSOCs($socs) {
    $root_url = \Drupal::request()->getBaseUrl();
    $output = array();
    if ($socs != NULL) {
      foreach ($socs as $soc) {
        if ($soc->uri != NULL && $soc->uri != "") {
          $linkObjects = $root_url.REPGUI::MANAGE_STUDY_OBJECTS.base64_encode($soc->uri);
          $button = '<a href="' . $linkObjects . '" class="btn btn-primary btn-sm" '.
            ' role="button">View Objects</a>';
          $spaceScopes = ' '; 
          if ($soc->spaceScopeUris != array()) {
            // DO SOMETHING WITH SPACE SCOPES
          }
          $timeScopes = ' ';  
          if ($soc->timeScopeUris != array()) {
            // DO SOMETHING WITH TIME SCOPES
          }
          $output[$soc->uri] = [
            'soc_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($soc->uri).'">'.
              Utils::namespaceUri($soc->uri).'</a>'),         
            'soc_label' => $soc->label,     
            'soc_grounding_label' => $soc->groundingLabel,
            'soc_reference' => $soc->socreference,     
            'soc_role_label' => $soc->roleLabel,     
            'soc_has_space_scopes' => $spaceScopes,     
            'soc_operations' => t($button),     
          ];
        }
      }
    }
    return $output;
  }

  # POPULATE SOC TEMPORAL DATA
  public static function populateTemporalSOCs($socs) {
    $root_url = \Drupal::request()->getBaseUrl();
    $output = array();
    if ($socs != NULL) {
      foreach ($socs as $soc) {
        if ($soc->uri != NULL && $soc->uri != "") {
          $linkObjects = $root_url.REPGUI::MANAGE_STUDY_OBJECTS.base64_encode($soc->uri);
          $button = '<a href="' . $linkObjects . '" class="btn btn-primary btn-sm" '.
            ' role="button">View Objects</a>';
          $spaceScopes = ' '; 
          if ($soc->spaceScopeUris != array()) {
            // DO SOMETHING WITH SPACE SCOPES
          }
          $timeScopes = ' ';  
          if ($soc->timeScopeUris != array()) {
            // DO SOMETHING WITH TIME SCOPES
          }
          $output[$soc->uri] = [
            'soc_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($soc->uri).'">'.
              Utils::namespaceUri($soc->uri).'</a>'),         
            'soc_label' => $soc->label,     
            'soc_grounding_label' => $soc->groundingLabel,
            'soc_reference' => $soc->socreference,     
            'soc_role_label' => $soc->roleLabel,     
            'soc_has_time_scopes' => $timeScopes,     
            'soc_operations' => t($button),     
          ];
        }
      }
    }
    return $output;
  }

}

/*
 @if(oc.getTypeUri().equals("http://hadatac.org/ont/hasco/SubjectGroup")) {

  <tr>
     <td>@if(oc.getLabel() != null) { @oc.getLabel() }</td>
     <td>@if(vc.getGroundingLabel() != null) { @vc.getGroundingLabel() }</td>
     <td>@if(vc.getSOCReference() != null) { @vc.getSOCReference() }</td>
     <td>@if(oc.getRoleLabel() != null) { @oc.getRoleLabel() }</td>
     <td>@if(oc.getComment() != null) { @oc.getComment() }</td>
     <td>@if(oc.getHasScope() != null) { @oc.getHasScope().getLabel() }</td>
     <td>@if(oc.getSpaceScopes() != null) { 
       @for(scope <- oc.getSpaceScopes()) {
          @scope.getLabel() <br>
       }
     }
     </td>
     <td>@if(oc.getTimeScopes() != null) { 
       @for(scope <- oc.getTimeScopes()) {
          @scope.getLabel() <br>
       }
     }
     </td>
     <td>@oc.getObjectUris().size()</td>
     <td>
        <a View Objects</a>
        <a List Obj. URIs</a>
     </td>
 </tr>
 }
  @if(oc.getTypeUri().equals("http://hadatac.org/ont/hasco/SampleCollection")) {
  <tr>
     <td>@if(oc.getLabel() != null) { @oc.getLabel() }</td>
     <td>@if(vc.getGroundingLabel() != null) { @vc.getGroundingLabel() }</td>
     <td>@if(vc.getSOCReference() != null) { @vc.getSOCReference() }</td>
     <td>@if(oc.getRoleLabel() != null) { @oc.getRoleLabel() }</td>
     <td>@if(oc.getComment() != null) { @oc.getComment() }</td>
     <td>@if(oc.getHasScope() != null) { @oc.getHasScope().getLabel() }</td>
     <td>@if(oc.getSpaceScopes() != null) { 
       @for(scope <- oc.getSpaceScopes()) {
          @scope.getLabel() <br>
       }
     }
     </td>
     <td>@if(oc.getTimeScopes() != null) { 
       @for(scope <- oc.getTimeScopes()) {
          @scope.getLabel() <br>
       }
     }
     </td>
     <td>@oc.getObjectUris().size()</td>
     <td>
        <a View Objects</a>
     </td>
 </tr>
 }
  @if(oc.getTypeUri().equals("http://hadatac.org/ont/hasco/LocationCollection")) {
  <tr>
     <td>@if(oc.getLabel() != null) { @oc.getLabel() }</td>
     <td>@if(vc.getGroundingLabel() != null) { @vc.getGroundingLabel() }</td>
     <td>@if(vc.getSOCReference() != null) { @vc.getSOCReference() }</td>
     <td>@if(oc.getRoleLabel() != null) { @oc.getRoleLabel() }</td>
     <td>@if(oc.getComment() != null) { @oc.getComment() }</td>
     <td>@if(oc.getSpaceScopes() != null) { 
       @for(scope <- oc.getSpaceScopes()) {
          @scope.getLabel() <br>
       }
     }
     </td>
     <td>@oc.getObjectUris().size()</td>
     <td>
        <a View Objects</a>
     </td>
 </tr>
 }
  @if(mode.equals("time") && oc.getTypeUri().equals("http://hadatac.org/ont/hasco/TimeCollection")) {
  <tr>
     <td>@if(oc.getLabel() != null) { @oc.getLabel() }</td>
     <td>@if(vc.getGroundingLabel() != null) { @vc.getGroundingLabel() }</td>
     <td>@if(vc.getSOCReference() != null) { @vc.getSOCReference() }</td>
     <td>@if(oc.getRoleLabel() != null) { @oc.getRoleLabel() }</td>
     <td>@if(oc.getComment() != null) { @oc.getComment() }</td>
     <td>@if(oc.getTimeScopes() != null) {
       @for(scope <- oc.getTimeScopes()) {
          @scope.getLabel() <br>
       }
     }
     </td>
     <td>@oc.getObjectUris().size()</td>
     <td>
        <a View Objects</a>
     </td>
 </tr>
 }
}
*/