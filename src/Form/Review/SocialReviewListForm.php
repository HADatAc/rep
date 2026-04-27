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

    $header = [
      $this->t('Label'),
      $this->t('URI'),
      $this->t('Actions'),
    ];

    $rows = [];
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

      $review_url = Url::fromRoute('rep.review_social_element', [
        'elementtype' => $elementtype,
        'elementuri' => base64_encode($uri),
      ]);

      $rows[] = [
        'data' => [
          $label,
          $uri,
          Link::fromTextAndUrl($this->t('Review'), $review_url)->toRenderable(),
        ],
      ];
    }

    $form['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No items are currently Under Review.'),
    ];

    $nav = [];
    if ($page > 1) {
      $nav[] = Link::fromTextAndUrl($this->t('Previous'), Url::fromRoute('rep.review_social_select', [
        'elementtype' => $elementtype,
        'page' => $page - 1,
        'pagesize' => $pagesize,
      ]))->toString();
    }

    $nav[] = $this->t('Page @p of @t', ['@p' => $page, '@t' => $total_pages]);

    if ($page < $total_pages) {
      $nav[] = Link::fromTextAndUrl($this->t('Next'), Url::fromRoute('rep.review_social_select', [
        'elementtype' => $elementtype,
        'page' => $page + 1,
        'pagesize' => $pagesize,
      ]))->toString();
    }

    $form['pager'] = [
      '#type' => 'item',
      '#markup' => '<div class="mt-3">' . implode(' | ', $nav) . '</div>',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No submit actions.
  }

}
