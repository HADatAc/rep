<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\Utils;
 use Drupal\rep\Vocabulary\REPGUI;

class DescribeHeaderForm extends FormBase {

  protected $element;

  public function getElement() {
    return $this->element;
  }

  public function setElement($obj) {
    return $this->element = $obj;
  }

  public function getFormId() {
    return "describe_header_form";
  }

  public function buildForm(array $form, FormStateInterface $form_state) {

    $root_url = \Drupal::request()->getBaseUrl();

    $request = \Drupal::request();
    $pathInfo = $request->getPathInfo();
    $pathElements = (explode('/', $pathInfo));
    if (sizeof($pathElements) >= 4) {
      $elementuri = $pathElements[3];
    }

    $uri = base64_decode(rawurldecode($elementuri));
    $full_uri = Utils::plainUri($uri);
    $api = \Drupal::service('rep.api_connector');
    $this->setElement($api->parseObjectResponse($api->getUri($full_uri), 'getUri'));

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
    } else {

      if (($this->getElement()->typeLabel === NULL || $this->getElement()->typeLabel === "") &&
          ($this->getElement()->hascoTypeLabel === NULL || $this->getElement()->hascoTypeLabel === "")) {
        $parts = explode('/', $this->getElement()->typeUri);
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

      if (isset($this->getElement()->hasImageUri)) {
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

      $form['label'] = [
        '#type' => 'markup',
        '#markup' => $this->t("<br /><h1>" . UTILS::sanitizeString($this->getElement()->label) . "</h1><br />"),
      ];

      if ($this->getElement()->hascoTypeLabel === 'Organization') {
        $form['organization'] = [
          '#type' => 'markup',
          '#markup' => $this->t("<h5>" . UTILS::sanitizeString($this->getElement()->name) . "</h5><br>"),
        ];
      }

      $form['element_uri'] = [
        '#type' => 'markup',
        '#markup' => $this->t('<div class="describe-header-wb"><b>URI</b>: ' . $this->getElement()->uri . "</div><br />"),
      ];

      $typeUri = $this->getElement()->typeUri;

      if ($typeUri) {
        $form['element_type'] = [
          '#type' => 'inline_template',
          '#template' => '<b>Type URI</b>: <a href="{{ uri }}" target="_blank">{{ typeUri }}</a>
          <span class="graph-toggle" data-node="{{ uri }}" style="cursor:pointer;" title="Mostrar/Ocultar nó">
            <i class="fa fa-eye"></i>
          </span><br><br>',
          '#context' => [
            'uri' => ($root_url.REPGUI::DESCRIBE_PAGE.base64_encode($this->getElement()->typeUri)),
            'typeUri' => rawurldecode($this->getElement()->typeUri),
          ],
        ];
      }

      if ($this->getElement()->hascoTypeUri) {
        $form['element_hascoType'] = [
          '#type' => 'inline_template',
          '#template' => '<b>HascoType URI</b>: <a href="{{ uri }}" target="_new">{{ hascoTypeUri }}</a>
          <span class="graph-toggle" data-node="{{ uri }}" style="cursor:pointer;" title="Mostrar/Ocultar nó">
            <i class="fa fa-eye"></i>
          </span><br><br>',
          '#context' => [
            'uri' => ($root_url.REPGUI::DESCRIBE_PAGE.base64_encode($this->getElement()->hascoTypeUri)),
            'hascoTypeUri' => rawurldecode($this->getElement()->hascoTypeUri),
          ],
        ];
      }

      if ($this->getElement()->superUri) {
        $form['element_super'] = [
          '#type' => 'inline_template',
          '#template' => '<b>Super URI</b>: <a href="{{ uri }}" target="_new">{{ superUri }}</a>
          <span class="graph-toggle" data-node="{{ uri }}" style="cursor:pointer;" title="Mostrar/Ocultar nó">
            <i class="fa fa-eye"></i>
          </span><br><br>',
          '#context' => [
            'uri' => ($root_url.REPGUI::DESCRIBE_PAGE.base64_encode($this->getElement()->superUri)),
            'superUri' => rawurldecode($this->getElement()->superUri),
          ],
        ];
      }

      if (isset($this->getElement()->title)) {
        $form['element_title'] = [
          '#type' => 'markup',
          '#markup' => $this->t("<b>Title</b>: " . UTILS::sanitizeString($this->getElement()->title) . "<br><br>"),
        ];
      }

      if (isset($this->getElement()->description) || isset($this->getElement()->comment)) {
        if ($this->getElement()->description !== "" && $this->getElement()->comment !== "") {
          $descmarkup = "<b>From RDF Comment</b>: " . $this->getElement()->comment
            . "<b>From DCTerms Description</b>: " . $this->getElement()->description;
        } else if ($this->getElement()->description !== "") {
          $descmarkup = "<b>Description</b>: " . $this->getElement()->description;
        } else if ($this->getElement()->comment !== "") {
          $descmarkup = "<b>Comment</b>: " . $this->getElement()->comment;
        }

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
