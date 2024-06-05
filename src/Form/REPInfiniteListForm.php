<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rep\ListKeywordPage;
use Drupal\rep\Entity\DataFile;
use Drupal\rep\Entity\Organization;
use Drupal\rep\Entity\Person;

class REPInfiniteListForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_infinit_list_form';
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
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = NULL, $keyword = NULL, $page = 1, $pagesize = 10) {

    // Get total number of elements
    $this->setListSize(-1);
    if ($elementtype != NULL) {
      $this->setListSize(ListKeywordPage::total($elementtype, $keyword));
    }

    // Retrieve elements
    $this->setList(ListKeywordPage::exec($elementtype, $keyword, $page, $pagesize));

    $class_name = "";
    $header = array();
    $output = array();    
    switch ($elementtype) {

      // DataFile
      case "datafile":
        $class_name = "DataFile";
        $header = DataFile::generateHeader();
        $output = DataFile::generateOutput($this->getList());    
        break;

      default:
        $class_name = "Objects of Unknown Types";
    }

    // Add AJAX wrapper
    $form['element_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $output,
      '#empty' => t('No response options found'),
      '#attributes' => ['id' => 'element-table'],
    ];

    // Add load more button
    if ($page * $pagesize < $this->list_size) {
      $form['load_more'] = [
        '#type' => 'button',
        '#value' => t('Load more'),
        '#ajax' => [
          'callback' => '::loadMoreCallback',
          'wrapper' => 'element-table',
          'effect' => 'fade',
        ],
        '#attributes' => [
          'data-page' => $page,
          'data-elementtype' => $elementtype,
          'data-keyword' => $keyword,
        ],
      ];
    }

    // Attach custom JS
    $form['#attached']['library'][] = 'rep/infinitescroll';

    return $form;
  }

  /**
   * AJAX callback to load more items.
   */
  public function loadMoreCallback(array &$form, FormStateInterface $form_state) {
    $page = $form_state->getTriggeringElement()['#attributes']['data-page'] + 1;
    $elementtype = $form_state->getTriggeringElement()['#attributes']['data-elementtype'];
    $keyword = $form_state->getTriggeringElement()['#attributes']['data-keyword'];

    // Rebuild the form with updated page number
    return $this->buildForm([], $form_state, $elementtype, $keyword, $page);
  }

  /**
   * {@inheritdoc}
   */   
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }
}
