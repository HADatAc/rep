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

 class REPProjectInfoForm extends ConfigFormBase {

     /**
     * Settings Variable.
     */
    Const CONFIGNAME = "rep.settings";

     /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "rep_form_project_info";
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

        $host_organization = "";
        if ($config->get("host_organization")!= NULL) {
            $host_organization = $config->get("host_organization");
        }
        $form['host_organization'] = [
            '#type' => 'textfield',
            '#title' => $this->t("Host Organization's URI"),
            '#default_value' => $host_organization,
        ];

        $associated_project = "";
        if ($config->get("associated_project")!= NULL) {
            $associated_project = $config->get("associated_project");
        }
        $form['associated_project'] = [
            '#type' => 'textfield',
            '#title' => $this->t("Associated Project's URI"),
            '#default_value' => $associated_project,
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
        if ($form_state->getValue('host_organization') != null &&
            $form_state->getValue('host_organization') != "" &&
            $form_state->getValue('associated_project') != null &&
            $form_state->getValue('associated_project') != "") {
          $config->set("host_organization", $form_state->getValue('host_organization'));
          $config->set("associated_project", $form_state->getValue('associated_project'));
          $config->save();
        }

        // BUTTON ACTIONS
        if ($button_name === 'back') {
            $form_state->setRedirectUrl(Url::fromRoute('rep.admin_settings_custom'));
            return;
        }
    }

 }
