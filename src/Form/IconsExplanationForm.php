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

    // Cabeçalho.
    $header = [
      ['data' => $this->t('Icon')],
      ['data' => $this->t('Name')],
      ['data' => $this->t('Explanation')],
    ];

    // Dados da tabela.
    $rows_data = [
      ['icon' => '🔷',     'name' => 'Class',    'desc' => 'Represents an ontology class node.'],
      ['icon' => '🧭',     'name' => 'Property', 'desc' => 'Represents a property/relation.'],
      ['icon' => '👤',     'name' => 'Instance', 'desc' => 'Represents an instance/individual.'],
      ['icon' => 'teste', 'name' => 'teste', 'desc' => 'test'],
    ];

    // Linhas.
    $rows = [];
    foreach ($rows_data as $r) {
      $rows[] = [
        ['data' => (string) $r['icon'], 'class' => ['kg-col-icon']],
        ['data' => (string) $r['name']],
        ['data' => (string) $r['desc']],
      ];
    }

    // Tabela.
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
