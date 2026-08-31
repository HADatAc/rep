<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\file\Entity\File;
use Drupal\rep\Utils;
use Drupal\rep\Constant;
use Drupal\rep\Vocabulary\HASCO;

/**
 * Dedicated WKF upload form.
 */
class UploadWKFForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'upload_wkf_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyuri = NULL, $fixstd = NULL) {
    $upload_path = 'private://wkf/';
    if (!\Drupal::service('file_system')->prepareDirectory($upload_path, FileSystemInterface::CREATE_DIRECTORY)) {
      \Drupal::messenger()->addError(t('Upload directory could not be prepared: @path', ['@path' => $upload_path]));
      return $form;
    }

    $form['page_title'] = [
      '#type' => 'item',
      '#title' => $this->t('<h1>Upload WKF</h1>'),
    ];

    $form['mt_filename'] = [
      '#type' => 'file',
      '#title' => $this->t('File Upload'),
      '#description' => $this->t('Upload a WKF workbook file (.xlsx). Name, version, and comment are read from workbook content.'),
      '#attributes' => [
        'accept' => '.xlsx',
      ],
    ];

    $form['save_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#name' => 'save',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'save-button'],
      ],
    ];

    $form['cancel_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'cancel-button'],
      ],
    ];

    $form['bottom_space'] = [
      '#type' => 'item',
      '#title' => t('<br><br>'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];
    if ($button_name !== 'save') {
      return;
    }

    $uploaded_name = $_FILES['files']['name']['mt_filename'] ?? '';
    if (trim((string) $uploaded_name) === '') {
      $form_state->setErrorByName('mt_filename', $this->t('Please upload a WKF file.'));
      return;
    }

    $ext = strtolower(pathinfo((string) $uploaded_name, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
      $form_state->setErrorByName('mt_filename', $this->t('Only .xlsx files are allowed for WKF upload.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      $this->redirectBackOrManageWkf($form_state);
      return;
    }

    if (empty($_FILES['files']['name']['mt_filename'])) {
      \Drupal::messenger()->addError(t('Please upload a file.'));
      return;
    }

    $file_info = $_FILES['files'];
    $tmp_name = $file_info['tmp_name']['mt_filename'];
    $original_name = $file_info['name']['mt_filename'];
    $original_filename = basename((string) $original_name);
    $uploadedFingerprint = $this->captureBinaryFingerprint($tmp_name);
    if (!$uploadedFingerprint['ok']) {
      \Drupal::messenger()->addError(t('WKF upload failed: cannot read uploaded temporary file bytes. Please retry upload.'));
      return;
    }

    $destination_dir = 'private://wkf';
    $file_system = \Drupal::service('file_system');
    if (!$file_system->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY)) {
      \Drupal::messenger()->addError(t('Upload directory could not be prepared: @dir', ['@dir' => $destination_dir]));
      return;
    }

    $this->purgeLegacyUploadArtifacts($destination_dir, $original_filename);

    $stored_filename = $this->buildUniqueStoredFilename($original_filename);
    $destination_real = $file_system->realpath($destination_dir) . '/' . $stored_filename;
    if (!move_uploaded_file($tmp_name, $destination_real)) {
      \Drupal::messenger()->addError(t('Failed to move uploaded file. Check permissions for @dir', ['@dir' => $destination_real]));
      return;
    }

    if (!file_exists($destination_real)) {
      \Drupal::messenger()->addError(t('File move failed - destination file does not exist: @path', ['@path' => $destination_real]));
      return;
    }

    $storedFingerprint = $this->captureBinaryFingerprint($destination_real);
    $isExactCopy = $storedFingerprint['ok']
      && $uploadedFingerprint['size'] === $storedFingerprint['size']
      && hash_equals((string) $uploadedFingerprint['sha256'], (string) $storedFingerprint['sha256']);
    if (!$isExactCopy) {
      if (is_file($destination_real)) {
        @unlink($destination_real);
      }
      \Drupal::logger('rep')->error('WKF private copy mismatch in UploadWKFForm. file=@file uploaded_size=@uploaded_size stored_size=@stored_size uploaded_sha256=@uploaded_sha256 stored_sha256=@stored_sha256', [
        '@file' => $original_filename,
        '@uploaded_size' => (string) $uploadedFingerprint['size'],
        '@stored_size' => (string) ($storedFingerprint['size'] ?? 0),
        '@uploaded_sha256' => (string) $uploadedFingerprint['sha256'],
        '@stored_sha256' => (string) ($storedFingerprint['sha256'] ?? ''),
      ]);
      \Drupal::messenger()->addError(t('WKF upload rejected: private copy bytes differ from uploaded file bytes.'));
      return;
    }

    $drupal_uri = $destination_dir . '/' . $stored_filename;

    try {
      $useremail = trim((string) \Drupal::currentUser()->getEmail());
      if ($useremail === '' || !filter_var($useremail, FILTER_VALIDATE_EMAIL)) {
        $siteMail = trim((string) (\Drupal::config('system.site')->get('mail') ?? ''));
        if ($siteMail !== '' && filter_var($siteMail, FILTER_VALIDATE_EMAIL)) {
          $useremail = $siteMail;
        }
        else {
          $useremail = 'admin@pmsr.com';
        }
      }

      $file_entity = File::create([
        'uri' => $drupal_uri,
        'filename' => $original_filename,
        'status' => FileInterface::STATUS_PERMANENT,
        'uid' => \Drupal::currentUser()->id(),
      ]);
      $file_entity->save();
      $file_id = $file_entity->id();

      $originMgr = \Drupal::service('rep.file_origin_manager');
      $originMgr->markLocal((int) $file_id);

      $api = \Drupal::service('rep.api_connector');

      $wkfWorkbookMeta = [];
      try {
        $metadataJson = (string) \Drupal::service('ctt.wkf_metadata_extractor')->extractMetadataJsonFromFile($destination_real);
        if (trim($metadataJson) === '') {
          \Drupal::logger('rep')->warning('WKF metadata extractor returned empty JSON in UploadWKFForm for {path}', [
            'path' => $destination_real,
          ]);
        }
        else {
          $decoded = json_decode($metadataJson, TRUE);
          if (is_array($decoded)) {
            $wkfWorkbookMeta = $decoded;
          }
          else {
            \Drupal::logger('rep')->warning('WKF metadata extractor returned non-object JSON in UploadWKFForm for {path}. json_error={error}', [
              'path' => $destination_real,
              'error' => json_last_error_msg(),
            ]);
          }
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('rep')->warning('WKF metadata extraction service failed in UploadWKFForm for {path}: {message}', [
          'path' => $destination_real,
          'message' => $e->getMessage(),
        ]);
      }
      if (empty($wkfWorkbookMeta)) {
        \Drupal::logger('rep')->warning('WKF metadata map is empty in UploadWKFForm before URI extraction. file=@file path=@path', [
          '@file' => $original_filename,
          '@path' => $destination_real,
        ]);
      }
      \Drupal::logger('rep')->notice('WKF metadata identity URIs in UploadWKFForm. file=@file organization_uri=@org_uri principal_investigator_uri=@pi_uri', [
        '@file' => $original_filename,
        '@org_uri' => (string) ($wkfWorkbookMeta['organization_uri'] ?? ''),
        '@pi_uri' => (string) ($wkfWorkbookMeta['principal_investigator_uri'] ?? ''),
      ]);
      $targetOrganizationUri = trim((string) ($wkfWorkbookMeta['organization_uri'] ?? ''));

      $nameValue = trim((string) ($wkfWorkbookMeta['label'] ?? ''));
      $commentValue = trim($wkfWorkbookMeta['comment']) !== '' ? trim($wkfWorkbookMeta['comment']) : $nameValue;
      $versionValue = trim($wkfWorkbookMeta['version']) !== '' ? trim($wkfWorkbookMeta['version']) : '1';

      // Keep uploader provenance in hasSIRManagerEmail. STD ownership fields are
      // used for study identity validation, not uploader attribution.

      $newDataFileUri = Utils::uriGen('datafile');
      $datafilePayload = [
        'uri' => $newDataFileUri,
        'typeUri' => HASCO::DATAFILE,
        'hascoTypeUri' => HASCO::DATAFILE,
        'label' => $nameValue,
        'filename' => $original_filename,
        'fileStatus' => Constant::FILE_STATUS_UNPROCESSED,
        'hasSIRManagerEmail' => $useremail,
        'ingestionOrganizationUri' => $targetOrganizationUri,
        'id' => $file_id,
      ];
      $datafileJSON = json_encode($datafilePayload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
      if (!is_string($datafileJSON) || trim($datafileJSON) === '') {
        \Drupal::messenger()->addError(t('WKF upload failed: could not encode DataFile payload as JSON. Error: @err', [
          '@err' => json_last_error_msg(),
        ]));
        $this->redirectBackOrManageWkf($form_state);
        return;
      }

      $newMTUri = str_replace('DFL', Utils::elementPrefix('wkf'), $newDataFileUri);
      $mtData = [
        'uri' => $newMTUri,
        'typeUri' => HASCO::WKF,
        'hascoTypeUri' => HASCO::WKF,
        'label' => $nameValue,
        'hasDataFileUri' => $newDataFileUri,
        'hasVersion' => $versionValue,
        'comment' => $commentValue,
        'hasSIRManagerEmail' => $useremail,
      ];
      $mtJSON = json_encode($mtData, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
      if (!is_string($mtJSON) || trim($mtJSON) === '') {
        \Drupal::messenger()->addError(t('WKF upload failed: could not encode WKF payload as JSON. Error: @err', [
          '@err' => json_last_error_msg(),
        ]));
        $this->redirectBackOrManageWkf($form_state);
        return;
      }

      $rawDatafileAdd = $api->datafileAdd($datafileJSON);
      $rawWkfAdd = $api->elementAdd('wkf', $mtJSON);

      $msg1 = $api->parseObjectResponse($rawDatafileAdd, 'datafileAdd');
      $msg2 = $api->parseObjectResponse($rawWkfAdd, 'elementAdd');

      if ($msg1 != NULL && $msg2 != NULL) {
        $session = \Drupal::request()->getSession();
        $session->set('rep_recent_uploaded_wkf_uri', $newMTUri);
        $session->set('rep_select_mt_status_filter.wkf', '_');
        $session->set('rep_select_mt_manager_filter.wkf', '');
        $this->resetUploadRuntimeCaches();

        // Confirm the WKF is resolvable in KG immediately after creation.
        $confirm = $api->parseObjectResponse($api->getUri($newMTUri), 'getUri');
        if (is_object($confirm) && !empty($confirm->uri)) {
          \Drupal::messenger()->addMessage(t('WKF has been added successfully. URI: @uri', ['@uri' => $newMTUri]));
        }
        else {
          \Drupal::messenger()->addWarning(t('Upload completed but WKF URI could not be confirmed in KG immediately. URI: @uri', ['@uri' => $newMTUri]));
        }
      }
      else {
        $error = trim((string) ($api->getErrorMessage() ?? ''));
        $raw1 = is_string($rawDatafileAdd) ? $rawDatafileAdd : json_encode($rawDatafileAdd);
        $raw2 = is_string($rawWkfAdd) ? $rawWkfAdd : json_encode($rawWkfAdd);
        $raw1 = substr((string) $raw1, 0, 300);
        $raw2 = substr((string) $raw2, 0, 300);
        \Drupal::messenger()->addError(t('WKF upload failed to create KG entities. API: @api RawDataFileAdd: @raw1 RawWKFAdd: @raw2', [
          '@api' => $error !== '' ? $error : 'no API error detail',
          '@raw1' => $raw1,
          '@raw2' => $raw2,
        ]));
      }

      $this->redirectBackOrManageWkf($form_state);
      return;
    }
    catch (\Exception $e) {
      \Drupal::messenger()->addError(t('An error occurred while uploading WKF: @msg', ['@msg' => $e->getMessage()]));
      $this->redirectBackOrManageWkf($form_state);
      return;
    }
  }

  /**
   * Redirect to previous page if tracked, otherwise to WKF manage card page.
   */
  protected function redirectBackOrManageWkf(FormStateInterface $form_state): void {
    $uid = \Drupal::currentUser()->id();
    \Drupal::request()->getSession()->set('rep_wkf_preserve_flash_messages', 1);
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'rep.add_mt');

    if (is_string($previousUrl) && $previousUrl !== '' && strpos($previousUrl, '/') === 0) {
      try {
        $form_state->setRedirectUrl(Url::fromUserInput($previousUrl));
        return;
      }
      catch (\Throwable $e) {
        // Fall through to default route below.
      }
    }

    $form_state->setRedirect('rep.select_wkf_element', [
      'mode' => 'card',
      'page' => 1,
      'pagesize' => 9,
      'studyuri' => 'none',
    ]);
  }

  /**
   * Build a unique filename for private storage to avoid stale path reuse.
   */
  protected function buildUniqueStoredFilename(string $originalFilename): string {
    $base = pathinfo($originalFilename, PATHINFO_FILENAME);
    $ext = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $base) ?? 'wkf_upload';
    $base = trim((string) $base, '_');
    if ($base === '') {
      $base = 'wkf_upload';
    }

    $suffix = date('YmdHis') . '_' . substr(hash('sha256', uniqid((string) mt_rand(), TRUE)), 0, 10);
    return $ext !== '' ? ($base . '__' . $suffix . '.' . $ext) : ($base . '__' . $suffix);
  }

  /**
   * Remove stale artifacts stored under legacy same-name URI.
   */
  protected function purgeLegacyUploadArtifacts(string $destinationDir, string $originalFilename): void {
    $legacyUri = rtrim($destinationDir, '/') . '/' . ltrim($originalFilename, '/');

    try {
      $existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $legacyUri]);
      if (is_array($existing)) {
        foreach ($existing as $entity) {
          if ($entity instanceof File) {
            $entity->delete();
          }
        }
      }
    }
    catch (\Throwable $e) {
      // Continue upload even if cleanup lookup fails.
    }

    try {
      $realDir = \Drupal::service('file_system')->realpath($destinationDir);
      if (is_string($realDir) && $realDir !== '') {
        $legacyRealPath = rtrim($realDir, '/') . '/' . $originalFilename;
        if (is_file($legacyRealPath)) {
          @unlink($legacyRealPath);
        }
      }
    }
    catch (\Throwable $e) {
      // Continue upload even if physical cleanup fails.
    }
  }

  /**
   * Reset runtime entity cache touched by upload write operations.
   */
  protected function resetUploadRuntimeCaches(): void {
    try {
      \Drupal::entityTypeManager()->getStorage('file')->resetCache();
    }
    catch (\Throwable $e) {
      // Best effort cache reset.
    }
  }
  /**
   * Capture binary fingerprint used to verify byte-for-byte copy integrity.
   *
   * @return array{ok:bool,size:int,sha256:string}
   */
  protected function captureBinaryFingerprint(string $path): array {
    $result = [
      'ok' => false,
      'size' => 0,
      'sha256' => '',
    ];

    if ($path === '' || !is_file($path) || !is_readable($path)) {
      return $result;
    }

    $size = filesize($path);
    $hash = hash_file('sha256', $path);
    if (!is_int($size) || !is_string($hash) || $hash === '') {
      return $result;
    }

    $result['ok'] = true;
    $result['size'] = $size;
    $result['sha256'] = $hash;
    return $result;
  }

}
