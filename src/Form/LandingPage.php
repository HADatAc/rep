<?php

/**
 * @file
 * Contains the settings for administering the rep Module
 */

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\URL;
use Drupal\rep\Utils;
use Drupal\rep\ListKeywordLanguagePage;
use Drupal\rep\Entity\Tables;

class LandingPage extends FormBase
{

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return "rep_landing_page";
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    // Title
    $form['dashboard_title'] = [
      '#type' => 'markup',
      '#markup' => '<h1 class="mt-5 mb-3">Knowledge Graph Dashboard</h1>',
      '#attributes' => [
        'class' => ['mt-5', 'mb-3']
      ],
    ];

    // Introduction text
    $form['rep_home'] = [
      '#type' => 'item',
      '#title' => '<br>This is an instance of the <a href="http://hadatac.org/software/hascoapp/">HAScO App</a> knowledge repository ' .
        'developed by <a href="http://hadatac.org/">HADatAc.org</a> community.<br>',
    ];

    $form['rep_content1'] = [
      '#type' => 'item',
      '#title' => 'This repository currently hosts a knowledge graph about the following:<br>',
    ];

    // First row with 5 columns, responsive to stack vertically on small screens
    $form['row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row']],
    ];

    // Social/Organizational Elements Column
    $form['row']['social_organizational_elements'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-12', 'col-md-3', 'p-2']],
      'card' => [
        '#type' => 'markup',
        '#markup' => '<div class="card mb-3"><div class="card-body">' . $this->getSocialOrganizationalElements() . '</div></div>',
      ],
    ];

    // Study Elements Column
    $form['row']['study_elements'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-12', 'col-md-2', 'p-2']],
      'card' => [
        '#type' => 'markup',
        '#markup' => '<div class="card mb-3"><div class="card-body">' . $this->getStudyElements() . '</div></div>',
      ],
    ];

    // Deployment Elements Column
    $form['row']['deployment_elements'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-12', 'col-md-2', 'p-2']],
      'card' => [
        '#type' => 'markup',
        '#markup' => '<div class="card mb-3"><div class="card-body">' . $this->getDeploymentElements() . '</div></div>',
      ],
    ];

    // Instrument Elements Column
    $form['row']['instrument_elements'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-12', 'col-md-2', 'p-2']],
      'card' => [
        '#type' => 'markup',
        '#markup' => '<div class="card mb-3"><div class="card-body">' . $this->getInstrumentElements() . '</div></div>',
      ],
    ];

    // Data Elements Column
    $form['row']['data_elements'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-12', 'col-md-3', 'p-2']],
      'card' => [
        '#type' => 'markup',
        '#markup' => '<div class="card mb-3"><div class="card-body">' . $this->getDataElements() . '</div></div>',
      ],
    ];

    // Second row with 2 columns (50%-50%) using flex
    $form['second_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'mt-4']],
    ];

    // HASCO Cycle Image Column (responsive image)
    $form['second_row']['hasco_cycle_image'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-12', 'col-md-6', 'p-2']],
      'card' => [
        '#type' => 'markup',
        '#markup' => '<img src="' . $this->getImagePath() . '" alt="HASCO Cycle" class="img-fluid" border="0" />',
      ],
    ];

    // Ontologies Column with responsive table
    $form['second_row']['ontologies'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-12', 'col-md-6', 'p-2']],
      'card' => [
        '#type' => 'markup',
        '#markup' => '<div class="card"><div class="card-body">' . $this->getOntologies() . '</div></div>',
      ],
    ];

    return $form;
  }

  // Helper function to render Social/Organizational Elements
  private function getSocialOrganizationalElements()
  {
    $totalsSocial = '<h3>Social/Organizational Elements</h3>';
    $totalsSocial .= '<ul>';
    $totalsSocial .= '<li>' . About::total('place') . ' <a href="' . Utils::selectBackUrl('place')->toString() . '">place(s)</a></li>';
    $totalsSocial .= '<li>' . About::total('organization') . ' <a href="' . Utils::selectBackUrl('organization')->toString() . '">organization(s)</a></li>';
    $totalsSocial .= '<li>' . About::total('person') . ' <a href="' . Utils::selectBackUrl('person')->toString() . '">person(s)</a></li>';
    $totalsSocial .= '<li>' . About::total('postaladdress') . ' <a href="' . Utils::selectBackUrl('postaladdress')->toString() . '">postaladdress(es)</a></li>';
    $totalsSocial .= '</ul>';
    return $totalsSocial;
  }

  // Helper function to render Study Elements
  private function getStudyElements()
  {
    $totalsStudy = '<h3>Study Elements</h3><ul>';
    $totalsStudy .= '<li>' . About::total('dsg') . ' <a href="' . Utils::selectBackUrl('dsg')->toString() . '">DSG(s)</a> (MT)</li>';
    $totalsStudy .= '<li>' . About::total('dd') . ' <a href="' . Utils::selectBackUrl('dd')->toString() . '">DD(s)</a> (MT)</li>';
    $totalsStudy .= '<li>' . About::total('sdd') . ' <a href="' . Utils::selectBackUrl('sdd')->toString() . '">SDD(s)</a> (MT)</li>';
    $totalsStudy .= '<li>' . About::total('study') . ' <a href="' . Utils::selectBackUrl('study')->toString() . '">study(ies)</a></li>';
    $totalsStudy .= '<li>' . About::total('studyrole') . ' <a href="' . Utils::selectBackUrl('studyrole')->toString() . '">studyrole(s)</a></li>';
    $totalsStudy .= '<li>' . About::total('virtualcolumn') . ' <a href="' . Utils::selectBackUrl('virtualcolumn')->toString() . '">virtualcolumn(s)</a></li>';
    $totalsStudy .= '<li>' . About::total('studyobjectcollection') . ' <a href="' . Utils::selectBackUrl('studyobjectcollection')->toString() . '">studyobjectcollection(s)</a></li>';
    $totalsStudy .= '<li>' . About::total('studyobject') . ' <a href="' . Utils::selectBackUrl('studyobject')->toString() . '">studyobject(s)</a></li>';
    $totalsStudy .= '</ul>';
    return $totalsStudy;
  }

  // Helper function to render Deployment Elements
  private function getDeploymentElements()
  {
    $totalsDeploy = '<h3>Deployment Elements</h3><ul>';
    $totalsDeploy .= '<li>' . About::total('dp2') . ' <a href="' . Utils::selectBackUrl('dp2')->toString() . '">DP2(s)</a> (MT)</li>';
    $totalsDeploy .= '<li>' . About::total('str') . ' <a href="' . Utils::selectBackUrl('str')->toString() . '">STR(s)</a> (MT)</li>';
    $totalsDeploy .= '<li>' . About::total('platform') . ' <a href="' . Utils::selectBackUrl('platform')->toString() . '">platform(s)</a></li>';
    $totalsDeploy .= '<li>' . About::total('platforminstance') . ' <a href="' . Utils::selectBackUrl('platforminstance')->toString() . '">platform instance(s)</a></li>';
    $totalsDeploy .= '<li>' . About::total('instrumentinstance') . ' <a href="' . Utils::selectBackUrl('instrumentinstance')->toString() . '">instrument instance(s)</a></li>';
    $totalsDeploy .= '<li>' . About::total('detectorinstance') . ' <a href="' . Utils::selectBackUrl('detectorinstance')->toString() . '">detector instance(s)</a></li>';
    $totalsDeploy .= '<li>' . About::total('deployment') . ' <a href="' . Utils::selectBackUrl('deployment')->toString() . '">deployment(s)</a></li>';
    $totalsDeploy .= '<li>' . About::total('stream') . ' <a href="' . Utils::selectBackUrl('stream')->toString() . '">stream(s)</a></li>';
    $totalsDeploy .= '</ul>';
    return $totalsDeploy;
  }

  // Helper function to render Instrument Elements
  private function getInstrumentElements()
  {
    $totalsInst = '<h3>Instrument Elements</h3><ul>';
    $totalsInst .= '<li>' . About::total('ins') . ' <a href="' . Utils::selectBackUrl('ins')->toString() . '">INS(s)</a> (MT)</li>';
    $totalsInst .= '<li>' . About::total('instrument') . ' <a href="' . Utils::selectBackUrl('instrument')->toString() . '">instrument(s)</a></li>';
    $totalsInst .= '<li>' . About::total('detectorstem') . ' <a href="' . Utils::selectBackUrl('detectorstem')->toString() . '">detector stem(s)</a></li>';
    $totalsInst .= '<li>' . About::total('detector') . ' <a href="' . Utils::selectBackUrl('detector')->toString() . '">detector(s)</a></li>';
    $totalsInst .= '<li>' . About::total('codebook') . ' <a href="' . Utils::selectBackUrl('codebook')->toString() . '">codebook(s)</a></li>';
    $totalsInst .= '<li>' . About::total('responseoption') . ' <a href="' . Utils::selectBackUrl('responseoption')->toString() . '">response option(s)</a></li>';
    $totalsInst .= '<li>' . About::total('annotationstem') . ' <a href="' . Utils::selectBackUrl('annotationstem')->toString() . '">annotation stem(s)</a></li>';
    $totalsInst .= '<li>' . About::total('annotation') . ' <a href="' . Utils::selectBackUrl('annotation')->toString() . '">annotation(s)</a></li>';
    $totalsInst .= '</ul>';
    return $totalsInst;
  }

  // Helper function to render Data Elements
  private function getDataElements()
  {
    $totalsData = '<h3>Data Elements</h3>';
    $totalsData .= '<h5>Data Content</h5><ul>';
    $totalsData .= '<li>' . About::total('da') . ' <a href="' . Utils::selectBackUrl('da')->toString() . '">dataset\'s data file(s)</a></li>';
    $totalsData .= '<li>' . About::total('value') . ' <a href="' . Utils::selectBackUrl('value')->toString() . '">data value(s)</a></li>';
    $totalsData .= '</ul><h5>Data Semantics</h5><ul>';
    $totalsData .= '<li>' . About::total('sdd') . ' <a href="' . Utils::selectBackUrl('sdd')->toString() . '">semantic data dictionary(ies)</a> (MT)</li>';
    $totalsData .= '<li>' . About::total('semanticvariable') . ' <a href="' . Utils::selectBackUrl('semanticvariable')->toString() . '">semantic variable(s)</a></li>';
    $totalsData .= '<li>' . About::total('entity') . ' <a href="' . Utils::selectBackUrl('entity')->toString() . '">entity(ies)</a></li>';
    $totalsData .= '<li>' . About::total('attribute') . ' <a href="' . Utils::selectBackUrl('attribute')->toString() . '">attribute(s)</a></li>';
    $totalsData .= '<li>' . About::total('unit') . ' <a href="' . Utils::selectBackUrl('unit')->toString() . '">unit(s)</a></li>';
    $totalsData .= '</ul>';
    return $totalsData;
  }

  // Helper function to retrieve the image path
  private function getImagePath()
  {
    $module_handler = \Drupal::service('module_handler');
    $module_path = "";
    if ($module_handler->moduleExists('rep')) {
      $module_path = $module_handler->getModule('rep')->getPath();
    }
    return './' . $module_path . '/images/hasco_cycle.png';
  }

  // Helper function to render Ontologies content (responsive table)
  private function getOntologies()
  {
    $ontologies = '<h3>Ontologies</h3>';
    // Limitar largura da tabela e garantir rolagem horizontal quando necessário
    $ontologies .= '<div class="table-responsive" style="max-width: 100%;">';
    $ontologies .= '<table class="table table-striped table-bordered" style="table-layout: fixed; width: 100%;">';
    $ontologies .= '<thead><tr><th style="width: 30%;">Abbreviation</th><th style="width: 70%;">Namespace</th></tr></thead><tbody>';

    $tables = new Tables;
    $namespaces = $tables->getNamespaces();

    if ($namespaces != NULL) {
      foreach ($namespaces as $abbrev => $ns) {
        $ontologies .= '<tr>';
        $ontologies .= '<td style="word-wrap: break-word;">' . $abbrev . '</td>';
        $ontologies .= '<td style="word-wrap: break-word;"><a href="' . $ns . '">' . $ns . '</a></td>';
        $ontologies .= '</tr>';
      }
    } else {
      $ontologies .= '<tr><td colspan="3">No NAMESPACE information available at the moment</td></tr>';
    }

    $ontologies .= '</tbody></table>';
    $ontologies .= '</div>';  // End of container

    return $ontologies;
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    //if ($button_name === 'back') {
    //  $url = Url::fromRoute('rep.home');
    //  $form_state->setRedirectUrl($url);
    //  return;
    //}

  }

  public static function total($elementtype)
  {
    return ListKeywordLanguagePage::total($elementtype, NULL, NULL);
  }
}
