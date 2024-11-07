<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;
use Drupal\file\Entity\File;
use Drupal\rep\ListManagerEmailPage;
use Drupal\rep\Utils;
use Drupal\rep\Entity\MetadataTemplate;
use Drupal\Core\Render\Markup;

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
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = NULL, $mode = NULL, $pagesize = NULL, $studyuri = NULL) {
    // STUDYURI OPCIONAL
    if ($studyuri == NULL) {
        $studyuri = "";
    }
    $this->studyuri = $studyuri;

    // OBTÉM O MODO
    if ($mode != NULL) {
        $this->setMode($mode);
    }

    // OBTÉM O EMAIL DO GERENTE
    $this->manager_email = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $this->manager_name = $user->name->value;

    // OBTÉM O TIPO DE ELEMENTO
    $this->element_type = $elementtype;
    if ($this->element_type != NULL) {
        $this->setListSize(ListManagerEmailPage::total($this->element_type, $this->manager_email));
    }

    // Definir o tamanho da página
    $pagesize = $form_state->get('page_size') ?? $pagesize ?? 9;
    $form_state->set('page_size', $pagesize);

    /// Recupera ou define o tipo de visualização
    $session = \Drupal::request()->getSession();
    $view_type = $form_state->get('view_type') ?? $session->get('rep_select_mt_view_type') ?? 'table';
    $form_state->set('view_type', $view_type);

    // Log para depuração
    \Drupal::logger('rep_select_mt_form')->notice('Building Form: page_size @page_size, view_type @view_type', [
        '@page_size' => $pagesize,
        '@view_type' => $view_type,
    ]);

    // Atualiza a lista de elementos com base no valor de page_size
    \Drupal::logger('rep_select_mt_form')->notice('Calling ListManagerEmailPage::exec with parameters: element_type @element_type, manager_email @manager_email, pagesize @pagesize', [
        '@element_type' => $this->element_type,
        '@manager_email' => $this->manager_email,
        '@pagesize' => $pagesize,
    ]);
    $this->setList(ListManagerEmailPage::exec($this->element_type, $this->manager_email, 1, $pagesize));

    // Log para verificar lista retornada
    \Drupal::logger('rep_select_mt_form')->notice('List returned: @list', [
        '@list' => json_encode($this->getList()),
    ]);

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

    // Log para verificar header e output
    // \Drupal::logger('rep_select_mt_form')->notice('Header: @header, Output: @output', [
    //     '@header' => json_encode($header),
    //     '@output' => json_encode($output),
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

    // Adicionar botões de alternância de visualização
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

    // Renderizar saída com base no tipo de visualização
    if ($view_type == 'table') {
        $this->buildTableView($form, $form_state, $header, $output);

        // Adicionar paginação para visualização em tabela
        $form['pager'] = [
            '#type' => 'pager',
        ];
    } elseif ($view_type == 'card') {
        $this->buildCardView($form, $form_state, $header, $output);

        // Mostrar o botão "Carregar Mais" apenas se houver mais elementos
        $total_items = $this->getListSize();
        $current_page_size = $form_state->get('page_size') ?? 9;

        if ($total_items > $current_page_size) {
            $form['load_more'] = [
                '#type' => 'submit',
                '#value' => $this->t('Load More'),
                '#name' => 'load_more',
                '#attributes' => [
                    'class' => ['btn', 'btn-primary', 'load-more-button'],
                    'id' => 'load-more-button', // Adicionando ID para facilitar o clique via JavaScript
                    'style' => 'display: none;', // Escondendo o botão, pois o JavaScript deve acioná-lo automaticamente
                ],
                '#submit' => ['::loadMoreSubmit'],
                '#limit_validation_errors' => [],
            ];

            // Add loading overlay
            $form['loading_overlay'] = [
                '#type' => 'container',
                '#attributes' => [
                    'id' => 'loading-overlay',
                    'class' => ['loading-overlay'],
                    'style' => 'display: none;', // Inicialmente escondido
                ],
                '#markup' => '<div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div>',
            ];

            // Atualiza o estado da lista de acordo com os elementos restantes
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
   * Submit handler for the Load More button.
   */
  public function loadMoreSubmit(array &$form, FormStateInterface $form_state) {
    // Atualiza o tamanho da página para carregar mais itens
    $current_page_size = $form_state->get('page_size') ?? 9;
    $pagesize = $current_page_size + 9; // Soma mais 9 ao tamanho atual
    $form_state->set('page_size', $pagesize);

    // Log para depuração
    \Drupal::logger('rep_select_mt_form')->notice('Load More Triggered: new page_size @page_size', [
        '@page_size' => $pagesize,
    ]);

    // Forces rebuild to load more cards
    $form_state->setRebuild();
  }



  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // RETRIEVE TRIGGERING BUTTON
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    // Se o botão acionado tiver um manipulador de submissão específico, não processar aqui
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
        $this->performIngest($rows, $form_state);
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
   * Build Table View
   */
  protected function buildTableView(array &$form, FormStateInterface $form_state, $header, $output) {
    // Adicionar botões de ação para visualização em tabela
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
    $form['ingest_mt'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ingest ' . $this->single_class_name . ' Selected'),
      '#name' => 'ingest_mt',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'ingest_mt-button'],
      ],
    ];
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
   * Build Cards view with infinite scroll
   */
  protected function buildCardView(array &$form, FormStateInterface $form_state, $header, $output) {

    $form['element_cards_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'element-cards-wrapper', 'class' => ['row', 'mt-3']],
    ];

    foreach ($output as $key => $item) {
      // Gerar uma chave sanitizada para os nomes dos elementos
      $sanitized_key = md5($key);

      $form['element_cards_wrapper'][$sanitized_key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-md-4']],
      ];

      $form['element_cards_wrapper'][$sanitized_key]['card'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['card', 'mb-4']],
      ];

      // Inicializar o texto do cabeçalho
      $header_text = '';

      // Primeiro, extrair o texto do cabeçalho (campo 'Name')
      foreach ($header as $column_key => $column_label) {
        if ($column_label == 'Name') {
          $value = isset($item[$column_key]) ? $item[$column_key] : '';
          $header_text = strip_tags($value);
          break;
        }
      }

      // Adicionar o cabeçalho ao card antes do conteúdo
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

      // Agora, adicionar o conteúdo do card
      $form['element_cards_wrapper'][$sanitized_key]['card']['content'] = [
        '#type' => 'container',
        '#attributes' => [
          'style' => 'margin-bottom:0!important;',
          'class' => ['card-body'],
        ],
      ];

      // Adicionar campos ao conteúdo do card
      foreach ($header as $column_key => $column_label) {
        $value = isset($item[$column_key]) ? $item[$column_key] : '';
        if ($column_label == 'Name') {
          // Já foi tratado no cabeçalho
          continue;
        }

        if ($column_label == 'Status') {
          // Renderizar o valor do Status com HTML permitido
          $value_rendered = [
            '#markup' => $value,
            '#allowed_tags' => ['b', 'font', 'span', 'div', 'strong', 'em'],
          ];
        } else {
          $value_rendered = [
            '#markup' => $value,
          ];
        }

        $form['element_cards_wrapper'][$sanitized_key]['card']['content'][$column_key] = [
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

      // Finalmente, adicionar o rodapé do card (botões de ação)
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

      // Botão Excluir
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
        '#element_uri' => $key
      ];

      // Botão Ingest
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['ingest'] = [
        '#type' => 'submit',
        '#value' => $this->t('Ingest'),
        '#name' => 'ingest_mt_' . $sanitized_key,
        '#attributes' => [
          'class' => ['btn', 'btn-success', 'btn-sm', 'ingest_mt-button'],
        ],
        '#submit' => ['::ingestElementSubmit'],
        '#limit_validation_errors' => [],
        '#element_uri' => $key
      ];

      // Botão Uningest
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['uningest'] = [
        '#type' => 'submit',
        '#value' => $this->t('Uningest'),
        '#name' => 'uningest_mt_' . $sanitized_key,
        '#attributes' => [
          'class' => ['btn', 'btn-warning', 'btn-sm', 'uningest_mt-element-button'],
        ],
        '#submit' => ['::uningestElementSubmit'],
        '#limit_validation_errors' => [],
        '#element_uri' => $key
      ];
    }
  }

  /**
   * Submit handler para alternar para visualização em tabela.
   */
  public function viewTableSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->set('view_type', 'table');
    $session = \Drupal::request()->getSession();
    $session->set('rep_select_mt_view_type', 'table');
    $form_state->setRebuild();
  }

  /**
   * Submit handler para alternar para visualização em cards.
   */
  public function viewCardSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->set('view_type', 'card');
    $session = \Drupal::request()->getSession();
    $session->set('rep_select_mt_view_type', 'card');
    $form_state->setRebuild();
  }

  /**
   * Submit handler para editar um elemento na visualização em cards.
   */
  public function editElementSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performEdit($uri, $form_state);
  }

  /**
   * Submit handler para excluir um elemento na visualização em cards.
   */
  public function deleteElementSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performDelete([$uri], $form_state);
  }

  /**
   * Submit handler para ingestar um elemento na visualização em cards.
   */
  public function ingestElementSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performIngest([$uri], $form_state);
  }

  /**
   * Submit handler para desingestar um elemento na visualização em cards.
   */
  public function uningestElementSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performUningest([$uri], $form_state);
  }

  /**
   * Executa a ação de adicionar.
   */
  protected function performAdd(FormStateInterface $form_state) {
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
   * Executa a ação de editar.
   */
  protected function performEdit($uri, FormStateInterface $form_state) {
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
   * Executa a ação de excluir.
   */
  protected function performDelete(array $uris, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    foreach($uris as $uri) {
      $mt = $api->parseObjectResponse($api->getUri($uri),'getUri');
      if ($mt != NULL && $mt->hasDataFile != NULL) {

        // DELETE FILE
        if (isset($mt->hasDataFile->id)) {
          $file = File::load($mt->hasDataFile->id);
          if ($file) {
            $file->delete();
            \Drupal::messenger()->addMessage(t("Archive with ID ".$mt->hasDataFile->id." deleted."));
          }
        }

        // DELETE DATAFILE
        if (isset($mt->hasDataFile->uri)) {
          $api->dataFileDel($mt->hasDataFile->uri);
          \Drupal::messenger()->addMessage(t("DataFile with URI ".$mt->hasDataFile->uri." deleted."));
        }
      }
    }
    \Drupal::messenger()->addMessage(t("The " . $this->plural_class_name . " selected were deleted successfully."));
    $form_state->setRebuild();
  }

  /**
   * Executa a ação de ingestar.
   */
  protected function performIngest(array $uris, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $uri = reset($uris);
    $study = $api->parseObjectResponse($api->getUri($uri),'getUri');
    if ($study == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve the datafile to be ingested."));
      $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      return;
    }
    $msg = $api->parseObjectResponse($api->uploadTemplate($this->element_type, $study),'uploadTemplate');
    if ($msg == NULL) {
      \Drupal::messenger()->addError(t("The " . $this->single_class_name . " selected FAILD to be submited for Ingestion."));
      $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      return;
    }
    \Drupal::messenger()->addMessage(t("The " . $this->single_class_name . " selected was successfully submited for Ingestion."));
    $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
    return;
  }

  /**
   * Executa a ação de desingestar.
   */
  protected function performUningest(array $uris, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $uri = reset($uris);
    $newMT = new MetadataTemplate();
    $mt = $api->parseObjectResponse($api->getUri($uri),'getUri');
    if ($mt == NULL) {
      \Drupal::messenger()->addError(t("Failed to recover " . $this->single_class_name . " for uningestion."));
      return;
    }
    $newMT->setPreservedMT($mt);
    $df = $api->parseObjectResponse($api->getUri($mt->hasDataFileUri),'getUri');
    if ($df == NULL) {
      \Drupal::messenger()->addError(t("Fail to recover datafile of" . $this->single_class_name . " from being unigested."));
      return;
    }
    $newMT->setPreservedDF($df);
    $msg = $api->parseObjectResponse($api->uningestMT($mt->uri),'uningestMT');
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
  public static function backSelect($elementType, $mode, $studyuri) {
    $url = Url::fromRoute('rep.select_mt_element');
    $url->setRouteParameter('elementtype', $elementType);
    $url->setRouteParameter('mode', $mode);
    $url->setRouteParameter('page', 0);
    $url->setRouteParameter('pagesize', 9); // Ajustado para 9 conforme solicitado
    if ($studyuri == NULL || $studyuri == '' || $studyuri == ' ') {
      $url->setRouteParameter('studyuri', 'none');
    } else {
      $url->setRouteParameter('studyuri', $studyuri);
    }
    return $url;
  }

}
