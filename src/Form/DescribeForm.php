<?php

/**
 * @file
 * Contains the settings for admninistering the REP Module
 */

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\FormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\Core\Url;
 use Drupal\rep\ListUsage;
 use Drupal\rep\Utils;
 use Drupal\rep\Entity\Tables;
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
            '#title' => '<h3>Core Properties</h3>',
        ];

        if ($hascoType == VSTOI::INSTRUMENT) {
            $form['element_label'] = [
                '#type' => 'item',
                '#title' => '<b>Short Name</b>: ' . $shortName,
            ];
        }

        if ($hascoType == VSTOI::INSTRUMENT || $hascoType == VSTOI::CODEBOOK) {
            $form['element_name'] = [
                '#type' => 'item',
                '#title' => '<b>Full Name</b>: ' . $name,
            ];
        }

        if ($hascoType == VSTOI::DETECTOR || $hascoType == VSTOI::RESPONSE_OPTION) {
            $form['element_content'] = [
                '#type' => 'item',
                '#title' => '<b>Content</b>: ' . $this->getElement()->hasContent,
            ];
        }

        if ($this->getElement() != NULL) {

            $languages = $tables->getLanguages();
            $lang = "";
            if ($this->getElement()->hasLanguage != NULL && $this->getElement()->hasLanguage != "") {
                $lang = $languages[$this->getElement()->hasLanguage];
            }

            $form['element_description'] = [
                '#type' => 'item',
                '#title' => '<b>Description</b>: ' . $this->getElement()->comment,
            ];

            $form['element_version'] = [
                '#type' => 'item',
                '#title' => '<b>Version</b>: ' . $this->getElement()->hasVersion,
            ];

            $form['element_language'] = [
                '#type' => 'item',
                '#title' => '<b>Language</b>: ' . $lang,
            ];

        }

        $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Back'),
            '#name' => 'back',
        ];
        $form['space'] = [
            '#type' => 'item',
            '#value' => $this->t('<br><br>'),
        ];

        return $form;

    }

    public function validateForm(array &$form, FormStateInterface $form_state) {
    }
     
    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $url = Url::fromRoute('rep.about');
        $form_state->setRedirectUrl($url);
    }

 }