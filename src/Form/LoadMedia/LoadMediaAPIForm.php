<?php

namespace Drupal\rep\Form\LoadMedia;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\rep\Utils;
use Drupal\file\Entity\File;


/**
 * Provides a form for generating INS (GRAXIOM project).
 */
class LoadMediaApiForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'load_media_api_form';
  }

  /**
   * Builds the form with a text field and a ZIP file upload field.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The modified form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Add a text field for Folder Name.
    $form['folder_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Folder Name'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('Enter folder name'),
      ],
    ];

    // Add a managed file field for uploading a ZIP file.
    $form['zip_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload ZIP File'),
      '#upload_validators' => [
        // Allow only files with the .zip extension.
        'file_validate_extensions' => ['zip'],
      ],
      '#required' => TRUE,
      '#description' => $this->t('Only ZIP files are allowed.'),
    ];

    // Create the actions container.
    $form['actions'] = [
      '#type' => 'actions',
    ];
    // Add the submit button with conditional state: enabled only when both fields are filled.
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#states' => [
        'enabled' => [
          // The folder_name field must be filled.
          ':input[name="folder_name"]' => ['filled' => TRUE],
          // The zip_upload field (specifically the file IDs array) must be filled.
          ':input[name="zip_upload[fids]"]' => ['filled' => TRUE],
        ],
      ],
    ];

    // Cancel button: separate callback, skips validation.
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'cancel',
      '#submit' => ['::cancelForm'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'cancel-button'],
      ],
    ];

    return $form;
  }

  /**
   * Validates the form inputs.
   *
   * @param array &$form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate that the Folder Name field is not empty.
    if (trim($form_state->getValue('folder_name')) === '') {
      $form_state->setErrorByName('folder_name', $this->t('Folder Name is required.'));
    }

    // Retrieve the file IDs from the zip_upload field.
    $zip_fids = $form_state->getValue('zip_upload');
    if (empty($zip_fids)) {
      $form_state->setErrorByName('zip_upload', $this->t('A ZIP file must be uploaded.'));
    }
    else {
      // Load the uploaded file using its file ID.
      $fid = reset($zip_fids);
      $file = File::load($fid);
      if ($file) {
        // Get the real file system path of the uploaded file.
        $file_path = \Drupal::service('file_system')->realpath($file->getFileUri());

        // Initialize the ZipArchive class.
        $zip = new \ZipArchive();
        if ($zip->open($file_path) === TRUE) {
          // Check if the ZIP file contains at least one item.
          if ($zip->numFiles <= 0) {
            $form_state->setErrorByName('zip_upload', $this->t('The ZIP file is empty.'));
          }
          $zip->close();
        }
        else {
          $form_state->setErrorByName('zip_upload', $this->t('Unable to open the ZIP file.'));
        }
      }
      else {
        $form_state->setErrorByName('zip_upload', $this->t('Uploaded file not found.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Get the API service.
    $api_service = \Drupal::service('rep.api_connector');

    // Retrieve the folder name from the form input.
    $filename = $form_state->getValue('folder_name');

    // Retrieve the uploaded file ID from the managed file field.
    $zip_fids = $form_state->getValue('zip_upload');
    $fid = reset($zip_fids);

    // Call the API method, passing the Drupal file ID and the filename.
    $result = $api_service->uploadMediaFile($fid, $filename);

    // Display a success message.
    \Drupal::messenger()->addMessage($this->t('File successfully sent.'));

    // Redirect to the home route.
    $url = \Drupal\Core\Url::fromRoute('rep.home');
    $response = new \Symfony\Component\HttpFoundation\RedirectResponse($url->toString());
    $response->send();
  }


  /**
   * Cancel button submit callback.
   */
  public function cancelForm(array &$form, FormStateInterface $form_state) {
    $this->backUrl();
  }

  /**
   * Redirects the user to the previously tracked URL or a fallback.
   */
  public function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'rep.load_media_api');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
    }
    else {
      $url = Url::fromRoute('rep.home');
      $response = new RedirectResponse($url->toString());
      $response->send();
    }
  }

}
