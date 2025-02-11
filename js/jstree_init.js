(function ($, Drupal, drupalSettings) {

  $(document).on('dialogcreate', '.ui-dialog', function (event) {
    var $dialog = $(event.target);
    var $container = $dialog.find('#jstree-container');
    console.log('dialogcreate -> found container length=', $container.length);

    if ($container.length && drupalSettings.rep && drupalSettings.rep.fileTree) {
      if ($.jstree.reference($container)) {
        $container.jstree('destroy').empty();
      }
      $container.jstree({
        'core': {
          'data': drupalSettings.rep.fileTree
        }
      });
      console.log('jsTree initialized inside dialog');
    }
    else {
      console.warn('No #jstree-container or no rep.fileTree found at dialogcreate event.');
    }
  });

})(jQuery, Drupal, drupalSettings);
