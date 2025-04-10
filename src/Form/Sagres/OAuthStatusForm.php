<?php

namespace Drupal\rep\Form\Sagres;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class OAuthStatusForm extends FormBase {

  protected $session;

  public function __construct(RequestStack $request_stack) {
    $this->session = $request_stack->getCurrentRequest()->getSession();
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')
    );
  }

  public function getFormId() {
    return 'rep_oauth_status_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $token = $this->session->get('oauth_access_token', 'No token found');

    $form['token'] = [
      '#type' => 'item',
      '#title' => $this->t('OAuth Token'),
      '#markup' => $token,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Obter Novo Token'),
      '#submit' => ['::refreshToken'],
    ];

    return $form;
  }

  public function refreshToken(array &$form, FormStateInterface $form_state) {
    $callable = \Drupal::service('controller_resolver')
    ->getControllerFromDefinition('Drupal\rep\Controller\Sagres\OAuthController::getAccessToken');
  
    $response = call_user_func($callable);

    if ($response instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      $data = json_decode($response->getContent(), TRUE);
      if (!empty($data['body']['access_token'])) {
        $this->session->set('oauth_access_token', $data['body']['access_token']);
        \Drupal::messenger()->addMessage($this->t('Novo token obtido com sucesso.'));
      } else {
        \Drupal::messenger()->addError($this->t('Falha ao obter token.'));
      }
    } else {
      \Drupal::messenger()->addError($this->t('Erro ao contactar o controlador.'));
    }

    // Rebuild the form to show the new token
    $form_state->setRebuild(TRUE);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
  }
}