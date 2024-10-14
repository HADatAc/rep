<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Constant;
use Drupal\rep\Vocabulary\HASCO;

class EditMTForm extends FormBase {

  protected $elementType;

  protected $elementName;

  protected $mtUri;

  protected $mt;

  protected $studyUri;

  protected $study;

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

  public function getStudy() {
    return $this->study;
  }

  public function setStudy($study) {
    return $this->study = $study;
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
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = NULL, $elementuri = NULL, $fixstd = NULL, $studyuri = NULL) {

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
          self::backUrl();
          return;
        } else {
          $this->setStudy($study);
        }
      }
    }

    if ($elementtype == NULL) {
      \Drupal::messenger()->addError(t("An elementType is required to retrieve a metadata template."));
      self::backUrl();
      return;
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
      self::backUrl();
      return;
    }

    if ($elementuri == NULL) {
      \Drupal::messenger()->addError(t("An URI is required to retrieve a metadata template."));
      self::backUrl();
      return;
    }

    $uri_decode=base64_decode($elementuri);
    $this->setMTUri($uri_decode);
    $api = \Drupal::service('rep.api_connector');
    $this->setMT($api->parseObjectResponse($api->getUri($this->getMTUri()),'getUri'));
    if ($this->getMT() == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve " . $this->getElementType() . "."));
      self::backUrl();
      return;
    }

    if (isset($this->getMT()->isMemberOf) && $this->getMT()->isMemberOf != NULL) {
      $this->setStudy($this->getMT()->isMemberOf);
      $this->setStudyUri($this->getMT()->isMemberOfUri);
    }

    $study = ' ';
    if ($this->getStudy() != NULL &&
        $this->getStudy()->uri != NULL &&
        $this->getStudy()->label != NULL) {
      $study = Utils::fieldToAutocomplete($this->getStudy()->uri,$this->getStudy()->label);
    }

    $dd = ' ';
    if (isset($this->getMT()->hasDD) &&
        $this->getMT()->hasDD != NULL &&
        $this->getMT()->hasDD->uri != NULL &&
        $this->getMT()->hasDD->label != NULL) {
      $dd = Utils::fieldToAutocomplete($this->getMT()->hasDD->uri,$this->getMT()->hasDD->label);
    }

    $sdd = ' ';
    if (isset($this->getMT()->hasSDD) &&
        $this->getMT()->hasSDD != NULL &&
        $this->getMT()->hasSDD->uri != NULL &&
        $this->getMT()->hasSDD->label != NULL) {
      $sdd = Utils::fieldToAutocomplete($this->getMT()->hasSDD->uri,$this->getMT()->hasSDD->label);
    }

    $form['page_title'] = [
      '#type' => 'item',
      '#title' => $this->t('<h1>Edit ' . $this->getElementName() . '</h1>'),
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
      '#default_value' => $this->getMT()->label,
    ];

    if ($this->getElementType() == 'da') {
      $form['mt_dd'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Data Dictionary (DD)'),
        '#default_value' => $dd,
        '#autocomplete_route_name' => 'rep.dd_autocomplete',
      ];
      $form['mt_sdd'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Semantic Data Dictionary (SDD)'),
        '#default_value' => $sdd,
        '#autocomplete_route_name' => 'rep.sdd_autocomplete',
      ];
    }

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
      self::backUrl();
      return;
    }

    $useremail = \Drupal::currentUser()->getEmail();

    $ddUri = NULL;
    if ($form_state->getValue('mt_dd') != NULL && $form_state->getValue('mt_dd') != '') {
      $ddUri = Utils::uriFromAutocomplete($form_state->getValue('mt_dd'));
    }

    $sddUri = NULL;
    if ($form_state->getValue('mt_sdd') != NULL && $form_state->getValue('mt_sdd') != '') {
      $sddUri = Utils::uriFromAutocomplete($form_state->getValue('mt_sdd'));
    }

    $mtJSON = '{"uri":"'. $this->getMT()->uri .'",'.
      '"typeUri":"'. $this->getMT()->typeUri .'",'.
      '"hascoTypeUri":"'. $this->getMT()->hascoTypeUri .'",';
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
        self::backUrl();
        return;
      } else {
        $msg2 = $api->parseObjectResponse($api->elementAdd($this->getElementType(),$mtJSON),'elementAdd');
        if ($msg2 == NULL) {
          \Drupal::messenger()->addError(t("Failed to update " . $this->getElementType() . " : error while inserting new " . $this->getElementType()));
          self::backUrl();
          return;
        } else {
          \Drupal::messenger()->addMessage(t($this->getElementType() . " has been updated successfully."));
          self::backUrl();
          return;
        }
      }

    } catch(\Exception $e) {
      \Drupal::messenger()->addError(t("An error occurred while updating " . $this->getElementType() . ": ".$e->getMessage()));
      self::backUrl();
      return;
    }

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'rep.edit_mt');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
