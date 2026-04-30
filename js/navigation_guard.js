(function (Drupal, once) {
  'use strict';

  const DIRTY_ATTR = 'data-rep-form-dirty';

  function isFormField(el) {
    if (!el || el.disabled) return false;
    const tag = (el.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
    return el.isContentEditable === true;
  }

  function markFormDirty(form) {
    if (form) {
      form.setAttribute(DIRTY_ATTR, '1');
    }
  }

  function clearFormDirty(form) {
    if (form) {
      form.removeAttribute(DIRTY_ATTR);
    }
  }

  function isDirty(form) {
    return !!(form && form.getAttribute(DIRTY_ATTR) === '1');
  }

  function isPlainLeftClick(event) {
    // Only guard normal left-click navigation; let ctrl/cmd/middle-click behave normally.
    if (!event || event.defaultPrevented) return false;
    if (event.button !== 0) return false;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
    return true;
  }

  Drupal.behaviors.repNavigationGuard = {
    attach(context) {
      // Track dirty forms.
      once('repNavigationGuardForm', 'form', context).forEach((form) => {
        form.addEventListener('input', (e) => {
          if (isFormField(e.target)) {
            markFormDirty(form);
          }
        }, true);

        form.addEventListener('change', (e) => {
          if (isFormField(e.target)) {
            markFormDirty(form);
          }
        }, true);

        form.addEventListener('submit', () => {
          // Best-effort: consider the form clean after a submit.
          clearFormDirty(form);
        }, true);
      });

      // Guard navigation for our known internal links.
      once('repNavigationGuardClick', 'body', context).forEach((body) => {
        body.addEventListener('click', (e) => {
          const a = e.target && e.target.closest ? e.target.closest('a.rep-nav-guard') : null;
          if (!a) return;
          if (!isPlainLeftClick(e)) return;

          const form = a.closest('form');
          if (!form) return;
          if (!isDirty(form)) return;

          const ok = window.confirm('You have unsaved changes. Do you want to leave this page?');
          if (!ok) {
            e.preventDefault();
            e.stopPropagation();
          }
        }, true);
      });
    },
  };
})(Drupal, once);
