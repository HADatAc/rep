<?php

namespace Drupal\rep\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Guards pagination parameters from unsafe URL manipulation.
 */
class PaginationGuardSubscriber implements EventSubscriberInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the pagination guard subscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      // Run after route matching so route attributes are available.
      KernelEvents::REQUEST => ['onRequest', 20],
    ];
  }

  /**
   * Sanitizes route/query page and pagesize values.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The kernel request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();

    $config = $this->configFactory->get('rep.settings');
    $default_page_size = (int) ($config->get('pagination_default_pagesize') ?? 9);
    $max_page_size = (int) ($config->get('pagination_max_pagesize') ?? 50);

    $default_page_size = max(1, $default_page_size);
    $max_page_size = max($default_page_size, $max_page_size);

    if ($request->attributes->has('page')) {
      $request->attributes->set('page', max(1, (int) $request->attributes->get('page')));
    }

    if ($request->attributes->has('pagesize')) {
      $request->attributes->set(
        'pagesize',
        $this->sanitizePageSize($request->attributes->get('pagesize'), $default_page_size, $max_page_size)
      );
    }

    if ($request->query->has('page')) {
      $request->query->set('page', max(1, (int) $request->query->get('page')));
    }

    if ($request->query->has('pagesize')) {
      $request->query->set(
        'pagesize',
        $this->sanitizePageSize($request->query->get('pagesize'), $default_page_size, $max_page_size)
      );
    }

    // Keep compatibility with callers using camelCase query parameters.
    if ($request->query->has('pageSize')) {
      $request->query->set(
        'pageSize',
        $this->sanitizePageSize($request->query->get('pageSize'), $default_page_size, $max_page_size)
      );
    }
  }

  /**
   * Sanitizes pagesize with configured defaults and limits.
   */
  protected function sanitizePageSize($value, int $default_page_size, int $max_page_size): int {
    if (!is_numeric($value)) {
      return $default_page_size;
    }

    $pagesize = (int) $value;
    if ($pagesize < 1) {
      return $default_page_size;
    }

    return min($pagesize, $max_page_size);
  }

}
