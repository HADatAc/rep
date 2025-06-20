<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\rep\Utils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Provides a form to add ontologies.
 */
class AddOntologiesForm extends FormBase {

  /**
   * The HTTP client factory service.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected $httpClientFactory;

  /**
   * Constructs a new AddOntologiesForm.
   *
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   *   The HTTP client factory service.
   */
  public function __construct(ClientFactory $http_client_factory) {
    $this->httpClientFactory = $http_client_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client_factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_ontologies_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Wrapper row centered on screen.
    $form['wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'col-md-6', 'justify-content-center', 'my-5'],
        'style' => "margin-bottom: 55px!important;"
      ],
    ];

    // Card container with md-6 width.
    $form['wrapper']['card'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['card', 'col-md-6', 'p-4', 'shadow-sm'],
      ],
    ];


    $form['wrapper']['card']['ontology_abbrev'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Abbreviature'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['col-md-5']
      ],
    ];

    $form['wrapper']['card']['ontology_namespace'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Namespace'),
      '#description' => $this->t('Enter the namespace (e.g. https://example.org/)'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => 'https://...',
      ],
    ];

    // Ontology URL field.
    $form['wrapper']['card']['ontology_source'] = [
      '#type' => 'url',
      '#title' => $this->t('Source URL'),
      '#description' => $this->t('Enter the URL of your ontology (e.g. https://example.org/ontology.ttl)'),
      '#attributes' => [
        'placeholder' => 'https://...',
      ],
    ];

    // Format selection.
    $form['wrapper']['card']['mimeType'] = [
      '#type' => 'select',
      '#title' => $this->t('MIME'),
      '#options' => [
        '' => 'None',
        'text/turtle' => $this->t('Turtle (TTL)'),
        'application/rdf+xml' => $this->t('RDF/XML'),
        // 'ntriples' => $this->t('N-Triples'),
        // 'jsonld' => $this->t('JSON-LD'),
      ],
      '#empty_option' => $this->t('- Select -'),
      '#wrapper_attributes' => [
        'class' => ['col-md-5']
      ]
    ];

    // Submit button, enabled only when both fields are filled.
    $form['wrapper']['card']['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'justify-content-start'],
      ],
    ];

    $form['wrapper']['card']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['col-md-2', 'mt-4', 'save-button'],
      ],
      '#states' => [
        'enabled' => [
          ':input[name="ontology_abbrev"]'   => ['filled' => TRUE],
          ':input[name="ontology_namespace"]'=> ['filled' => TRUE],
        ],
      ],
    ];

    $form['wrapper']['card']['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'cancel',
      // 1) Não dispara validação de required
      '#limit_validation_errors' => [],
      // 2) Handler customizado
      '#submit' => ['::cancelForm'],
      // Opcional: adiciona estilo
      '#attributes' => ['class' => ['col-md-2', 'mt-4', 'ms-2', 'btn', 'btn-secondary', 'cancel-button']],
    ];


    return $form;
  }

  /**
   * Handler de submit para o botão Cancel.
   */
  public function cancelForm(array &$form, FormStateInterface $form_state) {
   self::backUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $url = $form_state->getValue('ontology_source');

    // // Check if the URL is accessible via HTTP HEAD.
    // $client = $this->httpClientFactory->fromOptions(['timeout' => 5]);
    // try {
    //   $response = $client->head($url);
    //   $code = $response->getStatusCode();
    //   if ($code < 200 || $code >= 400) {
    //     $form_state->setErrorByName('ontology_source', $this->t('The URL returned status @code. Please verify it is correct and accessible.', ['@code' => $code]));
    //   }
    // }
    // catch (RequestException $e) {
    //   $form_state->setErrorByName('ontology_source', $this->t('Unable to access the URL: @message', ['@message' => $e->getMessage()]));
    // }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      self::backUrl();
      return;
    }

    try {

      $jsonPayload = '{' .
        '"label":"' . $form_state->getValue('ontology_abbrev') . '",' .
        '"uri":"' . $form_state->getValue('ontology_namespace') . '",' .
        '"source":"' . $form_state->getValue('ontology_source') . '",' .
        '"sourceMime":"' . $form_state->getValue('mimeType') . '"}';

      // Call the API connector service with the JSON.
      $api = \Drupal::service('rep.api_connector');
      $response = $api->repoCreateNamespace($jsonPayload);

      // dpm($response);
      $obj = json_decode($response);
      if ($obj->isSuccessful === true) {
        $this->messenger()->addStatus($this->t('Ontology submitted successfully.'));
      }
      else {
        $this->messenger()->addError($this->t('Submission error: status @code', ['@code' => $obj->body]));
      }
    }
    catch (RequestException $e) {
      $this->messenger()->addError($this->t('Connection failed: @message', ['@message' => $e->getMessage()]));
    }
  }

  function backUrl()
  {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'rep.admin_namespace_settings_custom');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
