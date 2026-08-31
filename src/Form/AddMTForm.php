<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Constant;
use Drupal\rep\Entity\Tables;
use Drupal\rep\Vocabulary\HASCO;
use ZipArchive;

class AddMTForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_mt_form';
  }

  protected $elementType;

  protected $elementName;

  protected $elementTypeUri;

  protected $studyUri;

  protected $study;

  public function getElementType() {
    return $this->elementType;
  }

  public function setElementType($type) {
    return $this->elementType = $type;
  }

  public function getElementName() {
    return $this->elementName;
  }

  public function setElementName($name) {
    return $this->elementName = $name;
  }

  public function getElementTypeUri() {
    return $this->elementTypeUri;
  }

  public function setElementTypeUri($typeUri) {
    return $this->elementTypeUri = $typeUri;
  }

  public function getStudyUri() {
    return $this->studyUri;
  }

  public function setStudyUri($studyUri) {
    return $this->studyUri = $studyUri;
  }

  public function getStudy() {
    return $this->study;
  }

  public function setStudy($study) {
    return $this->study = $study;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = NULL, $studyuri = NULL, $fixstd = NULL) {

    $api = \Drupal::service('rep.api_connector');

    // Study Prefered name
    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';

    // HANDLE STUDYURI AND STUDY, IF ANY
    if ($studyuri != NULL) {
      if ($studyuri == 'none') {
        $this->setStudyUri(NULL);
      } else {
        $studyuri_decoded = base64_decode($studyuri);
        $this->setStudyUri($studyuri_decoded);
        $study = $api->parseObjectResponse($api->getUri($this->getStudyUri()),'getUri');
        if ($study == NULL) {
          \Drupal::messenger()->addMessage(t("Failed to retrieve ".$preferred_study."."));
          $response = new RedirectResponse($this->backUrl['rep.add_mt']);
          $response->send();
          return;
        } else {
          $this->setStudy($study);
        }
      }
    }

    // HANDLE ELEMENT TYPE
    if ($elementtype == NULL || $elementtype == '') {
      \Drupal::messenger()->addError(t("Metadata Template type cannot be empty."));
      $response = new RedirectResponse($this->backUrl['rep.add_mt']);
      $response->send();
      return;
    }

    if ($elementtype == 'da') {
      $this->setElementName('DA');
      $this->setElementTypeUri(HASCO::DATA_ACQUISITION);
    } else if ($elementtype == 'dd') {
      $this->setElementName('DD');
      $this->setElementTypeUri(HASCO::DD);
    } else if ($elementtype == 'dp2') {
      $this->setElementName('DP2');
      $this->setElementTypeUri(HASCO::DP2);
    } else if ($elementtype == 'dsg') {
      $this->setElementName('DSG');
      $this->setElementTypeUri(HASCO::DSG);
    } else if ($elementtype == 'ins') {
      $this->setElementName('INS');
      $this->setElementTypeUri(HASCO::INS);
    } else if ($elementtype == 'kgr') {
      $this->setElementName('KGR');
      $this->setElementTypeUri(HASCO::KGR);
    } else if ($elementtype == 'sdd') {
      $this->setElementName('SDD');
      $this->setElementTypeUri(HASCO::SDD);
    } else if ($elementtype == 'str') {
      $this->setElementName('STR');
      $this->setElementTypeUri(HASCO::STR);
    } else if ($elementtype == 'wkf') {
      $this->setElementName('WKF');
      $this->setElementTypeUri(HASCO::WKF);
    } else {
      \Drupal::messenger()->addError(t("<b>".$elementtype . "</b> is not a valid Metadata Template type."));
      self::backUrl();
    }

    $this->setElementType($elementtype);

    //dpm(basename($this->getStudy()->uri));


    $study = ' ';
    if ($this->getStudy() != NULL &&
        $this->getStudy()->uri != NULL &&
        $this->getStudy()->label != NULL) {
      $study = Utils::fieldToAutocomplete($this->getStudy()->uri,$this->getStudy()->label);
    }

    $form['page_title'] = [
      '#type' => 'item',
      '#title' => $this->t('<h1>Add ' . $this->getElementName() . '</h1>'),
    ];
    if ($this->getElementType() == 'da') {
      if ($fixstd == 'T') {
        $form['mt_study'] = [
          '#type' => 'textfield',
          '#title' => $this->t($preferred_study),
          '#default_value' => $study,
          '#disabled' => TRUE,
        ];
      } else {
        $form['mt_study'] = [
          '#type' => 'textfield',
          '#title' => $this->t($preferred_study),
          '#default_value' => $study,
          '#autocomplete_route_name' => 'std.study_autocomplete',
        ];
      }
    }
    $form['mt_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
    ];
    // if ($this->getElementType() == 'da') {
    //   $form['mt_filename'] = [
    //     '#type' => 'managed_file',
    //     '#title' => $this->t('File Upload'),
    //     '#description' => $this->t('Upload a file.'),
    //     //'#upload_location' => 'public://uploads/',
    //     '#upload_location' => 'private://'..'/',
    //     '#upload_validators' => [
    //       'file_validate_extensions' => ['csv'],
    //     ],
    //   ];
    // } else {
    //   $form['mt_filename'] = [
    //     '#type' => 'managed_file',
    //     '#title' => $this->t('File Upload'),
    //     '#description' => $this->t('Upload a file.'),
    //     '#upload_location' => 'public://uploads/',
    //     '#upload_validators' => [
    //       'file_validate_extensions' => ['xlsx'],
    //     ],
    //   ];
    // }

    if ($this->getElementType() == 'da') {
      $form['mt_filename'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('File Upload'),
        '#description' => $this->t('Upload a file.'),
        '#upload_location' => 'private://upload/std/'.basename($this->getStudy()->uri).'/'.$this->getElementType().'/',
        '#upload_validators' => [
          'file_validate_extensions' => ['csv'],
        ],
      ];
    } else {
      if ($this->getElementType() == 'kgr') {
        $upload_path = 'private://social/' . $this->getElementType() . '/';
      } else {
        $upload_path = 'private://' . $this->getElementType() . '/';
      }

      if (!\Drupal::service('file_system')->prepareDirectory($upload_path, FileSystemInterface::CREATE_DIRECTORY)) {
        \Drupal::messenger()->addError(t("Upload directory could not be prepared: " . $upload_path));
        return;
      }
      $form['mt_filename'] = [
        '#type' => 'file',
        '#title' => $this->t('File Upload'),
        '#description' => $this->t('Upload a file (.xlsx only).'),
        '#attributes' => [
          'accept' => '.xlsx',
        ],
      ];

      if ($this->getElementType() === 'wkf') {
        $form['mt_copy_name'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('copy name'),
          '#description' => $this->t('If checked, copies the uploaded filename stem into both Name and Comment. Example: WKF-BIOPSY.xlsx -> BIOPSY.'),
          '#default_value' => 1,
        ];
      }

    }

    // if ($this->getElementType() == 'da') {
    //   $form['mt_dd'] = [
    //     '#type' => 'textfield',
    //     '#title' => $this->t('Data Dictionary (DD)'),
    //     #'#default_value' => $study,
    //     '#autocomplete_route_name' => 'rep.dd_autocomplete',
    //   ];
    //   $form['mt_sdd'] = [
    //     '#type' => 'textfield',
    //     '#title' => $this->t('Semantic Data Dictionary (SDD)'),
    //     '#autocomplete_route_name' => 'rep.sdd_autocomplete',
    //   ];
    // }

    $form['mt_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#default_value' => $this->getElementType() === 'wkf' ? '1' : '',
      '#disabled' => $this->getElementType() === 'wkf',
    ];
    //if ($this->getElementType() == 'da') {
    //}
    $form['mt_comment'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Comment'),
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

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'save' && $this->getElementType() === 'wkf' && $form_state->getValue('mt_copy_name')) {
      $uploaded_name = $_FILES['files']['name']['mt_filename'] ?? '';
      $copied_name = $this->extractNameFromFilename((string) $uploaded_name);
      if ($copied_name !== '') {
        $form_state->setValue('mt_name', $copied_name);
        $form_state->setValue('mt_comment', $copied_name);
      }
    }

    if ($button_name === 'save') {
      if(strlen($form_state->getValue('mt_name')) < 1) {
        $form_state->setErrorByName('mt_name', $this->t('Please enter a valid name for the ' . $this->getElementName()));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      $this->redirectBack($form_state);
      return;
    }

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

      // Validate that a file was uploaded
      if (empty($_FILES['files']['name']['mt_filename'])) {
        \Drupal::messenger()->addError(t('Please upload a file.'));
        return;
      }

      $file_info = $_FILES['files'];
      $tmp_name = $file_info['tmp_name']['mt_filename'];
      $original_name = $file_info['name']['mt_filename'];
      $original_filename = basename((string) $original_name);
      $uploadedFingerprint = $this->captureBinaryFingerprint($tmp_name);

      if ($this->getElementType() === 'wkf' && $form_state->getValue('mt_copy_name')) {
        $copied_name = $this->extractNameFromFilename((string) $original_name);
        if ($copied_name !== '') {
          $form_state->setValue('mt_name', $copied_name);
          $form_state->setValue('mt_comment', $copied_name);
        }
      }

      // Determine destination directory
      if ($this->getElementType() == 'kgr') {
        $destination_dir = 'private://social/' . $this->getElementType();
      } else {
        $destination_dir = 'private://' . $this->getElementType();
      }

      // Ensure directory exists
      $file_system = \Drupal::service('file_system');
      if (!$file_system->prepareDirectory($destination_dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY)) {
        \Drupal::messenger()->addError(t("Upload directory could not be prepared: " . $destination_dir));
        return;
      }

      // Move uploaded file
      $this->purgeLegacyUploadArtifacts($destination_dir, $original_filename);
      $stored_filename = $this->buildUniqueStoredFilename($original_filename);
      $destination_real = $file_system->realpath($destination_dir) . '/' . $stored_filename;

      if (!move_uploaded_file($tmp_name, $destination_real)) {
        \Drupal::messenger()->addError(t("Failed to move uploaded file. Check permissions for @dir", ['@dir' => $destination_real]));
        return;
      }

      // Confirm file actually exists at destination
      if (!file_exists($destination_real)) {
        \Drupal::messenger()->addError(t("File move failed - destination file does not exist: @path", ['@path' => $destination_real]));
        return;
      }

      $storedFingerprint = $this->captureBinaryFingerprint($destination_real);
      $isExactCopy = $uploadedFingerprint['ok']
        && $storedFingerprint['ok']
        && $uploadedFingerprint['size'] === $storedFingerprint['size']
        && hash_equals((string) $uploadedFingerprint['sha256'], (string) $storedFingerprint['sha256']);
      if (!$isExactCopy) {
        if (is_file($destination_real)) {
          @unlink($destination_real);
        }
        \Drupal::logger('rep')->error('WKF upload integrity check failed in AddMTForm. file=@file uploaded_size=@uploaded_size stored_size=@stored_size uploaded_sha256=@uploaded_hash stored_sha256=@stored_hash', [
          '@file' => $original_filename,
          '@uploaded_size' => (string) ($uploadedFingerprint['size'] ?? 0),
          '@stored_size' => (string) ($storedFingerprint['size'] ?? 0),
          '@uploaded_hash' => (string) ($uploadedFingerprint['sha256'] ?? ''),
          '@stored_hash' => (string) ($storedFingerprint['sha256'] ?? ''),
        ]);
        \Drupal::messenger()->addError(t('Upload failed integrity check: private copy differs from uploaded bytes. Please retry upload.'));
        $this->redirectBack($form_state);
        return;
      }

      if ($this->getElementType() === 'wkf') {
        $hashCheck = $this->checkCanonicalWkfHashMismatch($destination_real, $original_filename);
        if (!empty($hashCheck['mismatch'])) {
          if (is_file($destination_real)) {
            @unlink($destination_real);
          }
          \Drupal::messenger()->addError(t('WKF upload rejected: selected file does not match canonical workspace WKF content for @file. Uploaded SHA1=@uploaded ; Canonical SHA1=@canonical ; Canonical path=@path', [
            '@file' => $original_filename,
            '@uploaded' => (string) ($hashCheck['uploaded_sha1'] ?? ''),
            '@canonical' => (string) ($hashCheck['canonical_sha1'] ?? ''),
            '@path' => (string) ($hashCheck['canonical_path'] ?? ''),
          ]));
          $this->redirectBack($form_state);
          return;
        }
      }

      // If we reach here, upload succeeded
      $filename = $original_filename;

      // Build the Drupal file URI (not the absolute path)
      $drupal_uri = $destination_dir . '/' . $stored_filename;

      // Create a File entity for Drupal tracking
      $file_entity = File::create([
        'uri' => $drupal_uri,
        'filename' => $filename,
        'status' => FileInterface::STATUS_PERMANENT,
        'uid' => \Drupal::currentUser()->id(),
      ]);

      $file_entity->save();
      $file_id = $file_entity->id(); // This is your $fid

      // Mark this file as local origin (user uploaded, not from API).
      $originMgr = \Drupal::service('rep.file_origin_manager');
      $originMgr->markLocal((int) $file_id);

      $api = \Drupal::service('rep.api_connector');

      // Build URIs (same as before)
      $ddUri = NULL;
      if ($form_state->getValue('mt_dd') != NULL && $form_state->getValue('mt_dd') != '') {
        $ddUri = Utils::uriFromAutocomplete($form_state->getValue('mt_dd'));
      }

      $sddUri = NULL;
      if ($form_state->getValue('mt_sdd') != NULL && $form_state->getValue('mt_sdd') != '') {
        $sddUri = Utils::uriFromAutocomplete($form_state->getValue('mt_sdd'));
      }

      $targetOrganizationUri = '';
      $stdPrincipalInvestigatorUri = '';
      if ($this->getElementType() === 'wkf') {
        $wkfWorkbookMeta = [];
        try {
          $metadataJson = (string) \Drupal::service('ctt.wkf_metadata_extractor')->extractMetadataJsonFromFile($destination_real);
          $decoded = json_decode($metadataJson, TRUE);
          $wkfWorkbookMeta = is_array($decoded) ? $decoded : [];
        }
        catch (\Throwable $e) {
          \Drupal::logger('rep')->warning('WKF metadata extraction service failed in AddMTForm for {path}: {message}', [
            'path' => $destination_real,
            'message' => $e->getMessage(),
          ]);
        }

        $targetOrganizationUri = $this->normalizeUriUsingRepUtils((string) ($wkfWorkbookMeta['organization_uri'] ?? ''));
        $stdPrincipalInvestigatorUri = $this->normalizeUriUsingRepUtils((string) ($wkfWorkbookMeta['principal_investigator_uri'] ?? ''));

        $fallbackOwnership = $this->resolveCurrentUserOwnershipUris($api, $useremail);
        $fallbackApplied = FALSE;

        if ($stdPrincipalInvestigatorUri === '' && (($fallbackOwnership['principal_investigator_uri'] ?? '') !== '')) {
          $stdPrincipalInvestigatorUri = (string) $fallbackOwnership['principal_investigator_uri'];
          $fallbackApplied = TRUE;
        }

        if ($targetOrganizationUri === '' && (($fallbackOwnership['organization_uri'] ?? '') !== '')) {
          $targetOrganizationUri = (string) $fallbackOwnership['organization_uri'];
          $fallbackApplied = TRUE;
        }

        if ($fallbackApplied) {
          \Drupal::logger('rep')->warning('WKF ownership fallback applied in AddMTForm for {file}. Using current user ownership where STD URIs were missing/invalid. PI={pi} ORG={org}', [
            'file' => $original_filename,
            'pi' => $stdPrincipalInvestigatorUri,
            'org' => $targetOrganizationUri,
          ]);
        }
      }

      // Send data to your API connector.
      if ($this->getElementType() === 'wkf') {
        $targetOrganizationUri = $this->normalizeUriUsingRepUtils($targetOrganizationUri);
        // Keep uploader provenance in hasSIRManagerEmail. STD ownership fields are
        // used for study identity validation, not uploader attribution.
      }

      $versionValue = (string) $form_state->getValue('mt_version');
      if ($this->getElementType() === 'wkf') {
        $versionValue = '1';
      }

      // Build DATAFILE JSON
      $newDataFileUri = Utils::uriGen('datafile');
      $datafileData = [
        "uri" => $newDataFileUri,
        "typeUri" => HASCO::DATAFILE,
        "hascoTypeUri" => HASCO::DATAFILE,
        "label" => $form_state->getValue('mt_name'),
        "filename" => $filename,
        "fileStatus" => Constant::FILE_STATUS_UNPROCESSED,
        "hasSIRManagerEmail" => $useremail,
        "id" => $file_id,
      ];
      if ($this->getElementType() === 'wkf' && $targetOrganizationUri !== '') {
        $datafileData['ingestionOrganizationUri'] = $targetOrganizationUri;
      }
      $datafileJSON = json_encode($datafileData, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
      if (!is_string($datafileJSON) || trim($datafileJSON) === '') {
        \Drupal::messenger()->addError(t('Failed to add @name: DataFile payload JSON encoding failed. Error: @err', [
          '@name' => $this->getElementName(),
          '@err' => json_last_error_msg(),
        ]));
        $this->redirectBack($form_state);
        return;
      }

      // Build MT JSON
      $newMTUri = str_replace("DFL", Utils::elementPrefix($this->getElementType()), $newDataFileUri);
      $mtData = [
        "uri" => $newMTUri,
        "typeUri" => $this->getElementTypeUri(),
        "hascoTypeUri" => $this->getElementTypeUri(),
        "label" => $form_state->getValue('mt_name'),
        "hasDataFileUri" => $newDataFileUri,
        "hasVersion" => $versionValue,
        "comment" => $form_state->getValue('mt_comment'),
        "hasSIRManagerEmail" => $useremail,
      ];

      if ($this->getElementType() == 'da' && $this->getStudy()) {
        $mtData["isMemberOfUri"] = $this->getStudy()->uri;
      }

      if ($this->getElementType() == 'str' && $this->getStudy()) {
        $mtData["studyUri"] = $this->getStudy()->uri;
      }

      if ($ddUri != NULL) {
        $mtData["hasDDUri"] = $ddUri;
      }
      if ($sddUri != NULL) {
        $mtData["hasSDDUri"] = $sddUri;
      }

      $mtJSON = json_encode($mtData, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
      if (!is_string($mtJSON) || trim($mtJSON) === '') {
        \Drupal::messenger()->addError(t('Failed to add @name: metadata payload JSON encoding failed. Error: @err', [
          '@name' => $this->getElementName(),
          '@err' => json_last_error_msg(),
        ]));
        $this->redirectBack($form_state);
        return;
      }

      $rawDatafileAdd = $api->datafileAdd($datafileJSON);
      $rawElementAdd = $api->elementAdd($this->getElementType(), $mtJSON);
      $msg1 = $api->parseObjectResponse($rawDatafileAdd, 'datafileAdd');
      $msg2 = $api->parseObjectResponse($rawElementAdd, 'elementAdd');

      if ($msg1 != NULL && $msg2 != NULL) {
        if ($this->getElementType() === 'wkf') {
          $session = \Drupal::request()->getSession();
          $session->set('rep_recent_uploaded_wkf_uri', $newMTUri);
          $session->set('rep_select_mt_status_filter.wkf', '_');
          $session->set('rep_select_mt_manager_filter.wkf', '');
        }
        $this->resetUploadRuntimeCaches();
        \Drupal::messenger()->addMessage(t($this->getElementName() . " has been added successfully."));
      } else {
        $error = trim((string) ($api->getErrorMessage() ?? ''));
        $raw1 = is_string($rawDatafileAdd) ? $rawDatafileAdd : json_encode($rawDatafileAdd);
        $raw2 = is_string($rawElementAdd) ? $rawElementAdd : json_encode($rawElementAdd);
        $raw1 = substr((string) $raw1, 0, 300);
        $raw2 = substr((string) $raw2, 0, 300);
        \Drupal::messenger()->addError(t('Failed to add @name. API: @api RawDataFileAdd: @raw1 RawElementAdd: @raw2', [
          '@name' => $this->getElementName(),
          '@api' => $error !== '' ? $error : 'no API error detail',
          '@raw1' => $raw1,
          '@raw2' => $raw2,
        ]));
      }

      $this->redirectBack($form_state);
      return;

    } catch (\Exception $e) {
      \Drupal::messenger()->addError(t("An error occurred while adding an ". $this->getElementName() . ": ".$e->getMessage()));
      $this->redirectBack($form_state);
      return;
    }
  }

  protected function redirectBack(FormStateInterface $form_state): void {
    $uid = \Drupal::currentUser()->id();
    if ($this->elementType === 'wkf') {
      \Drupal::request()->getSession()->set('rep_wkf_preserve_flash_messages', 1);
    }
    if ($this->elementType != 'da')
      $previousUrl = Utils::trackingGetPreviousUrl($uid, 'rep.add_mt');
    else
      $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.manage_study_elements');

    if (is_string($previousUrl) && $previousUrl !== '' && strpos($previousUrl, '/') === 0) {
      try {
        $form_state->setRedirectUrl(Url::fromUserInput($previousUrl));
        return;
      }
      catch (\Throwable $e) {
        // Fall through to default fallback below.
      }
    }

    if ($this->elementType === 'wkf') {
      $form_state->setRedirect('rep.select_wkf_element', [
        'mode' => 'card',
        'page' => 1,
        'pagesize' => 9,
        'studyuri' => 'none',
      ]);
      return;
    }

    $form_state->setRedirect('rep.select_mt_element', [
      'elementtype' => $this->elementType,
      'mode' => 'table',
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
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $base) ?? 'upload';
    $base = trim((string) $base, '_');
    if ($base === '') {
      $base = 'upload';
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
   * Reject same-name WKF uploads when uploaded bytes differ from canonical
   * pmsrgui/wkf file content.
   *
   * @return array{mismatch:bool,uploaded_sha1:string,canonical_sha1:string,canonical_path:string}
   */
  protected function checkCanonicalWkfHashMismatch(string $uploadedPath, string $originalFilename): array {
    $result = [
      'mismatch' => false,
      'uploaded_sha1' => '',
      'canonical_sha1' => '',
      'canonical_path' => '',
    ];

    if ($uploadedPath === '' || !is_file($uploadedPath) || !is_readable($uploadedPath)) {
      return $result;
    }

    $canonicalPath = $this->resolveCanonicalWkfPath($originalFilename);

    if ($canonicalPath === '') {
      return $result;
    }

    $uploadedSha1 = sha1_file($uploadedPath);
    $canonicalSha1 = sha1_file($canonicalPath);
    $result['uploaded_sha1'] = is_string($uploadedSha1) ? $uploadedSha1 : '';
    $result['canonical_sha1'] = is_string($canonicalSha1) ? $canonicalSha1 : '';
    $result['canonical_path'] = $canonicalPath;

    if ($result['uploaded_sha1'] !== '' && $result['canonical_sha1'] !== '' && $result['uploaded_sha1'] !== $result['canonical_sha1']) {
      $result['mismatch'] = true;
      \Drupal::logger('rep')->warning('WKF hash mismatch rejected in AddMTForm. file=@file uploaded_sha1=@uploaded canonical_sha1=@canonical canonical_path=@path', [
        '@file' => $originalFilename,
        '@uploaded' => $result['uploaded_sha1'],
        '@canonical' => $result['canonical_sha1'],
        '@path' => $canonicalPath,
      ]);
    }

    return $result;
  }

  /**
   * Resolve canonical WKF workbook path for same-name hash checks.
   */
  protected function resolveCanonicalWkfPath(string $originalFilename): string {
    $candidates = [];

    // Prefer enabled-module discovery when available.
    try {
      $modulePath = \Drupal::service('extension.list.module')->getPath('pmsrgui');
      if (is_string($modulePath) && trim($modulePath) !== '') {
        $candidates[] = DRUPAL_ROOT . '/' . trim($modulePath, '/') . '/wkf/' . $originalFilename;
      }
    }
    catch (\Throwable $e) {
      // Fall back to deterministic filesystem locations below.
    }

    $candidates[] = DRUPAL_ROOT . '/modules/custom/pmsrgui/wkf/' . $originalFilename;
    $candidates[] = dirname(DRUPAL_ROOT) . '/web/modules/custom/pmsrgui/wkf/' . $originalFilename;

    foreach ($candidates as $candidate) {
      if (is_file($candidate) && is_readable($candidate)) {
        return $candidate;
      }
    }

    return '';
  }

  /**
   * Build a display name from uploaded WKF filename.
   */
  protected function extractNameFromFilename(string $filename): string {
    $base = pathinfo(trim($filename), PATHINFO_FILENAME);
    if ($base === '') {
      return '';
    }

    // Remove conventional WKF prefix and normalize separators.
    $name = preg_replace('/^WKF[-_\s]*/i', '', $base) ?? $base;
    $name = preg_replace('/[-_]+/', ' ', $name) ?? $name;
    return trim($name);
  }


  /**
   * Normalize an identity URI using shared rep Utils helpers.
   */
  protected function normalizeUriUsingRepUtils(string $uri): string {
    $candidate = trim($uri);
    if ($candidate === '') {
      return '';
    }

    $expanded = Utils::plainUri($candidate);
    if (is_string($expanded) && trim($expanded) !== '') {
      $candidate = trim($expanded);
    }

    $candidate = Utils::canonicalizePmsrUri($candidate);
    if ($candidate === '' || preg_match('#^https?://#i', $candidate) !== 1) {
      return '';
    }

    return filter_var($candidate, FILTER_VALIDATE_URL) !== FALSE ? $candidate : '';
  }

  /**
   * Resolve fallback ownership from current user's person and affiliation.
   *
   * @return array{principal_investigator_uri:string,organization_uri:string}
   */
  protected function resolveCurrentUserOwnershipUris($api, string $userEmail): array {
    $result = [
      'principal_investigator_uri' => '',
      'organization_uri' => '',
    ];

    $person = $this->pickCurrentUserPersonByEmail($api, $userEmail);
    if (!is_object($person)) {
      return $result;
    }

    if (isset($person->uri) && is_string($person->uri)) {
      $result['principal_investigator_uri'] = $this->normalizeUriUsingRepUtils((string) $person->uri);
    }

    $affiliationUri = $this->extractPersonAffiliationUri($person);
    if ($affiliationUri !== '') {
      $result['organization_uri'] = $this->normalizeUriUsingRepUtils($affiliationUri);
    }

    return $result;
  }

  /**
   * Select best person record matching uploader email.
   */
  protected function pickCurrentUserPersonByEmail($api, string $userEmail) {
    $normalizedEmail = strtolower(trim($userEmail));
    if ($normalizedEmail === '') {
      return NULL;
    }

    $candidates = [];
    try {
      $rawPeople = $api->listByManagerEmail('person', $normalizedEmail, 200, 0);
      $people = $api->parseObjectResponse($rawPeople, 'listByManagerEmail');
      if (is_array($people)) {
        $candidates = $people;
      }
    }
    catch (\Throwable $e) {
      $candidates = [];
    }

    foreach ($candidates as $person) {
      if (!is_object($person)) {
        continue;
      }
      $personEmail = strtolower($this->extractPersonEmail($person));
      if ($personEmail !== '' && $personEmail === $normalizedEmail) {
        return $person;
      }
    }

    try {
      $rawAllPeople = $api->listByKeyword('person', '_', 500, 0);
      $allPeople = $api->parseObjectResponse($rawAllPeople, 'listByKeyword');
      if (is_array($allPeople)) {
        foreach ($allPeople as $person) {
          if (!is_object($person)) {
            continue;
          }
          $personEmail = strtolower($this->extractPersonEmail($person));
          if ($personEmail !== '' && $personEmail === $normalizedEmail) {
            return $person;
          }
        }
      }
    }
    catch (\Throwable $e) {
      return NULL;
    }

    return NULL;
  }

  /**
   * Extract one usable email from a person payload.
   */
  protected function extractPersonEmail($person): string {
    if (!is_object($person)) {
      return '';
    }

    foreach (['hasEmail', 'email', 'mbox', 'hasSIRManagerEmail'] as $field) {
      if (!isset($person->{$field})) {
        continue;
      }

      $value = $person->{$field};
      if (is_string($value)) {
        $candidate = trim($value);
        if ($candidate !== '') {
          return $candidate;
        }
      }
      elseif (is_array($value)) {
        foreach ($value as $entry) {
          if (!is_string($entry)) {
            continue;
          }
          $candidate = trim($entry);
          if ($candidate !== '') {
            return $candidate;
          }
        }
      }
    }

    return '';
  }

  /**
   * Extract affiliation URI from a person payload.
   */
  protected function extractPersonAffiliationUri($person): string {
    if (!is_object($person)) {
      return '';
    }

    if (isset($person->hasAffiliationUri) && is_string($person->hasAffiliationUri)) {
      return trim((string) $person->hasAffiliationUri);
    }

    if (isset($person->hasAffiliation) && is_object($person->hasAffiliation)
      && isset($person->hasAffiliation->uri) && is_string($person->hasAffiliation->uri)) {
      return trim((string) $person->hasAffiliation->uri);
    }

    return '';
  }

  /**
   * Capture binary fingerprint used to enforce exact copy persistence.
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

  /**
   * Detect placeholder ownership URIs that must not be persisted.
   */
  protected function isPlaceholderStdIdentityUri(string $uri): bool {
    $value = strtolower(trim($uri));
    if ($value === '') {
      return false;
    }

    $plain = Utils::plainUri($value);
    if (is_string($plain) && trim($plain) !== '') {
      $value = strtolower(trim($plain));
    }

    return in_array($value, [
      'https://pmsr.net/ont/org/ess',
      'https://pmsr.net/ont/per/pi-001',
      'pmsr:org/ess',
      'pmsr:per/pi-001',
    ], TRUE);
  }

  /**
   * Extract WKF STD ownership hints from workbook.
   */
  protected function extractWkfOwnershipMetadataFromWorkbook(string $filePath): array {
    $result = [
      'organization_uri' => '',
      'principal_investigator_uri' => '',
      'principal_investigator_email' => '',
    ];

    if (!is_file($filePath)) {
      return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
      return $result;
    }

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (!is_string($workbookXml) || !is_string($relsXml)) {
      $zip->close();
      return $result;
    }

    $sharedStrings = $this->extractXlsxSharedStringsRaw($zip);
    $sheetTargets = $this->extractWorkbookSheetTargets($workbookXml, $relsXml);
    $namespaceMap = [];

    if (isset($sheetTargets['Namespaces'])) {
      $nsSheetPath = $sheetTargets['Namespaces'];
      if (strpos($nsSheetPath, 'xl/') !== 0) {
        $nsSheetPath = 'xl/' . ltrim($nsSheetPath, '/');
      }

      $nsXml = $zip->getFromName($nsSheetPath);
      if (is_string($nsXml) && trim($nsXml) !== '') {
        $nsRows = $this->extractSheetRowsRaw($nsXml, $sharedStrings);
        $namespaceMap = $this->extractNamespaceMapFromRows($nsRows);
      }
    }
    if (!isset($sheetTargets['STD'])) {
      $zip->close();
      return $result;
    }

    $stdSheetPath = $sheetTargets['STD'];
    if (strpos($stdSheetPath, 'xl/') !== 0) {
      $stdSheetPath = 'xl/' . ltrim($stdSheetPath, '/');
    }

    $stdXml = $zip->getFromName($stdSheetPath);
    if (!is_string($stdXml) || trim($stdXml) === '') {
      $zip->close();
      return $result;
    }

    $stdRows = $this->extractSheetRowsRaw($stdXml, $sharedStrings);
    $zip->close();
    $ownership = $this->extractStdOwnershipFromRows($stdRows);
    if (!empty($ownership['organization_uri'])) {
      $ownership['organization_uri'] = $this->expandPrefixedWorkbookUri((string) $ownership['organization_uri'], $namespaceMap);
    }
    if (!empty($ownership['principal_investigator_uri'])) {
      $ownership['principal_investigator_uri'] = $this->expandPrefixedWorkbookUri((string) $ownership['principal_investigator_uri'], $namespaceMap);
    }
    return $ownership;
  }

  /**
   * Extract STD ownership URIs by scanning for header/value row pairs.
   */
  protected function extractStdOwnershipFromRows(array $rows): array {
    $result = [
      'organization_uri' => '',
      'principal_investigator_uri' => '',
      'principal_investigator_email' => '',
    ];

    if (count($rows) < 2) {
      return $result;
    }

    $organizationHeaders = [
      'institution',
      'hasco:hasinstitution',
      'hasinstitution',
      'organization',
      'organization uri',
      'hasorganizationuri',
    ];

    $piHeaders = [
      'principal investigator',
      'principal investigator uri',
      'hasco:haspi',
      'haspi',
      'pi',
    ];

    $piEmailHeaders = [
      'email',
      'principal investigator email',
      'pi email',
      'hasco:hasemail',
      'hasemail',
      'mbox',
    ];

    $total = count($rows);
    $headerRowIdx = -1;
    $headerMap = [];

    for ($i = 0; $i < $total; $i++) {
      $headers = $rows[$i];
      if (!is_array($headers) || count($headers) < 2) {
        continue;
      }

      $tokenSet = [];
      $localHeaderMap = [];
      foreach ($headers as $idx => $header) {
        $token = $this->normalizeHeaderToken((string) $header);
        if ($token === '') {
          continue;
        }
        if (!array_key_exists($token, $localHeaderMap)) {
          $localHeaderMap[$token] = (int) $idx;
        }
        $tokenSet[$token] = TRUE;
      }

      $isStdHeaderRow = isset($tokenSet['hasuri'])
        && (isset($tokenSet['institution']) || isset($tokenSet['hasco:hasinstitution']) || isset($tokenSet['organization']) || isset($tokenSet['organization uri']) || isset($tokenSet['hasorganizationuri']))
        && (isset($tokenSet['principal investigator']) || isset($tokenSet['principal investigator uri']) || isset($tokenSet['hasco:haspi']) || isset($tokenSet['haspi']) || isset($tokenSet['pi']));
      if (!$isStdHeaderRow) {
        continue;
      }

      $headerRowIdx = $i;
      $headerMap = $localHeaderMap;
      break;
    }

    if ($headerRowIdx < 0 || empty($headerMap)) {
      return $result;
    }

    $pickFirstValue = static function (array $tokens, array $map, array $row): string {
      foreach ($tokens as $token) {
        if (!isset($map[$token])) {
          continue;
        }
        $idx = $map[$token];
        $value = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
        if ($value !== '') {
          return $value;
        }
      }
      return '';
    };

    $dataRowIdx = -1;
    $bestScore = -999;
    $hasUriIdx = $headerMap['hasuri'] ?? -1;
    for ($j = $headerRowIdx + 1; $j < min($total, $headerRowIdx + 25); $j++) {
      $candidate = $rows[$j] ?? NULL;
      if (!is_array($candidate)) {
        continue;
      }

      $hasUri = $hasUriIdx >= 0 && isset($candidate[$hasUriIdx])
        ? trim((string) $candidate[$hasUriIdx])
        : '';
      $orgValue = $pickFirstValue($organizationHeaders, $headerMap, $candidate);
      $piValue = $pickFirstValue($piHeaders, $headerMap, $candidate);

      if ($hasUri === '' && $orgValue === '' && $piValue === '') {
        continue;
      }

      $score = 0;
      if ($hasUri !== '') {
        $score += 1;
      }
      if ($orgValue !== '') {
        $score += 1;
      }
      if ($piValue !== '') {
        $score += 1;
      }

      $orgIsPlaceholder = $orgValue !== '' && $this->isPlaceholderStdIdentityUri($orgValue);
      $piIsPlaceholder = $piValue !== '' && $this->isPlaceholderStdIdentityUri($piValue);
      if (!$orgIsPlaceholder && !$piIsPlaceholder && ($orgValue !== '' || $piValue !== '')) {
        $score += 4;
      }
      if ($orgIsPlaceholder) {
        $score -= 2;
      }
      if ($piIsPlaceholder) {
        $score -= 2;
      }

      if ($score > $bestScore) {
        $bestScore = $score;
        $dataRowIdx = $j;
      }
    }

    if ($dataRowIdx < 0 || $bestScore < 0) {
      return $result;
    }

    $values = is_array($rows[$dataRowIdx]) ? $rows[$dataRowIdx] : [];

    $result['organization_uri'] = $pickFirstValue($organizationHeaders, $headerMap, $values);
    $result['principal_investigator_uri'] = $pickFirstValue($piHeaders, $headerMap, $values);
    $result['principal_investigator_email'] = $pickFirstValue($piEmailHeaders, $headerMap, $values);

    return $result;
  }

  /**
   * Normalize header token for matching.
   */
  protected function normalizeHeaderToken(string $header): string {
    $token = trim(strtolower($header));
    if ($token === '') {
      return '';
    }
    $token = preg_replace('/\s+/', ' ', $token) ?? $token;
    return trim($token);
  }

  /**
   * Extract namespace prefix map from Namespaces sheet rows.
   *
   * @return array<string, string>
   */
  protected function extractNamespaceMapFromRows(array $rows): array {
    $map = [];
    if (count($rows) < 2) {
      return $map;
    }

    $headers = $rows[0];
    $prefixIdx = -1;
    $uriIdx = -1;

    foreach ($headers as $idx => $header) {
      $token = $this->normalizeHeaderToken((string) $header);
      if ($token === 'prefix' || $token === 'namespace prefix') {
        $prefixIdx = (int) $idx;
      }
      if ($token === 'namespace uri' || $token === 'uri' || $token === 'namespace') {
        $uriIdx = (int) $idx;
      }
    }

    if ($prefixIdx < 0 || $uriIdx < 0) {
      return $map;
    }

    for ($i = 1; $i < count($rows); $i++) {
      $row = $rows[$i];
      if (!is_array($row)) {
        continue;
      }

      $prefix = isset($row[$prefixIdx]) ? strtolower(trim((string) $row[$prefixIdx])) : '';
      $uri = isset($row[$uriIdx]) ? trim((string) $row[$uriIdx]) : '';
      if ($prefix === '' || $uri === '') {
        continue;
      }

      if (!str_ends_with($uri, '/') && !str_ends_with($uri, '#')) {
        $uri .= '/';
      }
      $map[$prefix] = $uri;
    }

    return $map;
  }

  /**
   * Expand compact URI values (e.g., pmsr:PER123) using namespace map.
   */
  protected function expandPrefixedWorkbookUri(string $value, array $namespaceMap): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    if (preg_match('#^https?://#i', $value) === 1) {
      return \Drupal\rep\Utils::canonicalizePmsrUri($value);
    }

    if (preg_match('/^([A-Za-z][A-Za-z0-9_\-]*):(\S+)$/', $value, $m) === 1) {
      $prefix = strtolower((string) $m[1]);
      $local = (string) $m[2];

      if ($prefix === 'pmsr') {
        return 'https://pmsr.net/ont/' . ltrim($local, '/');
      }

      if (isset($namespaceMap[$prefix]) && $namespaceMap[$prefix] !== '') {
        return $namespaceMap[$prefix] . ltrim($local, '/');
      }

      // Avoid external namespace fallback during upload parsing.
      return $value;
    }

    return $value;
  }

  /**
   * Expand CURIE values using hascoapi domain Namespaces map.
   */
  protected function expandUsingDomainNamespaces(string $prefix, string $local): string {
    static $domainNamespaces = NULL;
    if ($domainNamespaces === NULL) {
      $domainNamespaces = [];
      $namespaces = (new Tables())->getNamespaces();
      if (is_array($namespaces)) {
        foreach ($namespaces as $abbr => $baseUri) {
          $abbr = strtolower(trim((string) $abbr));
          $baseUri = trim((string) $baseUri);
          if ($abbr === '' || $baseUri === '') {
            continue;
          }
          $domainNamespaces[$abbr] = $baseUri;
        }
      }
    }

    if (!isset($domainNamespaces[$prefix])) {
      return '';
    }

    $expanded = $domainNamespaces[$prefix] . ltrim($local, '/');
    return Utils::canonicalizePmsrUri($expanded);
  }

  /**
   * Read workbook sheet names to paths using workbook relationships.
   */
  protected function extractWorkbookSheetTargets(string $workbookXml, string $relsXml): array {
    $ridToTarget = [];
    if (preg_match_all('/<Relationship[^>]*\bId="([^"]+)"[^>]*\bTarget="([^"]+)"/i', $relsXml, $relsMatches, PREG_SET_ORDER) === 1 || !empty($relsMatches)) {
      foreach ($relsMatches as $m) {
        $ridToTarget[$m[1]] = html_entity_decode($m[2], ENT_QUOTES | ENT_XML1);
      }
    }

    $sheetTargets = [];
    if (preg_match_all('/<sheet[^>]*\bname="([^"]+)"[^>]*\br:id="([^"]+)"/i', $workbookXml, $sheetMatches, PREG_SET_ORDER) === 1 || !empty($sheetMatches)) {
      foreach ($sheetMatches as $m) {
        $name = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
        $rid = $m[2];
        if (isset($ridToTarget[$rid])) {
          $sheetTargets[$name] = $ridToTarget[$rid];
        }
      }
    }

    return $sheetTargets;
  }

  /**
   * Extract shared strings from XLSX XML.
   */
  protected function extractXlsxSharedStringsRaw(ZipArchive $zip): array {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($xml) || trim($xml) === '') {
      return [];
    }

    $strings = [];
    if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $xml, $siMatches, PREG_SET_ORDER) === 1 || !empty($siMatches)) {
      foreach ($siMatches as $si) {
        $text = '';
        if (preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/is', $si[1], $tMatches, PREG_SET_ORDER) === 1 || !empty($tMatches)) {
          foreach ($tMatches as $part) {
            $text .= html_entity_decode($part[1], ENT_QUOTES | ENT_XML1);
          }
        }
        $strings[] = $text;
      }
    }
    return $strings;
  }

  /**
   * Extract row values from one worksheet XML.
   */
  protected function extractSheetRowsRaw(string $sheetXml, array $sharedStrings): array {
    $rows = [];
    if (preg_match_all('/<row\b[^>]*>(.*?)<\/row>/is', $sheetXml, $rowMatches, PREG_SET_ORDER) === 1 || !empty($rowMatches)) {
      foreach ($rowMatches as $rowMatch) {
        $cells = [];
        if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>|<c\b([^>]*)\/>/is', $rowMatch[1], $cellMatches, PREG_SET_ORDER) === 1 || !empty($cellMatches)) {
          foreach ($cellMatches as $cellMatch) {
            $attrs = $cellMatch[1] !== '' ? $cellMatch[1] : $cellMatch[3];
            $inner = $cellMatch[2] ?? '';
            $colRef = '';
            if (preg_match('/\br="([A-Z]+)[0-9]+"/i', $attrs, $refMatch) === 1) {
              $colRef = strtoupper($refMatch[1]);
            }
            $colIndex = $this->xlsxColumnIndexFromRef($colRef);

            $type = '';
            if (preg_match('/\bt="([^"]+)"/i', $attrs, $typeMatch) === 1) {
              $type = strtolower(trim($typeMatch[1]));
            }

            $value = '';
            if ($type === 's') {
              if (preg_match('/<v>(.*?)<\/v>/is', $inner, $vMatch) === 1) {
                $sharedIndex = (int) trim($vMatch[1]);
                $value = $sharedStrings[$sharedIndex] ?? trim($vMatch[1]);
              }
            }
            elseif ($type === 'inlinestr') {
              if (preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/is', $inner, $tMatches, PREG_SET_ORDER) === 1 || !empty($tMatches)) {
                $parts = '';
                foreach ($tMatches as $part) {
                  $parts .= html_entity_decode($part[1], ENT_QUOTES | ENT_XML1);
                }
                $value = $parts;
              }
            }
            else {
              if (preg_match('/<v>(.*?)<\/v>/is', $inner, $vMatch) === 1) {
                $value = html_entity_decode(trim($vMatch[1]), ENT_QUOTES | ENT_XML1);
              }
            }

            if ($colIndex >= 0) {
              $cells[$colIndex] = $value;
            }
            else {
              $cells[] = $value;
            }
          }
        }

        if (!empty($cells)) {
          ksort($cells);
          $rows[] = array_values($cells);
        }
      }
    }
    return $rows;
  }

  /**
   * Convert XLSX column letters (e.g., A, AA) to 0-based index.
   */
  protected function xlsxColumnIndexFromRef(string $colRef): int {
    $colRef = strtoupper(trim($colRef));
    if ($colRef === '') {
      return -1;
    }

    $index = 0;
    $length = strlen($colRef);
    for ($i = 0; $i < $length; $i++) {
      $ord = ord($colRef[$i]);
      if ($ord < 65 || $ord > 90) {
        return -1;
      }
      $index = ($index * 26) + ($ord - 64);
    }

    return $index - 1;
  }

}
