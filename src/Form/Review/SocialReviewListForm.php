<?php

namespace Drupal\rep\Form\Review;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rep\Vocabulary\VSTOI;

class SocialReviewListForm extends FormBase {

  public function getFormId() {
    return 'rep_social_review_list_form';
  }

  private function isAllowedElementType($elementtype): bool {
    return in_array((string) $elementtype, ['person', 'organization'], TRUE);
  }

  private function pluralTitle(string $elementtype): string {
    return $elementtype === 'person' ? 'People' : 'Organizations';
  }

  public function ajaxReloadTable(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['element_table_wrapper'];
  }

  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = 'person', $page = 1, $pagesize = 9) {
    $elementtype = (string) $elementtype;
    $page = max(1, (int) $page);
    $pagesize = max(1, (int) $pagesize);

    if (!$this->isAllowedElementType($elementtype)) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Unsupported element type.'),
      ];
      return $form;
    }

    $api = \Drupal::service('rep.api_connector');

    $name_filter = $form_state->getValue('name_filter');
    if ($name_filter === NULL) {
      $name_filter = (string) \Drupal::request()->query->get('name_filter', '');
    }
    $name_filter = trim((string) $name_filter);
    $query = $name_filter !== '' ? ['name_filter' => $name_filter] : [];

    $total_all = (int) $api->parseTotalResponse(
      $api->listSizeByReviewStatus($elementtype, VSTOI::UNDER_REVIEW),
      'listSizeByReviewStatus'
    );

    $items = [];
    $total = $total_all;
    if ($name_filter === '') {
      $total_pages = $total_all > 0 ? (int) ceil($total_all / $pagesize) : 1;
      $page = min($page, $total_pages);
      $offset = ($page - 1) * $pagesize;

      $items = $api->parseObjectResponse(
        $api->listByReviewStatus($elementtype, VSTOI::UNDER_REVIEW, $pagesize, $offset),
        'listByReviewStatus'
      );
      if (!is_array($items)) {
        $items = [];
      }
    }
    else {
      $all_items = [];
      $batch_size = 200;
      $fetch_offset = 0;

      while ($fetch_offset < $total_all) {
        $limit = min($batch_size, max(0, $total_all - $fetch_offset));
        if ($limit <= 0) {
          break;
        }

        $batch = $api->parseObjectResponse(
          $api->listByReviewStatus($elementtype, VSTOI::UNDER_REVIEW, $limit, $fetch_offset),
          'listByReviewStatus'
        );

        if (!is_array($batch) || $batch === []) {
          break;
        }

        $all_items = array_merge($all_items, $batch);
        if (count($batch) < $limit) {
          break;
        }
        $fetch_offset += $limit;
      }

      $filter_cmp = function_exists('mb_strtolower') ? mb_strtolower($name_filter) : strtolower($name_filter);
      $filtered = [];
      foreach ($all_items as $item) {
        if (is_array($item)) {
          $item = (object) $item;
        }
        if (!is_object($item)) {
          continue;
        }

        $uri = (string) ($item->uri ?? '');
        if ($uri === '') {
          continue;
        }

        $label = (string) ($item->label ?? ($item->name ?? $uri));
        $label_cmp = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
        if (str_contains($label_cmp, $filter_cmp)) {
          $filtered[] = $item;
          continue;
        }

        $uri_cmp = function_exists('mb_strtolower') ? mb_strtolower($uri) : strtolower($uri);
        if (str_contains($uri_cmp, $filter_cmp)) {
          $filtered[] = $item;
        }
      }

      $total = count($filtered);
      $total_pages = $total > 0 ? (int) ceil($total / $pagesize) : 1;
      $page = min($page, $total_pages);
      $offset = ($page - 1) * $pagesize;
      $items = array_slice($filtered, $offset, $pagesize);
    }

    $form['title'] = [
      '#type' => 'item',
      '#markup' => '<h3 class="mt-4">' . $this->t('Manage @title Reviews', ['@title' => $this->pluralTitle($elementtype)]) . '</h3>',
    ];

    $form['elementtype'] = [
      '#type' => 'hidden',
      '#value' => $elementtype,
    ];

    // Actions + filters (always before the table for UI consistency).
    $form['actions_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'align-items-center', 'justify-content-between', 'mb-0'],
        'style' => 'margin-bottom:0!important;',
      ],
    ];

    $form['actions_wrapper']['buttons_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'gap-2', 'flex-nowrap'],
        'style' => 'flex-wrap:nowrap;overflow-x:auto;',
      ],
    ];

    $form['actions_wrapper']['buttons_container']['review_selected'] = [
      '#type' => 'submit',
      '#value' => $this->t('Review Selected'),
      '#name' => 'review_selected',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'edit-element-button'],
      ],
    ];

    $form['actions_wrapper']['filter_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'ms-auto', 'mb-0'],
        'style' => 'margin-bottom:0!important;',
      ],
    ];

    $form['actions_wrapper']['filter_container']['filter_label'] = [
      '#type' => 'label',
      '#title' => $this->t('Filter(s): '),
      '#attributes' => [
        'class' => ['pt-3', 'me-2', 'fw-bold'],
      ],
    ];

    $form['actions_wrapper']['filter_container']['name_filter'] = [
      '#type' => 'textfield',
      '#default_value' => $name_filter,
      '#ajax' => [
        'callback' => '::ajaxReloadTable',
        'wrapper' => 'element-table-wrapper',
        'event' => 'change',
      ],
      '#attributes' => [
        'class' => ['form-select', 'w-auto', 'mt-2', 'me-1'],
        'style' => 'max-width:230px;margin-bottom:0!important;float:right;',
        'placeholder' => (string) $this->t('Type in your search criteria'),
        'onkeydown' => 'if (event.keyCode == 13) { event.preventDefault(); this.blur(); }',
      ],
    ];

    $header = [
      'label' => $this->t('Label'),
      'uri' => $this->t('URI'),
    ];

    $options = [];
    foreach ($items as $item) {
      if (is_array($item)) {
        $item = (object) $item;
      }
      if (!is_object($item)) {
        continue;
      }

      $uri = (string) ($item->uri ?? '');
      if ($uri === '') {
        continue;
      }

      $label = (string) ($item->label ?? ($item->name ?? $uri));

      $options[$uri] = [
        'label' => $label,
        'uri' => $uri,
      ];
    }

    $form['element_table_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'element-table-wrapper'],
    ];

    $form['element_table_wrapper']['element_table'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $options,
      '#js_select' => FALSE,
      '#empty' => $this->t('No items are currently Under Review.'),
    ];

    $previous_link = $page > 1
      ? Url::fromRoute('rep.review_social_select', [
        'elementtype' => $elementtype,
        'page' => $page - 1,
        'pagesize' => $pagesize,
      ], ['query' => $query])->toString()
      : '';

    $next_link = $page < $total_pages
      ? Url::fromRoute('rep.review_social_select', [
        'elementtype' => $elementtype,
        'page' => $page + 1,
        'pagesize' => $pagesize,
      ], ['query' => $query])->toString()
      : '';

    $form['element_table_wrapper']['pager'] = [
      '#theme' => 'list-page',
      '#items' => [
        'page' => (string) $page,
        'first' => Url::fromRoute('rep.review_social_select', [
          'elementtype' => $elementtype,
          'page' => 1,
          'pagesize' => $pagesize,
        ], ['query' => $query])->toString(),
        'last' => Url::fromRoute('rep.review_social_select', [
          'elementtype' => $elementtype,
          'page' => $total_pages,
          'pagesize' => $pagesize,
        ], ['query' => $query])->toString(),
        'previous' => $previous_link,
        'next' => $next_link,
        'last_page' => (string) $total_pages,
        'links' => NULL,
        'title' => ' ',
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $button_name = (string) ($trigger['#name'] ?? '');

    if ($button_name !== 'review_selected') {
      return;
    }

    $selected_rows = $form_state->getValue('element_table') ?? [];
    $rows = [];
    foreach ($selected_rows as $key => $selected) {
      if ($selected) {
        $rows[$key] = $key;
      }
    }

    if (count($rows) < 1) {
      \Drupal::messenger()->addWarning($this->t('Select exactly one item to review.'));
      return;
    }
    if (count($rows) > 1) {
      \Drupal::messenger()->addWarning($this->t('Select only one item at a time to review.'));
      return;
    }

    $uri = (string) array_key_first($rows);
    $elementtype = (string) $form_state->getValue('elementtype');
    if (!$this->isAllowedElementType($elementtype)) {
      $elementtype = 'person';
    }

    $form_state->setRedirect('rep.review_social_element', [
      'elementtype' => $elementtype,
      'elementuri' => base64_encode($uri),
    ]);
  }

}
