<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rep\ListManagerEmailPage;
use Drupal\rep\ManageOwnerFilter;
use Drupal\rep\Entity\DataFile;
use Drupal\file\Entity\File;
use Drupal\rep\Vocabulary\VSTOI;

class REPSelectForm extends FormBase
{

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
  public function getFormId()
  {
    return 'rep_select_form';
  }

  public $element_type;

  public $manager_email;

  public $manager_name;

  public $single_class_name;

  public $plural_class_name;

  protected $list;

  protected $list_size;

  public function getList()
  {
    return $this->list;
  }

  public function setList($list)
  {
    return $this->list = $list;
  }

  public function getListSize()
  {
    return $this->list_size;
  }

  public function setListSize($list_size)
  {
    return $this->list_size = $list_size;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = NULL, $page = NULL, $pagesize = NULL)
  {

    // GET MANAGER EMAIL
    $this->manager_email = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $this->manager_name = $user->name->value;
    $this->element_type = $elementtype;

    // Persist filter state in session
    $session = \Drupal::request()->getSession();
    $status_filter = $form_state->getValue('status_filter');
    if ($status_filter === NULL) {
      $status_filter = $session->get('rep_select_status_filter', '_');
    }
    else {
      $session->set('rep_select_status_filter', $status_filter);
    }
    $status_filter = $this->normalizeStatusFilter($status_filter);
    $session->set('rep_select_status_filter', $status_filter);

    $is_admin = ManageOwnerFilter::isAdmin();
    $manager_filter_key = 'rep_select_manager_filter.' . (string) $this->element_type;
    $manager_filter = $form_state->getValue('manager_filter');
    if ($manager_filter === NULL) {
      $manager_filter = $session->get($manager_filter_key, '');
    }
    else {
      $manager_filter = ManageOwnerFilter::normalizeSelectedEmail($manager_filter);
      $session->set($manager_filter_key, $manager_filter);
    }

    $effective_manager_email = ManageOwnerFilter::resolveEffectiveOwner($this->manager_email, $manager_filter, $status_filter);

    // GET TOTAL NUMBER OF ELEMENTS AND TOTAL NUMBER OF PAGES
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
      $next_page_link = ListManagerEmailPage::link($this->element_type, $next_page, $pagesize);
    } else {
      $next_page_link = '';
    }
    if ($page > 1) {
      $previous_page = $page - 1;
      $previous_page_link = ListManagerEmailPage::link($this->element_type, $previous_page, $pagesize);
    } else {
      $previous_page_link = '';
    }

    // RETRIEVE ELEMENTS
    if ($status_filter === '_' || $status_filter === NULL || $status_filter === '') {
      $this->setList(ListManagerEmailPage::exec($this->element_type, $effective_manager_email, $page, $pagesize));
    }
    else {
      $this->setList(ListManagerEmailPage::execByStatusManagerEmail($this->element_type, $status_filter, $effective_manager_email, FALSE, $page, $pagesize));
    }

    $this->single_class_name = "";
    $this->plural_class_name = "";
    switch ($this->element_type) {

        // ELEMENTS
      case "datafile":
        $this->single_class_name = "Data File";
        $this->plural_class_name = "Data Files";
        $header = DataFile::generateHeader();
        $output = DataFile::generateOutput($this->getList());
        break;
      default:
        $this->single_class_name = "Object of Unknown Type";
        $this->plural_class_name = "Objects of Unknown Types";
    }

    // PUT FORM TOGETHER
    //$form['#attached']['library'][] = 'rep/scrollable_table';
    $form['page_title'] = [
      '#type' => 'item',
      '#title' => $this->t('<h3 class="mt-5">Manage ' . $this->plural_class_name . '</h3>'),
    ];
    $form['page_subtitle'] = [
      '#type' => 'item',
      '#title' => $this->t('<h4>' . $this->plural_class_name . ' maintained by <font color="DarkGreen">' . $this->manager_name . ' (' . $this->manager_email . ')</font></h4>'),
    ];

    $show_owner_indicator = $is_admin && $manager_filter !== '' && strcasecmp($effective_manager_email, $manager_filter) === 0;
    if ($show_owner_indicator) {
      $form['owner_indicator'] = [
        '#type' => 'item',
        '#markup' => $this->t('<div class="alert alert-info py-2 mb-3"><strong>A visualizar owner:</strong> @owner</div>', [
          '@owner' => $effective_manager_email,
        ]),
      ];
    }

    $form['actions_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'align-items-center', 'justify-content-between', 'mb-0'],
        'style' => 'margin-bottom:0!important;'
      ],
    ];

    $form['actions_wrapper']['buttons_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'gap-2', 'flex-nowrap'],
        'style' => 'flex-wrap:nowrap;overflow-x:auto;'
      ],
    ];

    $form['actions_wrapper']['buttons_container']['delete_selected_element'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete Selected ' . $this->plural_class_name),
      '#name' => 'delete_element',
      '#attributes' => [
        'onclick' => 'if(!confirm("Really Delete?")){return false;}',
        'class' => ['btn', 'btn-primary', 'delete-element-button']
      ],
    ];

    $status_options = [
      '_' => $this->t('All Status'),
      VSTOI::DRAFT => $this->t('Draft'),
      VSTOI::UNDER_REVIEW => $this->t('Under Review'),
      VSTOI::CURRENT => $this->t('Current'),
      VSTOI::DEPRECATED => $this->t('Deprecated'),
    ];

    $form['actions_wrapper']['filter_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'ms-auto', 'mb-0'],
        'style' => 'margin-bottom:0!important;'
      ],
    ];

    $form['actions_wrapper']['filter_container']['filter_label'] = [
      '#type' => 'label',
      '#title' => $this->t('Filter(s): '),
      '#attributes' => [
        'class' => ['pt-3', 'me-2', 'fw-bold'],
      ],
    ];

    if ($is_admin) {
      $form['actions_wrapper']['filter_container']['manager_filter'] = [
        '#type' => 'textfield',
        '#title' => $this->t('User'),
        '#title_display' => 'invisible',
        '#default_value' => $manager_filter,
        '#ajax' => [
          'callback' => '::ajaxReloadTable',
          'wrapper' => 'element-table-wrapper',
          'event' => 'change',
        ],
        '#attributes' => [
          'class' => ['form-control', 'w-auto', 'mt-2', 'me-1'],
          'style' => 'min-width:240px;margin-bottom:0!important;float:right;',
          'placeholder' => $this->t('User email (Draft/Under Review)'),
        ],
      ];
    }

    $form['actions_wrapper']['filter_container']['status_filter'] = [
      '#type' => 'select',
      '#options' => $status_options,
      '#default_value' => $status_filter,
      '#ajax' => [
        'callback' => '::ajaxReloadTable',
        'wrapper' => 'element-table-wrapper',
        'event' => 'change',
      ],
      '#attributes' => [
        'class' => ['form-select', 'w-auto', 'mt-2'],
        'style' => 'margin-bottom:0!important;float:right;'
      ],
    ];
    //$form['my_tableselect_wrapper'] = array(
    //  '#type' => 'container',
    //  '#attributes' => array('class' => array('my-tableselect-wrapper')),
    //);
    //$form['my_tableselect_wrapper']['element_table'] = [
    $form['element_table_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'element-table-wrapper'],
    ];

    $form['element_table_wrapper']['element_table'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $output,
      '#js_select' => FALSE,
      '#empty' => t('No ' . $this->plural_class_name . ' found'),
    ];
    $form['element_table_wrapper']['pager'] = [
      '#theme' => 'list-page',
      '#items' => [
        'page' => strval($page),
        'first' => ListManagerEmailPage::link($this->element_type, 1, $pagesize),
        'last' => ListManagerEmailPage::link($this->element_type, $total_pages, $pagesize),
        'previous' => $previous_page_link,
        'next' => $next_page_link,
        'last_page' => strval($total_pages),
        'links' => null,
        'title' => ' ',
      ],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'back-button'],
      ],
    ];
    $form['space'] = [
      '#type' => 'item',
      '#value' => $this->t('<br><br><br>'),
    ];

    return $form;
  }

  /**
   * AJAX callback to reload list when filters change.
   */
  public function ajaxReloadTable(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['element_table_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // RETRIEVE TRIGGERING BUTTON
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    // RETRIEVE SELECTED ROWS, IF ANY
    $selected_rows = $form_state->getValue('element_table');
    $rows = [];
    foreach ($selected_rows as $index => $selected) {
      if ($selected) {
        $rows[$index] = $index;
      }
    }

    // ADD ELEMENT
    if ($button_name === 'add_element') {
      //if ($this->element_type == 'organization') {
      //  $url = Url::fromRoute('rep.add_organization');
      //}
      //$form_state->setRedirectUrl($url);
    }

    // EDIT ELEMENT
    if ($button_name === 'edit_element') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addMessage(t("Select the exact " . $this->single_class_name . " to be edited."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addMessage(t("No more than one " . $this->single_class_name . " can be edited at once."));
      } else {
        $first = array_shift($rows);
        //if ($this->element_type == 'organization') {
        //  $url = Url::fromRoute('rep.edit_organization', ['organizationuri' => base64_encode($first)]);
        //}
        $form_state->setRedirectUrl($url);
      }
    }

    // DELETE ELEMENT
    // if ($button_name === 'delete_element') {
    //   if (sizeof($rows) <= 0) {
    //     \Drupal::messenger()->addMessage(t("At least one " . $this->single_class_name . " needs to be selected to be deleted."));
    //   } else {
    //     $api = \Drupal::service('rep.api_connector');
    //     $success = TRUE;
    //     foreach ($rows as $uri) {
    //       if ($this->element_type == 'datafile') {
    //         $resp = $api->parseObjectResponse($api->datafileDel($uri), 'datafileDel');
    //         if ($resp == NULL) {
    //           \Drupal::messenger()->addMessage(t("Failed to delete the following " . $this->$single_class_name . ": " . $uri));
    //           $success = FALSE;
    //         }
    //       }
    //     }
    //     if ($success) {
    //       \Drupal::messenger()->addMessage(t("Selected " . $this->plural_class_name . " has/have been deleted successfully."));
    //     }
    //   }
    // }
    if ($button_name === 'delete_element') {
      if (sizeof($rows) <= 0) {
          \Drupal::messenger()->addMessage(t("At least one " . $this->single_class_name . " needs to be selected to be deleted."));
      } else {
          $api = \Drupal::service('rep.api_connector');
          $file_system = \Drupal::service('file_system');
          $logger = \Drupal::logger('REP'); // Logger for debugging
          $success = TRUE;

          foreach ($rows as $uri) {
              if ($this->element_type == 'datafile') {
                  // $logger->info("Attempting to delete file with URI: " . $uri);
                  $file = $api->parseObjectResponse($api->getUri($uri), 'getUri');
                  $resp = $api->parseObjectResponse($api->datafileDel($uri), 'datafileDel');

                  if ($resp == NULL) {
                      \Drupal::messenger()->addMessage(t("Failed to delete the following " . $this->single_class_name . ": " . $uri));
                      $logger->error("API response failed for URI: " . $uri);
                      $success = FALSE;
                  } else {
                      // 1. Fetch file ID from the database
                      $fid = \Drupal::database()->select('file_managed', 'fm')
                          ->fields('fm', ['fid'])
                          ->condition('fid', $file->id)
                          ->execute()
                          ->fetchField();

                      if ($fid) {
                          // $logger->info("File found in database with FID: " . $fid);
                          $file = File::load($fid);
                          if ($file) {
                              // 2. Remove file usage references
                              \Drupal::service('file.usage')->delete($file, 'custom_module', 'entity_type', $file->id());

                              // 3. Get the real file path and check if it exists
                              $file_path = $file->getFileUri();
                              $real_path = $file_system->realpath($file_path);

                              if ($real_path && file_exists($real_path)) {
                                  // $logger->info("File exists at: " . $real_path . " - Proceeding to delete.");
                                  if ($file_system->delete($file_path)) {
                                      // $logger->info("File successfully deleted from filesystem: " . $file_path);
                                  } else {
                                      // $logger->error("Failed to delete file from filesystem: " . $file_path);
                                      \Drupal::messenger()->addError(t("Failed to delete file physically: " . $file_path));
                                  }
                              } else {
                                  $logger->warning("File not found on filesystem: " . $file_path);
                              }

                              // 4. Delete file from database
                              $file->delete();
                              $deleted = \Drupal::database()->delete('file_managed')
                                  ->condition('fid', $file->id())
                                  ->execute();

                              if (!$deleted) {
                                  $logger->error("Failed to remove file entry from database for FID: " . $file->id());
                              }

                              \Drupal::messenger()->addMessage(t("File with URI " . $uri . " deleted."));
                          } else {
                              $logger->warning("File entity could not be loaded for FID: " . $fid);
                          }
                      } else {
                          \Drupal::messenger()->addWarning(t("File not found in database: " . $uri));
                      }
                  }
              }
          }

          if ($success) {
              \Drupal::messenger()->addMessage(t("Selected " . $this->plural_class_name . " has/have been deleted successfully."));
          }

          // 5. Clear cache to ensure updated data
          \Drupal::service('cache.default')->invalidateAll();
      }
    }

    // BACK TO MAIN PAGE
    if ($button_name === 'back') {
      $url = Url::fromRoute('rep.home');
      $form_state->setRedirectUrl($url);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function backSelect($elementType)
  {
    $url = Url::fromRoute('rep.select_element');
    $url->setRouteParameter('elementtype', $elementType);
    $url->setRouteParameter('page', 0);
    $url->setRouteParameter('pagesize', 12);
    return $url;
  }
}
