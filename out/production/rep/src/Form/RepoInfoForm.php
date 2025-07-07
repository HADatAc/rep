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

 class RepoInfoForm extends FormBase {

     /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "repo_info";
    }

     /**
     * {@inheritdoc}
     */
     public function buildForm(array $form, FormStateInterface $form_state){

        // SET SERVICES
        $messenger = \Drupal::service('messenger');
        $APIservice = \Drupal::service('rep.api_connector');

        // RETRIEVE CONFIGURATION FROM CURRENT IP
        $repoObj = $APIservice->parseObjectResponse($APIservice->repoInfo(),'repoInfo');
        if ($repoObj != NULL) {
            //dpm($repoObj);
            $label = $repoObj->label;
            $name = $repoObj->title;
            $domainUrl = $repoObj->hasDomainURL;
            $namespaceUrl = $repoObj->hasDefaultNamespaceURL;
            $namespacePrefix = $repoObj->hasDefaultNamespacePrefix;
            $namespaceSourceMime = $repoObj->hasDefaultNamespaceSourceMime;
            $namespaceSource = $repoObj->hasDefaultNamespaceSource;
            $description = $repoObj->comment;
        } else {
            $label = "";
            $name = "<<FAILED TO LOAD CONFIGURATION>>";
            $domainUrl = "";
            $namespaceUrl = "";
            $namespaceSourceMime = "";
            $namespaceSource = "";
            $description = "";
        }

        $form['site_label'] = [
            '#type' => 'textfield',
            '#title' => 'Repository Short Name',
            '#default_value' => $label,
            '#disabled' => TRUE,
        ];

        $form['site_name'] = [
            '#type' => 'textfield',
            '#title' => 'Repository Full Name',
            '#default_value' => $name,
            '#disabled' => TRUE,
        ];

        $form['repository_domain_url'] = [
            '#type' => 'textfield',
            '#title' => 'Repository URL',
            '#default_value' => $domainUrl,
            '#disabled' => TRUE,
        ];

        $form['repository_namespace_url'] = [
            '#type' => 'textfield',
            '#title' => 'URL for Base Namespace',
            '#default_value' => $namespaceUrl,
            '#disabled' => TRUE,
        ];

        $form['repository_namespace_prefix'] = [
            '#type' => 'textfield',
            '#title' => 'Prefix for Base Namespace',
            '#default_value' => $namespacePrefix,
            '#disabled' => TRUE,
        ];

        $form['repository_namespace_source_mime'] = [
            '#type' => 'textfield',
            '#title' => 'Mime for Base Namespace',
            '#default_value' => $namespaceSourceMime,
            '#disabled' => TRUE,
        ];

        $form['repository_namespace_source'] = [
            '#type' => 'textfield',
            '#title' => 'Source for Base Namespace',
            '#default_value' => $namespaceSource,
            '#disabled' => TRUE,
        ];

        $form['repository_description'] = [
            '#type' => 'textarea',
            '#title' => ' description for the repository that appears in the rep APIs GUI',
            '#default_value' => $description,
            '#disabled' => TRUE,
        ];

        $form['api_url'] = [
            '#type' => 'textfield',
            '#title' => 'rep API Base URL',
            '#default_value' => Utils::configApiUrl(),
            '#disabled' => TRUE,
        ];
        $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Back'),
            '#name' => 'back',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'back-button'],
            ],
        ];
        $form['space'] = [
            '#type' => 'label',
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
        $url = Url::fromRoute('rep.home');
        $form_state->setRedirectUrl($url);
    }

 }
