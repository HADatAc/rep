<?php

namespace Drupal\rep\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'DescribeDerivationBlock' block.
 *
 * @Block(
 *  id = "describe_derivation_block",
 *  admin_label = @Translation("Describe Derivation"),
 *  category = @Translation("Describe Derivation")
 * )
 */
class DescribeDerivationBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    //return [
    //  '#markup' => $this->t('Hello, World!'),
    //];

    $form = \Drupal::formBuilder()->getForm('Drupal\rep\Form\DescribeDerivationForm');

    return $form;
  }

}
