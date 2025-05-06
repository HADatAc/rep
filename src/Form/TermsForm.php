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
    $terms_text = '';

    try {
      $client = \Drupal::httpClient();
      $project_id = 'pmsr_v0.1';

      $response = $client->get('http://192.168.1.169/sguser/terms/latest', [
        'query' => [
          'project_id' => $project_id,
        ],
      ]);
      $data = json_decode($response->getBody(), TRUE);

      if (!empty($data['content'])) {
        $terms_text = $data['content'];
      } else {
        $terms_text = 'Não foi possível carregar os termos de uso.';
      }
    }
    catch (\Exception $e) {
      $terms_text = 'Erro ao carregar os termos: ' . $e->getMessage();
    }

    $form['description'] = [
      '#markup' => '<h2>Termos de Uso</h2><div class="terms-content" style="border:1px solid #ccc; padding:15px; max-height:300px; overflow-y:auto;">' . $terms_text . '</div>',
    ];
      
    $form['accept'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Li e aceito os Termos de Uso.'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Aceitar Termos'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $current_user = \Drupal::currentUser();
    $username = $current_user->getAccountName();
    $repo_instance = \Drupal::config('system.site')->get('name');
    $project_id = 'pmsr_v0.1';

    try {
      $client = \Drupal::httpClient();
      $response = $client->post('http://192.168.1.169/sguser/account/accept-terms', [
        'json' => [
          'acc_id' => $username,
          'acc_repo_instance' => $repo_instance,
          'project_id' => $project_id,
        ],
      ]);
      $data = json_decode($response->getBody(), TRUE);
      
      if (!empty($data['status']) && $data['status'] === 'success') {
        \Drupal::service('session')->remove('terms_pending');
        $this->messenger()->addStatus($this->t('Obrigado por aceitar os termos de uso.'));
        $form_state->setRedirect('<front>');
      } elseif (!empty($data['status']) && $data['status'] === 'already_accepted') {
        \Drupal::service('session')->remove('terms_pending');
        $this->messenger()->addWarning($this->t('Já tinha aceite os termos.'));
        $form_state->setRedirect('<front>');
      } else {
        $this->messenger()->addError($this->t('Erro ao registar aceitação.'));
      }      
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Erro ao aceitar os termos: @msg', ['@msg' => $e->getMessage()]));
    }
  }

}
