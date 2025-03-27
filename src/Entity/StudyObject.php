<?php

namespace Drupal\rep\Entity;

use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Utils;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;

class StudyObject {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_soc_name' => t('SOC Name'),
      'element_original_id' => t('Original ID'),
      'element_entity' => t('Entity Type'),
      'element_domain_scope' => t('Domain Scope'),
      'element_time_scope' => t('Time Scope'),
      'element_space_scope' => t('Space Scope'),
    ];

  }

  public static function generateOutput($list) {

    //dpm($list);

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    $output = array();
    foreach ($list as $element) {
      $uri = ' ';
      if ($element->uri != NULL) {
        $uri = $element->uri;
      }
      $uri = Utils::namespaceUri($uri);
      $label = ' ';
      if ($element->label != NULL) {
        $label = $element->label;
      }
      $originalId = ' ';
      if ($element->originalId != NULL) {
        $originalId = $element->originalId;
      }
      $socLabel = ' ';
      if ($element->isMemberOf != NULL &&
          $element->isMemberOf->label != NULL) {
        $socLabel = $element->isMemberOf->label;
      }
      $typeLabel = ' ';
      if ($element->typeLabel != NULL) {
        $typeLabel = $element->typeLabel;
      }
      $domainScope = ' ';
      if ($element->scopeUris != NULL && count($element->scopeUris) > 0) {
        $domainScope = implode(', ', $element->scopeUris);
      }
      $timeScope = ' ';
      if ($element->timeScopeUris != NULL && count($element->timeScopeUris) > 0) {
        $timeScope = implode(', ', $element->timeScopeUris);
      }
      $spaceScope = ' ';
      if ($element->spaceScopeUris != NULL && count($element->spaceScopeUris) > 0) {
        $spaceScope = implode(', ', $element->spaceScopeUris);
      }
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),
        'element_soc_name' => t($socLabel),
        'element_original_id' => t($originalId),
        'element_entity' => t($typeLabel),
        'element_domain_scope' => t($domainScope),
        'element_time_scope' => t($timeScope),
        'element_space_scope' => t($spaceScope),
      ];
    }
    return $output;
  }

  public static function generateOutputCards($list) {
    $output = [];
    // Get the root URL.
    $root_url = \Drupal::request()->getBaseUrl();

    if (empty($list)) {
      return $output;
    }

    $index = 0;
    foreach ($list as $element) {
      $index++;

      // Retrieve and process element properties.
      $uri = !empty($element->uri) ? $element->uri : ' ';
      // Process the URI with your namespace utility.
      $uri = \Drupal\rep\Utils::namespaceUri($uri);

      $label = !empty($element->label) ? $element->label : ' ';
      $originalId = !empty($element->originalId) ? $element->originalId : ' ';

      $socLabel = ' ';
      if (!empty($element->isMemberOf) && !empty($element->isMemberOf->label)) {
        $socLabel = $element->isMemberOf->label;
      }
      $typeLabel = !empty($element->typeLabel) ? $element->typeLabel : ' ';

      $domainScope = ' ';
      if (!empty($element->scopeUris) && is_array($element->scopeUris) && count($element->scopeUris) > 0) {
        $domainScope = implode(', ', $element->scopeUris);
      }
      $timeScope = ' ';
      if (!empty($element->timeScopeUris) && is_array($element->timeScopeUris) && count($element->timeScopeUris) > 0) {
        $timeScope = implode(', ', $element->timeScopeUris);
      }
      $spaceScope = ' ';
      if (!empty($element->spaceScopeUris) && is_array($element->spaceScopeUris) && count($element->spaceScopeUris) > 0) {
        $spaceScope = implode(', ', $element->spaceScopeUris);
      }

      // Create a "View" link for the study object.
      $previousUrl = base64_encode(\Drupal::request()->getRequestUri());
      $view_obj_str = base64_encode(Url::fromRoute('rep.describe_element', ['elementuri' => base64_encode($element->uri)])->toString());
      $view_obj = Url::fromRoute('rep.back_url', [
        'previousurl' => $previousUrl,
        'currenturl' => $view_obj_str,
        'currentroute' => 'rep.describe_element',
      ]);

      // Build the card output.
      $output[$index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['card', 'mb-3'],
        ],
        // Wrap the card in a column for grid layouts.
        '#prefix' => '<div class="col-md-6">',
        '#suffix' => '</div>',
        'card_body_' . $index => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['card-body'],
          ],
          // Card title displays the element label.
          'title' => [
            '#markup' => '<h5 class="card-title">' . $label . '</h5>',
          ],
          // Card text displays various properties.
          'details' => [
            '#markup' => '<p class="card-text">'
              . '<strong>Original ID:</strong> ' . $originalId . '<br>'
              . '<strong>Entity:</strong> ' . $typeLabel . '<br>'
              . '<strong>Domain:</strong> ' . $domainScope . '<br>'
              . '<strong>Time:</strong> ' . $timeScope . '<br>'
              . '<strong>Space:</strong> ' . $spaceScope . '<br>'
              . '<strong>Study:</strong> ' . $socLabel
              . '</p>',
          ],
          // Add a "View" button that links to the study object description.
          'view_link_' . $index => [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fa-solid fa-eye"></i> View'),
            '#url' => $view_obj,
            '#attributes' => [
              'class' => ['btn', 'btn-sm', 'btn-secondary'],
            ],
          ],
        ],
      ];
    }

    return $output;
  }

}
