<?php

namespace Drupal\rep\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'REPFacetedValueSearchBlock' block.
 *
 * @Block(
 *  id = "rep_faceted_value_search_block",
 *  admin_label = @Translation("Faceted Value Search"),
 *  category = @Translation("Faceted Value Search")
 * )
 */
class REPFacetedValueSearchBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $form = \Drupal::formBuilder()->getForm('Drupal\rep\Form\ValueSearch\REPFacetedValueSearchForm');

    return $form;
  }

}
