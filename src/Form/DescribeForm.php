<?php

/**
 * @file
 * Contains the settings for admninistering the REP Module
 */

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\FormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\Core\Url;
 use Symfony\Component\HttpFoundation\RedirectResponse;
 use Drupal\rep\ListUsage;
 use Drupal\rep\Utils;
 use Drupal\rep\Entity\Tables;
 use Drupal\rep\Entity\GenericObject;
 use Drupal\rep\Vocabulary\REPGUI;
 use Drupal\rep\Vocabulary\VSTOI;
 use Drupal\Core\Render\Markup;

 class DescribeForm extends FormBase {

  protected $element;

  protected $source;

  protected $codebook;

  public function getElement() {
    return $this->element;
  }

  public function setElement($obj) {
    return $this->element = $obj;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
      return "describe_form";
  }

  /**
   * {@inheritdoc}
   */

  public function buildForm(array $form, FormStateInterface $form_state, $elementuri=NULL){

    // MODAL
    $form['#attached']['library'][] = 'rep/webdoc_modal';
    $form['#attached']['library'][] = 'core/drupal.dialog';
    $base_url = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBaseUrl();
    $form['#attached']['drupalSettings']['webdoc_modal'] = [
      'baseUrl' => $base_url,
    ];
    $form['#attached']['library'][] = 'rep/pdfjs';

    // RETRIEVE REQUESTED ELEMENT
    $uri_decode=base64_decode($elementuri);
    $full_uri = Utils::plainUri($uri_decode);
    $api = \Drupal::service('rep.api_connector');
    $this->setElement($api->parseObjectResponse($api->getUri($full_uri),'getUri'));

    // dpm($this->getElement());

    $objectProperties = GenericObject::inspectObject($this->getElement());

    // dpm($objectProperties);
    //($objectProperties);

    //if ($objectProperties !== null) {
    //    dpm($objectProperties);
    //} else {
    //    dpm("The provided variable is not an object.");
    //}


    // RETRIEVE CONFIGURATION FROM CURRENT IP
    if ($this->getElement() != NULL) {
      $hascoType = $this->getElement()->hascoTypeUri;
      if ($hascoType == VSTOI::INSTRUMENT) {
        $shortName = $this->getElement()->hasShortName;
      }
      if ($hascoType == VSTOI::INSTRUMENT || $hascoType == VSTOI::CODEBOOK) {
        $name = $this->getElement()->label;
      }
      $message = "";
    } else {
      $shortName = "";
      $name = "";
      $message = "<b>FAILED TO RETRIEVE ELEMENT FROM PROVIDED URI</b>";
    }

    //dpm($this->getElement());

    // Instantiate tables
    $tables = new Tables;

    $form['header1'] = [
      '#type' => 'item',
      '#title' => '<h3>Data Properties</h3>',
    ];

    foreach ($objectProperties['literals'] as $propertyName => $propertyValue) {
      // Add a textfield element for each property
      if ($propertyValue !== NULL && $propertyValue !== "") {
        if ($propertyName !== 'hasImageUri' && $propertyName !== 'hasWebDocument') {
          $prettyName = DescribeForm::prettyProperty($propertyName);
          $form[$propertyName] = [
            '#type' => 'markup',
            '#markup' => $this->t("<b>" . $prettyName . "</b>: " . $propertyValue. "<br><br>"),
          ];
        } else if ($propertyName === 'hasWebDocument') {
          // Recupera o URI do elemento.
          $uri = $this->getElement()->uri;

          // Se o URI contiver "#/", extrai a parte após "#/", caso contrário, utiliza o URI completo.
          if (strpos($uri, '#/') !== false) {
            $parts = explode('#/', $uri);
            $uriPart = $parts[1];
          }
          else {
            $uriPart = $uri;
          }

          // Chama o método para obter o documento via API.
          // Supondo que o método getAPIDocument esteja na mesma classe, use self::getAPIDocument() ou o namespace apropriado.
          $documentDataURI = Utils::getAPIDocument($uri, $this->getElement()->hasWebDocument);

          // Se o método não conseguiu retornar um Data URI, você pode exibir uma mensagem de erro ou fallback.
          if (!$documentDataURI) {
            $documentDataURI = '#';
          }

          // Monta o elemento markup para exibir o botão com o atributo que contém o Data URI.
          // Aqui, o JavaScript do modal deverá capturar o atributo "data-view-url" e carregar o conteúdo, por exemplo, num iframe dentro do modal.
          $form['document_link'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['document-link-container']],
          ];

          // Cria o botão com o elemento "html_tag".
          $form['document_link']['button'] = [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('View associated WebDocument'),
            '#attributes' => [
              'class' => ['view-media-button', 'btn', 'btn-primary', 'mb-3'],
              'data-view-url' => $documentDataURI,
              'type' => 'button',
            ],
          ];
        }
      }
    }

    // $form['submit'] = [
    //   '#type' => 'submit',
    //   '#value' => $this->t('Back'),
    //   '#name' => 'back',
    //   '#attributes' => [
    //     'class' => ['btn', 'btn-primary', 'back-button'],
    //   ],
    // ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'back-button'],
        'onclick' => 'if(window.opener){ window.opener.focus(); window.close(); return false; } else { return true; }',
      ],
    ];

    $form['space'] = [
      '#type' => 'markup',
      '#markup' => $this->t("<br><br>"),
    ];

    $form['modal'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div id="modal-container" class="modal-media hidden">
          <div class="modal-content">
            <button class="close-btn" type="button">&times;</button>
            <div id="pdf-scroll-container"></div>
            <div id="modal-content"></div>
          </div>
          <div class="modal-backdrop"></div>
        </div>
      '),
    ];


    return $form;

  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
      self::backUrl();
      return;
  }

  public static function prettyProperty($input) {
    // Remove "has" from the string
    $inputWithoutHas = str_replace('has', '', $input);

    // Add a space before each capital letter (excluding the first character)
    $stringWithSpaces = preg_replace('/(?<!^)([A-Z])/', ' $1', $inputWithoutHas);

    // Capitalize the first term
    $result = ucfirst($stringWithSpaces);

    return $result;
  }

  function backUrl() {
    // $uid = \Drupal::currentUser()->id();
    // $previousUrl = Utils::trackingGetPreviousUrl($uid, 'rep.describe_element');
    // if ($previousUrl) {
    //   $response = new RedirectResponse($previousUrl);
    //   $response->send();
    //   return;
    // }
    $url = Url::fromRoute('rep.element_uri')->toString();
    $response = new RedirectResponse($url);
    $response->send();
    return;
  }

 }
