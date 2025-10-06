<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\VSTOI;

class MTSearchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mtsearchform';
  }

  protected $elementtype;

  protected $keyword;

  protected $page;

  protected $pagesize;

  public function getElementType() {
    return $this->elementtype;
  }

  public function setElementType($type) {
    return $this->elementtype = $type;
  }

  public function getKeyword() {
    return $this->keyword;
  }

  public function setKeyword($kw) {
    return $this->keyword = $kw;
  }

  public function getPage() {
    return $this->page;
  }

  public function setPage($pg) {
    return $this->page = $pg;
  }

  public function getPageSize() {
    return $this->pagesize;
  }

  public function setPageSize($pgsize) {
    return $this->pagesize = $pgsize;
  }

  public function iconSubmitForm(array &$form, FormStateInterface $form_state) {
    $clicked_button = $form_state->getTriggeringElement()['#name'];
    $form_state->setValue('search_element_type', $clicked_button);
    $form_state->setValue('search_keyword', '');
    // Keep pagination predictable when switching element type.
    $this->setPage(1);
    $this->setPageSize(12);
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'rep/mtsearch_icons';


    // RETRIEVE PARAMETERS FROM HTML REQUEST
    $request = \Drupal::request();
    $pathInfo = $request->getPathInfo();
    $pathElements = (explode('/',$pathInfo));
    $this->setElementType('ins');
    $this->setKeyword('');
    $this->setPage(1);
    $this->setPageSize(12);

    // IT IS A CLASS ELEMENT if size of path elements is equal 5
    if (sizeof($pathElements) == 5) {

          // ELEMENT TYPE
          $this->setElementType($pathElements[4]);

    // IT IS AN INSTANCE ELEMENT if size of path elements is greate or equal 7
    } else if (sizeof($pathElements) >= 7) {

      // ELEMENT TYPE
      $this->setElementType($pathElements[3]);

      // KEYWORD
      if ($pathElements[4] == '_') {
        $this->setKeyword('');
      } else {
        $this->setKeyword($pathElements[4]);
      }

      // PAGE
      $this->setPage((int)$pathElements[5]);

      // PAGESIZE
      $this->setPageSize((int)$pathElements[6]);
    }

    // Ensure we never keep an empty element type; default to 'ins'.
    if (!is_string($this->getElementType()) || $this->getElementType() === '') {
      $this->setElementType('ins');
    }

    $preferred_instrument = \Drupal::config('rep.settings')->get('preferred_instrument');
    $preferred_component = \Drupal::config('rep.settings')->get('preferred_component') ?? 'Component';

    $form['element_icons'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['element-icons-grid-wrapper']],
    ];

    $form['element_icons']['grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['element-icons-grid']],
    ];

    $element_types = [
      'ins' => ['label' => 'INS', 'image' => 'white/ins_placeholder.png'],
      'dsg' => ['label' => 'DSG', 'image' => 'white/dsg_placeholder.png'],
      'dd'  => ['label' => 'DD', 'image' => 'white/dd_placeholder.png'],
      'sdd' => ['label' => 'SDD', 'image' => 'white/sdd_placeholder.png'],
      'dp2' => ['label' => 'DP2', 'image' => 'white/dp2_placeholder.png'],
      'str' => ['label' => 'STR', 'image' => 'white/str_placeholder.png'],
    ];

    foreach ($element_types as $type => $info) {

      $module_path = \Drupal::request()->getBaseUrl(). '/' . \Drupal::service('extension.list.module')->getPath('rep');
      $placeholder_image = $module_path . '/images/placeholders/' . $info['image'];

      $button_classes = ['element-icon-button'];
      if ($type === $this->getElementType()) {
        $button_classes[] = 'selected';
      }

      $form['element_icons']['grid'][$type] = [
        '#type' => 'submit',
        '#value' => '',
        '#attributes' => [
        'class' => $button_classes,
        'style' => "background-image: url('$placeholder_image');",
        'title' => $this->t($info['label']),
        'aria-label' => $this->t($info['label']),
        ],
        '#name' => $type,
        '#submit' => ['::iconSubmitForm'],
        '#limit_validation_errors' => [],
        '#ajax' => [
        'callback' => '::ajaxSubmitForm',
        'progress' => [
          'type' => 'none',
          ],
        ],
      ];
    }

    $form['search_element_type'] = [
      '#type' => 'hidden',
      '#value' => $this->getElementType() ?: 'ins',
    ];

    $form['search_keyword'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Keyword'),
      '#default_value' => $this->getKeyword(),
    ];
    $form['search_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'search-button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // if(strlen($form_state->getValue('search_element_type')) < 1) {
    //   $form_state->setErrorByName('search_element_type', $this->t('Please select an element type'));
    // }
  }

  /**
   * {@inheritdoc}
   */
  private function redirectUrl(FormStateInterface $form_state) {

    // Debug only, remove if you want.
    // dpm($form_state->getValues());

    // --- Resolve element type with safe fallbacks ---
    // Prefer the value from the submitted form, then the current property, then default 'ins'.
    $type = $form_state->getValue('search_element_type');
    if (!is_string($type) || $type === '') {
      $type = $this->getElementType();
    }
    if (!is_string($type) || $type === '') {
      $type = 'ins'; // safe default
    }
    $this->setElementType($type);

    // --- Normalize keyword for route segment ---
    $kw = (string) $form_state->getValue('search_keyword');
    $kw = trim($kw);
    if ($kw === '' || strtolower($kw) === 'none') {
      $kw = '_'; // we use "_" to mean "no keyword"
    }
    // Route path segment cannot contain slashes; replace them to keep "[^/]+"
    $kw = str_replace('/', '-', $kw);
    $this->setKeyword($kw);

    // Keep paging defaults or current ones
    $page = $this->getPage() ?: 1;
    $pagesize = $this->getPageSize() ?: 12;

    $url = Url::fromRoute('rep.mt_list_element', [
      'elementtype' => $this->getElementType(),
      'keyword'     => $this->getKeyword(),
      'page'        => $page,
      'pagesize'    => $pagesize,
    ]);

    return $url;
  }


  /**
   * {@inheritdoc}
   */
  public function ajaxSubmitForm(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $this->setPage(1);
    $this->setPageSize(12);
    $url = $this->redirectUrl($form_state);
    $response->addCommand(new RedirectCommand($url->toString()));
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $url = $this->redirectUrl($form_state);
    $form_state->setRedirectUrl($url);
  }

}
