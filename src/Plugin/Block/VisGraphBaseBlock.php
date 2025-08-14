<?php

namespace Drupal\rep\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\rep\Form\VisGraphBaseForm;

/**
 * Provides a block that displays the Vis Graph.
 *
 * @Block(
 *   id = "vis_graph_base_block",
 *   admin_label = @Translation("Vis Graph Panel Block")
 * )
 */
class VisGraphBaseBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Constructs a new VisGraphBaseBlock.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FormBuilderInterface $form_builder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // O elemento a ser exibido deve ser injetado aqui. Exemplo fictício:
    $request = \Drupal::request();
    $uriParam = $request->query->get('uri');

    if (!$uriParam) {
      return [
        '#markup' => t('Nenhum elemento foi fornecido para o grafo.'),
      ];
    }

    $decodedUri = base64_decode(rawurldecode($uriParam));
    $api = \Drupal::service('rep.api_connector');
    $finalUri = $api->getUri(\Drupal\rep\Utils::plainUri($decodedUri));
    $element = $api->parseObjectResponse($finalUri, 'getUri');

    if (!$element || !isset($element->uri)) {
      return [
        '#markup' => t('Elemento não encontrado ou inválido.'),
      ];
    }

    // Cria e renderiza o formulário do grafo
    $graphForm = new VisGraphBaseForm();
    $graphForm->setVisElement($element);

    return $this->formBuilder->getForm($graphForm);
  }

}
