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
 use Drupal\Core\File\FileSystemInterface;


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

        // $form['namespace_submit'] = [
        //     '#type' => 'submit',
        //     '#value' => $this->t('Manage NameSpaces'),
        //     '#name' => 'namespace',
        //     '#attributes' => [
        //       'class' => ['btn', 'btn-primary', 'manage_codebookslots'],
        //     ],
        // ];

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

        $form['social_conf'] = [
            '#type' => 'checkbox',
            '#title' => 'Do you want to connect this repository to a Social Knowledge Graph?',
            '#default_value' => $config->get("social_conf"),
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

        // $namespaceSourceMime = "text/turtle";
        // if ($config->get("repository_namespace_source_mime")!= NULL) {
        //     $namespaceSourceMime = $config->get("repository_namespace_source_mime");
        // }
        // $form['repository_namespace_source_mime'] = [
        //     '#type' => 'textfield',
        //     '#title' => 'Mime for Base Namespace',
        //     '#required' => FALSE,
        //     '#default_value' => $namespaceSourceMime,
        // ];

        // // $namespaceSource = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://') . ((($a=$_SERVER['SERVER_ADDR']??'') && $a!=='::1' && $a!=='127.0.0.1' && strpos($a,':')===false) ? $a : (($b=@gethostbyname(@gethostname())) && $b!=='127.0.0.1' && $b!==@gethostname() ? $b : (function(){ $s=@socket_create(AF_INET,SOCK_DGRAM,SOL_UDP); if($s && @socket_connect($s,'8.8.8.8',53)){ @socket_getsockname($s,$n,$p); @socket_close($s); return $n; } return '127.0.0.1'; })())) . '/ont/';
        // $namespaceSource = \Drupal::request()->getScheme().'://'.((($a=$_SERVER['SERVER_ADDR']??'') && $a!=='::1' && $a!=='127.0.0.1' && strpos($a,':')===false)?$a:(($b=@gethostbyname(@gethostname())) && $b!=='127.0.0.1' && $b!==@gethostname()?$b:(function(){ $s=@socket_create(AF_INET,SOCK_DGRAM,SOL_UDP); if($s && @socket_connect($s,'8.8.8.8',53)){ @socket_getsockname($s,$n,$p); @socket_close($s); return $n; } return '127.0.0.1';})())).((($p=\Drupal::request()->getPort()) && !in_array($p,[80,443]))?':'.$p:'').\Drupal::request()->getBasePath().'/ont/';
        // if ($config->get("repository_namespace_source")!= NULL) {
        //     $namespaceSource = $config->get("repository_namespace_source");
        // }
        // $form['repository_namespace_source'] = [
        //     '#type' => 'textfield',
        //     '#title' => 'Source for Base Namespace',
        //     '#required' => FALSE,
        //     '#default_value' => $namespaceSource,
        // ];

        // Read current checkbox state.
        $local = $form_state->getValue('localAppOntology', $config->get('localAppOntology') ?? FALSE);

        // Controller checkbox (keeps AJAX)
        $form['localAppOntology'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Use LocalAPP Ontology file?'),
          '#default_value' => $local,
          '#ajax' => [
            'callback' => '::toggleLocalAppOntology',
            'wrapper'  => 'repo-wrapper',
            'progress' => ['type' => 'throbber'],
          ],
        ];

        // Wrapper updated by AJAX
        $form['repo_wrapper'] = [
          '#type' => 'container',
          '#attributes' => ['id' => 'repo-wrapper'],
        ];

        // Config (settings) values – used when checkbox is OFF
        $configMime   = $config->get('repository_namespace_source_mime') ?? '';
        $configSource = $config->get('repository_namespace_source') ?? '';

        // Local (auto) suggestions – used when checkbox is ON
        $localMime    = $config->get('repository_namespace_source_mime') ?: 'text/turtle';
        $localSource  = \Drupal::request()->getScheme().'://'
          .((($a=$_SERVER['SERVER_ADDR']??'') && $a!=='::1' && $a!=='127.0.0.1' && strpos($a,':')===false)?$a:
            (($b=@gethostbyname(@gethostname())) && $b!=='127.0.0.1' && $b!==@gethostname()?$b:(function(){
              $s=@socket_create(AF_INET,SOCK_DGRAM,SOL_UDP);
              if($s && @socket_connect($s,'8.8.8.8',53)){ @socket_getsockname($s,$n,$p); @socket_close($s); return $n; }
              return '127.0.0.1';
            })()))
          .((($p=\Drupal::request()->getPort()) && !in_array($p,[80,443]))?':'.$p:'')
          .\Drupal::request()->getBasePath().'/ont/';

        // Values stored in settings (non-local baseline)
        $configMime   = (string) ($config->get('repository_namespace_source_mime') ?? '');
        $configSource = (string) ($config->get('repository_namespace_source') ?? '');

        // Current checkbox state (already computed)
        $local = $form_state->getValue('localAppOntology', (bool) $config->get('localAppOntology'));

        // Read current inputs
        $inputMime   = $form_state->getValue('repository_namespace_source_mime');
        $inputSource = $form_state->getValue('repository_namespace_source');

        // Detect if the checkbox triggered this rebuild
        $trigger = $form_state->getTriggeringElement();
        $checkboxToggled = $trigger && (($trigger['#name'] ?? '') === 'localAppOntology');

        if ($checkboxToggled) {
          // Get raw userInput so the next render uses our changes
          $raw = $form_state->getUserInput() ?: [];

          if ($local) {
            // Turned ON → prefill with local suggestions when empty
            if ($inputMime === NULL || $inputMime === '') {
              $form_state->setValue('repository_namespace_source_mime', $localMime);
              $raw['repository_namespace_source_mime'] = $localMime;
            }
            if ($inputSource === NULL || $inputSource === '') {
              $form_state->setValue('repository_namespace_source', $localSource);
              $raw['repository_namespace_source'] = $localSource;
            }
          } else {
            // Turned OFF → if fields still equal local suggestions, clear them
            if ((string) $inputMime === (string) $localMime) {
              $form_state->setValue('repository_namespace_source_mime', '');
              $raw['repository_namespace_source_mime'] = '';
            }
            if ((string) $inputSource === (string) $localSource) {
              $form_state->setValue('repository_namespace_source', '');
              $raw['repository_namespace_source'] = '';
            }
          }

          // Write back so #default_value is ignored in favor of what we set here
          $form_state->setUserInput($raw);

          // Re-read after mutation
          $inputMime   = $form_state->getValue('repository_namespace_source_mime');
          $inputSource = $form_state->getValue('repository_namespace_source');
        }

        // Render editable fields (never disabled)
        $form['repo_wrapper']['repository_namespace_source_mime'] = [
          '#type' => 'textfield',
          '#title' => $this->t('MIME for Base Namespace'),
          '#required' => FALSE,
          // If still NULL (first paint without interaction), pick config when OFF, local when ON
          '#default_value' => $inputMime ?? ($local ? $localMime : $configMime),
        ];

        $form['repo_wrapper']['repository_namespace_source'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Source for Base Namespace'),
          '#required' => FALSE,
          '#default_value' => $inputSource ?? ($local ? $localSource : $configSource),
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

        if ($button_name === 'project') {
          $form_state->setRedirectUrl(Url::fromRoute('rep.admin_associated_project_custom'));
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

        // Persist "localAppOntology" and its dependent fields coherently.
        $config->set('localAppOntology', (bool) $form_state->getValue('localAppOntology'));

        //save confs
        $config->set("rep_home", $form_state->getValue('rep_home'));
        $config->set('sagres_conf', $form_state->getValue('sagres_conf'));
        $config->set('social_conf', $form_state->getValue('social_conf'));
        $config->set("site_label", trim($form_state->getValue('site_label')));
        $config->set("site_name", trim($form_state->getValue('site_name')));
        $config->set("repository_domain_url", trim($form_state->getValue('repository_domain_url')));
        $config->set("repository_namespace_prefix", trim($form_state->getValue('repository_namespace_prefix')));
        $config->set("repository_namespace_url", trim($form_state->getValue('repository_namespace_url')));
        $config->set('repository_namespace_source_mime', trim((string) $form_state->getValue('repository_namespace_source_mime')));
        $config->set('repository_namespace_source', trim((string) $form_state->getValue('repository_namespace_source')));
        $config->set("repository_description", trim($form_state->getValue('repository_description')));
        $config->set("sagres_base_url", $form_state->getValue('sagres_base_url'));
        $config->set("api_url", $form_state->getValue('api_url'));
        $config->set("jwt_secret", $form_state->getValue('jwt_secret'));
        $config->save();

        // --- Ensure private://ont directory and hasco.ttl exist --------------------

        /** @var \Drupal\Core\File\FileSystemInterface $fs */
        $fs = \Drupal::service('file_system');
        $logger = \Drupal::logger('rep');
        $messenger = \Drupal::messenger();

        $dir_uri  = 'private://ont';
        $file_uri = $dir_uri . '/'.$config->get('repository_namespace_prefix').'.ttl';

        try {
          // 1) Ensure the directory exists (create if missing).
          //    prepareDirectory() will create the directory for stream wrappers.
          $created = $fs->prepareDirectory($dir_uri, FileSystemInterface::CREATE_DIRECTORY);
          if (!$created) {
            // Directory may already exist; check realpath to confirm.
            $dir_real = $fs->realpath($dir_uri);
            if ($dir_real === FALSE || !is_dir($dir_real)) {
              $logger->error('Could not create or access directory {dir}', ['dir' => $dir_uri]);
              // Not fatal for the form, but we notify the user.
              $messenger->addError($this->t('Could not create/access the directory %dir.', ['%dir' => $dir_uri]));
            }
          }

          // 2) Try to enforce 0755 on the directory (no-op on Windows).
          $dir_real = $fs->realpath($dir_uri);
          if ($dir_real && is_dir($dir_real)) {
            // On Windows this may have no effect; do not treat failure as fatal.
            @chmod($dir_real, 0755);
          }

          // 3) Ensure the file exists; if missing, create an empty TTL file.
          $file_real = $fs->realpath($file_uri);
          if ($file_real === FALSE || !file_exists($file_real)) {
            // saveData() creates a file for stream wrappers; write empty content.
            $saved_uri = $fs->saveData('', $file_uri, FileSystemInterface::EXISTS_ERROR);
            if ($saved_uri === FALSE) {
              $logger->error('Failed to create file {file}', ['file' => $file_uri]);
              $messenger->addError($this->t('Failed to create %file.', ['%file' => $file_uri]));
            } else {
              // Optional: set 0644 on the file (again, no-op on Windows).
              $file_real = $fs->realpath($file_uri);
              if ($file_real) {
                @chmod($file_real, 0644);
              }
              $logger->notice('Created ontology file at {file}', ['file' => $file_uri]);
              // You may show a gentle info message if you want:
              // $messenger->addStatus($this->t('Created %file.', ['%file' => $file_uri]));
            }
          }
        }
        catch (\Throwable $e) {
          // Catch-all to avoid breaking the submit flow.
          $logger->error('Error ensuring private://ont and hasco.ttl: {msg}', ['msg' => $e->getMessage()]);
          $messenger->addError($this->t('Error preparing ontology storage: %msg', ['%msg' => $e->getMessage()]));
        }

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
        if ($resp !== '') {
          $messenger->addMessage($this->t('Your new rep configuration has been saved'));
        } else {
          $messenger->addError($this->t('Failed to set rep configuration. Message: [@resp]', ['@resp' => $resp]));
        }

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
            $key = $user['acc_repo_instance'] . ':' . $user['acc_id'];
            $sguser_map[$key] = $user;
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
                'acc_user_uri' => (\Drupal::request()->headers->get('x-forwarded-proto') === 'https' ? 'https://':'http://'). \Drupal::request()->getHost() . \Drupal::request()->getBaseUrl() . '/user/' . $user->id(),
                'acc_cellphone' => $user->hasField('field_cellphone') && !$user->get('field_cellphone')->isEmpty()
                    ? (int) $user->get('field_cellphone')->value
                    : null,
            ];

            $user_key = $repo_instance . ':' . $user->id();

            if (isset($sguser_map[$user_key])) {
                // Usuário já existe no sguser, verificar necessidade de atualização
                $existing_user = $sguser_map[$user_key];

                if (trim((string) $existing_user['acc_name']) !== trim((string) $user_data['acc_name']) || trim((string) $existing_user['acc_email']) !== trim((string) $user_data['acc_email']) || (string) $existing_user['acc_cellphone'] !== (string) $user_data['acc_cellphone']) {
                    try {
                        $response = \Drupal::httpClient()->patch("{$sagres_base_url}/sguser/account/update", [
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
                    $response = \Drupal::httpClient()->post("{$sagres_base_url}/sguser/account/add", [
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

    public function toggleLocalAppOntology(array &$form, FormStateInterface $form_state) {
      // Force a rebuild so #default_value / user input injection takes effect.
      $form_state->setRebuild(TRUE);
      return $form['repo_wrapper'];
    }


}
