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
//use Drupal\rep\ListKeywordLanguagePage;
use Drupal\rep\ListKeywordPage;
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
            '#title' => '<br>This is an instance of the <a href="http://hadatac.org/software/hascoapp/">HAScO App</a> knowledge repository ' .
                'developed by <a href="http://hadatac.org/">HADatAc.org</a> community.<br>',
        ];

        $rep_status = '<font color="Red"><b>IS UNAVAILABLE</b></font>';
        if (\Drupal::moduleHandler()->moduleExists('rep')) {
            $rep_status = '<font color="Green"><b>IS AVAILABLE</b></font>';
        }

        $sir_status = '<span style="color: red; font-weight: bold; !important;">IS UNAVAILABLE</span>';
        if (\Drupal::moduleHandler()->moduleExists('sir')) {
            $sir_status = '<span style="color: green; font-weight: bold; !important;"><b>IS AVAILABLE</b></span>';
        }

        $dpl_status = '<span style="color: red; font-weight: bold; !important;">IS UNAVAILABLE</span>';
        if (\Drupal::moduleHandler()->moduleExists('dpl')) {
            $dpl_status = '<span style="color: green; font-weight: bold; !important;"><b>IS AVAILABLE</b></span>';
        }

        $sem_status = '<span style="color: red;"><b>IS UNAVAILABLE</b></span>';
        if (\Drupal::moduleHandler()->moduleExists('sem')) {
            $sem_status = '<span style="color: green; font-weight: bold; !important;"><b>IS AVAILABLE</b></span>';
        }

        $std_status = '<span style="color: red; font-weight: bold; !important;">IS UNAVAILABLE</span>';
        if (\Drupal::moduleHandler()->moduleExists('std')) {
            $std_status = '<span style="color: green; font-weight: bold; !important;"><b>IS AVAILABLE</b></span>';
        }

        $form['rep_content1'] = [
            '#type' => 'markup',
            '#markup' => 'The HASCO App is a <b>Semantic Repository</b> for handling Scientific Evidence in the form of RDF knowledge, including data. ' .
                'The repository is composed of a back-end API called HASCO and a Drupal-based front end based on a number of Drupal modules.<br><br>' .
                'These are the HASCO App components:<br><br>'.
                '<ul>' .
                '  <li>Back end component</li>' .
                '     <ul>' .
                '       <li>HASCOAPI - A comprehensive back-end solution and API for managing HASCO concepts in RDF.  HASCO is the HADatAc.org\'s Human-Aware Science Ontology.</li>' .
                '     </ul><br>' .
                '  <li>Front-end components</li>' .
                '     <ul>',
        ];

        $form['rep_status'] = [
            '#type' => 'item',
            '#title' => $this->t('<li>REP - A Drupal module responsible for connecting HASCO App front-end capabilities to the HASCO API. ' .
                                 'In this repository, REP module ' . $rep_status . '</li> '),
        ];
        $form['sir_status'] = [
            '#type' => 'item',
            '#title' => $this->t('<li>SIR - A Drupal module that provides front-end capabilities for managing instruments and questionnaires using the HASCOAPI. ' .
                                 'In this repository, SIR module ' . $sir_status . '</li> '),
        ];
        $form['dpl_status'] = [
            '#type' => 'item',
            '#title' => $this->t('<li>DPL - A Drupal module that provides front-end capabilities for managing deployment elements like deployments, platforms and streams using the HASCOAPI. ' .
                                 'In this repository, DPL module ' . $dpl_status . '</li> '),
        ];
        $form['sem_status'] = [
            '#type' => 'item',
            '#title' => $this->t('<li>SEM - A Drupal module that provides front-end capabilities for managing data dictionaries, codebooks and semantic variables using the HASCOAPI. ' .
                                 'In this repository, SEM module ' . $sem_status . '</li> '),
        ];
        $form['std_status'] = [
            '#type' => 'item',
            '#title' => $this->t('<li>STD - A Drupal module that provides front-end capabilities for managing scientific studies using the HASCOAPI. ' .
                                 'In this repository, STD module ' . $std_status . '</li> '),
        ];

        $form['rep_content2'] = [
            '#type' => 'markup',
            '#markup' => '     </ul>' .
                         '</ul>',
        ];


        $form['rep_newline1'] = [
            '#type' => 'item',
            '#title' => '<br><br>',
        ];
        $form['back'] = [
            '#type' => 'submit',
            '#value' => $this->t('Back'),
            '#name' => 'back',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'back-button'],
            ],
        ];
        $form['rep_newline2'] = [
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

      if ($button_name === 'back') {
        $url = Url::fromRoute('rep.home');
        $form_state->setRedirectUrl($url);
        return;
      }
    }

    public static function total($elementtype) {
        //return ListKeywordLanguagePage::total($elementtype, NULL, NULL);
        return ListKeywordPage::total($elementtype, NULL, NULL);
    }

}
