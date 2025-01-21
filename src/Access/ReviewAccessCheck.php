<?php

namespace Drupal\rep\Access;

use Drupal\Core\Access\AccessCheckInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Classe para verificar acesso aos menus Reviewer.
 */
class ReviewAccessCheck implements AccessCheckInterface {

  /**
   * A conta do usuário atual.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * O serviço de log.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Construtor.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   A conta do usuário atual.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   A fábrica de canais de log.
   */
  public function __construct(AccountInterface $current_user, LoggerChannelFactoryInterface $logger_factory) {
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('rep');
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Route $route) {
    // Verifica se a rota tem o requisito de acesso '_review_access'.
    return $route->hasRequirement('_review_access');
  }

  /**
   * {@inheritdoc}
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account) {
    // Log de depuração para verificar se a função está sendo chamada.
    $this->logger->debug('ReviewAccessCheck chamado para o usuário: @user', ['@user' => $account->getAccountName()]);

    // Verifica se o usuário possui o papel 'content_editor'.
    $has_content_editor = $account->hasRole('content_editor');

    // Verifica se o usuário NÃO possui o papel 'administrator'.
    $is_not_administrator = !$account->hasRole('administrator');

    $this->logger->debug('User has content_editor: @ce, is not administrator: @na', [
      '@ce' => $has_content_editor ? 'TRUE' : 'FALSE',
      '@na' => $is_not_administrator ? 'TRUE' : 'FALSE',
    ]);

    if ($has_content_editor && $is_not_administrator) {
      $this->logger->debug('Acesso permitido para o usuário: @user', ['@user' => $account->getAccountName()]);
      return AccessResult::allowed()->cachePerUser();
    }

    $this->logger->debug('Acesso negado para o usuário: @user', ['@user' => $account->getAccountName()]);
    return AccessResult::forbidden()->cachePerUser();
  }

}
