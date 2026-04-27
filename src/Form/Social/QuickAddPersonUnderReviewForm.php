<?php

namespace Drupal\rep\Form\Social;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\SCHEMA;
use Drupal\rep\Vocabulary\VSTOI;

class QuickAddPersonUnderReviewForm extends FormBase {

  public function getFormId() {
    return 'rep_quick_add_person_under_review_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $input_id = '', $prefill = '') {
    $wrapper_id = 'rep-social-quick-add-person-form-wrapper';
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';

    if (!$form_state->has('person_uri')) {
      $form_state->set('person_uri', Utils::uriGen('person'));
    }

    $prefill = trim((string) $prefill);
    $given_default = '';
    $family_default = '';
    if ($prefill !== '') {
      $parts = preg_split('/\s+/', $prefill);
      $given_default = (string) array_shift($parts);
      $family_default = trim((string) implode(' ', $parts));
    }

    $form['target_input_id'] = [
      '#type' => 'hidden',
      '#value' => (string) $input_id,
    ];

    $form['person_uri'] = [
      '#type' => 'hidden',
      '#value' => (string) $form_state->get('person_uri'),
    ];

    $form['person_givenname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Given Name'),
      '#default_value' => $given_default,
      '#required' => TRUE,
    ];

    $form['person_familyname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Family Name'),
      '#default_value' => $family_default,
      '#required' => TRUE,
    ];

    $form['person_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email'),
      '#default_value' => '',
      '#required' => FALSE,
    ];

    $form['person_telephone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phone'),
      '#required' => FALSE,
    ];

    $form['person_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#required' => FALSE,
    ];

    $form['person_affiliation'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Affiliation'),
      '#autocomplete_route_name' => 'social.autocomplete_organization',
      '#required' => FALSE,
    ];

    $form['person_job_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Job Title'),
      '#required' => FALSE,
    ];

    // Image type (URL or Upload).
    $form['person_image_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Type'),
      '#options' => [
        '' => $this->t('Select Image Type'),
        'url' => $this->t('URL'),
        'upload' => $this->t('Upload'),
      ],
      '#default_value' => '',
    ];

    $form['person_image_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image'),
      '#attributes' => [
        'placeholder' => 'http://',
      ],
      '#states' => [
        'visible' => [
          ':input[name="person_image_type"]' => ['value' => 'url'],
        ],
      ],
    ];

    // Compute upload path segment from the generated URI.
    $modUri = '';
    $nsUri = Utils::namespaceUri((string) $form_state->get('person_uri'));
    if (is_string($nsUri) && str_contains($nsUri, ':/')) {
      $parts = explode(':/', $nsUri);
      $modUri = $parts[1] ?? '';
    }

    $form['person_image_upload_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="person_image_type"]' => ['value' => 'upload'],
        ],
      ],
    ];
    $form['person_image_upload_wrapper']['person_image_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Image'),
      '#upload_location' => 'private://resources/' . $modUri . '/image',
      '#default_value' => $form_state->getValue('person_image_upload') ?: NULL,
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'],
        'file_validate_size' => [2097152],
      ],
    ];

    $form['person_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => FALSE,
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

    if (trim((string) $form_state->getValue('person_givenname')) === '') {
      $form_state->setErrorByName('person_givenname', $this->t('Please enter a given name.'));
    }

    if (trim((string) $form_state->getValue('person_familyname')) === '') {
      $form_state->setErrorByName('person_familyname', $this->t('Please enter a family name.'));
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

    $given = trim((string) $form_state->getValue('person_givenname'));
    $family = trim((string) $form_state->getValue('person_familyname'));
    $label = trim($given . ' ' . $family);

    $member_uri = '';
    if (trim((string) $form_state->getValue('person_affiliation')) !== '') {
      $member_uri = (string) Utils::uriFromAutocomplete($form_state->getValue('person_affiliation'));
    }

    // Image.
    $image_type = (string) $form_state->getValue('person_image_type');
    $person_image = '';
    if ($image_type === 'url') {
      $person_image = (string) $form_state->getValue('person_image_url');
    }
    elseif ($image_type === 'upload') {
      $fids = $form_state->getValue('person_image_upload') ?: [];
      if (!empty($fids)) {
        $file = File::load(reset($fids));
        if ($file) {
          $file->setPermanent();
          $file->save();
          \Drupal::service('file.usage')->add($file, 'rep', 'person', 1);
          $person_image = (string) $file->getFilename();
        }
      }
    }

    $payload = [
      'uri' => (string) $form_state->getValue('person_uri'),
      'typeUri' => SCHEMA::PERSON,
      'hascoTypeUri' => SCHEMA::PERSON,
      'givenName' => $given,
      'familyName' => $family,
      'name' => $label,
      'label' => $label,
      'hasStatus' => VSTOI::UNDER_REVIEW,
      'mbox' => (string) $form_state->getValue('person_email'),
      'telephone' => (string) $form_state->getValue('person_telephone'),
      'hasWebDocument' => (string) $form_state->getValue('person_url'),
      'hasAffiliationUri' => $member_uri,
      'jobTitle' => (string) $form_state->getValue('person_job_title'),
      'comment' => (string) $form_state->getValue('person_description'),
      'hasImageUri' => $person_image,
      'hasSIRManagerEmail' => (string) \Drupal::currentUser()->getEmail(),
    ];

    $api = \Drupal::service('rep.api_connector');

    // Attempt with hasVersion=1 first (if API supports it), then fallback.
    $attempt_payloads = [
      $payload + ['hasVersion' => '1'],
      $payload,
    ];

    $created = FALSE;
    $last_message = '';

    foreach ($attempt_payloads as $attempt) {
      $raw = $api->elementAdd('person', json_encode($attempt, JSON_UNESCAPED_SLASHES));
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

      // Only retry without hasVersion on the common Jackson unknown-field error.
      if (stripos($last_message, 'hasVersion') === FALSE) {
        break;
      }
    }

    if (!$created) {
      $form_state->setErrorByName('person_givenname', $this->t('Failed to submit Person for review. @msg', [
        '@msg' => $last_message ? $last_message : '',
      ]));
      return;
    }

    $form_state->set('rep_social_created', TRUE);
    $form_state->set('rep_social_created_payload', [
      'elementType' => 'person',
      'uri' => $payload['uri'],
      'label' => $label,
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

    // Validation or API error: re-render the form wrapper.
    return $form;
  }

}
