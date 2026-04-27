<?php

namespace Drupal\rep\Form\Review;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Vocabulary\VSTOI;

class ReviewOrganizationSuggestionForm extends FormBase {

  public function getFormId() {
    return 'rep_review_org_suggestion_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $suggestion_id = NULL) {
    $suggestion_id = (int) ($suggestion_id ?? 0);
    if ($suggestion_id <= 0) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Invalid suggestion id.'),
      ];
      return $form;
    }

    $row = \Drupal::database()->select('rep_org_edit_suggestions', 's')
      ->fields('s')
      ->condition('id', $suggestion_id)
      ->execute()
      ->fetchObject();

    if (!$row) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Suggestion not found (maybe already processed).'),
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#name' => 'back',
        '#attributes' => ['class' => ['btn', 'btn-primary']],
      ];
      return $form;
    }

    $payload = [];
    if (!empty($row->payload)) {
      $decoded = json_decode($row->payload, TRUE);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }

    $organization_uri = (string) ($row->organization_uri ?? '');

    $api = \Drupal::service('rep.api_connector');
    $org = $organization_uri !== '' ? $api->parseObjectResponse($api->getUri($organization_uri), 'getUri') : NULL;

    $form['suggestion_id'] = [
      '#type' => 'hidden',
      '#value' => $suggestion_id,
    ];

    $form['title'] = [
      '#type' => 'item',
      '#markup' => '<h3 class="mt-4">' . $this->t('Review Organization Edit Suggestion') . '</h3>',
    ];

    $created = (int) ($row->created ?? 0);
    $created_str = $created ? \Drupal::service('date.formatter')->format($created, 'short') : '';

    $form['meta'] = [
      '#type' => 'item',
      '#markup' => '<div class="mb-3">'
        . '<div><strong>' . $this->t('Organization URI') . ':</strong> ' . Html::escape(Utils::namespaceUri($organization_uri)) . '</div>'
        . '<div><strong>' . $this->t('Suggested by') . ':</strong> ' . Html::escape((string) ($row->suggested_by_email ?? '')) . '</div>'
        . '<div><strong>' . $this->t('Created') . ':</strong> ' . Html::escape($created_str) . '</div>'
        . '</div>',
    ];

    if (!is_object($org)) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Failed to retrieve the current Organization from the API. You can still reject this suggestion.'),
      ];
    }

    $current = [
      'typeUri' => is_object($org) ? (string) ($org->typeUri ?? '') : '',
      'typeLabel' => is_object($org) ? (string) ($org->typeLabel ?? '') : '',
      'label' => is_object($org) ? (string) ($org->label ?? '') : '',
      'name' => is_object($org) ? (string) ($org->name ?? '') : '',
      'mbox' => is_object($org) ? (string) ($org->mbox ?? '') : '',
      'telephone' => is_object($org) ? (string) ($org->telephone ?? '') : '',
      'hasAddressUri' => is_object($org) ? (string) ($org->hasAddressUri ?? '') : '',
      'hasWebDocument' => is_object($org) ? (string) ($org->hasWebDocument ?? ($org->hasUrl ?? '')) : '',
      'comment' => is_object($org) ? (string) ($org->comment ?? '') : '',
      'parentOrganizationUri' => is_object($org) ? (string) ($org->parentOrganizationUri ?? '') : '',
      'hasImageUri' => is_object($org) ? (string) ($org->hasImageUri ?? '') : '',
      'hasStatus' => is_object($org) ? (string) ($org->hasStatus ?? '') : '',
    ];

    $fields = [
      'typeUri' => $this->t('Type URI'),
      'label' => $this->t('Short Name'),
      'name' => $this->t('Name'),
      'mbox' => $this->t('Email'),
      'telephone' => $this->t('Phone'),
      'hasAddressUri' => $this->t('Postal Address URI'),
      'hasWebDocument' => $this->t('URL'),
      'comment' => $this->t('Description'),
      'parentOrganizationUri' => $this->t('Parent Organization URI'),
      'hasImageUri' => $this->t('Image'),
    ];

    $rows = [];
    foreach ($fields as $key => $label) {
      $cur = (string) ($current[$key] ?? '');
      $sug = (string) ($payload[$key] ?? '');

      // For type, include label if present.
      if ($key === 'typeUri' && !empty($current['typeLabel'])) {
        $cur = $cur !== '' ? ($cur . ' (' . $current['typeLabel'] . ')') : $current['typeLabel'];
      }

      $rows[] = [
        'data' => [
          (string) $label,
          Html::escape($cur),
          Html::escape($sug),
        ],
      ];
    }

    $form['diff'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Field'),
        $this->t('Current'),
        $this->t('Suggested'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No suggested fields found.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['approve'] = [
      '#type' => 'submit',
      '#value' => $this->t('Approve'),
      '#name' => 'approve',
      '#attributes' => [
        'class' => ['btn', 'btn-primary'],
        'onclick' => 'if(!confirm("Apply this suggestion to the same URI?")){return false;}',
      ],
    ];

    $form['actions']['reject'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reject'),
      '#name' => 'reject',
      '#attributes' => [
        'class' => ['btn', 'btn-primary'],
        'onclick' => 'if(!confirm("Reject and delete this suggestion?")){return false;}',
      ],
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary'],
      ],
    ];

    // Persist for submit.
    $form_state->set('rep_org_suggestion_row', $row);
    $form_state->set('rep_org_suggestion_payload', $payload);
    $form_state->set('rep_org_suggestion_org_current', is_object($org) ? $org : NULL);

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $button = (string) ($trigger['#name'] ?? '');

    if ($button === 'back') {
      $form_state->setRedirectUrl(Url::fromRoute('rep.review_org_suggestions', ['page' => 1, 'pagesize' => 9]));
      return;
    }

    $row = $form_state->get('rep_org_suggestion_row');
    $payload = $form_state->get('rep_org_suggestion_payload') ?: [];
    $org = $form_state->get('rep_org_suggestion_org_current');

    $suggestion_id = (int) ($row->id ?? 0);
    if ($suggestion_id <= 0) {
      \Drupal::messenger()->addError($this->t('Invalid suggestion.'));
      $form_state->setRedirectUrl(Url::fromRoute('rep.review_org_suggestions', ['page' => 1, 'pagesize' => 9]));
      return;
    }

    if ($button === 'reject') {
      \Drupal::database()->delete('rep_org_edit_suggestions')
        ->condition('id', $suggestion_id)
        ->execute();
      \Drupal::messenger()->addMessage($this->t('Suggestion rejected and deleted.'));
      $form_state->setRedirectUrl(Url::fromRoute('rep.review_org_suggestions', ['page' => 1, 'pagesize' => 9]));
      return;
    }

    if ($button !== 'approve') {
      return;
    }

    if (!is_object($org)) {
      \Drupal::messenger()->addError($this->t('Cannot approve: failed to retrieve current Organization from API.'));
      return;
    }

    $organization_uri = (string) ($org->uri ?? ($row->organization_uri ?? ''));

    $update = [
      'uri' => $organization_uri,
      'typeUri' => (string) (!empty($payload['typeUri']) ? $payload['typeUri'] : ($org->typeUri ?? '')),
      'hascoTypeUri' => SCHEMA::ORGANIZATION,
      'label' => (string) (!empty($payload['label']) ? $payload['label'] : ($org->label ?? '')),
      'name' => (string) (!empty($payload['name']) ? $payload['name'] : ($org->name ?? '')),
      'mbox' => (string) (!empty($payload['mbox']) ? $payload['mbox'] : ($org->mbox ?? '')),
      'telephone' => (string) (!empty($payload['telephone']) ? $payload['telephone'] : ($org->telephone ?? '')),
      'hasAddressUri' => (string) (!empty($payload['hasAddressUri']) ? $payload['hasAddressUri'] : ($org->hasAddressUri ?? '')),
      'hasWebDocument' => (string) (!empty($payload['hasWebDocument']) ? $payload['hasWebDocument'] : ($org->hasWebDocument ?? ($org->hasUrl ?? ''))),
      'comment' => (string) (!empty($payload['comment']) ? $payload['comment'] : ($org->comment ?? '')),
      'parentOrganizationUri' => (string) (!empty($payload['parentOrganizationUri']) ? $payload['parentOrganizationUri'] : ($org->parentOrganizationUri ?? '')),
      'hasImageUri' => (string) (!empty($payload['hasImageUri']) ? $payload['hasImageUri'] : ($org->hasImageUri ?? '')),
      // Keep existing status (no state change).
      'hasStatus' => (string) (!empty($org->hasStatus) ? $org->hasStatus : VSTOI::DRAFT),
      // Preserve owner email.
      'hasSIRManagerEmail' => (string) ($org->hasSIRManagerEmail ?? ($org->managerEmail ?? '')),
    ];

    $api = \Drupal::service('rep.api_connector');

    // Overwrite the same URI (no versioning).
    $ok_del = $api->parseObjectResponse($api->elementDel('organization', $organization_uri), 'elementDel');

    $json = json_encode($update, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
      \Drupal::messenger()->addError($this->t('Failed to encode suggestion payload.'));
      return;
    }

    $ok_add = $api->parseObjectResponse($api->elementAdd('organization', $json), 'elementAdd');

    if ($ok_add === NULL) {
      \Drupal::messenger()->addError($this->t('Failed to apply suggestion to the API.'));
      return;
    }

    \Drupal::database()->delete('rep_org_edit_suggestions')
      ->condition('id', $suggestion_id)
      ->execute();

    \Drupal::messenger()->addMessage($this->t('Suggestion approved and applied to @uri.', ['@uri' => Utils::namespaceUri($organization_uri)]));
    $form_state->setRedirectUrl(Url::fromRoute('rep.review_org_suggestions', ['page' => 1, 'pagesize' => 9]));
  }

}
