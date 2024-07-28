<?php

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\FormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\rep\Utils;
 use Drupal\rep\Form\Associates\AssocOrganization;
 use Drupal\rep\Form\Associates\AssocPlace;
 use Drupal\rep\Form\Associates\AssocStudy;
 use Drupal\rep\Form\Associates\AssocStudyObjectCollection;
 use Drupal\rep\Entity\GenericObject;
 use Drupal\rep\Vocabulary\FOAF;
 use Drupal\rep\Vocabulary\HASCO;
 use Drupal\rep\Vocabulary\REPGUI;
 use Drupal\rep\Vocabulary\SCHEMA;
 use Drupal\rep\Vocabulary\VSTOI;

 class DescribeAssociatesForm extends FormBase {

  protected $element;
  
    public function getElement() {
      return $this->element;
    }
  
    public function setElement($object) {
      return $this->element = $object; 
    }
  
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
            //dpm($objectProperties);
            //dpm($this->getElement());
          }
        }

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
            $link = ' ';
            if (isset($propertyValue->label) && isset($propertyValue->uri) &&
               ($propertyValue->label != NULL) && ($propertyValue->uri != NULL)) {
              $link = Utils::link($propertyValue->label,$propertyValue->uri);
            }
            $form[$propertyName] = [
              '#type' => 'markup',
              '#markup' => $this->t("<b>".$prettyName . "</b>: " . $link ."<br><br>"),
            ];
          }
        }

        foreach ($objectProperties['arrays'] as $propertyName => $propertyValue) {

          if (!empty($propertyValue)) {
            // THIS IS THE PROCESSING OF GENERAL ARRAY PROPERTIES
            $prettyName = DescribeForm::prettyProperty($propertyName);
            // Render array elements as a bullet list.
            $list_items = '<ul>';
            foreach ($propertyValue as $item) {
              $item_str = '';
              if (is_object($item)) {
                $item_str = $item->uri;
              } elseif (is_array($item)) {
                $item_str = implode(', ',$item);
              } else {
                $item_str = $item;
              }
              $list_items .= '<li>' . $item_str . '</li>';
            }
            $list_items .= '</ul>';

            $form[$propertyName] = [
              '#type' => 'markup',
              '#markup' => $this->t("<b>".$prettyName . "</b>: " . $list_items ."<br>"),
            ];
          }
        }

        if ($this->getElement()->hascoTypeUri === SCHEMA::PLACE) {
          AssocPlace::process($this->getElement(), $form, $form_state);
        } else if ($this->getElement()->hascoTypeUri === FOAF::ORGANIZATION) {
          AssocOrganization::process($this->getElement(), $form, $form_state);
        } else if ($this->getElement()->hascoTypeUri === HASCO::STUDY) {
          AssocStudy::process($this->getElement(), $form, $form_state);
        } else if ($this->getElement()->hascoTypeUri === HASCO::STUDY_OBJECT_COLLECTION) {
          AssocStudyObjectCollection::process($this->getElement(), $form, $form_state);
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

    public function validateForm(array &$form, FormStateInterface $form_state) {
    }
     
    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
    }

 }
