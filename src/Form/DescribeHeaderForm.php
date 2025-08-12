<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Utils;

/**
 * Build the top "describe" header for a single element page.
 *
 * Notes for the graph integration:
 * - We render external "eye" toggles with the class .graph-toggle.
 * - Each toggle now carries:
 *     data-node  = the URI of the node to show/hide on the canvas
 *     data-label = OPTIONAL label filter to only toggle edges of that label
 *                  (we use "typeUri" or "hascoTypeUri" here)
 * - The JS handler (graph.js) reads data-label and only adds/removes edges
 *   for that label, keeping typeUri and hascoTypeUri independent.
 */
class DescribeHeaderForm extends FormBase {

  /** @var object|null */
  protected $element;

  public function getElement() {
    return $this->element;
  }

  public function setElement($obj) {
    $this->element = $obj;
    return $this->element;
  }

  public function getFormId() {
    return "describe_header_form";
  }

  public function buildForm(array $form, FormStateInterface $form_state) {

    // --- Resolve the element URI from the path (encoded in the 4th segment) ---
    $request = \Drupal::request();
    $pathInfo = $request->getPathInfo();
    $pathElements = explode('/', $pathInfo);
    $elementuri = null;
    if (count($pathElements) >= 4) {
      $elementuri = $pathElements[3];
    }

    $uri = base64_decode(rawurldecode((string) $elementuri));
    $full_uri = Utils::plainUri($uri);

    // Load the element via the API connector and keep only the parsed object.
    /** @var \Drupal\rep\FusekiAPIConnector $api */
    $api = \Drupal::service('rep.api_connector');
    $this->setElement($api->parseObjectResponse($api->getUri($full_uri), 'getUri'));

    // --- Error case: could not load the element ---
    if ($this->getElement() == NULL || $this->getElement() == "") {
      $form['message'] = [
        '#type' => 'item',
        '#title' => t("<b>FAILED TO RETRIEVE ELEMENT FROM PROVIDED URI</b>"),
      ];
      $form['type'] = [
        '#type' => 'markup',
        '#markup' => $this->t("<h3>(UNKNOWN TYPE)</h3><br>"),
      ];
      $form['element_uri'] = [
        '#type' => 'markup',
        '#markup' => $this->t("<b>URI</b>: " . $full_uri . "<br><br>"),
      ];
      $form['element_type'] = [
        '#type' => 'markup',
        '#markup' => $this->t("<b>Type</b>: NONE<br><br>"),
      ];
      return $form;
    }

    // --- Compute a human-friendly type label (fallback logic kept as in original) ---
    if (
      ($this->getElement()->typeLabel === NULL || $this->getElement()->typeLabel === "") &&
      ($this->getElement()->hascoTypeLabel === NULL || $this->getElement()->hascoTypeLabel === "")
    ) {
      $parts = explode('/', (string) $this->getElement()->typeUri);
      $type = end($parts);
    } else if ($this->getElement()->typeLabel === NULL) {
      $type = $this->getElement()->hascoTypeLabel;
    } else if ($this->getElement()->hascoTypeLabel === NULL) {
      $type = $this->getElement()->typeLabel;
    } else if ($this->getElement()->typeLabel == $this->getElement()->hascoTypeLabel) {
      $type = $this->getElement()->typeLabel;
    } else if ($this->getElement()->typeLabel && $this->getElement()->hascoTypeLabel) {
      $type = $this->getElement()->typeLabel . " (" . $this->getElement()->hascoTypeLabel . ")";
    } else {
      $type = $this->getElement()->typeLabel;
    }

    // --- Optional thumbnail/image on the left ---
    if (isset($this->getElement()->hasImageUri)) {
      // Keep the original helper usage (same casing as your codebase).
      $placeholder_image = UTILS::placeholderImage($this->getElement()->hascoTypeUri, $this->getElement()->typeLabel, '/');
      $hasImageUri = (isset($this->getElement()->hasImageUri) && !empty($this->getElement()->hasImageUri))
        ? Utils::getAPIImage($this->getElement()->uri, $this->getElement()->hasImageUri, $placeholder_image)
        : $placeholder_image;

      $form['image_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['d-flex', 'justify-content-center'],
          'style' => ['margin-bottom: 10px!important;'],
        ],
      ];

      $form['image_wrapper']['image'] = [
        'image' => [
          '#theme' => 'image',
          '#uri' => $hasImageUri,
          '#attributes' => [
            'class' => ['img-fluid', 'mb-0', 'border', 'border-2', 'rounded', 'rounded-3'],
            'style' => ['max-width: 180px; height: auto;'],
          ],
        ],
      ];
    }

    // --- Title/label line ---
    $form['label'] = [
      '#type' => 'markup',
      '#markup' => $this->t("<br /><h1>" . UTILS::sanitizeString($this->getElement()->label) . "</h1><br />"),
    ];

    // --- Optional organization name under the title (domain-specific) ---
    if ($this->getElement()->hascoTypeLabel === 'Organization') {
      $form['organization'] = [
        '#type' => 'markup',
        '#markup' => $this->t("<h5>" . UTILS::sanitizeString($this->getElement()->name) . "</h5><br>"),
      ];
    }

    // --- Always show the element's own URI ---
    $form['element_uri'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<div class="describe-header-wb"><b>URI</b>: ' . $this->getElement()->uri . "</div><br />"),
    ];

    // --- Type URI block (with external graph toggle) ---
    // IMPORTANT: We add data-label="typeUri" so the JS only toggles edges with label "typeUri".
    $typeUri = $this->getElement()->typeUri;
    if ($typeUri) {
      $form['element_type'] = [
        '#type' => 'inline_template',
        '#template' => '<b>Type URI</b>: <a href="{{ uri }}" target="_blank">{{ uri }}</a>
          <span class="graph-toggle"
                data-node="{{ uri }}"
                data-label="typeUri"
                style="cursor:pointer;"
                title="Show/Hide typeUri edge">
            <i class="fa fa-eye"></i>
          </span><br><br>',
        '#context' => [
          'uri' => $this->getElement()->typeUri,
        ],
      ];
    }

    // --- If there is no standard type URI but a HASCO type exists, show link-only fallback ---
    if (!$typeUri && $this->getElement()->hascoTypeUri) {
      $form['element_hascoType_link'] = [
        '#type' => 'markup',
        '#markup' => $this->t("<b>HascoType URI</b>: " . Utils::link($this->getElement()->hascoTypeLabel, $this->getElement()->hascoTypeUri) . "<br><br>"),
      ];
    }

    // --- HASCO Type URI block (with external graph toggle) ---
    // IMPORTANT: We add data-label="hascoTypeUri" so the JS only toggles edges with that label.
    if ($this->getElement()->hascoTypeUri) {
      $form['element_hascoType'] = [
        '#type' => 'inline_template',
        '#template' => '<b>HascoType URI</b>: <a href="{{ uri }}" target="_blank">{{ uri }}</a>
          <span class="graph-toggle"
                data-node="{{ uri }}"
                data-label="hascoTypeUri"
                style="cursor:pointer;"
                title="Show/Hide hascoTypeUri edge">
            <i class="fa fa-eye"></i>
          </span><br><br>',
        '#context' => [
          'uri' => $this->getElement()->hascoTypeUri,
        ],
      ];
    }

    // --- Optional "super" relation link ---
    if (!empty($this->getElement()->superUri)) {
      $form['element_super'] = [
        '#type' => 'markup',
        '#markup' => $this->t("<b>Super URI</b>: " . Utils::link($this->getElement()->superUri, $this->getElement()->superUri) . "<br><br>"),
      ];
    }

    // --- Optional title/description/comment area ---
    if (isset($this->getElement()->title)) {
      $form['element_title'] = [
        '#type' => 'markup',
        '#markup' => $this->t("<b>Title</b>: " . UTILS::sanitizeString($this->getElement()->title) . "<br><br>"),
      ];
    }

    if (isset($this->getElement()->description) || isset($this->getElement()->comment)) {
      if (!empty($this->getElement()->description) && !empty($this->getElement()->comment)) {
        $descmarkup = "<b>From RDF Comment</b>: " . $this->getElement()->comment
          . "<b>From DCTerms Description</b>: " . $this->getElement()->description;
      } else if (!empty($this->getElement()->description)) {
        $descmarkup = "<b>Description</b>: " . $this->getElement()->description;
      } else if (!empty($this->getElement()->comment)) {
        $descmarkup = "<b>Comment</b>: " . $this->getElement()->comment;
      } else {
        $descmarkup = "";
      }

      if ($descmarkup !== "") {
        $form['element_short_name'] = [
          '#type' => 'markup',
          '#markup' => UTILS::sanitizeString($descmarkup),
        ];
      }
    }

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {}

  public function submitForm(array &$form, FormStateInterface $form_state) {}

}
