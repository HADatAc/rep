<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rep\Utils;
use Drupal\rep\Constant;
use Drupal\rep\Vocabulary\HASCO;

class EditMTForm extends FormBase {

  protected $elementType;

  protected $elementName;

  protected $mtUri;

  protected $mt;

  protected $studyUri;

  public function getElementType() {
    return $this->elementType;
  }

  public function setElementType($elementType) {
    return $this->elementType = $elementType; 
  }

  public function getElementName() {
    return $this->elementName;
  }

  public function setElementName($name) {
    return $this->elementName = $name; 
  }

  public function getMTUri() {
    return $this->mtUri;
  }

  public function setMTUri($uri) {
    return $this->mtUri = $uri; 
  }

  public function getMT() {
    return $this->mt;
  }

  public function setMT($mt) {
    return $this->mt = $mt; 
  }

  public function getStudyUri() {
    return $this->studyUri;
  }

  public function setStudyUri($studyUri) {
    return $this->studyUri = $studyUri; 
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_mt_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype=NULL, $elementuri=NULL, $fixstd=NULL, $studyuri=NULL) {

    if ($studyuri != NULL) {
      $studyuri_decoded = base64_decode($studyuri);
      // dpm($studyuri_decoded);
      $this->setStudyUri($studyuri_decoded);
    }
    
    if ($elementtype == NULL) {
      \Drupal::messenger()->addError(t("An elementType is required to retrieve a metadata template."));
      $form_state->setRedirectUrl(REPSelectMTForm::backSelect('datafile'));
    }
    $this->setElementType($elementtype);

    if ($this->getElementType() == 'dsg') {
      $this->setElementName('DSG');
    } else if ($this->getElementType() == 'ins') {
      $this->setElementName('INS');
    } else if ($this->getElementType() == 'da') {
      $this->setElementName('DA');
    } else if ($this->getElementType() == 'dd') {
      $this->setElementName('DD');
    } else if ($this->getElementType() == 'sdd') {
      $this->setElementName('SDD');
    } else if ($this->getElementType() == 'kgr') {
      $this->setElementName('KGR');
    } else {
      \Drupal::messenger()->addError(t("<b>".$this->getElementType() . "</b> is not a valid Metadata Template type."));
      $form_state->setRedirectUrl(REPSelectMTForm::backSelect($this->getElementType(),$this->getStudyUri()));
    }

    if ($elementuri == NULL) {
      \Drupal::messenger()->addError(t("An URI is required to retrieve a metadata template."));
      $form_state->setRedirectUrl(REPSelectMTForm::backSelect($this->getElementType(),$this->getStudyUri()));
    }

    $uri_decode=base64_decode($elementuri);
    $this->setMTUri($uri_decode);
    $api = \Drupal::service('rep.api_connector');
    $this->setMT($api->parseObjectResponse($api->getUri($this->getMTUri()),'getUri'));
    if ($this->getMT() == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve " . $this->getElementType() . "."));
      $form_state->setRedirectUrl(REPSelectMTForm::backSelect($this->getElementType(),$this->getStudyUri()));
    }

    $form['page_title'] = [
      '#type' => 'item',
      '#title' => $this->t('<h1>Edit ' . $this->getElementName() . '</h1>'),
    ];
    $form['mt_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $this->getMT()->label,
    ];
    $form['mt_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#default_value' => $this->getMT()->hasVersion,
    ];
    $form['mt_comment'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Comment'),
      '#default_value' => $this->getMT()->comment,
    ];
    $form['update_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
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
        $form_state->setErrorByName('mt_name', $this->t('Please enter a name for the ' . $this->getElementName() . '.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      $form_state->setRedirectUrl(REPSelectMTForm::backSelect($this->getElementType(),$this->getStudyUri()));
      return;
    } 

    $useremail = \Drupal::currentUser()->getEmail();

    //dpm($this->getMT());

    $mtJSON = '{"uri":"'. $this->getMT()->uri .'",'.
      '"typeUri":"'. $this->getMT()->typeUri .'",'.
      '"hascoTypeUri":"'. $this->getMT()->hascoTypeUri .'",'.
      '"label":"'.$form_state->getValue('mt_name').'",'.
      '"hasDataFileUri":"'.$this->getMT()->hasDataFile->uri.'",'.          
      '"hasVersion":"'.$form_state->getValue('mt_version').'",'.
      '"comment":"'.$form_state->getValue('mt_comment').'",'.
      '"hasSIRManagerEmail":"'.$useremail.'"}';

    try {
      // UPDATE BY DELETING AND CREATING
      $api = \Drupal::service('rep.api_connector');
      $msg1 = $api->parseObjectResponse($api->elementDel($this->getElementType(),$this->getMT()->uri),'elementDel');
      if ($msg1 == NULL) {
        \Drupal::messenger()->addError(t("Failed to update " .$this->getElementType() . ": error while deleting existing " . $this->getElementType()));
        $form_state->setRedirectUrl(REPSelectMTForm::backSelect($this->getElementType(),$this->getStudyUri()));
      } else {
        $msg2 = $api->parseObjectResponse($api->elementAdd($this->getElementType(),$mtJSON),'elementAdd');
        if ($msg2 == NULL) {
          \Drupal::messenger()->addError(t("Failed to update " . $this->getElementType() . " : error while inserting new " . $this->getElementType()));
          $form_state->setRedirectUrl(REPSelectMTForm::backSelect($this->getElementType(),$this->getStudyUri()));
        } else {
          \Drupal::messenger()->addMessage(t($this->getElementType() . " has been updated successfully."));
          $form_state->setRedirectUrl(REPSelectMTForm::backSelect($this->getElementType(),$this->getStudyUri()));
        }
      }

    } catch(\Exception $e) {
      \Drupal::messenger()->addError(t("An error occurred while updating " . $this->getElementType() . ": ".$e->getMessage()));
      $form_state->setRedirectUrl(REPSelectMTForm::backSelect($this->getElementType(),$this->getStudyUri()));
    }

  }

}