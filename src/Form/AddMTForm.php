<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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

    // HANDLE STUDYURI AND STUDY, IF ANY
    if ($studyuri != NULL) {
      if ($studyuri == 'none') {
        $this->setStudyUri(NULL);
      } else {
        $studyuri_decoded = base64_decode($studyuri);
        $this->setStudyUri($studyuri_decoded);
        $study = $api->parseObjectResponse($api->getUri($this->getStudyUri()),'getUri');
        if ($study == NULL) {
          \Drupal::messenger()->addMessage(t("Failed to retrieve Study."));
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

    if ($elementtype == 'dsg') {
      $this->setElementName('DSG');
      $this->setElementTypeUri(HASCO::DSG);
    } else if ($elementtype == 'ins') {
      $this->setElementName('INS');
      $this->setElementTypeUri(HASCO::INS);
    } else if ($elementtype == 'da') {
      $this->setElementName('DA');
      $this->setElementTypeUri(HASCO::DATA_ACQUISITION);
    } else if ($elementtype == 'dd') {
      $this->setElementName('DD');
      $this->setElementTypeUri(HASCO::DD);
    } else if ($elementtype == 'sdd') {
      $this->setElementName('SDD');
      $this->setElementTypeUri(HASCO::SDD);
    } else if ($elementtype == 'kgr') {
      $this->setElementName('KGR');
      $this->setElementTypeUri(HASCO::KGR);
    } else {
      \Drupal::messenger()->addError(t("<b>".$elementtype . "</b> is not a valid Metadata Template type."));
      self::backUrl();
    }

    $this->setElementType($elementtype);

    //dpm("Element type: " . $this->getElementType());


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
          '#title' => $this->t('Study'),
          '#default_value' => $study,
          '#disabled' => TRUE,
        ];
      } else {
        $form['mt_study'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Study'),
          '#default_value' => $study,
          '#autocomplete_route_name' => 'std.study_autocomplete',
        ];
      }
    }
    $form['mt_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
    ];
    if ($this->getElementType() == 'da') {
      $form['mt_filename'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('File Upload'),
        '#description' => $this->t('Upload a file.'),
        '#upload_location' => 'public://uploads/',
        '#upload_validators' => [
          'file_validate_extensions' => ['csv'],
        ],
      ];
    } else {
      $form['mt_filename'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('File Upload'),
        '#description' => $this->t('Upload a file.'),
        '#upload_location' => 'public://uploads/',
        '#upload_validators' => [
          'file_validate_extensions' => ['xlsx'],
        ],
      ];      
    }

    if ($this->getElementType() == 'da') {
      $form['mt_dd'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Data Dictionary (DD)'),
        #'#default_value' => $study,
        '#autocomplete_route_name' => 'rep.dd_autocomplete',
      ];
      $form['mt_sdd'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Semantic Data Dictionary (SDD)'),
        '#autocomplete_route_name' => 'rep.sdd_autocomplete',
      ];
    }

    $form['mt_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
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
    ];
    $form['cancel_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'back',
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

      $fileId = $form_state->getValue('mt_filename');
      $file_entity = \Drupal\file\Entity\File::load($fileId[0]);
      $filename = $file_entity->getFilename();

      $ddUri = NULL;
      if ($form_state->getValue('mt_dd') != NULL && $form_state->getValue('mt_dd') != '') {
        $ddUri = Utils::uriFromAutocomplete($form_state->getValue('mt_dd'));
      } 

      $sddUri = NULL;
      if ($form_state->getValue('mt_sdd') != NULL && $form_state->getValue('mt_sdd') != '') {
        $sddUri = Utils::uriFromAutocomplete($form_state->getValue('mt_sdd'));
      } 

      // DATAFILE JSON
      $newDataFileUri = Utils::uriGen('datafile');
      $datafileJSON = '{"uri":"'. $newDataFileUri .'",'.
          '"typeUri":"'.HASCO::DATAFILE.'",'.
          '"hascoTypeUri":"'.HASCO::DATAFILE.'",'.
          '"label":"'.$form_state->getValue('mt_name').'",'.
          '"filename":"'.$filename.'",'.          
          '"id":"'.$fileId[0].'",'.          
          '"fileStatus":"'.Constant::FILE_STATUS_UNPROCESSED.'",'.          
          '"hasSIRManagerEmail":"'.$useremail.'"}';

      // MT JSON
      $newMTUri = str_replace("DF",Utils::elementPrefix($this->getElementType()),$newDataFileUri);
      $mtJSON = '{"uri":"'. $newMTUri .'",'.
          '"typeUri":"'.$this->getElementTypeUri().'",'.
          '"hascoTypeUri":"'.$this->getElementTypeUri().'",';
      if ($this->getElementType() == 'da') {
        $mtJSON .= '"isMemberOfUri":"'.$this->getStudy()->uri.'",';
      }
      if ($ddUri != NULL) {
        $mtJSON .= '"hasDDUri":"'.$ddUri.'",';
      }
      if ($sddUri != NULL) {
        $mtJSON .= '"hasSDDUri":"'.$sddUri.'",';
      }
      $mtJSON .= '"label":"'.$form_state->getValue('mt_name').'",'.
          '"hasDataFileUri":"'.$newDataFileUri.'",'.          
          '"hasVersion":"'.$form_state->getValue('mt_version').'",'.
          '"comment":"'.$form_state->getValue('mt_comment').'",'.
          '"hasSIRManagerEmail":"'.$useremail.'"}';

      // Check if a file was uploaded.
      if ($file_entity) {
        // Set the status to FILE_STATUS_PERMANENT.
        $file_entity->set('status', FILE_STATUS_PERMANENT);
        $file_entity->save();
        \Drupal::messenger()->addMessage(t('File uploaded successfully.'));

        $api = \Drupal::service('rep.api_connector');

        // ADD DATAFILE
        $msg1 = $api->parseObjectResponse($api->datafileAdd($datafileJSON),'datafileAdd');

        // ADD MT
        $msg2 = $api->parseObjectResponse($api->elementAdd($this->getElementType(),$mtJSON),'elementAdd');

        if ($msg1 != NULL && $msg2 != NULL) {
          \Drupal::messenger()->addMessage(t($this->getElementName() . " has been added successfully."));      
        } else {
          \Drupal::messenger()->addError(t("Something went wrong while adding " . $this->getElementName() . "."));      
        }
        self::backUrl();
        return;
      }

    } catch(\Exception $e) {
      \Drupal::messenger()->addError(t("An error occurred while adding an ". $this->getElementName() . ": ".$e->getMessage()));
      self::backUrl();
      return;
    }

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'rep.add_mt');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }


}