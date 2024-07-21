<?php

/**
 * @file
 * Contains the settings for admninistering the REP Module
 */

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\FormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\Core\Url;
 use Symfony\Component\HttpFoundation\RedirectResponse;
 use Drupal\rep\ListUsage;
 use Drupal\rep\Utils;
 use Drupal\rep\Entity\Tables;
 use Drupal\rep\Entity\GenericObject;
 use Drupal\rep\Vocabulary\REPGUI;
 use Drupal\rep\Vocabulary\VSTOI;

 class DescribeForm extends FormBase {

    protected $element;

    protected $source;

    protected $codebook;
  
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
        return "describe_form";
    }

    /**
     * {@inheritdoc}
     */

     public function buildForm(array $form, FormStateInterface $form_state, $elementuri=NULL){

        // RETRIEVE REQUESTED ELEMENT
        $uri_decode=base64_decode($elementuri);
        $full_uri = Utils::plainUri($uri_decode);
        $api = \Drupal::service('rep.api_connector');
        $this->setElement($api->parseObjectResponse($api->getUri($full_uri),'getUri'));

        //dpm($this->getElement());

        $objectProperties = GenericObject::inspectObject($this->getElement());

        //($objectProperties);

        //if ($objectProperties !== null) {
        //    dpm($objectProperties);
        //} else {
        //    dpm("The provided variable is not an object.");
        //}
        

        // RETRIEVE CONFIGURATION FROM CURRENT IP
        if ($this->getElement() != NULL) {
            $hascoType = $this->getElement()->hascoTypeUri;
            if ($hascoType == VSTOI::INSTRUMENT) {
                $shortName = $this->getElement()->hasShortName;
            }
            if ($hascoType == VSTOI::INSTRUMENT || $hascoType == VSTOI::CODEBOOK) {
                $name = $this->getElement()->label;
            }
            $message = "";
        } else {
            $shortName = "";
            $name = "";
            $message = "<b>FAILED TO RETRIEVE ELEMENT FROM PROVIDED URI</b>";
        }

        // Instantiate tables 
        $tables = new Tables;

        $form['header1'] = [
            '#type' => 'item',
            '#title' => '<h3>Data Properties</h3>',
        ];

        foreach ($objectProperties['literals'] as $propertyName => $propertyValue) {
            // Add a textfield element for each property
            if ($propertyValue !== NULL && $propertyValue !== "") {
                $prettyName = DescribeForm::prettyProperty($propertyName);
                $form[$propertyName] = [
                    '#type' => 'markup',
                    '#markup' => $this->t("<b>" . $prettyName . "</b>: " . $propertyValue. "<br><br>"),
                ];
            }

            /*
            $form[$propertyName] = [
              '#type' => 'textfield',
              '#title' => $this->t($propertyName),
              '#default_value' => $propertyValue, // Set default value
              '#disabled' => TRUE,
            ];
            */
          }
          

        $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Back'),
            '#name' => 'back',
        ];
        $form['space'] = [
            '#type' => 'markup',
            '#markup' => $this->t("<br><br>"),
        ];

        return $form;

    }

    public function validateForm(array &$form, FormStateInterface $form_state) {
    }
     
    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        //$url = Url::fromRoute('rep.about');
        //$form_state->setRedirectUrl($url);
        self::backUrl();
        return;
    }

    public static function prettyProperty($input) {
        // Remove "has" from the string
        $inputWithoutHas = str_replace('has', '', $input);
        
        // Add a space before each capital letter (excluding the first character)
        $stringWithSpaces = preg_replace('/(?<!^)([A-Z])/', ' $1', $inputWithoutHas);
    
        // Capitalize the first term
        $result = ucfirst($stringWithSpaces);
        
        return $result;
      }
  
      function backUrl() {
        $uid = \Drupal::currentUser()->id();
        $previousUrl = Utils::trackingGetPreviousUrl($uid, 'rep.describe_element');
        if ($previousUrl) {
          $response = new RedirectResponse($previousUrl);
          $response->send();
          return;
        }
      }
      
 }