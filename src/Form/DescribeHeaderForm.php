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

          $form['name'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<h1>" . $this->getElement()->label . "</h1><br>"),
          ];

          $form['type'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<h3>" . ucfirst($type) . "</h3><br>"),
          ];

          $form['element_uri'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<b>URI</b>: " . $this->getElement()->uri . "<br><br>"),
          ];

          $form['element_type'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<b>Type URI</b>: " . $this->getElement()->typeUri . "<br><br>"),
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