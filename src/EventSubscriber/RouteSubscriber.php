<?php

namespace Drupal\rep\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;
use Drupal\Core\Config\ConfigFactoryInterface;

class RouteSubscriber extends RouteSubscriberBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new YourModuleRouteSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  protected function alterRoutes(RouteCollection $collection) {
    
    $config = $this->configFactory->get('rep.settings');
    $rep_home = $config->get('rep_home');

    if($rep_home == '1'){
      if ($route = $collection->get('view.frontpage.page_1')) {
        $route->setDefault('_controller', '\Drupal\rep\Controller\InitializationController::index');
      }
   }
  }
}