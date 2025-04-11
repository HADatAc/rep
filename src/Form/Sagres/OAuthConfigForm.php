<?php

/**
 * @file
 * Contains the settings for admninistering the rep Module
 */

 namespace Drupal\rep\Form\Sagres;

 use Drupal\Core\Form\ConfigFormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\Core\Url;
 use Drupal\rep\Constant;

 class OAuthConfigForm extends ConfigFormBase {

     /**
     * Settings Variable.
     */
     /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "rep_oauth_config_form";
    }

    /**
     * {@inheritdoc}
     */

    protected function getEditableConfigNames() {
        return [
            Constant::CONFIG_SAGRES
        ];
    }

     /**
     * {@inheritdoc}
     */

     public function buildForm(array $form, FormStateInterface $form_state){
        $config = $this->config(Constant::CONFIG_SAGRES);

        $oauth_url = "";
        if ($config->get("oauth_url") != NULL) {
            $oauth_url = $config->get("oauth_url");
        }

        $client_id = "";
        if ($config->get("client_id")!= NULL) {
            $client_id = $config->get("client_id");
        }

        $client_secret = "";
        if ($config->get("client_secret")!= NULL) {
            $client_secret = $config->get("client_secret");
        }

        $form['oauth_url'] = [
            '#type' => 'textfield',
            '#title' => 'OAuth Authorization Server URL',
            '#default_value' => $oauth_url,
        ];

        $form['client_id'] = [
            '#type' => 'textfield',
            '#title' => 'Client ID',
            '#default_value' => $client_id,
        ];

        $form['client_secret'] = [
            '#type' => 'textfield',
            '#title' => 'Client Secret',
            '#default_value' => $client_secret,
        ];


        $form['filler_1'] = [
            '#type' => 'item',
            '#title' => $this->t('<br>'),
        ];

        return Parent::buildForm($form, $form_state);


     }

    public function validateForm(array &$form, FormStateInterface $form_state) {
        if(strlen($form_state->getValue('oauth_url')) < 1) {
            $form_state->setErrorByName('oauth_url', $this->t("Please inform OAuth Authorization Server's URL."));
        }
        if(strlen($form_state->getValue('client_id')) < 1) {
            $form_state->setErrorByName('client_id', $this->t("Please inform client's ID."));
        }
        if(strlen($form_state->getValue('client_secret')) < 1) {
            $form_state->setErrorByName('client_secret', $this->t("Please inform client's secret."));
        }
   }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $triggering_element = $form_state->getTriggeringElement();
        $button_name = $triggering_element['#name'];

        #if ($button_name === 'namespace') {
        #  $form_state->setRedirectUrl(Url::fromRoute('rep.admin_namespace_settings_custom'));
        #  return;
        #}

        $config = $this->config(Constant::CONFIG_SAGRES);

        //save confs
        $config->set("oauth_url", $form_state->getValue('oauth_url'));
        $config->set("client_id", $form_state->getValue('client_id'));
        $config->set("client_secret", trim($form_state->getValue('client_secret')));
        $config->save();

        $messenger = \Drupal::service('messenger');
        $messenger->addMessage($this->t('OAuth configuration has been saved.'));

        $url = Url::fromRoute('rep.sagres.status_form');
        $form_state->setRedirectUrl($url);

    }

 }
