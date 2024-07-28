<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\ListKeywordPage;
use Drupal\rep\Entity\DataFile;
use Drupal\rep\Entity\Organization;
use Drupal\rep\Entity\Person;

class REPListForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_list_form';
  }

  protected $list;

  protected $list_size;

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
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype=NULL, $keyword=NULL, $page=NULL, $pagesize=NULL) {

    // GET TOTAL NUMBER OF ELEMENTS AND TOTAL NUMBER OF PAGES
    $this->setListSize(-1);
    if ($elementtype != NULL) {
      $this->setListSize(ListKeywordPage::total($elementtype, $keyword));
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
      $next_page_link = ListKeywordPage::link($elementtype, $keyword, $next_page, $pagesize);
    } else {
      $next_page_link = '';
    }
    if ($page > 1) {
      $previous_page = $page - 1;
      $previous_page_link = ListKeywordPage::link($elementtype, $keyword, $previous_page, $pagesize);
    } else {
      $previous_page_link = '';
    }

    // RETRIEVE ELEMENTS
    $this->setList(ListKeywordPage::exec($elementtype, $keyword, $page, $pagesize));

    $class_name = "";
    $header = array();
    $output = array();    
    switch ($elementtype) {

      // DATAFILE
      case "datafile":
        $class_name = "DataFile";
        $header = DataFile::generateHeader();
        $output = DataFile::generateOutput($this->getList());    
        break;
  
      default:
        $class_name = "Objects of Unknown Types";
    }

    // PUT FORM TOGETHER
    $form['element_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $output,
      '#empty' => t('No response options found'),
    ];

    $form['pager'] = [
      '#theme' => 'list-page',
      '#items' => [
        'page' => strval($page),
        'first' => ListKeywordPage::link($elementtype, $keyword, 1, $pagesize),
        'last' => ListKeywordPage::link($elementtype, $keyword, $total_pages, $pagesize),
        'previous' => $previous_page_link,
        'next' => $next_page_link,
        'last_page' => strval($total_pages),
        'links' => null,
        'title' => ' ',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */   
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}