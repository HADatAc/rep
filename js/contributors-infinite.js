(function (Drupal, once, drupalSettings) {
  function postJSON(url, data) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data || {}),
    }).then(function (r) {
      return r.json();
    });
  }

  Drupal.behaviors.repContributorsInfinite = {
    attach: function (context) {
      var cfg = (drupalSettings && drupalSettings.rep && drupalSettings.rep.contributorsInfinite) || null;
      if (!cfg || !cfg.grids) return;

      Object.keys(cfg.grids).forEach(function (gridId) {
        var g = cfg.grids[gridId];
        if (!g || !Array.isArray(g.uris) || !g.endpoint) return;

        var grid = document.getElementById(gridId);
        var sentinel = g.sentinelId ? document.getElementById(g.sentinelId) : null;
        var spinner = g.spinnerId ? document.getElementById(g.spinnerId) : null;
        if (!grid || !sentinel) return;

        once('repContribInfinite-' + gridId, sentinel, context).forEach(function () {
          var loaded = Number(g.loaded || 0);
          var pageSize = Number(g.pageSize || 9);
          var uris = g.uris;
          var inFlight = false;

          function setSpinnerVisible(visible) {
            if (!spinner) return;
            if (visible) {
              spinner.classList.remove('d-none');
            } else {
              spinner.classList.add('d-none');
            }
          }

          function removeSpinner() {
            if (spinner && spinner.parentNode) {
              spinner.parentNode.removeChild(spinner);
            }
            spinner = null;
          }

          function hasMore() {
            return loaded < uris.length;
          }

          function loadNext() {
            if (inFlight || !hasMore()) return;
            inFlight = true;

            setSpinnerVisible(true);

            var slice = uris.slice(loaded, loaded + pageSize);
            if (!slice.length) {
              inFlight = false;
              setSpinnerVisible(false);
              try { observer.disconnect(); } catch (e) {}
              removeSpinner();
              return;
            }
            postJSON(g.endpoint, { uris: slice })
              .then(function (res) {
                if (res && typeof res.html === 'string' && res.html.trim()) {
                  grid.insertAdjacentHTML('beforeend', res.html);
                }
                if (res && typeof res.count === 'number') {
                  loaded += Number(res.count) || slice.length;
                }
                else {
                  loaded += slice.length;
                }
                inFlight = false;
                setSpinnerVisible(false);
                if (!hasMore()) {
                  try { observer.disconnect(); } catch (e) {}
                  setSpinnerVisible(false);
                  removeSpinner();
                }
              })
              .catch(function () {
                inFlight = false;
                setSpinnerVisible(false);
              });
          }

          var observer = new IntersectionObserver(
            function (entries) {
              entries.forEach(function (e) {
                if (e.isIntersecting) {
                  loadNext();
                }
              });
            },
            { root: null, rootMargin: '200px', threshold: 0.01 }
          );

          observer.observe(sentinel);

          // If we're already done (edge cases), don't leave spinner around.
          if (!hasMore()) {
            try { observer.disconnect(); } catch (e) {}
            removeSpinner();
          }
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
