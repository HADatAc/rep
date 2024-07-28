<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\HtmlTag;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;
use Drupal\file\Entity\File;
use Drupal\rep\ListManagerEmailPage;
use Drupal\rep\Utils;
use Drupal\rep\Entity\MetadataTemplate;

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
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype=NULL, $mode = NULL, $page=NULL, $pagesize=NULL, $studyuri=NULL) {

    // OPTIONAL STUDYURI
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
      case "dsg":
        $this->single_class_name = "DSG";
        $this->plural_class_name = "DSGs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('dsg',$this->getList());    
        break;
      case "ins":
        $this->single_class_name = "INS";
        $this->plural_class_name = "INSs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('ins',$this->getList());    
        break;
      case "da":
        $this->single_class_name = "DA";
        $this->plural_class_name = "DAs";
        $header = MetadataTemplate::generateHeader();
        if ($mode == 'table') {
          $output = MetadataTemplate::generateOutput('da',$this->getList());    
        } else {
          $output = MetadataTemplate::generateOutput('da',$this->getList());    
        }
        break;
      case "dd":
        $this->single_class_name = "DD";
        $this->plural_class_name = "DDs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('dd',$this->getList());    
        break;
      case "sdd":
        $this->single_class_name = "SDD";
        $this->plural_class_name = "SDDs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('sdd',$this->getList());    
        break;
      default:
        $this->single_class_name = "Object of Unknown Type";
        $this->plural_class_name = "Objects of Unknown Types";
    }

    // PUT FORM TOGETHER
    $form['page_title'] = [
        '#type' => 'item',
        '#title' => $this->t('<h3>Manage ' . $this->plural_class_name . '</h3>'),
    ];
    $form['page_subtitle'] = [
        '#type' => 'item',
        '#title' => $this->t('<h4>' . $this->plural_class_name . ' maintained by <font color="DarkGreen">' . $this->manager_name . ' (' . $this->manager_email . ')</font></h4>'),
    ];
    $form['add_element'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add New ' . $this->single_class_name),
      '#name' => 'add_element',
    ];
    if ($mode == 'table') {
      $form['edit_selected_element'] = [
          '#type' => 'submit',
          '#value' => $this->t('Edit Selected ' . $this->single_class_name),
          '#name' => 'edit_element',
      ];
      $form['delete_selected_element'] = [
          '#type' => 'submit',
          '#value' => $this->t('Delete Selected ' . $this->plural_class_name),
          '#name' => 'delete_element',
          '#attributes' => ['onclick' => 'if(!confirm("Really Delete?")){return false;}'],
        ];
      $form['ingest_mt'] = [
          '#type' => 'submit',
          '#value' => $this->t('Ingest Selected ' . $this->single_class_name),
          '#name' => 'ingest_mt',
          '#attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode(['width' => 700, 'height' => 400]),
          ],  
      ];
      $form['uningest_mt'] = [
          '#type' => 'submit',
          '#value' => $this->t('Uningest Selected ' . $this->plural_class_name),
          '#name' => 'uningest_mt',
      ];  
      $form['space1'] = [
        '#type' => 'item',
        '#value' => $this->t('<br>'),
      ];
    }

    //if ($mode == 'card') {

      // Add elements as table
      $form['element_table'] = [
        '#type' => 'tableselect',
        '#header' => $header,
        '#options' => $output,
        '#js_select' => FALSE,
        '#empty' => t('No ' . $this->plural_class_name . ' found'),
      ];

    /*
    } else {

      // Loop through $output and creates two cards per row
      $index = 0;
      foreach (array_chunk($output, 2, true) as $row) {
          $index++;
          $form['row_' . $index] = [
              '#type' => 'container',
              '#attributes' => [
                  'class' => ['row', 'mb-3'],
              ],
          ];
          $indexCard = 0;
          foreach ($row as $uri => $card) {
              $indexCard++;
              $form['row_' . $index]['element_' . $indexCard] = $card;     
          }
      }

    }*/

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
    ];
    $form['space2'] = [
        '#type' => 'item',
        '#value' => $this->t('<br><br><br>'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */   
  public function submitForm(array &$form, FormStateInterface $form_state) {
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

    // EDIT ELEMENT
    if ($button_name === 'edit_element') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Select the exact " . $this->single_class_name . " to be edited."));      
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("No more than one " . $this->single_class_name . " can be edited at once."));      
      } else {
        $first = array_shift($rows);
        $uid = \Drupal::currentUser()->id();
        $previousUrl = \Drupal::request()->getRequestUri();
        Utils::trackingStoreUrls($uid, $previousUrl, 'rep.edit_mt');
          $url = Url::fromRoute('rep.edit_mt', [
          'elementtype' => $this->element_type, 
          'elementuri' => base64_encode($first), 
          'fixstd' => 'F',
        ]);
        $form_state->setRedirectUrl($url);
      } 
    }

    // DELETE ELEMENT
    if ($button_name === 'delete_element') {
      if (sizeof($rows) <= 0) {
        \Drupal::messenger()->addWarning(t("At least one " . $this->single_class_name . " needs to be selected to be deleted."));      
      } else {
        $api = \Drupal::service('rep.api_connector');
        foreach($rows as $uri) {
          $mt = $api->parseObjectResponse($api->getUri($uri),'getUri');
          if ($mt != NULL && $mt->hasDataFile != NULL) {

            // DELETE FILE
            if (isset($mt->hasDataFile->id)) {
              $file = File::load($mt->hasDataFile->id);
              if ($file) {
                $file->delete();
                \Drupal::messenger()->addMessage(t("Deleted file with following ID: ".$mt->hasDataFile->id));      
              }  
            }

            // DELETE DATAFILE
            if (isset($mt->hasDataFile->uri)) {
              $api->dataFileDel($mt->hasDataFile->uri);
              \Drupal::messenger()->addMessage(t("Deleted DataFile with following URI: ".$mt->hasDataFile->uri));      
            }
          }
        }
        \Drupal::messenger()->addMessage(t("Selected " . $this->plural_class_name . " has/have been deleted successfully."));      
      }
    }  

    // INGEST MT
    if ($button_name === 'ingest_mt') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Select the exact " . $this->single_class_name . " to be ingested."));      
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("No more than one " . $this->single_class_name . " can be ingested at once."));      
      } else {
        $api = \Drupal::service('rep.api_connector');
        $first = array_shift($rows);
        $study = $api->parseObjectResponse($api->getUri($first),'getUri');
        if ($study == NULL) {
          \Drupal::messenger()->addError(t("Failed to retrieve datafile to be ingested."));
          $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
          return;
        } 
        $msg = $api->parseObjectResponse($api->uploadTemplate($this->element_type, $study),'uploadTemplate');
        if ($msg == NULL) {
          \Drupal::messenger()->addError(t("Selected " . $this->single_class_name . " FAILED to be submitted for ingestion."));      
          $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
          return;
        }
        \Drupal::messenger()->addMessage(t("Selected " . $this->single_class_name . " has been submitted for ingestion."));      
        $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
        return;
      }
    }  

    // UNINGEST MT
    if ($button_name === 'uningest_mt') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Select the exact " . $this->single_class_name . " to be uningested."));      
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("No more than one " . $this->single_class_name . " can be uningested at once."));      
      } else {
        $api = \Drupal::service('rep.api_connector');
        $first = array_shift($rows);
        $newMT = new MetadataTemplate();
        $mt = $api->parseObjectResponse($api->getUri($first),'getUri');
        if ($mt == NULL) {
          \Drupal::messenger()->addError(t("Failed to retrieve " . $this->single_class_name . " to be uningested."));
          return;
        } 
        $newMT->setPreservedMT($mt);
        $df = $api->parseObjectResponse($api->getUri($mt->hasDataFileUri),'getUri');
        if ($df == NULL) {
          \Drupal::messenger()->addError(t("Failed to retrieve " . $this->single_class_name . "'s datafile to be uningested."));
          return;
        } 
        $newMT->setPreservedDF($df);
        $msg = $api->parseObjectResponse($api->uningestMT($mt->uri),'uningestMT');
        if ($msg == NULL) {
          \Drupal::messenger()->addError(t("Selected " . $this->single_class_name . " FAILED to be uningested."));      
          return;
        }
        $newMT->savePreservedMT($this->element_type);
        \Drupal::messenger()->addMessage(t("Selected " . $this->single_class_name . " has been uningested."));      
        return;
      }
    }  
    
    // BACK TO MAIN PAGE
    if ($button_name === 'back') {
      $url = Url::fromRoute('std.search');
      $form_state->setRedirectUrl($url);
    }  

  }

  /**
   * {@inheritdoc}
   */   
  public static function backSelect($elementType, $mode, $studyuri) {
    $url = Url::fromRoute('rep.select_mt_element');
    $url->setRouteParameter('elementtype', $elementType);
    $url->setRouteParameter('mode', $mode);
    $url->setRouteParameter('page', 0);
    $url->setRouteParameter('pagesize', 12);
    if ($studyuri == NULL || $studyuri != '' || $studyuri == ' ') { 
      $url->setRouteParameter('studyuri', 'none');
    } else {
      $url->setRouteParameter('studyuri', $studyuri);
    }
    return $url;
  }
  
}