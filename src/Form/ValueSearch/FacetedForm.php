<?php

namespace Drupal\rep\Form\ValueSearch;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Faceted value search results form.
 *
 * Responsibilities:
 *  - Read selected facet parameters from the querystring (coming from the
 *    sidebar form).
 *  - Only build a chart if there is at least ONE selected facet.
 *  - Build mock data (Value X & Value Y) for each selected item:
 *      - Studies
 *      - Attributes
 *      - Entities
 *      - Units
 *      - Time
 *  - Expose the data to the frontend via drupalSettings for Chart.js.
 *
 * Chart:
 *  - Type: bar (vertical bars, default indexAxis = 'x').
 *  - One label per selected item on the X axis.
 *  - Two datasets: "Value X" and "Value Y".
 *  - Bar colors depend on the facet group (Study, Attribute, etc.).
 */
class FacetedForm extends FormBase {

  /**
   * Current page number (1-based).
   *
   * @var int
   */
  protected $currentPage = 1;

  /**
   * Number of items per page.
   *
   * @var int
   */
  protected $pageSize = 20;

  /**
   * Total number of items returned by the API (for pagination).
   *
   * @var int
   */
  protected $totalItems = 0;

  /**
   * Selected facet values.
   *
   * @var array
   */
  protected $studyFilters = [];
  protected $attributeFilters = [];
  protected $entityFilters = [];
  protected $unitFilters = [];
  protected $timeFilters = [];

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_faceted_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $page = 1, $pagesize = 20) {

    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';

    // 1. Normalize pagination parameters.
    $this->currentPage = max(1, (int) $page);
    $this->pageSize = max(1, (int) $pagesize);

    // 2. Read facet parameters from the querystring.
    $request = \Drupal::request();
    $query = $request->query;

    $this->studyFilters     = (array) $query->all('study');
    $this->attributeFilters = (array) $query->all('attributes');
    $this->entityFilters    = (array) $query->all('entities');
    $this->unitFilters      = (array) $query->all('units');
    $this->timeFilters      = (array) $query->all('time');

    // 3. Determine if there is at least one selection.
    $has_any_selection =
      !empty($this->studyFilters) ||
      !empty($this->attributeFilters) ||
      !empty($this->entityFilters) ||
      !empty($this->unitFilters) ||
      !empty($this->timeFilters);

    // If there is no selection in the querystring, the user has not run
    // a search yet, so do NOT show any chart.
    if (!$has_any_selection) {
      // You can optionally show a placeholder message here instead of
      // returning an empty form.
      // Example:
      // $form['placeholder'] = [
      //   '#type' => 'item',
      //   '#markup' => '<p>' . $this->t('Run a search to see the charts.') . '</p>',
      // ];
      return $form;
    }

    // 4. Optional title for the chart area.
    // $form['page_title'] = [
    //   '#type' => 'item',
    //   '#markup' => '<h3 class="rep-faceted-results-title">' .
    //     $this->t('Faceted Value Search Results') .
    //     '</h3>',
    // ];

    // ------------------------------------------------------------------
    // 5. Prepare human-readable labels for each selected item.
    //    These are dummy labels, same as the sidebar. Replace with real
    //    data from your API or services later.
    // ------------------------------------------------------------------
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

    // ------------------------------------------------------------------
    // 6. Build chart data.
    //
    // Each selected item:
    //   - One label on the X axis.
    //   - Two values (X & Y).
    // Colors:
    //   - Each group (Study, Attribute, etc.) has its own color.
    //   - Value X = solid color, Value Y = lighter version of that color.
    // ------------------------------------------------------------------
    $labels   = [];
    $valuesX  = [];
    $valuesY  = [];
    $colorsX  = [];
    $colorsY  = [];

    $index = 0;

    // Helper function to get colors per group.
    $groupColors = function (string $groupKey): array {
      // Colors are hex/RGBA values. You can customize them freely.
      switch ($groupKey) {
        case 'study':
          return ['rgba(59, 130, 246, 0.9)', 'rgba(59, 130, 246, 0.4)'];   // Blue.
        case 'attribute':
          return ['rgba(249, 115, 22, 0.9)', 'rgba(249, 115, 22, 0.4)'];   // Orange.
        case 'entity':
          return ['rgba(34, 197, 94, 0.9)', 'rgba(34, 197, 94, 0.4)'];     // Green.
        case 'unit':
          return ['rgba(168, 85, 247, 0.9)', 'rgba(168, 85, 247, 0.4)'];   // Purple.
        case 'time':
          return ['rgba(107, 114, 128, 0.9)', 'rgba(107, 114, 128, 0.4)']; // Gray.
        default:
          return ['rgba(99, 102, 241, 0.9)', 'rgba(99, 102, 241, 0.4)'];   // Fallback.
      }
    };

    // Helper closure to add items from a group.
    $addItems = function (string $groupLabel, string $groupKey, array $filters, array $options)
      use (&$labels, &$valuesX, &$valuesY, &$colorsX, &$colorsY, &$index, $groupColors) {

      [$colorX, $colorY] = $groupColors($groupKey);

      foreach ($filters as $id) {
        if (!isset($options[$id])) {
          continue;
        }
        $index++;

        // Label example: "Study: Example study 1".
        $labels[]  = $groupLabel . ': ' . $options[$id];

        // Mock values (deterministic for demo / placeholder).
        $valuesX[] = 10 + $index * 2;
        $valuesY[] = 5 + $index * 3;

        // Custom colors for this specific bar.
        $colorsX[] = $colorX;
        $colorsY[] = $colorY;
      }
    };

    // Add items for all groups.
    $addItems((string) $this->t(ucfirst($preferred_study)),    'study',    $this->studyFilters,     $study_options);
    $addItems((string) $this->t('Attribute'),'attribute',$this->attributeFilters, $attribute_options);
    $addItems((string) $this->t('Entity'),   'entity',   $this->entityFilters,    $entity_options);
    $addItems((string) $this->t('Unit'),     'unit',     $this->unitFilters,      $unit_options);
    $addItems((string) $this->t('Time'),     'time',     $this->timeFilters,      $time_options);

    // Safety check (should not happen, because we already tested $has_any_selection,
    // but keeps the JS robust).
    if (empty($labels)) {
      $labels[]  = (string) $this->t('No selections');
      $valuesX[] = 0;
      $valuesY[] = 0;
      $colorsX[] = 'rgba(148, 163, 184, 0.9)'; // Neutral gray.
      $colorsY[] = 'rgba(148, 163, 184, 0.4)';
    }

    // Two datasets: X & Y values.
    $datasets = [
      [
        'label'           => (string) $this->t('Value X'),
        'data'            => $valuesX,
        'backgroundColor' => $colorsX, // array of colors (one per bar).
      ],
      [
        'label'           => (string) $this->t('Value Y'),
        'data'            => $valuesY,
        'backgroundColor' => $colorsY, // array of colors (one per bar).
      ],
    ];

    // 7. Attach Chart.js library and pass data to drupalSettings.
    $form['#attached']['library'][] = 'rep/faceted_bar_chart';

    $form['#attached']['drupalSettings']['repFacetedChart'] = [
      'labels'   => $labels,
      'datasets' => $datasets,
      'options'  => [
        'title' => (string) $this->t('Facet selections'),
      ],
    ];

    // 8. Graph container (canvas for Chart.js).
    $form['graph'] = [
      '#type' => 'container',
      '#attributes' => [
        'id'    => 'rep-faceted-graph-wrapper',
        'class' => ['rep-faceted-graph-wrapper'],
        // Wide horizontal area, vertical bars.
        'style' => 'min-height: 450px;',
      ],
      'canvas' => [
        '#type' => 'html_tag',
        '#tag' => 'canvas',
        '#attributes' => [
          'id' => 'rep-faceted-chart',
        ],
      ],
    ];

    // Hidden state for potential future AJAX/pager.
    $form['state'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['rep-faceted-hidden-state'],
        'style' => 'display:none;',
      ],
    ];
    $form['state']['current_page'] = [
      '#type' => 'hidden',
      '#value' => $this->currentPage,
    ];
    $form['state']['page_size'] = [
      '#type' => 'hidden',
      '#value' => $this->pageSize,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    //
  }
}
