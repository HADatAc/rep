<?php

/**
 * @file
 * Contains the settings for admninistering the rep Module
 */

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\URL;
use Drupal\rep\Utils;
use Drupal\rep\ListKeywordLanguagePage;
use Drupal\rep\Entity\Tables;

class About extends FormBase {

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "rep_about";
        
    }


     /**
     * {@inheritdoc}
     */

     public function buildForm(array $form, FormStateInterface $form_state){
        
        $form['rep_home'] = [
            '#type' => 'item',
            '#title' => '<h2>About this website</h2>' . 
                'This is an instance of the <a href="http://hadatac.org/rep/">Semantic Instrument Repository (rep)</a> environment ' . 
                'developed by <a href="http://hadatac.org/">HADatAc.org</a> community.<br>',
        ];

        $form['rep_content1'] = [
            '#type' => 'item',
            '#title' => 'This repository currently hosts a knowledge graph about the following:<br>',
        ];

        $totals = '<h3>Instrument Elements</h3>';
        $totals .= '<ul>';
        $totals .=  '<li> ' . About::total('instrument') . ' <a href="'.Utils::selectBackUrl('instrument')->toString().'">instrument(s)</a></li>'; 
        $totals .=  '<li> ' . About::total('detectorstem') . ' <a href="'.Utils::selectBackUrl('detectorstem')->toString().'">detector stem(s)</a></li>';
        $totals .=  '<li> ' . About::total('detector') . ' <a href="'.Utils::selectBackUrl('detector')->toString().'">detector(s)</a></li>';
        $totals .=  '<li> ' . About::total('codebook') . ' <a href="'.Utils::selectBackUrl('codebook')->toString().'">codebook(s)</a></li>';
        $totals .=  '<li> ' . About::total('responseoption') . ' <a href="'.Utils::selectBackUrl('responseoption')->toString().'">response option(s)</a></li>';
        $totals .= '</ul>';
        $totals .= '<h3>Semantic Elements</h3>';
        $totals .= '<ul>';
        $totals .=  '<li> ' . About::total('semanticvariable') . ' <a href="'.Utils::selectBackUrl('semanticvariable')->toString().'">semantic variable(s)</a></li>'; 
        $totals .=  '<li> ' . About::total('entity') . ' <a href="'.Utils::selectBackUrl('entity')->toString().'">entity(ies)</a></li>';
        $totals .=  '<li> ' . About::total('attribute') . ' <a href="'.Utils::selectBackUrl('attribute')->toString().'">attribute(s)</a></li>';
        $totals .=  '<li> ' . About::total('unit') . ' <a href="'.Utils::selectBackUrl('unit')->toString().'">unit(s)</a></li>';
        $totals .= '</ul>';
        $totals .= '<h3>Study Elements</h3>';
        $totals .= '<ul>';
        $totals .=  '<li> ' . About::total('study') . ' <a href="'.Utils::selectBackUrl('study')->toString().'">study(ies)</a></li>'; 
        $totals .=  '<li> ' . About::total('studyrole') . ' <a href="'.Utils::selectBackUrl('studyrole')->toString().'">studyrole(s)</a></li>';
        $totals .=  '<li> ' . About::total('studyobjectcollection') . ' <a href="'.Utils::selectBackUrl('studyobjectcollection')->toString().'">studyobjectcollection(s)</a></li>'; 
        $totals .=  '<li> ' . About::total('studyobject') . ' <a href="'.Utils::selectBackUrl('studyobject')->toString().'">studyobject(s)</a></li>';
        $totals .= '</ul>';
        $totals .= '<h3>Social/Organizational Elements</h3>';
        $totals .= '<ul>';
        $totals .=  '<li> ' . About::total('place') . ' <a href="'.Utils::selectBackUrl('place')->toString().'">place(s)</a></li>';
        $totals .=  '<li> ' . About::total('organization') . ' <a href="'.Utils::selectBackUrl('organization')->toString().'">organization(s)</a></li>';
        $totals .=  '<li> ' . About::total('person') . ' <a href="'.Utils::selectBackUrl('person')->toString().'">person(s)</a></li>';
        $totals .=  '<li> ' . About::total('postaladdress') . ' <a href="'.Utils::selectBackUrl('postaladdress')->toString().'">postaladdress(es)</a></li>';
        $totals .= '</ul>';
        $form['rep_content_totals'] = [
            '#type' => 'item',
            '#title' => $totals,
        ];
        $form['rep_content2'] = [
            '#type' => 'item',
            '#title' => 'In this instance, the knowledge graph is based on content coming from the following ontologies:<br>',
        ];

 
        $ontologies = '<ul>';
        $tables = new Tables;
        $namespaces = $tables->getNamespaces();
        if ($namespaces != NULL) {
          foreach ($namespaces as $abbrev => $ns) {
             $ontologies .= '<li><a href="'. $ns .'">'. $ns . '</a> ('. $abbrev . ')</li>';
          }
        } else {
            $ontologies .= '<li>No NAMESPACE information available at the moment</li>';
        }
        $ontologies .= '</ul>';
        $form['rep_ontologies_totals'] = [
            '#type' => 'item',
            '#title' => $ontologies,
        ];
        $form['rep_newline1'] = [
            '#type' => 'item',
            '#title' => '<br><br>',
        ];
        $form['back'] = [
            '#type' => 'submit',
            '#value' => $this->t('Back'),
            '#name' => 'back',
        ];
        $form['rep_newline2'] = [
            '#type' => 'item',
            '#title' => '<br><br><br>',
        ];
      
        return $form;

     }
     
    /**
    * {@inheritdoc}
    */
    public function submitForm(array &$form, FormStateInterface $form_state) {
      $submitted_values = $form_state->cleanValues()->getValues();
      $triggering_element = $form_state->getTriggeringElement();
      $button_name = $triggering_element['#name'];

      if ($button_name === 'back') {
        $url = Url::fromRoute('rep.about');
        $form_state->setRedirectUrl($url);
        return;
      } 

    }

    public static function total($elementtype) {
        return ListKeywordLanguagePage::total($elementtype, NULL, NULL);
    }

}