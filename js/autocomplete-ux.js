(function (Drupal, $) {
  'use strict';

  const MSG_SEARCHING = 'Searching...';
  const MSG_NO_RESULTS = 'No results found.';
  const MSG_ERROR = 'Search error. Please try again.';
  const MSG_SUBMITTED = 'Submitted for review.';

  function canCreateSocial() {
    try {
      return !!(typeof drupalSettings !== 'undefined'
        && drupalSettings.repAutocompleteUx
        && drupalSettings.repAutocompleteUx.canCreateSocial);
    } catch (e) {
      return false;
    }
  }

  function isMakerOrOwnerField($input) {
    const hay = `${$input.attr('name') || ''} ${$input.attr('id') || ''} ${$input.attr('data-drupal-selector') || ''}`.toLowerCase();
    return /(^|[^a-z0-9])(maker|owner)([^a-z0-9]|$)/.test(hay);
  }

  function isSocialPersonOrOrganizationAutocomplete($input) {
    const path = String($input.attr('data-autocomplete-path') || '').toLowerCase();
    if (!path) {
      return false;
    }

    // Covers both:
    // - core Social routes: /social/autocomplete/{person|organization}
    // - REP social wrapper: /api/socialm/autocomplete/{person|organization}
    return (
      path.includes('/social/autocomplete/person') ||
      path.includes('/social/autocomplete/organization') ||
      path.includes('/api/socialm/autocomplete/person') ||
      path.includes('/api/socialm/autocomplete/organization')
    );
  }

  function escapeHtml(str) {
    return $('<div/>').text(str == null ? '' : String(str)).html();
  }

  function getWrapper($input) {
    const $wrapper = $input.closest('.js-form-item');
    return $wrapper.length ? $wrapper : $input.parent();
  }

  function getStatusElement($input) {
    let $status = $input.data('repAutocompleteStatusElement');
    if ($status && $status.length) {
      return $status;
    }

    const $wrapper = getWrapper($input);
    $status = $wrapper.find('.rep-autocomplete-status').first();

    if (!$status.length) {
      $status = $('<div class="rep-autocomplete-status description" aria-live="polite"></div>');
      $wrapper.append($status);
    }

    $input.data('repAutocompleteStatusElement', $status);
    return $status;
  }

  function setStatus($input, message, type) {
    const $status = getStatusElement($input);
    $status
      .removeClass('rep-autocomplete-status--error rep-autocomplete-status--info')
      .addClass(type === 'error' ? 'rep-autocomplete-status--error' : 'rep-autocomplete-status--info')
      .text(message)
      .show();
  }

  function setStatusHtml($input, html, type) {
    const $status = getStatusElement($input);
    $status
      .removeClass('rep-autocomplete-status--error rep-autocomplete-status--info')
      .addClass(type === 'error' ? 'rep-autocomplete-status--error' : 'rep-autocomplete-status--info')
      .html(html)
      .show();

    // Newly-inserted use-ajax links need behaviors attached.
    if (Drupal && typeof Drupal.attachBehaviors === 'function') {
      Drupal.attachBehaviors($status.get(0));
    }
  }

  function buildQuickCreateActionsHtml($input) {
    const inputId = $input.attr('id') || '';
    if (!inputId) {
      return escapeHtml(MSG_NO_RESULTS);
    }

    const term = $input.val() || '';
    const dialogOptions = JSON.stringify({ width: 700 });

    const personUrl = Drupal.url('rep/social/quick-add/person')
      + '?input_id=' + encodeURIComponent(inputId)
      + '&prefill=' + encodeURIComponent(term);

    const orgUrl = Drupal.url('rep/social/quick-add/organization')
      + '?input_id=' + encodeURIComponent(inputId)
      + '&prefill=' + encodeURIComponent(term);

    return (
      `<div class="rep-autocomplete-no-results">${escapeHtml(MSG_NO_RESULTS)}</div>`
      + `<div class="rep-autocomplete-actions" style="margin-top: 6px;">`
      + `  <a href="${personUrl}" class="use-ajax btn btn-sm btn-primary" data-dialog-type="modal" data-dialog-options='${dialogOptions}'>Create person</a>`
      + `  <a href="${orgUrl}" class="use-ajax btn btn-sm btn-primary" data-dialog-type="modal" data-dialog-options='${dialogOptions}' style="margin-left: 6px;">Create organization</a>`
      + `</div>`
      + `<div class="rep-autocomplete-help" style="margin-top: 6px;">Items are submitted as <b>Under Review</b> and will appear in autocomplete only after approval.</div>`
    );
  }

  function clearStatus($input) {
    const $status = getStatusElement($input);
    $status
      .removeClass('rep-autocomplete-status--error rep-autocomplete-status--info')
      .text('')
      .hide();
  }

  /**
   * Replacement source callback for Drupal core autocomplete.
   *
   * Adds explicit error feedback (HTTP/network errors) so the user can
   * distinguish "no results" from "search failed".
   */
  function repSourceData(request, response) {
    const $input = this.element;

    // Clear any previous error state for this new search.
    $input.data('repAutocompleteError', false);

    const elementId = $input.attr('id') || $input.attr('name') || '__rep_autocomplete__';

    if (!(elementId in Drupal.autocomplete.cache)) {
      Drupal.autocomplete.cache[elementId] = {};
    }

    // Get the desired term and construct the autocomplete URL for it.
    const term = Drupal.autocomplete.extractLastTerm(request.term);

    function showSuggestions(suggestions) {
      const tagged = Drupal.autocomplete.splitValues(request.term);
      const il = tagged.length;

      // Preserve core behavior; attempt to filter duplicates for both
      // string arrays and {value,label} arrays.
      for (let i = 0; i < il; i++) {
        const taggedValue = tagged[i];

        if (!Array.isArray(suggestions) || suggestions.length === 0) {
          break;
        }

        if (typeof suggestions[0] === 'string') {
          if (suggestions.includes(taggedValue)) {
            suggestions.splice(suggestions.indexOf(taggedValue), 1);
          }
        } else {
          const idx = suggestions.findIndex((s) => s && typeof s === 'object' && s.value === taggedValue);
          if (idx !== -1) {
            suggestions.splice(idx, 1);
          }
        }
      }

      response(suggestions);
    }

    function sourceCallbackHandler(data) {
      $input.data('repAutocompleteError', false);
      Drupal.autocomplete.cache[elementId][term] = data;
      showSuggestions(data);
    }

    // Check if the term is already cached.
    if (Object.prototype.hasOwnProperty.call(Drupal.autocomplete.cache[elementId], term)) {
      showSuggestions(Drupal.autocomplete.cache[elementId][term]);
    } else {
      const options = $.extend(
        {
          success: sourceCallbackHandler,
          error: function (jqXHR, textStatus) {
            // Avoid showing an error for request aborts.
            if (textStatus === 'abort') {
              return;
            }

            $input.data('repAutocompleteError', true);
            setStatus($input, MSG_ERROR, 'error');
            response([]);
          },
          data: { q: term },
        },
        Drupal.autocomplete.ajax
      );

      $.ajax($input.attr('data-autocomplete-path'), options);
    }
  }

  Drupal.behaviors.repAutocompleteUx = {
    attach: function (context) {
      // Global handler for modal success events.
      if (!Drupal.repAutocompleteUxSocialCreatedBound) {
        Drupal.repAutocompleteUxSocialCreatedBound = true;
        $(document).on('repSocialCreated.repAutocompleteUx', function (event, payload) {
          if (!payload || !payload.targetInputId) {
            return;
          }

          const el = document.getElementById(payload.targetInputId);
          if (!el) {
            return;
          }

          const $input = $(el);
          const label = payload.label ? escapeHtml(payload.label) : '';
          const badge = '<span class="badge bg-warning text-dark" style="margin-left: 6px;">' + escapeHtml(MSG_SUBMITTED) + '</span>';
          const msg = (label ? (label + ' ') : '') + badge;
          setStatusHtml($input, msg, 'info');
        });
      }

      $('input.form-autocomplete', context)
        .not('.repAutocompleteUx-processed')
        .addClass('repAutocompleteUx-processed')
        .each(function () {
          const $input = $(this);

          // Ensure status element exists (hidden by default).
          getStatusElement($input).hide();

          // Patch the jQuery UI widget so we can detect HTTP/network errors.
          const widget = $input.data('ui-autocomplete');
          if (widget && widget.options) {
            widget.options.source = repSourceData;
          }

          // UX feedback.
          $input.on('autocompletesearch.repAutocompleteUx', function () {
            $input.data('repAutocompleteError', false);
            setStatus($input, MSG_SEARCHING, 'info');
          });

          $input.on('autocompleteresponse.repAutocompleteUx', function (event, ui) {
            if ($input.data('repAutocompleteError') === true) {
              return;
            }

            const items = (ui && Array.isArray(ui.content)) ? ui.content : [];
            if (items.length === 0) {
              const eligible = isMakerOrOwnerField($input) || isSocialPersonOrOrganizationAutocomplete($input);
              if (eligible && canCreateSocial()) {
                setStatusHtml($input, buildQuickCreateActionsHtml($input), 'info');
              } else {
                setStatus($input, MSG_NO_RESULTS, 'info');
              }
            } else {
              clearStatus($input);
            }
          });

          $input.on('autocompleteopen.repAutocompleteUx', function () {
            if ($input.data('repAutocompleteError') !== true) {
              clearStatus($input);
            }
          });

          $input.on('autocompleteselect.repAutocompleteUx', function () {
            $input.data('repAutocompleteError', false);
            clearStatus($input);
          });

          $input.on('input.repAutocompleteUx', function () {
            if (!$input.val()) {
              $input.data('repAutocompleteError', false);
              clearStatus($input);
            }
          });
        });
    },
  };
})(Drupal, jQuery);
