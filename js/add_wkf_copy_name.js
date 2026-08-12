(function ($, Drupal, once) {
  'use strict';

  function extractNameFromFilename(filename) {
    var base = String(filename || '').trim();
    if (!base) {
      return '';
    }

    // Remove directory part if browser includes fake path.
    base = base.split('\\').pop().split('/').pop();

    // Remove extension.
    base = base.replace(/\.[^.]+$/, '');

    // Remove leading WKF prefix and normalize separators.
    base = base.replace(/^WKF[-_\s]*/i, '');
    base = base.replace(/[-_]+/g, ' ').trim();

    return base;
  }

  function tryCopyFromSelectedFile() {
    var $copyTick = $('#edit-mt-copy-name');
    var $fileInput = $('#edit-mt-filename');
    var $name = $('#edit-mt-name');
    var $comment = $('#edit-mt-comment');

    if (!$copyTick.length || !$fileInput.length || !$name.length || !$comment.length) {
      return;
    }

    if (!$copyTick.is(':checked')) {
      return;
    }

    var input = $fileInput.get(0);
    if (!input || !input.files || !input.files.length) {
      return;
    }

    var filename = String(input.files[0].name || '');
    var copied = extractNameFromFilename(filename);
    if (!copied) {
      return;
    }

    $name.val(copied).trigger('change').trigger('input');
    $comment.val(copied).trigger('change').trigger('input');
  }

  Drupal.behaviors.addWkfCopyName = {
    attach: function (context) {
      once('add-wkf-copy-name-file', '#edit-mt-filename', context).forEach(function (el) {
        $(el).on('change', function () {
          tryCopyFromSelectedFile();
        });
      });

      once('add-wkf-copy-name-tick', '#edit-mt-copy-name', context).forEach(function (el) {
        $(el).on('change', function () {
          tryCopyFromSelectedFile();
        });
      });
    }
  };

})(jQuery, Drupal, once);
