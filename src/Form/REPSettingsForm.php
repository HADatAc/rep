<?php

/**
 * @file
 * Contains the main settings form for administering the REP module.
 */

namespace Drupal\rep\Form;

use Drupal\Component\Utility\UrlHelper;
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

    // Container for REP API Base URL with recovery button at the top.
    $form['api_url_container'] = [
      '#type' => 'container',
      '#attributes' => ['style' => 'display: flex; align-items: flex-end; gap: 10px; margin-top: 20px; margin-bottom: 20px;'],
    ];

    // Get default value - prefer user input during form rebuild, otherwise use config.
    $api_url_default = $config->get('api_url');
    if ($form_state->isRebuilding()) {
      $api_url_values = $form_state->getValue('api_url_container');
      if (isset($api_url_values['api_url']) && !empty($api_url_values['api_url'])) {
        $api_url_default = $api_url_values['api_url'];
      }
    }

    $form['api_url_container']['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('REP API Base URL'),
      '#default_value' => $api_url_default,
      '#description' => $this->t('Base URL for the hascoapi service (e.g., http://localhost:9001)'),
      '#attributes' => ['style' => 'flex: 1;'],
    ];

    $form['api_url_container']['recover_repo_info'] = [
      '#type' => 'submit',
      '#value' => $this->t('Recover Config from API'),
      '#name' => 'recover_repo',
      '#limit_validation_errors' => [],
      '#validate' => ['::noValidation'],
      '#executes_submit_callback' => TRUE,
      '#attributes' => [
        'class' => ['btn', 'btn-success'],
        'style' => 'margin-bottom: 25px; padding: 8px 16px; height: auto; line-height: 1.4;',
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

    // PMSR landing page: expose the feature flag when PMSR module exists.
    // Support both possible machine names across environments.
    $hasPmsrGui = FALSE;
    try {
      $moduleList = \Drupal::service('extension.list.module');
      $extensions = $moduleList->getList();
      $moduleName = '';
      if (isset($extensions['pmsr'])) {
        $moduleName = 'pmsr';
      }
      elseif (isset($extensions['pmsr_gui'])) {
        $moduleName = 'pmsr_gui';
      }

      $modulePath = $moduleName !== '' ? (string) $moduleList->getPath($moduleName) : '';
      $hasPmsrGui = $modulePath !== '';
    }
    catch (\Throwable $e) {
      $hasPmsrGui = FALSE;
    }

    if ($hasPmsrGui) {
      $form['pmsr_new_landing_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable PMSR new landing page'),
        '#default_value' => $config->get('pmsr_new_landing_enabled') ?? 0,
      ];

      $socialInitiativeField = [
        '#type' => 'textfield',
        '#title' => $this->t('Social Initiative URI for PMSR landing page'),
        '#default_value' => trim((string) ($config->get('social_initiative_uri') ?? '')),
        '#description' => $this->t('Priority project shown in PMSR new landing page. Start typing to select a project. If empty, the landing page keeps the existing fallback flow.'),
      ];

      // Reuse existing Social project autocomplete when the route is available.
      try {
        \Drupal::service('router.route_provider')->getRouteByName('social.autocomplete_project');
        $socialInitiativeField['#autocomplete_route_name'] = 'social.autocomplete_project';
      }
      catch (\Throwable $e) {
        // Keep plain text field fallback if social autocomplete route is unavailable.
      }

      $form['social_initiative_uri'] = $socialInitiativeField;
    }

    // Full name of the repository - moved to top after PMSR config.
    $fullName = '';
    if ($form_state->isRebuilding() && $form_state->hasValue('site_name')) {
      $fullName = $form_state->getValue('site_name');
    } elseif ($config->get('site_name') != NULL) {
      $fullName = $config->get('site_name');
    }
    $form['site_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Full Name (e.g. "ChildFIRST: Focus on Innovation")'),
      '#default_value' => $fullName,
      '#description' => $this->t('This value is the website name.'),
    ];

    // Short name of the repository.
    $shortName = '';
    if ($form_state->isRebuilding() && $form_state->hasValue('site_label')) {
      $shortName = $form_state->getValue('site_label');
    } elseif ($config->get('site_label') != NULL) {
      $shortName = $config->get('site_label');
    }
    $form['site_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Short Name (e.g. "ChildFIRST")'),
      '#default_value' => $shortName,
    ];

    // Public domain URL of the repository.
    $domainUrl = '';
    if ($form_state->isRebuilding() && $form_state->hasValue('repository_domain_url')) {
      $domainUrl = $form_state->getValue('repository_domain_url');
    } elseif ($config->get('repository_domain_url') != NULL) {
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
    if ($form_state->isRebuilding() && $form_state->hasValue('repository_namespace_prefix')) {
      $namespacePrefix = $form_state->getValue('repository_namespace_prefix');
    } elseif ($config->get('repository_namespace_prefix') != NULL) {
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
    if ($form_state->isRebuilding() && $form_state->hasValue('repository_namespace_url')) {
      $namespaceUrl = $form_state->getValue('repository_namespace_url');
    } elseif ($config->get('repository_namespace_url') != NULL) {
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
    if ($form_state->isRebuilding() && $form_state->hasValue('repository_description')) {
      $description = $form_state->getValue('repository_description');
    } elseif ($config->get('repository_description') != NULL) {
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

    // JWT secret selection (using the Key module).
    $form['jwt_secret'] = [
      '#type' => 'key_select',
      '#title' => $this->t('JWT Secret'),
      '#key_filters' => ['type' => 'authentication'],
      '#default_value' => $config->get('jwt_secret'),
    ];

    // Graph visualization (vis-network).
    $form['graph_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Graph visualization'),
      '#open' => FALSE,
    ];

    $form['graph_settings']['graph_max_live_nodes'] = [
      '#type' => 'number',
      '#title' => $this->t('Max visible nodes'),
      '#default_value' => (int) ($config->get('graph_max_live_nodes') ?? 600),
      '#min' => 50,
      '#step' => 1,
      '#description' => $this->t('Safety cap for nodes shown on the canvas.'),
    ];

    $form['graph_settings']['graph_page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Page size (Load more)'),
      '#default_value' => (int) ($config->get('graph_page_size') ?? 25),
      '#min' => 1,
      '#step' => 1,
      '#description' => $this->t('How many relationships are fetched per request.'),
    ];

    $form['graph_settings']['graph_max_members_per_soc'] = [
      '#type' => 'number',
      '#title' => $this->t('Items per page (menus)'),
      '#default_value' => (int) ($config->get('graph_max_members_per_soc') ?? 5),
      '#min' => 1,
      '#step' => 1,
      '#description' => $this->t('How many items are shown per page in relationship lists/menus.'),
    ];

    $form['graph_settings']['graph_auto_show_on_fetch'] = [
      '#type' => 'number',
      '#title' => $this->t('Auto-show nodes when fetched'),
      '#default_value' => (int) ($config->get('graph_auto_show_on_fetch') ?? 0),
      '#min' => 0,
      '#step' => 1,
      '#description' => $this->t('0 disables auto-adding fetched nodes to the canvas.'),
    ];

    $form['graph_settings']['graph_predicates_hidden'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Hidden predicates'),
      '#default_value' => $config->get('graph_predicates_hidden') ?? '',
      '#description' => $this->t('One predicate per line. These labels will be hidden from the graph explorer/menu.'),
    ];

    $form['graph_settings']['graph_predicates_auto_expand'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Auto-expand predicates (double-click)'),
      '#default_value' => $config->get('graph_predicates_auto_expand') ?? '',
      '#description' => $this->t('One predicate per line. On double-click, the graph will prefetch one page for each predicate (without automatically showing everything).'),
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
   * No-op validation for buttons that don't need validation.
   */
  public function noValidation(array &$form, FormStateInterface $form_state) {
    // Clear any validation errors that may have been set by required fields.
    $form_state->clearErrors();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Skip validation for special buttons that don't save configuration.
    $triggering_element = $form_state->getTriggeringElement();
    if (isset($triggering_element['#name'])) {
      $button_name = $triggering_element['#name'];
      // Skip validation for navigation and recovery buttons.
      if (in_array($button_name, ['preferred', 'namespace', 'sync_sagres', 'recover_repo'])) {
        return;
      }
    }
    
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

    $cttUrl = trim((string) $form_state->getValue('ctt_url'));
    if ($cttUrl !== '' &&
      (strtolower(substr($cttUrl, 0, 7)) !== 'http://') &&
      (strtolower(substr($cttUrl, 0, 8)) !== 'https://')) {
      $form_state->setErrorByName('ctt_url', $this->t("CTT Editor URL must start with 'http://' or 'https://'."));
    }

    $socialInitiativeRaw = trim((string) ($form_state->getValue('social_initiative_uri') ?? ''));
    if ($socialInitiativeRaw !== '') {
      $socialInitiativeUri = $this->extractUriFromAutocompleteValue($socialInitiativeRaw);
      if ($socialInitiativeUri === '') {
        $form_state->setErrorByName('social_initiative_uri', $this->t('Please select a project from autocomplete or provide a valid URI.'));
      }
      elseif (!UrlHelper::isValid($socialInitiativeUri, TRUE)) {
        $form_state->setErrorByName('social_initiative_uri', $this->t('Social Initiative URI must be a valid absolute URL.'));
      }
      else {
        $form_state->setValue('social_initiative_uri_parsed', $socialInitiativeUri);
      }
    }
    else {
      $form_state->setValue('social_initiative_uri_parsed', '');
    }

    // Graph settings validation.
    $maxLive = (int) $form_state->getValue('graph_max_live_nodes');
    if ($maxLive < 50) {
      $form_state->setErrorByName('graph_max_live_nodes', $this->t('Max visible nodes must be at least 50.'));
    }

    $pageSize = (int) $form_state->getValue('graph_page_size');
    if ($pageSize < 1) {
      $form_state->setErrorByName('graph_page_size', $this->t('Page size must be at least 1.'));
    }

    $itemsPerPage = (int) $form_state->getValue('graph_max_members_per_soc');
    if ($itemsPerPage < 1) {
      $form_state->setErrorByName('graph_max_members_per_soc', $this->t('Items per page must be at least 1.'));
    }

    $autoShow = (int) $form_state->getValue('graph_auto_show_on_fetch');
    if ($autoShow < 0) {
      $form_state->setErrorByName('graph_auto_show_on_fetch', $this->t('Auto-show must be 0 or greater.'));
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

    // Button to trigger Sagres synchronization (no config save).
    if ($button_name === 'sync_sagres') {
      \Drupal::logger('rep')->notice('Calling syncUsersWithSagres().');
      $this->syncUsersWithSagres();
      $messenger = \Drupal::service('messenger');
      $messenger->addMessage($this->t('User synchronization with Sagres completed!'));
      return;
    }

    // Button to recover repository information from API.
    if ($button_name === 'recover_repo') {
      \Drupal::logger('rep')->notice('Recovering repository info from API.');
      $this->recoverRepositoryInfo($form, $form_state);
      return;
    }

    // From here on we are actually saving configuration.
    $config = $this->config(static::CONFIGNAME);

    // Save configuration values.
    $config->set('rep_home', $form_state->getValue('rep_home'));
    $config->set('sagres_conf', $form_state->getValue('sagres_conf'));
    $config->set('social_conf', $form_state->getValue('social_conf'));
    $pmsrFlag = $form_state->getValue('pmsr_new_landing_enabled');
    if ($pmsrFlag !== NULL) {
      $config->set('pmsr_new_landing_enabled', $pmsrFlag);
    }
    $socialInitiativeRaw = $form_state->getValue('social_initiative_uri');
    if ($socialInitiativeRaw !== NULL) {
      $socialInitiativeParsed = $form_state->getValue('social_initiative_uri_parsed');
      if ($socialInitiativeParsed === NULL) {
        $socialInitiativeParsed = $this->extractUriFromAutocompleteValue((string) $socialInitiativeRaw);
      }
      $config->set('social_initiative_uri', trim((string) $socialInitiativeParsed));
    }
    $config->set('site_label', trim($form_state->getValue('site_label')));
    $config->set('site_name', trim($form_state->getValue('site_name')));
    $config->set('repository_domain_url', trim($form_state->getValue('repository_domain_url')));
    $config->set('repository_namespace_prefix', trim($form_state->getValue('repository_namespace_prefix')));
    $config->set('repository_namespace_url', trim($form_state->getValue('repository_namespace_url')));
    $config->set('repository_description', trim($form_state->getValue('repository_description')));
    $config->set('sagres_base_url', $form_state->getValue('sagres_base_url'));
    
    // Get api_url from container or user input.
    $api_url_values = $form_state->getValue('api_url_container');
    $api_url = isset($api_url_values['api_url']) ? trim($api_url_values['api_url']) : '';
    
    // Fallback: try to get from user input directly (for form rebuilds).
    if (empty($api_url)) {
      $user_input = $form_state->getUserInput();
      if (isset($user_input['api_url_container']['api_url'])) {
        $api_url = trim($user_input['api_url_container']['api_url']);
      }
    }
    
    // Save the api_url to config.
    if (!empty($api_url)) {
      $config->set('api_url', $api_url);
    }
    
    $cttUrl = $form_state->getValue('ctt_url');
    if ($cttUrl !== NULL) {
      $config->set('ctt_url', trim((string) $cttUrl));
    }
    $config->set('jwt_secret', $form_state->getValue('jwt_secret'));

    // Graph settings.
    $config->set('graph_max_live_nodes', (int) $form_state->getValue('graph_max_live_nodes'));
    $config->set('graph_page_size', (int) $form_state->getValue('graph_page_size'));
    $config->set('graph_max_members_per_soc', (int) $form_state->getValue('graph_max_members_per_soc'));
    $config->set('graph_auto_show_on_fetch', (int) $form_state->getValue('graph_auto_show_on_fetch'));
    $config->set('graph_predicates_hidden', trim((string) ($form_state->getValue('graph_predicates_hidden') ?? '')));
    $config->set('graph_predicates_auto_expand', trim((string) ($form_state->getValue('graph_predicates_auto_expand') ?? '')));

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
    // Only if api_url is configured.
    // ------------------------------------------------------------------
    $messenger = \Drupal::service('messenger');
    
    if (!empty($api_url)) {
      $api = \Drupal::service('rep.api_connector');

      $resp = '';
      // Label.
      $resp .= $api->repoUpdateLabel(
        $api_url,
        $form_state->getValue('site_label')
      );

      // Title.
      $resp .= $api->repoUpdateTitle(
        $api_url,
        $form_state->getValue('site_name')
      );

      // Domain URL.
      $resp .= $api->repoUpdateURL(
        $api_url,
        $form_state->getValue('repository_domain_url')
      );

      // Description.
      $resp .= $api->repoUpdateDescription(
        $api_url,
        $form_state->getValue('repository_description')
      );

      // Namespace table mutation is restricted by policy to approved PMSR flows.
      // Keep local REP settings, but do not mutate namespace table from this form.
      $messenger->addWarning($this->t('Namespace table updates are policy-restricted and are not executed from REP Settings. Use approved PMSR bootstrap/ingestion flows.'));

      if ($resp !== '') {
        $messenger->addMessage($this->t('Your new REP configuration has been saved.'));
      }
      else {
        $messenger->addError($this->t('Failed to set REP configuration. Message: [@resp]', ['@resp' => $resp]));
      }
    }
    else {
      // No API URL configured, just save locally.
      $messenger->addMessage($this->t('Your new REP configuration has been saved locally. Configure "REP API Base URL" to sync with the repository API.'));
    }

    // Redirect to the "repository info" route after saving.
    $url = Url::fromRoute('rep.repo_info');
    $form_state->setRedirectUrl($url);
  }

  /**
   * Extracts URI from an autocomplete value in the format "Label [URI]".
   */
  private function extractUriFromAutocompleteValue(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    if (preg_match('/\[([^\]]+)\]/', $value, $match) === 1) {
      return trim((string) $match[1]);
    }

    return $value;
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

  /**
   * Recovers repository information from the API.
   *
   * Retrieves repository configuration from the hascoapi and populates
   * form fields with the recovered values.
   */
  private function recoverRepositoryInfo(array &$form, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $messenger = \Drupal::service('messenger');

    // Get the API URL from the form state.
    $api_url = '';
    
    // Try multiple ways to get the value since Drupal form handling can be tricky.
    
    // Method 1: Try direct access from user input (containers don't always nest)
    $user_input = $form_state->getUserInput();
    if (isset($user_input['api_url']) && !empty(trim($user_input['api_url']))) {
      $api_url = trim($user_input['api_url']);
    }
    
    // Method 2: Try nested container access from user input
    if (empty($api_url) && isset($user_input['api_url_container']['api_url'])) {
      $api_url = trim($user_input['api_url_container']['api_url']);
    }
    
    // Method 3: Try from processed form values (nested)
    if (empty($api_url)) {
      $api_url_values = $form_state->getValue('api_url_container');
      if (isset($api_url_values['api_url'])) {
        $api_url = trim($api_url_values['api_url']);
      }
    }
    
    // Method 4: Try from processed form values (direct)
    if (empty($api_url)) {
      $direct_value = $form_state->getValue('api_url');
      if (!empty($direct_value)) {
        $api_url = trim($direct_value);
      }
    }
    
    // Method 5: Fall back to saved config
    if (empty($api_url)) {
      $config = $this->config(static::CONFIGNAME);
      $api_url = $config->get('api_url');
    }
    
    if (empty($api_url)) {
      $messenger->addError($this->t('Please enter an API URL in the "REP API Base URL" field before recovering configuration. Example: http://localhost:9001'));
      $form_state->setRebuild(TRUE);
      return;
    }
    
    // Validate that the API URL looks valid.
    if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
      $messenger->addError($this->t('The API URL "@url" is not valid. Please enter a complete URL including protocol (e.g., http://localhost:9001).', ['@url' => $api_url]));
      $form_state->setRebuild(TRUE);
      return;
    }

    try {
      \Drupal::logger('rep')->notice('Calling API to retrieve repository info from: ' . $api_url);
      $repo = $api->repoInfoNewIP($api_url);
      
      if (empty($repo)) {
        $messenger->addError($this->t('No response from API. Please check the API URL and ensure the service is running.'));
        \Drupal::logger('rep')->error('Empty response from repoInfoNewIP');
        $form_state->setRebuild(TRUE);
        return;
      }
      
      $obj = json_decode($repo);

      if (isset($obj->isSuccessful) && $obj->isSuccessful) {
        $repoObj = $obj->body;
        
        // Get current user input to preserve form metadata.
        $input = $form_state->getUserInput();
        
        // Preserve the API URL in user input for next rebuild.
        if (!isset($input['api_url_container'])) {
          $input['api_url_container'] = [];
        }
        $input['api_url_container']['api_url'] = $api_url;
        
        // Update form state values and user input with recovered data.
        if (isset($repoObj->label)) {
          $form_state->setValue('site_label', $repoObj->label);
          $input['site_label'] = $repoObj->label;
        }
        if (isset($repoObj->title)) {
          $form_state->setValue('site_name', $repoObj->title);
          $input['site_name'] = $repoObj->title;
        }
        if (isset($repoObj->hasDomainURL)) {
          $form_state->setValue('repository_domain_url', $repoObj->hasDomainURL);
          $input['repository_domain_url'] = $repoObj->hasDomainURL;
        }
        if (isset($repoObj->hasDefaultNamespacePrefix)) {
          $form_state->setValue('repository_namespace_prefix', $repoObj->hasDefaultNamespacePrefix);
          $input['repository_namespace_prefix'] = $repoObj->hasDefaultNamespacePrefix;
        }
        if (isset($repoObj->hasDefaultNamespaceURL)) {
          $form_state->setValue('repository_namespace_url', $repoObj->hasDefaultNamespaceURL);
          $input['repository_namespace_url'] = $repoObj->hasDefaultNamespaceURL;
        }
        if (isset($repoObj->comment)) {
          $form_state->setValue('repository_description', $repoObj->comment);
          $input['repository_description'] = $repoObj->comment;
        }
        if (isset($repoObj->hasSocialInitiativeURI)) {
          $form_state->setValue('social_initiative_uri', $repoObj->hasSocialInitiativeURI);
          $input['social_initiative_uri'] = $repoObj->hasSocialInitiativeURI;
        }
        if (isset($repoObj->hasSagresBaseURL)) {
          $form_state->setValue('sagres_base_url', $repoObj->hasSagresBaseURL);
          $input['sagres_base_url'] = $repoObj->hasSagresBaseURL;
        }
        if (isset($repoObj->hasCTTURL)) {
          $form_state->setValue('ctt_url', $repoObj->hasCTTURL);
          $input['ctt_url'] = $repoObj->hasCTTURL;
        }
        if (isset($repoObj->hasREPAsHome)) {
          $form_state->setValue('rep_home', $repoObj->hasREPAsHome ? 1 : 0);
          $input['rep_home'] = $repoObj->hasREPAsHome ? 1 : 0;
        }
        if (isset($repoObj->hasSagresEnabled)) {
          $form_state->setValue('sagres_conf', $repoObj->hasSagresEnabled ? 1 : 0);
          $input['sagres_conf'] = $repoObj->hasSagresEnabled ? 1 : 0;
        }
        if (isset($repoObj->hasSocialEnabled)) {
          $form_state->setValue('social_conf', $repoObj->hasSocialEnabled ? 1 : 0);
          $input['social_conf'] = $repoObj->hasSocialEnabled ? 1 : 0;
        }
        if (isset($repoObj->hasPMSRLandingEnabled)) {
          $form_state->setValue('pmsr_new_landing_enabled', $repoObj->hasPMSRLandingEnabled ? 1 : 0);
          $input['pmsr_new_landing_enabled'] = $repoObj->hasPMSRLandingEnabled ? 1 : 0;
        }
        
        // Update user input so values persist through form rebuild.
        $form_state->setUserInput($input);

        $messenger->addStatus($this->t('Repository information successfully recovered from API. Review the values and click "Save configuration" to apply them.'));
      }
      else {
        $error_msg = isset($obj->message) ? $obj->message : 'Unknown error';
        $messenger->addError($this->t('Failed to recover repository information. API response: @msg', ['@msg' => $error_msg]));
        \Drupal::logger('rep')->error('API recovery failed: ' . $error_msg);
      }
    }
    catch (\Exception $e) {
      $messenger->addError($this->t('Error connecting to API: @msg', ['@msg' => $e->getMessage()]));
      \Drupal::logger('rep')->error('Exception during repository info recovery: ' . $e->getMessage());
    }

    // Rebuild the form so the user can see the recovered values.
    $form_state->setRebuild(TRUE);
  }

}
