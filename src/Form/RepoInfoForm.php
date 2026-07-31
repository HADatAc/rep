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

        $config = \Drupal::config('rep.settings');

        // RETRIEVE CONFIGURATION FROM CURRENT IP
        $repoObj = $APIservice->parseObjectResponse($APIservice->repoInfo(),'repoInfo');
        if ($repoObj != NULL) {
            //dpm($repoObj);
            $label = isset($repoObj->label) && $repoObj->label !== '' ? $repoObj->label : (string) ($config->get('site_label') ?? '');
            $name = isset($repoObj->title) && $repoObj->title !== '' ? $repoObj->title : (string) ($config->get('site_name') ?? '');
            $domainUrl = isset($repoObj->hasDomainURL) && $repoObj->hasDomainURL !== '' ? $repoObj->hasDomainURL : (string) ($config->get('repository_domain_url') ?? '');
            $namespaceUrl = isset($repoObj->hasDefaultNamespaceURL) && $repoObj->hasDefaultNamespaceURL !== '' ? $repoObj->hasDefaultNamespaceURL : (string) ($config->get('repository_namespace_url') ?? '');
            $namespacePrefix = isset($repoObj->hasDefaultNamespacePrefix) && $repoObj->hasDefaultNamespacePrefix !== '' ? $repoObj->hasDefaultNamespacePrefix : (string) ($config->get('repository_namespace_prefix') ?? '');
            $namespaceSourceMime = isset($repoObj->hasDefaultNamespaceSourceMime) && $repoObj->hasDefaultNamespaceSourceMime !== '' ? $repoObj->hasDefaultNamespaceSourceMime : (string) ($config->get('repository_namespace_source_mime') ?? '');
            $namespaceSource = isset($repoObj->hasDefaultNamespaceSource) && $repoObj->hasDefaultNamespaceSource !== '' ? $repoObj->hasDefaultNamespaceSource : (string) ($config->get('repository_namespace_source') ?? '');
            $description = isset($repoObj->comment) && $repoObj->comment !== '' ? $repoObj->comment : (string) ($config->get('repository_description') ?? '');
        } else {
            $label = (string) ($config->get('site_label') ?? '');
            $name = (string) ($config->get('site_name') ?? '<<FAILED TO LOAD CONFIGURATION>>');
            $domainUrl = (string) ($config->get('repository_domain_url') ?? '');
            $namespaceUrl = (string) ($config->get('repository_namespace_url') ?? '');
            $namespacePrefix = (string) ($config->get('repository_namespace_prefix') ?? '');
            $namespaceSourceMime = (string) ($config->get('repository_namespace_source_mime') ?? '');
            $namespaceSource = (string) ($config->get('repository_namespace_source') ?? '');
            $description = (string) ($config->get('repository_description') ?? '');
            $messenger->addWarning($this->t('Could not retrieve repository configuration from API. Showing locally saved settings values.'));
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
