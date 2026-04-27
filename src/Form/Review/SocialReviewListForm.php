<?php

namespace Drupal\rep\Form\Review;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
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

    $total = (int) $api->parseTotalResponse(
      $api->listSizeByReviewStatus($elementtype, VSTOI::UNDER_REVIEW),
      'listSizeByReviewStatus'
    );

    $total_pages = $total > 0 ? (int) ceil($total / $pagesize) : 1;
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $pagesize;

    $items = $api->parseObjectResponse(
      $api->listByReviewStatus($elementtype, VSTOI::UNDER_REVIEW, $pagesize, $offset),
      'listByReviewStatus'
    );

    if (!is_array($items)) {
      $items = [];
    }

    $form['title'] = [
      '#type' => 'item',
      '#markup' => '<h3 class="mt-4">' . $this->t('Manage @title Reviews', ['@title' => $this->pluralTitle($elementtype)]) . '</h3>',
    ];

    $form['elementtype'] = [
      '#type' => 'hidden',
      '#value' => $elementtype,
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

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['review_selected'] = [
      '#type' => 'submit',
      '#value' => $this->t('Review Selected'),
      '#name' => 'review_selected',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'edit-element-button'],
      ],
    ];

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
      ])->toString()
      : '';

    $next_link = $page < $total_pages
      ? Url::fromRoute('rep.review_social_select', [
        'elementtype' => $elementtype,
        'page' => $page + 1,
        'pagesize' => $pagesize,
      ])->toString()
      : '';

    $form['element_table_wrapper']['pager'] = [
      '#theme' => 'list-page',
      '#items' => [
        'page' => (string) $page,
        'first' => Url::fromRoute('rep.review_social_select', [
          'elementtype' => $elementtype,
          'page' => 1,
          'pagesize' => $pagesize,
        ])->toString(),
        'last' => Url::fromRoute('rep.review_social_select', [
          'elementtype' => $elementtype,
          'page' => $total_pages,
          'pagesize' => $pagesize,
        ])->toString(),
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
