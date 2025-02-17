<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\rep\ListManagerEmailPage;
use Drupal\rep\Utils;
use Drupal\rep\Entity\MetadataTemplate;
use Drupal\rep\Vocabulary\VSTOI;

class REPSelectMTForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_select_mt_form';
  }

  public $element_type;

  public $manager_email;

  public $manager_name;

  public $single_class_name;

  public $plural_class_name;

  protected $mode;

  protected $list;

  protected $list_size;

  protected $studyuri;

  public function getMode() {
    return $this->mode;
  }

  public function setMode($mode) {
    return $this->mode = $mode;
  }

  public function getList() {
    return $this->list;
  }

  public function setList($list) {
    return $this->list = $list;
  }

  public function getListSize() {
    return $this->list_size;
  }

  public function setListSize($list_size) {
    return $this->list_size = $list_size;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = NULL, $mode = NULL, $page=1, $pagesize=9, $studyuri = NULL)
  {
    // STUDYURI OPTIONAL
    if ($studyuri == NULL) {
      $studyuri = "";
    }
    $this->studyuri = $studyuri;

    // GET MODE
    if ($mode != NULL) {
      $this->setMode($mode);
    }

    // GET MANAGER EMAIL
    $this->manager_email = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $this->manager_name = $user->name->value;

    // GET ELEMENT TYPE
    $this->element_type = $elementtype;
    if ($this->element_type != NULL) {
      $this->setListSize(ListManagerEmailPage::total($this->element_type, $this->manager_email));
    }

    /// GET VIEW MODE
    $session = \Drupal::request()->getSession();
    $view_type = $form_state->get('view_type') ?? $session->get('rep_select_mt_view_type') ?? 'table';
    $form_state->set('view_type', $view_type);

    if ($view_type == 'table') {

      $this->setListSize(-1);
      if ($this->element_type != NULL) {
          $this->setListSize(ListManagerEmailPage::total($this->element_type, $this->manager_email));
      }
      if (gettype($this->list_size) == 'string') {
        $total_pages = "0";
      } else {
        if ($this->list_size % $pagesize == 0) {
            $total_pages = $this->list_size / $pagesize;
        } else {
            $total_pages = (int) floor($this->list_size / $pagesize) + 1;
        }
      }

      // CREATE LINK FOR NEXT PAGE AND PREVIOUS PAGE
      if ($page < $total_pages) {
          $next_page = $page + 1;
          $next_page_link = ListManagerEmailPage::linkdpl($this->element_type, $next_page, $pagesize, 'rep');
      } else {
          $next_page_link = '';
      }
      if ($page > 1) {
          $previous_page = $page - 1;
          $previous_page_link = ListManagerEmailPage::linkdpl($this->element_type, $previous_page, $pagesize, 'rep');
      } else {
          $previous_page_link = '';
      }

      $form_state->set('current_page', $page);
      $form_state->set('page_size', $pagesize);

      $this->setList(ListManagerEmailPage::exec($this->element_type, $this->manager_email, $page, $pagesize));

    } else {
      // SET PAGE_SIZE
      $pagesize = $form_state->get('page_size') ?? $pagesize ?? 9;
      $form_state->set('page_size', $pagesize);
      $this->setList(ListManagerEmailPage::exec($this->element_type, $this->manager_email, 1, $pagesize));
    }

    $this->single_class_name = "";
    $this->plural_class_name = "";
    switch ($this->element_type) {
      case "dsg":
        $this->single_class_name = "DSG";
        $this->plural_class_name = "DSGs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('dsg', $this->getList());
        break;
      case "ins":
        $this->single_class_name = "INS";
        $this->plural_class_name = "INSs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('ins', $this->getList());
        break;
      case "da":
        $this->single_class_name = "DA";
        $this->plural_class_name = "DAs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('da', $this->getList());
        break;
      case "dd":
        $this->single_class_name = "DD";
        $this->plural_class_name = "DDs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('dd', $this->getList());
        break;
      case "sdd":
        $this->single_class_name = "SDD";
        $this->plural_class_name = "SDDs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('sdd', $this->getList());
        break;
      case "dp2":
        $this->single_class_name = "DP2";
        $this->plural_class_name = "DP2s";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('dp2', $this->getList());
        break;
      case "str":
        $this->single_class_name = "STR";
        $this->plural_class_name = "STRs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('str', $this->getList());
        break;
      default:
        \Drupal::messenger()->addError(t("[ERROR] Element [" . $this->element_type . "] is of unknown type."));
        $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
        return;
    }

    // START FORM
    $form['page_title'] = [
      '#type' => 'item',
      '#markup' => '<h3 class="mt-5">Manage ' . $this->plural_class_name . '</h3>',
    ];
    $form['page_subtitle'] = [
      '#type' => 'item',
      '#markup' => $this->t('<h4>@plural_class_name maintained by <font color="DarkGreen">@manager_name (@manager_email)</font></h4>', [
        '@plural_class_name' => $this->plural_class_name,
        '@manager_name' => $this->manager_name,
        '@manager_email' => $this->manager_email,
      ]),
    ];

    // ADD BUTTONS FOR VIEW MODE
    $form['view_toggle'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['view-toggle', 'd-flex', 'justify-content-end']],
    ];

    $form['view_toggle']['table_view'] = [
      '#type' => 'submit',
      '#value' => '',
      '#name' => 'view_table',
      '#attributes' => [
        'style' => 'padding: 20px;',
        'class' => ['table-view-button', 'fa-xl', 'mx-1'],
        'title' => $this->t('Table View'),
      ],
      '#submit' => ['::viewTableSubmit'],
      '#limit_validation_errors' => [],
    ];

    $form['view_toggle']['card_view'] = [
      '#type' => 'submit',
      '#value' => '',
      '#name' => 'view_card',
      '#attributes' => [
        'style' => 'padding: 20px;',
        'class' => ['card-view-button', 'fa-xl'],
        'title' => $this->t('Card View'),
      ],
      '#submit' => ['::viewCardSubmit'],
      '#limit_validation_errors' => [],
    ];

    $form['add_element'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add New ' . $this->single_class_name),
      '#name' => 'add_element',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'add-element-button'],
      ],
    ];

    // RENDER BASED ON VIEW TYPE
    if ($view_type == 'table') {

      $this->buildTableView($form, $form_state, $header, $output);

      $form['pager'] = [
        '#theme' => 'list-page',
        '#items' => [
            'page' => strval($page),
            'first' => ListManagerEmailPage::linkdpl($this->element_type, 1, $pagesize, 'rep'),
            'last' => ListManagerEmailPage::linkdpl($this->element_type, $total_pages, $pagesize, 'rep'),
            'previous' => $previous_page_link,
            'next' => $next_page_link,
            'last_page' => strval($total_pages),
            'links' => null,
            'title' => ' ',
        ],
      ];

    } elseif ($view_type == 'card') {
      $this->buildCardView($form, $form_state, $header, $output);

      $total_items = $this->getListSize();
      $current_page_size = $form_state->get('page_size') ?? 9;

      if ($total_items > $current_page_size) {
        $form['load_more'] = [
          '#type' => 'submit',
          '#value' => $this->t('Load More'),
          '#name' => 'load_more',
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'load-more-button'],
            'id' => 'load-more-button',
            'style' => 'display: none;',
          ],
          '#submit' => ['::loadMoreSubmit'],
          '#limit_validation_errors' => [],
        ];

        // ADD LOADING OVERLAY
        $form['loading_overlay'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'loading-overlay',
            'class' => ['loading-overlay'],
            'style' => 'display: none;',
          ],
          '#markup' => '<div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div>',
        ];

        $form['list_state'] = [
          '#type' => 'hidden',
          '#value' => ($this->getListSize() > $form_state->get('page_size')) ? 1 : 0,
          '#attributes' => [
            'id' => 'list_state',
          ],
        ];
      }
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'back-button'],
      ],
    ];
    $form['space2'] = [
      '#type' => 'item',
      '#markup' => '<br><br><br>',
    ];

    return $form;
  }

  /**
   * HANDLER FOR LOAD MORE BUTTON
   */
  public function loadMoreSubmit(array &$form, FormStateInterface $form_state)
  {
    // Atualiza o tamanho da página para carregar mais itens
    $current_page_size = $form_state->get('page_size') ?? 9;
    $pagesize = $current_page_size + 9; // Soma mais 9 ao tamanho atual
    $form_state->set('page_size', $pagesize);

    // \Drupal::logger('rep_select_mt_form')->notice('Load More Triggered: new page_size @page_size', [
    //     '@page_size' => $pagesize,
    // ]);

    // FORCE REBUILD
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // RETRIEVE TRIGGERING BUTTON
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if (isset($triggering_element['#submit']) && !empty($triggering_element['#submit'])) {
      return;
    }

    // RETRIEVE SELECTED ROWS, IF ANY
    $selected_rows = $form_state->getValue('element_table');
    $rows = [];
    if ($selected_rows) {
      foreach ($selected_rows as $index => $selected) {
        if ($selected) {
          $rows[$index] = $index;
        }
      }
    }

    // Handle actions based on button name
    if ($button_name === 'add_element') {
      $this->performAdd($form_state);
    } elseif ($button_name === 'edit_element') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Please select exactly one " . $this->single_class_name . " to be edited."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Not more than one " . $this->single_class_name . " can be edited simultaneously."));
      } else {
        $first = array_shift($rows);
        $this->performEdit($first, $form_state);
      }
    } elseif ($button_name === 'delete_element') {
      if (sizeof($rows) <= 0) {
        \Drupal::messenger()->addWarning(t("At least one " . $this->single_class_name . " must be selected to delete."));
      } else {
        $this->performDelete($rows, $form_state);
      }
    } elseif ($button_name === 'ingest_mt') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Please select exactly one " . $this->single_class_name . " to be ingested."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Not more than one " . $this->single_class_name . " can be ingested simultaneously."));
      } else {
        $this->performIngest($rows, $form_state, "_");
      }
    } elseif ($button_name === 'ingest_mt_draft') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Please select exactly one " . $this->single_class_name . " to be ingested."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Not more than one " . $this->single_class_name . " can be ingested simultaneously."));
      } else {
        $this->performIngest($rows, $form_state, VSTOI::DRAFT);
      }
    } elseif ($button_name === 'ingest_mt_current') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Please select exactly one " . $this->single_class_name . " to be ingested."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Not more than one " . $this->single_class_name . " can be ingested simultaneously."));
      } else {
        $this->performIngest($rows, $form_state, VSTOI::CURRENT);
      }
    } elseif ($button_name === 'uningest_mt') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Please select exactly one " . $this->single_class_name . " to be uningested."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Not more than one " . $this->single_class_name . " can be uningested simultaneously."));
      } else {
        $this->performUningest($rows, $form_state);
      }
    } elseif ($button_name === 'back') {
      $url = Url::fromRoute('std.search');
      $form_state->setRedirectUrl($url);
    }
  }

  /**
   * BUILD TABLE VIEW
   */
  protected function buildTableView(array &$form, FormStateInterface $form_state, $header, $output)
  {
    $form['edit_selected_element'] = [
      '#type' => 'submit',
      '#value' => $this->t('Edit ' . $this->single_class_name . ' Selected'),
      '#name' => 'edit_element',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'edit-element-button'],
      ],
    ];
    $form['delete_selected_element'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete ' . $this->plural_class_name . ' Selected'),
      '#name' => 'delete_element',
      '#attributes' => [
        'onclick' => 'if(!confirm("Really Delete?")){return false;}',
        'class' => ['btn', 'btn-primary', 'delete-element-button'],
      ],
    ];
    if ($this->element_type == "ins") {
      $uid = \Drupal::currentUser()->id();
      $user = \Drupal\user\Entity\User::load($uid);
      //dpm($user->getRoles());
      if ($user && $user->hasRole('content_editor')) {
        $form['ingest_mt'] = [
          '#type' => 'submit',
          '#value' => $this->t('Ingest ' . $this->single_class_name . ' selected as Draft'),
          '#name' => 'ingest_mt_draft',
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'ingest_mt-button'],
          ],
        ];
        $form['ingest_mt_current'] = [
          '#type' => 'submit',
          '#value' => $this->t('Ingest ' . $this->single_class_name . ' selected as Current'),
          '#name' => 'ingest_mt_current',
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'ingest_mt-button'],
          ],
        ];
      } else {
        $form['ingest_mt'] = [
          '#type' => 'submit',
          '#value' => $this->t('Ingest ' . $this->single_class_name . ' Selected'),
          '#name' => 'ingest_mt_draft',
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'ingest_mt-button'],
          ],
        ];
      }
    } else {
      $form['ingest_mt'] = [
        '#type' => 'submit',
        '#value' => $this->t('Ingest ' . $this->single_class_name . ' Selected'),
        '#name' => 'ingest_mt',
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'ingest_mt-button'],
        ],
      ];    
    }
    $form['uningest_mt'] = [
      '#type' => 'submit',
      '#value' => $this->t('Uningest ' . $this->plural_class_name . ' Selected'),
      '#name' => 'uningest_mt',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'uningest_mt-element-button'],
      ],
    ];
    $form['element_table'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $output,
      '#js_select' => FALSE,
      '#empty' => $this->t('No ' . $this->plural_class_name . ' found'),
    ];
  }

  /**
   * BUILD CARD VIEW
   */
  protected function buildCardView(array &$form, FormStateInterface $form_state, $header, $output)
  {

    // IMAGE PLACEHOLDER
    $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/semVar_placeholder.png';

    $form['element_cards_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'element-cards-wrapper', 'class' => ['row', 'mt-3']],
    ];

    foreach ($output as $key => $item) {
      $sanitized_key = md5($key);

      $form['element_cards_wrapper'][$sanitized_key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-md-4']],
      ];

      $form['element_cards_wrapper'][$sanitized_key]['card'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['card', 'mb-4']],
      ];

      $header_text = '';

      foreach ($header as $column_key => $column_label) {
        if ($column_label == 'Name') {
          $value = isset($item[$column_key]) ? $item[$column_key] : '';
          $header_text = strip_tags($value);
          break;
        }
      }

      // Definir a URL da imagem, usar placeholder se não houver imagem no item
      $image_uri = !empty($item['image']) ? $item['image'] : $placeholder_image;

      if (strlen($header_text) > 0) {
        $form['element_cards_wrapper'][$sanitized_key]['card']['header'] = [
          '#type' => 'container',
          '#attributes' => [
            'style' => 'margin-bottom:0!important;',
            'class' => ['card-header'],
          ],
          '#markup' => '<h5 class="mb-0">' . $header_text . '</h5>',
        ];
      }

      $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['row'],
        ],
      ];

      // Column for the image
      $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper']['image'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['col-md-5', 'texta-align-center'],
          'style' => 'margin-bottom:0!important;text-align:center!important;',
        ],
        'image' => [
          '#type' => 'html_tag',
          '#tag' => 'img',
          '#attributes' => [
              'src' => $image_uri,
              'alt' => $header_text,
              'style' => 'max-width: 70%; height: auto;',
          ]
        ],
      ];

      // Column for main content and footer
      $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper']['content'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['col-md-7', 'card-body'],
          'style' => 'margin-bottom:0!important;',
        ],
      ];

      // Iterando sobre o conteúdo existente e adicionando-o à coluna de conteúdo
      foreach ($header as $column_key => $column_label) {
        $value = isset($item[$column_key]) ? $item[$column_key] : '';
        if ($column_label == 'Name') {
          continue;
        }

        if ($column_label == 'Status') {
          $value_rendered = [
            '#markup' => $value,
            '#allowed_tags' => ['b', 'font', 'span', 'div', 'strong', 'em'],
          ];
        } else {
          $value_rendered = [
            '#markup' => $value,
          ];
        }

        $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper']['content'][$column_key] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['field-container'],
          ],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => $column_label . ': ',
          ],
          'value' => $value_rendered,
        ];
      }

      // Adicionando o rodapé na mesma coluna de conteúdo
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer'] = [
        '#type' => 'container',
        '#attributes' => [
          'style' => 'margin-bottom:0!important;',
          'class' => ['d-flex', 'card-footer', 'justify-content-end'],
        ],
      ];

      // Adicionando os botões ao rodapé
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions'] = [
        '#type' => 'actions',
        '#attributes' => [
          'style' => 'margin-bottom:0!important;',
          'class' => ['mb-0'],
        ],
      ];

      // Botão Editar
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Edit'),
        '#name' => 'edit_element_' . $sanitized_key,
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'btn-sm', 'edit-element-button'],
        ],
        '#submit' => ['::editElementSubmit'],
        '#limit_validation_errors' => [],
        '#element_uri' => $key,
      ];

      // Button Delete
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete'),
        '#name' => 'delete_element_' . $sanitized_key,
        '#attributes' => [
          'class' => ['btn', 'btn-danger', 'btn-sm', 'delete-element-button'],
          'onclick' => 'if(!confirm("Really Delete?")){return false;}',
        ],
        '#submit' => ['::deleteElementSubmit'],
        '#limit_validation_errors' => [],
        '#element_uri' => $key,
      ];

      // Button Ingest
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['ingest'] = [
        '#type' => 'submit',
        '#value' => $this->t('Ingest'),
        '#name' => 'ingest_mt_' . $sanitized_key,
        '#attributes' => [
          'class' => ['btn', 'btn-success', 'btn-sm', 'ingest_mt-button'],
        ],
        '#submit' => ['::ingestElementSubmit'],
        '#limit_validation_errors' => [],
        '#element_uri' => $key,
      ];

      // Button Uningest
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['uningest'] = [
        '#type' => 'submit',
        '#value' => $this->t('Uningest'),
        '#name' => 'uningest_mt_' . $sanitized_key,
        '#attributes' => [
          'class' => ['btn', 'btn-warning', 'btn-sm', 'uningest_mt-element-button'],
        ],
        '#submit' => ['::uningestElementSubmit'],
        '#limit_validation_errors' => [],
        '#element_uri' => $key,
      ];
    }
  }

  /**
   * HANDLER TO CHANGE TO TABLE VIEW
   */
  public function viewTableSubmit(array &$form, FormStateInterface $form_state)
  {
    $form_state->set('view_type', 'table');
    $session = \Drupal::request()->getSession();
    $session->set('rep_select_mt_view_type', 'table');
    $form_state->setRebuild();
  }

  /**
   * HANDLER TO CHANGE TO CARD VIEW
   */
  public function viewCardSubmit(array &$form, FormStateInterface $form_state)
  {
    $form_state->set('view_type', 'card');
    $session = \Drupal::request()->getSession();
    $session->set('rep_select_mt_view_type', 'card');
    $form_state->setRebuild();
  }

  /**
   * HANDLER TO EDIT CARD
   */
  public function editElementSubmit(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performEdit($uri, $form_state);
  }

  /**
   * HANDLER TO DELETE CARD
   */
  public function deleteElementSubmit(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performDelete([$uri], $form_state);
  }

  /**
   * HANDLER TO INGEST CARD
   */
  public function ingestElementSubmit(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performIngest([$uri], $form_state, VSTOI::Draft);
  }

  /**
   * HANDLER TO UNINGEST CARD
   */
  public function uningestElementSubmit(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performUningest([$uri], $form_state);
  }

  /**
   * ADD CARD
   */
  protected function performAdd(FormStateInterface $form_state)
  {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();
    Utils::trackingStoreUrls($uid, $previousUrl, 'rep.add_mt');
    $url = Url::fromRoute('rep.add_mt', [
      'elementtype' => $this->element_type,
      'studyuri' => 'none',
      'fixstd' => 'F',
    ]);
    $form_state->setRedirectUrl($url);
  }

  /**
   * EDIT CARD
   */
  protected function performEdit($uri, FormStateInterface $form_state)
  {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();
    Utils::trackingStoreUrls($uid, $previousUrl, 'rep.edit_mt');
    $url = Url::fromRoute('rep.edit_mt', [
      'elementtype' => $this->element_type,
      'elementuri' => base64_encode($uri),
      'fixstd' => 'F',
    ]);
    $form_state->setRedirectUrl($url);
  }

  /**
   * DELETE CARD
   */
  protected function performDelete(array $uris, FormStateInterface $form_state)
  {
    $api = \Drupal::service('rep.api_connector');
    foreach ($uris as $uri) {
      $mt = $api->parseObjectResponse($api->getUri($uri), 'getUri');
      if ($mt != NULL && $mt->hasDataFile != NULL) {

        // DELETE FILE
        if (isset($mt->hasDataFile->id)) {
          $file = File::load($mt->hasDataFile->id);
          if ($file) {
            $file->delete();
            \Drupal::messenger()->addMessage(t("Archive with ID " . $mt->hasDataFile->id . " deleted."));
          }
        }

        // DELETE DATAFILE
        if (isset($mt->hasDataFile->uri)) {
          $api->dataFileDel($mt->hasDataFile->uri);
          \Drupal::messenger()->addMessage(t("DataFile with URI " . $mt->hasDataFile->uri . " deleted."));
        }
      }
    }
    \Drupal::messenger()->addMessage(t("The " . $this->plural_class_name . " selected were deleted successfully."));
    $form_state->setRebuild();
  }

  /**
   * INGEST CARD
   */
  protected function performIngest(array $uris, FormStateInterface $form_state, String $status) {
    $api = \Drupal::service('rep.api_connector');
    $uri = reset($uris);
    $study = $api->parseObjectResponse($api->getUri($uri), 'getUri');
    if ($study == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve the datafile to be ingested."));
      $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      return;
    }
    $msg = $api->parseObjectResponse($api->uploadTemplate($this->element_type, $study, $status), 'uploadTemplateStatus');
    if ($msg == NULL) {
      \Drupal::messenger()->addError(t("The " . $this->single_class_name . " selected FAILED to be submited for Ingestion."));
      $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      return;
    }
    \Drupal::messenger()->addMessage(t("The " . $this->single_class_name . " selected was successfully submited for Ingestion."));
    $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
    return;
  }

  /**
   * UNINGEST CARD
   */
  protected function performUningest(array $uris, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $uri = reset($uris);
    $newMT = new MetadataTemplate();
    $mt = $api->parseObjectResponse($api->getUri($uri), 'getUri');
    if ($mt == NULL) {
      \Drupal::messenger()->addError(t("Failed to recover " . $this->single_class_name . " for uningestion."));
      return;
    }
    $newMT->setPreservedMT($mt);
    $df = $api->parseObjectResponse($api->getUri($mt->hasDataFileUri), 'getUri');
    if ($df == NULL) {
      \Drupal::messenger()->addError(t("Fail to recover datafile of" . $this->single_class_name . " from being unigested."));
      return;
    }
    $newMT->setPreservedDF($df);
    $msg = $api->parseObjectResponse($api->uningestMT($mt->uri), 'uningestMT');
    if ($msg == NULL) {
      \Drupal::messenger()->addError(t("The " . $this->single_class_name . " selected FAILED to uningested."));
      return;
    }
    $newMT->savePreservedMT($this->element_type);
    \Drupal::messenger()->addMessage(t("The " . $this->single_class_name . " seleted was uningested."));
    return;
  }

  /**
   * {@inheritdoc}
   */
  public static function backSelect($elementType, $mode, $studyuri)
  {
    $url = Url::fromRoute('rep.select_mt_element');
    $url->setRouteParameter('elementtype', $elementType);
    $url->setRouteParameter('mode', $mode);
    $url->setRouteParameter('page', 0);
    $url->setRouteParameter('pagesize', 9);
    if ($studyuri == NULL || $studyuri == '' || $studyuri == ' ') {
      $url->setRouteParameter('studyuri', 'none');
    } else {
      $url->setRouteParameter('studyuri', $studyuri);
    }
    return $url;
  }
}
