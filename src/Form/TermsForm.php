<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formulário para aceitar os termos de uso.
 */
class TermsForm extends FormBase {

  public function getFormId() {
    return 'rep_terms_acceptance_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'rep/terms_modal';

    try {
      $client = \Drupal::httpClient();
      $project_id = 'hascorepo';

      $response = $client->get('http://192.168.1.169/sgcontract/terms/latest', [
        'query' => ['project_id' => $project_id],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      $version = $data['version'];
      $download_url = $data['download_url'];
      $terms_hash = hash('sha256', file_get_contents($download_url));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Erro ao carregar os termos: @msg', ['@msg' => $e->getMessage()]));
      $download_url = '';
      $version = '';
      $terms_hash = '';
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
    $username = $current_user->getAccountName();
    $repo_instance = \Drupal::request()->getHost();
    $project_id = 'hascorepo';

    try {
      $client = \Drupal::httpClient();
      $response = $client->post('http://192.168.1.169/sgcontract/account/accept-terms', [
        'json' => [
          'acc_id' => $username,
          'acc_repo_instance' => $repo_instance,
          'project_id' => $project_id,
          'terms_version' => $form_state->get('terms_version'),
          'accepted_at' => date('Y-m-d H:i:s'),
          'user_ip' => \Drupal::request()->getClientIp(),
          'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
          'session_id' => \Drupal::service('session')->getId(),
          'terms_hash' => $form_state->get('terms_hash'),
        ],
      ]);
      $data = json_decode($response->getBody(), TRUE);
      
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
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Erro ao aceitar os termos: @msg', ['@msg' => $e->getMessage()]));
    }
  }

}
