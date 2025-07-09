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

    $form['#attached']['html_head'][] = [
      [
        '#tag' => 'script',
        '#attributes' => [],
        '#value' => <<<EOD
          (function () {
            // only set once, on the very first load of this popup
            if (!window.name.startsWith('initialUrl:')) {
              window.name = 'initialUrl:' + window.location.href;
            }
          })();
        EOD,
      ],
      'assoc_project_popup_init',
    ];


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

    // Instantiate tables
    $tables = new Tables;

    $form['header1'] = [
      '#type' => 'item',
      '#title' => '<h3>Data Properties</h3>',
    ];

    foreach ($objectProperties['literals'] as $propertyName => $propertyValue) {

      // Add a textfield element for each property
      if ($propertyValue !== NULL && $propertyValue !== "") {

        $prettyName = DescribeForm::prettyProperty($propertyName);

        if ($propertyName !== 'hasImageUri'
            && $propertyName !== 'hasWebDocument'
            && $propertyName !== 'hasStatus'
            && $propertyName !== 'hasStreamStatus'
            ) {

          $form[$propertyName] = [
            '#type' => 'markup',
            '#markup' => $this->t("<b>" . $prettyName . "</b>: " . $propertyValue. "<br><br>"),
          ];
        } else if ($propertyName === 'hasStatus'
        || $propertyName === 'hasStreamStatus') {
          $form[$propertyName] = [
            '#type' => 'markup',
            '#markup' => $this->t("<b>" . $prettyName . "</b>: " . Utils::plainStatus($propertyValue). "<br><br>"),
          ];
        } else if ($propertyName === 'hasWebDocument') {
          // Retrieve the element’s URI.
          $uri = $this->getElement()->uri;

          // If the URI contains "#/", extract the part after it; otherwise, use the full URI.
          if (strpos($uri, '#/') !== false) {
            $parts = explode('#/', $uri);
            $uriPart = $parts[1];
          } else {
            $uriPart = $uri;
          }

          // Get the hasWebDocument property.
          $hasWebDocument = $this->getElement()->hasWebDocument;

          // Check if hasWebDocument starts with "http".
          if (strpos($hasWebDocument, 'http') === 0 || strpos($hasWebDocument, 'https') === 0) {
            // Display only a link for the user to click.
            $form['document_link'] = [
              '#type' => 'container',
              '#attributes' => ['class' => ['document-link-container']],
            ];
            $form['document_link']['link'] = [
              '#type' => 'link',
              '#title' => $this->t('View associated resource'),
              '#url' => \Drupal\Core\Url::fromUri($hasWebDocument),
              '#attributes' => [
                'class' => ['view-media-link', 'btn', 'btn-primary', 'mb-3'],
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
              ],
            ];
          }
          else {
            // Generate the documentDataURI as before.
            $documentDataURI = Utils::getAPIDocument($uri, $hasWebDocument);
            if (!$documentDataURI) {
              $documentDataURI = '#';
            }

            $form['document_link'] = [
              '#type' => 'container',
              '#attributes' => ['class' => ['document-link-container']],
            ];

            // Create a button using the html_tag element.
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
    }

    // $form['submit'] = [
    //   '#type' => 'submit',
    //   '#value' => $this->t('Back'),
    //   '#name' => 'back',
    //   '#attributes' => [
    //     'class' => ['btn', 'btn-primary', 'back-button'],
    //   ],
    // ];
    // $form['submit'] = [
    //   '#type' => 'submit',
    //   '#value' => $this->t('Back'),
    //   '#name' => 'back',
    //   '#attributes' => [
    //     'class' => ['btn', 'btn-primary', 'back-button'],
    //     'onclick' => 'if(window.opener){ window.opener.focus(); window.close(); return false; } else { return true; }',
    //   ],
    // ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'back-button'],
        'onclick' => <<<EOD
          if (window.opener) {
            // Pull our initial URL out of window.name
            var initial = window.name.replace(/^initialUrl:/, '');
            if (window.location.href !== initial) {
              // Not on first page yet?  Go back in history.
              window.history.back();
            } else {
              // On first page: focus opener and close us.
              window.opener.focus();
              window.close();
            }
            return false;
          }
          // No opener?  Let the form submit (redirect).
          return true;
        EOD,
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
