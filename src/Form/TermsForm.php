<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;

/**
 * Formulário para aceitar os termos de uso.
 */
class TermsForm extends FormBase {

  private const TERMS_SERVICE_BASE_URL = 'http://192.168.1.169/sgcontract';
  private const TERMS_HTTP_CONNECT_TIMEOUT = 2.0;
  private const TERMS_HTTP_TIMEOUT = 4.0;

  public function getFormId() {
    return 'rep_terms_acceptance_form';
  }

  /**
   * Build HTTP options with fail-fast defaults for external integrations.
   */
  private function buildTermsHttpOptions(array $options = []): array {
    $defaults = [
      'connect_timeout' => self::TERMS_HTTP_CONNECT_TIMEOUT,
      'timeout' => self::TERMS_HTTP_TIMEOUT,
      'http_errors' => FALSE,
    ];

    return $options + $defaults;
  }

  /**
   * Resolve terms service base URL from config with a safe fallback.
   */
  private function getTermsServiceBaseUrl(): string {
    $configured = trim((string) \Drupal::config('rep.settings')->get('terms_service_base_url'));
    $base = $configured !== '' ? $configured : self::TERMS_SERVICE_BASE_URL;
    return rtrim($base, '/');
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'rep/terms_modal';

    $download_url = '';
    $version = '';
    $terms_hash = '';
    $termsServiceBaseUrl = $this->getTermsServiceBaseUrl();

    try {
      $client = \Drupal::httpClient();
      $project_id = 'hascorepo';

      $response = $client->get($termsServiceBaseUrl . '/terms/latest', $this->buildTermsHttpOptions([
        'query' => ['project_id' => $project_id],
      ]));

      if ($response->getStatusCode() !== 200) {
        throw new \RuntimeException('Terms service returned HTTP ' . $response->getStatusCode());
      }

      $data = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($data)) {
        throw new \RuntimeException('Invalid JSON response from terms service.');
      }

      $version = isset($data['version']) ? (string) $data['version'] : '';
      $download_url = isset($data['download_url']) ? (string) $data['download_url'] : '';

      if ($download_url !== '') {
        $termsResponse = $client->get($download_url, $this->buildTermsHttpOptions());
        if ($termsResponse->getStatusCode() === 200) {
          $terms_hash = hash('sha256', (string) $termsResponse->getBody());
        }
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Erro ao carregar os termos: @msg', ['@msg' => $e->getMessage()]));
    }


    // Botão para visualizar termos no modal
    $form['terms_button'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Visualizar Termos de Uso'),
      '#attributes' => [
        'class' => ['view-terms-button', 'btn', 'btn-secondary'],
        'type' => 'button',
        'data-terms-url' => $download_url,
        'style' => 'margin-bottom:15px;',
      ],
      '#disabled' => empty($download_url),
    ];

    $form['terms_modal'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div id="drupal-modal" class="modal-media" style="display:none;"></div>
      '),
    ];

    $form['description'] = [
      '#markup' => empty($download_url)
        ? '<p><strong>Os Termos de Uso não estão disponíveis de momento. Por favor, tente mais tarde.</strong></p>'
        : '<p>Clique no botão acima para visualizar os Termos de Uso.</p>',
    ];

    $form['accept_terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Li e aceito os Termos de Uso'),
      '#required' => TRUE,
    ];

    $form_state->set('terms_version', $version);
    $form_state->set('terms_hash', $terms_hash);

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Aceitar'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $current_user = \Drupal::currentUser();
    $repo_instance = \Drupal::request()->getHost();
    $project_id = 'hascorepo';
    $termsServiceBaseUrl = $this->getTermsServiceBaseUrl();

    try {
      $client = \Drupal::httpClient();
      $response = $client->post($termsServiceBaseUrl . '/account/accept-terms', $this->buildTermsHttpOptions([
        'json' => [
          'acc_id' => $current_user->id(),
          'acc_repo_instance' => $repo_instance,
          'project_id' => $project_id,
          'terms_version' => $form_state->get('terms_version'),
          'accepted_at' => date('Y-m-d H:i:s'),
          'user_ip' => \Drupal::request()->getClientIp(),
          'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
          'session_id' => \Drupal::service('session')->getId(),
          'terms_hash' => $form_state->get('terms_hash'),
        ],
      ]));

      if ($response->getStatusCode() !== 200) {
        throw new \RuntimeException('Terms acceptance service returned HTTP ' . $response->getStatusCode());
      }

      $data = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($data)) {
        throw new \RuntimeException('Invalid JSON response from terms acceptance service.');
      }
      
      if (!empty($data['status']) && $data['status'] === 'success') {
        \Drupal::service('session')->remove('terms_pending');
        $this->messenger()->addStatus($this->t('Obrigado por aceitar os termos de uso.'));
        $form_state->setRedirect('<front>');
      }
      elseif (!empty($data['status']) && $data['status'] === 'already_accepted') {
        \Drupal::service('session')->remove('terms_pending');
        $this->messenger()->addWarning($this->t('Já tinha aceite os termos.'));
        $form_state->setRedirect('<front>');
      }
      else {
        $this->messenger()->addError($this->t('Erro ao registar aceitação.'));
      }      
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Erro ao aceitar os termos: @msg', ['@msg' => $e->getMessage()]));
    }
  }

}