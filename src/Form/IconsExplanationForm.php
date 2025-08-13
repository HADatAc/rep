<?php

declare(strict_types=1);

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

final class IconsExplanationForm extends FormBase {

  public function getFormId(): string {
    return 'rep_icons_explanation_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {

    $form['#attached']['library'][] = 'rep/mtsearch_icons';

    $module_path = \Drupal::service('extension.list.module')->getPath('rep');
    $base_url = \Drupal::request()->getBaseUrl();
    $placeholder_base = $base_url . '/' . $module_path . '/images/placeholders/';

    $header = [
      ['data' => $this->t('Icon')],
      ['data' => $this->t('Type URI')],
      ['data' => $this->t('Name')],
      ['data' => $this->t('Explanation')],
    ];

    $map_img = [
      'Funding Schemes' => 'fundingschemes_placeholder.png',
      'Projects' => 'projects_placeholder.png',
      'Organizations' => 'organizations_placeholder.png',
      'Persons' => 'persons_placeholder.png',
      'Places' => 'places_placeholder.png',
      'Postal Adresses' => 'postaladresses_placeholder.png',
      'DAs' => 'da_placeholder.png',
      'Studies' => 'study_placeholder.png',
      'Study Roles' => 'studyrole_placeholder.png',
      'Virtual Columns' => 'virtualcolumns_placeholder.png',
      'Object Collections' => 'studyobjectcollection_placeholder.png',
      'Study Objects' => 'studyobject_placeholder.png',
      'Process Stems' => 'processstems_placeholder.png',
      'Processes' => 'processes_placeholder.png',
      'Data Dictionary' => 'datadictionary_placeholder.png',
      'Semantic Data Dictionary' => 'semanticdatadictionary_placeholder.png',
      'SV' => 'sv_placeholder.png',
      'Entity' => 'entity_placeholder.png',
      'Attribute' => 'attribute_placeholder.png',
      'Unit' => 'unit_placeholder.png',
      'Component' => 'component_placeholder.png',
      'Component Stem' => 'componentstem_placeholder.png',
      'Codebooks' => 'codebooks_placeholder.png',
      'Response Options' => 'respondeoptions_placeholder.png',
      'Annotation Stems' => 'annotationstems_placeholder.png',
      'Annotations' => 'annotations_placeholder.png',
      'Platform' => 'platform_placeholder.png',
      'Platform Instances' => 'platform_instance_placeholder.png',
      'Instrument' => 'instrument_placeholder.png',
      'Instrument Instances' => 'Instrument_instance_placeholder.png',
      'Detector Instances' => 'detector_instance_placeholder.png',
      'Actuator Instances' => 'actuator_instance_placeholder.png',
      'Deployments' => 'deployment_placeholder.png',
      'Message Streams' => 'message_stream_placeholder.png',
      'File Streams' => 'datafile_stream_placeholder.png',
      'INS' => 'ins_placeholder.png',
      'DSG' => 'dsg_placeholder.png',
      'DD'  => 'dd_placeholder.png',
      'SDD' => 'sdd_placeholder.png',
      'DP2' => 'dp2_placeholder.png',
      'STR' => 'str_placeholder.png',
    ];

    $map_uri = [
      'Funding Schemes' => '',
      'Projects' => 'https://schema.org/Project',
      'Organizations' => 'https://schema.org/GovernmentOrganization',
      'Persons' => '',
      'Places' => 'https://schema.org/City',
      'Postal Adresses' => 'https://schema.org/PostalAddress',
      'DAs' => '',
      'Studies' => 'http://hadatac.org/ont/hasco/Study',
      'Study Roles' => '',
      'Virtual Columns' => 'http://hadatac.org/ont/hasco/VirtualColumn',
      'Object Collections' => '',
      'Study Objects' => '',
      'Process Stems' => '',
      'Processes' => '',
      'Data Dictionary' => '',
      'Semantic Data Dictionary' => '',
      'SV' => '',
      'Entity' => '',
      'Attribute' => '',
      'Unit' => '',
      'Component' => '',
      'Component Stem' => '',
      'Codebooks' => '',
      'Response Options' => '',
      'Annotation Stems' => '',
      'Annotations' => '',
      'Platform' => '',
      'Platform Instances' => 'http://hadatac.org/ont/vstoi#Platform',
      'Instrument' => '',
      'Instrument Instances' => 'http://hadatac.org/ont/vstoi#Instrument',
      'Detector Instances' => 'http://hadatac.org/ont/vstoi#Detector',
      'Actuator Instances' => 'http://hadatac.org/ont/vstoi#Actuator',
      'Deployments' => 'http://hadatac.org/ont/vstoi#Deployment',
      'Message Streams' => '',
      'File Streams' => '',
      'INS' => '',
      'DSG' => 'http://hadatac.org/ont/hasco/DSG',
      'DD'  => '',
      'SDD' => '',
      'DP2' => '',
      'STR' => '',
    ];

    $rows_data = [
      ['name' => 'Funding Schemes', 'desc' => 'NOT FOUND'],
      ['name' => 'Projects', 'desc' => 'An enterprise (potentially individual but typically collaborative), planned to achieve a particular aim. Use properties from [[Organization]], [[subOrganization]]/[[parentOrganization]] to indicate project sub-structures.'],
      ['name' => 'Organizations', 'desc' => 'A governmental organization or agency.'],
      ['name' => 'Persons', 'desc' => 'NOT FOUND'],
      ['name' => 'Places', 'desc' => 'NOT FOUND'],
      ['name' => 'Postal Adresses', 'desc' => 'The mailing address.'],
      ['name' => 'DAs', 'desc' => 'NOT FOUND'],
      ['name' => 'Studies', 'desc' => 'NOT FOUND'],
      ['name' => 'Study Roles', 'desc' => 'NOT FOUND'],
      ['name' => 'Virtual Columns', 'desc' => 'NOT FOUND'],
      ['name' => 'Object Collections', 'desc' => 'NOT FOUND'],
      ['name' => 'Study Objects', 'desc' => 'NOT FOUND'],
      ['name' => 'Process Stems', 'desc' => 'NOT FOUND'],
      ['name' => 'Processes', 'desc' => 'NOT FOUND'],
      ['name' => 'Data Dictionary', 'desc' => 'NOT FOUND'],
      ['name' => 'Semantic Data Dictionary', 'desc' => 'NOT FOUND'],
      ['name' => 'SV', 'desc' => 'NOT FOUND'],
      ['name' => 'Entity', 'desc' => 'NOT FOUND'],
      ['name' => 'Attribute', 'desc' => 'NOT FOUND'],
      ['name' => 'Unit', 'desc' => 'NOT FOUND'],
      ['name' => 'Component', 'desc' => 'NOT FOUND'],
      ['name' => 'Component Stem', 'desc' => 'NOT FOUND'],
      ['name' => 'Codebooks', 'desc' => 'NOT FOUND'],
      ['name' => 'Response Options', 'desc' => 'NOT FOUND'],
      ['name' => 'Annotation Stems', 'desc' => 'NOT FOUND'],
      ['name' => 'Annotations', 'desc' => 'NOT FOUND'],
      ['name' => 'Platform', 'desc' => 'NOT FOUND'],
      ['name' => 'Platform Instances', 'desc' => 'A surface onto which instruments are deployed to collect data.'],
      ['name' => 'Instrument', 'desc' => 'A device or mechanism that is used to achire attribute values of entities of interest. An instrument does not necessarily require a way to store its measured quantity (e.g, a hard disk).'],
      ['name' => 'Instrument Instances', 'desc' => 'NOT FOUND'],
      ['name' => 'Detector Instances', 'desc' => 'A device which detects measurements, such as temperature or wind velocity, and cointains a codebook.'],
      ['name' => 'Actuator Instances', 'desc' => 'A device that puts into action values that are fed into it.'],
      ['name' => 'Deployments', 'desc' => 'A platform is deployed during a certain duration of time and over a certain spacial domain. The platform has instruments on it within the scope of this deployment. For example, a boat will carry certain instruments during a deployment, and those instruments will be removed once the deployment is completed. A stationary deployment can last a much longer time, even decades, with the same instrument.'],
      ['name' => 'Message Streams', 'desc' => 'NOT FOUND'],
      ['name' => 'File Streams', 'desc' => 'NOT FOUND'],
      ['name' => 'INS', 'desc' => 'NOT FOUND'],
      ['name' => 'DSG', 'desc' => 'NOT FOUND'],
      ['name' => 'DD',  'desc' => 'NOT FOUND'],
      ['name' => 'SDD', 'desc' => 'NOT FOUND'],
      ['name' => 'DP2', 'desc' => 'NOT FOUND'],
      ['name' => 'STR', 'desc' => 'NOT FOUND'],
    ];

    $rows = [];
    foreach ($rows_data as $r) {
      $img = $map_img[$r['name']] ?? null;
      $style = $img ? "background-image: url('{$placeholder_base}{$img}');" : '';

      $button = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '',
        '#attributes' => [
          'type' => 'button',
          'class' => ['element-icon-button', 'kg-col-icon'],
          'style' => $style,
          'title' => $this->t($r['name']),
          'aria-label' => $this->t($r['name']),
          'onclick' => 'return false;',
        ],
      ];

    $uri = $map_uri[$r['name']] ?? null;

if ($uri) {
  $label = $uri;

  $b64 = base64_encode($uri);

  $describe_path = '/rep/uri/';
  if (class_exists('\repGUI') && defined('\repGUI::DESCRIBE_PAGE')) {
    $describe_path = \repGUI::DESCRIBE_PAGE; 
    if ($describe_path[0] !== '/') { $describe_path = '/' . $describe_path; }
  }

  $url = \Drupal\Core\Url::fromUserInput($describe_path . $b64, [
    'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
  ]);

  $uriCell = \Drupal\Core\Link::fromTextAndUrl($label, $url)->toRenderable();
} else {
  $uriCell = ['#markup' => '—'];
}

      $rows[] = [
        ['data' => $button],               // Icon
        ['data' => $uriCell],              // Type URI (link)
        ['data' => (string) $r['name']],   // Name
        ['data' => (string) $r['desc']],   // Explanation
      ];
    }

    $form['icons_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows'  => $rows,
      '#empty' => $this->t('No icons to display.'),
      '#attributes' => [
        'class' => ['kg-icons-table'],
        'style' => 'max-width: 980px; margin: 0 auto;',
      ],
      '#responsive' => FALSE,
      '#sticky' => FALSE,
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}
}
