<?php

/**
 * @file
 * Contains the settings for admninistering the rep Module
 */

namespace Drupal\rep\Form;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\URL;
use Drupal\rep\Utils;
use Drupal\rep\ListKeywordLanguagePage;
use Drupal\rep\Entity\Tables;

class LandingPage extends FormBase {

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "rep_landing_page";

    }


     /**
     * {@inheritdoc}
     */

     public function buildForm(array $form, FormStateInterface $form_state){

        $form['rep_home'] = [
            '#type' => 'item',
            '#title' => '<br>This is an instance of the <a href="http://hadatac.org/software/hascoapp/">HAScO App</a> knowledge repository ' .
                'developed by <a href="http://hadatac.org/">HADatAc.org</a> community.<br>',
        ];

        $form['rep_content1'] = [
            '#type' => 'item',
            '#title' => 'This repository currently hosts a knowledge graph about the following:<br>',
        ];

        $form['row1'] = array(
            '#type' => 'container',
            '#attributes' => array('class' => array('row')),
        );

        // TITLE ROW

        $totals = '<div class="row text-center">';

        $totals .= '<div class="col-md-2"><h4>Social<br>Dimension</h4></div>';
        
        $totals .= '<div class="col-md-2"><h4>Sensing/Acting<br>Dimension</h4></div>';

        $totals .= '<div class="col-md-2"><h4>Infrastructure<br>Dimension</h4></div>';

        $totals .= '<div class="col-md-2"><h4>Science<br>Dimension</h4></div>';

        $totals .= '<div class="col-md-2"><h4>Semantic<br>Dimension</h4></div>';

        $totals .= '<div class="col-md-2"><h4>Data<br>Dimension</h4></div>';

        $totals .= '</div>';
        
        // FIRST ROW

        $totals .= '<div class="row text-center">';

        $totals .= '<div class="col-md-2">' .
                         ' <a href="' . Utils::selectBackUrl('project')->toString() . '">Project(s)</a><br>' . 
                         About::total('project') . '</div>';
        
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('instrument')->toString() . '">Instrument(s)</a><br>' . 
                         About::total('instrument') . '</div>';
        
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('instrumentinstance')->toString() . '">Instrument instance(s)</a><br>' . 
                         About::total('instrumentinstance') . '</div>';
        
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('study')->toString() . '">Study(ies)</a><br>' . 
                         About::total('study') . '</div>';

        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('entity')->toString() . '">Entity Type(s)</a><br>' . 
                         About::total('entity') . '</div>';
                
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('stream')->toString() . '">Datafile Stream(s)</a><br>' . 
                         About::total('stream') . '</div>';
                
        $totals .= '</div>';
        

        // SECOND ROW

        $totals .= '<div class="row text-center">';

        $totals .= '<div class="col-md-2">' .
                         ' <a href="' . Utils::selectBackUrl('organization')->toString() . '">Organization(s)</a><br>' . 
                         About::total('organization') . '</div>';
        
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('person')->toString() . '">Component(s)</a><br>' . 
                         About::total('person') . '</div>';
        
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('componentinstance')->toString() . '">Component Instance(s)</a><br>' . 
                         About::total('componentinstance') . '</div>';
        
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('objectcollection')->toString() . '">Object Collection(s)</a><br>' . 
                         About::total('objectcollection') . '</div>';

        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('variable')->toString() . '">Variable(s)</a><br>' . 
                         About::total('variable') . '</div>';
                
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('stream')->toString() . '">Message Stream(s)</a><br>' . 
                         About::total('stream') . '</div>';
                
        $totals .= '</div>';
        
        // THIRD ROW

        $totals .= '<div class="row text-center">';

        $totals .= '<div class="col-md-2">' .
                         ' <a href="' . Utils::selectBackUrl('person')->toString() . '">Person(s)</a><br>' . 
                         About::total('person') . '</div>';
        
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('codebook')->toString() . '">Codebook(s)</a><br>' . 
                         About::total('codebook') . '</div>';
        
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('deployment')->toString() . '">Deployment(s)</a><br>' . 
                         About::total('deployment') . '</div>';
        
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('object')->toString() . '">Object(s)</a><br>' . 
                         About::total('object') . '</div>';

        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('semanticvariable')->toString() . '">Semantic Variable(s)</a><br>' . 
                         About::total('semanticvariable') . '</div>';
                
        $totals .= '<div class="col-md-2">' .  
                         ' <a href="' . Utils::selectBackUrl('value')->toString() . '">Value(s)</a><br>' . 
                         About::total('value') . '</div>';
                
        $totals .= '</div>';
        

        $form['row1']['column1'] = array(
            '#type' => 'container',
            //'#attributes' => array('class' => array('row')),
            'card' => array(
                '#type' => 'markup',
                '#markup' => '<div class="card"><div class="card-body">' . $totals . '</div></div>',
                //'<div class="card-footer text-center"><a href="(link)" class="btn btn-secondary">Manage</a></div></div>',
            ),
        );

        $form['rep_newline1'] = [
            '#type' => 'item',
            '#title' => '<br><br>',
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

      //if ($button_name === 'back') {
      //  $url = Url::fromRoute('rep.home');
      //  $form_state->setRedirectUrl($url);
      //  return;
      //}

    }

    public static function total($elementtype) {
        return ListKeywordLanguagePage::total($elementtype, NULL, NULL);
    }

}
