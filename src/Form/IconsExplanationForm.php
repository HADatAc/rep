<?php

declare(strict_types=1);

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

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

    $rows_data = [
      ['name' => 'Funding Schemes', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Projects', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Organizations', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Persons', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Places', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Postal Adresses', 'desc' => 'Represents a property/relation.'],
      ['name' => 'DAs', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Studies', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Study Roles', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Virtual Columns', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Object Collections', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Study Objects', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Process Stems', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Processes', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Data Dictionary', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Semantic Data Dictionary', 'desc' => 'Represents a property/relation.'],
      ['name' => 'SV', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Entity', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Attribute', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Unit', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Component', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Component Stem', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Codebooks', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Response Options', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Annotation Stems', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Annotations', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Platform', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Platform Instances', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Instrument', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Instrument Instances', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Detector Instances', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Actuator Instances', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Deployments', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Message Streams', 'desc' => 'Represents a property/relation.'],
      ['name' => 'File Streams', 'desc' => 'Represents a property/relation.'],
      ['name' => 'INS', 'desc' => 'Represents a property/relation.'],
      ['name' => 'DSG', 'desc' => 'Represents a property/relation.'],
      ['name' => 'DD',  'desc' => 'Represents a property/relation.'],
      ['name' => 'SDD', 'desc' => 'Represents a property/relation.'],
      ['name' => 'DP2', 'desc' => 'Represents a property/relation.'],
      ['name' => 'STR', 'desc' => 'Represents a property/relation.'],
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
    '#attached' => [],
  ];

  $rows[] = [
    ['data' => $button],
    ['data' => (string) $r['name']],
    ['data' => (string) $r['desc']],
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
