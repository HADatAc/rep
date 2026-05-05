<?php

namespace Drupal\rep\Form\Review;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\rep\Utils;

class OrganizationSuggestionListForm extends FormBase {

  public function getFormId() {
    return 'rep_org_suggestion_list_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $page = 1, $pagesize = 9) {
    $page = max(1, (int) $page);
    $pagesize = max(1, (int) $pagesize);

    $db = \Drupal::database();

    $total = (int) $db->select('rep_org_edit_suggestions', 's')
      ->countQuery()
      ->execute()
      ->fetchField();

    $total_pages = $total > 0 ? (int) ceil($total / $pagesize) : 1;
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $pagesize;

    $items = $db->select('rep_org_edit_suggestions', 's')
      ->fields('s', ['id', 'organization_uri', 'suggested_by_email', 'created'])
      ->orderBy('created', 'DESC')
      ->range($offset, $pagesize)
      ->execute()
      ->fetchAll();

    $form['title'] = [
      '#type' => 'item',
      '#markup' => '<h3 class="mt-4">' . $this->t('Manage Organization Edit Suggestions') . '</h3>',
    ];

    $header = [
      $this->t('Organization'),
      $this->t('Suggested by'),
      $this->t('Created'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($items as $item) {
      $id = (int) ($item->id ?? 0);
      $uri = (string) ($item->organization_uri ?? '');
      if ($id <= 0 || $uri === '') {
        continue;
      }

      $ns_uri = Utils::namespaceUri($uri);
      $describe_url = Utils::describeUrl($uri, [], FALSE);

      $review_url = Url::fromRoute('rep.review_org_suggestion', [
        'suggestion_id' => $id,
      ]);

      $created = (int) ($item->created ?? 0);
      $created_str = $created ? \Drupal::service('date.formatter')->format($created, 'short') : '';

      $rows[] = [
        'data' => [
          [
            'data' => Link::fromTextAndUrl($ns_uri, $describe_url)->toRenderable(),
          ],
          (string) ($item->suggested_by_email ?? ''),
          $created_str,
          [
            'data' => Link::fromTextAndUrl($this->t('Review'), $review_url)->toRenderable(),
          ],
        ],
      ];
    }

    $form['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No edit suggestions are currently pending.'),
    ];

    $previous_link = $page > 1
      ? Url::fromRoute('rep.review_org_suggestions', [
        'page' => $page - 1,
        'pagesize' => $pagesize,
      ])->toString()
      : '';

    $next_link = $page < $total_pages
      ? Url::fromRoute('rep.review_org_suggestions', [
        'page' => $page + 1,
        'pagesize' => $pagesize,
      ])->toString()
      : '';

    $form['pager'] = [
      '#theme' => 'list-page',
      '#items' => [
        'page' => (string) $page,
        'first' => Url::fromRoute('rep.review_org_suggestions', [
          'page' => 1,
          'pagesize' => $pagesize,
        ])->toString(),
        'last' => Url::fromRoute('rep.review_org_suggestions', [
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
    // No submit actions.
  }

}
