<?php

/**
 * @file
 * Contains the settings for admninistering the rep Module
 */

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\FormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\Core\Url;
 use Drupal\rep\Utils;

 class UriForm extends FormBase {

    protected $elementUri;

    public function getElementUri() {
      return $this->elementUri;
    }

    public function setElementUri($uri) {
      return $this->elementUri = $uri;
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "uri_form";
    }

    /**
     * {@inheritdoc}
     */

     public function buildForm(array $form, FormStateInterface $form_state){

        $form['std_status'] = [
            '#type' => 'item',
            '#title' => $this->t('<h3>Describe URI</h3>'),
        ];
        $form['element_uri'] = [
            '#type' => 'textfield',
            '#title' => 'URI to be described',
            '#required' => TRUE,
        ];
        $form['note1'] = [
            '#type' => 'item',
            '#title' => $this->t('The provided value can be in plain URI format (something like <b>http://example.com/mydomain/concept</b>). ' .
                'The provided value can also be based on a known namespace prefix (something like <b>myproject:concept</b>). ' .
                'If using a prefix it needs to be a prefix registered in this repository.'),
        ];
        $form['submit_describe'] = [
            '#type' => 'submit',
            '#value' => $this->t('Describe'),
            '#name' => 'describe',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'describe-button'],
            ],
        ];
        $form['space1'] = [
            '#type' => 'item',
            '#title' => $this->t('<br><br<'),
        ];
        $form['submit_back'] = [
            '#type' => 'submit',
            '#value' => $this->t('Back'),
            '#name' => 'back',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'back-button'],
            ],
        ];
        $form['space2'] = [
            '#type' => 'markup',
            '#markup' => '<br><br><br><br>',
        ];

        return $form;

    }

    public function validateForm(array &$form, FormStateInterface $form_state) {
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $submitted_values = $form_state->cleanValues()->getValues();
        $triggering_element = $form_state->getTriggeringElement();
        $button_name = $triggering_element['#name'];

        if ($button_name === 'back') {
            $url = Url::fromRoute('rep.home');
            $form_state->setRedirectUrl($url);
            return;
        }

        if ($button_name === 'describe') {
            $newUri = Utils::plainUri($form_state->getValue('element_uri'));
            $url = Url::fromRoute('rep.describe_element', ['elementuri' => base64_encode($newUri)]);
            $form_state->setRedirectUrl($url);
            return;
        }

        $url = Url::fromRoute('rep.home');
        $form_state->setRedirectUrl($url);
        return;
    }

 }
