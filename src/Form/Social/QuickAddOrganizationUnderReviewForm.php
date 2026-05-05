<?php

namespace Drupal\rep\Form\Social;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Vocabulary\VSTOI;

class QuickAddOrganizationUnderReviewForm extends FormBase {

  public function getFormId() {
    return 'rep_quick_add_organization_under_review_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $input_id = '', $prefill = '') {
    $wrapper_id = 'rep-social-quick-add-organization-form-wrapper';
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';

    // Support tree modal fields (open-tree-modal).
    $form['#attached']['library'][] = 'rep/rep_modal';

    if (!$form_state->has('organization_uri')) {
      $form_state->set('organization_uri', Utils::uriGen('organization'));
    }

    $prefill = trim((string) $prefill);

    $form['target_input_id'] = [
      '#type' => 'hidden',
      '#value' => (string) $input_id,
    ];

    $form['organization_uri'] = [
      '#type' => 'hidden',
      '#value' => (string) $form_state->get('organization_uri'),
    ];

    // Organization Type (tree modal selection).
    $org_type_field_id = 'rep_social_quick_add_organization_type';
    $form['organization_type'] = [
      'top' => [
        '#type' => 'markup',
        '#markup' => '<div class="pt-3 col border border-white">',
      ],
      'main' => [
        '#type' => 'textfield',
        '#title' => $this->t('Organization Type'),
        '#name' => 'organization_type',
        '#default_value' => '',
        '#id' => $org_type_field_id,
        '#parents' => ['organization_type'],
        '#attributes' => [
          'class' => ['open-tree-modal'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => json_encode(['width' => 800]),
          'data-url' => Url::fromRoute('rep.tree_form', [
            'mode' => 'modal',
            'elementtype' => 'organization',
          ], ['query' => ['field_id' => $org_type_field_id]])->toString(),
          'data-field-id' => $org_type_field_id,
          'data-elementtype' => 'organization',
          'autocomplete' => 'off',
        ],
      ],
      'bottom' => [
        '#type' => 'markup',
        '#markup' => '</div>',
      ],
    ];

    $form['organization_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Short Name (or Acronym)'),
      '#required' => FALSE,
    ];

    $form['organization_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $prefill,
      '#required' => TRUE,
    ];

    $form['organization_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email'),
      '#required' => FALSE,
    ];

    $form['organization_telephone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phone'),
      '#required' => FALSE,
    ];

    $form['organization_postal_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Postal Address'),
      '#autocomplete_route_name' => 'social.autocomplete_postal_address',
      '#required' => FALSE,
    ];

    $form['organization_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#required' => FALSE,
    ];

    $form['organization_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => FALSE,
    ];

    $form['organization_parent_organization'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Parent Organization'),
      '#autocomplete_route_name' => 'social.autocomplete_parent_organization',
      '#required' => FALSE,
    ];

    // Image type (URL or Upload).
    $form['organization_image_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Type'),
      '#options' => [
        '' => $this->t('Select Image Type'),
        'url' => $this->t('URL'),
        'upload' => $this->t('Upload'),
      ],
      '#default_value' => '',
    ];

    $form['organization_image_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image'),
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="organization_image_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Compute upload path segment from the generated URI.
    $modUri = '';
    $nsUri = Utils::namespaceUri((string) $form_state->get('organization_uri'));
    if (is_string($nsUri) && str_contains($nsUri, ':/')) {
      $parts = explode(':/', $nsUri);
      $modUri = $parts[1] ?? '';
    }

    $form['organization_image_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="organization_image_type"]' => ['value' => 'upload'],
        ],
      ],
    ];
    $form['organization_image_upload_wrapper']['organization_image_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Image'),
      '#upload_location' => 'private://resources/' . $modUri . '/image',
      '#default_value' => $form_state->getValue('organization_image_upload') ?: NULL,
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'],
        'file_validate_size' => [2097152],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit for review'),
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

    $name = trim((string) $form_state->getValue('organization_name'));
    $short_label = trim((string) $form_state->getValue('organization_label'));

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

    // Image.
    $image_type = (string) $form_state->getValue('organization_image_type');
    $organization_image = '';
    if ($image_type === 'url') {
      $organization_image = (string) $form_state->getValue('organization_image_url');
    }
    elseif ($image_type === 'upload') {
      $fids = $form_state->getValue('organization_image_upload') ?: [];
      if (!empty($fids)) {
        $file = File::load(reset($fids));
        if ($file) {
          $file->setPermanent();
          $file->save();
          \Drupal::service('file.usage')->add($file, 'rep', 'organization', 1);
          $organization_image = (string) $file->getFilename();
        }
      }
    }

    $payload = [
      'uri' => (string) $form_state->getValue('organization_uri'),
      'typeUri' => $type_uri,
      'hascoTypeUri' => SCHEMA::ORGANIZATION,
      'label' => $short_label !== '' ? $short_label : $name,
      'name' => $name,
      'hasStatus' => VSTOI::UNDER_REVIEW,
      'mbox' => (string) $form_state->getValue('organization_email'),
      'telephone' => (string) $form_state->getValue('organization_telephone'),
      'hasAddressUri' => $postal_uri,
      'hasWebDocument' => (string) $form_state->getValue('organization_url'),
      'comment' => (string) $form_state->getValue('organization_description'),
      'hasImageUri' => $organization_image,
      'parentOrganizationUri' => $parent_uri,
      'hasSIRManagerEmail' => (string) \Drupal::currentUser()->getEmail(),
    ];

    $api = \Drupal::service('rep.api_connector');

    $attempt_payloads = [
      $payload + ['hasVersion' => '1'],
      $payload,
    ];

    $created = FALSE;
    $last_message = '';

    foreach ($attempt_payloads as $attempt) {
      $raw = $api->elementAdd('organization', json_encode($attempt, JSON_UNESCAPED_SLASHES));
      $obj = NULL;
      if (is_string($raw)) {
        $obj = json_decode($raw);
      }
      elseif (is_array($raw) || is_object($raw)) {
        $obj = json_decode(json_encode($raw));
      }

      if (is_object($obj) && !empty($obj->isSuccessful)) {
        $created = TRUE;
        break;
      }

      $last_message = is_object($obj) ? (string) ($obj->body ?? '') : '';
      if (stripos($last_message, 'hasVersion') === FALSE) {
        break;
      }
    }

    if (!$created) {
      $form_state->setErrorByName('organization_name', $this->t('Failed to submit Organization for review. @msg', [
        '@msg' => $last_message ? $last_message : '',
      ]));
      return;
    }

    $form_state->set('rep_social_created', TRUE);
    $form_state->set('rep_social_created_payload', [
      'elementType' => 'organization',
      'uri' => $payload['uri'],
      'label' => $payload['label'] ?? $name,
      'targetInputId' => (string) $form_state->getValue('target_input_id'),
    ]);
  }

  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    if ($form_state->get('rep_social_created') === TRUE) {
      $payload = $form_state->get('rep_social_created_payload') ?: [];

      $response = new AjaxResponse();
      $response->addCommand(new CloseModalDialogCommand());
      $response->addCommand(new InvokeCommand('body', 'trigger', ['repSocialCreated', [$payload]]));
      return $response;
    }

    return $form;
  }

}
