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
      'INS' => 'ins_placeholder.png',
      'DSG' => 'dsg_placeholder.png',
      'DD'  => 'dd_placeholder.png',
      'SDD' => 'sdd_placeholder.png',
      'DP2' => 'dp2_placeholder.png',
      'STR' => 'str_placeholder.png',
    ];

    $rows_data = [
      ['name' => 'DAs', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Studies', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Study Roles', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Virtual Columns', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Object Collections', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Study Objects', 'desc' => 'Represents a property/relation.'],
      ['name' => 'CHANGE LATER', 'desc' => 'Represents a property/relation.'],
      ['name' => 'CHANGE LATER', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Data Dictionary', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Semantic Data Dictionary', 'desc' => 'Represents a property/relation.'],
      ['name' => 'SV', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Entity', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Attribute', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Unit', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Platform', 'desc' => 'Represents a property/relation.'],
      ['name' => 'Platform Instances', 'desc' => 'Represents a property/relation.'],
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
