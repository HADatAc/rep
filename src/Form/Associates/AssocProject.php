<?php

namespace Drupal\rep\Form\Associates;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\rep\Utils;

class AssocProject {

  public static function process($element, array &$form, FormStateInterface $form_state) {
    $t = \Drupal::service('string_translation');

    // --- 1) Contributor URIs as a <ul> with real bullets
    if (!empty($element->contributorUris) && is_array($element->contributorUris)) {

      unset($element->contributorUris);
      $items = '';
      foreach ($element->contributorUris as $uri) {
        // escape the URI automatically
        $items .= '<li>' . Html::escape($uri) . '</li>';
      }
      $ul = '<ul style="list-style: disc; margin-left: 1.5em;">' . $items . '</ul>';

      $form['contributorUris'] = [
        '#type'        => 'markup',
        // '#markup'      => '<h3>' . $t->translate('Contributor URIs') . '</h3>' . $ul,
        '#allowed_tags'=> ['h3','ul','li','a'],  // allow only these tags
      ];
    }

    // --- 2) Contributors as a <ul> with links
    if (!empty($element->contributors) && is_array($element->contributors)) {
      $items = '';
      foreach ($element->contributors as $contrib) {
        $label = !empty($contrib->label) ? $contrib->label : $contrib->name;
        // build an <a> to the URI
        $url   = Html::escape($contrib->uri);
        $items .= '<li>' . Utils::link(Html::escape($label . ' - ' . $contrib->name), $url, '_new') .'</li>';
        // . ' ['.Utils::namespaceUri($url).']
      }
      $ul = '<ul style="list-style: disc; margin-left: 1.5em;">' . $items . '</ul>';

      $form['contributors'] = [
        '#type'        => 'markup',
        '#markup'      => '<h3>' . $t->translate('Contributors') . '</h3>' . $ul,
        '#allowed_tags'=> ['h3','ul','li','a'],
      ];
    }

    return $form;
  }


}
