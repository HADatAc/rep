(function (Drupal, once) {
  'use strict';

  function normalizeText(value) {
    return String(value || '')
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  function getRowLabel(row) {
    if (!row || !row.querySelectorAll) {
      return '';
    }

    var cells = row.querySelectorAll('td');
    if (!cells || cells.length < 2) {
      return normalizeText(row.textContent || '');
    }

    return normalizeText(cells[1].textContent || '');
  }

  function createButton(label, disabled, onClick) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-default btn-xs';
    button.textContent = label;
    button.disabled = Boolean(disabled);
    button.addEventListener('click', onClick);
    return button;
  }

  function initSection(section) {
    var table = section.querySelector('[data-rep-instance-table]');
    if (!table || !table.tBodies || !table.tBodies.length) {
      return;
    }

    var tbody = table.tBodies[0];
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    if (!rows.length) {
      return;
    }

    var pageSize = parseInt(table.getAttribute('data-rep-page-size') || '5', 10);
    if (!Number.isFinite(pageSize) || pageSize < 1) {
      pageSize = 5;
    }

    var toggle = section.querySelector('[data-rep-instance-search-toggle]');
    var searchWrap = section.querySelector('[data-rep-instance-search-wrap]');
    var searchInput = section.querySelector('[data-rep-instance-search-input]');
    var pagination = section.querySelector('[data-rep-instance-pagination]');

    var state = {
      query: '',
      page: 1,
    };

    function getFilteredRows() {
      if (!state.query) {
        return rows;
      }

      return rows.filter(function (row) {
        return getRowLabel(row).indexOf(state.query) !== -1;
      });
    }

    function renderPagination(filteredRows, totalPages) {
      if (!pagination) {
        return;
      }

      pagination.innerHTML = '';

      if (!filteredRows.length) {
        pagination.style.display = 'flex';
        var noMatch = document.createElement('span');
        noMatch.className = 'rep-instance-no-match';
        noMatch.textContent = Drupal.t('No matching labels found.');
        pagination.appendChild(noMatch);
        return;
      }

      if (filteredRows.length <= pageSize) {
        pagination.style.display = 'none';
        return;
      }

      pagination.style.display = 'flex';

      var prev = createButton(Drupal.t('Prev'), state.page <= 1, function () {
        if (state.page > 1) {
          state.page -= 1;
          render();
        }
      });

      var next = createButton(Drupal.t('Next'), state.page >= totalPages, function () {
        if (state.page < totalPages) {
          state.page += 1;
          render();
        }
      });

      var info = document.createElement('span');
      info.className = 'rep-instance-pagination-info';
      info.textContent = Drupal.t('Page @current of @total', {
        '@current': String(state.page),
        '@total': String(totalPages),
      });

      pagination.appendChild(prev);
      pagination.appendChild(info);
      pagination.appendChild(next);
    }

    function render() {
      var filteredRows = getFilteredRows();
      var totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));

      if (state.page > totalPages) {
        state.page = totalPages;
      }
      if (state.page < 1) {
        state.page = 1;
      }

      var start = (state.page - 1) * pageSize;
      var end = start + pageSize;
      var visibleRows = filteredRows.slice(start, end);
      var visibleSet = new Set(visibleRows);

      rows.forEach(function (row) {
        row.style.display = visibleSet.has(row) ? '' : 'none';
      });

      renderPagination(filteredRows, totalPages);
    }

    if (toggle && searchWrap && searchInput) {
      toggle.addEventListener('click', function (event) {
        event.preventDefault();

        var isOpen = searchWrap.classList.contains('is-open');
        if (isOpen) {
          searchWrap.classList.remove('is-open');
          toggle.setAttribute('aria-expanded', 'false');
          searchInput.value = '';
          state.query = '';
          state.page = 1;
          render();
          return;
        }

        searchWrap.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        searchInput.focus();
      });

      searchInput.addEventListener('input', function () {
        state.query = normalizeText(searchInput.value);
        state.page = 1;
        render();
      });
    }

    render();
  }

  Drupal.behaviors.repDescribeInstanceTables = {
    attach: function (context) {
      once('rep-describe-instance-tables', '[data-rep-instance-section]', context).forEach(initSection);
    },
  };
})(Drupal, once);
