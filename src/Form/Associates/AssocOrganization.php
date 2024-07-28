<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Vocabulary\FOAF;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\ListPropertyPage;
use Drupal\rep\Constant;
use Drupal\rep\Utils;

class AssocOrganization {

  public static function process($element, array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $t = \Drupal::service('string_translation');
    
    /*
      *    ORGANIZATION's ORGANIZATIONS
      */
    $rawSubOrgs = $api->getSubOrganizations($element->uri,Constant::TOT_PER_PAGE,0);
    if ($rawSubOrgs != NULL) {
      $subOrgs = $api->parseObjectResponse($rawSubOrgs,'getSubOrganizations');
      if ($subOrgs != NULL) {
        $totalSubOrgs = $api->parseTotalResponse($api->getTotalSubOrganizations($element->uri),'getTotalSubOrganizations');
        $form['beginSubOrgs'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>SubOrganizations (total of " . $totalSubOrgs . "):</b><ul>"),
        ];
        foreach ($subOrgs as $propertyNameSubOrgs => $propertyValueSubOrgs) {
          $form[$propertyNameSubOrgs] = [
            '#type' => 'markup',
            '#markup' => $t->translate("<li>" . Utils::link($propertyValueSubOrgs->label,$propertyValueSubOrgs->uri) . " - " . $propertyValueSubOrgs->name . "</li>"),
          ];
        }
        if ($totalSubOrgs > Constant::TOT_PER_PAGE) {
          $link = ListPropertyPage::link($element,SCHEMA::SUB_ORGANIZATION,NULL,1,20);
          $form['moreElements'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['endSubOrgs'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }
    /*
      *    ORGANIZATION's PEOPLE
      */
    $rawAffiliations = $api->getAffiliations($element->uri,Constant::TOT_PER_PAGE,0);
    if ($rawAffiliations != NULL) {
      $affiliations = $api->parseObjectResponse($rawAffiliations,'getAffiliations');
      if ($affiliations != NULL) {
        $totalAffiliations = $api->parseTotalResponse($api->getTotalAffiliations($element->uri),'getTotalAffiliations');
        $form['beginAffiliations'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("<b>Affiliated People (total of " . $totalAffiliations . "):</b><ul>"),
        ];
        foreach ($affiliations as $propertyNameAffiliations => $propertyValueAffiliations) {
          $form[$propertyNameAffiliations] = [
            '#type' => 'markup',
            '#markup' => $t->translate("<li>" . Utils::link($propertyValueAffiliations->label,$propertyValueAffiliations->uri) . " - " . $propertyValueAffiliations->name . "</li>"),
          ];
        }
        if ($totalAffiliations > Constant::TOT_PER_PAGE) {
          $link = ListPropertyPage::link($element,FOAF::MEMBER,NULL,1,20);
          $form['moreElements'] = [
            '#type' => 'markup',
            '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                        'data-dialog-type="modal" '.
                        'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
          ];
        }
        $form['endAffiliations'] = [
          '#type' => 'markup',
          '#markup' => $t->translate("</ul><br>"),
        ];
      }
    }
    /*
     *    ORGANIZATION's POSTAL ADDRESSES
     */
    $rawContainsPostalAddress = $api->getContainsPostalAddress($element->hasAddress->hasAddressLocalityUri,Constant::TOT_PER_PAGE,0);
    if ($rawContainsPostalAddress != NULL) {
      $containsPostalAddress = $api->parseObjectResponse($rawContainsPostalAddress,'getContainsPostalAddress');
      if ($containsPostalAddress != NULL) {
        $totalContainsPostalAddress = $api->parseTotalResponse($api->getTotalContainsPostalAddress($element->hasAddress->hasAddressLocalityUri),'getTotalContainsPostalAddress');
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