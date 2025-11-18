/**
 * Bar chart for faceted value search results.
 *
 * Uses Chart.js (MIT license).
 */

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.repFacetedChart = {
    attach: function (context) {
      const cfg = drupalSettings.repFacetedChart || {};
      const labels = cfg.labels || [];
      const datasets = cfg.datasets || [];

      if (!labels.length || !datasets.length) {
        return;
      }

      const $canvas = $('#rep-faceted-chart', context);
      if (!$canvas.length) {
        return;
      }

      // Avoid double initialization (no once()).
      if ($canvas.data('repFacetedChartInit')) {
        return;
      }
      $canvas.data('repFacetedChartInit', true);

      const canvasEl = $canvas[0];
      const ctx = canvasEl.getContext('2d');

      if (typeof Chart === 'undefined') {
        console.error('repFacetedChart: Chart.js library is not loaded.');
        return;
      }

      const chartConfig = {
        type: 'bar',
        data: {
          labels: labels,
          datasets: datasets
        },
        options: {
          indexAxis: 'x', // vertical bars
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom'
            },
            title: {
              display: !!(cfg.options && cfg.options.title),
              text: (cfg.options && cfg.options.title) || ''
            },
            tooltip: {
              mode: 'nearest',
              intersect: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                precision: 0
              },
              title: {
                display: true,
                text: 'Values'
              }
            },
            x: {
              title: {
                display: true,
                text: 'Selected items'
              }
            }
          }
        }
      };

      // eslint-disable-next-line no-undef
      const chartInstance = new Chart(ctx, chartConfig);

      // Extra legend for facet groups (Study, Attribute, etc.)
      const legendGroups = cfg.legendGroups || [];
      if (legendGroups.length) {
        const $groupLegend = $('<div class="rep-faceted-legend-groups"></div>');

        legendGroups.forEach(function (group) {
          const $item = $('<div class="rep-legend-group-item"></div>');
          const $colorBox = $('<span class="rep-legend-color-box"></span>')
            .css('background-color', group.color);

          $item.append($colorBox).append(
            $('<span class="rep-legend-label"></span>').text(' ' + group.label)
          );
          $groupLegend.append($item);
        });

        $canvas.after($groupLegend);
      }
    }
  };
})(jQuery, Drupal, drupalSettings);
