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
use Drupal\rep\Vocabulary\HASCO;

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

    if ($elementtype === 'wkf') {
      $form['#attached']['library'][] = 'rep/add_wkf_copy_name';
    }

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
      self::backUrl();
      return;
    }

    try {
      $useremail = \Drupal::currentUser()->getEmail();

      // Validate that a file was uploaded
      if (empty($_FILES['files']['name']['mt_filename'])) {
        \Drupal::messenger()->addError(t('Please upload a file.'));
        return;
      }

      $file_info = $_FILES['files'];
      $tmp_name = $file_info['tmp_name']['mt_filename'];
      $original_name = $file_info['name']['mt_filename'];

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
      $destination_real = $file_system->realpath($destination_dir) . '/' . basename($original_name);

      if (!move_uploaded_file($tmp_name, $destination_real)) {
        \Drupal::messenger()->addError(t("Failed to move uploaded file. Check permissions for @dir", ['@dir' => $destination_real]));
        return;
      }

      // Confirm file actually exists at destination
      if (!file_exists($destination_real)) {
        \Drupal::messenger()->addError(t("File move failed - destination file does not exist: @path", ['@path' => $destination_real]));
        return;
      }

      // If we reach here, upload succeeded
      $filename = basename($original_name);

      // Build the Drupal file URI (not the absolute path)
      $drupal_uri = $destination_dir . '/' . $filename;

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

      // Build URIs (same as before)
      $ddUri = NULL;
      if ($form_state->getValue('mt_dd') != NULL && $form_state->getValue('mt_dd') != '') {
        $ddUri = Utils::uriFromAutocomplete($form_state->getValue('mt_dd'));
      }

      $sddUri = NULL;
      if ($form_state->getValue('mt_sdd') != NULL && $form_state->getValue('mt_sdd') != '') {
        $sddUri = Utils::uriFromAutocomplete($form_state->getValue('mt_sdd'));
      }

      $versionValue = (string) $form_state->getValue('mt_version');
      if ($this->getElementType() === 'wkf') {
        $versionValue = '1';
      }

      // Build DATAFILE JSON
      $newDataFileUri = Utils::uriGen('datafile');
      $datafileJSON = json_encode([
        "uri" => $newDataFileUri,
        "typeUri" => HASCO::DATAFILE,
        "hascoTypeUri" => HASCO::DATAFILE,
        "label" => $form_state->getValue('mt_name'),
        "filename" => $filename,
        "fileStatus" => Constant::FILE_STATUS_UNPROCESSED,
        "hasSIRManagerEmail" => $useremail,
        "id" => $file_id,
      ]);

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

      $mtJSON = json_encode($mtData);

      // Send data to your API connector
      $api = \Drupal::service('rep.api_connector');

      $msg1 = $api->parseObjectResponse($api->datafileAdd($datafileJSON), 'datafileAdd');
      $msg2 = $api->parseObjectResponse($api->elementAdd($this->getElementType(), $mtJSON), 'elementAdd');

      if ($msg1 != NULL && $msg2 != NULL) {
        \Drupal::messenger()->addMessage(t($this->getElementName() . " has been added successfully."));
      } else {
        $error = ($msg1 ?? '') . ' ' . ($msg2 ?? '');
        \Drupal::messenger()->addError(t("Something went wrong while adding " . $this->getElementName() . ": " . $error));
      }

      self::backUrl();
      return;

    } catch (\Exception $e) {
      \Drupal::messenger()->addError(t("An error occurred while adding an ". $this->getElementName() . ": ".$e->getMessage()));
      self::backUrl();
      return;
    }
  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    if ($this->elementType != 'da')
      $previousUrl = Utils::trackingGetPreviousUrl($uid, 'rep.add_mt');
    else
      $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.manage_study_elements');

    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
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

}
