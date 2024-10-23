<?php

/**
 * @file
 * Contains the settings for admninistering the rep Module
 */

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\ConfigFormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\Core\Url;
 use Symfony\Component\HttpFoundation\RedirectResponse;
 use Drupal\rep\Vocabulary\HASCO;

 class REPUpdateNameSpaceForm extends ConfigFormBase {

     /**
     * Settings Variable.
     */
    Const CONFIGNAME = "rep.settings";

     /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "rep_form_update_namespace";
    }

    /**
     * {@inheritdoc}
     */

    protected function getEditableConfigNames() {
        return [
            static::CONFIGNAME,
        ];
    }

    protected $nameSpace;

    public function getNameSpace() {
      return $this->nameSpace;
    }

    public function setNameSpace($nameSpace) {
      return $this->nameSpace = $nameSpace;
    }

    /**
     * {@inheritdoc}
     */

     public function buildForm(array $form, FormStateInterface $form_state, $abbreviation = NULL) {
        $config = $this->config(static::CONFIGNAME);

        if (!isset($abbreviation) || $abbreviation == '') {
            \Drupal::messenger()->addError(t("Requested abbreviation is EMPTY."));
            self::backUrl();
            return;
        };

        // ESTABLISH API SERVICE
        $api = \Drupal::service('rep.api_connector');

        $namespace_list = $api->namespaceList();
        if ($namespace_list == NULL) {
            \Drupal::messenger()->addError(t("Namespace list is EMPTY."));
            self::backUrl();
            return;

        } else {
            $obj = json_decode($namespace_list);
            if ($obj->isSuccessful) {
                $list = $obj->body;
                if ($list != NULL) {
                    foreach ($list as $ns) {
                        if ($ns->label == $abbreviation) {
                            $this->setNameSpace($ns);
                            break;
                        }
                    }
                }
            }
            if ($this->getNameSpace() == NULL) {
                \Drupal::messenger()->addError(t("Could not find Namespace [" . $abbreviation . "]"));
                self::backUrl();
            }
            if ($this->getNameSpace()->permanent) {
                \Drupal::messenger()->addError(t("An In-Memory Namespace cannot be updated online. These can be updated in Namespaces.java in the API source code."));
            }
        }

        //dpm($this->getNameSpace());

        $source = "";
        if (isset($this->getNameSpace()->source)) {
            $source = $this->getNameSpace()->source;
        }
        $mime = "";
        if (isset($this->getNameSpace()->sourceMime)) {
            $mime = $this->getNameSpace()->sourceMime;
        }
        $mime_types = [
            ' ' => ' ',
            'text/turtle' => 'text/turtle',
            'application/rdf+xml' => 'application/rdf+xml',
        ];

        $disabled = $this->getNameSpace()->permanent;

        $form['namespace_abbreviation'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Abbreviation'),
            '#default_value' => $this->getNameSpace()->label,
            '#disabled' => $disabled,
        ];
        $form['namespace_uri'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Namespace'),
            '#default_value' => $this->getNameSpace()->uri,
            '#disabled' => $disabled,
        ];
        $form['namespace_source_url'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Source URL'),
            '#default_value' => $source,
            '#disabled' => $disabled,
        ];
        $form['namespace_mime_type'] = [
            '#type' => 'select',
            '#title' => $this->t('MIME Type'),
            '#options' => $mime_types,
            '#default_value' => $mime,
            '#disabled' => $disabled,
        ];
        if (!$this->getNameSpace()->permanent) {
            $form['update_submit'] = [
                '#type' => 'submit',
                '#value' => $this->t('Update'),
                '#name' => 'save',
                '#attributes' => [
                  'class' => ['btn', 'btn-primary', 'save-button'],
                ],
            ];
        }
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

        $form['actions']['submit']['#access'] = 'FALSE';

        return Parent::buildForm($form, $form_state);
    }

    public function validateForm(array &$form, FormStateInterface $form_state) {
        $submitted_values = $form_state->cleanValues()->getValues();
        $triggering_element = $form_state->getTriggeringElement();
        $button_name = $triggering_element['#name'];

        if ($button_name != 'back') {
            if(strlen($form_state->getValue('namespace_abbreviation')) < 1) {
            $form_state->setErrorByName('namespace_abbreviation', $this->t('Please fill out a value for abbreviation.'));
            }
            if(strlen($form_state->getValue('namespace_uri')) < 1) {
            $form_state->setErrorByName('namespace_namespace', $this->t('Please fill out a value for namespace'));
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
          $nsJson = '{"uri":"'.$form_state->getValue('namespace_uri').'",'.
            '"label":"'.$form_state->getValue('namespace_abbreviation').'",'.
            '"typeUri":"'.HASCO::ONTOLOGY.'",'.
            '"hascoTypeUri":"'.HASCO::ONTOLOGY.'",'.
            '"priority":"'.$this->getNameSpace()->priority.'",'.
            '"permanent":"'.$this->getNameSpace()->permanent.'",'.
            '"source":"'.$form_state->getValue('namespace_source_url').'",'.
            '"sourceMime":"'.$form_state->getValue('namespace_mime_type').'"}';

          // UPDATE BY DELETING AND CREATING
          $api = \Drupal::service('rep.api_connector');
          $api->repoDeleteSelectedNamespace($this->getNameSpace()->label);
          $updatedNamespace = $api->repoCreateNamespace($nsJson);
          //dpm($nsJson);
          \Drupal::messenger()->addMessage(t("Namespace has been updated successfully."));
          self::backUrl();
          return;

        } catch(\Exception $e) {
          \Drupal::messenger()->addError(t("An error occurred while updating selected Namespace: ".$e->getMessage()));
          self::backUrl();
          return;
        }
    }

    function backUrl() {
        $url = Url::fromRoute('rep.admin_namespace_settings_custom')->toString();
        $response = new RedirectResponse($url);
        $response->send();
        return;
    }

}
