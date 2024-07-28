<?php

namespace Drupal\rep\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'REPSearchBlock' block.
 *
 * @Block(
 *  id = "rep_search_block",
 *  admin_label = @Translation("Search Basic Criteria"),
 *  category = @Translation("Search Basic Criteria")
 * )
 */
class REPSearchBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $form = \Drupal::formBuilder()->getForm('Drupal\rep\Form\REPSearchForm');

    return $form;
  }

}
