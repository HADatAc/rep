<?php

/**
 * @file
 * Contains the settings for admninistering the rep Module
 */

 namespace Drupal\rep\Form;

 use Drupal\Core\Form\ConfigFormBase;
 use Drupal\Core\Form\FormStateInterface;
 use Drupal\Core\Url;
use Drupal\devel\Plugin\Devel\Dumper\Kint;
use Drupal\rep\Entity\Ontology;
 use Drupal\rep\Entity\Tables;
 use Drupal\rep\Utils;

 class repNamespaceForm extends ConfigFormBase {

     /**
     * Settings Variable.
     */
    Const CONFIGNAME = "rep.settings";

     /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "rep_form_namespace";
    }

    /**
     * {@inheritdoc}
     */

    protected function getEditableConfigNames() {
        return [
            static::CONFIGNAME,
        ];
    }

    protected $list;

    public function getList() {
      return $this->list;
    }

    public function setList($list) {
      return $this->list = $list;
    }

    /**
     * {@inheritdoc}
     */

     public function buildForm(array $form, FormStateInterface $form_state){
        $config = $this->config(static::CONFIGNAME);

        $APIservice = \Drupal::service('rep.api_connector');
        $namespace_list = $APIservice->namespaceList();
        if ($namespace_list == NULL) {
            $empty_list = array();
            $this->setList($empty_list);
        } else {
            $obj = json_decode($namespace_list);
            if ($obj->isSuccessful) {
                $this->setList($obj->body);
            }
        }
        $header = Ontology::generateHeader();
        $output = Ontology::generateOutput($this->getList());

        $form['filler_1'] = [
            '#type' => 'item',
            '#title' => $this->t('<br>'),
        ];

        $form['reload_triples_submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Reload Triples from All Ontologies with URL'),
            '#name' => 'reload',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'reload-button'],
            ],
        ];

        $form['reload_triples_selected_submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Reload Selected Triples'),
            '#name' => 'reload_selected',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'arrow-button'],
            ],
        ];

        $form['delete_triples_submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Delete Triples from All Ontologies with URL'),
            '#name' => 'delete',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'delete-element-button'],
            ],
        ];

        $form['add_ontology'] = [
            '#type' => 'submit',
            '#value' => $this->t('Add Ontology'),
            '#name' => 'add_ontology',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'add-element-button'],
            ],
        ];

        $form['update_namespace'] = [
            '#type' => 'submit',
            '#value' => $this->t('Update Selected Ontology'),
            '#name' => 'upd_selected',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'save-button'],
            ],
        ];

        $form['delete_namespace'] = [
            '#type' => 'submit',
            '#value' => $this->t('Delete Selected Ontologies'),
            '#name' => 'del_selected',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'delete-element-button'],
            ],
        ];

        //$form['reset_namespace'] = [
        //    '#type' => 'submit',
        //    '#value' => $this->t('Reset Ontologies'),
        //    '#name' => 'reset',
        //];

        $form['filler_2'] = [
            '#type' => 'item',
            '#title' => $this->t('<br>'),
        ];

        $form['element_table'] = [
            '#type' => 'tableselect',
            '#header' => $header,
            '#options' => $output,
            '#multiple' => TRUE,
            '#js_select' => FALSE,
            '#empty' => t('No Ontology found'),
        ];

        $form['filler_3'] = [
            '#type' => 'item',
            '#title' => $this->t('<br>'),
        ];

        $form['back_submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Back to rep Settings'),
            '#name' => 'back',
            '#attributes' => [
              'class' => ['btn', 'btn-primary', 'back-button'],
            ],
        ];

        $form['filler_4'] = [
            '#type' => 'item',
            '#title' => $this->t('<br>'),
        ];

        $form['actions']['submit']['#access'] = 'FALSE';
        //$form['actions']['edit-submit'] = [
        //    '#type' => 'hidden',
        //    '#title' => 'test',
        //];
        //$form['edit-submit']['#access'] = 'FALSE';
        //$form['edit-submit'] = [
            //'#class' => 'button button--primary js-form-submit form-submit',
          //  '#value' => 'hidden',
        //];

        return Parent::buildForm($form, $form_state);
    }

    public function validateForm(array &$form, FormStateInterface $form_state) {
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {

        // RETRIEVE TRIGGERING BUTTON
        $triggering_element = $form_state->getTriggeringElement();
        $button_name = $triggering_element['#name'];

        if ($button_name === 'reload_selected') {
          $selected = array_filter($form_state->getValue('element_table'));
          $abbrevs  = array_keys($selected);

          if (empty($abbrevs)) {
            \Drupal::messenger()->addWarning($this->t('Please select at least one item on the table.'));
            return;
          }

          $namespaces = [];
          foreach ($abbrevs as $abbr) {
            foreach ($this->getList() as $nsObj) {
              if ($nsObj->label === $abbr) {
                $namespaces[] = $nsObj->uri;
                break;
              }
            }
          }

          if (empty($namespaces)) {
            \Drupal::messenger()->addWarning($this->t('Please select at least one namespace.'));
          }
          else {
            $api = \Drupal::service('rep.api_connector');
            kint($namespaces);
            // TODONS
            $response = $api->reloadSelectedNamespaceTriples($namespaces);
            // \Drupal::messenger()->addMessage($this->t($response));
            $form_state->setRedirectUrl(Url::fromRoute('rep.admin_namespace_settings_custom'));
          }
          return;
        }

        // RETRIEVE SELECTED ROWS, IF ANY
        $selected_rows = $form_state->getValue('element_table');
        $rows = [];
        foreach ($selected_rows as $index => $selected) {
            if ($selected) {
                $rows[$index] = $index;
            }
        }

        // BUTTON ACTIONS

        if ($button_name === 'back') {
          $form_state->setRedirectUrl(Url::fromRoute('rep.admin_settings_custom'));
          return;
        }

        $APIservice = \Drupal::service('rep.api_connector');

        if ($button_name === 'reload') {
          $message = $APIservice->parseObjectResponse($APIservice->repoReloadNamespaceTriples(),'repoReloadNamespaceTriples');
          \Drupal::messenger()->addMessage(t($message));
          $form_state->setRedirectUrl(Url::fromRoute('rep.admin_namespace_settings_custom'));
          return;
        }

        if ($button_name === 'delete') {
          $message = $APIservice->parseObjectResponse($APIservice->repoDeleteNamespaceTriples(),'repoDeleteNamespaceTriples');
          \Drupal::messenger()->addMessage(t($message));
          $form_state->setRedirectUrl(Url::fromRoute('rep.admin_namespace_settings_custom'));
          return;
        }

        if ($button_name === 'add_ontology') {
          $uid = \Drupal::currentUser()->id();
          $previousUrl = Url::fromRoute('rep.admin_namespace_settings_custom')->toString();
          Utils::trackingStoreUrls($uid, $previousUrl, 'rep.admin_namespace_settings_custom');
          $url = Url::fromRoute('rep.add_ontologies');
          $form_state->setRedirectUrl($url);
          return;

        }

        if ($button_name === 'upd_selected') {
            if (sizeof($rows) != 1) {
                \Drupal::messenger()->addWarning(t("Select the exact Ontology to be updated."));
            } else {
                $firstKey = array_key_first($rows);
                $abbrev = $rows[$firstKey];
                //dpm($abbrev);
                $url = Url::fromRoute('rep.update_namespace_settings_custom', ['abbreviation' => $abbrev]);
                $form_state->setRedirectUrl($url);
                return;
            }
        }

        if ($button_name === 'del_selected') {
            if (sizeof($rows) <= 0) {
                \Drupal::messenger()->addWarning(t("At least one Ontology needs to be selected for deletion."));
            } else {
                foreach($rows as $abbrev) {
                    if ($form['element_table']['#options'][$abbrev]['ontology_in_memory'] == 'yes') {
                        \Drupal::messenger()->addWarning(t("An in-memory ontology cannot be deleted."));
                        return;
                    } else {
                        $message = ' ';
                        $message = $APIservice->parseObjectResponse($APIservice->repoDeleteSelectedNamespace($abbrev),'repoDeleteSelectedNamespace');
                        \Drupal::messenger()->addMessage(t($message));
                    }
                }
                $form_state->setRedirectUrl(Url::fromRoute('rep.admin_namespace_settings_custom'));
                return;
            }
        }

        //if ($button_name === 'reset') {
        //    $message = ' ';
        //    $message = $APIservice->parseObjectResponse($APIservice->repoResetNamespaces(),'repoResetNamespaces');
        //    \Drupal::messenger()->addMessage(t($message));
        //    $form_state->setRedirectUrl(Url::fromRoute('rep.admin_namespace_settings_custom'));
        //    return;
        //}

    }

 }
