<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

class ConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['rep.config_image'];
  }

  /**
   * Build the configuration form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('rep.config_image');

    // Add vertical tabs to organize the modules
    $form['image_tabs'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Image Configuration'),
      '#default_tab' => 'general_settings',
    ];

    // Header image tab
    $form['header_image_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Header Image'),
      '#group' => 'image_tabs',
    ];

    $default_image_path = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/default_placeholder.png';
    $header_image_id = $config->get('header_image');
    $header_image_url = $header_image_id ? File::load($header_image_id)->createFileUrl() : $default_image_path;

    $form['header_image_tab']['header_image'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Header Image'),
      '#upload_location' => 'public://custom_images/',
      '#default_value' => $header_image_id,
      '#description' => $this->t('Select a JPG or PNG image for the header. Maximum size: 1 MB. Recommended dimensions: 1200x300px.'),
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg png'],
        'file_validate_size' => [1024 * 1024], // 1 MB limit
        'file_validate_image_resolution' => ['1200x300', '1200x300'],
      ],
    ];

    $form['header_image_tab']['header_image_preview'] = [
      '#markup' => '<img id="preview-header_image" src="' . $header_image_url . '" style="max-width: 200px; max-height: 200px; margin-top: 10px;" alt="Header Image preview" />',
    ];

    // Placeholder for each module
    $modules = [
      'REP' => ['REP', 'SIR', 'STD', 'SEM', 'INS'],
      'SIR' => ['Study', 'Semantic variable', 'Semantic Data Dictionary'],
      'Other' => ['Instrument', 'Detector Stem', 'Detector', 'Codebook', 'Response Option', 'Annotation Stem'],
      'Templates' => ['INS Template', 'DSG Template', 'DD Template', 'SDD Template', 'STR Template', 'DP2 Template', 'Platform', 'Platforms Instance', 'Instrument Instance', 'Detector Instance', 'Deployment'],
    ];

    foreach ($modules as $module_name => $placeholders) {
      $module_key = strtolower(str_replace(' ', '_', $module_name));

      // Create a tab for each module
      $form[$module_key . '_tab'] = [
        '#type' => 'details',
        '#title' => $this->t('@module Image Configuration', ['@module' => $module_name]),
        '#group' => 'image_tabs',
      ];

      // Add placeholders under each module tab
      foreach ($placeholders as $placeholder) {
        $placeholder_key = strtolower(str_replace(' ', '_', $placeholder));
        $file_id = $config->get($placeholder_key . '_image');
        $image_url = $file_id ? File::load($file_id)->createFileUrl() : $default_image_path;

        $form[$module_key . '_tab'][$placeholder_key . '_image'] = [
          '#type' => 'managed_file',
          '#title' => $this->t('@placeholder Image', ['@placeholder' => $placeholder]),
          '#upload_location' => 'public://custom_images/',
          '#default_value' => $file_id,
          '#description' => $this->t('Select a JPG or PNG image for @placeholder. Maximum size: 500 KB. Recommended dimensions: 200x200px.', ['@placeholder' => $placeholder]),
          '#upload_validators' => [
            'file_validate_extensions' => ['jpg png'],
            'file_validate_size' => [500 * 1024], // 500 KB limit
            'file_validate_image_resolution' => ['200x200', '200x200'],
          ],
        ];

        $form[$module_key . '_tab'][$placeholder_key . '_image_preview'] = [
          '#markup' => '<img id="preview-' . $placeholder_key . '" src="' . $image_url . '" style="max-width: 200px; max-height: 200px; margin-top: 10px;" alt="Image preview for ' . $placeholder . '" />',
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('rep.config_image');

    // Save header image
    $header_image = $form_state->getValue('header_image');
    if (!empty($header_image)) {
      $file = File::load($header_image[0]);
      if ($file) {
        $file->setPermanent();
        $file->save();
        $config->set('header_image', $file->id());
      }
    }

    // Save images for each placeholder in each module
    $modules = [
      'REP' => ['REP', 'SIR', 'STD', 'SEM', 'INS'],
      'SIR' => ['Study', 'Semantic variable', 'Semantic Data Dictionary'],
      'Other' => ['Instrument', 'Detector Stem', 'Detector', 'Codebook', 'Response Option', 'Annotation Stem'],
      'Templates' => ['INS Template', 'DSG Template', 'DD Template', 'SDD Template', 'STR Template', 'DP2 Template', 'Platform', 'Platforms Instance', 'Instrument Instance', 'Detector Instance', 'Deployment'],
    ];

    foreach ($modules as $placeholders) {
      foreach ($placeholders as $placeholder) {
        $placeholder_key = strtolower(str_replace(' ', '_', $placeholder));
        $image = $form_state->getValue($placeholder_key . '_image');

        if (!empty($image)) {
          $file = File::load($image[0]);
          if ($file) {
            $file->setPermanent();
            $file->save();
            $config->set($placeholder_key . '_image', $file->id());
          }
        }
      }
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }
}
