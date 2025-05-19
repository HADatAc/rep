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
            '#title' => '<br>This is a <a href="http://hadatac.org/software/hascorepo/">HAScO/Repo</a> instance ' .
                'developed by <a href="http://hadatac.org/">HADatAc.org</a> community.<br>',
        ];

        $form['rep_content1'] = [
            '#type' => 'item',
            '#title' => 'This repository currently hosts a knowledge graph containing the following kinds of <b>core elements</b>:<br>',
        ];

        $form['row1'] = array(
            '#type' => 'container',
            '#attributes' => array('class' => array('row')),
        );

        $form['totals_wrapper'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['card']],
            'body' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['card-body']],
            ],
          ];
          
          // Title row
          $form['totals_wrapper']['body']['titles'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['row', 'text-center']],
          ];
          
          $dimensions = [
            'Social<br>Dimension',
            'Sensing/Acting<br>Dimension',
            'Infrastructure<br>Dimension',
            'Science<br>Dimension',
            'Semantic<br>Dimension',
            'Data<br>Dimension',
          ];
          
          foreach ($dimensions as $i => $label) {
            $form['totals_wrapper']['body']['titles']["col_$i"] = [
              '#type' => 'markup',
              '#markup' => "<div class='col-md-2'><h4>$label</h4></div>",
            ];
          }
          
          // Data rows
          $rows = [
            // Row 1
            [
              ['project', 'Project(s)', null, 'fa-diagram-project'],
              ['instrument', 'Instrument(s)', null, 'fa-microchip'],
              ['instrumentinstance', 'Instrument instance(s)', null, 'fa-plug'],
              ['study', 'Study(ies)', null, 'fa-graduation-cap'],
              ['entity', 'Entity Type(s)', null, 'fa-cubes'],
              ['stream', 'Datafile Stream(s)', null, 'fa-database'],
            ],
            // Row 2
            [
              ['organization', 'Organization(s)', null, 'fa-building'],
              ['component', 'Component(s)', null, 'fa-puzzle-piece'],
              ['componentinstance', 'Component Instance(s)', null, 'fa-shapes'],
              ['objectcollection', 'Object Collection(s)', 'studyobjectcollection', 'fa-boxes-stacked'],
              ['variable', 'Variable(s)', null, 'fa-chart-line'],
              ['stream', 'Message Stream(s)', null, 'fa-envelope'],
            ],
            // Row 3
            [
              ['person', 'Person(s)', null, 'fa-user'],
              ['codebook', 'Codebook(s)', null, 'fa-book'],
              ['deployment', 'Deployment(s)', null, 'fa-rocket'],
              ['object', 'Object(s)', 'studyobject', 'fa-cube'],
              ['semanticvariable', 'Semantic Variable(s)', null, 'fa-language'],
              ['value', 'Value(s)', null, 'fa-equals'],
            ],
          ];
          
          // Icon styling
          $iconStyle = 'padding:30px; width:80px; height:80px; display:inline-flex; align-items:center; justify-content:center;';
          
          foreach ($rows as $row_index => $row) {
            $form['totals_wrapper']['body']["row_$row_index"] = [
              '#type' => 'container',
              '#attributes' => ['class' => ['row', 'text-center']],
            ];
          
            foreach ($row as $col_index => $item) {
              $key = $item[0];
              $label = $item[1];
              $total_key = $item[2] ?? $key;
              $icon_class = $item[3] ?? 'fa-database'; // Default icon if not specified
          
              $url = Utils::selectBackUrl($key)->toString();
              $count = About::total($total_key);
          
              $form['totals_wrapper']['body']["row_$row_index"]["col_{$col_index}"] = [
                '#type' => 'container',
                '#attributes' => ['class' => ['col-md-2', 'text-center']],
                'icon' => [
                  '#type' => 'html_tag',
                  '#tag' => 'i',
                  '#attributes' => [
                    'class' => [
                      'fa-button', 'fa-3x', 'fa-solid', $icon_class, 'view-active',
                    ],
                    'style' => $iconStyle,
                  ],
                ],
                'label' => [
                  '#type' => 'markup',
                  '#markup' => "<div><a href=\"$url\">$label</a><br><h3>$count</h3></div>",
                ],
              ];
            }
          }        
          
        $form['rep_full_list'] = [
            '#type' => 'item',
            '#title' => '<br>There is the <a href="/rep/full">full list of kinds of elements</a> in this knowledge graph.<br>',
        ];

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
