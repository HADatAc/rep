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

class MTListForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mt_list_form';
  }

  public $element_type;

  public $keyword;

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
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = NULL, $keyword = NULL, $mode = NULL, $page=1, $pagesize=9, $studyuri = NULL)
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
    $table_active_class = ($view_type == 'table') ? ['selected-button'] : [];
    $card_active_class = ($view_type == 'card') ? ['selected-button'] : [];

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

    // Store the incoming keyword (always as a trimmed string).
    $this->keyword = is_string($keyword) ? trim($keyword) : '';

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
      case "kgr":
        $this->single_class_name = "KGR";
        $this->plural_class_name = "KGRs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('kgr', $this->getList());
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
      case "wkf":
        $this->single_class_name = "WKF";
        $this->plural_class_name = "WKFs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('wkf', $this->getList());
        break;
      default:
        \Drupal::messenger()->addError(t("[ERROR] Element [" . $this->element_type . "] is of unknown type."));
        $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
        return;
    }

    // If a keyword is present, filter the output accordingly.
    if ($this->keyword !== '') {
      $filtered = $this->filterOutputByKeyword($output, $this->keyword);

      if (empty($filtered)) {
        // English message as requested (user-facing).
        $no_results_msg = $this->t('No results were found for the searched term: "@term".', ['@term' => $this->keyword]);
        // Keep a per-request message available for both table and card modes.
        $form_state->set('empty_msg', $no_results_msg);
        // Also replace $output with an empty array so builders can react accordingly.
        $output = [];
      } else {
        $output = $filtered;
      }
    }

    // kint([
    //   "Keyword" => $keyword,
    //   "element_type" => $this->element_type,
    //   "manager_email" => $this->manager_email,
    //   "manager_name" => $this->manager_name,
    //   "studyuri" => $this->studyuri,
    //   "mode" => $this->getMode(),
    //   "list_size" => $this->getListSize(),
    //   "page" => $page,
    //   "pagesize" => $pagesize,
    //   "form_page_size" => $form_state->get('page_size'),
    //   "view_type" => $form_state->get('view_type'),
    //   "current_page" => $form_state->get('current_page'),
    //   "list_state" => $form_state->get('list_state'),
    // ]);

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
        'class' => array_merge(['table-view-button', 'fa-xl', 'mx-1'], $table_active_class),
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
        'class' => array_merge(['card-view-button', 'fa-xl'], $card_active_class),
        'title' => $this->t('Card View'),
      ],
      '#submit' => ['::viewCardSubmit'],
      '#limit_validation_errors' => [],
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

      $form['records_count'] = [
        '#type' => 'item',
        '#markup' => $this->t('<div id="count-cards" style="font-weight:bold; margin-top:10px; padding-right:2rem;">Currently viewing @count of @total @class</div>', [
          '@count' => count($this->getList()),
          '@total' => (int) $this->getListSize(),
          '@class' => $this->plural_class_name,
        ]),
      ];

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

    $form['space2'] = [
      '#type' => 'item',
      '#markup' => '<br><br><br>',
    ];

    $form['#attached']['library'][] = 'rep/mtlist_styles';

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
  }

  /**
   * BUILD TABLE VIEW
   */
  protected function buildTableView(array &$form, FormStateInterface $form_state, $header, $output)
  {
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);

    // Choose an "empty" message. If a keyword was used and nothing matched, prefer that message.
    $empty_msg = $form_state->get('empty_msg') ?? $this->t('No ' . $this->plural_class_name . ' found');

    $form['element_table'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $output,
      '#js_select' => FALSE,
      // Must be in English per requirements.
      '#empty' => $empty_msg,
    ];
  }

  /**
   * BUILD CARD VIEW
   */
  protected function buildCardView(array &$form, FormStateInterface $form_state, $header, $output)
  {
    // If there are no cards to show, print a single English message and return early.
    if (empty($output)) {
      $msg = $form_state->get('empty_msg') ?? $this->t('No items were found.');
      $form['element_cards_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['row', 'mt-3']],
        'no_results' => [
          '#type' => 'item',
          // Must be in English per requirements.
          '#markup' => '<div class="alert alert-info" role="alert">' . $msg . '</div>',
        ],
      ];
      return;
    }

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

      // Define image URL with placeholder fallback.
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

      // Render all fields except "Name" (already in header).
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

      // Footer with actions (unchanged)
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer'] = [
        '#type' => 'container',
        '#attributes' => [
          'style' => 'margin-bottom:0!important;',
          'class' => ['d-flex', 'card-footer', 'justify-content-end'],
        ],
      ];

      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions'] = [
        '#type' => 'actions',
        '#attributes' => [
          'style' => 'margin-bottom:0!important;',
          'class' => ['mb-0'],
        ],
      ];

      // Edit
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

      // Delete
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

      // Ingest
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

      // Uningest
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

    $this->performIngest([$uri], $form_state, VSTOI::DRAFT);
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
   * ADD FUNCTION
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
   * EDIT FUNCTION
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
   * DELETE FUNCTION
  */
  protected function performDelete(array $uris, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $file_system = \Drupal::service('file_system');

    foreach ($uris as $uri) {
        $mt = $api->parseObjectResponse($api->getUri($uri), 'getUri');
        if ($mt != NULL && $mt->hasDataFile != NULL) {

            // DELETE FILE
            if (isset($mt->hasDataFile->id)) {
                $file = File::load($mt->hasDataFile->id);
                if ($file) {
                    // Remove referências do file_usage
                    \Drupal::service('file.usage')->delete($file, 'custom_module', 'entity_type', $file->id());

                    // Obtém o caminho real do ficheiro
                    $file_path = $file->getFileUri();
                    $real_path = $file_system->realpath($file_path);

                    // Eliminar o ficheiro fisicamente
                    if ($real_path && file_exists($real_path)) {
                        $file_system->delete($file_path);
                    }

                    // Remover da base de dados
                    \Drupal::database()->delete('file_managed')->condition('fid', $file->id())->execute();

                    // Irrelevant info for user
                    // \Drupal::messenger()->addMessage(t("File with ID " . $mt->hasDataFile->id . " deleted."));
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
    \Drupal::service('cache.default')->invalidateAll();
    $form_state->setRebuild();
  }

  /**
   * INGEST FUNCTION
   */
  protected function performIngest(array $uris, FormStateInterface $form_state, String $status) {
    //($status);
    $api = \Drupal::service('rep.api_connector');
    $uri = reset($uris);
    $template = $api->parseObjectResponse($api->getUri($uri), 'getUri');
    if ($template == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve the datafile to be ingested."));
      $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      return;
    }
    
    // FIX: If template doesn't have hasDataFile embedded, fetch it separately
    if (!isset($template->hasDataFile) && isset($template->hasDataFileUri)) {
      \Drupal::logger('rep')->notice('performIngest: Template missing hasDataFile, fetching separately from: @uri', [
        '@uri' => $template->hasDataFileUri,
      ]);
      
      $dataFile = $api->parseObjectResponse($api->getUri($template->hasDataFileUri), 'getUri');
      if ($dataFile != NULL) {
        $template->hasDataFile = $dataFile;
        \Drupal::logger('rep')->notice('performIngest: DataFile attached - id: @id, filename: @filename', [
          '@id' => isset($dataFile->id) ? $dataFile->id : 'NULL',
          '@filename' => isset($dataFile->filename) ? $dataFile->filename : 'NULL',
        ]);
      } else {
        \Drupal::logger('rep')->warning('performIngest: Failed to retrieve DataFile from: @uri', [
          '@uri' => $template->hasDataFileUri,
        ]);
      }
    }
    
    $msg = $api->parseObjectResponse($api->uploadTemplate($this->element_type, $template, $status), 'uploadTemplateStatus');
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
   * UNINGEST FUNCTION
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

  /**
   * Filters the given $output rows by $keyword (case-insensitive).
   * It searches across all scalar fields in each row, stripping HTML tags.
   *
   * @param array $output
   * @param string $keyword
   * @return array Filtered array preserving the original keys.
   */
  protected function filterOutputByKeyword(array $output, string $keyword): array
  {
    $needle = mb_strtolower($keyword);
    $filtered = [];

    foreach ($output as $row_key => $row) {
      // Each $row is expected to be an associative array of column_key => string/renderable.
      foreach ($row as $col_key => $value) {
        // Convert value to plain string for matching.
        if (is_array($value)) {
          // If a render array slips in, try to get a string-ish representation.
          $value_str = strip_tags((string) \Drupal::service('renderer')->renderPlain($value));
        } else {
          $value_str = strip_tags((string) $value);
        }

        if ($value_str !== '' && mb_stripos(mb_strtolower($value_str), $needle) !== false) {
          $filtered[$row_key] = $row;
          break; // Found a match in this row, move to next row.
        }
      }
    }

    return $filtered;
  }

}
