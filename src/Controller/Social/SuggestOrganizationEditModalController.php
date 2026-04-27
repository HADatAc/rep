<?php

namespace Drupal\rep\Controller\Social;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\rep\Form\Social\SuggestOrganizationEditForm;
use Drupal\rep\Utils;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class SuggestOrganizationEditModalController extends ControllerBase {

  /**
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  public function __construct(FormBuilderInterface $form_builder) {
    $this->formBuilder = $form_builder;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder')
    );
  }

  public function openOrganization(string $elementuri, Request $request): AjaxResponse {
    $uri_decode = base64_decode($elementuri);
    $full_uri = Utils::plainUri($uri_decode);

    $form = $this->formBuilder->getForm(SuggestOrganizationEditForm::class, $full_uri);

    $response = new AjaxResponse();
    $response->addCommand(new OpenModalDialogCommand(
      $this->t('Suggest Organization Edit'),
      $form,
      [
        'width' => '700',
        'dialogClass' => 'rep-social-suggest-edit-modal',
      ]
    ));

    return $response;
  }

}
