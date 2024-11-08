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
    return ['rep.config_imagem'];
  }

  /**
   * Construção do formulário de configuração.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('rep.config_imagem');

    // Campo para imagem de cabeçalho
    $form['header_image'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Imagem do Cabeçalho'),
      '#upload_location' => 'public://custom_images/',
      '#default_value' => $config->get('header_image'),
      '#description' => $this->t('Selecione uma imagem JPG ou PNG para o cabeçalho. Tamanho máximo: 1 MB.'),
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg png'],
        'file_validate_size' => [1024 * 1024], // Limite de 1 MB
      ],
    ];

    // Campo para imagem placeholder
    $form['placeholder_image'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Imagem Placeholder'),
      '#upload_location' => 'public://custom_images/',
      '#default_value' => $config->get('placeholder_image'),
      '#description' => $this->t('Selecione uma imagem JPG ou PNG para o placeholder. Tamanho máximo: 500 KB.'),
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg png'],
        'file_validate_size' => [500 * 1024], // Limite de 500 KB
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Salva a imagem de cabeçalho
    $header_image = $form_state->getValue('header_image');
    if (!empty($header_image)) {
      $file = File::load($header_image[0]);
      $file->setPermanent();
      $file->save();
      $this->config('rep.config_imagem')->set('header_image', $file->id())->save();
    }

    // Salva a imagem placeholder
    $placeholder_image = $form_state->getValue('placeholder_image');
    if (!empty($placeholder_image)) {
      $file = File::load($placeholder_image[0]);
      $file->setPermanent();
      $file->save();
      $this->config('rep.config_imagem')->set('placeholder_image', $file->id())->save();
    }

    parent::submitForm($form, $form_state);
  }
}
