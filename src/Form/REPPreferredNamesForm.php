<?php

/**
 * @file
 * Contains the settings for admninistering the rep Module
 */

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\ConfigFormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\Core\Url;
 use Drupal\rep\Entity\Ontology;
 use Drupal\rep\Entity\Tables;

 class REPPreferredNamesForm extends ConfigFormBase {

     /**
     * Settings Variable.
     */
    Const CONFIGNAME = "rep.settings";

     /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "rep_form_preferred_names";
    }

    /**
     * {@inheritdoc}
     */

    protected function getEditableConfigNames() {
        return [
            static::CONFIGNAME,
        ];
    }

    /**
     * {@inheritdoc}
     */

     public function buildForm(array $form, FormStateInterface $form_state){
        $config = $this->config(static::CONFIGNAME);

        $instrument = "";
        if ($config->get("preferred_instrument")!= NULL) {
            $instrument = $config->get("preferred_instrument");
        }
        $form['preferred_instrument'] = [
            '#type' => 'textfield',
            '#title' => $this->t("Instrument's preferred name"),
            '#default_value' => $instrument,
        ];

        $detector = "";
        if ($config->get("preferred_detector")!= NULL) {
            $detector = $config->get("preferred_detector");
        }
        $form['preferred_detector'] = [
            '#type' => 'textfield',
            '#title' => $this->t("Detector's preferred name"),
            '#default_value' => $detector,
        ];

        $actuator = "";
        if ($config->get("preferred_actuator")!= NULL) {
            $actuator = $config->get("preferred_actuator");
        }
        $form['preferred_actuator'] = [
            '#type' => 'textfield',
            '#title' => $this->t("Actuator's preferred name"),
            '#default_value' => $actuator,
        ];

        $proccess = "";
        if ($config->get("preferred_process")!= NULL) {
            $proccess = $config->get("preferred_process");
        }
        $form['preferred_process'] = [
            '#type' => 'textfield',
            '#title' => $this->t("Process's preferred name"),
            '#default_value' => $proccess,
        ];

        $form['filler'] = [
            '#type' => 'item',
            '#title' => $this->t('<br>'),
        ];

        $form['back_submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Back to rep Settings'),
            '#name' => 'back',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'back-button'],
            ],
        ];

        return Parent::buildForm($form, $form_state);
    }

    public function validateForm(array &$form, FormStateInterface $form_state) {
        if(strlen($form_state->getValue('preferred_instrument')) < 1) {
            $form_state->setErrorByName('preferred_instrument', $this->t("Please inform a preferred name for instruments."));
        }
        if(strlen($form_state->getValue('preferred_actuator')) < 1) {
          $form_state->setErrorByName('preferred_actuator', $this->t("Please inform a preferred name for actuators."));
      }
        if(strlen($form_state->getValue('preferred_detector')) < 1) {
            $form_state->setErrorByName('preferred_detector', $this->t("Please inform a preferred name for detectors."));
        }
        if(strlen($form_state->getValue('preferred_process')) < 1) {
            $form_state->setErrorByName('preferred_process', $this->t("Please inform a preferred name for processes."));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {

        // ACCESS CONFIGURATION STATE
        $config = $this->config(static::CONFIGNAME);

        // RETRIEVE TRIGGERING BUTTON
        $triggering_element = $form_state->getTriggeringElement();
        $button_name = $triggering_element['#name'];

        //save confs
        if ($form_state->getValue('preferred_instrument') != null &&
            $form_state->getValue('preferred_instrument') != "" &&
            $form_state->getValue('preferred_actuator') != null &&
            $form_state->getValue('preferred_actuator') != "" &&
            $form_state->getValue('preferred_detector') != null &&
            $form_state->getValue('preferred_detector') != "" &&
            $form_state->getValue('preferred_process') != null &&
            $form_state->getValue('preferred_process') != "") {
          $config->set("preferred_instrument", $form_state->getValue('preferred_instrument'));
          $config->set("preferred_actuator", $form_state->getValue('preferred_actuator'));
          $config->set("preferred_detector", $form_state->getValue('preferred_detector'));
          $config->set("preferred_process", $form_state->getValue('preferred_process'));
          $config->save();
        }

        // BUTTON ACTIONS
        if ($button_name === 'back') {
            $form_state->setRedirectUrl(Url::fromRoute('rep.admin_settings_custom'));
            return;
        }
    }

 }
