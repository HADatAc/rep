<?php

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\FormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\rep\Utils;
 use Drupal\rep\Entity\GenericObject;
 use Drupal\rep\Vocabulary\FOAF;
 use Drupal\rep\Vocabulary\REPGUI;
 use Drupal\rep\Vocabulary\VSTOI;
 use Drupal\rep\Vocabulary\SCHEMA;
 use Drupal\rep\ListPropertyPage;

 class DescribeAssociatesForm extends FormBase {

  protected const TOT_PER_PAGE = 6;

  protected $element;

//    protected $associates;
  
    public function getElement() {
      return $this->element;
    }
  
    public function setElement($object) {
      return $this->element = $object; 
    }
  
//    public function getAssociates() {
//      return $this->associates;
//    }
  
//    public function setAssociates($obj) {
//      return $this->associates = $obj; 
//    }
  
    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "describe_associates_form";
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state) {

        // RETRIEVE PARAMETERS FROM HTML REQUEST
        $request = \Drupal::request();
        $pathInfo = $request->getPathInfo();
        $pathElements = (explode('/',$pathInfo));
        if (sizeof($pathElements) >= 4) {
          $elementuri = $pathElements[3];
        }
        // RETRIEVE REQUESTED ELEMENT
        $uri=base64_decode(rawurldecode($elementuri));
        $api = \Drupal::service('rep.api_connector');
        $finalUri = $api->getUri(Utils::plainUri($uri));
        if ($finalUri != NULL) {
          $this->setElement($api->parseObjectResponse($finalUri,'getUri'));
          if ($this->getElement() != NULL) {
            $objectProperties = GenericObject::inspectObject($this->getElement());
          }
        }

        //dpm($objectProperties);

        $form['associates_header'] = [
          '#type' => 'item',
          '#title' => '<h3>Associated Elements</h3>',
        ];

        foreach ($objectProperties['objects'] as $propertyName => $propertyValue) {

          // PROCESS EMBEDDED OBJECTS
          if ($propertyName === 'hasAddress') {  
            $this->processPropertyAddress($propertyValue, $form, $form_state);
          } else {

            // THIS IS THE PROCESSING OF GENERAL OBJECT PROPERTIES
            $prettyName = DescribeForm::prettyProperty($propertyName);
            $form[$propertyName] = [
              '#type' => 'markup',
              '#markup' => $this->t("<b>".$prettyName . "</b>: " . Utils::link($propertyValue->label,$propertyValue->uri)."<br><br>"),
            ];
          }
        }
        
        if ($this->getElement()->hascoTypeUri === SCHEMA::PLACE) {
          $this->processPlace($form, $form_state);
        } else if ($this->getElement()->hascoTypeUri === FOAF::ORGANIZATION) {
          $this->processOrganization($form, $form_state);
        }

        return $form;        
    }

    public function processPropertyAddress($addressObject, array &$form, FormStateInterface $form_state) {
      $addressProperties = GenericObject::inspectObject($addressObject);
      $form['beginAddress'] = [
        '#type' => 'markup',
        '#markup' => $this->t("<b>Postal Address</b>:<br><ul>"),
      ];
      $excludedLiterals = ['label','typeLabel','hascoTypeLabel'];
      foreach ($addressProperties['literals'] as $propertyNameAddress => $propertyValueAddress) {
        if (!in_array($propertyNameAddress,$excludedLiterals)) {
          $form[$propertyNameAddress] = [
            '#type' => 'markup',
            '#markup' => $this->t("<b>" . $propertyNameAddress . "</b>: " . $propertyValueAddress. "<br>"),
          ];
        }
      }
      foreach ($addressProperties['objects'] as $propertyNameAddress => $propertyValueAddress) {
        $form[$propertyNameAddress] = [
          '#type' => 'markup',
          '#markup' => $this->t("<b>" . $propertyNameAddress . "</b>: " . Utils::link($propertyValueAddress->label,$propertyValueAddress->uri) . "<br>"),
        ];
      }
      $form['endAddress'] = [
        '#type' => 'markup',
        '#markup' => $this->t("</ul>"),
      ];
    }

    public function processPlace(array &$form, FormStateInterface $form_state) {
      $api = \Drupal::service('rep.api_connector');
      $rawContains = $api->getContains($this->getElement()->uri,self::TOT_PER_PAGE,0);
      if ($rawContains != NULL) {
        $contains = $api->parseObjectResponse($rawContains,'getContains');
        if ($contains != NULL) {
          $totalContains = $api->parseTotalResponse($api->getTotalContains($this->getElement()->uri),'getTotalContains');
          $form['beginContains'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<b>Contains Places (total of " . $totalContains . "):</b><ul>"),
          ];
          foreach ($contains as $propertyNameContains => $propertyValueContains) {
            $form[$propertyNameContains] = [
              '#type' => 'markup',
              '#markup' => $this->t("<li>" . Utils::link($propertyValueContains->label,$propertyValueContains->uri) . "</li>"),
            ];
          }
          if ($totalContains > self::TOT_PER_PAGE) {
            $link = ListPropertyPage::link($this->getElement(),SCHEMA::CONTAINS_PLACE,1,20);
            $form['moreElements'] = [
              '#type' => 'markup',
              //'#markup' => $this->t("<li><a href=\"" . . "\">(More)</a></li>"),
              '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                          'data-dialog-type="modal" '.
                          'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
            ];
              //$form['moreElements'] = [
              //'#type' => 'markup',
              //'#markup' => $this->t("<li><a href=\"" . 
              //    ListPropertyPage::link($this->getElement(),SCHEMA::CONTAINS_PLACE,1,30) . 
              //    "\">(More)</a></li>"),
              //];
          }
          $form['endContains'] = [
            '#type' => 'markup',
            '#markup' => $this->t("</ul><br>"),
          ];
        }
      }
      return $form;        
    }
     
    public function processOrganization(array &$form, FormStateInterface $form_state) {
      $api = \Drupal::service('rep.api_connector');
      $rawSubOrgs = $api->getSubOrganizations($this->getElement()->uri,self::TOT_PER_PAGE,0);
      if ($rawSubOrgs != NULL) {
        $subOrgs = $api->parseObjectResponse($rawSubOrgs,'getSubOrganizations');
        if ($subOrgs != NULL) {
          $totalSubOrgs = $api->parseTotalResponse($api->getTotalSubOrganizations($this->getElement()->uri),'getTotalSubOrganizations');
          $form['beginSubOrgs'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<b>SubOrganizations (total of " . $totalSubOrgs . "):</b><ul>"),
          ];
          foreach ($subOrgs as $propertyNameSubOrgs => $propertyValueSubOrgs) {
            $form[$propertyNameSubOrgs] = [
              '#type' => 'markup',
              '#markup' => $this->t("<li>" . Utils::link($propertyValueSubOrgs->label,$propertyValueSubOrgs->uri) . " - " . $propertyValueSubOrgs->name . "</li>"),
            ];
          }
          if ($totalSubOrgs > self::TOT_PER_PAGE) {
            $link = ListPropertyPage::link($this->getElement(),SCHEMA::SUB_ORGANIZATION,1,20);
            $form['moreElements'] = [
              '#type' => 'markup',
              //'#markup' => $this->t("<li><a href=\"" . . "\">(More)</a></li>"),
              '#markup' => '<a href="' . $link . '" class="use-ajax btn btn-primary btn-sm" '.
                          'data-dialog-type="modal" '.
                          'data-dialog-options=\'{"width": 700}\' role="button">(More)</a>',
            ];
          }
          $form['endSubOrgs'] = [
            '#type' => 'markup',
            '#markup' => $this->t("</ul><br>"),
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