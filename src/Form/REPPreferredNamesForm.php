<?php

/**
 * @file
 * Contains the settings form for administering the preferred names in the REP module.
 */

namespace Drupal\rep\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Class REPPreferredNamesForm.
 *
 * Provides a configuration form to set preferred names (Instrument, Component,
 * Process, Study, Platform) used across the repository and related modules.
 */
class REPPreferredNamesForm extends ConfigFormBase {

  /**
   * Configuration name used by this form.
   */
  const CONFIGNAME = 'rep.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_form_preferred_names';
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
   *
   * Builds the form with four preferred name fields and a "Back" button which
   * returns to the main REP settings form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::CONFIGNAME);

    // Preferred name for "Instrument".
    $instrument = '';
    if ($config->get('preferred_instrument') != NULL) {
      $instrument = $config->get('preferred_instrument');
    }
    $form['preferred_instrument'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Instrument's preferred name"),
      '#default_value' => $instrument,
    ];

    // Preferred name for "Component".
    $component = '';
    if ($config->get('preferred_component') != NULL) {
      $component = $config->get('preferred_component');
    }
    $form['preferred_component'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Component's preferred name"),
      '#default_value' => $component,
    ];

    // Preferred name for "Process" / "Workflow".
    $process = '';
    if ($config->get('preferred_process') != NULL) {
      $process = $config->get('preferred_process');
    }
    $form['preferred_process'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Workflow's preferred name"),
      '#default_value' => $process,
    ];

    // Preferred name for "Study".
    $study = '';
    if ($config->get('preferred_study') != NULL) {
      $study = $config->get('preferred_study');
    }
    $form['preferred_study'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Study's preferred name"),
      '#default_value' => $study,
    ];

    // Preferred name for "Platform".
    $platform = $config->get('preferred_platform') ?: 'Platform';
    $form['preferred_platform'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Platform's preferred name"),
      '#default_value' => $platform,
      '#description' => $this->t('Use singular form, e.g. "Laboratory".'),
    ];

    // Simple filler to add some vertical spacing.
    $form['filler'] = [
      '#type' => 'item',
      '#title' => $this->t('<br>'),
    ];

    // "Back" button to return to the main REP settings page.
    // NOTE:
    //  The default "Save configuration" button is removed by rep_form_alter(),
    //  so this is effectively the only submit button. When clicked, it will:
    //   1) Save configuration (if values are valid).
    //   2) Rebuild menu links so preferred names are applied to menus.
    //   3) Redirect back to rep.admin_settings_custom.
    $form['back_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back to REP Settings'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'back-button'],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Ensures that all preferred name fields are non-empty.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (strlen($form_state->getValue('preferred_instrument')) < 1) {
      $form_state->setErrorByName('preferred_instrument', $this->t("Please inform a preferred name for instruments."));
    }
    if (strlen($form_state->getValue('preferred_component')) < 1) {
      $form_state->setErrorByName('preferred_component', $this->t("Please inform a preferred name for components."));
    }
    if (strlen($form_state->getValue('preferred_process')) < 1) {
      $form_state->setErrorByName('preferred_process', $this->t("Please inform a preferred name for processes."));
    }
    if (strlen($form_state->getValue('preferred_study')) < 1) {
      $form_state->setErrorByName('preferred_study', $this->t("Please inform a preferred name for study."));
    }
    if (strlen($form_state->getValue('preferred_platform')) < 1) {
      $form_state->setErrorByName('preferred_platform', $this->t("Please inform a preferred name for platform."));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Saves the preferred names into rep.settings and rebuilds menu links so that
   * menu labels which depend on "preferred_study" or others can be updated
   * immediately (via rep_menu_links_discovered_alter()).
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Access configuration state.
    $config = $this->config(static::CONFIGNAME);

    // Retrieve triggering button (currently only "back").
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    // Save configuration only if all values are non-empty.
    // (This is redundant with validateForm(), but kept for extra safety.)
    if ($form_state->getValue('preferred_instrument') !== NULL &&
        $form_state->getValue('preferred_instrument') !== '' &&
        $form_state->getValue('preferred_component') !== NULL &&
        $form_state->getValue('preferred_component') !== '' &&
        $form_state->getValue('preferred_process') !== NULL &&
        $form_state->getValue('preferred_process') !== '' &&
        $form_state->getValue('preferred_study') !== NULL &&
        $form_state->getValue('preferred_study') !== '' &&
        $form_state->getValue('preferred_platform') !== NULL &&
        $form_state->getValue('preferred_platform') !== '') {

      $config->set('preferred_instrument', $form_state->getValue('preferred_instrument'));
      $config->set('preferred_component', $form_state->getValue('preferred_component'));
      $config->set('preferred_process', $form_state->getValue('preferred_process'));
      $config->set('preferred_study', $form_state->getValue('preferred_study'));
      $config->set('preferred_platform', $form_state->getValue('preferred_platform'));
      $config->save();

      // IMPORTANT:
      // Rebuild menu links so that changes in preferred names are applied
      // immediately to any menu entries altered in rep_menu_links_discovered_alter().
      \Drupal::service('plugin.manager.menu.link')->rebuild();
    }

    // Button actions.
    if ($button_name === 'back') {
      // Redirect back to the main REP settings page after saving.
      $form_state->setRedirectUrl(Url::fromRoute('rep.admin_settings_custom'));
      return;
    }
  }

}
