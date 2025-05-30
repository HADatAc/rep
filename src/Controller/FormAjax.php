<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Generic AJAX controller to load any Drupal form into a modal dialog.
 */
class FormAjax extends ControllerBase {

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Constructs a new FormAjax controller.
   *
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder service.
   */
  public function __construct(FormBuilderInterface $form_builder) {
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder')
    );
  }

  /**
   * AJAX callback to open any FormInterface implementation in a modal.
   *
   * Expects two query parameters:
   * - form_class: Fully qualified class name of a FormInterface implementation.
   * - args:       JSON-encoded array of positional arguments for the form.
   *
   * Example request:
   *   /rep/formModal?form_class=Drupal%5Cmy_module%5CForm%5CMyForm&args=%5B%22foo%22%2C42%5D
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current HTTP request.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AjaxResponse containing commands to open the modal and adjust CSS.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the requested form class does not exist or does not implement FormInterface.
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If the args parameter is not valid JSON.
   */
  public function open(Request $request): AjaxResponse {
    // 1) Retrieve and validate the 'form_class' query parameter.
    $form_class = $request->query->get('form_class');
    if (!is_string($form_class)
      || !class_exists($form_class)
      || !in_array(FormInterface::class, class_implements($form_class))
    ) {
      throw new NotFoundHttpException('The specified form class is invalid or missing.');
    }

    // 2) Decode the JSON-encoded 'args' parameter (default to empty array).
    $args_json = $request->query->get('args', '[]');
    $args = json_decode($args_json, TRUE);
    if (!is_array($args)) {
      throw new BadRequestHttpException('The "args" parameter must be a JSON array.');
    }

    // 3) Build the form using the form builder.
    //    This pulls in all #attached libraries, preprocessors, etc.
    $form = $this->formBuilder->getForm($form_class, ...$args);

    // 4) Create an AjaxResponse and add an OpenModalDialogCommand to display the form.
    $response = new AjaxResponse();
    $response->addCommand(new OpenModalDialogCommand(
      $this->t('Modal Form'),
      $form,
      [
        'width'       => '1280',              // Width in pixels.
        'dialogClass' => 'rep-generic-modal', // Custom CSS class for styling.
      ]
    ));

    // 5) Immediately override the wrapper’s CSS to use absolute positioning
    //    (bypassing the fixed rule in modal-media.css) for this modal only.
    $response->addCommand(new InvokeCommand(
      '#drupal-modal',
      'css',
      [
        // one single argument: an associative array of all your overrides
        [
          'position'       => 'relative',
          'pointer-events' => 'auto',
        ],
      ]
    ));

    return $response;
  }

}
