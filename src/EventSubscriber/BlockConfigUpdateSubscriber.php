<?php

namespace Drupal\rep\EventSubscriber;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Drupal\block\Entity\Block;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class BlockConfigUpdateSubscriber implements EventSubscriberInterface {

  /**
   * This method is called on every request.
   */
  public function onKernelRequest(RequestEvent $event) {
    // Execute only on the main request.
    if (!$event->isMainRequest()) {
      return;
    }

    // Use a static flag to run this only once per request.
    static $updated = FALSE;
    if ($updated) {
      return;
    }

    // Load the block configuration entity by its machine name.
    $block = Block::load('hasco_barrio_main_menu');
    if ($block) {
      $settings = $block->get('settings');
      // Check if the 'menu_levels' depth is set and is below 3.
      if (isset($settings['depth']) && $settings['depth'] < 3) {
        // Update the depth value to 3.
        $settings['depth'] = 3;
        $block->set('settings', $settings);
        $block->save();
      }
      //dpm($block);
    }
    $updated = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 0],
    ];
  }
}
