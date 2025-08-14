<?php

namespace Drupal\rep\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormInterface;

/**
 * Provides a 'MTSearchBlock' block.
 *
 * @Block(
 *   id = "rep_mtsearch_block",
 *   admin_label = @Translation("MT Search Block"),
 *   category = @Translation("REP")
 * )
 */
class MTSearchBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return \Drupal::formBuilder()->getForm('Drupal\rep\Form\MTSearchForm');
  }

}
