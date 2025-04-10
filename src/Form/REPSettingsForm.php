<?php

/**
 * @file
 * Contains the settings for admninistering the rep Module
 */

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\ConfigFormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\Core\Url;
 use Drupal\rep\Constant;


 class REPSettingsForm extends ConfigFormBase {

     /**
     * Settings Variable.
     */
    Const CONFIGNAME = "rep.settings";

     /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "rep_form_settings";
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

        $home = "";
        if ($config->get("rep_home")!= NULL) {
            $home = $config->get("rep_home");
        }

        $form['namespace_submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Manage NameSpaces'),
            '#name' => 'namespace',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'manage_codebookslots'],
            ],
        ];

        $form['preferred_names_submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Preferred Names'),
            '#name' => 'preferred',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'bookmark-button'],
            ],
        ];

        $form['sync_with_sagres'] = [
            '#type' => 'submit',
            '#value' => $this->t('Synchronize Users with Sagres'),
            '#name' => 'sync_sagres',
            '#attributes' => [
                'class' => ['btn', 'btn-warning'],
            ],
        ];

        $form['rep_home'] = [
            '#type' => 'checkbox',
            '#title' => 'Do you want rep to be the home (first page) of the Drupal?',
            '#default_value' => $home,
        ];

        $form['sagres_conf'] = [
            '#type' => 'checkbox',
            '#title' => 'Do you want to connect this repository to Sagres?',
            '#default_value' => $config->get("sagres_conf"),
        ];

        $shortName = "";
        if ($config->get("site_label")!= NULL) {
            $shortName = $config->get("site_label");
        }
        $form['site_label'] = [
            '#type' => 'textfield',
            '#title' => 'Repository Short Name (ex. "ChildFIRST")',
            '#default_value' => $shortName,
        ];

        $fullName = "";
        if ($config->get("site_name")!= NULL) {
            $fullName = $config->get("site_name");
        }
        $form['site_name'] = [
            '#type' => 'textfield',
            '#title' => 'Repository Full Name (ex. "ChildFIRST: Focus on Innovation")',
            '#default_value' => $fullName,
            '#description' => 'This value is the website name.',
        ];

        $domainUrl = "";
        if ($config->get("repository_domain_url")!= NULL) {
            $domainUrl = $config->get("repository_domain_url");
        }
        $form['repository_domain_url'] = [
            '#type' => 'textfield',
            '#title' => 'Repository URL (ex: http://childfirst.ucla.edu, http://tw.rpi.edu, etc.)',
            '#required' => TRUE,
            '#default_value' => $domainUrl,
            '#description' => 'The main URL to access the Repository',
        ];

        $namespacePrefix = "";
        if ($config->get("repository_namespace_prefix")!= NULL) {
            $namespacePrefix = $config->get("repository_namespace_prefix");
        }
        $form['repository_namespace_prefix'] = [
            '#type' => 'textfield',
            '#title' => 'Prefix for Base Namespace (ex: ufmg, ucla, rpi, etc.)',
            '#required' => TRUE,
            '#default_value' => $namespacePrefix,
        ];

        $namespaceUrl = "";
        if ($config->get("repository_namespace_url")!= NULL) {
            $namespaceUrl = $config->get("repository_namespace_url");
        }
        $form['repository_namespace_url'] = [
            '#type' => 'textfield',
            '#title' => 'URL for Base Namespace',
            '#required' => TRUE,
            '#default_value' => $namespaceUrl,
            '#description' => 'This value is used to compose the URL of rep elements created within this repository',
        ];

        $namespaceSourceMime = "";
        if ($config->get("repository_namespace_source_mime")!= NULL) {
            $namespaceSourceMime = $config->get("repository_namespace_source_mime");
        }
        $form['repository_namespace_source_mime'] = [
            '#type' => 'textfield',
            '#title' => 'Mime for Base Namespace',
            '#required' => FALSE,
            '#default_value' => $namespaceSourceMime,
        ];

        $namespaceSource = "";
        if ($config->get("repository_namespace_source")!= NULL) {
            $namespaceSource = $config->get("repository_namespace_source");
        }
        $form['repository_namespace_source'] = [
            '#type' => 'textfield',
            '#title' => 'Source for Base Namespace',
            '#required' => FALSE,
            '#default_value' => $namespaceSource,
        ];

        $description = "";
        if ($config->get("repository_description")!= NULL) {
            $description = $config->get("repository_description");
        }
        $form['repository_description'] = [
            '#type' => 'textarea',
            '#title' => ' description for the repository that appears in the rep APIs GUI',
            '#required' => TRUE,
            '#default_value' => $description,
        ];

        $form['sagres_base_url'] = [
            '#type' => 'textfield',
            '#title' => 'Sagres Base URL',
            '#default_value' => $config->get("sagres_base_url") ?? 'https://52.214.194.214',
            '#description' => 'Sagres Base URL for Users Synchronization.',
        ];

        $sagres_token = \Drupal::service('request_stack')->getCurrentRequest()->getSession()->get('oauth_access_token');
        $form['sagres_access_token'] = [
            '#type' => 'item',
            '#title' => $this->t('Sagres Access Token'),
            '#markup' => $sagres_token ? $sagres_token : $this->t('No token found'),
        ];
        
        $form['api_url'] = [
            '#type' => 'textfield',
            '#title' => 'rep API Base URL',
            '#default_value' => $config->get("api_url"),
        ];

        //$keys = \Drupal::service('key.repository')->getKeys();
        //var_dump($keys);

        //$key_value = '';
        //$key_entity = \Drupal::service('key.repository')->getKey('jwt');
        //if ($key_entity != NULL && $key_entity->getKeyValue() != NULL) {
        //    $key_value = $key_entity->getKeyValue();
        //}

        $form['jwt_secret'] = [
            '#type' => 'key_select',
            '#title' => 'JWT Secret',
            '#key_filters' => ['type' => 'authentication'],
            '#default_value' => $config->get("jwt_secret"),
        ];       

        $form['filler_1'] = [
            '#type' => 'item',
            '#title' => $this->t('<br>'),
        ];

        $form['filler_2'] = [
            '#type' => 'item',
            '#title' => $this->t('<br>'),
        ];

        return Parent::buildForm($form, $form_state);


     }

    public function validateForm(array &$form, FormStateInterface $form_state) {
        if(strlen($form_state->getValue('site_label')) < 1) {
            $form_state->setErrorByName('site_label', $this->t("Please inform repository's short name."));
        }
        if(strlen($form_state->getValue('site_name')) < 1) {
            $form_state->setErrorByName('site_name', $this->t("Please inform repository's full name."));
        }
        if(strlen($form_state->getValue('repository_domain_url')) < 1) {
            $form_state->setErrorByName('repository_domain_url', $this->t("Please inform repository's Domain URL."));
        } else {
            if ((strtolower(substr($form_state->getValue('repository_domain_url'), 0, 7)) !== "http://") &&
                (strtolower(substr($form_state->getValue('repository_domain_url'), 0, 8)) !== "https://")) {
                $form_state->setErrorByName('repository_domain_url', $this->t("Domain URL must start with 'http://' or 'https://'."));
            }
        }
        if(strlen($form_state->getValue('repository_namespace_prefix')) < 1) {
            $form_state->setErrorByName('repository_namespace_prefix', $this->t("Please inform repository's Namespace Prefix."));
        //} else if (strlen($form_state->getValue('repository_namespace_prefix')) > 10) {
        //    $form_state->setErrorByName('repository_namespace_prefix', $this->t("Domain Namespace cannot have more than 10 characters"));
        } else if (!preg_match('/^[a-zA-Z0-9\-]+$/', $form_state->getValue('repository_namespace_prefix'))) {
            $form_state->setErrorByName('repository_namespace_prefix', $this->t("Namespace prefix can only have letters, numbers and '-'."));
        }
        if(strlen($form_state->getValue('repository_namespace_url')) < 1) {
            $form_state->setErrorByName('repository_namespace_url', $this->t("Please inform repository's Namespace URL."));
        } else {
            if ((strtolower(substr($form_state->getValue('repository_namespace_url'), 0, 7)) !== "http://") &&
                (strtolower(substr($form_state->getValue('repository_namespace_url'), 0, 8)) !== "https://")) {
                $form_state->setErrorByName('repository_namespace_url', $this->t("Namespace URL must start with 'http://' or 'https://'."));
            }
        }
   }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $triggering_element = $form_state->getTriggeringElement();
        $button_name = $triggering_element['#name'];
        \Drupal::logger('rep')->notice('Botão pressionado: ' . $button_name);
        if ($button_name === 'namespace') {
          $form_state->setRedirectUrl(Url::fromRoute('rep.admin_namespace_settings_custom'));
          return;
        }

        if ($button_name === 'preferred') {
          $form_state->setRedirectUrl(Url::fromRoute('rep.admin_preferred_names_custom'));
          return;
        }

        if ($button_name === 'sync_sagres') {
            \Drupal::logger('rep')->notice('Chamando syncUsersWithSagres()');
            $this->syncUsersWithSagres();
            $messenger = \Drupal::service('messenger');
            $messenger->addMessage($this->t('User synchronization with Sagres completed!'));
            return;
        }        

          $config = $this->config(static::CONFIGNAME);

        //save confs
        $config->set("rep_home", $form_state->getValue('rep_home'));
        $config->set('sagres_conf', $form_state->getValue('sagres_conf'));
        $config->set("site_label", trim($form_state->getValue('site_label')));
        $config->set("site_name", trim($form_state->getValue('site_name')));
        $config->set("repository_domain_url", trim($form_state->getValue('repository_domain_url')));
        $config->set("repository_namespace_prefix", trim($form_state->getValue('repository_namespace_prefix')));
        $config->set("repository_namespace_url", trim($form_state->getValue('repository_namespace_url')));
        $config->set("repository_namespace_source_mime", trim($form_state->getValue('repository_namespace_source_mime')));
        $config->set("repository_namespace_source", trim($form_state->getValue('repository_namespace_source')));
        $config->set("repository_description", trim($form_state->getValue('repository_description')));
        $config->set("sagres_base_url", $form_state->getValue('sagres_base_url'));
        $config->set("api_url", $form_state->getValue('api_url'));
        $config->set("jwt_secret", $form_state->getValue('jwt_secret'));
        $config->save();
 
        //site name
        $configdrupal = \Drupal::service('config.factory')->getEditable('system.site');
        $configdrupal->set('name', $form_state->getValue('site_name'));
        $configdrupal->save();

        //update Repository configuration
        $api = \Drupal::service('rep.api_connector');

        $resp = '';
        //label
        $resp .= $api->repoUpdateLabel(
            $form_state->getValue('api_url'),
            $form_state->getValue('site_label'));

        //title
        $resp .= $api->repoUpdateTitle(
            $form_state->getValue('api_url'),
            $form_state->getValue('site_name'));

        //domain URL
        $resp .= $api->repoUpdateURL(
            $form_state->getValue('api_url'),
            $form_state->getValue('repository_domain_url'));

        //description
        $resp .= $api->repoUpdateDescription(
            $form_state->getValue('api_url'),
            $form_state->getValue('repository_description'));

        //namespace
        $resp .= $api->repoUpdateNamespace(
            $form_state->getValue('api_url'),
            $form_state->getValue('repository_namespace_prefix'),
            $form_state->getValue('repository_namespace_url'),
            $form_state->getValue('repository_namespace_source_mime'),
            $form_state->getValue('repository_namespace_source'));

        // Save the filename in configuration.
        //$this->config('rep.settings')
        //  ->set('svg_file', $file_id)
        //  ->save();

        $messenger = \Drupal::service('messenger');
        $messenger->addMessage($this->t('Your new rep configuration has been saved [' . $resp . ']'));

        $url = Url::fromRoute('rep.repo_info');
        $form_state->setRedirectUrl($url);

    }

    private function syncUsersWithSagres() {
        $config = $this->config(static::CONFIGNAME);
        $sagres_base_url = $config->get("sagres_base_url");
        $sagres_token = \Drupal::service('request_stack')->getCurrentRequest()->getSession()->get('oauth_access_token');
    
        if (!$sagres_token) {
            \Drupal::logger('rep')->error("Token não encontrado na sessão.");
            return;
        }

        $repo_instance = \Drupal::request()->getHost();
        \Drupal::logger('rep')->notice('Iniciando sincronização de utilizadores...');

        \Drupal::logger('rep')->notice("Sagres Base Url: " . $sagres_base_url);

        // Obter a lista de usuários do sguser de uma só vez
        try {
            $response = \Drupal::httpClient()->get("{$sagres_base_url}/sguser/account/list", [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer {$sagres_token}"
                ],
            ]);
            $sguser_users = json_decode($response->getBody(), true);
            \Drupal::logger('rep')->notice('Lista de utilizadores obtida com sucesso');
        } catch (\Exception $e) {
            \Drupal::logger('rep')->error("Erro ao obter lista de utilizadores do sguser: " . $e->getMessage());
            return;
        }
    
        // Criar um mapa de usuários do sguser para facilitar a comparação
        $sguser_map = [];
        foreach ($sguser_users as $user) {
            $sguser_map[$user['acc_id']] = $user;
        }
    
        $users_created = 0;
        $users_updated = 0;
        
        $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple();
    
        foreach ($users as $user) {

            if ($user->id() == 0 || $user->isBlocked()) {
                \Drupal::logger('rep')->notice("Utilizador ignorado: " . $user->id());
                continue;
            }

            \Drupal::logger('rep')->notice("Processando utilizador: " . $user->getEmail());

            $user_data = [
                'acc_id' => $user->id(),
                'acc_repo_instance' => $repo_instance,
                'acc_name' => $user->getDisplayName(),
                'acc_email' => $user->getEmail(),
                'acc_user_uri' => \Drupal::request()->getSchemeAndHttpHost() . '/user/' . $user->id(),
            ];
    
            if (isset($sguser_map[$user->id()])) {
                // Usuário já existe no sguser, verificar necessidade de atualização
                $existing_user = $sguser_map[$user->id()];
    
                if ($existing_user['acc_name'] !== $user_data['acc_name'] || $existing_user['acc_email'] !== $user_data['acc_email']) {
                    try {
                        \Drupal::httpClient()->patch("{$sagres_base_url}/sguser/account/update", [
                            'json' => $user_data,
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'Authorization' => "Bearer {$sagres_token}"
                            ],
                        ]);

                        if ($response->getStatusCode() === 200) {
                            $users_updated++;
                        } else {
                            \Drupal::logger('rep')->error("Erro ao atualizar utilizador {$user->id()}. Status: " . $response->getStatusCode());
                        }

                    } catch (\Exception $e) {
                        \Drupal::logger('rep')->error("Erro ao atualizar utilizador {$user->id()}: " . $e->getMessage());
                    }
                }

            } else {

                try {
                    \Drupal::httpClient()->post("{$sagres_base_url}/sguser/account/add", [
                        'json' => $user_data,
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Authorization' => "Bearer {$sagres_token}"
                        ],
                    ]);

                    if ($response->getStatusCode() === 201) {
                        $users_created++;
                    } else {
                        \Drupal::logger('rep')->error("Erro ao criar utilizador {$user->id()}. Status: " . $response->getStatusCode());
                    }

                } catch (\Exception $e) {
                    \Drupal::logger('rep')->error("Erro ao criar utilizador {$user->id()}: " . $e->getMessage());
                }
            }
        }
    
        \Drupal::messenger()->addMessage("Sincronização concluída: $users_created utilizadores criados, $users_updated atualizados.");
    }
}
