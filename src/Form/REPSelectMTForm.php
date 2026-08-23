<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\file\Entity\File;
use Drupal\rep\ListManagerEmailPage;
use Drupal\rep\ManageOwnerFilter;
use Drupal\rep\Utils;
use Drupal\rep\Entity\MetadataTemplate;
use Drupal\rep\Vocabulary\VSTOI;

class REPSelectMTForm extends FormBase {

  private function normalizeStatusFilter($status_filter): string {
    $value = trim((string) $status_filter);
    if ($value === '' || $value === '_' || strtolower($value) === 'none') {
      return '_';
    }

    $allowed = [
      (string) VSTOI::DRAFT,
      (string) VSTOI::UNDER_REVIEW,
      (string) VSTOI::CURRENT,
      (string) VSTOI::DEPRECATED,
    ];

    return in_array($value, $allowed, TRUE) ? $value : '_';
  }

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

  /**
   * Per-request cache for WKF URI -> owner affiliation organization label.
   *
   * @var array<string, string>
   */
  protected $wkfOwnerOrganizationLabelCache = [];

  /**
   * Per-request cache for owner email -> affiliation organization label.
   *
   * @var array<string, string>
   */
  protected $ownerAffiliationLabelByEmailCache = [];

  /**
   * Per-request cache for WKF URI -> owner email.
   *
   * @var array<string, string>
   */
  protected $wkfOwnerEmailCache = [];

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
    // This management form contains dynamic, role-scoped content and custom JS;
    // disable render caching to prevent stale WKF generation UI fragments.
    $form['#cache']['max-age'] = 0;

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
    if ($this->element_type === 'wkf') {
      $this->suppressWkfSelectPageMessages();
      $this->suppressLegacyWkfValidationMessages();
    }
    if ($this->element_type != NULL) {
      $this->setListSize(ListManagerEmailPage::total($this->element_type, $this->manager_email));
    }

    /// GET VIEW MODE + FILTER STATE
    $session = \Drupal::request()->getSession();
    $view_type = $form_state->get('view_type') ?? $session->get('rep_select_mt_view_type') ?? 'table';
    $form_state->set('view_type', $view_type);
    $table_active_class = ($view_type === 'table') ? ['selected-button'] : [];
    $card_active_class = ($view_type === 'card') ? ['selected-button'] : [];

    if ($view_type === 'card') {
      $form['#attached']['library'][] = 'rep/infinitescroll';
    }

    $status_filter_key = 'rep_select_mt_status_filter.' . (string) $elementtype;
    $status_filter = $form_state->getValue('status_filter');
    if ($status_filter === NULL) {
      $status_filter = $session->get($status_filter_key, '_');
    }
    else {
      $session->set($status_filter_key, $status_filter);
    }
    $status_filter = $this->normalizeStatusFilter($status_filter);
    $session->set($status_filter_key, $status_filter);

    $is_admin = ManageOwnerFilter::isAdmin();
    $manager_filter_key = 'rep_select_mt_manager_filter.' . (string) $elementtype;
    $manager_filter = $form_state->getValue('manager_filter');
    if ($manager_filter === NULL) {
      $manager_filter = $session->get($manager_filter_key, '');
    }
    else {
      $manager_filter = ManageOwnerFilter::normalizeSelectedEmail($manager_filter);
      $session->set($manager_filter_key, $manager_filter);
    }

    $effective_manager_email = ManageOwnerFilter::resolveEffectiveOwner($this->manager_email, $manager_filter, $status_filter);

    if ($view_type == 'table') {

      // Total + list (optionally filtered by status)
      $this->setListSize(-1);
      if ($this->element_type != NULL) {
        if ($status_filter === '_' || $status_filter === NULL || $status_filter === '') {
          $this->setListSize(ListManagerEmailPage::total($this->element_type, $effective_manager_email));
        }
        else {
          $this->setListSize(ListManagerEmailPage::totalByStatusManagerEmail($this->element_type, $status_filter, $effective_manager_email, FALSE));
        }
      }

      // Total pages (at least 1)
      $total_pages = 1;
      if (is_numeric($this->list_size) && $pagesize > 0) {
        $size = (int) $this->list_size;
        if ($size > 0) {
          $total_pages = (int) ceil($size / $pagesize);
        }
      }

      // Clamp current page
      $page = max(1, min((int) $page, (int) $total_pages));

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

      if ($status_filter === '_' || $status_filter === NULL || $status_filter === '') {
        $this->setList(ListManagerEmailPage::exec($this->element_type, $effective_manager_email, $page, $pagesize));
      }
      else {
        $this->setList(ListManagerEmailPage::execByStatusManagerEmail($this->element_type, $status_filter, $effective_manager_email, FALSE, $page, $pagesize));
      }

    } else {
      // SET PAGE_SIZE
      $pagesize = $form_state->get('page_size') ?? $pagesize ?? 9;
      $form_state->set('page_size', $pagesize);

      // Total + list (optionally filtered by status) for card view too.
      $this->setListSize(-1);
      if ($this->element_type != NULL) {
        if ($status_filter === '_' || $status_filter === NULL || $status_filter === '') {
          $this->setListSize(ListManagerEmailPage::total($this->element_type, $effective_manager_email));
        }
        else {
          $this->setListSize(ListManagerEmailPage::totalByStatusManagerEmail($this->element_type, $status_filter, $effective_manager_email, FALSE));
        }
      }

      if ($status_filter === '_' || $status_filter === NULL || $status_filter === '') {
        $this->setList(ListManagerEmailPage::exec($this->element_type, $effective_manager_email, 1, $pagesize));
      }
      else {
        $this->setList(ListManagerEmailPage::execByStatusManagerEmail($this->element_type, $status_filter, $effective_manager_email, FALSE, 1, $pagesize));
      }
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
        $form_state->setRedirectUrl(static::backSelect($this->element_type, $this->getMode(), $this->studyuri));
        return;
    }

    // START FORM
    $form['page_title'] = [
      '#type' => 'item',
      '#markup' => '<h3 class="mt-5">Manage ' . $this->plural_class_name . '</h3>',
    ];
    $subtitleManagerLabel = ($effective_manager_email === '_')
      ? $this->t('all owners')
      : $this->manager_name . ' (' . $this->manager_email . ')';
    $form['page_subtitle'] = [
      '#type' => 'item',
      '#markup' => $this->t('<h4>@plural_class_name maintained by <font color="DarkGreen">@manager_label</font></h4>', [
        '@plural_class_name' => $this->plural_class_name,
        '@manager_label' => $subtitleManagerLabel,
      ]),
    ];

    // WKF generation/validation UI is specialized in REPSelectWKFForm.
    if ($this->element_type === 'wkf') {
      $this->buildWkfSpecializedSection($form, $form_state);
    }

    $show_owner_indicator = $is_admin && $manager_filter !== '' && strcasecmp($effective_manager_email, $manager_filter) === 0;
    if ($show_owner_indicator) {
      $form['owner_indicator'] = [
        '#type' => 'item',
        '#markup' => $this->t('<div class="alert alert-info py-2 mb-3"><strong>A visualizar owner:</strong> @owner</div>', [
          '@owner' => $effective_manager_email,
        ]),
      ];
    }

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

    // Actions row (buttons + collapsible filters)
    $form['actions_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'flex-column', 'align-items-stretch', 'mb-0', 'rep-manage-toolbar'],
      ],
    ];

    $form['actions_wrapper']['buttons_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'gap-2', 'flex-nowrap', 'justify-content-start', 'mb-2', 'rep-manage-buttons'],
        'style' => 'flex-wrap:nowrap;overflow-x:auto;'
      ],
    ];

    if ($this->single_class_name !== 'WKF') {
      $form['actions_wrapper']['buttons_container']['add_element'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add New ' . $this->single_class_name),
        '#name' => 'add_element',
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'add-element-button'],
        ],
      ];
    }

    if ($view_type == 'table') {
      $form['actions_wrapper']['buttons_container']['edit_selected_element'] = [
        '#type' => 'submit',
        '#value' => $this->t(
          $this->single_class_name === 'WKF'
            ? 'Edit Selected WKF'
            : 'Edit ' . $this->single_class_name . ' Selected'
        ),
        '#name' => 'edit_element',
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'edit-element-button'],
        ],
      ];

      if ($this->single_class_name === 'WKF') {
        $form['actions_wrapper']['buttons_container']['validate_selected_wkf'] = [
          '#type' => 'submit',
          '#value' => $this->t('Validate Selected WKF'),
          '#name' => 'validate_selected_wkf',
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'validate-wkf-button'],
          ],
        ];
      }

      if ($this->single_class_name !== 'WKF') {
        $form['actions_wrapper']['buttons_container']['delete_selected_element'] = [
          '#type' => 'submit',
          '#value' => $this->t('Delete ' . $this->plural_class_name . ' Selected'),
          '#name' => 'delete_element',
          '#attributes' => [
            'onclick' => 'if(!confirm("Really Delete?")){return false;}',
            'class' => ['btn', 'btn-primary', 'delete-element-button'],
          ],
        ];
      }

      $uid = \Drupal::currentUser()->id();
      $user = \Drupal\user\Entity\User::load($uid);
      if ($user && $user->hasRole('content_editor')) {
        if ($this->single_class_name !== 'WKF') {
          $form['actions_wrapper']['buttons_container']['ingest_mt'] = [
            '#type' => 'submit',
            '#value' => $this->t('Ingest ' . $this->single_class_name . ' Selected as Draft'),
            '#name' => 'ingest_mt_draft',
            '#attributes' => [
              'onclick' => 'if(!confirm("Really Ingest file has DRAFT?")){return false;}',
              'class' => ['btn', 'btn-primary', 'ingest_mt-button'],
            ],
          ];
        }

        $form['actions_wrapper']['buttons_container']['ingest_mt_current'] = [
          '#type' => 'submit',
          '#value' => $this->t(
            $this->single_class_name === 'WKF'
              ? 'Ingest Selected WKF'
              : 'Ingest ' . $this->single_class_name . ' selected as Current'
          ),
          '#name' => 'ingest_mt_current',
          '#attributes' => [
            'onclick' => 'if(!confirm("Really Ingest file has CURRENT?")){return false;}',
            'class' => ['btn', 'btn-primary', 'ingest_mt-button'],
          ],
        ];

        $form['actions_wrapper']['buttons_container']['uningest_mt'] = [
          '#type' => 'submit',
          '#value' => $this->t(
            $this->single_class_name === 'WKF'
              ? 'Uningest Selected WKF'
              : 'Uningest ' . $this->plural_class_name . ' Selected'
          ),
          '#name' => 'uningest_mt',
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'uningest_mt-element-button'],
          ],
        ];
      }

      if ($this->single_class_name === 'WKF') {
        $form['actions_wrapper']['buttons_container']['delete_selected_element'] = [
          '#type' => 'submit',
          '#value' => $this->t('Delete Selected WKF'),
          '#name' => 'delete_element',
          '#attributes' => [
            'onclick' => 'if(!confirm("Really Delete?")){return false;}',
            'class' => ['btn', 'btn-primary', 'delete-element-button'],
          ],
        ];
      }
    }

    $status_options = [
      '_' => $this->t('All Status'),
      VSTOI::DRAFT => $this->t('Draft'),
      VSTOI::UNDER_REVIEW => $this->t('Under Review'),
      VSTOI::CURRENT => $this->t('Current'),
      VSTOI::DEPRECATED => $this->t('Deprecated'),
    ];

    $has_active_filters = ($status_filter !== '_' && $status_filter !== NULL && $status_filter !== '')
      || ($is_admin && trim((string) $manager_filter) !== '');

    $ajax_wrapper = ($view_type === 'card') ? 'cards-lazy-wrapper' : 'element-table-wrapper';
    $ajax_callback = ($view_type === 'card') ? '::ajaxReloadCards' : '::ajaxReloadTable';

    $form['actions_wrapper']['filters_panel'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter(s)'),
      '#open' => $has_active_filters,
      '#attributes' => [
        'class' => ['rep-manage-filters-panel', 'w-100'],
      ],
    ];

    $form['actions_wrapper']['filters_panel']['filter_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'g-2', 'align-items-end', 'rep-manage-filters'],
      ],
    ];

    if ($is_admin) {
      $form['actions_wrapper']['filters_panel']['filter_container']['manager_filter'] = [
        '#type' => 'textfield',
        '#title' => $this->t('User'),
        '#title_display' => 'invisible',
        '#default_value' => $manager_filter,
        '#prefix' => '<div class="col-12 col-lg-7">',
        '#suffix' => '</div>',
        '#ajax' => [
          'callback' => $ajax_callback,
          'wrapper' => $ajax_wrapper,
          'event' => 'change',
        ],
        '#attributes' => [
          'class' => ['form-control'],
          'placeholder' => $this->t('User email (Draft/Under Review)'),
        ],
      ];
    }

    $form['actions_wrapper']['filters_panel']['filter_container']['status_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#title_display' => 'invisible',
      '#options' => $status_options,
      '#default_value' => $status_filter,
      '#prefix' => '<div class="col-12 col-md-6 col-lg-3">',
      '#suffix' => '</div>',
      '#ajax' => [
        'callback' => $ajax_callback,
        'wrapper' => $ajax_wrapper,
        'event' => 'change',
      ],
      '#attributes' => [
        'class' => ['form-select'],
      ],
    ];

    $form['actions_wrapper']['filters_panel']['filter_container']['clear_filters'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Filters'),
      '#name' => 'clear_filters',
      '#limit_validation_errors' => [],
      '#prefix' => '<div class="col-12 col-md-6 col-lg-2 d-grid">',
      '#suffix' => '</div>',
      '#attributes' => [
        'class' => ['btn', 'btn-outline-secondary'],
      ],
    ];

    // RENDER BASED ON VIEW TYPE
    if ($view_type == 'table') {

      $form['element_table_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'element-table-wrapper'],
      ];

      $this->buildTableView($form['element_table_wrapper'], $form_state, $header, $output);

      $form['element_table_wrapper']['pager'] = [
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
      $form['cards_lazy_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'cards-lazy-wrapper'],
      ];

      $this->buildCardView($form['cards_lazy_wrapper'], $form_state, $header, $output);

      $visible_count = is_array($this->getList()) ? count($this->getList()) : 0;
      $total_count = max((int) $this->getListSize(), $visible_count);

      $form['cards_lazy_wrapper']['records_count'] = [
        '#type' => 'item',
        '#markup' => $this->t('<div id="count-cards" style="font-weight:bold; margin-top:10px; padding-right:2rem;">Currently viewing @count of @total @class</div>', [
          '@count' => $visible_count,
          '@total' => $total_count,
          '@class' => $this->plural_class_name,
        ]),
      ];

      $total_items = $this->getListSize();
      $current_page_size = $form_state->get('page_size') ?? 9;

      if ($total_items > $current_page_size) {
        $form['cards_lazy_wrapper']['load_more'] = [
          '#type' => 'submit',
          '#value' => $this->t('Load More'),
          '#name' => 'load_more',
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'load-more-button'],
            'id' => 'load-more-button',
            'style' => 'display: none;',
          ],
          '#submit' => ['::loadMoreSubmit'],
          '#ajax' => [
            'callback' => '::ajaxReloadCards',
            'wrapper' => 'cards-lazy-wrapper',
            'event' => 'click',
          ],
          '#limit_validation_errors' => [],
        ];

        // ADD LOADING OVERLAY
        $form['cards_lazy_wrapper']['loading_overlay'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'loading-overlay',
            'class' => ['loading-overlay'],
            'style' => 'display: none;',
          ],
          '#markup' => '<div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div>',
        ];

        $form['cards_lazy_wrapper']['list_state'] = [
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
   * Hook for WKF-only UI blocks.
   *
   * Base MT form keeps this empty; REPSelectWKFForm owns the specialized
  * 4-phase generation and validation panel UI.
   */
  protected function buildWkfSpecializedSection(array &$form, FormStateInterface $form_state): void {
    // Intentionally empty in the generic MT form.
  }

  /**
   * AJAX callback to reload list when filters change.
   */
  public function ajaxReloadTable(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['element_table_wrapper'];
  }

  /**
   * AJAX callback to reload cards wrapper when loading more.
   */
  public function ajaxReloadCards(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['cards_lazy_wrapper'];
  }

  /**
   * AJAX callback to refresh WKF validation panel wrapper.
   */
  public function ajaxClearWkfValidationPanel(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['wkf_validation_panel_wrapper'];
  }

  /**
   * Submit handler for clearing WKF validation panel state.
   */
  public function clearWkfValidationPanelSubmit(array &$form, FormStateInterface $form_state) {
    $this->setSkipLegacyWkfValidationRecovery(TRUE);
    $this->clearWkfValidationPanelData();
    // Clear all pending messages so nothing repopulates the panel after clear.
    \Drupal::messenger()->deleteAll();
    $form_state->setRebuild(TRUE);
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

    if ($button_name === 'clear_filters') {
      $this->clearSavedFilters($form_state);
      return;
    }

    if ($button_name === 'clear_wkf_validation_panel') {
      $this->clearWkfValidationPanelData();
      $form_state->setRedirectUrl(static::backSelect($this->element_type, $this->getMode(), $this->studyuri));
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
    } elseif ($button_name === 'validate_selected_wkf') {
      if (sizeof($rows) < 1) {
        $this->setWkfValidationPanelData([
          'valid' => FALSE,
          'summary' => 'Please select exactly one WKF to be validated.',
          'wkfUri' => '',
          'rules' => [],
          'validatedAt' => date('Y-m-d H:i:s'),
        ]);
        $form_state->setRedirectUrl(static::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      } else if ((sizeof($rows) > 1)) {
        $this->setWkfValidationPanelData([
          'valid' => FALSE,
          'summary' => 'Not more than one WKF can be validated simultaneously.',
          'wkfUri' => '',
          'rules' => [],
          'validatedAt' => date('Y-m-d H:i:s'),
        ]);
        $form_state->setRedirectUrl(static::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      } else {
        $first = array_shift($rows);
        $this->performValidateWKF($first, $form_state);
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
   * Clear persisted filters for MT list/select pages.
   */
  protected function clearSavedFilters(FormStateInterface $form_state): void {
    $session = \Drupal::request()->getSession();
    $suffix = (string) $this->element_type;

    $session->remove('rep_select_mt_status_filter.' . $suffix);
    $session->remove('rep_select_mt_manager_filter.' . $suffix);

    $input = $form_state->getUserInput();
    unset($input['status_filter'], $input['manager_filter']);
    $form_state->setUserInput($input);

    $form_state->setValue('status_filter', '_');
    $form_state->setValue('manager_filter', '');
    $form_state->setRebuild(TRUE);
  }

  /**
   * BUILD TABLE VIEW
   */
  protected function buildTableView(array &$form, FormStateInterface $form_state, $header, $output)
  {
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
    $placeholder_image = '';
    switch ($this->element_type) {
      case 'ins':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/ins_placeholder.png';
        break;
      case 'dsg':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/dsg_placeholder.png';
        break;
      case 'dd':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/dd_placeholder.png';
        break;
      case 'sdd':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/sdd_placeholder.png';
        break;
      case 'dp2':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/dp2_placeholder.png';
        break;
      case 'str':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/str_placeholder.png';
        break;
    }

    $form['element_cards_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'element-cards-wrapper', 'class' => ['row', 'mt-3']],
    ];

    if (empty($output)) {
      $form['element_cards_wrapper']['no_results'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-12']],
        'message' => [
          '#markup' => '<div class="alert alert-info mb-0">'
            . $this->t('No @items found for the current filters.', ['@items' => $this->plural_class_name])
            . '</div>',
        ],
      ];
      return;
    }

    foreach ($output as $key => $item) {
      $sanitized_key = md5($key);
      $wkfPhaseControlsMarkup = '';

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

      if ($this->element_type !== 'wkf') {
        // Column for the image.
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

        // Column for main content and footer.
        $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper']['content'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['col-md-7', 'card-body'],
            'style' => 'margin-bottom:0!important;',
          ],
        ];

        // Iterando sobre o conteúdo existente e adicionando-o à coluna de conteúdo.
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
      }
      else {
        $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper']['content'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['col-12', 'card-body'],
            'style' => 'margin-bottom:0!important;',
          ],
        ];

        $wkfUri = is_string($key) ? trim($key) : '';
        $uriValue = isset($item['element_uri']) ? (string) $item['element_uri'] : Html::escape($wkfUri);
        $statusValue = isset($item['element_status']) ? (string) $item['element_status'] : 'N/A';
        $logValue = isset($item['element_log']) ? (string) $item['element_log'] : 'N/A';
        $downloadValue = isset($item['element_download']) ? (string) $item['element_download'] : 'N/A';

        $sourceInfo = $this->resolveWkfSourceDocumentInfo($wkfUri);
        $sourceName = $sourceInfo['name'] ?? 'N/A';
        $sourceUrl = $sourceInfo['url'] ?? '';
        $sourceIsPdf = !empty($sourceInfo['is_pdf']);
        if ($sourceName === '') {
          $sourceName = 'N/A';
        }

        $sourceValue = Html::escape($sourceName);
        if ($sourceUrl !== '') {
          $sourceButtonLabel = $sourceIsPdf ? 'Open PDF' : 'Open Source';
          $sourceValue .= ' <a href="' . Html::escape($sourceUrl) . '" target="_blank" rel="noopener" class="btn btn-primary btn-sm ms-2" role="button">' . Html::escape($sourceButtonLabel) . '</a>';
        }
        else {
          $sourceButtonLabel = $sourceIsPdf ? 'Open PDF' : 'Open Source';
          $sourceValue .= ' <button type="button" class="btn btn-secondary btn-sm ms-2" disabled aria-disabled="true">' . Html::escape($sourceButtonLabel) . '</button>';
        }

        $sourceTextInfo = $this->resolveWkfSourceTextInfo($wkfUri, $sourceName);
        $sourceTextName = $sourceTextInfo['name'] ?? 'N/A';
        $sourceTextUrl = $sourceTextInfo['url'] ?? '';
        $sourceTextValue = '<button type="button" class="btn btn-outline-secondary btn-sm" disabled aria-disabled="true">' . Html::escape($sourceTextName) . '</button>';
        if ($sourceTextUrl !== '') {
          $sourceTextValue .= ' <a href="' . Html::escape($sourceTextUrl) . '" target="_blank" rel="noopener" class="btn btn-primary btn-sm ms-2" role="button">Open txt</a>';
        }
        else {
          $sourceTextValue .= ' <button type="button" class="btn btn-secondary btn-sm ms-2" disabled aria-disabled="true">Open txt</button>';
        }

        $taskResolution = $this->resolveWkfTaskCountAndProcessUri($wkfUri);
        $taskCount = $taskResolution['count'];
        $interactiveAutoTaskCount = $this->resolveWkfInteractiveAutoTaskCountFromLocalContext($wkfUri);
        $processStemUri = $this->resolveWkfProcessStemUriForCard($wkfUri);
        $ownerEmail = $this->resolveWkfOwnerEmailForCard($wkfUri);
        $organizationName = $this->resolveWkfOwnerOrganizationLabelForCard($wkfUri);
        $usedComponentsCount = $this->resolveWkfUsedComponentsCountFromLocalContext($wkfUri);
        $scenarioPropsCount = $this->resolveWkfScenarioPropsCountFromLocalContext($wkfUri);

        $processStemValue = 'N/A';
        if ($processStemUri !== '') {
          $processStemDisplay = Utils::namespaceUri($processStemUri);
          $processStemHref = Url::fromRoute('rep.describe_element', [
            'elementuri' => base64_encode($processStemUri),
          ])->toString();
          $processStemValue = '<a href="' . Html::escape($processStemHref) . '">' . Html::escape($processStemDisplay) . '</a>';
        }

        $taskCountLabel = ($taskCount === NULL) ? 'N/A' : (string) $taskCount;
        $tasksValue = Html::escape($taskCountLabel);
        $ownerEmailValue = Html::escape($ownerEmail !== '' ? $ownerEmail : 'N/A');
        $organizationValue = Html::escape($organizationName !== '' ? $organizationName : 'N/A');
        $usedComponentsLabel = ($usedComponentsCount === NULL) ? 'N/A' : (string) $usedComponentsCount;
        $usedComponentsValue = Html::escape($usedComponentsLabel);
        if ($usedComponentsCount !== NULL && $usedComponentsCount === 0 && $interactiveAutoTaskCount !== NULL) {
          $usedComponentsValue = Html::escape($usedComponentsLabel . ' (of ' . (string) $interactiveAutoTaskCount . ' interactive/auto tasks)');
        }
        $scenarioPropsLabel = ($scenarioPropsCount === NULL) ? 'N/A' : (string) $scenarioPropsCount;
        $scenarioPropsValue = Html::escape($scenarioPropsLabel);

        if ($wkfUri !== '') {
          $wkfDownloadUrl = Url::fromRoute('rep.wkf_current_download', [
            'wkfuri' => base64_encode($wkfUri),
          ])->toString();
          $downloadValue = '<a href="' . Html::escape($wkfDownloadUrl) . '" class="btn btn-primary btn-sm download-button" role="button">Get It</a>';
        }

        $wkfList = '<ul class="list-unstyled mb-0">'
          . '<li><strong>URI:</strong> ' . $uriValue . '</li>'
          . '<li><strong>Proc. Stem URI:</strong> ' . $processStemValue . '</li>'
          . '<li><strong>Status:</strong> ' . $statusValue . '</li>'
          . '<li><strong>Owner email:</strong> ' . $ownerEmailValue . '</li>'
          . '<li><strong>Organization:</strong> ' . $organizationValue . '</li>'
          . '<li><strong>Number of scenario prop w/values:</strong> ' . $scenarioPropsValue . '</li>'
          . '<li><strong>Number of tasks:</strong> ' . $tasksValue . '</li>'
          . '<li><strong>Number of used components:</strong> ' . $usedComponentsValue . '</li>'
          . '<li><strong>Source:</strong> ' . $sourceValue . '</li>'
          . '<li><strong>Src Txt:</strong> ' . $sourceTextValue . '</li>'
          . '<li><strong>Log:</strong> ' . $logValue . '</li>'
          . '<li><strong>Download:</strong> ' . $downloadValue . '</li>'
          . '</ul>';

        $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper']['content']['wkf_summary_list'] = [
          '#type' => 'container',
          '#markup' => $wkfList,
          '#allowed_tags' => ['ul', 'li', 'strong', 'a', 'b', 'font', 'span', 'div', 'em'],
        ];
      }

      $wkfPhaseControlConfig = NULL;
      if ($this->element_type === 'wkf') {
        $wkfUri = is_string($key) ? trim($key) : '';
        $currentPhase = $this->getWkfCurrentPublicPhaseFromHistory($wkfUri);
        $currentPhaseRoman = $this->wkfPhaseToRoman($currentPhase);
        $phaseOptions = [];
        // Keep selector values stable across submits to avoid form validation
        // errors when a stale client-side value (e.g. 4) is posted.
        for ($phase = 2; $phase <= 4; $phase++) {
          $phaseOptions[(string) $phase] = 'Phase ' . $this->wkfPhaseToRoman($phase);
        }
        if (empty($phaseOptions)) {
          $phaseOptions['2'] = 'Phase II';
        }

        $wkfPhaseControlConfig = [
          'uri' => $wkfUri,
          'current_phase' => (string) $currentPhase,
          'current_phase_label' => $currentPhaseRoman,
          'options' => $phaseOptions,
        ];

      }

      // Adicionando o rodapé na mesma coluna de conteúdo
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer'] = [
        '#type' => 'container',
        '#attributes' => [
          'style' => 'margin-bottom:0!important;',
          'class' => ['d-flex', 'card-footer', 'justify-content-between', 'align-items-center', 'wkf-card-footer'],
        ],
      ];

      if ($this->element_type === 'wkf' && $wkfPhaseControlConfig !== NULL) {
        $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['wkf_phase_controls'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['wkf-card-phase-controls'],
          ],
        ];

        $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['wkf_phase_controls']['row'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['d-flex', 'gap-2', 'align-items-center'],
          ],
        ];

        $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['wkf_phase_controls']['row']['phase_selector'] = [
          '#type' => 'select',
          '#title' => $this->t('Phase selector'),
          '#title_display' => 'invisible',
          '#options' => $wkfPhaseControlConfig['options'],
          '#default_value' => $wkfPhaseControlConfig['current_phase'],
          '#attributes' => [
            'class' => ['form-select', 'form-select-sm', 'wkf-card-phase-selector'],
            'data-wkf-uri' => $wkfPhaseControlConfig['uri'],
          ],
        ];

        $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['wkf_phase_controls']['row']['build_button'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#attributes' => [
            'type' => 'button',
            'class' => ['btn', 'btn-outline-secondary', 'btn-sm', 'wkf-card-build-phase-packet'],
            'data-wkf-uri' => $wkfPhaseControlConfig['uri'],
          ],
          '#value' => $this->t('Build Packet'),
        ];

        $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['wkf_sheet_controls'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['wkf-card-sheet-controls'],
          ],
        ];

        $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['wkf_sheet_controls']['task_model_update_button'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#attributes' => [
            'type' => 'button',
            'class' => ['btn', 'btn-outline-warning', 'btn-sm', 'wkf-card-task-model-update'],
            'data-wkf-uri' => $wkfPhaseControlConfig['uri'],
          ],
          '#value' => $this->t('Task Model Update'),
        ];

        $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['wkf_sheet_controls']['scenario_update_button'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#attributes' => [
            'type' => 'button',
            'class' => ['btn', 'btn-outline-info', 'btn-sm', 'wkf-card-scenario-update'],
            'data-wkf-uri' => $wkfPhaseControlConfig['uri'],
          ],
          '#value' => $this->t('Scenario Update'),
        ];
      }

      // Adicionando os botões ao rodapé
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions'] = [
        '#type' => 'actions',
        '#attributes' => [
          'style' => 'margin-bottom:0!important;',
          'class' => ['mb-0', 'ms-auto'],
        ],
      ];

      // Botão Editar
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t($this->element_type === 'wkf' ? 'Open WKF Workflow' : 'Edit'),
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

      if ($this->element_type === 'wkf') {
        $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['validate'] = [
          '#type' => 'submit',
          '#value' => $this->t('Validate'),
          '#name' => 'validate_mt_' . $sanitized_key,
          '#attributes' => [
            'class' => ['btn', 'btn-info', 'btn-sm', 'validate-wkf-button'],
          ],
          '#submit' => ['::validateElementSubmit'],
          '#limit_validation_errors' => [],
          '#element_uri' => $key,
        ];
      }
    }
  }

  protected function resolveWkfScopeFromUri(string $wkfUri): string {
    $normalized = Utils::plainUri($wkfUri) ?: trim($wkfUri);
    if ($normalized === '') {
      return '__global__';
    }
    return 'wkf_' . substr(sha1($normalized), 0, 16);
  }

  protected function getWkfCurrentPublicPhaseFromHistory(string $wkfUri): int {
    $scope = $this->resolveWkfScopeFromUri($wkfUri);
    $maxPublicPhase = 2;

    $tablePhase = $this->getPersistedWkfPublicPhaseFromTable($scope);
    if ($tablePhase !== NULL) {
      $maxPublicPhase = max($maxPublicPhase, $tablePhase);
    }

    // If Task Model Update was already applied and persisted for this WKF,
    // keep selector at least on Phase III even if packet/response history lags.
    $persistedTasks = $this->getPersistedWkfTaskCount($wkfUri);
    if ($persistedTasks !== NULL) {
      $maxPublicPhase = max($maxPublicPhase, 3);
    }

    $phaseStore = \Drupal::keyValue('rep.wkf.public_phase.by_scope');
    $phaseEntry = $phaseStore->get($scope, []);
    if (is_array($phaseEntry) && isset($phaseEntry['phase'])) {
      $savedPhase = (int) $phaseEntry['phase'];
      if ($savedPhase >= 2 && $savedPhase <= 4) {
        $maxPublicPhase = max($maxPublicPhase, $savedPhase);
      }
    }

    $session = \Drupal::request()->getSession();
    if ($session !== NULL) {
      $workflowStore = $session->get('rep.wkf.workflow.state.by_scope', []);
      if (is_array($workflowStore) && isset($workflowStore[$scope]) && is_array($workflowStore[$scope])) {
        $workflowState = $workflowStore[$scope];
        if (isset($workflowState['currentPublicPhase'])) {
          $currentPhase = (int) $workflowState['currentPublicPhase'];
          if ($currentPhase >= 2 && $currentPhase <= 4) {
            $maxPublicPhase = max($maxPublicPhase, $currentPhase);
          }
        }
      }
    }

    $packetHistory = \Drupal::keyValue('rep.wkf.phase_packets.by_scope')->get($scope, []);
    if (is_array($packetHistory)) {
      foreach ($packetHistory as $entry) {
        if (!is_array($entry)) {
          continue;
        }
        $phase = isset($entry['phase']) ? (int) $entry['phase'] : 0;
        $publicPhase = $this->mapBackendPhaseToPublicPhase($phase);
        if ($publicPhase >= 2 && $publicPhase <= 4) {
          $maxPublicPhase = max($maxPublicPhase, $publicPhase);
        }
      }
    }

    $responseHistory = \Drupal::keyValue('rep.wkf.phase_responses.by_scope')->get($scope, []);
    if (is_array($responseHistory)) {
      foreach ($responseHistory as $entry) {
        if (!is_array($entry)) {
          continue;
        }
        $phase = isset($entry['phase']) ? (int) $entry['phase'] : 0;
        $publicPhase = $this->mapBackendPhaseToPublicPhase($phase);
        if ($publicPhase >= 2 && $publicPhase <= 4) {
          $maxPublicPhase = max($maxPublicPhase, $publicPhase);
        }
      }
    }

    return max(2, min(4, $maxPublicPhase));
  }

  /**
   * Read persisted public phase from table-backed storage.
   */
  protected function getPersistedWkfPublicPhaseFromTable(string $scope): ?int {
    if ($scope === '') {
      return NULL;
    }

    try {
      $phase = \Drupal::database()
        ->select('rep_wkf_phase_state', 'w')
        ->fields('w', ['public_phase'])
        ->condition('scope', $scope)
        ->range(0, 1)
        ->execute()
        ->fetchField();
    }
    catch (\Throwable $e) {
      return NULL;
    }

    if ($phase === FALSE || $phase === NULL) {
      return NULL;
    }

    $value = (int) $phase;
    return ($value >= 2 && $value <= 4) ? $value : NULL;
  }

  protected function mapBackendPhaseToPublicPhase(int $phase): int {
    if ($phase === 4) {
      return 4;
    }
    if ($phase === 3) {
      return 3;
    }

    // Default to Phase II when unknown/legacy data is encountered.
    return 2;
  }

  protected function wkfPhaseToRoman(int $phase): string {
    $map = [
      1 => 'I',
      2 => 'II',
      3 => 'III',
      4 => 'IV',
    ];
    return $map[$phase] ?? (string) $phase;
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
   * HANDLER TO VALIDATE CARD
   */
  public function validateElementSubmit(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];
    $this->performValidateWKF($uri, $form_state);
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

    $deleted = 0;
    $failed = 0;
    $wkfManagerEmailsToRefresh = [];

    foreach ($uris as $raw_uri) {
      $uri = Utils::plainUri($raw_uri) ?: $raw_uri;

      // Resolve template (best-effort) so we can also clean up its cached DataFile.
      $mt = $api->parseObjectResponse($api->getUri($uri), 'getUri');
      if (!is_object($mt) && $raw_uri !== $uri) {
        $mt = $api->parseObjectResponse($api->getUri($raw_uri), 'getUri');
      }

      $effective_uri = $uri;
      if (is_object($mt) && isset($mt->uri) && is_string($mt->uri) && $mt->uri !== '') {
        $effective_uri = $mt->uri;
      }

      $datafileUri = NULL;
      $cachedFileId = NULL;

      if (is_object($mt)) {
        // Common shape: hasDataFile is an object with { uri, id, filename, ... }
        if (isset($mt->hasDataFile)) {
          if (is_object($mt->hasDataFile)) {
            if (isset($mt->hasDataFile->uri) && is_string($mt->hasDataFile->uri) && $mt->hasDataFile->uri !== '') {
              $datafileUri = $mt->hasDataFile->uri;
            }
            if (isset($mt->hasDataFile->id)) {
              $cachedFileId = $mt->hasDataFile->id;
            }
          }
          elseif (is_string($mt->hasDataFile) && $mt->hasDataFile !== '') {
            // Sometimes API returns just the URI string.
            $datafileUri = $mt->hasDataFile;
          }
        }

        // Alternate shape used by some WKF payloads.
        if ($datafileUri === NULL && isset($mt->hasDataFileUri) && is_string($mt->hasDataFileUri) && $mt->hasDataFileUri !== '') {
          $datafileUri = $mt->hasDataFileUri;
        }

      }

      // 1) Delete the Metadata Template / WKF itself (expected path for all MTs).
      $deleteConfirmed = FALSE;
      $deleteResult = $api->parseObjectResponse($api->elementDel($this->element_type, $effective_uri), 'elementDel');
      if ($this->deleteResponseIndicatesSuccess($deleteResult) && $this->confirmUriDeleted($effective_uri)) {
        $deleteConfirmed = TRUE;
      }

      // Some deployments keep WKF visible after elementDel("wkf", ...).
      // Fallback to process deletion and re-verify.
      if (!$deleteConfirmed && $this->element_type === 'wkf') {
        $processDeleteResult = $api->parseObjectResponse($api->processDel($effective_uri), 'processDel');
        if ($this->deleteResponseIndicatesSuccess($processDeleteResult) && $this->confirmUriDeleted($effective_uri)) {
          $deleteConfirmed = TRUE;
        }
      }

      if ($deleteConfirmed) {
        $deleted++;

        if ($this->element_type === 'wkf') {
          $candidateEmails = $this->extractManagerEmailsFromTemplateObject($mt);
          foreach ($candidateEmails as $email) {
            $wkfManagerEmailsToRefresh[$email] = true;
          }
        }

        // 2) Best-effort cleanup: delete associated DataFile (if known).
        if (!empty($datafileUri)) {
          $api->parseObjectResponse($api->datafileDel($datafileUri), 'datafileDel');
        }

        // 3) Best-effort cleanup: delete cached Drupal File entity + binary.
        if (!empty($cachedFileId)) {
          $file = File::load($cachedFileId);
          if ($file) {
            $file_uri = $file->getFileUri();
            if (!empty($file_uri)) {
              $real_path = $file_system->realpath($file_uri);
              if ($real_path && file_exists($real_path)) {
                try {
                  $file_system->delete($file_uri);
                }
                catch (\Throwable $e) {
                  // ignore
                }
              }
            }
            try {
              $file->delete();
            }
            catch (\Throwable $e) {
              // ignore
            }
          }
        }
      }
      else {
        $failed++;
      }
    }

    if ($deleted > 0 && $failed === 0) {
      \Drupal::messenger()->addMessage(t("The " . $this->plural_class_name . " selected were deleted successfully."));
    }
    elseif ($deleted > 0) {
      \Drupal::messenger()->addWarning(t("Some items were deleted, but @n deletions failed.", ['@n' => $failed]));
    }
    else {
      \Drupal::messenger()->addError(t("Failed to delete the selected " . $this->plural_class_name . "."));
    }

    if ($this->element_type === 'wkf' && $deleted > 0) {
      if (empty($wkfManagerEmailsToRefresh)) {
        $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($this->manager_email);
      }
      else {
        foreach (array_keys($wkfManagerEmailsToRefresh) as $email) {
          $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($email);
        }
      }
    }

    \Drupal::service('cache.default')->invalidateAll();
    $form_state->setRebuild();
  }

  /**
   * True only when delete response represents a real positive result.
   */
  protected function deleteResponseIndicatesSuccess($deleteResult): bool {
    if ($deleteResult === NULL) {
      return FALSE;
    }

    if (is_array($deleteResult)) {
      return !empty($deleteResult);
    }

    if (is_string($deleteResult)) {
      $normalized = trim($deleteResult);
      if ($normalized === '') {
        return FALSE;
      }
      if (preg_match('/^No\\b.*\\b(has|have)\\sbeen\\sfound\\.?$/i', $normalized)) {
        return FALSE;
      }
      return TRUE;
    }

    return TRUE;
  }

  /**
   * Confirms deletion against a fresh connector instance to avoid getUri cache.
   */
  protected function confirmUriDeleted(string $uri): bool {
    $uri = Utils::plainUri($uri) ?: $uri;
    $freshApi = new \Drupal\rep\FusekiAPIConnector(\Drupal::service('http_client_factory'));
    $raw = $freshApi->getUri($uri);

    if (!is_string($raw) || trim($raw) === '') {
      return FALSE;
    }

    $obj = json_decode($raw);
    if (!is_object($obj)) {
      return FALSE;
    }

    if (!empty($obj->isSuccessful)) {
      // URI still resolvable, so delete was not effective.
      return FALSE;
    }

    // A backend failure (e.g. triplestore_unavailable) carries an `error` object and no string
    // body, so $message stays empty and we correctly refuse to conclude anything.
    $message = isset($obj->body) && is_string($obj->body) ? trim($obj->body) : '';
    return self::messageIndicatesUriAbsent($message);
  }

  /**
   * Whether a hascoapi failure message means "this URI no longer exists".
   *
   * hascoapi signals not-found with prose, and phrases it differently per endpoint:
   *   SIRElementAPI::deleteElement -> "No element with URI [x] has been found"
   *   URIPage::getUri              -> "Uri [x] returned no object from the knowledge graph"
   * confirmUriDeleted() asks getUri(), so it must accept the second phrasing too. Everything
   * else (invalid URI, untyped instance, JSON error) means the URI is still there, or that we
   * cannot tell -- both must fail closed.
   *
   * ponytail: prose matching, because hascoapi returns HTTP 200 for every failure and has no
   * machine-readable error code on this path. Replace with a status/code check once it does
   * (see HASCOAPI_WKF_TASK_RESOLUTION_BUG_REPORT_2026-07-10.md, F2).
   *
   * Known ceiling: URIPage emits the "returned no object" message both for a genuinely absent
   * URI and for one whose hasco:hascoType it cannot dispatch (report F1). Metadata templates
   * dispatch fine, so this is safe for the delete flow; do not reuse it for Tasks until F1 lands.
   */
  public static function messageIndicatesUriAbsent(string $message): bool {
    if ($message === '') {
      return FALSE;
    }
    if (preg_match('/^No\\b.*\\b(has|have)\\sbeen\\sfound\\.?$/i', $message)) {
      return TRUE;
    }
    return stripos($message, 'returned no object from the knowledge graph') !== FALSE;
  }

  /**
   * INGEST FUNCTION
   */
  protected function performIngest(array $uris, FormStateInterface $form_state, String $status) {
    //($status);
    $api = \Drupal::service('rep.api_connector');
    $rawUri = reset($uris);
    $uri = Utils::plainUri($rawUri) ?: $rawUri;
    $template = $api->parseObjectResponse($api->getUri($uri), 'getUri');

    // Fallback: retry with original token in case the selected row already had canonical URI.
    if ($template == NULL && $rawUri !== $uri) {
      $template = $api->parseObjectResponse($api->getUri($rawUri), 'getUri');
    }
    
    // Debugging is handled via Drupal logger/messenger when needed.
    
    if ($template == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve the datafile to be ingested."));
      $form_state->setRedirectUrl(static::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      return;
    }

    // Keep template URI canonical before calling upload endpoint.
    if (isset($template->uri) && is_string($template->uri) && $template->uri !== '') {
      $template->uri = Utils::plainUri($template->uri) ?: $template->uri;
    } else {
      $template->uri = $uri;
    }

    // Some payloads embed hasDataFile but omit hasDataFileUri.
    if ((!isset($template->hasDataFileUri) || $template->hasDataFileUri == NULL || $template->hasDataFileUri === '')
      && isset($template->hasDataFile) && is_object($template->hasDataFile)
      && isset($template->hasDataFile->uri) && is_string($template->hasDataFile->uri) && $template->hasDataFile->uri !== '') {
      $template->hasDataFileUri = Utils::plainUri($template->hasDataFile->uri) ?: $template->hasDataFile->uri;
    }
    
    // FIX: If template doesn't have hasDataFile embedded, fetch it separately
    if (!isset($template->hasDataFile) && isset($template->hasDataFileUri)) {
      \Drupal::logger('rep')->notice('performIngest: Template missing hasDataFile, fetching separately from: @uri', [
        '@uri' => $template->hasDataFileUri,
      ]);
      
      $dataFileUri = Utils::plainUri($template->hasDataFileUri) ?: $template->hasDataFileUri;
      $dataFile = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
      if ($dataFile != NULL) {
        $template->hasDataFile = $dataFile;
        $template->hasDataFileUri = $dataFileUri;
        
        // DEBUG: Show ALL DataFile properties
        // \Drupal::messenger()->addStatus(t('[DEBUG] DataFile fetched - ALL PROPERTIES: @props', [
        //   '@props' => print_r($dataFile, TRUE),
        // ]));
        
        \Drupal::logger('rep')->notice('performIngest: DataFile attached - id: @id, filename: @filename, ALL: @all', [
          '@id' => isset($dataFile->id) ? $dataFile->id : 'NULL',
          '@filename' => isset($dataFile->filename) ? $dataFile->filename : 'NULL',
          '@all' => print_r($dataFile, TRUE),
        ]);
      } else {
        \Drupal::logger('rep')->warning('performIngest: Failed to retrieve DataFile from: @uri', [
          '@uri' => $template->hasDataFileUri,
        ]);
      }
    }

    // Normalize WKF file entity names like "20260823-WKF-...xlsx" to "WKF-...xlsx"
    // because hascoapi validates ingest parser type by filename prefix.
    if ($this->element_type === 'wkf') {
      $this->normalizeWkfFileEntityFilenameForIngestion($template);
    }

    // Hard pre-submit check: ensure the local file is actually readable before upload.
    $readability = $this->verifyLocalDataFileReadability($template);
    if (!$readability['ok']) {
      $tried = !empty($readability['tried']) ? implode(' | ', $readability['tried']) : '(none)';
      $this->appendLocalDataFileDiagnosticLog($template, 'Local file precheck warning before submit: ' . $readability['reason'] . '. Tried paths: ' . $tried);
      \Drupal::messenger()->addWarning(t('Local file precheck warning before submit: @reason. Tried paths: @paths. Submission will continue and backend/upload diagnostics will be used if it fails.', [
        '@reason' => $readability['reason'],
        '@paths' => $tried,
      ]));
    }
    
    $uploadResponse = $api->uploadTemplate($this->element_type, $template, $status);
    $msg = $api->parseObjectResponse($uploadResponse, 'uploadTemplateStatus');
    if ($msg == NULL) {
      $detail = $this->extractIngestionFailureDetail($uploadResponse);
      if ($detail === '') {
        $detail = $this->extractDataFileFailureDetail($api, $template);
      }
      if ($detail === '' && method_exists($api, 'getErrorMessage')) {
        $apiError = trim((string) $api->getErrorMessage());
        if ($apiError !== '') {
          $detail = $apiError;
        }
      }
      $this->appendLocalDataFileDiagnosticLog($template, 'Ingestion submit failed before worker start: ' . ($detail !== '' ? $detail : 'No backend detail returned.'));
      if ($detail !== '') {
        \Drupal::messenger()->addError(t("The " . $this->single_class_name . " selected FAILED to be submited for Ingestion. Reason: @reason", [
          '@reason' => $detail,
        ]));
      } else {
        \Drupal::messenger()->addError(t("The " . $this->single_class_name . " selected FAILED to be submited for Ingestion."));
      }
      $form_state->setRedirectUrl(static::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      return;
    }
    \Drupal::messenger()->addMessage(t("The " . $this->single_class_name . " selected was successfully submited for Ingestion."));
    if ($this->element_type === 'wkf') {
      $refreshEmails = $this->extractManagerEmailsFromTemplateObject($template);
      if (empty($refreshEmails)) {
        $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($this->manager_email);
      }
      else {
        foreach ($refreshEmails as $email) {
          $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($email);
        }
      }
    }
    $form_state->setRedirectUrl(static::backSelect($this->element_type, $this->getMode(), $this->studyuri));
    return;
  }

  /**
   * Ensure WKF uploaded file names start with WKF- so hascoapi prefix checks pass.
   */
  protected function normalizeWkfFileEntityFilenameForIngestion($template): void {
    if (!is_object($template) || !isset($template->hasDataFile) || !is_object($template->hasDataFile)) {
      return;
    }
    if (!isset($template->hasDataFile->id) || trim((string) $template->hasDataFile->id) === '') {
      return;
    }

    $fid = (int) $template->hasDataFile->id;
    $fileEntity = File::load($fid);
    if (!$fileEntity) {
      return;
    }

    $currentName = trim((string) $fileEntity->getFilename());
    if ($currentName === '') {
      return;
    }

    // If WKF- already starts the filename, keep as-is.
    if (stripos($currentName, 'WKF-') === 0) {
      return;
    }

    $normalized = '';
    if (preg_match('/(WKF-[A-Za-z0-9_.-]+)$/i', $currentName, $m) === 1) {
      $normalized = (string) $m[1];
    }

    if ($normalized === '') {
      return;
    }

    if (strcasecmp($normalized, $currentName) === 0) {
      return;
    }

    $fileEntity->setFilename($normalized);
    $fileEntity->save();

    $template->hasDataFile->filename = $normalized;
  }

  /**
   * Validate selected WKF without ingesting it.
   */
  protected function performValidateWKF($uri, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $rawWkfUri = (string) $uri;
    $wkfUri = Utils::plainUri($rawWkfUri) ?: $rawWkfUri;
    $previousWorkflowState = $this->getWkfWorkflowState();
    $validatedAt = date('Y-m-d H:i:s');
    $wkfCopyReason = '';
    $wkfCopyContent = $this->extractValidatedWkfTextForCopy($api, $wkfUri, $rawWkfUri, $wkfCopyReason);

    $raw = $api->validateWKF($wkfUri);
    $result = NULL;
    $detail = '';

    if (is_string($raw) && trim($raw) !== '') {
      $decoded = json_decode($raw);
      if (is_object($decoded)) {
        $isSuccessful = !empty($decoded->isSuccessful);
        $body = $decoded->body ?? NULL;

        if ($isSuccessful) {
          if (is_object($body)) {
            $result = $body;
          }
          elseif (is_string($body) && trim($body) !== '') {
            $decodedBody = json_decode($body);
            if (is_object($decodedBody)) {
              $result = $decodedBody;
            }
          }
        }
        else {
          if (is_string($body) && trim($body) !== '') {
            $detail = trim($body);
          }
          elseif ($body !== NULL) {
            $detail = trim((string) json_encode($body));
          }
        }
      }
    }

    if ($result == NULL || !is_object($result)) {
      // Keep diagnostics in the panel and avoid Drupal messenger for WKF validation flow.
      if (method_exists($api, 'getErrorMessage')) {
        $apiError = (string) $api->getErrorMessage();
        if ($detail === '' && trim($apiError) !== '') {
          $detail = trim($apiError);
        }
      }
      if ($detail === '' && is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw);
        if (is_object($decoded) && isset($decoded->body) && is_string($decoded->body)) {
          $detail = trim($decoded->body);
        }
      }

      $summary = $detail !== ''
        ? 'Failed to validate selected WKF. Reason: ' . $detail
        : 'Failed to validate selected WKF.';
      $snapshotId = substr(sha1($wkfUri . '|' . $validatedAt . '|' . $summary), 0, 12);
      $this->setWkfWorkflowState([
        'hasValidation' => TRUE,
        'lastValidationValid' => FALSE,
        'wkfUri' => $wkfUri,
        'validatedAt' => $validatedAt,
        'snapshotId' => $snapshotId,
        'lastBrokenRuleMap' => [],
      ]);
      $this->setWkfValidationPanelData([
        'valid' => FALSE,
        'summary' => $summary,
        'wkfUri' => $wkfUri,
        'snapshotId' => $snapshotId,
        'rules' => [],
        'newBrokenRules' => [],
        'resolvedBrokenRules' => [],
        'wkfCopyContent' => $wkfCopyContent,
        'wkfCopyReason' => $wkfCopyReason,
        'validatedAt' => $validatedAt,
      ]);
      $form_state->setRedirectUrl(static::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      return;
    }

    $isValid = !empty($result->valid);
    $rules = [];
    if (isset($result->brokenRuleDetails) && is_array($result->brokenRuleDetails)) {
      foreach ($result->brokenRuleDetails as $detail) {
        if (!is_object($detail)) {
          continue;
        }

        $ruleId = '';
        if (isset($detail->ruleId) && is_string($detail->ruleId)) {
          $ruleId = trim($detail->ruleId);
        }

        $message = '';
        if (isset($detail->message) && is_string($detail->message)) {
          $message = trim($detail->message);
        }

        $specSection = '';
        if (isset($detail->specSection) && is_string($detail->specSection)) {
          $specSection = trim($detail->specSection);
        }

        if ($message === '' && $ruleId === '') {
          continue;
        }

        $rules[] = [
          'ruleId' => $ruleId,
          'message' => $message,
          'specSection' => $specSection,
        ];
      }
    }

    if (empty($rules) && isset($result->brokenRules) && is_array($result->brokenRules)) {
      foreach ($result->brokenRules as $rule) {
        if (is_string($rule) && trim($rule) !== '') {
          $rules[] = [
            'ruleId' => '',
            'message' => trim($rule),
            'specSection' => '',
          ];
        }
      }
    }

    $summary = isset($result->summary) && is_string($result->summary) ? trim($result->summary) : '';
    if ($summary === '') {
      $summary = $isValid
        ? 'WKF is valid.'
        : 'WKF validation failed with no explicit broken rule list.';
    }

    $previousRuleMap = [];
    if (isset($previousWorkflowState['lastBrokenRuleMap']) && is_array($previousWorkflowState['lastBrokenRuleMap'])) {
      $previousRuleMap = $previousWorkflowState['lastBrokenRuleMap'];
    }

    $currentRuleMap = [];
    foreach ($rules as $rule) {
      if (!is_array($rule)) {
        continue;
      }
      $ruleKey = $this->buildValidationRuleKey($rule);
      if ($ruleKey === '') {
        continue;
      }

      $ruleLine = $rule['message'] ?? '';
      if (isset($rule['ruleId']) && is_string($rule['ruleId']) && trim($rule['ruleId']) !== '') {
        $ruleLine = trim((string) $rule['ruleId']) . ': ' . trim((string) $ruleLine);
      }
      $currentRuleMap[$ruleKey] = trim((string) $ruleLine);
    }

    $previousKeys = array_keys($previousRuleMap);
    $currentKeys = array_keys($currentRuleMap);
    $newKeys = array_values(array_diff($currentKeys, $previousKeys));
    $resolvedKeys = array_values(array_diff($previousKeys, $currentKeys));
    $newBrokenRules = array_values(array_filter(array_map(function ($key) use ($currentRuleMap) {
      return isset($currentRuleMap[$key]) ? (string) $currentRuleMap[$key] : '';
    }, $newKeys)));
    $resolvedBrokenRules = array_values(array_filter(array_map(function ($key) use ($previousRuleMap) {
      return isset($previousRuleMap[$key]) ? (string) $previousRuleMap[$key] : '';
    }, $resolvedKeys)));
    $snapshotId = substr(sha1($wkfUri . '|' . $validatedAt . '|' . $summary . '|' . json_encode($rules)), 0, 12);
    $this->setWkfWorkflowState([
      'hasValidation' => TRUE,
      'lastValidationValid' => $isValid,
      'wkfUri' => $wkfUri,
      'validatedAt' => $validatedAt,
      'snapshotId' => $snapshotId,
      'lastBrokenRuleMap' => $currentRuleMap,
    ]);

    $this->setWkfValidationPanelData([
      'valid' => $isValid,
      'summary' => $summary,
      'wkfUri' => $wkfUri,
      'snapshotId' => $snapshotId,
      'rules' => $rules,
      'newBrokenRules' => $newBrokenRules,
      'resolvedBrokenRules' => $resolvedBrokenRules,
      'wkfCopyContent' => $wkfCopyContent,
      'wkfCopyReason' => $wkfCopyReason,
      'validatedAt' => $validatedAt,
    ]);

    $form_state->setRedirectUrl(static::backSelect($this->element_type, $this->getMode(), $this->studyuri));
  }

  protected function getWkfValidationPanelSessionKey(): string {
    return 'rep.wkf.validation.panel.by_scope';
  }

  protected function getSkipLegacyWkfValidationRecoverySessionKey(): string {
    return 'rep.wkf.validation.skip_legacy_recovery';
  }

  protected function getWkfWorkflowStateSessionKey(): string {
    return 'rep.wkf.workflow.state.by_scope';
  }

  protected function getActiveWkfScopeSessionKey(): string {
    return 'rep.wkf.scope.active';
  }

  protected function getDefaultWkfScope(): string {
    return '__global__';
  }

  protected function buildWkfScopeFromUri(?string $wkfUri): string {
    $uri = is_string($wkfUri) ? trim($wkfUri) : '';
    if ($uri === '') {
      return $this->getDefaultWkfScope();
    }

    $normalized = Utils::plainUri($uri) ?: $uri;
    return 'wkf_' . substr(sha1($normalized), 0, 16);
  }

  protected function setActiveWkfScopeByUri(?string $wkfUri): void {
    $session = \Drupal::request()->getSession();
    $session->set($this->getActiveWkfScopeSessionKey(), $this->buildWkfScopeFromUri($wkfUri));
  }

  protected function getActiveWkfScope(): string {
    $session = \Drupal::request()->getSession();
    $scope = $session->get($this->getActiveWkfScopeSessionKey());
    if (!is_string($scope) || trim($scope) === '') {
      return $this->getDefaultWkfScope();
    }
    return trim($scope);
  }

  protected function resolveWkfScope(?string $wkfUri = NULL): string {
    if (is_string($wkfUri) && trim($wkfUri) !== '') {
      return $this->buildWkfScopeFromUri($wkfUri);
    }
    return $this->getActiveWkfScope();
  }

  protected function setWkfValidationPanelData(array $data, ?string $wkfUri = NULL): void {
    $session = \Drupal::request()->getSession();
    $panelStore = $session->get($this->getWkfValidationPanelSessionKey(), []);
    if (!is_array($panelStore)) {
      $panelStore = [];
    }

    $panelUri = $wkfUri;
    if ((!is_string($panelUri) || trim($panelUri) === '')
      && isset($data['wkfUri']) && is_string($data['wkfUri']) && trim($data['wkfUri']) !== '') {
      $panelUri = $data['wkfUri'];
    }

    $scope = $this->resolveWkfScope($panelUri);
    $panelStore[$scope] = $data;
    $session->set($this->getWkfValidationPanelSessionKey(), $panelStore);

    if (is_string($panelUri) && trim($panelUri) !== '') {
      $this->setActiveWkfScopeByUri($panelUri);
    }
    else {
      $session->set($this->getActiveWkfScopeSessionKey(), $scope);
    }
  }

  protected function getWkfValidationPanelData(?string $wkfUri = NULL): ?array {
    $session = \Drupal::request()->getSession();
    $panelStore = $session->get($this->getWkfValidationPanelSessionKey(), []);
    if (!is_array($panelStore)) {
      return NULL;
    }

    $scope = $this->resolveWkfScope($wkfUri);
    if (isset($panelStore[$scope]) && is_array($panelStore[$scope])) {
      return $panelStore[$scope];
    }

    return NULL;
  }

  protected function clearWkfValidationPanelData(?string $wkfUri = NULL): void {
    $session = \Drupal::request()->getSession();
    $panelStore = $session->get($this->getWkfValidationPanelSessionKey(), []);
    if (!is_array($panelStore)) {
      return;
    }

    $scope = $this->resolveWkfScope($wkfUri);
    unset($panelStore[$scope]);
    $session->set($this->getWkfValidationPanelSessionKey(), $panelStore);
  }

  protected function setWkfWorkflowState(array $state, ?string $wkfUri = NULL): void {
    $session = \Drupal::request()->getSession();
    $stateStore = $session->get($this->getWkfWorkflowStateSessionKey(), []);
    if (!is_array($stateStore)) {
      $stateStore = [];
    }

    $stateUri = $wkfUri;
    if ((!is_string($stateUri) || trim($stateUri) === '')
      && isset($state['wkfUri']) && is_string($state['wkfUri']) && trim($state['wkfUri']) !== '') {
      $stateUri = $state['wkfUri'];
    }

    $scope = $this->resolveWkfScope($stateUri);
    $stateStore[$scope] = $state;
    $session->set($this->getWkfWorkflowStateSessionKey(), $stateStore);

    if (is_string($stateUri) && trim($stateUri) !== '') {
      $this->setActiveWkfScopeByUri($stateUri);
    }
    else {
      $session->set($this->getActiveWkfScopeSessionKey(), $scope);
    }
  }

  protected function getWkfWorkflowState(?string $wkfUri = NULL): array {
    $session = \Drupal::request()->getSession();
    $stateStore = $session->get($this->getWkfWorkflowStateSessionKey(), []);
    $scope = $this->resolveWkfScope($wkfUri);
    $state = (is_array($stateStore) && isset($stateStore[$scope]) && is_array($stateStore[$scope]))
      ? $stateStore[$scope]
      : NULL;
    if (!is_array($state)) {
      return [
        'hasValidation' => FALSE,
        'lastValidationValid' => FALSE,
        'wkfUri' => '',
        'validatedAt' => '',
        'snapshotId' => '',
        'lastBrokenRuleMap' => [],
      ];
    }

    $lastBrokenRuleMap = [];
    if (isset($state['lastBrokenRuleMap']) && is_array($state['lastBrokenRuleMap'])) {
      foreach ($state['lastBrokenRuleMap'] as $key => $value) {
        if (is_string($key) && is_string($value) && trim($key) !== '' && trim($value) !== '') {
          $lastBrokenRuleMap[$key] = $value;
        }
      }
    }

    return [
      'hasValidation' => !empty($state['hasValidation']),
      'lastValidationValid' => !empty($state['lastValidationValid']),
      'wkfUri' => isset($state['wkfUri']) && is_string($state['wkfUri']) ? $state['wkfUri'] : '',
      'validatedAt' => isset($state['validatedAt']) && is_string($state['validatedAt']) ? $state['validatedAt'] : '',
      'snapshotId' => isset($state['snapshotId']) && is_string($state['snapshotId']) ? $state['snapshotId'] : '',
      'lastBrokenRuleMap' => $lastBrokenRuleMap,
    ];
  }

  protected function buildValidationRuleKey(array $rule): string {
    $ruleId = isset($rule['ruleId']) && is_string($rule['ruleId']) ? trim($rule['ruleId']) : '';
    $message = isset($rule['message']) && is_string($rule['message']) ? trim($rule['message']) : '';
    $specSection = isset($rule['specSection']) && is_string($rule['specSection']) ? trim($rule['specSection']) : '';

    if ($ruleId === '' && $message === '' && $specSection === '') {
      return '';
    }

    return sha1($ruleId . '|' . $message . '|' . $specSection);
  }

  protected function buildWkfVersionLabel(string $wkfUri, string $snapshotId = ''): string {
    $wkfUri = trim($wkfUri);
    $snapshotId = trim($snapshotId);

    if ($wkfUri === '') {
      return 'none';
    }

    $uriPath = parse_url($wkfUri, PHP_URL_PATH);
    $base = is_string($uriPath) && $uriPath !== '' ? basename($uriPath) : basename($wkfUri);
    if ($base === '') {
      $base = $wkfUri;
    }

    if ($snapshotId !== '') {
      return $base . ' @ ' . $snapshotId;
    }

    return $base;
  }

  protected function setSkipLegacyWkfValidationRecovery(bool $skip): void {
    $session = \Drupal::request()->getSession();
    $session->set($this->getSkipLegacyWkfValidationRecoverySessionKey(), $skip ? 1 : 0);
  }

  protected function consumeSkipLegacyWkfValidationRecovery(): bool {
    $session = \Drupal::request()->getSession();
    $value = (int) $session->get($this->getSkipLegacyWkfValidationRecoverySessionKey(), 0);
    $session->remove($this->getSkipLegacyWkfValidationRecoverySessionKey());
    return $value === 1;
  }

  /**
   * Remove old WKF validation messages from Drupal messenger.
   *
   * This keeps validation feedback in the dedicated panel and avoids
   * duplicated legacy bullet-list errors from previous requests.
   */
  protected function suppressLegacyWkfValidationMessages(): void {
    $skipLegacyRecovery = $this->consumeSkipLegacyWkfValidationRecovery();

    $messenger = \Drupal::messenger();
    $messages = $messenger->deleteAll();
    if (!is_array($messages) || empty($messages)) {
      return;
    }

    $legacyValidationLines = [];

    foreach ($messages as $type => $typedMessages) {
      if (!is_array($typedMessages)) {
        continue;
      }

      foreach ($typedMessages as $message) {
        $text = trim(strip_tags((string) $message));
        if ($text === '') {
          continue;
        }

        $isLegacyWkfValidation =
          (strpos($text, 'WKF-RULE-') !== FALSE)
          || (stripos($text, 'WKF validation failed') !== FALSE)
          || (stripos($text, 'broken validation rule') !== FALSE)
          || (stripos($text, 'Failed to validate selected WKF') !== FALSE);

        if ($isLegacyWkfValidation) {
          foreach (preg_split('/[\r\n]+/', $text) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
              continue;
            }
            if (strpos($line, '* ') === 0 || strpos($line, '- ') === 0) {
              $line = trim(substr($line, 2));
            }
            $legacyValidationLines[] = $line;
          }
          continue;
        }

        switch ($type) {
          case 'error':
            $messenger->addError($message);
            break;

          case 'warning':
            $messenger->addWarning($message);
            break;

          case 'status':
          default:
            $messenger->addStatus($message);
            break;
        }
      }
    }

    if (!$skipLegacyRecovery && !empty($legacyValidationLines) && $this->getWkfValidationPanelData() === NULL) {
      $rules = [];
      foreach ($legacyValidationLines as $line) {
        $ruleId = '';
        $message = $line;

        if (preg_match('/^(WKF-RULE-[0-9]+)\s*:\s*(.+)$/', $line, $m)) {
          $ruleId = trim($m[1]);
          $message = trim($m[2]);
        }

        if ($message === '') {
          continue;
        }

        $rules[] = [
          'ruleId' => $ruleId,
          'message' => $message,
          'specSection' => '',
        ];
      }

      $summary = 'WKF validation failed.';
      if (!empty($rules)) {
        $summary .= ' Imported ' . count($rules) . ' legacy validation message(s) into the panel.';
      }

      $this->setWkfValidationPanelData([
        'valid' => FALSE,
        'summary' => $summary,
        'wkfUri' => '',
        'rules' => $rules,
        'validatedAt' => date('Y-m-d H:i:s'),
      ]);
    }
  }

  /**
   * Remove non-panel warning/error messages on WKF select page.
   *
   * This keeps UX consistent by rendering WKF validation feedback only
   * inside the dedicated in-page panel.
   */
  protected function suppressWkfSelectPageMessages(): void {
    $messenger = \Drupal::messenger();

    // Drop warning/error streams unconditionally for this page context.
    $messenger->deleteByType('warning');
    $messenger->deleteByType('error');
  }

  protected function buildWkfValidationPanel(): ?array {
    $panel = $this->getWkfValidationPanelData();
    if ($panel === NULL) {
      return NULL;
    }

    // Consume panel data so validation results behave like a flash message:
    // visible on first render, cleared on subsequent refreshes.
    $this->clearWkfValidationPanelData();

    $isValid = !empty($panel['valid']);
    $summary = isset($panel['summary']) && is_string($panel['summary'])
      ? trim($panel['summary'])
      : ($isValid ? 'WKF is valid.' : 'WKF validation failed.');
    $wkfUri = isset($panel['wkfUri']) && is_string($panel['wkfUri']) ? trim($panel['wkfUri']) : '';
    $snapshotId = isset($panel['snapshotId']) && is_string($panel['snapshotId']) ? trim($panel['snapshotId']) : '';
    $versionLabel = $this->buildWkfVersionLabel($wkfUri, $snapshotId);
    $validatedAt = isset($panel['validatedAt']) && is_string($panel['validatedAt']) ? trim($panel['validatedAt']) : '';
    $rules = [];
    if (isset($panel['rules']) && is_array($panel['rules'])) {
      $rules = $panel['rules'];
    }
    $newBrokenRules = [];
    if (isset($panel['newBrokenRules']) && is_array($panel['newBrokenRules'])) {
      foreach ($panel['newBrokenRules'] as $line) {
        if (is_string($line) && trim($line) !== '') {
          $newBrokenRules[] = trim($line);
        }
      }
    }
    $resolvedBrokenRules = [];
    if (isset($panel['resolvedBrokenRules']) && is_array($panel['resolvedBrokenRules'])) {
      foreach ($panel['resolvedBrokenRules'] as $line) {
        if (is_string($line) && trim($line) !== '') {
          $resolvedBrokenRules[] = trim($line);
        }
      }
    }
    $wkfCopyContent = isset($panel['wkfCopyContent']) && is_string($panel['wkfCopyContent'])
      ? trim($panel['wkfCopyContent'])
      : '';
    $wkfCopyReason = isset($panel['wkfCopyReason']) && is_string($panel['wkfCopyReason'])
      ? trim($panel['wkfCopyReason'])
      : '';
    $wkfCopyUnavailable = ($wkfCopyContent === '');
    $wkfCopyHintText = $wkfCopyUnavailable
      ? ('WKF snapshot unavailable: ' . ($wkfCopyReason !== '' ? $wkfCopyReason : 'unknown reason.'))
      : 'WKF snapshot ready for copy.';
    $wkfCopyHintClass = $wkfCopyUnavailable ? 'text-muted' : 'text-success';

    $copyLines = [];
    $copyLines[] = $summary;
    if ($wkfUri !== '') {
      $copyLines[] = 'WKF URI: ' . $wkfUri;
    }
    $copyLines[] = 'WKF Version: ' . $versionLabel;
    if ($snapshotId !== '') {
      $copyLines[] = 'Validation Snapshot ID: ' . $snapshotId;
    }
    if ($validatedAt !== '') {
      $copyLines[] = 'Validated at: ' . $validatedAt;
    }

    $items = [];
    foreach ($rules as $rule) {
      if (!is_array($rule)) {
        continue;
      }
      $ruleId = isset($rule['ruleId']) && is_string($rule['ruleId']) ? trim($rule['ruleId']) : '';
      $message = isset($rule['message']) && is_string($rule['message']) ? trim($rule['message']) : '';
      $specSection = isset($rule['specSection']) && is_string($rule['specSection']) ? trim($rule['specSection']) : '';

      if ($ruleId === '' && $message === '') {
        continue;
      }

      $lineText = '';
      if ($ruleId !== '') {
        $lineText .= $ruleId;
        if ($message !== '') {
          $lineText .= ': ';
        }
      }
      if ($message !== '') {
        $lineText .= $message;
      }
      if ($specSection !== '') {
        $lineText .= ' (' . $specSection . ')';
      }
      $copyLines[] = '- ' . $lineText;

      $lineHtml = '';
      if ($ruleId !== '') {
        $lineHtml .= '<strong>' . Html::escape($ruleId) . '</strong>';
        if ($message !== '') {
          $lineHtml .= ': ';
        }
      }
      if ($message !== '') {
        $lineHtml .= Html::escape($message);
      }
      if ($specSection !== '') {
        $lineHtml .= ' <em>(' . Html::escape($specSection) . ')</em>';
      }
      $items[] = '<li>' . $lineHtml . '</li>';
    }

    if (!empty($newBrokenRules)) {
      $copyLines[] = '';
      $copyLines[] = 'New broken rules since previous validation:';
      foreach ($newBrokenRules as $line) {
        $copyLines[] = '+ ' . $line;
      }
    }

    if (!empty($resolvedBrokenRules)) {
      $copyLines[] = '';
      $copyLines[] = 'Resolved broken rules since previous validation:';
      foreach ($resolvedBrokenRules as $line) {
        $copyLines[] = '- ' . $line;
      }
    }

    if (!$isValid) {
      $taskModelCorrectionPrompt = trim($this->getWkfTaskModelCorrectionPromptText());
      if ($taskModelCorrectionPrompt !== '') {
        $copyLines[] = '';
        $copyLines[] = '--- TASK MODEL CORRECTION PROMPT ---';
        $copyLines[] = $taskModelCorrectionPrompt;
      }
    }

    $copyText = implode("\n", $copyLines);

    $repairLines = [];
    $repairLines[] = 'WKF TASK MODEL REPAIR PACKET';
    $repairLines[] = 'Use this packet with ChatGPT to fix the validated WKF.';
    $repairLines[] = '';
    $repairLines[] = '--- VALIDATION MESSAGES ---';
    $repairLines[] = $copyText;
    $repairLines[] = '';
    $repairLines[] = '--- TASK MODEL CORRECTION PROMPT ---';
    $repairPrompt = trim($this->getWkfTaskModelCorrectionPromptText());
    $repairLines[] = ($repairPrompt !== '') ? $repairPrompt : '(prompt not available)';
    $repairLines[] = '';
    $repairLines[] = '--- VALIDATED WKF CONTENT ---';
    if ($wkfCopyContent !== '') {
      $repairLines[] = $wkfCopyContent;
    }
    else {
      $repairLines[] = '(validated WKF content is unavailable in this runtime)';
    }
    $repairText = implode("\n", $repairLines);
    $panelBorderClass = $isValid ? 'border-success' : 'border-danger';
    $panelHeaderClass = $isValid ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis';
    $title = $isValid ? 'WKF validation passed' : 'WKF validation failed';

    $normalizedTitle = strtolower(trim($title, " \t\n\r\0\x0B.:-"));
    $normalizedSummary = strtolower(trim($summary, " \t\n\r\0\x0B.:-"));
    $showSummary = ($normalizedSummary !== '' && $normalizedSummary !== $normalizedTitle);
    $nextStepHint = $isValid
      ? 'Next step: proceed with the next official WKF generation phase (Phase III or Phase IV) using this validated WKF version.'
      : 'Next step: optionally run task-model verification/correction, then continue with official WKF phases.';
    $nextStepHintClass = $isValid ? 'text-success-emphasis' : 'text-danger-emphasis';

    $markup = '<section class="wkf-validation-panel card ' . Html::escape($panelBorderClass) . ' mt-3 mb-4" role="status" style="max-width:100%; overflow:hidden;">'
      . '<div class="card-header ' . Html::escape($panelHeaderClass) . '">'
      . '<div class="small fw-bold text-uppercase">Dedicated WKF Validation Panel</div>'
      . '</div>'
      . '<div class="card-body">'
      . '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2" style="min-width:0;">'
      . '<div style="flex:1 1 auto; min-width:0;">'
      . '<h5 class="mb-1">' . Html::escape($title) . '</h5>'
      . ($showSummary ? '<div class="mb-1">' . Html::escape($summary) . '</div>' : '')
      . ($wkfUri !== '' ? '<div class="small text-muted">WKF URI: ' . Html::escape($wkfUri) . '</div>' : '')
      . '<div class="small text-muted">WKF version: ' . Html::escape($versionLabel) . '</div>'
      . ($snapshotId !== '' ? '<div class="small text-muted">Validation snapshot: ' . Html::escape($snapshotId) . '</div>' : '')
      . ($validatedAt !== '' ? '<div class="small text-muted">Validated at: ' . Html::escape($validatedAt) . '</div>' : '')
      . '<div class="small mt-1 ' . Html::escape($nextStepHintClass) . '">' . Html::escape($nextStepHint) . '</div>'
      . '</div>'
      . '<div class="d-flex flex-column align-items-end gap-1" style="flex:0 0 auto; min-width:0;">'
      . '<div class="d-flex align-items-center gap-2">'
      . '<button type="button" class="btn btn-primary btn-sm wkf-validation-copy-btn" data-copy-source="#wkf-validation-copy-source">Copy Messages</button>'
      . '<button type="button" class="btn btn-outline-secondary btn-sm wkf-validation-copy-btn" data-copy-source="#wkf-validation-repair-source">Copy Repair Packet</button>'
      . '<button type="button" class="btn btn-outline-primary btn-sm wkf-validation-copy-btn" data-copy-source="#wkf-validation-wkf-source"' . ($wkfCopyUnavailable ? ' disabled aria-disabled="true" title="Validated WKF content is not available locally."' : '') . '>Copy WKF</button>'
      . '<span class="small text-success wkf-validation-copy-status" aria-live="polite"></span>'
      . '</div>'
      . '<div class="small ' . Html::escape($wkfCopyHintClass) . '">' . Html::escape($wkfCopyHintText) . '</div>'
      . '</div>'
      . '</div>';

    if (!empty($items)) {
      $markup .= '<hr class="my-2" />'
        . '<div><strong>' . Html::escape('Broken validation rules:') . '</strong>'
        . '<div class="wkf-validation-rules mt-2" style="max-height: 22rem; overflow-y: auto; overflow-x: hidden; border: 1px solid rgba(0,0,0,0.15); border-radius: .25rem; padding: .5rem .75rem; background: rgba(255,255,255,0.55); overflow-wrap:anywhere; word-break:break-word; white-space:normal;">'
        . '<ul class="mb-0" style="overflow-wrap:anywhere; word-break:break-word; white-space:normal;">'
        . implode('', $items)
        . '</ul></div></div>';

    }

    if (!empty($newBrokenRules) || !empty($resolvedBrokenRules)) {
      $deltaItems = '';
      if (!empty($newBrokenRules)) {
        $deltaItems .= '<div class="small text-danger-emphasis"><strong>New broken rules:</strong></div>';
        $deltaItems .= '<ul class="mb-2">';
        foreach ($newBrokenRules as $line) {
          $deltaItems .= '<li class="small">' . Html::escape($line) . '</li>';
        }
        $deltaItems .= '</ul>';
      }
      if (!empty($resolvedBrokenRules)) {
        $deltaItems .= '<div class="small text-success-emphasis"><strong>Resolved broken rules:</strong></div>';
        $deltaItems .= '<ul class="mb-0">';
        foreach ($resolvedBrokenRules as $line) {
          $deltaItems .= '<li class="small">' . Html::escape($line) . '</li>';
        }
        $deltaItems .= '</ul>';
      }

      $markup .= '<hr class="my-2" />'
        . '<div><strong>' . Html::escape('Validation delta (vs previous validation):') . '</strong>'
        . '<div class="mt-2" style="border: 1px solid rgba(0,0,0,0.15); border-radius: .25rem; padding: .5rem .75rem; background: rgba(255,255,255,0.55);">'
        . $deltaItems
        . '</div></div>';
    }

    $markup .= '<textarea id="wkf-validation-copy-source" class="visually-hidden" readonly>'
      . Html::escape($copyText)
      . '</textarea>'
      . '<textarea id="wkf-validation-repair-source" class="visually-hidden" readonly>'
      . Html::escape($repairText)
      . '</textarea>'
      . '<textarea id="wkf-validation-wkf-source" class="visually-hidden" readonly>'
      . Html::escape($wkfCopyContent)
      . '</textarea>'
      . '</div>'
      . '</section>';

    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'wkf-validation-result-panel'],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['d-flex', 'justify-content-end', 'align-items-center', 'gap-2', 'mb-2']],
        'clear_panel' => [
          '#type' => 'submit',
          '#name' => 'clear_wkf_validation_panel',
          '#value' => $this->t('Clear panel'),
          '#submit' => ['::clearWkfValidationPanelSubmit'],
          '#limit_validation_errors' => [],
          '#ajax' => [
            'callback' => '::ajaxClearWkfValidationPanel',
            'wrapper' => 'wkf-validation-panel-wrapper',
            'event' => 'click',
          ],
          '#attributes' => ['class' => ['btn', 'btn-outline-secondary', 'btn-sm']],
        ],
      ],
      'content' => [
        '#type' => 'markup',
        '#markup' => Markup::create($markup),
      ],
    ];
  }

  /**
   * Load the core prompt used to fix WKF task model issues.
   */
  protected function getWkfTaskModelCorrectionPromptText(): string {
    $pmsr_module_path = \Drupal::service('extension.list.module')->getPath('pmsr');
    $prompt_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/prompts/PROMPT-WKF-PHASE3-TASK-MODEL-CORRECTION.md';

    if (is_file($prompt_path)) {
      $content = file_get_contents($prompt_path);
      if (is_string($content) && trim($content) !== '') {
        return trim($content);
      }
    }

    return "PHASE III - TASK MODEL CORRECTION\n\n"
      . "Input:\n"
      . "1) The current WKF file\n"
      . "2) The WKF validation output (rule IDs and messages)\n"
      . "3) The original source document\n\n"
      . "Goal:\n"
      . "Correct only task-model inconsistencies so the WKF passes task-model validation without regressing valid content.\n\n"
      . "Mandatory rules:\n"
      . "- Preserve existing URIs and structure whenever possible.\n"
      . "- Fix each reported task-model violation precisely and minimally.\n"
      . "- Do not invent entities that are not supported by the source document.\n"
      . "- Keep all previously valid sections unchanged.\n\n"
      . "Output:\n"
      . "Return the corrected full WKF content and a short change log grouped by validation rule ID.";
  }

  /**
   * Build a textual snapshot of the just-validated WKF for clipboard usage.
   */
  protected function extractValidatedWkfTextForCopy($api, string $wkfUri, ?string $rawWkfUri = NULL, string &$reason = ''): string {
    $reason = '';
    $candidateUris = [];
    $candidateUris[] = trim($wkfUri);
    if (is_string($rawWkfUri) && trim($rawWkfUri) !== '') {
      $candidateUris[] = trim($rawWkfUri);
      $decodedRaw = rawurldecode(trim($rawWkfUri));
      if ($decodedRaw !== '') {
        $candidateUris[] = $decodedRaw;
      }
    }

    $template = NULL;
    foreach (array_values(array_unique($candidateUris)) as $candidateUri) {
      if (!is_string($candidateUri) || $candidateUri === '') {
        continue;
      }
      $candidate = Utils::plainUri($candidateUri) ?: $candidateUri;
      $template = $api->parseObjectResponse($api->getUri($candidate), 'getUri');
      if (is_object($template)) {
        break;
      }
    }

    if (!is_object($template)) {
      $reason = 'WKF metadata could not be loaded.';
      return '';
    }

    // Some payloads embed hasDataFile but omit hasDataFileUri.
    if ((!isset($template->hasDataFileUri) || $template->hasDataFileUri == NULL || $template->hasDataFileUri === '')
      && isset($template->hasDataFile) && is_object($template->hasDataFile)
      && isset($template->hasDataFile->uri) && is_string($template->hasDataFile->uri) && $template->hasDataFile->uri !== '') {
      $template->hasDataFileUri = Utils::plainUri($template->hasDataFile->uri) ?: $template->hasDataFile->uri;
    }

    if (!isset($template->hasDataFile) && isset($template->hasDataFileUri) && is_string($template->hasDataFileUri) && $template->hasDataFileUri !== '') {
      $dataFileUri = Utils::plainUri($template->hasDataFileUri) ?: $template->hasDataFileUri;
      $dataFile = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
      if ($dataFile != NULL) {
        $template->hasDataFile = $dataFile;
      }
    }

    // If DataFile exists but lacks filename/id, enrich it from the KG object.
    if (isset($template->hasDataFileUri) && is_string($template->hasDataFileUri) && trim($template->hasDataFileUri) !== '') {
      $needsEnrichment = TRUE;
      if (isset($template->hasDataFile) && is_object($template->hasDataFile)) {
        $hasFilename = isset($template->hasDataFile->filename) && is_string($template->hasDataFile->filename) && trim((string) $template->hasDataFile->filename) !== '';
        $hasId = isset($template->hasDataFile->id) && trim((string) $template->hasDataFile->id) !== '';
        $needsEnrichment = !($hasFilename && $hasId);
      }

      if ($needsEnrichment) {
        $dataFileUri = Utils::plainUri($template->hasDataFileUri) ?: $template->hasDataFileUri;
        $dataFile = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
        if (is_object($dataFile)) {
          $template->hasDataFile = $dataFile;
        }
      }
    }

    $readability = $this->verifyLocalDataFileReadability($template);

    $resolvedPath = '';
    if (isset($readability['resolved_path']) && is_string($readability['resolved_path'])) {
      $resolvedPath = trim($readability['resolved_path']);
    }

    if (!empty($readability['ok']) && $resolvedPath === '' && isset($readability['tried']) && is_array($readability['tried'])) {
      foreach ($readability['tried'] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_readable($candidate)) {
          $resolvedPath = $candidate;
          break;
        }
      }
    }

    if ($resolvedPath === '') {
      // Fallback: use the exact file version from API when local filesystem
      // resolution is unavailable in this runtime.
      return $this->extractValidatedWkfTextFromRemoteDataFile($api, $template, $wkfUri, $reason);
    }

    $text = $this->renderWorkbookAsPlainText($resolvedPath, $wkfUri);
    if ($text === '') {
      $reason = 'Workbook parser could not read local WKF file.';
    }
    return $text;
  }

  /**
   * Build WKF copy text by downloading the just-validated datafile from API.
   */
  protected function extractValidatedWkfTextFromRemoteDataFile($api, $template, string $wkfUri, string &$reason = ''): string {
    if (!is_object($template) || !method_exists($api, 'downloadFile')) {
      $reason = 'Download API is unavailable in this environment.';
      return '';
    }

    $dataFileUri = '';
    if (isset($template->hasDataFileUri) && is_string($template->hasDataFileUri) && trim($template->hasDataFileUri) !== '') {
      $dataFileUri = trim((string) $template->hasDataFileUri);
    }
    else if (isset($template->hasDataFile) && is_object($template->hasDataFile)
      && isset($template->hasDataFile->uri) && is_string($template->hasDataFile->uri) && trim($template->hasDataFile->uri) !== '') {
      $dataFileUri = trim((string) $template->hasDataFile->uri);
    }

    $dataFileUri = Utils::plainUri($dataFileUri) ?: $dataFileUri;
    if ($dataFileUri === '') {
      $reason = 'No DataFile URI found for the validated WKF.';
      return '';
    }

    // Refresh DataFile object if filename is missing.
    if ((!isset($template->hasDataFile) || !is_object($template->hasDataFile)
      || !isset($template->hasDataFile->filename) || trim((string) $template->hasDataFile->filename) === '')) {
      $df = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
      if (is_object($df)) {
        $template->hasDataFile = $df;
      }
    }

    $filenameCandidates = [];

    if (isset($template->hasDataFile) && is_object($template->hasDataFile)
      && isset($template->hasDataFile->filename) && is_string($template->hasDataFile->filename)) {
      $filenameCandidates[] = trim((string) $template->hasDataFile->filename);
    }

    // Try Drupal file entity filename when FID is available.
    if (isset($template->hasDataFile) && is_object($template->hasDataFile)
      && isset($template->hasDataFile->id) && trim((string) $template->hasDataFile->id) !== '') {
      $fid = (int) $template->hasDataFile->id;
      if ($fid > 0) {
        $fileEntity = File::load($fid);
        if ($fileEntity !== NULL) {
          $filenameCandidates[] = trim((string) $fileEntity->getFilename());
        }
      }
    }

    // Fallback from URI path (may still be useful in some setups).
    $uriPath = parse_url($dataFileUri, PHP_URL_PATH);
    if (is_string($uriPath) && $uriPath !== '') {
      $filenameCandidates[] = basename($uriPath);
    }

    // Common PMSR MT workbook fallback names.
    $filenameCandidates[] = 'INS-PMSR.xlsx';
    $filenameCandidates[] = 'WKF-PMSR.xlsx';

    // If backend keeps filename without extension, probe expected spreadsheet extensions.
    $extensionVariants = [];
    foreach ($filenameCandidates as $candidateName) {
      $base = trim((string) $candidateName);
      if ($base === '' || strpos($base, '.') !== FALSE) {
        continue;
      }
      $extensionVariants[] = $base . '.xlsx';
      $extensionVariants[] = $base . '.xlsm';
      $extensionVariants[] = $base . '.xls';
    }
    $filenameCandidates = array_merge($filenameCandidates, $extensionVariants);

    $filenameCandidates = array_values(array_filter(array_unique($filenameCandidates), function ($name) {
      return is_string($name) && trim($name) !== '';
    }));

    if (empty($filenameCandidates)) {
      $reason = 'No candidate filename could be resolved for WKF download.';
      return '';
    }

    $binary = '';
    $chosenFilename = '';
    $elementUriCandidates = array_values(array_filter(array_unique([
      trim($dataFileUri),
      trim($wkfUri),
    ]), function ($candidateUri) {
      return is_string($candidateUri) && $candidateUri !== '';
    }));

    foreach ($elementUriCandidates as $elementUriCandidate) {
      foreach ($filenameCandidates as $candidateFilename) {
        $downloadResponse = $api->downloadFile($elementUriCandidate, $candidateFilename);
        if (!is_object($downloadResponse) || !method_exists($downloadResponse, 'getContent')) {
          continue;
        }

        $payload = (string) $downloadResponse->getContent();
        if ($payload === '') {
          continue;
        }

        $binary = $payload;
        $chosenFilename = $candidateFilename;
        break 2;
      }
    }

    if ($binary === '') {
      $reason = 'Remote WKF download returned empty content for all filename candidates.';
      return '';
    }

    $tmpRoot = '';
    try {
      $tmpRoot = (string) \Drupal::service('file_system')->realpath('temporary://');
    }
    catch (\Throwable $e) {
      $tmpRoot = '';
    }
    if ($tmpRoot === '') {
      $tmpRoot = sys_get_temp_dir();
    }

    $ext = strtolower((string) pathinfo($chosenFilename, PATHINFO_EXTENSION));
    if ($ext === '') {
      $ext = 'xlsx';
    }
    $tmpPath = rtrim($tmpRoot, '/') . '/wkf-copy-' . uniqid('', TRUE) . '.' . $ext;

    if (@file_put_contents($tmpPath, $binary) === FALSE) {
      $reason = 'Temporary file write failed while preparing WKF copy text.';
      return '';
    }

    try {
      $text = $this->renderWorkbookAsPlainText($tmpPath, $wkfUri);
      if ($text === '') {
        $reason = 'Workbook parser could not read downloaded WKF content.';
      }
      return $text;
    }
    finally {
      @unlink($tmpPath);
    }
  }

  /**
   * Convert an XLSX workbook to a compact, copy-friendly text snapshot.
   */
  protected function renderWorkbookAsPlainText(string $filePath, string $wkfUri): string {
    if (!is_readable($filePath)) {
      return '';
    }

    $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

    // Plain-text artifacts can be copied directly.
    if (in_array($ext, ['txt', 'csv', 'tsv', 'json', 'xml', 'md'], TRUE)) {
      $raw = @file_get_contents($filePath);
      if (!is_string($raw) || trim($raw) === '') {
        return '';
      }

      $text = 'WKF URI: ' . $wkfUri . "\n"
        . 'Source file: ' . basename($filePath) . "\n\n"
        . trim($raw);
      $maxChars = 250000;
      if (strlen($text) > $maxChars) {
        $text = substr($text, 0, $maxChars) . "\n\n[TRUNCATED: WKF snapshot exceeded copy size limit]";
      }
      return $text;
    }

    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
      return $this->renderWorkbookFromXlsxZipFallback($filePath, $wkfUri);
    }

    try {
      $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
      $lines = [];
      $lines[] = 'WKF URI: ' . $wkfUri;
      $lines[] = 'Source file: ' . basename($filePath);
      $lines[] = '';

      foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
        $sheetName = (string) $worksheet->getTitle();
        $highestRow = (int) $worksheet->getHighestDataRow();
        $highestCol = (string) $worksheet->getHighestDataColumn();

        $lines[] = '### Sheet: ' . $sheetName;
        if ($highestRow <= 0 || $highestCol === '') {
          $lines[] = '(empty)';
          $lines[] = '';
          continue;
        }

        $rows = $worksheet->rangeToArray('A1:' . $highestCol . $highestRow, '', TRUE, FALSE);
        foreach ($rows as $row) {
          $cells = [];
          foreach ($row as $cell) {
            $value = is_scalar($cell) ? (string) $cell : '';
            $value = trim(str_replace(["\r", "\n", "\t"], ' ', $value));
            $cells[] = $value;
          }

          while (!empty($cells) && end($cells) === '') {
            array_pop($cells);
          }

          if (empty($cells)) {
            continue;
          }

          $lines[] = implode("\t", $cells);
        }
        $lines[] = '';
      }

      $text = trim(implode("\n", $lines));
      $maxChars = 250000;
      if (strlen($text) > $maxChars) {
        $text = substr($text, 0, $maxChars) . "\n\n[TRUNCATED: WKF snapshot exceeded copy size limit]";
      }

      return $text;
    }
    catch (\Throwable $e) {
      return $this->renderWorkbookFromXlsxZipFallback($filePath, $wkfUri);
    }
  }

  /**
   * Lightweight XLSX parser fallback based on ZIP/XML.
   */
  protected function renderWorkbookFromXlsxZipFallback(string $filePath, string $wkfUri): string {
    if (!class_exists('\\ZipArchive')) {
      return '';
    }

    $zip = new \ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
      return '';
    }

    try {
      $sharedStrings = $this->extractXlsxSharedStrings($zip);
      $workbookXml = $zip->getFromName('xl/workbook.xml');
      $workbookRelsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
      if (!is_string($workbookXml) || $workbookXml === '' || !is_string($workbookRelsXml) || $workbookRelsXml === '') {
        return '';
      }

      $workbook = @simplexml_load_string($workbookXml);
      $rels = @simplexml_load_string($workbookRelsXml);
      if ($workbook === FALSE || $rels === FALSE) {
        return '';
      }

      $relMap = [];
      foreach ($rels->Relationship as $rel) {
        $attrs = $rel->attributes();
        $id = (string) ($attrs['Id'] ?? '');
        $target = (string) ($attrs['Target'] ?? '');
        if ($id !== '' && $target !== '') {
          $relMap[$id] = 'xl/' . ltrim($target, '/');
        }
      }

      $lines = [];
      $lines[] = 'WKF URI: ' . $wkfUri;
      $lines[] = 'Source file: ' . basename($filePath);
      $lines[] = '';

      $sheetCount = 0;
      foreach ($workbook->sheets->sheet as $sheet) {
        $sheetName = (string) ($sheet['name'] ?? 'Sheet');
        $sheetRid = (string) ($sheet->attributes('r', TRUE)['id'] ?? '');
        if ($sheetRid === '' || !isset($relMap[$sheetRid])) {
          continue;
        }

        $sheetXml = $zip->getFromName($relMap[$sheetRid]);
        if (!is_string($sheetXml) || $sheetXml === '') {
          continue;
        }

        $sheetObj = @simplexml_load_string($sheetXml);
        if ($sheetObj === FALSE) {
          continue;
        }

        $sheetCount++;
        $lines[] = '### Sheet: ' . $sheetName;

        $rows = [];
        foreach ($sheetObj->sheetData->row as $row) {
          $rowCells = [];
          foreach ($row->c as $cell) {
            $cellRef = (string) ($cell['r'] ?? '');
            $colIndex = $this->xlsxColumnIndexFromRef($cellRef);
            $type = (string) ($cell['t'] ?? '');
            $value = '';

            if ($type === 'inlineStr' && isset($cell->is->t)) {
              $value = (string) $cell->is->t;
            }
            else if ($type === 's' && isset($cell->v)) {
              $sharedIndex = (int) ((string) $cell->v);
              $value = $sharedStrings[$sharedIndex] ?? '';
            }
            else if (isset($cell->v)) {
              $value = (string) $cell->v;
            }

            $value = trim(str_replace(["\r", "\n", "\t"], ' ', $value));
            $rowCells[$colIndex] = $value;
          }

          if (empty($rowCells)) {
            continue;
          }

          ksort($rowCells);
          $maxIndex = max(array_keys($rowCells));
          $ordered = [];
          for ($i = 1; $i <= $maxIndex; $i++) {
            $ordered[] = $rowCells[$i] ?? '';
          }

          while (!empty($ordered) && end($ordered) === '') {
            array_pop($ordered);
          }

          if (!empty($ordered)) {
            $rows[] = implode("\t", $ordered);
          }
        }

        if (empty($rows)) {
          $lines[] = '(empty)';
        }
        else {
          foreach ($rows as $line) {
            $lines[] = $line;
          }
        }

        $lines[] = '';
      }

      if ($sheetCount === 0) {
        return '';
      }

      $text = trim(implode("\n", $lines));
      $maxChars = 250000;
      if (strlen($text) > $maxChars) {
        $text = substr($text, 0, $maxChars) . "\n\n[TRUNCATED: WKF snapshot exceeded copy size limit]";
      }

      return $text;
    }
    catch (\Throwable $e) {
      return '';
    }
    finally {
      $zip->close();
    }
  }

  /**
   * Parse XLSX shared strings table.
   */
  protected function extractXlsxSharedStrings(\ZipArchive $zip): array {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($xml) || $xml === '') {
      return [];
    }

    $doc = @simplexml_load_string($xml);
    if ($doc === FALSE) {
      return [];
    }

    $strings = [];
    foreach ($doc->si as $si) {
      if (isset($si->t)) {
        $strings[] = (string) $si->t;
        continue;
      }

      $acc = '';
      foreach ($si->r as $run) {
        if (isset($run->t)) {
          $acc .= (string) $run->t;
        }
      }
      $strings[] = $acc;
    }

    return $strings;
  }

  /**
   * Convert A1-style cell reference to 1-based column index.
   */
  protected function xlsxColumnIndexFromRef(string $cellRef): int {
    if ($cellRef === '') {
      return 1;
    }

    if (!preg_match('/^([A-Z]+)\d+$/', strtoupper($cellRef), $matches)) {
      return 1;
    }

    $letters = $matches[1];
    $index = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
      $index = ($index * 26) + (ord($letters[$i]) - ord('A') + 1);
    }

    return max(1, $index);
  }

  /**
   * Trigger lightweight PMSR members statistics refresh for one manager email.
   */
  protected function triggerPmsrMembersStatisticsRefreshByManagerEmail(?string $managerEmail): void {
    if (!\Drupal::moduleHandler()->moduleExists('pmsr')) {
      return;
    }

    $email = strtolower(trim((string) $managerEmail));
    if ($email === '') {
      return;
    }

    $baseUrl = \Drupal::request()->getSchemeAndHttpHost();
    $url = rtrim($baseUrl, '/') . '/pmsr/api/statistics/refresh/members?manager_email=' . rawurlencode($email);

    try {
      $response = \Drupal::httpClient()->request('POST', $url, [
        'timeout' => 25,
        'connect_timeout' => 2,
        'http_errors' => FALSE,
      ]);

      $status = (int) $response->getStatusCode();
      $body = (string) $response->getBody();
      $decoded = json_decode($body, TRUE);
      $ok = ($status >= 200 && $status < 300) && (!is_array($decoded) || !array_key_exists('success', $decoded) || !empty($decoded['success']));

      // Fallback to full members refresh when scoped refresh fails.
      if (!$ok) {
        $fallbackUrl = rtrim($baseUrl, '/') . '/pmsr/api/statistics/refresh/members';
        \Drupal::httpClient()->request('POST', $fallbackUrl, [
          'timeout' => 30,
          'connect_timeout' => 2,
          'http_errors' => FALSE,
        ]);
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->notice('Could not trigger PMSR members statistics refresh: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Normalize a raw manager email-ish value.
   */
  protected function normalizeManagerEmailValue($value): string {
    $email = strtolower(trim((string) $value));
    if ($email === '') {
      return '';
    }

    if (strpos($email, 'mailto:') === 0) {
      $email = substr($email, 7);
    }

    if (preg_match('/(?:^|[?&])manageremail=([^&\s]+)/i', $email, $m)) {
      $email = trim((string) $m[1]);
    }

    $qPos = strpos($email, '?');
    if ($qPos !== FALSE) {
      $email = substr($email, 0, $qPos);
    }

    if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $email, $m)) {
      return strtolower(trim((string) $m[0]));
    }

    return '';
  }

  /**
   * Extract manager emails from a metadata template-like API object.
   */
  protected function extractManagerEmailsFromTemplateObject($template): array {
    $emails = [];
    if (!is_object($template)) {
      return $emails;
    }

    $candidates = [];
    if (!empty($template->hasSIRManagerEmail)) {
      $candidates[] = $template->hasSIRManagerEmail;
    }
    if (!empty($template->principalInvestigator)) {
      $candidates[] = $template->principalInvestigator;
    }
    if (!empty($template->contactEmail)) {
      $candidates[] = $template->contactEmail;
    }

    if (isset($template->hasDataFile) && is_object($template->hasDataFile)) {
      if (!empty($template->hasDataFile->hasSIRManagerEmail)) {
        $candidates[] = $template->hasDataFile->hasSIRManagerEmail;
      }
      if (!empty($template->hasDataFile->principalInvestigator)) {
        $candidates[] = $template->hasDataFile->principalInvestigator;
      }
      if (!empty($template->hasDataFile->contactEmail)) {
        $candidates[] = $template->hasDataFile->contactEmail;
      }
    }

    foreach ($candidates as $candidate) {
      $normalized = $this->normalizeManagerEmailValue($candidate);
      if ($normalized !== '') {
        $emails[$normalized] = true;
      }
    }

    return array_keys($emails);
  }

  /**
   * Extract a concise backend reason from ingest API response payload.
   */
  protected function extractIngestionFailureDetail($uploadResponse): string {
    if ($uploadResponse === NULL || $uploadResponse === FALSE) {
      return '';
    }

    $raw = is_string($uploadResponse) ? trim($uploadResponse) : (string) $uploadResponse;
    if ($raw === '') {
      return '';
    }

    $obj = json_decode($raw);
    if (!is_object($obj)) {
      return '';
    }

    $body = '';
    if (isset($obj->body) && is_string($obj->body)) {
      $body = trim($obj->body);
    } else if (isset($obj->message) && is_string($obj->message)) {
      $body = trim($obj->message);
    }

    if ($body === '') {
      return '';
    }

    $body = preg_replace('/\s+/', ' ', $body);
    return mb_substr($body, 0, 300);
  }

  /**
   * Verify readability of the local DataFile behind the selected template.
   * Returns ['ok' => bool, 'reason' => string, 'tried' => string[]].
   */
  protected function verifyLocalDataFileReadability($template): array {
    $result = [
      'ok' => false,
      'reason' => '',
      'tried' => [],
      'resolved_path' => '',
    ];

    $fileId = NULL;
    if (isset($template->hasDataFile) && is_object($template->hasDataFile) && isset($template->hasDataFile->id)) {
      $fileId = $template->hasDataFile->id;
    }

    if (empty($fileId)) {
      $result['reason'] = 'template has no Drupal File ID (hasDataFile.id)';
      return $result;
    }

    $fileEntity = \Drupal\file\Entity\File::load($fileId);
    if ($fileEntity === NULL) {
      $result['reason'] = 'Drupal file entity not found for FID [' . $fileId . ']';
      return $result;
    }

    $fileUri = (string) $fileEntity->getFileUri();
    $filename = (string) $fileEntity->getFilename();

    $tryPath = function (?string $path) use (&$result): bool {
      if (!is_string($path) || trim($path) === '') {
        return false;
      }
      $path = trim($path);
      $result['tried'][] = $path;
      $ok = is_readable($path);
      if ($ok) {
        $result['resolved_path'] = $path;
      }
      return $ok;
    };

    // Direct absolute path (if any).
    if ($fileUri !== '' && !str_contains($fileUri, '://') && $tryPath($fileUri)) {
      $result['ok'] = true;
      $result['reason'] = 'readable absolute path';
      return $result;
    }

    // Stream-wrapper URI realpath.
    try {
      $fileSystem = \Drupal::service('file_system');
      $realPath = $fileSystem->realpath($fileUri);
      if (is_string($realPath) && $tryPath($realPath)) {
        $result['ok'] = true;
        $result['reason'] = 'readable stream-wrapper realpath';
        return $result;
      }
    } catch (\Throwable $e) {
      // Continue with explicit fallbacks.
    }

    // public:// explicit fallbacks.
    if ($fileUri !== '' && str_starts_with($fileUri, 'public://')) {
      $relative = ltrim(substr($fileUri, strlen('public://')), '/');

      $publicPath = (string) \Drupal::config('system.file')->get('path.public');
      if ($publicPath !== '') {
        $candidate = DRUPAL_ROOT . '/' . trim($publicPath, '/') . '/' . $relative;
        if ($tryPath($candidate)) {
          $result['ok'] = true;
          $result['reason'] = 'readable configured public path';
          return $result;
        }
      }

      try {
        $fileSystem = \Drupal::service('file_system');
        $publicRoot = $fileSystem->realpath('public://');
        if (is_string($publicRoot) && $publicRoot !== '') {
          $candidate = rtrim($publicRoot, '/') . '/' . $relative;
          if ($tryPath($candidate)) {
            $result['ok'] = true;
            $result['reason'] = 'readable public:// root path';
            return $result;
          }
        }
      } catch (\Throwable $e) {
        // Continue.
      }

      $candidate = DRUPAL_ROOT . '/sites/default/files/' . $relative;
      if ($tryPath($candidate)) {
        $result['ok'] = true;
        $result['reason'] = 'readable default public files path';
        return $result;
      }
    }

    // private:// explicit fallbacks.
    if ($fileUri !== '' && str_starts_with($fileUri, 'private://')) {
      $relative = ltrim(substr($fileUri, strlen('private://')), '/');

      $privatePath = (string) \Drupal::config('system.file')->get('path.private');
      if ($privatePath !== '') {
        $candidate = rtrim($privatePath, '/') . '/' . $relative;
        if ($tryPath($candidate)) {
          $result['ok'] = true;
          $result['reason'] = 'readable configured private path';
          return $result;
        }
      }

      try {
        $fileSystem = \Drupal::service('file_system');
        $privateRoot = $fileSystem->realpath('private://');
        if (is_string($privateRoot) && $privateRoot !== '') {
          $candidate = rtrim($privateRoot, '/') . '/' . $relative;
          if ($tryPath($candidate)) {
            $result['ok'] = true;
            $result['reason'] = 'readable private:// root path';
            return $result;
          }
        }
      } catch (\Throwable $e) {
        // Continue.
      }

      $candidate = DRUPAL_ROOT . '/sites/default/files/private/' . $relative;
      if ($tryPath($candidate)) {
        $result['ok'] = true;
        $result['reason'] = 'readable default private files path';
        return $result;
      }
    }

    // Known fallback for INS bootstrap file.
    if ($filename !== '' && strcasecmp($filename, 'INS-PMSR.xlsx') === 0) {
      try {
        $pmsrPath = \Drupal::service('extension.list.module')->getPath('pmsr');
        if (is_string($pmsrPath) && $pmsrPath !== '') {
          $candidate = DRUPAL_ROOT . '/' . trim($pmsrPath, '/') . '/mts/' . $filename;
          if ($tryPath($candidate)) {
            $result['ok'] = true;
            $result['reason'] = 'readable pmsr module mts fallback';
            return $result;
          }
        }
      } catch (\Throwable $e) {
        // Continue.
      }
    }

    $result['reason'] = 'no readable local path for FID [' . $fileId . '], URI [' . $fileUri . ']';
    $result['tried'] = array_values(array_unique($result['tried']));
    return $result;
  }

  /**
   * Read DataFile state/log to explain ingest failures when API returns no direct reason.
   */
  protected function extractDataFileFailureDetail($api, $template): string {
    if (!isset($template->hasDataFileUri) || !is_string($template->hasDataFileUri) || $template->hasDataFileUri === '') {
      return '';
    }

    $dfUri = Utils::plainUri($template->hasDataFileUri) ?: $template->hasDataFileUri;
    $df = $api->parseObjectResponse($api->getUri($dfUri), 'getUri');
    if (!is_object($df)) {
      return '';
    }

    $parts = [];
    if (isset($df->fileStatus) && is_string($df->fileStatus) && trim($df->fileStatus) !== '') {
      $parts[] = 'DataFile status: ' . trim($df->fileStatus);
    }

    $log = '';
    if (isset($df->log) && is_string($df->log)) {
      $log = trim($df->log);
    } else if (isset($df->hasLog) && is_string($df->hasLog)) {
      $log = trim($df->hasLog);
    }

    if ($log !== '') {
      $logOneLine = preg_replace('/\s+/', ' ', $log);
      if (preg_match('/Error in INSGenerator:[^\n\r]*/i', $logOneLine, $m)) {
        $parts[] = trim($m[0]);
      } else {
        $parts[] = mb_substr($logOneLine, 0, 240);
      }
    }

    return implode(' | ', $parts);
  }

  /**
   * Persist Drupal-side ingestion diagnostics when HASCO DataFile log is empty.
   */
  protected function appendLocalDataFileDiagnosticLog($template, string $message): void {
    if (!is_object($template)) {
      return;
    }

    $dataFileUri = '';
    if (isset($template->hasDataFileUri) && is_string($template->hasDataFileUri) && trim($template->hasDataFileUri) !== '') {
      $dataFileUri = trim((string) $template->hasDataFileUri);
    } else if (isset($template->hasDataFile) && is_object($template->hasDataFile) && isset($template->hasDataFile->uri) && is_string($template->hasDataFile->uri)) {
      $dataFileUri = trim((string) $template->hasDataFile->uri);
    }

    $dataFileUri = Utils::plainUri($dataFileUri) ?: $dataFileUri;
    if ($dataFileUri === '' || trim($message) === '') {
      return;
    }

    $stateKey = 'rep.datafile_local_logs';
    $store = \Drupal::state()->get($stateKey, []);
    if (!is_array($store)) {
      $store = [];
    }

    $existing = '';
    if (isset($store[$dataFileUri]) && is_string($store[$dataFileUri])) {
      $existing = $store[$dataFileUri];
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message);
    $combined = trim($existing) === '' ? $line : ($existing . "\n" . $line);

    // Keep only tail to avoid unbounded growth in state.
    $maxChars = 16000;
    if (strlen($combined) > $maxChars) {
      $combined = substr($combined, -$maxChars);
    }

    $store[$dataFileUri] = $combined;
    \Drupal::state()->set($stateKey, $store);
  }

  /**
   * UNINGEST FUNCTION
   *
   * Behavior:
   *  1) Fetch current MT + DF from the API and preserve them locally (in-memory).
   *  2) Call API to UNINGEST the MT.
   *  3) Recreate/persist the preserved MT/DF locally (status UNPROCESSED).
   *  4) If the DF has a Drupal File entity and its origin is 'api', purge ONLY the
   *     physical binary from private:// (keep the File entity). This forces a
   *     fresh download from the API on the next "Get It".
   *     - If origin is 'local', do NOT purge (API does not own that asset).
   */
  protected function performUningest(array $uris, FormStateInterface $form_state) {
    $api        = \Drupal::service('rep.api_connector');
    $originMgr  = \Drupal::service('rep.file_origin_manager'); // RepFileOriginManager (as discussed)
    $fs         = \Drupal::service('file_system');

    // Expecting a single URI in $uris.
    $uri = reset($uris);

    // 1) Retrieve and preserve current MT.
    $newMT = new MetadataTemplate();
    $mt = $api->parseObjectResponse($api->getUri($uri), 'getUri');
    if ($mt == NULL) {
      \Drupal::messenger()->addError(t('Failed to recover @type for uningestion.', ['@type' => $this->single_class_name]));
      return;
    }
    $newMT->setPreservedMT($mt);

    // 2) Retrieve and preserve DF.
    // Validate that MT has a DataFile URI
    if (empty($mt->hasDataFileUri)) {
      \Drupal::messenger()->addError(t('The @type does not have an associated DataFile URI. Cannot uningest.', ['@type' => $this->single_class_name]));
      \Drupal::logger('rep')->error('performUningest: MT @uri has no hasDataFileUri', ['@uri' => $uri]);
      return;
    }
    
    $df = $api->parseObjectResponse($api->getUri($mt->hasDataFileUri), 'getUri');
    if ($df == NULL) {
      \Drupal::messenger()->addError(t('Failed to recover DataFile of @type before uningestion.', ['@type' => $this->single_class_name]));
      return;
    }
    $newMT->setPreservedDF($df);

    // 3) Call API to UNINGEST.
    $msg = $api->parseObjectResponse($api->uningestMT($mt->uri), 'uningestMT');
    if ($msg == NULL) {
      \Drupal::messenger()->addError(t('The selected @type failed to be uningested.', ['@type' => $this->single_class_name]));
      return;
    }

    // 4) Recreate/persist preserved MT + DF locally (DataFile UNPROCESSED, etc.).
    $savedOk = $newMT->savePreservedMT($this->element_type);
    if (!$savedOk) {
      \Drupal::messenger()->addWarning(t('Uningest succeeded, but local preservation of @type could not be saved.', ['@type' => $this->single_class_name]));
    }

    // 5) Conditional purge of the local binary to enforce freshness on next download.
    //    We only purge if:
    //      - The DataFile has a Drupal File entity (fid).
    //      - The file origin is 'api' (remote owns the asset).
    //    We KEEP the File entity (do NOT delete from DB); we only delete the physical file
    //    so the download controller will re-fetch from the API on next "Get It".
    try {
      if (!empty($df->id)) {
        /** @var \Drupal\file\Entity\File|null $file */
        $file = \Drupal\file\Entity\File::load($df->id);
        if ($file) {
          $origin = $originMgr->getOrigin((int) $file->id());

          // For legacy files without origin tracking, assume 'api' as a reasonable default
          // since most existing files came from the API. Mark it now for future operations.
          if ($origin === NULL) {
            \Drupal::logger('rep')->notice('performUningest: File @fid has unknown origin, assuming API and marking it.', [
              '@fid' => $file->id(),
            ]);
            $originMgr->markApi((int) $file->id(), [
              'df_uri' => $df->uri ?? NULL,
              'migrated' => TRUE,
            ]);
            $origin = 'api';
          }

          if ($origin === 'api') {
            // Purge only the physical binary; keep the File entity record.
            $file_uri  = $file->getFileUri();
            $real_path = $file_uri ? $fs->realpath($file_uri) : NULL;

            if ($real_path && is_file($real_path)) {
              // Delete the physical file from private:// storage.
              $fs->delete($file_uri);
              // Do NOT delete file_managed row; keeping fid allows the controller
              // to trigger a cache-miss and re-download from the API on demand.
              \Drupal::messenger()->addStatus(t('Local binary was purged (API origin). Next "Get It" will fetch a fresh copy from the API.'));
            }
            else {
              // Nothing to purge physically.
              \Drupal::messenger()->addStatus(t('No local binary found to purge (API origin).'));
            }
          }
          elseif ($origin === 'local') {
            // Keep local files created via "Add New" (API does not have the asset).
            \Drupal::messenger()->addWarning(t('Binary was NOT purged because its origin is local. The API does not own this asset.'));
          }
          else {
            // Unexpected origin value (should never reach here after migration logic above).
            \Drupal::messenger()->addWarning(t('Binary was NOT purged due to unexpected origin value "@origin". No action taken.', [
              '@origin' => $origin,
            ]));
          }
        }
      }
    }
    catch (\Throwable $e) {
      // Non-fatal: uningest already succeeded; just warn about purge failure.
      \Drupal::messenger()->addWarning(t('Uningested, but failed to purge local binary: @msg', ['@msg' => $e->getMessage()]));
    }

    \Drupal::messenger()->addStatus(t('The selected @type was successfully uningested.', ['@type' => $this->single_class_name]));
    if ($this->element_type === 'wkf') {
      $refreshEmails = $this->extractManagerEmailsFromTemplateObject($mt);
      if (empty($refreshEmails)) {
        $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($this->manager_email);
      }
      else {
        foreach ($refreshEmails as $email) {
          $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($email);
        }
      }
    }

    // Optional: redirect back to the selector to refresh the list.
    $form_state->setRedirectUrl(static::backSelect($this->element_type, $this->getMode(), $this->studyuri));
    return;
  }


  /**
   * Extract the first href value from an HTML anchor snippet.
   */
  protected function extractHrefFromAnchorHtml(string $html): string {
    $html = trim($html);
    if ($html === '') {
      return '';
    }

    $matches = [];
    if (preg_match('/href\s*=\s*"([^"]+)"/i', $html, $matches) === 1 && !empty($matches[1])) {
      return html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match("/href\\s*=\\s*'([^']+)'/i", $html, $matches) === 1 && !empty($matches[1])) {
      return html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return '';
  }

  /**
   * Resolve source document display data for a WKF card.
   *
   * @return array{name:string,url:string,is_pdf:bool}
   */
  protected function resolveWkfSourceDocumentInfo(string $wkfUri): array {
    $context = $this->getPersistedPhase1ContextByWkfUri($wkfUri);
    if (empty($context)) {
      return ['name' => 'N/A', 'url' => '', 'is_pdf' => FALSE];
    }

    $sourceName = '';
    if (isset($context['sourceDocumentName']) && is_string($context['sourceDocumentName'])) {
      $sourceName = trim($context['sourceDocumentName']);
    }

    if ($sourceName === '' && isset($context['sourceDocumentContext']) && is_string($context['sourceDocumentContext'])) {
      $sourceContext = trim($context['sourceDocumentContext']);
      if ($sourceContext !== '') {
        $matches = [];
        if (preg_match('/Supporting\s+document\s+filename\s*:\s*(.+)$/i', $sourceContext, $matches) === 1 && !empty($matches[1])) {
          $sourceName = trim((string) $matches[1]);
        }
        else {
          $sourceName = $sourceContext;
        }
      }
    }

    if ($sourceName === '') {
      $sourceName = 'N/A';
    }

    $sourceUrl = '';
    $sourceFileUri = isset($context['sourceDocumentFileUri']) && is_string($context['sourceDocumentFileUri'])
      ? trim($context['sourceDocumentFileUri'])
      : '';
    if ($wkfUri !== '' && ($sourceFileUri !== '' || strtolower((string) pathinfo($sourceName, PATHINFO_EXTENSION)) === 'pdf')) {
      $sourceUrl = Url::fromRoute('rep.wkf_source_view', [
        'wkfuri' => base64_encode($wkfUri),
      ])->toString();
    }

    $isPdf = (strtolower((string) pathinfo($sourceName, PATHINFO_EXTENSION)) === 'pdf');

    return [
      'name' => $sourceName,
      'url' => $sourceUrl,
      'is_pdf' => $isPdf,
    ];
  }

  /**
   * Read persisted Phase I packet context keyed by WKF URI.
   */
  protected function getPersistedPhase1ContextByWkfUri(string $wkfUri): array {
    $normalized = Utils::plainUri($wkfUri) ?: trim($wkfUri);
    if ($normalized === '') {
      return [];
    }

    $store = \Drupal::keyValue('rep.wkf.phase1.context.by_uri');
    $entry = $store->get($normalized, []);
    return is_array($entry) ? $entry : [];
  }

  /**
   * Resolve source text display data for a WKF card.
   *
   * @return array{name:string,url:string}
   */
  protected function resolveWkfSourceTextInfo(string $wkfUri, string $sourceName): array {
    $context = $this->getPersistedPhase1ContextByWkfUri($wkfUri);
    $textName = isset($context['sourceTextFileName']) && is_string($context['sourceTextFileName'])
      ? trim($context['sourceTextFileName'])
      : '';
    $sourceText = isset($context['sourceDocumentContent']) && is_string($context['sourceDocumentContent'])
      ? trim($context['sourceDocumentContent'])
      : '';

    if ($textName === '') {
      $baseName = trim((string) pathinfo($sourceName, PATHINFO_FILENAME));
      if ($baseName === '') {
        $baseName = 'source_document';
      }
      $textName = $baseName . '.txt';
    }

    $textUrl = '';
    $sourceTextFileUri = isset($context['sourceTextFileUri']) && is_string($context['sourceTextFileUri'])
      ? trim($context['sourceTextFileUri'])
      : '';
    if ($wkfUri !== '' && ($sourceTextFileUri !== '' || $sourceText !== '')) {
      $textUrl = Url::fromRoute('rep.wkf_source_text_view', [
        'wkfuri' => base64_encode($wkfUri),
      ])->toString();
    }

    return [
      'name' => $textName,
      'url' => $textUrl,
    ];
  }

  /**
   * Resolve owner affiliation organization label for a WKF card.
   */
  protected function resolveWkfOwnerOrganizationLabelForCard(string $wkfUri): string {
    $wkfUri = trim($wkfUri);
    if ($wkfUri === '') {
      return 'N/A';
    }

    if (array_key_exists($wkfUri, $this->wkfOwnerOrganizationLabelCache)) {
      return $this->wkfOwnerOrganizationLabelCache[$wkfUri];
    }

    $label = 'N/A';

    try {
      $api = \Drupal::service('rep.api_connector');
      $raw = $api->getUri($wkfUri);
      $wkf = $api->parseObjectResponse($raw, 'getUri');

      if (is_object($wkf)) {
        $ownerEmail = '';
        if (isset($wkf->hasSIRManagerEmail) && is_string($wkf->hasSIRManagerEmail)) {
          $ownerEmail = trim((string) $wkf->hasSIRManagerEmail);
        }
        elseif (isset($wkf->managerEmail) && is_string($wkf->managerEmail)) {
          $ownerEmail = trim((string) $wkf->managerEmail);
        }

        if ($ownerEmail !== '') {
          $label = $this->resolveOwnerAffiliationOrganizationLabelByEmail($ownerEmail);
        }
      }
    }
    catch (\Throwable $e) {
      $label = 'N/A';
    }

    if ($label === '') {
      $label = 'N/A';
    }
    $this->wkfOwnerOrganizationLabelCache[$wkfUri] = $label;

    return $label;
  }

  /**
   * Resolve owner email for a WKF card.
   */
  protected function resolveWkfOwnerEmailForCard(string $wkfUri): string {
    $wkfUri = trim($wkfUri);
    if ($wkfUri === '') {
      return '';
    }

    if (array_key_exists($wkfUri, $this->wkfOwnerEmailCache)) {
      return $this->wkfOwnerEmailCache[$wkfUri];
    }

    $ownerEmail = '';
    try {
      $api = \Drupal::service('rep.api_connector');
      $raw = $api->getUri($wkfUri);
      $wkf = $api->parseObjectResponse($raw, 'getUri');
      if (is_object($wkf)) {
        if (isset($wkf->hasSIRManagerEmail) && is_string($wkf->hasSIRManagerEmail)) {
          $ownerEmail = $this->normalizeEmailValue((string) $wkf->hasSIRManagerEmail);
        }
        elseif (isset($wkf->managerEmail) && is_string($wkf->managerEmail)) {
          $ownerEmail = $this->normalizeEmailValue((string) $wkf->managerEmail);
        }
      }
    }
    catch (\Throwable $e) {
      $ownerEmail = '';
    }

    $this->wkfOwnerEmailCache[$wkfUri] = $ownerEmail;
    return $ownerEmail;
  }

  /**
   * Resolve affiliation organization label for an owner email.
   */
  protected function resolveOwnerAffiliationOrganizationLabelByEmail(string $ownerEmail): string {
    $ownerEmail = $this->normalizeEmailValue($ownerEmail);
    if ($ownerEmail === '') {
      return 'N/A';
    }

    $cacheKey = $ownerEmail;
    if (array_key_exists($cacheKey, $this->ownerAffiliationLabelByEmailCache)) {
      return $this->ownerAffiliationLabelByEmailCache[$cacheKey];
    }

    $label = 'N/A';

    try {
      $api = \Drupal::service('rep.api_connector');
      $rawPeople = $api->listByManagerEmail('person', $ownerEmail, 200, 0);
      $people = $api->parseObjectResponse($rawPeople, 'listByManagerEmail');

      $candidates = [];
      if (is_array($people)) {
        foreach ($people as $person) {
          if (!is_object($person)) {
            continue;
          }
          $personEmail = $this->extractPersonEmail($person);
          if ($personEmail !== '' && $personEmail === $ownerEmail) {
            $candidates[] = $person;
          }
        }
      }

      if (empty($candidates)) {
        // Fallback: search broader person set by email match.
        $rawAllPeople = $api->listByKeyword('person', '_', 500, 0);
        $allPeople = $api->parseObjectResponse($rawAllPeople, 'listByKeyword');
        if (is_array($allPeople)) {
          foreach ($allPeople as $person) {
            if (!is_object($person)) {
              continue;
            }
            $personEmail = $this->extractPersonEmail($person);
            if ($personEmail !== '' && $personEmail === $ownerEmail) {
              $candidates[] = $person;
            }
          }
        }
      }

      foreach ($candidates as $person) {
        $affiliationUri = $this->extractPersonAffiliationUri($person);
        if ($affiliationUri === '') {
          continue;
        }

        $rawOrg = $api->getUri($affiliationUri);
        $org = $api->parseObjectResponse($rawOrg, 'getUri');

        if (is_object($org) && isset($org->label) && is_string($org->label) && trim((string) $org->label) !== '') {
          $label = trim((string) $org->label);
          break;
        }

        $label = Utils::namespaceUri($affiliationUri);
        if (trim($label) !== '') {
          break;
        }
      }
    }
    catch (\Throwable $e) {
      $label = 'N/A';
    }

    if ($label === '') {
      $label = 'N/A';
    }
    $this->ownerAffiliationLabelByEmailCache[$cacheKey] = $label;

    return $label;
  }

  /**
   * Normalize an email value for robust equality checks.
   */
  protected function normalizeEmailValue(string $value): string {
    $email = trim($value);
    if ($email === '') {
      return '';
    }
    if (stripos($email, 'mailto:') === 0) {
      $email = trim(substr($email, 7));
    }
    return strtolower($email);
  }

  /**
   * Extract person email from common person payload fields.
   */
  protected function extractPersonEmail($person): string {
    if (!is_object($person)) {
      return '';
    }

    foreach (['mbox', 'hasEmail', 'email'] as $field) {
      if (!isset($person->{$field})) {
        continue;
      }

      $value = $person->{$field};
      if (is_string($value)) {
        $email = $this->normalizeEmailValue($value);
        if ($email !== '') {
          return $email;
        }
      }
      elseif (is_array($value)) {
        foreach ($value as $entry) {
          if (!is_string($entry)) {
            continue;
          }
          $email = $this->normalizeEmailValue($entry);
          if ($email !== '') {
            return $email;
          }
        }
      }
    }

    return '';
  }

  /**
   * Extract affiliation URI from a person payload.
   */
  protected function extractPersonAffiliationUri($person): string {
    if (!is_object($person)) {
      return '';
    }

    if (isset($person->hasAffiliationUri) && is_string($person->hasAffiliationUri)) {
      return trim((string) $person->hasAffiliationUri);
    }

    if (isset($person->hasAffiliation) && is_object($person->hasAffiliation) && isset($person->hasAffiliation->uri) && is_string($person->hasAffiliation->uri)) {
      return trim((string) $person->hasAffiliation->uri);
    }

    return '';
  }

  /**
   * Resolve Process Stem URI for WKF card display.
   */
  protected function resolveWkfProcessStemUriForCard(string $wkfUri): string {
    $context = $this->getPersistedPhase1ContextByWkfUri($wkfUri);

    if (isset($context['processStemUri']) && is_string($context['processStemUri'])) {
      $uri = trim($context['processStemUri']);
      if ($uri !== '') {
        return $uri;
      }
    }

    if (isset($context['phase1CoreContext']) && is_string($context['phase1CoreContext'])) {
      $core = (string) $context['phase1CoreContext'];
      $matches = [];
      if (preg_match('/^Clinical\s+Process\s+URI:\s*(.+)$/mi', $core, $matches) === 1 && !empty($matches[1])) {
        return trim((string) $matches[1]);
      }
    }

    return '';
  }

  /**
   * Resolve task count for a WKF by trying process URI candidates.
   *
   * @return array{count:?int,process_uri:string}
   */
  protected function resolveWkfTaskCountAndProcessUri(string $wkfUri): array {
    $persistedCount = $this->getPersistedWkfTaskCount($wkfUri);

    if ($wkfUri === '' || !\Drupal::moduleHandler()->moduleExists('ctt') || !\Drupal::hasService('ctt.hasco_client')) {
      $localCount = $this->resolveWkfTaskCountFromLocalContext($wkfUri);
      $best = $this->pickBestTaskCount($persistedCount, $localCount, NULL);
      return ['count' => $best, 'process_uri' => ''];
    }

    $client = \Drupal::service('ctt.hasco_client');
    $bestCount = NULL;
    $bestUri = '';

    foreach ($this->buildWkfProcessUriCandidates($wkfUri) as $candidate) {
      try {
        $tasks = $client->getTasksByProcess($candidate);
        if (!is_array($tasks)) {
          continue;
        }

        $count = count($tasks);
        if ($bestCount === NULL || $count > $bestCount) {
          $bestCount = $count;
          $bestUri = $candidate;
        }
      }
      catch (\Throwable $e) {
        continue;
      }
    }

    $localCount = $this->resolveWkfTaskCountFromLocalContext($wkfUri);
    $best = $this->pickBestTaskCount($persistedCount, $localCount, $bestCount);

    return ['count' => $best, 'process_uri' => $bestUri];
  }

  /**
   * Resolve most recent persisted authoritative task count from task updates.
   */
  protected function getPersistedWkfTaskCount(string $wkfUri): ?int {
    $normalized = Utils::plainUri($wkfUri) ?: trim($wkfUri);
    if ($normalized === '') {
      return NULL;
    }

    $store = \Drupal::keyValue('rep.wkf.task_count.by_uri');
    $entry = $store->get($normalized, NULL);

    if (is_array($entry) && isset($entry['count']) && is_numeric($entry['count'])) {
      $count = (int) $entry['count'];
      return $count >= 0 ? $count : NULL;
    }

    if (is_numeric($entry)) {
      $count = (int) $entry;
      return $count >= 0 ? $count : NULL;
    }

    return NULL;
  }

  /**
   * Pick highest non-null task count among known sources.
   */
  protected function pickBestTaskCount(?int $persistedCount, ?int $localCount, ?int $apiCount): ?int {
    $best = NULL;
    foreach ([$persistedCount, $localCount, $apiCount] as $candidate) {
      if ($candidate === NULL) {
        continue;
      }
      if ($best === NULL || $candidate > $best) {
        $best = $candidate;
      }
    }
    return $best;
  }

  /**
   * Resolve Tasks row count from locally stored WKF TSV contexts.
   */
  protected function resolveWkfTaskCountFromLocalContext(string $wkfUri): ?int {
    foreach ($this->getWkfWorkbookCandidateContents($wkfUri) as $content) {
      $count = $this->countTasksRowsFromWorkbookTsv((string) $content);
      if ($count !== NULL) {
        return $count;
      }
    }

    return NULL;
  }

  /**
   * Resolve number of interactive/auto tasks from local WKF TSV contexts.
   */
  protected function resolveWkfInteractiveAutoTaskCountFromLocalContext(string $wkfUri): ?int {
    foreach ($this->getWkfWorkbookCandidateContents($wkfUri) as $content) {
      $count = $this->countInteractiveAutoTasksFromWorkbookTsv((string) $content);
      if ($count !== NULL) {
        return $count;
      }
    }

    return NULL;
  }

  /**
   * Resolve number of used components from local WKF TSV contexts.
   */
  protected function resolveWkfUsedComponentsCountFromLocalContext(string $wkfUri): ?int {
    foreach ($this->getWkfWorkbookCandidateContents($wkfUri) as $content) {
      $count = $this->countUsedComponentsFromWorkbookTsv((string) $content);
      if ($count !== NULL) {
        return $count;
      }
    }

    return NULL;
  }

  /**
   * Resolve number of scenario properties with values from local WKF TSV contexts.
   */
  protected function resolveWkfScenarioPropsCountFromLocalContext(string $wkfUri): ?int {
    foreach ($this->getWkfWorkbookCandidateContents($wkfUri) as $content) {
      $count = $this->countScenarioPropsFromWorkbookTsv((string) $content);
      if ($count !== NULL) {
        return $count;
      }
    }

    return NULL;
  }

  /**
   * Collect workbook-like WKF TSV candidates from local stores.
   *
   * @return array<int, string>
   */
  protected function getWkfWorkbookCandidateContents(string $wkfUri): array {
    $scope = $this->resolveWkfScopeFromUri($wkfUri);
    $candidates = [];

    $workingStore = \Drupal::keyValue('rep.wkf.phase_working_copy.by_scope');
    $working = $workingStore->get($scope, []);
    if (is_array($working) && isset($working['wkfContent']) && is_string($working['wkfContent'])) {
      $candidates[] = $working['wkfContent'];
    }

    $session = \Drupal::request()->getSession();
    if ($session !== NULL) {
      $panelStore = $session->get('rep.wkf.validation.panel.by_scope', []);
      if (is_array($panelStore) && isset($panelStore[$scope]) && is_array($panelStore[$scope])) {
        $panel = $panelStore[$scope];
        if (isset($panel['wkfCopyContent']) && is_string($panel['wkfCopyContent'])) {
          $candidates[] = $panel['wkfCopyContent'];
        }
      }
    }

    $phase1 = $this->getPersistedPhase1ContextByWkfUri($wkfUri);
    if (isset($phase1['phase1WkfTableTsv']) && is_string($phase1['phase1WkfTableTsv'])) {
      $candidates[] = $phase1['phase1WkfTableTsv'];
    }

    return $candidates;
  }

  /**
   * Count data rows in the Tasks sheet inside workbook-like TSV content.
   */
  protected function countTasksRowsFromWorkbookTsv(string $workbookTsv): ?int {
    $text = str_replace(["\r\n", "\r"], "\n", trim($workbookTsv));
    if ($text === '') {
      return NULL;
    }

    $tasksBody = '';
    if (preg_match('/^### SHEET:\s*Tasks\s*$\n(.*?)(?=^### SHEET:\s*|\z)/ms', $text, $match) === 1) {
      $tasksBody = trim((string) ($match[1] ?? ''));
    }
    else {
      // Fallback: content may already be plain Tasks TSV.
      $tasksBody = $text;
    }

    if ($tasksBody === '') {
      return NULL;
    }

    $lines = explode("\n", $tasksBody);
    if (count($lines) < 1) {
      return NULL;
    }

    $header = trim((string) $lines[0]);
    if ($header === '' || strpos($header, "\t") === FALSE) {
      return NULL;
    }

    $rows = 0;
    for ($i = 1; $i < count($lines); $i++) {
      if (trim((string) $lines[$i]) !== '') {
        $rows++;
      }
    }

    return $rows;
  }

  /**
   * Count Tasks rows whose rdf:type is interactive or automated/manual.
   */
  protected function countInteractiveAutoTasksFromWorkbookTsv(string $workbookTsv): ?int {
    $text = str_replace(["\r\n", "\r"], "\n", trim($workbookTsv));
    if ($text === '') {
      return NULL;
    }

    $tasksBody = '';
    if (preg_match('/^### SHEET:\s*Tasks\s*$\n(.*?)(?=^### SHEET:\s*|\z)/ms', $text, $match) === 1) {
      $tasksBody = trim((string) ($match[1] ?? ''));
    }
    else {
      $tasksBody = $text;
    }

    if ($tasksBody === '') {
      return NULL;
    }

    $lines = explode("\n", $tasksBody);
    if (count($lines) < 1) {
      return NULL;
    }

    $header = trim((string) $lines[0]);
    if ($header === '' || strpos($header, "\t") === FALSE) {
      return NULL;
    }

    $headerCols = array_map('trim', explode("\t", $header));
    $typeColIdx = -1;
    foreach ($headerCols as $idx => $columnName) {
      $normalized = $this->normalizeWorkbookHeaderColumn($columnName);
      if ($normalized === 'rdf:type') {
        $typeColIdx = (int) $idx;
        break;
      }
    }

    if ($typeColIdx < 0) {
      return NULL;
    }

    $rows = 0;
    for ($i = 1; $i < count($lines); $i++) {
      $line = (string) $lines[$i];
      if (trim($line) === '') {
        continue;
      }
      $cols = explode("\t", $line);
      $typeValue = isset($cols[$typeColIdx]) ? trim((string) $cols[$typeColIdx]) : '';
      if ($this->isInteractiveOrAutoTaskType($typeValue)) {
        $rows++;
      }
    }

    return $rows;
  }

  /**
   * Determine whether rdf:type matches interactive or automated/manual task.
   */
  protected function isInteractiveOrAutoTaskType(string $typeValue): bool {
    $normalized = strtolower(trim($typeValue));
    if ($normalized === '') {
      return FALSE;
    }

    return strpos($normalized, 'interactiontask') !== FALSE
      || strpos($normalized, 'automatedtask') !== FALSE
      || strpos($normalized, 'applicationtask') !== FALSE
      || strpos($normalized, 'manualtask') !== FALSE;
  }

  /**
   * Count unique used components from Tasks sheet required-instrument column.
   */
  protected function countUsedComponentsFromWorkbookTsv(string $workbookTsv): ?int {
    $text = str_replace(["\r\n", "\r"], "\n", trim($workbookTsv));
    if ($text === '') {
      return NULL;
    }

    $tasksBody = '';
    if (preg_match('/^### SHEET:\s*Tasks\s*$\n(.*?)(?=^### SHEET:\s*|\z)/ms', $text, $match) === 1) {
      $tasksBody = trim((string) ($match[1] ?? ''));
    }
    else {
      $tasksBody = $text;
    }

    if ($tasksBody === '') {
      return NULL;
    }

    $lines = explode("\n", $tasksBody);
    if (count($lines) < 1) {
      return NULL;
    }

    $header = trim((string) $lines[0]);
    if ($header === '' || strpos($header, "\t") === FALSE) {
      return NULL;
    }

    $headerCols = array_map('trim', explode("\t", $header));
    $componentColIdx = -1;
    foreach ($headerCols as $idx => $columnName) {
      $normalized = $this->normalizeWorkbookHeaderColumn($columnName);
      if ($normalized === 'vstoi:hasrequiredinstrument') {
        $componentColIdx = (int) $idx;
        break;
      }
    }

    if ($componentColIdx < 0) {
      return NULL;
    }

    $uniqueComponents = [];
    for ($i = 1; $i < count($lines); $i++) {
      $line = (string) $lines[$i];
      if (trim($line) === '') {
        continue;
      }

      $cols = explode("\t", $line);
      if (!isset($cols[$componentColIdx])) {
        continue;
      }

      $rawCell = trim((string) $cols[$componentColIdx]);
      if ($rawCell === '') {
        continue;
      }

      $parts = preg_split('/\s*[;,|]\s*/', $rawCell);
      if (!is_array($parts) || empty($parts)) {
        $parts = [$rawCell];
      }

      foreach ($parts as $part) {
        $token = trim((string) $part);
        if ($token === '') {
          continue;
        }
        $uniqueComponents[strtolower($token)] = TRUE;
      }
    }

    return count($uniqueComponents);
  }

  /**
   * Count STD scenario-property columns that have at least one value.
   */
  protected function countScenarioPropsFromWorkbookTsv(string $workbookTsv): ?int {
    $text = str_replace(["\r\n", "\r"], "\n", trim($workbookTsv));
    if ($text === '') {
      return NULL;
    }

    $stdBody = '';
    if (preg_match('/^### SHEET:\s*STD\s*$\n(.*?)(?=^### SHEET:\s*|\z)/ms', $text, $match) === 1) {
      $stdBody = trim((string) ($match[1] ?? ''));
    }
    else {
      $stdBody = $text;
    }

    if ($stdBody === '') {
      return NULL;
    }

    $lines = explode("\n", $stdBody);
    if (count($lines) < 1) {
      return NULL;
    }

    $header = trim((string) $lines[0]);
    if ($header === '' || strpos($header, "\t") === FALSE) {
      return NULL;
    }

    $headerCols = array_map('trim', explode("\t", $header));
    $targetIdx = [];
    foreach ($headerCols as $idx => $columnName) {
      $normalized = $this->normalizeWorkbookHeaderColumn($columnName);
      // Exclude identifier column; all other STD columns are properties.
      if ($normalized === '' || $normalized === 'hasuri') {
        continue;
      }
      $targetIdx[$normalized] = (int) $idx;
    }

    if (empty($targetIdx)) {
      return NULL;
    }

    $filledProperties = [];
    for ($i = 1; $i < count($lines); $i++) {
      $line = (string) $lines[$i];
      if (trim($line) === '') {
        continue;
      }

      $cols = explode("\t", $line);
      foreach ($targetIdx as $propertyKey => $idx) {
        if (!isset($cols[$idx])) {
          continue;
        }
        if (trim((string) $cols[$idx]) !== '') {
          $filledProperties[$propertyKey] = TRUE;
        }
      }
    }

    return count($filledProperties);
  }

  /**
   * Normalize workbook header name for tolerant matching.
   */
  protected function normalizeWorkbookHeaderColumn(string $column): string {
    $normalized = strtolower(trim($column));
    $normalized = preg_replace('/\s+/', '', $normalized);
    return is_string($normalized) ? $normalized : '';
  }

  /**
   * Build process URI candidates for one WKF.
   *
   * @return array<int, string>
   */
  protected function buildWkfProcessUriCandidates(string $wkfUri): array {
    $candidates = [];

    $normalized = Utils::plainUri($wkfUri) ?: trim($wkfUri);
    if ($normalized === '') {
      return [];
    }

    $context = $this->getPersistedPhase1ContextByWkfUri($wkfUri);

    if (isset($context['processUri']) && is_string($context['processUri']) && trim($context['processUri']) !== '') {
      $candidates[] = trim($context['processUri']);
    }

    if (isset($context['phase1CoreContext']) && is_string($context['phase1CoreContext'])) {
      $core = $context['phase1CoreContext'];
      $matches = [];
      if (preg_match('/^Phase I Process URI:\s*(.+)$/mi', $core, $matches) === 1 && !empty($matches[1])) {
        $candidates[] = trim((string) $matches[1]);
      }
    }

    if (stripos($normalized, '/PROC/') !== FALSE) {
      $candidates[] = $normalized;
    }
    else {
      $base = rtrim($normalized, '/');
      $candidates[] = $base . '/PROC/0001';
      $candidates[] = $base . '/PROC/PROC001';
    }

    if (preg_match('/^pmsr:WKF(.+)$/i', $normalized, $matches) === 1 && !empty($matches[1])) {
      $wkfCode = 'WKF' . trim((string) $matches[1]);
      $candidates[] = 'https://pmsr.net/ont/' . $wkfCode . '/PROC/0001';
      $candidates[] = 'https://pmsr.net/ont/' . $wkfCode . '/PROC/PROC001';
      $candidates[] = 'http://pmsr.net/ont/' . $wkfCode . '/PROC/0001';
      $candidates[] = 'http://pmsr.net/ont/' . $wkfCode . '/PROC/PROC001';
      $candidates[] = 'pmsr:/' . $wkfCode . '/PROC/0001';
      $candidates[] = 'pmsr:/' . $wkfCode . '/PROC/PROC001';
    }

    $unique = [];
    $result = [];
    foreach ($candidates as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate === '') {
        continue;
      }
      $key = strtolower($candidate);
      if (isset($unique[$key])) {
        continue;
      }
      $unique[$key] = TRUE;
      $result[] = $candidate;
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public static function backSelect($elementType, $mode, $studyuri)
  {
    if ($elementType === 'wkf') {
      $url = Url::fromRoute('rep.select_wkf_element');
    }
    else {
      $url = Url::fromRoute('rep.select_mt_element');
      $url->setRouteParameter('elementtype', $elementType);
    }
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
   * Build Phase I hierarchy options from Scenario Search process stems.
   */
  protected function buildScenarioClinicalProcessOptionsHtml(): string {
    $options = [];
    foreach ($this->getScenarioProcessStemFilters() as $filter) {
      if (!is_array($filter)) {
        continue;
      }

      $label = trim((string) ($filter['label'] ?? ''));
      if ($label === '') {
        continue;
      }

      $value = trim((string) ($filter['slug'] ?? ''));
      if ($value === '') {
        $value = trim((string) ($filter['uri'] ?? ''));
      }
      if ($value === '') {
        $value = $label;
      }

      $options[$value] = $label;
    }

    if (empty($options)) {
      return '<option value="">No clinical process found</option>';
    }

    $html = '';
    foreach ($options as $value => $label) {
      $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
  }

  /**
   * Get aggregated process-stem filters exactly as Scenario Search uses.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getScenarioProcessStemFilters(): array {
    if (!\Drupal::moduleHandler()->moduleExists('std') || !\Drupal::hasService('rep.api_connector')) {
      return [];
    }

    try {
      if (\Drupal::hasService('std.study_variable_search')) {
        $searchService = \Drupal::service('std.study_variable_search');
      }
      else {
        $searchService = new \Drupal\std\Service\StudyVariableSearchService(
          \Drupal::service('rep.api_connector'),
          \Drupal::service('file_system'),
        );
      }

      $currentUser = \Drupal::currentUser();
      $userEmail = trim((string) $currentUser->getEmail());
      $isAdmin = ManageOwnerFilter::isAdmin() || $currentUser->hasPermission('administer study search');

      $context = $searchService->buildContext(
        $userEmail,
        $isAdmin,
        $currentUser->isAuthenticated(),
      );

      $processFilters = is_array($context['process_filters'] ?? NULL)
        ? $context['process_filters']
        : [];

      $aggregated = [];
      foreach ($processFilters as $processData) {
        if (!is_array($processData)) {
          continue;
        }

        $stemSlug = trim((string) ($processData['stem_slug'] ?? ''));
        $stemLabel = trim((string) ($processData['stem_label'] ?? ''));
        $stemUri = trim((string) ($processData['stem_uri'] ?? ''));
        if ($stemSlug === '' || $stemLabel === '') {
          continue;
        }

        if (!isset($aggregated[$stemSlug])) {
          $aggregated[$stemSlug] = [
            'slug' => $stemSlug,
            'label' => $stemLabel,
            'uri' => $stemUri,
            'count' => 0,
          ];
        }

        $aggregated[$stemSlug]['count'] += (int) ($processData['count'] ?? 0);
      }

      uasort($aggregated, static fn(array $a, array $b): int => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
      return array_values($aggregated);
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  /**
   * Convert markdown to HTML for WKF instructions
   */
  protected function convertMarkdownToHtml($markdown) {
    if (empty($markdown)) {
      return '';
    }

    $lines = explode("\n", $markdown);
    $html = '';
    $in_ordered_list = false;
    $in_unordered_list = false;

    foreach ($lines as $line) {
      $trimmed = trim($line);
      
      // Headers
      if (preg_match('/^##### (.+)$/', $trimmed, $matches)) {
        if ($in_ordered_list) {
          $html .= '</ol>';
          $in_ordered_list = false;
        }
        if ($in_unordered_list) {
          $html .= '</ul>';
          $in_unordered_list = false;
        }
        $html .= '<h5>' . htmlspecialchars($matches[1]) . '</h5>';
      }
      // Numbered list items (main points)
      elseif (preg_match('/^(\d+)\.\s+\*\*(.+?)\*\*:?\s*$/', $trimmed, $matches)) {
        if (!$in_ordered_list) {
          if ($in_unordered_list) {
            $html .= '</ul>';
            $in_unordered_list = false;
          }
          $html .= '<ol>';
          $in_ordered_list = true;
        }
        $html .= '<li><strong>' . htmlspecialchars($matches[2]) . ':</strong><ul>';
        $in_unordered_list = true;
      }
      // Unordered list items (sub-points with dashes)
      elseif (preg_match('/^\s+-\s+(.+)$/', $line, $matches)) {
        if (!$in_unordered_list) {
          $html .= '<ul>';
          $in_unordered_list = true;
        }
        $html .= '<li>' . htmlspecialchars($matches[1]) . '</li>';
      }
      // Empty line - close unordered list if needed
      elseif (empty($trimmed)) {
        if ($in_unordered_list) {
          $html .= '</ul>';
          $in_unordered_list = false;
        }
      }
    }

    // Close any open lists
    if ($in_unordered_list) {
      $html .= '</ul>';
    }
    if ($in_ordered_list) {
      $html .= '</ol>';
    }

    return $html;
  }
}
