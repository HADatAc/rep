<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

final class IconsExplanationForm extends FormBase {

  public function getFormId() {
    return 'rep_icons_explanation_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'rep/mtsearch_icons';

    $module_path = \Drupal::service('extension.list.module')->getPath('rep');
    $base_url = \Drupal::request()->getBaseUrl();
    $placeholder_base = $base_url . '/' . $module_path . '/images/placeholders/white/';

    $concept_header = [
      ['data' => $this->t('Class')],
      ['data' => $this->t('Instance')],
      ['data' => $this->t('Type URI')],
      ['data' => $this->t('Name')],
      ['data' => $this->t('Explanation')],
    ];

    $metadata_header = [
      ['data' => $this->t('Class')],
      // ['data' => $this->t('Instance')],
      ['data' => $this->t('Type URI')],
      ['data' => $this->t('Name')],
      ['data' => $this->t('Explanation')],
    ];

    $concept_img = [
      'Funding Schemes' => 'fundingschemes_placeholder.png',
      'Projects' => 'projects_placeholder.png',
      'Organizations' => 'organization_placeholder.png',
      'Persons' => 'persons_placeholder.png',
      'Places' => 'places_placeholder.png',
      'Postal Adresses' => 'postaladresses_placeholder.png',
      'Studies' => 'study_placeholder.png',
      'Study Roles' => 'studyrole_placeholder.png',
      'Virtual Columns' => 'virtualcolumn_placeholder.png',
      'Object Collections' => 'studyobjectcollection_placeholder.png',
      'Study Objects' => 'studyobject_placeholder.png',
      'Workflow Stems' => 'processstem_placeholder.png',
      'Workflow' => 'process_placeholder.png',
      'Data Dictionary' => 'datadictionary_placeholder.png',
      'Semantic Data Dictionary' => 'semanticdatadictionary_placeholder.png',
      'Semantic Variable' => 'semanticvariable_placeholder.png',
      'Entity' => 'entity_placeholder.png',
      'Attribute' => 'attribute_placeholder.png',
      'Unit' => 'unit_placeholder.png',
      'Component' => 'component_placeholder.png',
      'Component Instances' => 'component_instance_placeholder.png',
      'Component Stem' => 'component_stem_placeholder.png',
      'Codebooks' => 'codebook_placeholder.png',
      'Response Options' => 'responseoption_placeholder.png',
      'Annotations' => 'annotation_placeholder.png',
      'Annotation Stems' => 'annotation_stem_placeholder.png',
      'Platform' => 'platform_placeholder.png',
      'Platform Instances' => 'platform_instance_placeholder.png',
      'Instrument' => 'instrument_placeholder.png',
      'Instrument Instances' => 'Instrument_instance_placeholder.png',
      'Deployments' => 'deployment_placeholder.png',
      'Message Streams' => 'message_stream_placeholder.png',
      'File Streams' => 'datafile_stream_placeholder.png',
      'Values' => 'value_placeholder.png',
    ];

    $metadata_img = [
      'DAs' => 'da_placeholder.png',
      'INS' => 'ins_placeholder.png',
      'DSG' => 'dsg_placeholder.png',
      'DD'  => 'dd_placeholder.png',
      'SDD' => 'sdd_placeholder.png',
      'DP2' => 'dp2_placeholder.png',
      'STR' => 'str_placeholder.png',
      'KGR' => 'kgr_placeholder.png',
    ];

    $concept_uri = [
      'Funding Schemes' => 'https://schema.org/FundingScheme',
      'Projects' => 'https://schema.org/Project',
      'Organizations' => 'https://schema.org/GovernmentOrganization',
      'Persons' => 'https://schema.org/Person',
      'Places' => 'https://schema.org/City',
      'Postal Adresses' => 'https://schema.org/PostalAddress',
      'Studies' => 'http://hadatac.org/ont/hasco/Study',
      'Study Roles' => '',
      'Virtual Columns' => 'http://hadatac.org/ont/hasco/VirtualColumn',
      'Object Collections' => 'http://hadatac.org/ont/hasco/ObjectCollection',
      'Study Objects' => 'http://hadatac.org/ont/hasco/StudyObject',
      'Workflow Stems' => '',
      'Workflow' => '',
      'Data Dictionary' => '',
      'Semantic Data Dictionary' => '',
      'Semantic Variable' => '',
      'Entity' => '',
      'Attribute' => '',
      'Unit' => '',
      'Codebooks' => 'http://hadatac.org/ont/vstoi#Codebook',
      'Response Options' => 'http://hadatac.org/ont/vstoi#ResponseOption',
      'Annotation Stems' => 'http://hadatac.org/ont/vstoi#AnnotationStem',
      'Annotations' => 'http://hadatac.org/ont/vstoi#Annotation',
      'Platform' => 'http://hadatac.org/ont/vstoi#Platform',
      'Instrument' => 'http://hadatac.org/ont/vstoi#Instrument',
      'Component' => 'http://hadatac.org/ont/vstoi#Component',
      'Component Stem' => 'http://hadatac.org/ont/vstoi#ComponentStem',
      'Deployments' => 'http://hadatac.org/ont/vstoi#Deployment',
      'Message Streams' => '',
      'File Streams' => '',
      'Values' => '',

    ];

    $concept_data = [
      ['name' => 'Funding Schemes', 'desc' => 'NOT FOUND'],
      ['name' => 'Projects', 'desc' => 'An enterprise (potentially individual but typically collaborative), planned to achieve a particular aim. Use properties from [[Organization]], [[subOrganization]]/[[parentOrganization]] to indicate project sub-structures.'],
      ['name' => 'Organizations', 'desc' => 'A governmental organization or agency.'],
      ['name' => 'Persons', 'desc' => 'NOT FOUND'],
      ['name' => 'Places', 'desc' => 'NOT FOUND'],
      ['name' => 'Postal Adresses', 'desc' => 'The mailing address.'],
      ['name' => 'Studies', 'desc' => 'NOT FOUND'],
      ['name' => 'Study Roles', 'desc' => 'NOT FOUND'],
      ['name' => 'Virtual Columns', 'desc' => 'NOT FOUND'],
      ['name' => 'Object Collections', 'desc' => 'NOT FOUND'],
      ['name' => 'Study Objects', 'desc' => 'NOT FOUND'],
      ['name' => 'Workflow Stems', 'desc' => 'NOT FOUND'],
      ['name' => 'Workflow', 'desc' => 'NOT FOUND'],
      ['name' => 'Data Dictionary', 'desc' => 'NOT FOUND'],
      ['name' => 'Semantic Data Dictionary', 'desc' => 'NOT FOUND'],
      ['name' => 'Semantic Variable', 'desc' => 'NOT FOUND'],
      ['name' => 'Entity', 'desc' => 'NOT FOUND'],
      ['name' => 'Attribute', 'desc' => 'NOT FOUND'],
      ['name' => 'Unit', 'desc' => 'NOT FOUND'],
      ['name' => 'Codebooks', 'desc' => 'NOT FOUND'],
      ['name' => 'Response Options', 'desc' => 'NOT FOUND'],
      ['name' => 'Annotation Stems', 'desc' => 'NOT FOUND'],
      ['name' => 'Annotations', 'desc' => 'NOT FOUND'],
      ['name' => 'Platform', 'desc' => 'A surface onto which instruments are deployed to collect data.'],
      ['name' => 'Instrument', 'desc' => 'A device or mechanism that is used to achire attribute values of entities of interest. An instrument does not necessarily require a way to store its measured quantity (e.g, a hard disk).'],
      ['name' => 'Component', 'desc' => 'A physical part of an instrument. A component can be a sensor, a circuit board, a housing, etc. A component can also be a collection of other components.'],
      ['name' => 'Component Stem', 'desc' => 'NOT FOUND'],
      ['name' => 'Deployments', 'desc' => 'A platform is deployed during a certain duration of time and over a certain spacial domain. The platform has instruments on it within the scope of this deployment. For example, a boat will carry certain instruments during a deployment, and those instruments will be removed once the deployment is completed. A stationary deployment can last a much longer time, even decades, with the same instrument.'],
      ['name' => 'Message Streams', 'desc' => 'NOT FOUND'],
      ['name' => 'File Streams', 'desc' => 'NOT FOUND'],
      ['name' => 'Values', 'desc' => 'NOT FOUND'],
    ];

    $metadata_data = [
      ['name' => 'DAs', 'desc' => 'NOT FOUND'],
      ['name' => 'INS', 'desc' => 'NOT FOUND'],
      ['name' => 'DSG', 'desc' => 'NOT FOUND'],
      ['name' => 'DD',  'desc' => 'NOT FOUND'],
      ['name' => 'SDD', 'desc' => 'NOT FOUND'],
      ['name' => 'DP2', 'desc' => 'NOT FOUND'],
      ['name' => 'STR', 'desc' => 'NOT FOUND'],
      ['name' => 'KGR', 'desc' => 'NOT FOUND'],
    ];

    usort($concept_data, function($a, $b) {
      return strcasecmp($a['name'], $b['name']);
    });

    usort($metadata_data, function($a, $b) {
      return strcasecmp($a['name'], $b['name']);
    });

    $concept_instance_of = [
      'Platform' => 'Platform Instances',
      'Instrument' => 'Instrument Instances',
      'Component' => 'Component Instances',
    ];

    $concept_rows = [];
    foreach ($concept_data as $r) {
      $img = $concept_img[$r['name']] ?? null;
      $style = $img ? "background-image: url('{$placeholder_base}{$img}');" : '';

      $button = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '',
        '#attributes' => [
          'type' => 'button',
          'class' => ['element-icon-button', 'kg-col-icon'],
          'style' => $style,
          'title' => $this->t($r['name']),
          'aria-label' => $this->t($r['name']),
          'onclick' => 'return false;',
        ],
      ];

      $instance_cell = ['#markup' => ''];
      if (isset($concept_instance_of[$r['name']])) {
        $instance_name = $concept_instance_of[$r['name']];
        if (!empty($concept_img[$instance_name])) {
          $i_img = $concept_img[$instance_name];
          $i_style = "background-image: url('{$placeholder_base}{$i_img}');";
          $instance_cell = [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => '',
            '#attributes' => [
              'type' => 'button',
              'class' => ['element-icon-button', 'kg-col-icon'],
              'style' => $i_style,
              'title' => $this->t($instance_name),
              'aria-label' => $this->t($instance_name),
              'onclick' => 'return false;',
            ],
          ];
        }
      }

      $uri = $concept_uri[$r['name']] ?? null;
      if ($uri) {
        $label = $uri;
        $b64 = base64_encode($uri);

        $describe_path = '/rep/uri/';
        if (class_exists('\repGUI') && defined('\repGUI::DESCRIBE_PAGE')) {
          $describe_path = \repGUI::DESCRIBE_PAGE;
          if ($describe_path[0] !== '/') { $describe_path = '/' . $describe_path; }
        }

        $url = Url::fromUserInput($describe_path . $b64, [
          'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ]);
        $uriCell = Link::fromTextAndUrl($label, $url)->toRenderable();
      }
      else {
        $uriCell = ['#markup' => '—'];
      }

      $concept_rows[] = [
        ['data' => $button],               // Class
        ['data' => $instance_cell],        // Instance
        ['data' => $uriCell],              // Type URI
        ['data' => (string) $r['name']],   // Name
        ['data' => (string) $r['desc']],   // Explanation
      ];
    }

    $form['concept_title'] = [
      '#type' => 'item',
      '#markup' => '<h2 style="text-align: center; margin-top: 20px;">' . $this->t('Concept Icons') . '</h2>',
    ];

    $form['space1'] = [
      '#type' => 'item',
      '#value' => $this->t('<br><br><br>'),
    ];

    $form['concept_table'] = [
      '#type' => 'table',
      '#header' => $concept_header,
      '#rows'  => $concept_rows,
      '#empty' => $this->t('No icons to display.'),
      '#attributes' => [
        'class' => ['kg-icons-table'],
        'style' => 'max-width: 980px; margin: 0 auto;',
      ],
      '#responsive' => FALSE,
      '#sticky' => FALSE,
    ];

    $metadata_rows = [];
    foreach ($metadata_data as $r) {
      $img = $metadata_img[$r['name']] ?? null;
      $style = $img ? "background-image: url('{$placeholder_base}{$img}');" : '';

      $button = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '',
        '#attributes' => [
          'type' => 'button',
          'class' => ['element-icon-button', 'kg-col-icon'],
          'style' => $style,
          'title' => $this->t($r['name']),
          'aria-label' => $this->t($r['name']),
          'onclick' => 'return false;',
        ],
      ];

      $instance_cell = ['#markup' => ''];
      if (isset($concept_instance_of[$r['name']])) {
        $instance_name = $concept_instance_of[$r['name']];
        if (!empty($metadata_img[$instance_name])) {
          $i_img = $metadata_img[$instance_name];
          $i_style = "background-image: url('{$placeholder_base}{$i_img}');";
          $instance_cell = [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => '',
            '#attributes' => [
              'type' => 'button',
              'class' => ['element-icon-button', 'kg-col-icon'],
              'style' => $i_style,
              'title' => $this->t($instance_name),
              'aria-label' => $this->t($instance_name),
              'onclick' => 'return false;',
            ],
          ];
        }
      }

      $uri = $metadata_uri[$r['name']] ?? null;
      if ($uri) {
        $label = $uri;
        $b64 = base64_encode($uri);

        $describe_path = '/rep/uri/';
        if (class_exists('\repGUI') && defined('\repGUI::DESCRIBE_PAGE')) {
          $describe_path = \repGUI::DESCRIBE_PAGE;
          if ($describe_path[0] !== '/') { $describe_path = '/' . $describe_path; }
        }

        $url = Url::fromUserInput($describe_path . $b64, [
          'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ]);
        $uriCell = Link::fromTextAndUrl($label, $url)->toRenderable();
      }
      else {
        $uriCell = ['#markup' => '—'];
      }

      $metadata_rows[] = [
        ['data' => $button],               // Class
        // ['data' => $instance_cell],        // Instance
        ['data' => $uriCell],              // Type URI
        ['data' => (string) $r['name']],   // Name
        ['data' => (string) $r['desc']],   // Explanation
      ];
    }

    $form['space1'] = [
      '#type' => 'item',
      '#value' => $this->t('<br><br><br>'),
    ];

    $form['metadata_title'] = [
      '#type' => 'item',
      '#markup' => '<h2 style="text-align: center; margin-top: 20px;">' . $this->t('Metadata Icons') . '</h2>',
    ];

    $form['space2'] = [
      '#type' => 'item',
      '#value' => $this->t('<br><br><br>'),
    ];

    $form['metadata_table'] = [
      '#type' => 'table',
      '#header' => $metadata_header,
      '#rows'  => $metadata_rows,
      '#empty' => $this->t('No icons to display.'),
      '#attributes' => [
        'class' => ['kg-icons-table'],
        'style' => 'max-width: 980px; margin: 0 auto;',
      ],
      '#responsive' => FALSE,
      '#sticky' => FALSE,
    ];

    $form['space3'] = [
      '#type' => 'item',
      '#value' => $this->t('<br><br><br>'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
  }
}
