<?php

namespace Drupal\rep\Form\Social;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rep\Utils;

class SuggestOrganizationEditForm extends FormBase {

  public function getFormId() {
    return 'rep_suggest_organization_edit_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $organization_uri = NULL) {
    $wrapper_id = 'rep-suggest-org-edit-wrapper';

    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';

    $form['#attached']['library'][] = 'rep/rep_modal';
    $form['#attached']['library'][] = 'core/drupal.dialog';

    $organization_uri = (string) ($organization_uri ?? '');
    if ($organization_uri === '') {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Missing organization URI.'),
      ];
      return $form;
    }

    $form_state->set('organization_uri', $organization_uri);

    $api = \Drupal::service('rep.api_connector');
    $org = $api->parseObjectResponse($api->getUri($organization_uri), 'getUri');
    if (!is_object($org)) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Failed to retrieve organization.'),
      ];
      return $form;
    }

    $current_user_email = (string) (\Drupal::currentUser()->getEmail() ?? '');
    $owner_email = (string) ($org->hasSIRManagerEmail ?? ($org->managerEmail ?? ''));
    $is_owner = ($owner_email !== '' && $current_user_email !== '' && strcasecmp($owner_email, $current_user_email) === 0);

    if ($is_owner) {
      $form['notice'] = [
        '#type' => 'markup',
        '#markup' => $this->t('You are the owner of this organization.'),
      ];

      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['#attributes'] = [
        'class' => ['d-flex', 'gap-2', 'mt-3'],
      ];
      $form['actions']['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->t('Close'),
        '#name' => 'cancel',
        '#limit_validation_errors' => [],
        '#submit' => ['::submitCancel'],
        '#ajax' => [
          'callback' => '::ajaxCancel',
        ],
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'cancel-button'],
        ],
      ];
      return $form;
    }

    $postalAddressDefault = '';
    if (!empty($org->hasAddressUri)) {
      $postal = $api->parseObjectResponse($api->getUri($org->hasAddressUri), 'getUri');
      if (is_object($postal) && !empty($postal->uri) && !empty($postal->label)) {
        $postalAddressDefault = Utils::fieldToAutocomplete($postal->uri, $postal->label);
      }
    }

    $parentOrganizationDefault = '';
    if (!empty($org->parentOrganizationUri)) {
      $parent = $api->parseObjectResponse($api->getUri($org->parentOrganizationUri), 'getUri');
      if (is_object($parent) && !empty($parent->uri) && !empty($parent->label)) {
        $parentOrganizationDefault = Utils::fieldToAutocomplete($parent->uri, $parent->label);
      }
    }

    $typeDefault = '';
    if (!empty($org->typeUri)) {
      $typeDefault = Utils::fieldToAutocomplete($org->typeUri, (string) ($org->typeLabel ?? $org->typeUri));
    }

    $form['organization_uri'] = [
      '#type' => 'hidden',
      '#value' => $organization_uri,
    ];

    $form['organization_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization Type'),
      '#default_value' => $typeDefault,
      '#attributes' => [
        'class' => ['open-tree-modal'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode(['width' => 800]),
        'data-url' => Url::fromRoute('rep.tree_form', [
          'mode' => 'modal',
          'elementtype' => 'organization',
        ], ['query' => ['field_id' => 'organization_type']])->toString(),
        'data-field-id' => 'organization_type',
        'data-elementtype' => 'organization',
        'autocomplete' => 'off',
      ],
    ];

    $form['organization_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Short Name (or Acronym)'),
      '#default_value' => (string) ($org->label ?? ''),
      '#required' => TRUE,
    ];

    $form['organization_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => (string) ($org->name ?? ''),
      '#required' => TRUE,
    ];

    $form['organization_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email'),
      '#default_value' => (string) ($org->mbox ?? ''),
    ];

    $form['organization_telephone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phone'),
      '#default_value' => (string) ($org->telephone ?? ''),
    ];

    $form['organization_postal_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Postal Address'),
      '#autocomplete_route_name' => 'social.autocomplete_postal_address',
      '#default_value' => $postalAddressDefault,
    ];

    $form['organization_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#default_value' => (string) ($org->hasWebDocument ?? ($org->hasUrl ?? '')),
    ];

    $form['organization_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => (string) ($org->comment ?? ''),
    ];

    $form['organization_parent_organization'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Parent Organization'),
      '#autocomplete_route_name' => 'social.autocomplete_parent_organization',
      '#default_value' => $parentOrganizationDefault,
    ];

    $form['organization_image'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image (URL or filename)'),
      '#default_value' => (string) ($org->hasImageUri ?? ''),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'class' => ['d-flex', 'gap-2', 'mt-3'],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit suggestion'),
      '#name' => 'submit',
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => $wrapper_id,
      ],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'save-button'],
      ],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'cancel',
      '#limit_validation_errors' => [],
      '#submit' => ['::submitCancel'],
      '#ajax' => [
        'callback' => '::ajaxCancel',
      ],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'cancel-button'],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $button_name = $trigger['#name'] ?? '';
    if ($button_name === 'cancel') {
      return;
    }

    if (trim((string) $form_state->getValue('organization_name')) === '') {
      $form_state->setErrorByName('organization_name', $this->t('Please enter a name.'));
    }

    if (trim((string) $form_state->getValue('organization_label')) === '') {
      $form_state->setErrorByName('organization_label', $this->t('Please enter a short name.'));
    }
  }

  public function submitCancel(array &$form, FormStateInterface $form_state) {
    // No-op; modal will be closed by AJAX callback.
  }

  public function ajaxCancel(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $button_name = $trigger['#name'] ?? '';
    if ($button_name === 'cancel') {
      return;
    }

    $organization_uri = (string) ($form_state->get('organization_uri') ?? '');
    if ($organization_uri === '') {
      $form_state->setErrorByName('organization_name', $this->t('Missing organization URI.'));
      return;
    }

    $type_uri = '';
    if (trim((string) $form_state->getValue('organization_type')) !== '') {
      $type_uri = (string) Utils::uriFromAutocomplete($form_state->getValue('organization_type'));
    }

    $postal_uri = '';
    if (trim((string) $form_state->getValue('organization_postal_address')) !== '') {
      $postal_uri = (string) Utils::uriFromAutocomplete($form_state->getValue('organization_postal_address'));
    }

    $parent_uri = '';
    if (trim((string) $form_state->getValue('organization_parent_organization')) !== '') {
      $parent_uri = (string) Utils::uriFromAutocomplete($form_state->getValue('organization_parent_organization'));
    }

    $payload = [
      'typeUri' => $type_uri,
      'label' => trim((string) $form_state->getValue('organization_label')),
      'name' => trim((string) $form_state->getValue('organization_name')),
      'mbox' => trim((string) $form_state->getValue('organization_email')),
      'telephone' => trim((string) $form_state->getValue('organization_telephone')),
      'hasAddressUri' => $postal_uri,
      'hasWebDocument' => trim((string) $form_state->getValue('organization_url')),
      'comment' => trim((string) $form_state->getValue('organization_description')),
      'parentOrganizationUri' => $parent_uri,
      'hasImageUri' => trim((string) $form_state->getValue('organization_image')),
    ];

    $uid = (int) \Drupal::currentUser()->id();
    $email = (string) (\Drupal::currentUser()->getEmail() ?? '');

    try {
      \Drupal::database()->insert('rep_org_edit_suggestions')
        ->fields([
          'organization_uri' => $organization_uri,
          'suggested_by_uid' => $uid,
          'suggested_by_email' => $email,
          'created' => \Drupal::time()->getRequestTime(),
          'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ])
        ->execute();

      $form_state->set('rep_org_suggestion_created', TRUE);
    }
    catch (\Exception $e) {
      $form_state->setErrorByName('organization_name', $this->t('Failed to submit suggestion.'));
    }
  }

  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    if ($form_state->get('rep_org_suggestion_created') === TRUE) {
      $response = new AjaxResponse();
      $response->addCommand(new CloseModalDialogCommand());
      $response->addCommand(new HtmlCommand('#rep-org-suggestion-status', '<div class="mt-3"><strong>' . $this->t('Suggestion submitted for review.') . '</strong></div>'));
      return $response;
    }

    return $form;
  }

}
