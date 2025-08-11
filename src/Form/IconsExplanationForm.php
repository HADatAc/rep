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

    $header = [
      ['data' => $this->t('Icon')],
      ['data' => $this->t('Name')],
      ['data' => $this->t('Explanation')],
    ];

    $rows_data = [
      ['icon' => '🔷',     'name' => 'DAs',    'desc' => 'Represents an ontology class node.'],
      ['icon' => '🧭',     'name' => 'Studies', 'desc' => 'Represents a property/relation.'],
      ['icon' => '👤',     'name' => 'Study Roles', 'desc' => 'Represents an instance/individual.'],
      ['icon' => 'teste', 'name' => 'Virtual Columns', 'desc' => 'test'],
      ['icon' => '🧭',     'name' => 'Object Collections', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Study Objects', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'mudar', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'mudar', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'DD', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'SDD', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'SV', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Entity', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Attribute', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Unit', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Platform', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Platform Instances', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Instrument Instances', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Detector Instances', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Actuator Instances', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Deployments', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'Message Streams', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'File Streams', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'INS', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'DSG', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'DD', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'SDD', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'DP2', 'desc' => 'Represents a property/relation.'],
      ['icon' => '🧭',     'name' => 'STR', 'desc' => 'Represents a property/relation.'],
    ];

    $rows = [];
    foreach ($rows_data as $r) {
      $rows[] = [
        ['data' => (string) $r['icon'], 'class' => ['kg-col-icon']],
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
