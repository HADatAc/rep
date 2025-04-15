<?php

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\FormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\rep\Utils;

 class DescribeHeaderForm extends FormBase {

    protected $element;

    public function getElement() {
      return $this->element;
    }

    public function setElement($obj) {
      return $this->element = $obj;
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "describe_header_form";
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state){

        // RETRIEVE PARAMETERS FROM HTML REQUEST
        $request = \Drupal::request();
        $pathInfo = $request->getPathInfo();
        $pathElements = (explode('/',$pathInfo));
        if (sizeof($pathElements) >= 4) {
          $elementuri = $pathElements[3];
        }
        // RETRIEVE REQUESTED ELEMENT
        $uri=base64_decode(rawurldecode($elementuri));
        $full_uri = Utils::plainUri($uri);
        $api = \Drupal::service('rep.api_connector');
        $this->setElement($api->parseObjectResponse($api->getUri($full_uri),'getUri'));

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
          } else {
            $type = $this->getElement()->typeLabel . " (" . $this->getElement()->hascoTypeLabel . ")";
          }

          if ( isset($this->getElement()->hasImageUri) ) {
            // hascoTypeLabel
            $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/'.strtolower($type).'_placeholder.png';
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
            '#markup' => $this->t("<br /><h1>" . $this->getElement()->label . "</h1>"),
          ];

        if ($this->getElement()->hascoTypeLabel === 'Organization')
          $form['name'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<h5>" . $this->getElement()->name . "</h5><br>"),
          ];

          $form['type'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<h3>" . ucfirst($type) . "</h3><br>"),
          ];

          $form['element_uri'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<b>URI</b>: " . $this->getElement()->uri . "<br><br>"),
          ];

          $typeUri = $this->getElement()->typeUri;

          $form['element_type'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<b>Type URI</b>: " . Utils::link($typeUri,$typeUri) . "<br><br>"),
          ];

          if (isset($this->getElement()->title)) {
            $form['element_title'] = [
              '#type' => 'markup',
              '#markup' => $this->t("<b>Title</b>: " . $this->getElement()->title . "<br><br>"),
            ];
          }

        }

        return $form;

    }

    public function validateForm(array &$form, FormStateInterface $form_state) {
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
    }

 }
