<?php

namespace Drupal\rep\Form\ValueSearch;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Sidebar filter form for values grouped by category.
 *
 * Groups:
 *  - Study
 *  - Attributes
 *  - Entities
 *  - Units
 *  - Time
 *
 * All groups start collapsed and will later be populated with API results.
 * A "Search" button is provided and is only valid if at least one checkbox
 * is selected across any of the groups.
 *
 * Visual goal:
 *  - The groups can be styled as an accordion using the CSS classes
 *    added on the wrapper and each group.
 */
class REPFacetedValueSearchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_faceted_value_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Study preferred name (from configuration).
    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';

    // Read current selections from query so they persist after redirect.
    $request = \Drupal::request();
    $query = $request->query;

    $selected_study = $query->all('study') ?? [];
    $selected_attributes = $query->all('attributes') ?? [];
    $selected_entities = $query->all('entities') ?? [];
    $selected_units = $query->all('units') ?? [];
    $selected_time = $query->all('time') ?? [];

    // Convert arrays like ['study_1', 'study_2'] into associative arrays
    // expected by checkboxes: ['study_1' => 'study_1', ...].
    $selected_study = !empty($selected_study) ? array_combine($selected_study, $selected_study) : [];
    $selected_attributes = !empty($selected_attributes) ? array_combine($selected_attributes, $selected_attributes) : [];
    $selected_entities = !empty($selected_entities) ? array_combine($selected_entities, $selected_entities) : [];
    $selected_units = !empty($selected_units) ? array_combine($selected_units, $selected_units) : [];
    $selected_time = !empty($selected_time) ? array_combine($selected_time, $selected_time) : [];

    // Wrapper for potential AJAX in the future.
    $form['#prefix'] = '<div id="rep-value-sidebar-wrapper">';
    $form['#suffix'] = '</div>';

    // General classes for styling as accordion.
    $form['#attributes']['class'][] = 'rep-value-sidebar-form';
    $form['#attributes']['class'][] = 'rep-facet-accordion';

    // ----------------------------------------------------------------------
    // Dummy options (replace later with real API data).
    // ----------------------------------------------------------------------
    $study_options = [
      'study_1' => $this->t('Example study 1'),
      'study_2' => $this->t('Example study 2'),
    ];

    $attribute_options = [
      'attr_1' => $this->t('Example attribute 1'),
      'attr_2' => $this->t('Example attribute 2'),
    ];

    $entity_options = [
      'entity_1' => $this->t('Example entity 1'),
      'entity_2' => $this->t('Example entity 2'),
    ];

    $unit_options = [
      'unit_1' => $this->t('Example unit 1'),
      'unit_2' => $this->t('Example unit 2'),
    ];

    $time_options = [
      'time_1' => $this->t('Example time 1'),
      'time_2' => $this->t('Example time 2'),
    ];

    // Counts for "Title (N)".
    $study_count = count($study_options);
    $attribute_count = count($attribute_options);
    $entity_count = count($entity_options);
    $unit_count = count($unit_options);
    $time_count = count($time_options);

    // ----------------------------------------------------------------------
    // STUDY
    // ----------------------------------------------------------------------
    $form['study'] = [
      '#type' => 'details',
      '#title' => $this->t('@label (@count)', [
        '@label' => ucfirst($preferred_study),
        '@count' => $study_count,
      ]),
      '#open' => FALSE,
      '#attributes' => [
        'class' => [
          'rep-filter-category',
          'rep-filter-category--study',
          'rep-facet-accordion-item',
        ],
      ],
    ];

    $form['study']['study_values'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('@label filters', [
        '@label' => ucfirst($preferred_study),
      ]),
      '#title_display' => 'invisible',
      '#options' => $study_options,
      '#default_value' => $selected_study,
      '#attributes' => [
        'class' => [
          'rep-filter-checkboxes',
          'rep-filter-checkboxes--study',
        ],
      ],
    ];

    // ----------------------------------------------------------------------
    // ATTRIBUTES
    // ----------------------------------------------------------------------
    $form['attributes'] = [
      '#type' => 'details',
      '#title' => $this->t('Attributes (@count)', [
        '@count' => $attribute_count,
      ]),
      '#open' => FALSE,
      '#attributes' => [
        'class' => [
          'rep-filter-category',
          'rep-filter-category--attributes',
          'rep-facet-accordion-item',
        ],
      ],
    ];

    $form['attributes']['attribute_values'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Attribute filters'),
      '#title_display' => 'invisible',
      '#options' => $attribute_options,
      '#default_value' => $selected_attributes,
      '#attributes' => [
        'class' => [
          'rep-filter-checkboxes',
          'rep-filter-checkboxes--attributes',
        ],
      ],
    ];

    // ----------------------------------------------------------------------
    // ENTITIES
    // ----------------------------------------------------------------------
    $form['entities'] = [
      '#type' => 'details',
      '#title' => $this->t('Entities (@count)', [
        '@count' => $entity_count,
      ]),
      '#open' => FALSE,
      '#attributes' => [
        'class' => [
          'rep-filter-category',
          'rep-filter-category--entities',
          'rep-facet-accordion-item',
        ],
      ],
    ];

    $form['entities']['entity_values'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Entity filters'),
      '#title_display' => 'invisible',
      '#options' => $entity_options,
      '#default_value' => $selected_entities,
      '#attributes' => [
        'class' => [
          'rep-filter-checkboxes',
          'rep-filter-checkboxes--entities',
        ],
      ],
    ];

    // ----------------------------------------------------------------------
    // UNITS
    // ----------------------------------------------------------------------
    $form['units'] = [
      '#type' => 'details',
      '#title' => $this->t('Units (@count)', [
        '@count' => $unit_count,
      ]),
      '#open' => FALSE,
      '#attributes' => [
        'class' => [
          'rep-filter-category',
          'rep-filter-category--units',
          'rep-facet-accordion-item',
        ],
      ],
    ];

    $form['units']['unit_values'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Unit filters'),
      '#title_display' => 'invisible',
      '#options' => $unit_options,
      '#default_value' => $selected_units,
      '#attributes' => [
        'class' => [
          'rep-filter-checkboxes',
          'rep-filter-checkboxes--units',
        ],
      ],
    ];

    // ----------------------------------------------------------------------
    // TIME
    // ----------------------------------------------------------------------
    $form['time'] = [
      '#type' => 'details',
      '#title' => $this->t('Time (@count)', [
        '@count' => $time_count,
      ]),
      '#open' => FALSE,
      '#attributes' => [
        'class' => [
          'rep-filter-category',
          'rep-filter-category--time',
          'rep-facet-accordion-item',
        ],
      ],
    ];

    $form['time']['time_values'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Time filters'),
      '#title_display' => 'invisible',
      '#options' => $time_options,
      '#default_value' => $selected_time,
      '#attributes' => [
        'class' => [
          'rep-filter-checkboxes',
          'rep-filter-checkboxes--time',
        ],
      ],
    ];

    // ----------------------------------------------------------------------
    // ACTIONS / SEARCH BUTTON
    // ----------------------------------------------------------------------
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['search'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'rep-faceted-search-button', 'mt-4', 'search-button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Validate that at least one checkbox is selected in ANY group.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $study_values = (array) $form_state->getValue('study_values');
    $attribute_values = (array) $form_state->getValue('attribute_values');
    $entity_values = (array) $form_state->getValue('entity_values');
    $unit_values = (array) $form_state->getValue('unit_values');
    $time_values = (array) $form_state->getValue('time_values');

    $study_selected = array_filter($study_values);
    $attribute_selected = array_filter($attribute_values);
    $entity_selected = array_filter($entity_values);
    $unit_selected = array_filter($unit_values);
    $time_selected = array_filter($time_values);

    $has_any_selection =
      !empty($study_selected) ||
      !empty($attribute_selected) ||
      !empty($entity_selected) ||
      !empty($unit_selected) ||
      !empty($time_selected);

    if (!$has_any_selection) {
      $form_state->setErrorByName(
        'search',
        $this->t('Please select at least one filter before running the search.')
      );
    }
  }

  /**
   * {@inheritdoc}
   *
   * Build query params and redirect to the results form (FacetedForm).
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $study_values = array_filter((array) $form_state->getValue('study_values'));
    $attribute_values = array_filter((array) $form_state->getValue('attribute_values'));
    $entity_values = array_filter((array) $form_state->getValue('entity_values'));
    $unit_values = array_filter((array) $form_state->getValue('unit_values'));
    $time_values = array_filter((array) $form_state->getValue('time_values'));

    $query = [
      'study' => array_keys($study_values),
      'attributes' => array_keys($attribute_values),
      'entities' => array_keys($entity_values),
      'units' => array_keys($unit_values),
      'time' => array_keys($time_values),
    ];

    $url = Url::fromRoute(
      'rep.faceted_results',
      [
        'page' => 1,
        'pagesize' => 20,
      ],
      [
        'query' => $query,
      ]
    );

    $form_state->setRedirectUrl($url);
  }

}
