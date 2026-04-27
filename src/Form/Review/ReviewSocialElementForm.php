<?php

namespace Drupal\rep\Form\Review;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Vocabulary\VSTOI;

class ReviewSocialElementForm extends FormBase {

  public function getFormId() {
    return 'rep_review_social_element_form';
  }

  private function isAllowedElementType($elementtype): bool {
    return in_array((string) $elementtype, ['person', 'organization'], TRUE);
  }

  private function buildPersonPayload(object $person, string $status): array {
    $given = (string) ($person->givenName ?? '');
    $family = (string) ($person->familyName ?? '');
    $name = (string) ($person->name ?? trim($given . ' ' . $family));
    $label = (string) ($person->label ?? $name);

    $affiliation_uri = '';
    if (!empty($person->hasAffiliationUri)) {
      $affiliation_uri = (string) $person->hasAffiliationUri;
    }
    elseif (!empty($person->hasAffiliation) && is_object($person->hasAffiliation) && !empty($person->hasAffiliation->uri)) {
      $affiliation_uri = (string) $person->hasAffiliation->uri;
    }

    $webdoc = (string) ($person->hasWebDocument ?? '');

    $type_uri = (string) ($person->typeUri ?? '');
    $hasco_type_uri = (string) ($person->hascoTypeUri ?? '');

    return [
      'uri' => (string) ($person->uri ?? ''),
      'typeUri' => $type_uri !== '' ? $type_uri : SCHEMA::PERSON,
      'hascoTypeUri' => $hasco_type_uri !== '' ? $hasco_type_uri : SCHEMA::PERSON,
      'givenName' => $given,
      'familyName' => $family,
      'name' => $name,
      'label' => $label,
      'hasStatus' => $status,
      'mbox' => (string) ($person->mbox ?? ''),
      'telephone' => (string) ($person->telephone ?? ''),
      'hasWebDocument' => $webdoc,
      'hasAffiliationUri' => $affiliation_uri,
      'jobTitle' => (string) ($person->jobTitle ?? ''),
      'comment' => (string) ($person->comment ?? ''),
      'hasImageUri' => (string) ($person->hasImageUri ?? ''),
      'hasSIRManagerEmail' => (string) ($person->hasSIRManagerEmail ?? ''),
    ];
  }

  private function buildOrganizationPayload(object $org, string $status): array {
    $name = (string) ($org->name ?? '');
    $label = (string) ($org->label ?? ($name !== '' ? $name : ($org->uri ?? '')));

    $parent_uri = '';
    if (!empty($org->parentOrganizationUri)) {
      $parent_uri = (string) $org->parentOrganizationUri;
    }
    elseif (!empty($org->parentOrganization) && is_object($org->parentOrganization) && !empty($org->parentOrganization->uri)) {
      $parent_uri = (string) $org->parentOrganization->uri;
    }

    $type_uri = (string) ($org->typeUri ?? '');
    $hasco_type_uri = (string) ($org->hascoTypeUri ?? '');

    $address_uri = '';
    if (!empty($org->hasAddressUri)) {
      $address_uri = (string) $org->hasAddressUri;
    }
    elseif (!empty($org->hasAddress) && is_object($org->hasAddress) && !empty($org->hasAddress->uri)) {
      $address_uri = (string) $org->hasAddress->uri;
    }

    return [
      'uri' => (string) ($org->uri ?? ''),
      'typeUri' => $type_uri !== '' ? $type_uri : SCHEMA::ORGANIZATION,
      'hascoTypeUri' => $hasco_type_uri !== '' ? $hasco_type_uri : SCHEMA::ORGANIZATION,
      'label' => $label,
      'name' => $name !== '' ? $name : $label,
      'hasStatus' => $status,
      'mbox' => (string) ($org->mbox ?? ''),
      'telephone' => (string) ($org->telephone ?? ''),
      'hasWebDocument' => (string) ($org->hasWebDocument ?? ''),
      'hasAddressUri' => $address_uri,
      'comment' => (string) ($org->comment ?? ''),
      'hasImageUri' => (string) ($org->hasImageUri ?? ''),
      'parentOrganizationUri' => $parent_uri,
      'hasSIRManagerEmail' => (string) ($org->hasSIRManagerEmail ?? ''),
    ];
  }

  private function updateStatus(string $elementtype, object $element, string $status): bool {
    $api = \Drupal::service('rep.api_connector');

    $payload = [];
    if ($elementtype === 'person') {
      $payload = $this->buildPersonPayload($element, $status);
    }
    elseif ($elementtype === 'organization') {
      $payload = $this->buildOrganizationPayload($element, $status);
    }

    if (empty($payload['uri'])) {
      return FALSE;
    }

    $uri = (string) $payload['uri'];

    $del = $api->parseObjectResponse($api->elementDel($elementtype, $uri), 'elementDel');
    if ($del === NULL) {
      return FALSE;
    }

    $add = $api->parseObjectResponse(
      $api->elementAdd($elementtype, json_encode($payload, JSON_UNESCAPED_SLASHES)),
      'elementAdd'
    );

    return $add !== NULL;
  }

  private function getPreviousUriMarker(object $element): string {
    // Some payloads may expose either originalID or originalId.
    $candidate = '';
    if (!empty($element->originalID)) {
      $candidate = (string) $element->originalID;
    }
    elseif (!empty($element->originalId)) {
      $candidate = (string) $element->originalId;
    }

    $candidate = trim($candidate);
    if ($candidate === '') {
      return '';
    }

    // Only treat as a marker if it looks like a URI.
    if (preg_match('#^https?://#i', $candidate) !== 1) {
      return '';
    }

    return $candidate;
  }

  private function backToList(FormStateInterface $form_state, string $elementtype): void {
    $form_state->setRedirect('rep.review_social_select', [
      'elementtype' => $elementtype,
      'page' => 1,
      'pagesize' => 9,
    ]);
  }

  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = 'person', $elementuri = NULL) {
    $elementtype = (string) $elementtype;

    if (!$this->isAllowedElementType($elementtype) || !is_string($elementuri)) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Unsupported element type or missing URI.'),
      ];
      return $form;
    }

    $decoded_uri = base64_decode($elementuri, TRUE);
    if (!is_string($decoded_uri) || $decoded_uri === '') {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Invalid URI.'),
      ];
      return $form;
    }

    $api = \Drupal::service('rep.api_connector');
    $element = $api->parseObjectResponse($api->getUri($decoded_uri), 'getUri');

    if ($element === NULL || !is_object($element)) {
      \Drupal::messenger()->addError($this->t('Failed to retrieve the element.'));
      $this->backToList($form_state, $elementtype);
      return $form;
    }

    $label = (string) ($element->label ?? ($element->name ?? $decoded_uri));
    $status = (string) ($element->hasStatus ?? '');

    $form['summary'] = [
      '#type' => 'item',
      '#markup' => '<h3 class="mt-4">' . $this->t('Review @type', ['@type' => $elementtype]) . '</h3>'
        . '<div><b>' . $this->t('Label') . ':</b> ' . htmlspecialchars($label, ENT_QUOTES) . '</div>'
        . '<div><b>' . $this->t('URI') . ':</b> ' . htmlspecialchars($decoded_uri, ENT_QUOTES) . '</div>'
        . '<div><b>' . $this->t('Status') . ':</b> ' . htmlspecialchars($status, ENT_QUOTES) . '</div>',
    ];

    $form['review_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Review note (optional)'),
      '#description' => $this->t('Stored only in Drupal messages; the social API may not persist review notes for this element type.'),
      '#required' => FALSE,
    ];

    $form['elementtype'] = [
      '#type' => 'hidden',
      '#value' => $elementtype,
    ];

    $form['elementuri'] = [
      '#type' => 'hidden',
      '#value' => $decoded_uri,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['approve'] = [
      '#type' => 'submit',
      '#value' => $this->t('Approve'),
      '#name' => 'approve',
      '#attributes' => ['class' => ['btn', 'btn-primary', 'save-button']],
    ];

    $form['actions']['reject'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reject'),
      '#name' => 'reject',
      '#attributes' => ['class' => ['btn', 'btn-danger']],
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#attributes' => ['class' => ['btn', 'btn-primary', 'back-button']],
    ];

    // Keep the decoded object for submit.
    $form_state->set('rep_social_review_element', $element);

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    // No mandatory validation for reject note at this stage.
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $button = $trigger['#name'] ?? '';

    $elementtype = (string) $form_state->getValue('elementtype');
    $uri = (string) $form_state->getValue('elementuri');

    if ($button === 'back') {
      $this->backToList($form_state, $elementtype);
      return;
    }

    $element = $form_state->get('rep_social_review_element');
    if (!is_object($element)) {
      \Drupal::messenger()->addError($this->t('Internal error: missing element.'));
      $this->backToList($form_state, $elementtype);
      return;
    }

    $note = trim((string) $form_state->getValue('review_note'));

    if ($button === 'approve') {
      // If this Under Review element represents a new version created from an
      // edit, it may carry a marker pointing to the previous URI.
      $previous_uri = $this->getPreviousUriMarker($element);
      if ($previous_uri !== '' && $previous_uri !== $uri) {
        $api = \Drupal::service('rep.api_connector');
        $previous = $api->parseObjectResponse($api->getUri($previous_uri), 'getUri');
        if ($previous === NULL || !is_object($previous)) {
          \Drupal::messenger()->addError($this->t('Failed to retrieve previous version: @uri', ['@uri' => $previous_uri]));
          return;
        }

        $ok_prev = $this->updateStatus($elementtype, $previous, VSTOI::DEPRECATED);
        if (!$ok_prev) {
          \Drupal::messenger()->addError($this->t('Failed to deprecate previous version: @uri', ['@uri' => $previous_uri]));
          return;
        }
      }

      $ok = $this->updateStatus($elementtype, $element, VSTOI::CURRENT);
      if ($ok) {
        \Drupal::messenger()->addMessage($this->t('Approved: @uri', ['@uri' => $uri]));
      }
      else {
        \Drupal::messenger()->addError($this->t('Failed to approve: @uri', ['@uri' => $uri]));
        return;
      }
    }
    elseif ($button === 'reject') {
      $ok = $this->updateStatus($elementtype, $element, VSTOI::DRAFT);
      if ($ok) {
        \Drupal::messenger()->addMessage($this->t('Rejected: @uri', ['@uri' => $uri]));
        if ($note !== '') {
          \Drupal::messenger()->addWarning($this->t('Review note: @note', ['@note' => $note]));
        }
      }
      else {
        \Drupal::messenger()->addError($this->t('Failed to reject: @uri', ['@uri' => $uri]));
        return;
      }
    }

    $this->backToList($form_state, $elementtype);
  }

}
