<?php

namespace Drupal\rep\Controller\Social;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\rep\Form\Social\QuickAddOrganizationUnderReviewForm;
use Drupal\rep\Form\Social\QuickAddPersonUnderReviewForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class QuickAddSocialModalController extends ControllerBase {

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

  public function openPerson(Request $request): AjaxResponse {
    $prefill = (string) ($request->query->get('prefill') ?? '');
    $input_id = (string) ($request->query->get('input_id') ?? '');

    $form = $this->formBuilder->getForm(QuickAddPersonUnderReviewForm::class, $input_id, $prefill);

    $response = new AjaxResponse();
    $response->addCommand(new OpenModalDialogCommand(
      $this->t('Create Person'),
      $form,
      [
        'width' => '700',
        'dialogClass' => 'rep-social-quick-add-modal',
      ]
    ));

    return $response;
  }

  public function openOrganization(Request $request): AjaxResponse {
    $prefill = (string) ($request->query->get('prefill') ?? '');
    $input_id = (string) ($request->query->get('input_id') ?? '');

    $form = $this->formBuilder->getForm(QuickAddOrganizationUnderReviewForm::class, $input_id, $prefill);

    $response = new AjaxResponse();
    $response->addCommand(new OpenModalDialogCommand(
      $this->t('Create Organization'),
      $form,
      [
        'width' => '700',
        'dialogClass' => 'rep-social-quick-add-modal',
      ]
    ));

    return $response;
  }

}
