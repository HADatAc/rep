<?php

/**
 * @file
 * Contains the main settings form for administering the REP module.
 */

namespace Drupal\rep\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\File\FileSystemInterface;

/**
 * Class REPSettingsForm.
 *
 * Provides the main configuration form for the REP module.
 */
class REPSettingsForm extends ConfigFormBase {

  /**
   * Configuration name used by this form.
   */
  const CONFIGNAME = 'rep.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_form_settings';
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
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::CONFIGNAME);

    $home = '';
    if ($config->get('rep_home') != NULL) {
      $home = $config->get('rep_home');
    }

    // Button to navigate to the "Preferred Names" configuration form.
    $form['preferred_names_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preferred Names'),
      '#name' => 'preferred',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'bookmark-button'],
      ],
    ];

    // Button to trigger synchronization of users with Sagres.
    $form['sync_with_sagres'] = [
      '#type' => 'submit',
      '#value' => $this->t('Synchronize Users with Sagres'),
      '#name' => 'sync_sagres',
      '#attributes' => [
        'class' => ['btn', 'btn-warning'],
      ],
    ];

    // Button to navigate to the "Associated Project" configuration form.
    $form['project_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Associated Project'),
      '#name' => 'project',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'bookmark-button'],
      ],
    ];

    // Whether REP should be used as the Drupal front page.
    $form['rep_home'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Do you want REP to be the home (first page) of Drupal?'),
      '#default_value' => $home,
    ];

    // Whether Sagres integration is enabled.
    $form['sagres_conf'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Do you want to connect this repository to Sagres?'),
      '#default_value' => $config->get('sagres_conf'),
    ];

    // Whether Social Knowledge Graph integration is enabled.
    $form['social_conf'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Do you want to connect this repository to a Social Knowledge Graph?'),
      '#default_value' => $config->get('social_conf'),
    ];

    // Short name of the repository.
    $shortName = '';
    if ($config->get('site_label') != NULL) {
      $shortName = $config->get('site_label');
    }
    $form['site_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Short Name (e.g. "ChildFIRST")'),
      '#default_value' => $shortName,
    ];

    // Full name of the repository.
    $fullName = '';
    if ($config->get('site_name') != NULL) {
      $fullName = $config->get('site_name');
    }
    $form['site_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Full Name (e.g. "ChildFIRST: Focus on Innovation")'),
      '#default_value' => $fullName,
      '#description' => $this->t('This value is the website name.'),
    ];

    // Public domain URL of the repository.
    $domainUrl = '';
    if ($config->get('repository_domain_url') != NULL) {
      $domainUrl = $config->get('repository_domain_url');
    }
    $form['repository_domain_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository URL (e.g. http://childfirst.ucla.edu, http://tw.rpi.edu, etc.)'),
      '#required' => TRUE,
      '#default_value' => $domainUrl,
      '#description' => $this->t('The main URL to access the repository.'),
    ];

    // Namespace prefix (e.g., ufmg, ucla, rpi, etc.).
    $namespacePrefix = '';
    if ($config->get('repository_namespace_prefix') != NULL) {
      $namespacePrefix = $config->get('repository_namespace_prefix');
    }
    $form['repository_namespace_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Prefix for Base Namespace (e.g. ufmg, ucla, rpi, etc.)'),
      '#required' => TRUE,
      '#default_value' => $namespacePrefix,
    ];

    // Namespace URL where the repository vocabularies/ontologies are hosted.
    $namespaceUrl = '';
    if ($config->get('repository_namespace_url') != NULL) {
      $namespaceUrl = $config->get('repository_namespace_url');
    }
    $form['repository_namespace_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL for Base Namespace'),
      '#required' => TRUE,
      '#default_value' => $namespaceUrl,
      '#description' => $this->t('This value is used to compose the URL of REP elements created within this repository.'),
    ];

    // Human-readable description of the repository (used in APIs / GUI).
    $description = '';
    if ($config->get('repository_description') != NULL) {
      $description = $config->get('repository_description');
    }
    $form['repository_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description for the repository that appears in the REP APIs GUI'),
      '#required' => TRUE,
      '#default_value' => $description,
    ];

    // Sagres base URL configuration.
    $form['sagres_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sagres Base URL'),
      '#default_value' => $config->get('sagres_base_url') ?? 'https://52.214.194.214',
      '#description' => $this->t('Sagres Base URL for user synchronization.'),
    ];

    // Base URL for the REP API.
    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('REP API Base URL'),
      '#default_value' => $config->get('api_url'),
    ];

    // JWT secret selection (using the Key module).
    $form['jwt_secret'] = [
      '#type' => 'key_select',
      '#title' => $this->t('JWT Secret'),
      '#key_filters' => ['type' => 'authentication'],
      '#default_value' => $config->get('jwt_secret'),
    ];

    // Simple fillers to add vertical spacing in the form.
    $form['filler_1'] = [
      '#type' => 'item',
      '#title' => $this->t('<br>'),
    ];

    $form['filler_2'] = [
      '#type' => 'item',
      '#title' => $this->t('<br>'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (strlen($form_state->getValue('site_label')) < 1) {
      $form_state->setErrorByName('site_label', $this->t("Please inform the repository's short name."));
    }
    if (strlen($form_state->getValue('site_name')) < 1) {
      $form_state->setErrorByName('site_name', $this->t("Please inform the repository's full name."));
    }
    if (strlen($form_state->getValue('repository_domain_url')) < 1) {
      $form_state->setErrorByName('repository_domain_url', $this->t("Please inform the repository's Domain URL."));
    }
    else {
      if ((strtolower(substr($form_state->getValue('repository_domain_url'), 0, 7)) !== 'http://') &&
        (strtolower(substr($form_state->getValue('repository_domain_url'), 0, 8)) !== 'https://')) {
        $form_state->setErrorByName('repository_domain_url', $this->t("Domain URL must start with 'http://' or 'https://'."));
      }
    }
    if (strlen($form_state->getValue('repository_namespace_prefix')) < 1) {
      $form_state->setErrorByName('repository_namespace_prefix', $this->t("Please inform the repository's Namespace Prefix."));
    }
    else if (!preg_match('/^[a-zA-Z0-9\-]+$/', $form_state->getValue('repository_namespace_prefix'))) {
      $form_state->setErrorByName('repository_namespace_prefix', $this->t("Namespace prefix can only contain letters, numbers and '-'."));
    }
    if (strlen($form_state->getValue('repository_namespace_url')) < 1) {
      $form_state->setErrorByName('repository_namespace_url', $this->t("Please inform the repository's Namespace URL."));
    }
    else {
      if ((strtolower(substr($form_state->getValue('repository_namespace_url'), 0, 7)) !== 'http://') &&
        (strtolower(substr($form_state->getValue('repository_namespace_url'), 0, 8)) !== 'https://')) {
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

    \Drupal::logger('rep')->notice('Button clicked: ' . $button_name);

    // Redirect-only buttons (do not save configuration here).
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

    // Button to trigger Sagres synchronization (no config save).
    if ($button_name === 'sync_sagres') {
      \Drupal::logger('rep')->notice('Calling syncUsersWithSagres().');
      $this->syncUsersWithSagres();
      $messenger = \Drupal::service('messenger');
      $messenger->addMessage($this->t('User synchronization with Sagres completed!'));
      return;
    }

    // From here on we are actually saving configuration.
    $config = $this->config(static::CONFIGNAME);

    // Save configuration values.
    $config->set('rep_home', $form_state->getValue('rep_home'));
    $config->set('sagres_conf', $form_state->getValue('sagres_conf'));
    $config->set('social_conf', $form_state->getValue('social_conf'));
    $config->set('site_label', trim($form_state->getValue('site_label')));
    $config->set('site_name', trim($form_state->getValue('site_name')));
    $config->set('repository_domain_url', trim($form_state->getValue('repository_domain_url')));
    $config->set('repository_namespace_prefix', trim($form_state->getValue('repository_namespace_prefix')));
    $config->set('repository_namespace_url', trim($form_state->getValue('repository_namespace_url')));
    $config->set('repository_description', trim($form_state->getValue('repository_description')));
    $config->set('sagres_base_url', $form_state->getValue('sagres_base_url'));
    $config->set('api_url', $form_state->getValue('api_url'));
    $config->set('jwt_secret', $form_state->getValue('jwt_secret'));
    $config->save();

    // IMPORTANT:
    // Rebuild menu links so that any changes in REP configuration which affect
    // menus (e.g. Sagres/Social toggles, preferred labels used by
    // rep_menu_links_discovered_alter()) are reflected immediately.
    \Drupal::service('plugin.manager.menu.link')->rebuild();

    // ------------------------------------------------------------------
    // Ensure private://ont directory exists and that hasco.ttl is present.
    // If hasco.ttl does not exist, create it with default content.
    // ------------------------------------------------------------------
    /** @var \Drupal\Core\File\FileSystemInterface $fs */
    $fs = \Drupal::service('file_system');
    $logger = \Drupal::logger('rep');
    $messenger = \Drupal::messenger();

    $dir_uri = 'private://ont';
    $file_uri = $dir_uri . '/hasco.ttl';

    try {
      // 1) Ensure the directory exists (create if missing).
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

      // 2) Try to enforce 0755 on the directory (no-op on some systems).
      $dir_real = $fs->realpath($dir_uri);
      if ($dir_real && is_dir($dir_real)) {
        @chmod($dir_real, 0755);
      }

      // 3) Ensure the hasco.ttl file exists; if missing, create it with defaults.
      $file_real = $fs->realpath($file_uri);
      if ($file_real === FALSE || !file_exists($file_real)) {
        // Load default HASCO ontology content from a file inside the module.
        // Adjust this path if you place the TXT file in a different folder.
        $module_path = \Drupal::service('extension.list.module')->getPath('rep');
        $default_real_path = DRUPAL_ROOT . '/' . $module_path . '/resources/hasco_default.ttl';
        $default_content = '';

        if (file_exists($default_real_path) && is_readable($default_real_path)) {
          $default_content = file_get_contents($default_real_path) ?: '';
        }
        else {
          // Fallback: create an empty file and warn the administrator.
          $logger->warning('Default HASCO ontology file not found at @path. Creating an empty hasco.ttl file.', ['@path' => $default_real_path]);
        }

        // saveData() creates the file via the stream wrapper.
        $saved_uri = $fs->saveData($default_content, $file_uri, FileSystemInterface::EXISTS_ERROR);
        if ($saved_uri === FALSE) {
          $logger->error('Failed to create HASCO ontology file at {file}', ['file' => $file_uri]);
          $messenger->addError($this->t('Failed to create %file.', ['%file' => $file_uri]));
        }
        else {
          // Optional: set 0644 on the file.
          $file_real = $fs->realpath($file_uri);
          if ($file_real) {
            @chmod($file_real, 0644);
          }
          $logger->notice('Created HASCO ontology file at {file}', ['file' => $file_uri]);
        }
      }
    }
    catch (\Throwable $e) {
      // Catch-all to avoid breaking the submit flow.
      $logger->error('Error ensuring private://ont and hasco.ttl file: {msg}', ['msg' => $e->getMessage()]);
      $messenger->addError($this->t('Error preparing ontology storage: %msg', ['%msg' => $e->getMessage()]));
    }

    // ------------------------------------------------------------------
    // Keep Drupal's site name in sync with REP's site_name setting.
    // ------------------------------------------------------------------
    $configdrupal = \Drupal::service('config.factory')->getEditable('system.site');
    $configdrupal->set('name', $form_state->getValue('site_name'));
    $configdrupal->save();

    // ------------------------------------------------------------------
    // Update repository configuration via the external REP API.
    // ------------------------------------------------------------------
    $api = \Drupal::service('rep.api_connector');

    $resp = '';
    // Label.
    $resp .= $api->repoUpdateLabel(
      $form_state->getValue('api_url'),
      $form_state->getValue('site_label')
    );

    // Title.
    $resp .= $api->repoUpdateTitle(
      $form_state->getValue('api_url'),
      $form_state->getValue('site_name')
    );

    // Domain URL.
    $resp .= $api->repoUpdateURL(
      $form_state->getValue('api_url'),
      $form_state->getValue('repository_domain_url')
    );

    // Description.
    $resp .= $api->repoUpdateDescription(
      $form_state->getValue('api_url'),
      $form_state->getValue('repository_description')
    );

    // Namespace. The MIME/source arguments are now deprecated in the form,
    // so we send empty strings (API may handle sensible defaults).
    $resp .= $api->repoUpdateNamespace(
      $form_state->getValue('api_url'),
      $form_state->getValue('repository_namespace_prefix'),
      $form_state->getValue('repository_namespace_url'),
      '',
      ''
    );

    $messenger = \Drupal::service('messenger');
    if ($resp !== '') {
      $messenger->addMessage($this->t('Your new REP configuration has been saved.'));
    }
    else {
      $messenger->addError($this->t('Failed to set REP configuration. Message: [@resp]', ['@resp' => $resp]));
    }

    // Redirect to the "repository info" route after saving.
    $url = Url::fromRoute('rep.repo_info');
    $form_state->setRedirectUrl($url);
  }

  /**
   * Synchronizes Drupal users with Sagres user registry.
   *
   * This is a batch-like operation: it retrieves the list of users from
   * Sagres and compares it with local Drupal users, creating or updating
   * records as needed.
   */
  private function syncUsersWithSagres() {
    $config = $this->config(static::CONFIGNAME);
    $sagres_base_url = $config->get('sagres_base_url');
    $sagres_token = \Drupal::service('request_stack')->getCurrentRequest()->getSession()->get('oauth_access_token');

    if (!$sagres_token) {
      \Drupal::logger('rep')->error('Token not found in the session.');
      return;
    }

    $repo_instance = \Drupal::request()->getHost();
    \Drupal::logger('rep')->notice('Starting user synchronization with Sagres...');

    \Drupal::logger('rep')->notice('Sagres Base URL: ' . $sagres_base_url);

    // ------------------------------------------------------------------
    // Fetch the list of users from sguser (Sagres) in a single request.
    // ------------------------------------------------------------------
    try {
      $response = \Drupal::httpClient()->get("{$sagres_base_url}/sguser/account/list", [
        'headers' => [
          'Accept' => 'application/json',
          'Authorization' => "Bearer {$sagres_token}",
        ],
      ]);
      $sguser_users = json_decode($response->getBody(), TRUE);
      \Drupal::logger('rep')->notice('User list retrieved successfully from sguser.');
    }
    catch (\Exception $e) {
      \Drupal::logger('rep')->error('Error while fetching user list from sguser: ' . $e->getMessage());
      return;
    }

    // Build a map of sguser users keyed by "repo_instance:acc_id" for easy lookup.
    $sguser_map = [];
    foreach ($sguser_users as $user) {
      $key = $user['acc_repo_instance'] . ':' . $user['acc_id'];
      $sguser_map[$key] = $user;
    }

    $users_created = 0;
    $users_updated = 0;

    $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple();

    foreach ($users as $user) {
      // Skip user ID 0 (anonymous) and blocked users.
      if ($user->id() == 0 || $user->isBlocked()) {
        \Drupal::logger('rep')->notice('User ignored (anonymous or blocked): ' . $user->id());
        continue;
      }

      \Drupal::logger('rep')->notice('Processing user: ' . $user->getEmail());

      $user_data = [
        'acc_id' => $user->id(),
        'acc_repo_instance' => $repo_instance,
        'acc_name' => $user->getDisplayName(),
        'acc_email' => $user->getEmail(),
        'acc_user_uri' => (\Drupal::request()->headers->get('x-forwarded-proto') === 'https' ? 'https://' : 'http://') .
          \Drupal::request()->getHost() .
          \Drupal::request()->getBaseUrl() .
          '/user/' . $user->id(),
        'acc_cellphone' => $user->hasField('field_cellphone') && !$user->get('field_cellphone')->isEmpty()
          ? (int) $user->get('field_cellphone')->value
          : null,
      ];

      $user_key = $repo_instance . ':' . $user->id();

      if (isset($sguser_map[$user_key])) {
        // User already exists in sguser, check if an update is needed.
        $existing_user = $sguser_map[$user_key];

        if (trim((string) $existing_user['acc_name']) !== trim((string) $user_data['acc_name']) ||
          trim((string) $existing_user['acc_email']) !== trim((string) $user_data['acc_email']) ||
          (string) $existing_user['acc_cellphone'] !== (string) $user_data['acc_cellphone']) {

          try {
            $response = \Drupal::httpClient()->patch("{$sagres_base_url}/sguser/account/update", [
              'json' => $user_data,
              'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$sagres_token}",
              ],
            ]);

            if ($response->getStatusCode() === 200) {
              $users_updated++;
            }
            else {
              \Drupal::logger('rep')->error('Error updating user ' . $user->id() . '. Status: ' . $response->getStatusCode());
            }
          }
          catch (\Exception $e) {
            \Drupal::logger('rep')->error('Error updating user ' . $user->id() . ': ' . $e->getMessage());
          }
        }
      }
      else {
        // User does not exist in sguser; create a new record.
        try {
          $response = \Drupal::httpClient()->post("{$sagres_base_url}/sguser/account/add", [
            'json' => $user_data,
            'headers' => [
              'Content-Type' => 'application/json',
              'Authorization' => "Bearer {$sagres_token}",
            ],
          ]);

          if ($response->getStatusCode() === 201) {
            $users_created++;
          }
          else {
            \Drupal::logger('rep')->error('Error creating user ' . $user->id() . '. Status: ' . $response->getStatusCode());
          }
        }
        catch (\Exception $e) {
          \Drupal::logger('rep')->error('Error creating user ' . $user->id() . ': ' . $e->getMessage());
        }
      }
    }

    \Drupal::messenger()->addMessage("Synchronization finished: {$users_created} users created, {$users_updated} updated.");
  }

}
