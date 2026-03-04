(function (Drupal, once) {
  Drupal.behaviors.repGraphCollapse = {
    attach: function (context) {
      once('repGraphCollapse', '.rep-graph-collapse-toggle, .graph-toggle-btn', context).forEach(function (btn) {
        var targetId =
          btn.getAttribute('data-rep-collapse-target') ||
          btn.getAttribute('aria-controls') ||
          (btn.dataset ? btn.dataset.repCollapseTarget : null);

        if (!targetId) return;

        var target = document.getElementById(targetId);
        if (!target) return;

        // Body is the collapsible container itself.
        var body = target;

        var getLabelNode = function () {
          return (
            btn.querySelector('.rep-graph-collapse-label') ||
            btn.querySelector('.graph-toggle-label') ||
            btn
          );
        };

        var isActuallyOpen = function () {
          if (body.hidden) return false;
          if (body.style && body.style.display === 'none') return false;
          // If no explicit hiding is applied, consider it open.
          return true;
        };

        var clearForcedClosedStyles = function (el) {
          if (!el || !el.style) return;
          el.style.removeProperty('display');
          el.style.removeProperty('height');
          el.style.removeProperty('min-height');
          el.style.removeProperty('overflow');
        };

        var forceClosedStyles = function (el) {
          if (!el || !el.style) return;
          // Some themes may override [hidden] with display:block!important on form wrappers.
          // Inline !important wins, ensuring the collapsed container takes no space.
          el.style.setProperty('display', 'none', 'important');
          el.style.setProperty('height', '0', 'important');
          el.style.setProperty('min-height', '0', 'important');
          el.style.setProperty('overflow', 'hidden', 'important');
        };

        var setOpen = function (open) {
          var graph = body.querySelector('#my-network');
          var floatingMenu = document.getElementById('expand-menu');

          if (open) {
            body.hidden = false;
            clearForcedClosedStyles(body);
            body.classList.add('show');
            // Some markup uses style="display:none" by default.
            body.style.removeProperty('display');

            if (graph) {
              // remove our forced closed display if any
              graph.style.removeProperty('display');
            }
          }
          else {
            body.classList.remove('show');
            body.hidden = true;
            forceClosedStyles(body);

            if (graph) {
              graph.style.setProperty('display', 'none', 'important');
            }
            if (floatingMenu) {
              floatingMenu.style.display = 'none';
            }
          }
        };

        var update = function () {
          var open = isActuallyOpen();
          btn.setAttribute('aria-expanded', open ? 'true' : 'false');
          getLabelNode().textContent = open ? Drupal.t('Hide') : Drupal.t('Show');
          // Match CienciaPT: update title tooltip if present.
          if (btn.hasAttribute('title')) {
            btn.setAttribute('title', open ? Drupal.t('Hide graph') : Drupal.t('Show graph'));
          }
        };

        update();

        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var open = isActuallyOpen();
          setOpen(!open);
          update();

          // Let vis.js recalc sizes when showing again.
          if (!open) {
            window.setTimeout(function () {
              try {
                window.dispatchEvent(new Event('resize'));
              } catch (err) {
                // ignore
              }

              // Initialize graph behaviors now that it's visible (graph.js defers init when hidden).
              try {
                if (window.Drupal && Drupal.attachBehaviors) {
                  Drupal.attachBehaviors(body);
                }
              } catch (err2) {
                // ignore
              }

              // If a network already exists, re-center it.
              try {
                var graph = body.querySelector('#my-network');
                if (graph && graph.__repNetwork) {
                  var net = graph.__repNetwork;
                  var rootId = graph.__repRootId;
                  if (rootId) {
                    net.focus(rootId, { scale: 1.0, animation: { duration: 250 } });
                  } else {
                    net.fit({ animation: { duration: 250 } });
                  }
                }
              } catch (err3) {
                // ignore
              }
            }, 50);
          }
        });
      });
    },
  };
})(Drupal, once);
