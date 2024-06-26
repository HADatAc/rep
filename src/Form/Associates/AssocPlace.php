<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\ListPropertyPage;
use Drupal\rep\Constant;
use Drupal\rep\Utils;

class AssocPlace {

  public static function process($element, array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $t = \Drupal::service('string_translation');
    
    /*
     *    PLACE's PLACES
    */
    $rawContains = $api->getContains($element->uri,Constant::TOT_PER_PAGE,0);
    if ($rawContains != NULL) {
      $contains = $api->parseObjectResponse($rawContains,'getContains');
      if ($contains != NULL) {
        $totalContains = $api->parseTotalResponse($api->getTotalContains($element->uri),'getTotalContains');
        $form['place']['beginContains'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Contains Places (total of " . $totalContains . "):</b><ul>"),
        ];
        foreach ($contains as $propertyNameContains => $propertyValueContains) {
          $form['place'][$propertyNameContains] = [
            '#type' => 'markup',
            '#markup' => $t->translate("<li>" . Utils::link($propertyValueContains->label,$propertyValueContains->uri) . "</li>"),
          ];
        }
        if ($totalContains > Constant::TOT_PER_PAGE) {
          $link = ListPropertyPage::link($element,SCHEMA::CONTAINS_PLACE,NULL,1,20);
          $form['place']['moreElements'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['place']['endContains'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }

    /*
     *    PLACE's ORGANIZATIONS
     */
    $rawContainsOrg = $api->getContainsElement($element->uri,'organization',Constant::TOT_PER_PAGE,0);
    if ($rawContainsOrg != NULL) {
      $containsOrg = $api->parseObjectResponse($rawContainsOrg,'getContainsElement');
      if ($containsOrg != NULL) {
        $totalContainsOrg = $api->parseTotalResponse($api->getTotalContainsElement($element->uri,'organization'),'getTotalContainsElement');
        $form['organization']['beginContains'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Contains Organizations (total of " . $totalContainsOrg . "):</b><ul>"),
        ];
        foreach ($containsOrg as $propertyNameContainsOrg => $propertyValueContainsOrg) {
          $form['organization'][$propertyNameContainsOrg] = [
            '#type' => 'markup',
            '#markup' => $t->translate("<li>" . Utils::link($propertyValueContainsOrg->label,$propertyValueContainsOrg->uri) . "</li>"),
          ];
        }
        if ($totalContainsOrg > Constant::TOT_PER_PAGE) {
          $link = ListPropertyPage::link($element,SCHEMA::HAS_ADDRESS,'organization',1,20);
          $form['organization']['moreElements'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['organization']['endContains'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }

    /*
     *    PLACE's POSTAL ADDRESSES
     */
    $rawContainsPostalAddress = $api->getContainsPostalAddress($element->uri,Constant::TOT_PER_PAGE,0);
    if ($rawContainsPostalAddress != NULL) {
      $containsPostalAddress = $api->parseObjectResponse($rawContainsPostalAddress,'getContainsPostalAddress');
      if ($containsPostalAddress != NULL) {
        $totalContainsPostalAddress = $api->parseTotalResponse($api->getTotalContainsPostalAddress($element->uri),'getTotalContainsPostalAddress');
        $form['postaladdress']['beginContains'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Contains Postal Addresses (total of " . $totalContainsPostalAddress . "):</b><ul>"),
        ];
        foreach ($containsPostalAddress as $propertyNameContainsPostalAddress => $propertyValueContainsPostalAddress) {
          $form['postaladdress'][$propertyNameContainsPostalAddress] = [
            '#type' => 'markup',
            '#markup' => $t->translate("<li>" . Utils::link($propertyValueContainsPostalAddress->label,$propertyValueContainsPostalAddress->uri) . "</li>"),
          ];
        }
        if ($totalContainsPostalAddress > Constant::TOT_PER_PAGE) {
          $link = ListPropertyPage::link($element,SCHEMA::HAS_ADDRESS,NULL,1,20);
          $form['postaladdress']['moreElements'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['postaladdress']['endContains'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }
    return $form;        
  }

    
}