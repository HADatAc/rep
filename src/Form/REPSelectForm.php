<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rep\ListManagerEmailPage;
use Drupal\rep\Entity\DataFile;
use Drupal\file\Entity\File;

class REPSelectForm extends FormBase
{

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

    // GET TOTAL NUMBER OF ELEMENTS AND TOTAL NUMBER OF PAGES
    $this->element_type = $elementtype;
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
        $total_pages = floor($this->list_size / $pagesize) + 1;
      }
    }

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
    $this->setList(ListManagerEmailPage::exec($this->element_type, $this->manager_email, $page, $pagesize));

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
    $form['delete_selected_element'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete Selected ' . $this->plural_class_name),
      '#name' => 'delete_element',
      '#attributes' => [
        'onclick' => 'if(!confirm("Really Delete?")){return false;}',
        'class' => ['btn', 'btn-primary', 'delete-element-button']
      ],
    ];
    //$form['my_tableselect_wrapper'] = array(
    //  '#type' => 'container',
    //  '#attributes' => array('class' => array('my-tableselect-wrapper')),
    //);
    //$form['my_tableselect_wrapper']['element_table'] = [
    $form['element_table'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $output,
      '#js_select' => FALSE,
      '#empty' => t('No ' . $this->plural_class_name . ' found'),
    ];
    $form['pager'] = [
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
