<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class IconsExplanationForm extends FormBase {

  public function getFormId() {
    return 'rep_icons_explanation_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {

    // 1) HEADER ASSOCIATIVO (as chaves definem as colunas)
    $header = [
      'icon' => $this->t('Icon'),
      'name' => $this->t('Name'),
      'desc' => $this->t('Explanation'),
    ];

    // 2) Dados das linhas (livre para editares)
    $rowsData = [
      ['icon' => '🔷',   'name' => 'Class',    'desc' => 'Represents an ontology class node.'],
      ['icon' => '🧭',   'name' => 'Property', 'desc' => 'Represents a property/relation.'],
      ['icon' => '👤',   'name' => 'Instance', 'desc' => 'Represents an instance/individual.'],
      ['icon' => 'sbfakv','name' => 'Instance', 'desc' => 'Represents an instance/individual.'],
    ];

    // 3) Cada linha usa as MESMAS chaves do header
    $rows = [];
    foreach ($rowsData as $r) {
      $rows[] = [
        'icon' => ['data' => (string) $r['icon']],
        'name' => ['data' => (string) $r['name']],
        'desc' => ['data' => (string) $r['desc']],
      ];
    }

    $form['icons_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No icons to display.'),
      '#attributes' => ['class' => ['kg-icons-table']],
      '#sticky' => TRUE,
      // Evita que o JS "responsive table" reestruture as colunas após o load:
      '#responsive' => FALSE,
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {}
}
