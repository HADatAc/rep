<?php

namespace Drupal\rep\Form\Review;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Vocabulary\VSTOI;

class ReviewSocialElementForm extends FormBase {

  public function getFormId() {
    return 'rep_review_social_element_form';
  }

  /**
   * Dynamic page title based on the {elementtype} parameter.
   */
  public static function pageTitle($elementtype) {
    $elementtype = (string) ($elementtype ?? '');

    if ($elementtype === 'person') {
      $type_label = t('Person');
    }
    elseif ($elementtype === 'organization') {
      $type_label = t('Organization');
    }
    else {
      $type_label = t('Social Element');
    }

    return t('Review @type', ['@type' => $type_label]);
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

    $status_plain = Utils::plainStatus($status) ?? $status;

    $display = [];
    if ($elementtype === 'person') {
      $display = $this->buildPersonPayload($element, $status);
    }
    elseif ($elementtype === 'organization') {
      $display = $this->buildOrganizationPayload($element, $status);
    }

    $form['social_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'style' => 'max-width: 1280px;margin-bottom:15px!important;',
      ],
    ];

    $describe_url = Utils::describeUrl((string) $decoded_uri, [], FALSE)
      ->setOptions([
      'attributes' => [
        'target' => '_blank',
        'rel' => 'noopener',
      ],
    ]);

    $form['social_wrapper']['social_uri'] = [
      '#type' => 'item',
      '#title' => $this->t('URI: '),
      '#markup' => Link::fromTextAndUrl($decoded_uri, $describe_url)->toString(),
    ];

    if ($elementtype === 'person') {
      $form['social_wrapper']['person_givenName'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Given Name'),
        '#default_value' => (string) ($display['givenName'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['person_familyName'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Family Name'),
        '#default_value' => (string) ($display['familyName'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['person_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#default_value' => (string) ($display['name'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['person_label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#default_value' => (string) ($display['label'] ?? $label),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['person_mbox'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Email'),
        '#default_value' => (string) ($display['mbox'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['person_telephone'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Phone'),
        '#default_value' => (string) ($display['telephone'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['person_jobTitle'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Job Title'),
        '#default_value' => (string) ($display['jobTitle'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['person_affiliation'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Affiliation URI'),
        '#default_value' => (string) ($display['hasAffiliationUri'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['person_webdoc'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Web Document'),
        '#default_value' => (string) ($display['hasWebDocument'] ?? ''),
        '#attributes' => [
          'placeholder' => 'http://',
        ],
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['person_comment'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => (string) ($display['comment'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['person_image'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Image'),
        '#default_value' => (string) ($display['hasImageUri'] ?? ''),
        '#disabled' => TRUE,
      ];
    }
    elseif ($elementtype === 'organization') {
      $form['social_wrapper']['organization_label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#default_value' => (string) ($display['label'] ?? $label),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['organization_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#default_value' => (string) ($display['name'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['organization_mbox'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Email'),
        '#default_value' => (string) ($display['mbox'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['organization_telephone'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Phone'),
        '#default_value' => (string) ($display['telephone'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['organization_webdoc'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Web Document'),
        '#default_value' => (string) ($display['hasWebDocument'] ?? ''),
        '#attributes' => [
          'placeholder' => 'http://',
        ],
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['organization_address'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Postal Address URI'),
        '#default_value' => (string) ($display['hasAddressUri'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['organization_parent'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Parent Organization URI'),
        '#default_value' => (string) ($display['parentOrganizationUri'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['organization_comment'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => (string) ($display['comment'] ?? ''),
        '#disabled' => TRUE,
      ];
      $form['social_wrapper']['organization_image'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Image'),
        '#default_value' => (string) ($display['hasImageUri'] ?? ''),
        '#disabled' => TRUE,
      ];
    }

    $form['social_wrapper']['social_status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Status'),
      '#default_value' => (string) $status_plain,
      '#disabled' => TRUE,
    ];

    $owner = (string) ($display['hasSIRManagerEmail'] ?? ($element->hasSIRManagerEmail ?? ($element->managerEmail ?? '')));
    $form['social_wrapper']['social_owner'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Owner'),
      '#default_value' => $owner,
      '#attributes' => [
        'disabled' => 'disabled',
      ],
    ];

    $form['social_wrapper']['review_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Review Notes'),
      '#description' => $this->t('Stored only in Drupal messages; the social API may not persist review notes for this element type.'),
      '#required' => FALSE,
    ];

    $form['social_wrapper']['reviewer_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reviewer Email'),
      '#default_value' => (string) (\Drupal::currentUser()->getEmail() ?? ''),
      '#attributes' => [
        'disabled' => 'disabled',
      ],
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
      '#attributes' => [
        'onclick' => 'if(!confirm("Are you sure you want to Approve?")){return false;}',
        'class' => ['btn', 'btn-success', 'aprove-button'],
      ],
    ];

    $form['actions']['reject'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reject'),
      '#name' => 'reject',
      '#attributes' => [
        'onclick' => 'if(!confirm("Are you sure you want to Reject?")){return false;}',
        'class' => ['btn', 'btn-primary', 'cancel-button'],
      ],
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
