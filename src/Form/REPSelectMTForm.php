<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\rep\ListManagerEmailPage;
use Drupal\rep\ManageOwnerFilter;
use Drupal\rep\Utils;
use Drupal\rep\Entity\MetadataTemplate;
use Drupal\rep\Vocabulary\VSTOI;

class REPSelectMTForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_select_mt_form';
  }

  public $element_type;

  public $manager_email;

  public $manager_name;

  public $single_class_name;

  public $plural_class_name;

  protected $mode;

  protected $list;

  protected $list_size;

  protected $studyuri;

  public function getMode() {
    return $this->mode;
  }

  public function setMode($mode) {
    return $this->mode = $mode;
  }

  public function getList() {
    return $this->list;
  }

  public function setList($list) {
    return $this->list = $list;
  }

  public function getListSize() {
    return $this->list_size;
  }

  public function setListSize($list_size) {
    return $this->list_size = $list_size;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = NULL, $mode = NULL, $page=1, $pagesize=9, $studyuri = NULL)
  {
    // This management form contains dynamic, role-scoped content and custom JS;
    // disable render caching to prevent stale WKF generation UI fragments.
    $form['#cache']['max-age'] = 0;

    // STUDYURI OPTIONAL
    if ($studyuri == NULL) {
      $studyuri = "";
    }
    $this->studyuri = $studyuri;

    // GET MODE
    if ($mode != NULL) {
      $this->setMode($mode);
    }

    // GET MANAGER EMAIL
    $this->manager_email = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $this->manager_name = $user->name->value;

    // GET ELEMENT TYPE
    $this->element_type = $elementtype;
    if ($this->element_type != NULL) {
      $this->setListSize(ListManagerEmailPage::total($this->element_type, $this->manager_email));
    }

    /// GET VIEW MODE + FILTER STATE
    $session = \Drupal::request()->getSession();
    $view_type = $form_state->get('view_type') ?? $session->get('rep_select_mt_view_type') ?? 'table';
    $form_state->set('view_type', $view_type);
    $table_active_class = ($view_type === 'table') ? ['selected-button'] : [];
    $card_active_class = ($view_type === 'card') ? ['selected-button'] : [];

    if ($view_type === 'card') {
      $form['#attached']['library'][] = 'rep/infinitescroll';
    }

    $status_filter_key = 'rep_select_mt_status_filter.' . (string) $elementtype;
    $status_filter = $form_state->getValue('status_filter');
    if ($status_filter === NULL) {
      $status_filter = $session->get($status_filter_key, '_');
    }
    else {
      $session->set($status_filter_key, $status_filter);
    }

    $is_admin = ManageOwnerFilter::isAdmin();
    $manager_filter_key = 'rep_select_mt_manager_filter.' . (string) $elementtype;
    $manager_filter = $form_state->getValue('manager_filter');
    if ($manager_filter === NULL) {
      $manager_filter = $session->get($manager_filter_key, '');
    }
    else {
      $manager_filter = ManageOwnerFilter::normalizeSelectedEmail($manager_filter);
      $session->set($manager_filter_key, $manager_filter);
    }

    $effective_manager_email = ManageOwnerFilter::resolveEffectiveOwner($this->manager_email, $manager_filter, $status_filter);

    if ($view_type == 'table') {

      // Total + list (optionally filtered by status)
      $this->setListSize(-1);
      if ($this->element_type != NULL) {
        if ($status_filter === '_' || $status_filter === NULL || $status_filter === '') {
          $this->setListSize(ListManagerEmailPage::total($this->element_type, $effective_manager_email));
        }
        else {
          $this->setListSize(ListManagerEmailPage::totalByStatusManagerEmail($this->element_type, $status_filter, $effective_manager_email, FALSE));
        }
      }

      // Total pages (at least 1)
      $total_pages = 1;
      if (is_numeric($this->list_size) && $pagesize > 0) {
        $size = (int) $this->list_size;
        if ($size > 0) {
          $total_pages = (int) ceil($size / $pagesize);
        }
      }

      // Clamp current page
      $page = max(1, min((int) $page, (int) $total_pages));

      // CREATE LINK FOR NEXT PAGE AND PREVIOUS PAGE
      if ($page < $total_pages) {
          $next_page = $page + 1;
          $next_page_link = ListManagerEmailPage::linkdpl($this->element_type, $next_page, $pagesize, 'rep');
      } else {
          $next_page_link = '';
      }
      if ($page > 1) {
          $previous_page = $page - 1;
          $previous_page_link = ListManagerEmailPage::linkdpl($this->element_type, $previous_page, $pagesize, 'rep');
      } else {
          $previous_page_link = '';
      }

      $form_state->set('current_page', $page);
      $form_state->set('page_size', $pagesize);

      if ($status_filter === '_' || $status_filter === NULL || $status_filter === '') {
        $this->setList(ListManagerEmailPage::exec($this->element_type, $effective_manager_email, $page, $pagesize));
      }
      else {
        $this->setList(ListManagerEmailPage::execByStatusManagerEmail($this->element_type, $status_filter, $effective_manager_email, FALSE, $page, $pagesize));
      }

    } else {
      // SET PAGE_SIZE
      $pagesize = $form_state->get('page_size') ?? $pagesize ?? 9;
      $form_state->set('page_size', $pagesize);

      // Total + list (optionally filtered by status) for card view too.
      $this->setListSize(-1);
      if ($this->element_type != NULL) {
        if ($status_filter === '_' || $status_filter === NULL || $status_filter === '') {
          $this->setListSize(ListManagerEmailPage::total($this->element_type, $effective_manager_email));
        }
        else {
          $this->setListSize(ListManagerEmailPage::totalByStatusManagerEmail($this->element_type, $status_filter, $effective_manager_email, FALSE));
        }
      }

      if ($status_filter === '_' || $status_filter === NULL || $status_filter === '') {
        $this->setList(ListManagerEmailPage::exec($this->element_type, $effective_manager_email, 1, $pagesize));
      }
      else {
        $this->setList(ListManagerEmailPage::execByStatusManagerEmail($this->element_type, $status_filter, $effective_manager_email, FALSE, 1, $pagesize));
      }
    }

    $this->single_class_name = "";
    $this->plural_class_name = "";
    switch ($this->element_type) {
      case "dsg":
        $this->single_class_name = "DSG";
        $this->plural_class_name = "DSGs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('dsg', $this->getList());
        break;
      case "ins":
        $this->single_class_name = "INS";
        $this->plural_class_name = "INSs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('ins', $this->getList());
        break;
      case "da":
        $this->single_class_name = "DA";
        $this->plural_class_name = "DAs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('da', $this->getList());
        break;
      case "dd":
        $this->single_class_name = "DD";
        $this->plural_class_name = "DDs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('dd', $this->getList());
        break;
      case "kgr":
        $this->single_class_name = "KGR";
        $this->plural_class_name = "KGRs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('kgr', $this->getList());
        break;
      case "sdd":
        $this->single_class_name = "SDD";
        $this->plural_class_name = "SDDs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('sdd', $this->getList());
        break;
      case "dp2":
        $this->single_class_name = "DP2";
        $this->plural_class_name = "DP2s";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('dp2', $this->getList());
        break;
      case "str":
        $this->single_class_name = "STR";
        $this->plural_class_name = "STRs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('str', $this->getList());
        break;
      case "wkf":
        $this->single_class_name = "WKF";
        $this->plural_class_name = "WKFs";
        $header = MetadataTemplate::generateHeader();
        $output = MetadataTemplate::generateOutput('wkf', $this->getList());
        break;
      default:
        \Drupal::messenger()->addError(t("[ERROR] Element [" . $this->element_type . "] is of unknown type."));
        $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
        return;
    }

    // START FORM
    $form['page_title'] = [
      '#type' => 'item',
      '#markup' => '<h3 class="mt-5">Manage ' . $this->plural_class_name . '</h3>',
    ];
    $subtitleManagerLabel = ($effective_manager_email === '_')
      ? $this->t('all owners')
      : $this->manager_name . ' (' . $this->manager_email . ')';
    $form['page_subtitle'] = [
      '#type' => 'item',
      '#markup' => $this->t('<h4>@plural_class_name maintained by <font color="DarkGreen">@manager_label</font></h4>', [
        '@plural_class_name' => $this->plural_class_name,
        '@manager_label' => $subtitleManagerLabel,
      ]),
    ];

    // WKF GENERATION SECTION (only for WKF element type)
    if ($this->element_type === 'wkf') {
      $form['#attached']['library'][] = 'rep/rep_modal';
      $form['#attached']['library'][] = 'rep/wkf_instructions_modal';
      $form['#attached']['library'][] = 'rep/wkf_ingestion_status_poll';
      
      $form['wkf_generation_section'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['wkf-generation-section', 'mb-4', 'p-3', 'border', 'rounded', 'bg-light']],
      ];

      $form['wkf_generation_section']['section_title'] = [
        '#type' => 'item',
        '#markup' => '<h5 class="mb-3">WKF Generation</h5>',
      ];

      // Language selector row (used by phase instruction buttons).
      $form['wkf_generation_section']['instructions_row'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['d-flex', 'align-items-center', 'mb-2', 'gap-2']],
      ];

      $form['wkf_generation_section']['instructions_row']['language_selector'] = [
        '#type' => 'select',
        '#options' => [
          'pt' => $this->t('Português'),
          'en' => $this->t('English'),
        ],
        '#default_value' => 'pt',
        '#attributes' => [
          'class' => ['form-select', 'w-auto'],
          'id' => 'wkf-language-selector',
        ],
      ];

      $form['wkf_generation_section']['phase_context'] = [
        '#type' => 'item',
        '#markup' => '<p class="text-muted mb-3">Phase I creates the core WKF. Phases II to IV refine the same core WKF using the original document until the WKF is ready for PMSR ingestion.</p>',
      ];

      $form['wkf_generation_section']['phase_grid'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['row', 'g-3', 'mt-1']],
      ];

      $clinicalHierarchyOptionsHtml = $this->buildScenarioClinicalProcessOptionsHtml();
      $phase1DownloadUrl = Url::fromRoute('rep.wkf_phase1_download')->toString();
      $knowledgeGraphHierarchyUrl = Url::fromRoute('rep.tree_form', [
        'mode' => 'modal',
        'elementtype' => 'workflowstem',
        'silent' => 'false',
        'prefix' => 'false',
      ])->toString();

      // Phase I.
      $form['wkf_generation_section']['phase_grid']['phase1'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-12', 'col-md-6', 'col-xl-3']],
      ];
      $form['wkf_generation_section']['phase_grid']['phase1']['card'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('
          <div class="card h-100 shadow-sm">
            <div class="card-body d-flex flex-column gap-2">
              <h6 class="card-title">Phase I - Core WKF Generatiion</h6>
              <button type="button" class="btn btn-outline-info btn-sm open-wkf-phase-instructions-window" data-instructions-target="#wkf-phase1-instructions">Instruction</button>
              <div class="mb-2">
                <label for="phase1WkfName" class="form-label mb-1">Core WKF Name</label>
                <input type="text" id="phase1WkfName" class="form-control form-control-sm" placeholder="e.g. WKF-INTUBACAO-CORE" />
              </div>
              <div class="mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label for="phase1ClinicalProcess" class="form-label mb-0">Clinical Process</label>
                  <a href="' . $knowledgeGraphHierarchyUrl . '" class="small open-tree-modal" data-url="' . $knowledgeGraphHierarchyUrl . '" data-elementtype="[&quot;workflowstem&quot;]" data-dialog-type="modal" data-field-id="phase1ClinicalProcess">Knowledge Graph Hierarchy</a>
                </div>
                <select id="phase1ClinicalProcess" class="form-select form-select-sm">
                  <option value="">Select clinical process...</option>
                  ' . $clinicalHierarchyOptionsHtml . '
                </select>
              </div>
              <button type="button" class="btn btn-primary btn-sm mt-auto" id="generatePhase1DraftWkf" data-download-url="' . $phase1DownloadUrl . '">Generate Draft WKF</button>
            </div>
          </div>
        '),
      ];

      // Phase II.
      $form['wkf_generation_section']['phase_grid']['phase2'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-12', 'col-md-6', 'col-xl-3']],
      ];
      $form['wkf_generation_section']['phase_grid']['phase2']['card'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('
          <div class="card h-100 shadow-sm">
            <div class="card-body d-flex flex-column gap-2">
              <h6 class="card-title">Phase II - Task Model Generation</h6>
              <button type="button" class="btn btn-outline-info btn-sm open-wkf-phase-instructions-window" data-instructions-target="#wkf-phase2-instructions">Instruction</button>
              <p class="small text-muted mb-2">Generate task model details using the original document + Phase I core WKF.</p>
              <button type="button" class="btn btn-primary btn-sm mt-auto" data-bs-toggle="modal" data-bs-target="#wkfPhase2PromptModal">Open Prompt</button>
            </div>
          </div>
        '),
      ];

      // Phase III.
      $form['wkf_generation_section']['phase_grid']['phase3'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-12', 'col-md-6', 'col-xl-3']],
      ];
      $form['wkf_generation_section']['phase_grid']['phase3']['card'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('
          <div class="card h-100 shadow-sm">
            <div class="card-body d-flex flex-column gap-2">
              <h6 class="card-title">Phase III - Properties Extraction</h6>
              <button type="button" class="btn btn-outline-info btn-sm open-wkf-phase-instructions-window" data-instructions-target="#wkf-phase3-instructions">Instruction</button>
              <p class="small text-muted mb-2">Extract and encode detailed properties into the same core WKF.</p>
              <button type="button" class="btn btn-primary btn-sm mt-auto" data-bs-toggle="modal" data-bs-target="#wkfPhase3PromptModal">Open Prompt</button>
            </div>
          </div>
        '),
      ];

      // Phase IV.
      $form['wkf_generation_section']['phase_grid']['phase4'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-12', 'col-md-6', 'col-xl-3']],
      ];
      $form['wkf_generation_section']['phase_grid']['phase4']['card'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('
          <div class="card h-100 shadow-sm">
            <div class="card-body d-flex flex-column gap-2">
              <h6 class="card-title">Phase IV - Simulations Assignments</h6>
              <button type="button" class="btn btn-outline-info btn-sm open-wkf-phase-instructions-window" data-instructions-target="#wkf-phase4-instructions">Instruction</button>
              <p class="small text-muted mb-2">Assign simulation assets and finalize WKF ingestion readiness.</p>
              <button type="button" class="btn btn-primary btn-sm mt-auto" data-bs-toggle="modal" data-bs-target="#wkfPhase4PromptModal">Open Prompt</button>
            </div>
          </div>
        '),
      ];

      $form['wkf_generation_section']['footer_actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['d-flex', 'align-items-center', 'justify-content-end', 'mt-3']],
      ];

      $form['wkf_generation_section']['footer_actions']['download_ontologies'] = [
        '#type' => 'submit',
        '#value' => $this->t('Download Ontologies'),
        '#name' => 'wkf_download_ontologies',
        '#attributes' => ['class' => ['btn', 'btn-success']],
        '#submit' => ['::wkfDownloadOntologiesSubmit'],
        '#limit_validation_errors' => [],
      ];

      // Instructions sources.
      $pmsr_module_path = \Drupal::service('extension.list.module')->getPath('pmsr');
      $instructions_en_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/instructions/instructions_EN.md';
      $instructions_pt_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/instructions/instructions_PT.md';

      $instructions_en_md = file_exists($instructions_en_path) ? file_get_contents($instructions_en_path) : '';
      $instructions_pt_md = file_exists($instructions_pt_path) ? file_get_contents($instructions_pt_path) : '';

      $instructions_en = $this->convertMarkdownToHtml($instructions_en_md);
      $instructions_pt = $this->convertMarkdownToHtml($instructions_pt_md);

      // Hidden phase instruction payloads (opened in dedicated window by JS).
      $form['wkf_generation_section']['phase_instruction_payloads'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('
          <div id="wkf-phase1-instructions" class="d-none">
            <div class="instructions-content-phase" data-lang="en"><h5>Phase I - Core WKF Generatiion</h5><p>Fill the small form and generate a real ingestion-ready Draft WKF (version 1) directly from the panel.</p><ul><li>Provide a Core WKF Name.</li><li>Select a Clinical Process from the hierarchy used in Scenario Search.</li><li>Download the generated WKF file and reuse it in Phases II to IV.</li></ul></div>
            <div class="instructions-content-phase" data-lang="pt"><h5>Phase I - Core WKF Generatiion</h5><p>Preencha o formulario e gere o prompt. Anexe o documento original no ChatGPT e gere o WKF base que sera reutilizado nas fases seguintes.</p><ul><li>Foque em entidades e relacoes estruturais.</li><li>Evite detalhes que pertencem as fases posteriores.</li><li>Baixe o WKF core para reutilizacao.</li></ul></div>
          </div>
          <div id="wkf-phase2-instructions" class="d-none">
            <div class="instructions-content-phase" data-lang="en"><h5>Phase II - Task Model Generation</h5><p>Use the original document and the Phase I core WKF together. Ask ChatGPT to derive and encode task-model semantics into the same WKF.</p></div>
            <div class="instructions-content-phase" data-lang="pt"><h5>Phase II - Task Model Generation</h5><p>Use o documento original e o WKF core da Fase I. Solicite ao ChatGPT a geracao do modelo de tarefas no mesmo WKF.</p></div>
          </div>
          <div id="wkf-phase3-instructions" class="d-none">
            <div class="instructions-content-phase" data-lang="en"><h5>Phase III - Properties Extraction</h5><p>Inspect detailed attributes from the source document and encode them into the existing WKF without breaking previously generated structure.</p></div>
            <div class="instructions-content-phase" data-lang="pt"><h5>Phase III - Properties Extraction</h5><p>Extraia propriedades detalhadas do documento e codifique no WKF existente sem quebrar a estrutura das fases anteriores.</p></div>
          </div>
          <div id="wkf-phase4-instructions" class="d-none">
            <div class="instructions-content-phase" data-lang="en"><h5>Phase IV - Simulations Assignments</h5><p>Complete simulation assignments and final readiness checks so the WKF can be ingested into PMSR.</p></div>
            <div class="instructions-content-phase" data-lang="pt"><h5>Phase IV - Simulations Assignments</h5><p>Finalize atribuicoes de simulacao e verificacoes finais para ingestao do WKF no PMSR.</p></div>
          </div>
          <div id="wkf-legacy-instructions" class="d-none">
            <div id="instructions-en" class="instructions-content">' . $instructions_en . '</div>
            <div id="instructions-pt" class="instructions-content">' . $instructions_pt . '</div>
          </div>
        '),
      ];

      // Prompt files for Phases II-IV.
      $phase2_prompt_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/prompts/PROMPT-WKF-PHASE2-TASK-MODEL.md';
      $phase3_prompt_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/prompts/PROMPT-WKF-PHASE3-PROPERTIES.md';
      $phase4_prompt_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/prompts/PROMPT-WKF-PHASE4-SIMULATIONS.md';

      $phase2_prompt = file_exists($phase2_prompt_path) ? file_get_contents($phase2_prompt_path) : "PHASE II - TASK MODEL GENERATION\n\nUse the original document and the Phase I core WKF.\nEncode task model details into the same WKF and return the updated file.";
      $phase3_prompt = file_exists($phase3_prompt_path) ? file_get_contents($phase3_prompt_path) : "PHASE III - PROPERTIES EXTRACTION\n\nUse the original document and the current WKF from previous phases.\nExtract missing properties and encode them in the same WKF. Return the updated file.";
      $phase4_prompt = file_exists($phase4_prompt_path) ? file_get_contents($phase4_prompt_path) : "PHASE IV - SIMULATIONS ASSIGNMENTS\n\nUse the original document and the current WKF.\nAssign simulation mappings and finalize ingestion-readiness fields. Return the final WKF file.";

      // Phase I prompt modal (text generated from form by JS).
      $form['wkf_generation_section']['phase1_prompt_modal'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('
          <div class="modal fade" id="wkfPhase1CorePromptModal" tabindex="-1" aria-labelledby="wkfPhase1CorePromptModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="wkfPhase1CorePromptModalLabel">Phase I - Draft WKF Prompt</h5>
                  <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-primary wkf-copy-prompt" data-source="#phase1CorePromptText"><i class="fas fa-copy"></i> Copy to Clipboard</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                  </div>
                </div>
                <div class="modal-body">
                  <div class="alert alert-info"><i class="fas fa-info-circle"></i> Phase I now generates and downloads a real WKF file directly from the card inputs.</div>
                  <pre id="phase1CorePromptText" class="p-3 bg-light border rounded" style="white-space: pre-wrap;">Use "Generate Draft WKF" in Phase I to download the generated WKF file.</pre>
                </div>
              </div>
            </div>
          </div>
        '),
      ];

      $form['wkf_generation_section']['phase2_prompt_modal'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('
          <div class="modal fade" id="wkfPhase2PromptModal" tabindex="-1" aria-labelledby="wkfPhase2PromptModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="wkfPhase2PromptModalLabel">Phase II - Task Model Prompt</h5>
                  <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-primary wkf-copy-prompt" data-source="#phase2PromptText"><i class="fas fa-copy"></i> Copy to Clipboard</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                  </div>
                </div>
                <div class="modal-body">
                  <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Use this with the original document and the Phase I core WKF.
                  </div>
                  <pre id="phase2PromptText" class="p-3 bg-light border rounded" style="white-space: pre-wrap;">' . $phase2_prompt . '</pre>
                </div>
              </div>
            </div>
          </div>
        '),
      ];

      $form['wkf_generation_section']['phase3_prompt_modal'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('
          <div class="modal fade" id="wkfPhase3PromptModal" tabindex="-1" aria-labelledby="wkfPhase3PromptModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="wkfPhase3PromptModalLabel">Phase III - Properties Extraction Prompt</h5>
                  <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-primary wkf-copy-prompt" data-source="#phase3PromptText"><i class="fas fa-copy"></i> Copy to Clipboard</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                  </div>
                </div>
                <div class="modal-body">
                  <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Use this with the original document and the WKF generated in previous phases.
                  </div>
                  <pre id="phase3PromptText" class="p-3 bg-light border rounded" style="white-space: pre-wrap;">' . $phase3_prompt . '</pre>
                </div>
              </div>
            </div>
          </div>
        '),
      ];

      $form['wkf_generation_section']['phase4_prompt_modal'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('
          <div class="modal fade" id="wkfPhase4PromptModal" tabindex="-1" aria-labelledby="wkfPhase4PromptModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="wkfPhase4PromptModalLabel">Phase IV - Simulations Assignments Prompt</h5>
                  <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-primary wkf-copy-prompt" data-source="#phase4PromptText"><i class="fas fa-copy"></i> Copy to Clipboard</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                  </div>
                </div>
                <div class="modal-body">
                  <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Use this final phase prompt with the original document and current WKF to complete PMSR ingestion readiness.
                  </div>
                  <pre id="phase4PromptText" class="p-3 bg-light border rounded" style="white-space: pre-wrap;">' . $phase4_prompt . '</pre>
                </div>
              </div>
            </div>
          </div>
        '),
      ];
    }

    $show_owner_indicator = $is_admin && $manager_filter !== '' && strcasecmp($effective_manager_email, $manager_filter) === 0;
    if ($show_owner_indicator) {
      $form['owner_indicator'] = [
        '#type' => 'item',
        '#markup' => $this->t('<div class="alert alert-info py-2 mb-3"><strong>A visualizar owner:</strong> @owner</div>', [
          '@owner' => $effective_manager_email,
        ]),
      ];
    }

    // ADD BUTTONS FOR VIEW MODE
    $form['view_toggle'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['view-toggle', 'd-flex', 'justify-content-end']],
    ];

    $form['view_toggle']['table_view'] = [
      '#type' => 'submit',
      '#value' => '',
      '#name' => 'view_table',
      '#attributes' => [
        'style' => 'padding: 20px;',
        'class' => array_merge(['table-view-button', 'fa-xl', 'mx-1'], $table_active_class),
        'title' => $this->t('Table View'),
      ],
      '#submit' => ['::viewTableSubmit'],
      '#limit_validation_errors' => [],
    ];

    $form['view_toggle']['card_view'] = [
      '#type' => 'submit',
      '#value' => '',
      '#name' => 'view_card',
      '#attributes' => [
        'style' => 'padding: 20px;',
        'class' => array_merge(['card-view-button', 'fa-xl'], $card_active_class),
        'title' => $this->t('Card View'),
      ],
      '#submit' => ['::viewCardSubmit'],
      '#limit_validation_errors' => [],
    ];

    // Actions row (buttons + collapsible filters)
    $form['actions_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'flex-column', 'align-items-stretch', 'mb-0', 'rep-manage-toolbar'],
      ],
    ];

    $form['actions_wrapper']['buttons_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['d-flex', 'gap-2', 'flex-nowrap', 'justify-content-start', 'mb-2', 'rep-manage-buttons'],
        'style' => 'flex-wrap:nowrap;overflow-x:auto;'
      ],
    ];

    $form['actions_wrapper']['buttons_container']['add_element'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add New ' . $this->single_class_name),
      '#name' => 'add_element',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'add-element-button'],
      ],
    ];

    if ($view_type == 'table') {
      $form['actions_wrapper']['buttons_container']['edit_selected_element'] = [
        '#type' => 'submit',
        '#value' => $this->t(
          $this->single_class_name === 'WKF'
            ? 'Edit Selected WKF'
            : 'Edit ' . $this->single_class_name . ' Selected'
        ),
        '#name' => 'edit_element',
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'edit-element-button'],
        ],
      ];

      if ($this->single_class_name !== 'WKF') {
        $form['actions_wrapper']['buttons_container']['delete_selected_element'] = [
          '#type' => 'submit',
          '#value' => $this->t('Delete ' . $this->plural_class_name . ' Selected'),
          '#name' => 'delete_element',
          '#attributes' => [
            'onclick' => 'if(!confirm("Really Delete?")){return false;}',
            'class' => ['btn', 'btn-primary', 'delete-element-button'],
          ],
        ];
      }

      $uid = \Drupal::currentUser()->id();
      $user = \Drupal\user\Entity\User::load($uid);
      if ($user && $user->hasRole('content_editor')) {
        if ($this->single_class_name !== 'WKF') {
          $form['actions_wrapper']['buttons_container']['ingest_mt'] = [
            '#type' => 'submit',
            '#value' => $this->t('Ingest ' . $this->single_class_name . ' Selected as Draft'),
            '#name' => 'ingest_mt_draft',
            '#attributes' => [
              'onclick' => 'if(!confirm("Really Ingest file has DRAFT?")){return false;}',
              'class' => ['btn', 'btn-primary', 'ingest_mt-button'],
            ],
          ];
        }

        $form['actions_wrapper']['buttons_container']['ingest_mt_current'] = [
          '#type' => 'submit',
          '#value' => $this->t(
            $this->single_class_name === 'WKF'
              ? 'Ingest Selected WKF'
              : 'Ingest ' . $this->single_class_name . ' selected as Current'
          ),
          '#name' => 'ingest_mt_current',
          '#attributes' => [
            'onclick' => 'if(!confirm("Really Ingest file has CURRENT?")){return false;}',
            'class' => ['btn', 'btn-primary', 'ingest_mt-button'],
          ],
        ];

        $form['actions_wrapper']['buttons_container']['uningest_mt'] = [
          '#type' => 'submit',
          '#value' => $this->t(
            $this->single_class_name === 'WKF'
              ? 'Uningest Selected WKF'
              : 'Uningest ' . $this->plural_class_name . ' Selected'
          ),
          '#name' => 'uningest_mt',
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'uningest_mt-element-button'],
          ],
        ];
      }

      if ($this->single_class_name === 'WKF') {
        $form['actions_wrapper']['buttons_container']['delete_selected_element'] = [
          '#type' => 'submit',
          '#value' => $this->t('Delete Selected WKF'),
          '#name' => 'delete_element',
          '#attributes' => [
            'onclick' => 'if(!confirm("Really Delete?")){return false;}',
            'class' => ['btn', 'btn-primary', 'delete-element-button'],
          ],
        ];
      }
    }

    $status_options = [
      '_' => $this->t('All Status'),
      VSTOI::DRAFT => $this->t('Draft'),
      VSTOI::UNDER_REVIEW => $this->t('Under Review'),
      VSTOI::CURRENT => $this->t('Current'),
      VSTOI::DEPRECATED => $this->t('Deprecated'),
    ];

    $has_active_filters = ($status_filter !== '_' && $status_filter !== NULL && $status_filter !== '')
      || ($is_admin && trim((string) $manager_filter) !== '');

    $ajax_wrapper = ($view_type === 'card') ? 'cards-lazy-wrapper' : 'element-table-wrapper';
    $ajax_callback = ($view_type === 'card') ? '::ajaxReloadCards' : '::ajaxReloadTable';

    $form['actions_wrapper']['filters_panel'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter(s)'),
      '#open' => $has_active_filters,
      '#attributes' => [
        'class' => ['rep-manage-filters-panel', 'w-100'],
      ],
    ];

    $form['actions_wrapper']['filters_panel']['filter_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'g-2', 'align-items-end', 'rep-manage-filters'],
      ],
    ];

    if ($is_admin) {
      $form['actions_wrapper']['filters_panel']['filter_container']['manager_filter'] = [
        '#type' => 'textfield',
        '#title' => $this->t('User'),
        '#title_display' => 'invisible',
        '#default_value' => $manager_filter,
        '#prefix' => '<div class="col-12 col-lg-7">',
        '#suffix' => '</div>',
        '#ajax' => [
          'callback' => $ajax_callback,
          'wrapper' => $ajax_wrapper,
          'event' => 'change',
        ],
        '#attributes' => [
          'class' => ['form-control'],
          'placeholder' => $this->t('User email (Draft/Under Review)'),
        ],
      ];
    }

    $form['actions_wrapper']['filters_panel']['filter_container']['status_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#title_display' => 'invisible',
      '#options' => $status_options,
      '#default_value' => $status_filter,
      '#prefix' => '<div class="col-12 col-md-6 col-lg-3">',
      '#suffix' => '</div>',
      '#ajax' => [
        'callback' => $ajax_callback,
        'wrapper' => $ajax_wrapper,
        'event' => 'change',
      ],
      '#attributes' => [
        'class' => ['form-select'],
      ],
    ];

    $form['actions_wrapper']['filters_panel']['filter_container']['clear_filters'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Filters'),
      '#name' => 'clear_filters',
      '#limit_validation_errors' => [],
      '#prefix' => '<div class="col-12 col-md-6 col-lg-2 d-grid">',
      '#suffix' => '</div>',
      '#attributes' => [
        'class' => ['btn', 'btn-outline-secondary'],
      ],
    ];

    // RENDER BASED ON VIEW TYPE
    if ($view_type == 'table') {

      $form['element_table_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'element-table-wrapper'],
      ];

      $this->buildTableView($form['element_table_wrapper'], $form_state, $header, $output);

      $form['element_table_wrapper']['pager'] = [
        '#theme' => 'list-page',
        '#items' => [
          'page' => strval($page),
          'first' => ListManagerEmailPage::linkdpl($this->element_type, 1, $pagesize, 'rep'),
          'last' => ListManagerEmailPage::linkdpl($this->element_type, $total_pages, $pagesize, 'rep'),
          'previous' => $previous_page_link,
          'next' => $next_page_link,
          'last_page' => strval($total_pages),
          'links' => null,
          'title' => ' ',
        ],
      ];

    } elseif ($view_type == 'card') {
      $form['cards_lazy_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'cards-lazy-wrapper'],
      ];

      $this->buildCardView($form['cards_lazy_wrapper'], $form_state, $header, $output);

      $form['cards_lazy_wrapper']['records_count'] = [
        '#type' => 'item',
        '#markup' => $this->t('<div id="count-cards" style="font-weight:bold; margin-top:10px; padding-right:2rem;">Currently viewing @count of @total @class</div>', [
          '@count' => count($this->getList()),
          '@total' => (int) $this->getListSize(),
          '@class' => $this->plural_class_name,
        ]),
      ];

      $total_items = $this->getListSize();
      $current_page_size = $form_state->get('page_size') ?? 9;

      if ($total_items > $current_page_size) {
        $form['cards_lazy_wrapper']['load_more'] = [
          '#type' => 'submit',
          '#value' => $this->t('Load More'),
          '#name' => 'load_more',
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'load-more-button'],
            'id' => 'load-more-button',
            'style' => 'display: none;',
          ],
          '#submit' => ['::loadMoreSubmit'],
          '#ajax' => [
            'callback' => '::ajaxReloadCards',
            'wrapper' => 'cards-lazy-wrapper',
            'event' => 'click',
          ],
          '#limit_validation_errors' => [],
        ];

        // ADD LOADING OVERLAY
        $form['cards_lazy_wrapper']['loading_overlay'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'loading-overlay',
            'class' => ['loading-overlay'],
            'style' => 'display: none;',
          ],
          '#markup' => '<div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div>',
        ];

        $form['cards_lazy_wrapper']['list_state'] = [
          '#type' => 'hidden',
          '#value' => ($this->getListSize() > $form_state->get('page_size')) ? 1 : 0,
          '#attributes' => [
            'id' => 'list_state',
          ],
        ];
      }
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'back-button'],
      ],
    ];
    $form['space2'] = [
      '#type' => 'item',
      '#markup' => '<br><br><br>',
    ];

    return $form;
  }

  /**
   * AJAX callback to reload list when filters change.
   */
  public function ajaxReloadTable(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['element_table_wrapper'];
  }

  /**
   * AJAX callback to reload cards wrapper when loading more.
   */
  public function ajaxReloadCards(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['cards_lazy_wrapper'];
  }

  /**
   * HANDLER FOR LOAD MORE BUTTON
   */
  public function loadMoreSubmit(array &$form, FormStateInterface $form_state)
  {
    // Atualiza o tamanho da página para carregar mais itens
    $current_page_size = $form_state->get('page_size') ?? 9;
    $pagesize = $current_page_size + 9; // Soma mais 9 ao tamanho atual
    $form_state->set('page_size', $pagesize);

    // \Drupal::logger('rep_select_mt_form')->notice('Load More Triggered: new page_size @page_size', [
    //     '@page_size' => $pagesize,
    // ]);

    // FORCE REBUILD
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // RETRIEVE TRIGGERING BUTTON
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if (isset($triggering_element['#submit']) && !empty($triggering_element['#submit'])) {
      return;
    }

    if ($button_name === 'clear_filters') {
      $this->clearSavedFilters($form_state);
      return;
    }

    // RETRIEVE SELECTED ROWS, IF ANY
    $selected_rows = $form_state->getValue('element_table');
    $rows = [];
    if ($selected_rows) {
      foreach ($selected_rows as $index => $selected) {
        if ($selected) {
          $rows[$index] = $index;
        }
      }
    }

    // Handle actions based on button name
    if ($button_name === 'add_element') {
      $this->performAdd($form_state);
    } elseif ($button_name === 'edit_element') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Please select exactly one " . $this->single_class_name . " to be edited."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Not more than one " . $this->single_class_name . " can be edited simultaneously."));
      } else {
        $first = array_shift($rows);
        $this->performEdit($first, $form_state);
      }
    } elseif ($button_name === 'delete_element') {
      if (sizeof($rows) <= 0) {
        \Drupal::messenger()->addWarning(t("At least one " . $this->single_class_name . " must be selected to delete."));
      } else {
        $this->performDelete($rows, $form_state);
      }
    } elseif ($button_name === 'ingest_mt') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Please select exactly one " . $this->single_class_name . " to be ingested."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Not more than one " . $this->single_class_name . " can be ingested simultaneously."));
      } else {
        $this->performIngest($rows, $form_state, "_");
      }
    } elseif ($button_name === 'ingest_mt_draft') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Please select exactly one " . $this->single_class_name . " to be ingested."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Not more than one " . $this->single_class_name . " can be ingested simultaneously."));
      } else {
        $this->performIngest($rows, $form_state, VSTOI::DRAFT);
      }
    } elseif ($button_name === 'ingest_mt_current') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Please select exactly one " . $this->single_class_name . " to be ingested."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Not more than one " . $this->single_class_name . " can be ingested simultaneously."));
      } else {
        $this->performIngest($rows, $form_state, VSTOI::CURRENT);
      }
    } elseif ($button_name === 'uningest_mt') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Please select exactly one " . $this->single_class_name . " to be uningested."));
      } else if ((sizeof($rows) > 1)) {
        \Drupal::messenger()->addWarning(t("Not more than one " . $this->single_class_name . " can be uningested simultaneously."));
      } else {
        $this->performUningest($rows, $form_state);
      }
    } elseif ($button_name === 'back') {
      $url = Url::fromRoute('std.search');
      $form_state->setRedirectUrl($url);
    }
  }

  /**
   * Clear persisted filters for MT list/select pages.
   */
  protected function clearSavedFilters(FormStateInterface $form_state): void {
    $session = \Drupal::request()->getSession();
    $suffix = (string) $this->element_type;

    $session->remove('rep_select_mt_status_filter.' . $suffix);
    $session->remove('rep_select_mt_manager_filter.' . $suffix);

    $input = $form_state->getUserInput();
    unset($input['status_filter'], $input['manager_filter']);
    $form_state->setUserInput($input);

    $form_state->setValue('status_filter', '_');
    $form_state->setValue('manager_filter', '');
    $form_state->setRebuild(TRUE);
  }

  /**
   * BUILD TABLE VIEW
   */
  protected function buildTableView(array &$form, FormStateInterface $form_state, $header, $output)
  {
    $form['element_table'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $output,
      '#js_select' => FALSE,
      '#empty' => $this->t('No ' . $this->plural_class_name . ' found'),
    ];
  }

  /**
   * BUILD CARD VIEW
   */
  protected function buildCardView(array &$form, FormStateInterface $form_state, $header, $output)
  {

    // IMAGE PLACEHOLDER
    switch ($this->element_type) {
      case 'ins':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/ins_placeholder.png';
        break;
      case 'dsg':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/dsg_placeholder.png';
        break;
      case 'dd':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/dd_placeholder.png';
        break;
      case 'sdd':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/sdd_placeholder.png';
        break;
      case 'dp2':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/dp2_placeholder.png';
        break;
      case 'str':
        $placeholder_image = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/placeholders/str_placeholder.png';
        break;
    }

    $form['element_cards_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'element-cards-wrapper', 'class' => ['row', 'mt-3']],
    ];

    if (empty($output)) {
      $form['element_cards_wrapper']['no_results'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-12']],
        'message' => [
          '#markup' => '<div class="alert alert-info mb-0">'
            . $this->t('No @items found for the current filters.', ['@items' => $this->plural_class_name])
            . '</div>',
        ],
      ];
      return;
    }

    foreach ($output as $key => $item) {
      $sanitized_key = md5($key);

      $form['element_cards_wrapper'][$sanitized_key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-md-4']],
      ];

      $form['element_cards_wrapper'][$sanitized_key]['card'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['card', 'mb-4']],
      ];

      $header_text = '';

      foreach ($header as $column_key => $column_label) {
        if ($column_label == 'Name') {
          $value = isset($item[$column_key]) ? $item[$column_key] : '';
          $header_text = strip_tags($value);
          break;
        }
      }

      // Definir a URL da imagem, usar placeholder se não houver imagem no item
      $image_uri = !empty($item['image']) ? $item['image'] : $placeholder_image;

      if (strlen($header_text) > 0) {
        $form['element_cards_wrapper'][$sanitized_key]['card']['header'] = [
          '#type' => 'container',
          '#attributes' => [
            'style' => 'margin-bottom:0!important;',
            'class' => ['card-header'],
          ],
          '#markup' => '<h5 class="mb-0">' . $header_text . '</h5>',
        ];
      }

      $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['row'],
        ],
      ];

      // Column for the image
      $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper']['image'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['col-md-5', 'texta-align-center'],
          'style' => 'margin-bottom:0!important;text-align:center!important;',
        ],
        'image' => [
          '#type' => 'html_tag',
          '#tag' => 'img',
          '#attributes' => [
              'src' => $image_uri,
              'alt' => $header_text,
              'style' => 'max-width: 70%; height: auto;',
          ]
        ],
      ];

      // Column for main content and footer
      $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper']['content'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['col-md-7', 'card-body'],
          'style' => 'margin-bottom:0!important;',
        ],
      ];

      // Iterando sobre o conteúdo existente e adicionando-o à coluna de conteúdo
      foreach ($header as $column_key => $column_label) {
        $value = isset($item[$column_key]) ? $item[$column_key] : '';
        if ($column_label == 'Name') {
          continue;
        }

        if ($column_label == 'Status') {
          $value_rendered = [
            '#markup' => $value,
            '#allowed_tags' => ['b', 'font', 'span', 'div', 'strong', 'em'],
          ];
        } else {
          $value_rendered = [
            '#markup' => $value,
          ];
        }

        $form['element_cards_wrapper'][$sanitized_key]['card']['content_wrapper']['content'][$column_key] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['field-container'],
          ],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => $column_label . ': ',
          ],
          'value' => $value_rendered,
        ];
      }

      // Adicionando o rodapé na mesma coluna de conteúdo
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer'] = [
        '#type' => 'container',
        '#attributes' => [
          'style' => 'margin-bottom:0!important;',
          'class' => ['d-flex', 'card-footer', 'justify-content-end'],
        ],
      ];

      // Adicionando os botões ao rodapé
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions'] = [
        '#type' => 'actions',
        '#attributes' => [
          'style' => 'margin-bottom:0!important;',
          'class' => ['mb-0'],
        ],
      ];

      // Botão Editar
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Edit'),
        '#name' => 'edit_element_' . $sanitized_key,
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'btn-sm', 'edit-element-button'],
        ],
        '#submit' => ['::editElementSubmit'],
        '#limit_validation_errors' => [],
        '#element_uri' => $key,
      ];

      // Button Delete
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete'),
        '#name' => 'delete_element_' . $sanitized_key,
        '#attributes' => [
          'class' => ['btn', 'btn-danger', 'btn-sm', 'delete-element-button'],
          'onclick' => 'if(!confirm("Really Delete?")){return false;}',
        ],
        '#submit' => ['::deleteElementSubmit'],
        '#limit_validation_errors' => [],
        '#element_uri' => $key,
      ];

      // Button Ingest
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['ingest'] = [
        '#type' => 'submit',
        '#value' => $this->t('Ingest'),
        '#name' => 'ingest_mt_' . $sanitized_key,
        '#attributes' => [
          'class' => ['btn', 'btn-success', 'btn-sm', 'ingest_mt-button'],
        ],
        '#submit' => ['::ingestElementSubmit'],
        '#limit_validation_errors' => [],
        '#element_uri' => $key,
      ];

      // Button Uningest
      $form['element_cards_wrapper'][$sanitized_key]['card']['footer']['actions']['uningest'] = [
        '#type' => 'submit',
        '#value' => $this->t('Uningest'),
        '#name' => 'uningest_mt_' . $sanitized_key,
        '#attributes' => [
          'class' => ['btn', 'btn-warning', 'btn-sm', 'uningest_mt-element-button'],
        ],
        '#submit' => ['::uningestElementSubmit'],
        '#limit_validation_errors' => [],
        '#element_uri' => $key,
      ];
    }
  }

  /**
   * HANDLER TO CHANGE TO TABLE VIEW
   */
  public function viewTableSubmit(array &$form, FormStateInterface $form_state)
  {
    $form_state->set('view_type', 'table');
    $session = \Drupal::request()->getSession();
    $session->set('rep_select_mt_view_type', 'table');
    $form_state->setRebuild();
  }

  /**
   * HANDLER TO CHANGE TO CARD VIEW
   */
  public function viewCardSubmit(array &$form, FormStateInterface $form_state)
  {
    $form_state->set('view_type', 'card');
    $session = \Drupal::request()->getSession();
    $session->set('rep_select_mt_view_type', 'card');
    $form_state->setRebuild();
  }

  /**
   * HANDLER TO EDIT CARD
   */
  public function editElementSubmit(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performEdit($uri, $form_state);
  }

  /**
   * HANDLER TO DELETE CARD
   */
  public function deleteElementSubmit(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performDelete([$uri], $form_state);
  }

  /**
   * HANDLER TO INGEST CARD
   */
  public function ingestElementSubmit(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performIngest([$uri], $form_state, VSTOI::DRAFT);
  }

  /**
   * HANDLER TO UNINGEST CARD
   */
  public function uningestElementSubmit(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $uri = $triggering_element['#element_uri'];

    $this->performUningest([$uri], $form_state);
  }

  /**
   * ADD FUNCTION
   */
  protected function performAdd(FormStateInterface $form_state)
  {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();
    Utils::trackingStoreUrls($uid, $previousUrl, 'rep.add_mt');
    $url = Url::fromRoute('rep.add_mt', [
      'elementtype' => $this->element_type,
      'studyuri' => 'none',
      'fixstd' => 'F',
    ]);
    $form_state->setRedirectUrl($url);
  }

  /**
   * EDIT FUNCTION
   */
  protected function performEdit($uri, FormStateInterface $form_state)
  {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();
    Utils::trackingStoreUrls($uid, $previousUrl, 'rep.edit_mt');
    $url = Url::fromRoute('rep.edit_mt', [
      'elementtype' => $this->element_type,
      'elementuri' => base64_encode($uri),
      'fixstd' => 'F',
    ]);
    $form_state->setRedirectUrl($url);
  }

  /**
   * DELETE FUNCTION
  */
  protected function performDelete(array $uris, FormStateInterface $form_state) {
    $api = \Drupal::service('rep.api_connector');
    $file_system = \Drupal::service('file_system');

    $deleted = 0;
    $failed = 0;
    $wkfManagerEmailsToRefresh = [];

    foreach ($uris as $raw_uri) {
      $uri = Utils::plainUri($raw_uri) ?: $raw_uri;

      // Resolve template (best-effort) so we can also clean up its cached DataFile.
      $mt = $api->parseObjectResponse($api->getUri($uri), 'getUri');
      if (!is_object($mt) && $raw_uri !== $uri) {
        $mt = $api->parseObjectResponse($api->getUri($raw_uri), 'getUri');
      }

      $effective_uri = $uri;
      if (is_object($mt) && isset($mt->uri) && is_string($mt->uri) && $mt->uri !== '') {
        $effective_uri = $mt->uri;
      }

      $datafileUri = NULL;
      $cachedFileId = NULL;

      if (is_object($mt)) {
        // Common shape: hasDataFile is an object with { uri, id, filename, ... }
        if (isset($mt->hasDataFile)) {
          if (is_object($mt->hasDataFile)) {
            if (isset($mt->hasDataFile->uri) && is_string($mt->hasDataFile->uri) && $mt->hasDataFile->uri !== '') {
              $datafileUri = $mt->hasDataFile->uri;
            }
            if (isset($mt->hasDataFile->id)) {
              $cachedFileId = $mt->hasDataFile->id;
            }
          }
          elseif (is_string($mt->hasDataFile) && $mt->hasDataFile !== '') {
            // Sometimes API returns just the URI string.
            $datafileUri = $mt->hasDataFile;
          }
        }

        // Alternate shape used by some WKF payloads.
        if ($datafileUri === NULL && isset($mt->hasDataFileUri) && is_string($mt->hasDataFileUri) && $mt->hasDataFileUri !== '') {
          $datafileUri = $mt->hasDataFileUri;
        }

      }

      // 1) Delete the Metadata Template / WKF itself (expected path for all MTs).
      $deleteConfirmed = FALSE;
      $deleteResult = $api->parseObjectResponse($api->elementDel($this->element_type, $effective_uri), 'elementDel');
      if ($this->deleteResponseIndicatesSuccess($deleteResult) && $this->confirmUriDeleted($effective_uri)) {
        $deleteConfirmed = TRUE;
      }

      // Some deployments keep WKF visible after elementDel("wkf", ...).
      // Fallback to process deletion and re-verify.
      if (!$deleteConfirmed && $this->element_type === 'wkf') {
        $processDeleteResult = $api->parseObjectResponse($api->processDel($effective_uri), 'processDel');
        if ($this->deleteResponseIndicatesSuccess($processDeleteResult) && $this->confirmUriDeleted($effective_uri)) {
          $deleteConfirmed = TRUE;
        }
      }

      if ($deleteConfirmed) {
        $deleted++;

        if ($this->element_type === 'wkf') {
          $candidateEmails = $this->extractManagerEmailsFromTemplateObject($mt);
          foreach ($candidateEmails as $email) {
            $wkfManagerEmailsToRefresh[$email] = true;
          }
        }

        // 2) Best-effort cleanup: delete associated DataFile (if known).
        if (!empty($datafileUri)) {
          $api->parseObjectResponse($api->datafileDel($datafileUri), 'datafileDel');
        }

        // 3) Best-effort cleanup: delete cached Drupal File entity + binary.
        if (!empty($cachedFileId)) {
          $file = File::load($cachedFileId);
          if ($file) {
            $file_uri = $file->getFileUri();
            if (!empty($file_uri)) {
              $real_path = $file_system->realpath($file_uri);
              if ($real_path && file_exists($real_path)) {
                try {
                  $file_system->delete($file_uri);
                }
                catch (\Throwable $e) {
                  // ignore
                }
              }
            }
            try {
              $file->delete();
            }
            catch (\Throwable $e) {
              // ignore
            }
          }
        }
      }
      else {
        $failed++;
      }
    }

    if ($deleted > 0 && $failed === 0) {
      \Drupal::messenger()->addMessage(t("The " . $this->plural_class_name . " selected were deleted successfully."));
    }
    elseif ($deleted > 0) {
      \Drupal::messenger()->addWarning(t("Some items were deleted, but @n deletions failed.", ['@n' => $failed]));
    }
    else {
      \Drupal::messenger()->addError(t("Failed to delete the selected " . $this->plural_class_name . "."));
    }

    if ($this->element_type === 'wkf' && $deleted > 0) {
      if (empty($wkfManagerEmailsToRefresh)) {
        $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($this->manager_email);
      }
      else {
        foreach (array_keys($wkfManagerEmailsToRefresh) as $email) {
          $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($email);
        }
      }
    }

    \Drupal::service('cache.default')->invalidateAll();
    $form_state->setRebuild();
  }

  /**
   * True only when delete response represents a real positive result.
   */
  protected function deleteResponseIndicatesSuccess($deleteResult): bool {
    if ($deleteResult === NULL) {
      return FALSE;
    }

    if (is_array($deleteResult)) {
      return !empty($deleteResult);
    }

    if (is_string($deleteResult)) {
      $normalized = trim($deleteResult);
      if ($normalized === '') {
        return FALSE;
      }
      if (preg_match('/^No\\b.*\\b(has|have)\\sbeen\\sfound\\.?$/i', $normalized)) {
        return FALSE;
      }
      return TRUE;
    }

    return TRUE;
  }

  /**
   * Confirms deletion against a fresh connector instance to avoid getUri cache.
   */
  protected function confirmUriDeleted(string $uri): bool {
    $uri = Utils::plainUri($uri) ?: $uri;
    $freshApi = new \Drupal\rep\FusekiAPIConnector(\Drupal::service('http_client_factory'));
    $raw = $freshApi->getUri($uri);

    if (!is_string($raw) || trim($raw) === '') {
      return FALSE;
    }

    $obj = json_decode($raw);
    if (!is_object($obj)) {
      return FALSE;
    }

    if (!empty($obj->isSuccessful)) {
      // URI still resolvable, so delete was not effective.
      return FALSE;
    }

    // A backend failure (e.g. triplestore_unavailable) carries an `error` object and no string
    // body, so $message stays empty and we correctly refuse to conclude anything.
    $message = isset($obj->body) && is_string($obj->body) ? trim($obj->body) : '';
    return self::messageIndicatesUriAbsent($message);
  }

  /**
   * Whether a hascoapi failure message means "this URI no longer exists".
   *
   * hascoapi signals not-found with prose, and phrases it differently per endpoint:
   *   SIRElementAPI::deleteElement -> "No element with URI [x] has been found"
   *   URIPage::getUri              -> "Uri [x] returned no object from the knowledge graph"
   * confirmUriDeleted() asks getUri(), so it must accept the second phrasing too. Everything
   * else (invalid URI, untyped instance, JSON error) means the URI is still there, or that we
   * cannot tell -- both must fail closed.
   *
   * ponytail: prose matching, because hascoapi returns HTTP 200 for every failure and has no
   * machine-readable error code on this path. Replace with a status/code check once it does
   * (see HASCOAPI_WKF_TASK_RESOLUTION_BUG_REPORT_2026-07-10.md, F2).
   *
   * Known ceiling: URIPage emits the "returned no object" message both for a genuinely absent
   * URI and for one whose hasco:hascoType it cannot dispatch (report F1). Metadata templates
   * dispatch fine, so this is safe for the delete flow; do not reuse it for Tasks until F1 lands.
   */
  public static function messageIndicatesUriAbsent(string $message): bool {
    if ($message === '') {
      return FALSE;
    }
    if (preg_match('/^No\\b.*\\b(has|have)\\sbeen\\sfound\\.?$/i', $message)) {
      return TRUE;
    }
    return stripos($message, 'returned no object from the knowledge graph') !== FALSE;
  }

  /**
   * INGEST FUNCTION
   */
  protected function performIngest(array $uris, FormStateInterface $form_state, String $status) {
    //($status);
    $api = \Drupal::service('rep.api_connector');
    $rawUri = reset($uris);
    $uri = Utils::plainUri($rawUri) ?: $rawUri;
    $template = $api->parseObjectResponse($api->getUri($uri), 'getUri');

    // Fallback: retry with original token in case the selected row already had canonical URI.
    if ($template == NULL && $rawUri !== $uri) {
      $template = $api->parseObjectResponse($api->getUri($rawUri), 'getUri');
    }
    
    // Debugging is handled via Drupal logger/messenger when needed.
    
    if ($template == NULL) {
      \Drupal::messenger()->addError(t("Failed to retrieve the datafile to be ingested."));
      $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      return;
    }

    // Keep template URI canonical before calling upload endpoint.
    if (isset($template->uri) && is_string($template->uri) && $template->uri !== '') {
      $template->uri = Utils::plainUri($template->uri) ?: $template->uri;
    } else {
      $template->uri = $uri;
    }

    // Some payloads embed hasDataFile but omit hasDataFileUri.
    if ((!isset($template->hasDataFileUri) || $template->hasDataFileUri == NULL || $template->hasDataFileUri === '')
      && isset($template->hasDataFile) && is_object($template->hasDataFile)
      && isset($template->hasDataFile->uri) && is_string($template->hasDataFile->uri) && $template->hasDataFile->uri !== '') {
      $template->hasDataFileUri = Utils::plainUri($template->hasDataFile->uri) ?: $template->hasDataFile->uri;
    }
    
    // FIX: If template doesn't have hasDataFile embedded, fetch it separately
    if (!isset($template->hasDataFile) && isset($template->hasDataFileUri)) {
      \Drupal::logger('rep')->notice('performIngest: Template missing hasDataFile, fetching separately from: @uri', [
        '@uri' => $template->hasDataFileUri,
      ]);
      
      $dataFileUri = Utils::plainUri($template->hasDataFileUri) ?: $template->hasDataFileUri;
      $dataFile = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
      if ($dataFile != NULL) {
        $template->hasDataFile = $dataFile;
        $template->hasDataFileUri = $dataFileUri;
        
        // DEBUG: Show ALL DataFile properties
        // \Drupal::messenger()->addStatus(t('[DEBUG] DataFile fetched - ALL PROPERTIES: @props', [
        //   '@props' => print_r($dataFile, TRUE),
        // ]));
        
        \Drupal::logger('rep')->notice('performIngest: DataFile attached - id: @id, filename: @filename, ALL: @all', [
          '@id' => isset($dataFile->id) ? $dataFile->id : 'NULL',
          '@filename' => isset($dataFile->filename) ? $dataFile->filename : 'NULL',
          '@all' => print_r($dataFile, TRUE),
        ]);
      } else {
        \Drupal::logger('rep')->warning('performIngest: Failed to retrieve DataFile from: @uri', [
          '@uri' => $template->hasDataFileUri,
        ]);
      }
    }

    // Hard pre-submit check: ensure the local file is actually readable before upload.
    $readability = $this->verifyLocalDataFileReadability($template);
    if (!$readability['ok']) {
      $tried = !empty($readability['tried']) ? implode(' | ', $readability['tried']) : '(none)';
      $this->appendLocalDataFileDiagnosticLog($template, 'Local file precheck warning before submit: ' . $readability['reason'] . '. Tried paths: ' . $tried);
      \Drupal::messenger()->addWarning(t('Local file precheck warning before submit: @reason. Tried paths: @paths. Submission will continue and backend/upload diagnostics will be used if it fails.', [
        '@reason' => $readability['reason'],
        '@paths' => $tried,
      ]));
    }
    
    $uploadResponse = $api->uploadTemplate($this->element_type, $template, $status);
    $msg = $api->parseObjectResponse($uploadResponse, 'uploadTemplateStatus');
    if ($msg == NULL) {
      $detail = $this->extractIngestionFailureDetail($uploadResponse);
      if ($detail === '') {
        $detail = $this->extractDataFileFailureDetail($api, $template);
      }
      if ($detail === '' && method_exists($api, 'getErrorMessage')) {
        $apiError = trim((string) $api->getErrorMessage());
        if ($apiError !== '') {
          $detail = $apiError;
        }
      }
      $this->appendLocalDataFileDiagnosticLog($template, 'Ingestion submit failed before worker start: ' . ($detail !== '' ? $detail : 'No backend detail returned.'));
      if ($detail !== '') {
        \Drupal::messenger()->addError(t("The " . $this->single_class_name . " selected FAILED to be submited for Ingestion. Reason: @reason", [
          '@reason' => $detail,
        ]));
      } else {
        \Drupal::messenger()->addError(t("The " . $this->single_class_name . " selected FAILED to be submited for Ingestion."));
      }
      $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
      return;
    }
    \Drupal::messenger()->addMessage(t("The " . $this->single_class_name . " selected was successfully submited for Ingestion."));
    if ($this->element_type === 'wkf') {
      $refreshEmails = $this->extractManagerEmailsFromTemplateObject($template);
      if (empty($refreshEmails)) {
        $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($this->manager_email);
      }
      else {
        foreach ($refreshEmails as $email) {
          $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($email);
        }
      }
    }
    $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
    return;
  }

  /**
   * Trigger lightweight PMSR members statistics refresh for one manager email.
   */
  protected function triggerPmsrMembersStatisticsRefreshByManagerEmail(?string $managerEmail): void {
    if (!\Drupal::moduleHandler()->moduleExists('pmsr')) {
      return;
    }

    $email = strtolower(trim((string) $managerEmail));
    if ($email === '') {
      return;
    }

    $baseUrl = \Drupal::request()->getSchemeAndHttpHost();
    $url = rtrim($baseUrl, '/') . '/pmsr/api/statistics/refresh/members?manager_email=' . rawurlencode($email);

    try {
      $response = \Drupal::httpClient()->request('POST', $url, [
        'timeout' => 25,
        'connect_timeout' => 2,
        'http_errors' => FALSE,
      ]);

      $status = (int) $response->getStatusCode();
      $body = (string) $response->getBody();
      $decoded = json_decode($body, TRUE);
      $ok = ($status >= 200 && $status < 300) && (!is_array($decoded) || !array_key_exists('success', $decoded) || !empty($decoded['success']));

      // Fallback to full members refresh when scoped refresh fails.
      if (!$ok) {
        $fallbackUrl = rtrim($baseUrl, '/') . '/pmsr/api/statistics/refresh/members';
        \Drupal::httpClient()->request('POST', $fallbackUrl, [
          'timeout' => 30,
          'connect_timeout' => 2,
          'http_errors' => FALSE,
        ]);
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('rep')->notice('Could not trigger PMSR members statistics refresh: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Normalize a raw manager email-ish value.
   */
  protected function normalizeManagerEmailValue($value): string {
    $email = strtolower(trim((string) $value));
    if ($email === '') {
      return '';
    }

    if (strpos($email, 'mailto:') === 0) {
      $email = substr($email, 7);
    }

    if (preg_match('/(?:^|[?&])manageremail=([^&\s]+)/i', $email, $m)) {
      $email = trim((string) $m[1]);
    }

    $qPos = strpos($email, '?');
    if ($qPos !== FALSE) {
      $email = substr($email, 0, $qPos);
    }

    if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $email, $m)) {
      return strtolower(trim((string) $m[0]));
    }

    return '';
  }

  /**
   * Extract manager emails from a metadata template-like API object.
   */
  protected function extractManagerEmailsFromTemplateObject($template): array {
    $emails = [];
    if (!is_object($template)) {
      return $emails;
    }

    $candidates = [];
    if (!empty($template->hasSIRManagerEmail)) {
      $candidates[] = $template->hasSIRManagerEmail;
    }
    if (!empty($template->principalInvestigator)) {
      $candidates[] = $template->principalInvestigator;
    }
    if (!empty($template->contactEmail)) {
      $candidates[] = $template->contactEmail;
    }

    if (isset($template->hasDataFile) && is_object($template->hasDataFile)) {
      if (!empty($template->hasDataFile->hasSIRManagerEmail)) {
        $candidates[] = $template->hasDataFile->hasSIRManagerEmail;
      }
      if (!empty($template->hasDataFile->principalInvestigator)) {
        $candidates[] = $template->hasDataFile->principalInvestigator;
      }
      if (!empty($template->hasDataFile->contactEmail)) {
        $candidates[] = $template->hasDataFile->contactEmail;
      }
    }

    foreach ($candidates as $candidate) {
      $normalized = $this->normalizeManagerEmailValue($candidate);
      if ($normalized !== '') {
        $emails[$normalized] = true;
      }
    }

    return array_keys($emails);
  }

  /**
   * Extract a concise backend reason from ingest API response payload.
   */
  protected function extractIngestionFailureDetail($uploadResponse): string {
    if ($uploadResponse === NULL || $uploadResponse === FALSE) {
      return '';
    }

    $raw = is_string($uploadResponse) ? trim($uploadResponse) : (string) $uploadResponse;
    if ($raw === '') {
      return '';
    }

    $obj = json_decode($raw);
    if (!is_object($obj)) {
      return '';
    }

    $body = '';
    if (isset($obj->body) && is_string($obj->body)) {
      $body = trim($obj->body);
    } else if (isset($obj->message) && is_string($obj->message)) {
      $body = trim($obj->message);
    }

    if ($body === '') {
      return '';
    }

    $body = preg_replace('/\s+/', ' ', $body);
    return mb_substr($body, 0, 300);
  }

  /**
   * Verify readability of the local DataFile behind the selected template.
   * Returns ['ok' => bool, 'reason' => string, 'tried' => string[]].
   */
  protected function verifyLocalDataFileReadability($template): array {
    $result = [
      'ok' => false,
      'reason' => '',
      'tried' => [],
    ];

    $fileId = NULL;
    if (isset($template->hasDataFile) && is_object($template->hasDataFile) && isset($template->hasDataFile->id)) {
      $fileId = $template->hasDataFile->id;
    }

    if (empty($fileId)) {
      $result['reason'] = 'template has no Drupal File ID (hasDataFile.id)';
      return $result;
    }

    $fileEntity = \Drupal\file\Entity\File::load($fileId);
    if ($fileEntity === NULL) {
      $result['reason'] = 'Drupal file entity not found for FID [' . $fileId . ']';
      return $result;
    }

    $fileUri = (string) $fileEntity->getFileUri();
    $filename = (string) $fileEntity->getFilename();

    $tryPath = function (?string $path) use (&$result): bool {
      if (!is_string($path) || trim($path) === '') {
        return false;
      }
      $path = trim($path);
      $result['tried'][] = $path;
      return is_readable($path);
    };

    // Direct absolute path (if any).
    if ($fileUri !== '' && !str_contains($fileUri, '://') && $tryPath($fileUri)) {
      $result['ok'] = true;
      $result['reason'] = 'readable absolute path';
      return $result;
    }

    // Stream-wrapper URI realpath.
    try {
      $fileSystem = \Drupal::service('file_system');
      $realPath = $fileSystem->realpath($fileUri);
      if (is_string($realPath) && $tryPath($realPath)) {
        $result['ok'] = true;
        $result['reason'] = 'readable stream-wrapper realpath';
        return $result;
      }
    } catch (\Throwable $e) {
      // Continue with explicit fallbacks.
    }

    // public:// explicit fallbacks.
    if ($fileUri !== '' && str_starts_with($fileUri, 'public://')) {
      $relative = ltrim(substr($fileUri, strlen('public://')), '/');

      $publicPath = (string) \Drupal::config('system.file')->get('path.public');
      if ($publicPath !== '') {
        $candidate = DRUPAL_ROOT . '/' . trim($publicPath, '/') . '/' . $relative;
        if ($tryPath($candidate)) {
          $result['ok'] = true;
          $result['reason'] = 'readable configured public path';
          return $result;
        }
      }

      try {
        $fileSystem = \Drupal::service('file_system');
        $publicRoot = $fileSystem->realpath('public://');
        if (is_string($publicRoot) && $publicRoot !== '') {
          $candidate = rtrim($publicRoot, '/') . '/' . $relative;
          if ($tryPath($candidate)) {
            $result['ok'] = true;
            $result['reason'] = 'readable public:// root path';
            return $result;
          }
        }
      } catch (\Throwable $e) {
        // Continue.
      }

      $candidate = DRUPAL_ROOT . '/sites/default/files/' . $relative;
      if ($tryPath($candidate)) {
        $result['ok'] = true;
        $result['reason'] = 'readable default public files path';
        return $result;
      }
    }

    // private:// explicit fallbacks.
    if ($fileUri !== '' && str_starts_with($fileUri, 'private://')) {
      $relative = ltrim(substr($fileUri, strlen('private://')), '/');

      $privatePath = (string) \Drupal::config('system.file')->get('path.private');
      if ($privatePath !== '') {
        $candidate = rtrim($privatePath, '/') . '/' . $relative;
        if ($tryPath($candidate)) {
          $result['ok'] = true;
          $result['reason'] = 'readable configured private path';
          return $result;
        }
      }

      try {
        $fileSystem = \Drupal::service('file_system');
        $privateRoot = $fileSystem->realpath('private://');
        if (is_string($privateRoot) && $privateRoot !== '') {
          $candidate = rtrim($privateRoot, '/') . '/' . $relative;
          if ($tryPath($candidate)) {
            $result['ok'] = true;
            $result['reason'] = 'readable private:// root path';
            return $result;
          }
        }
      } catch (\Throwable $e) {
        // Continue.
      }

      $candidate = DRUPAL_ROOT . '/sites/default/files/private/' . $relative;
      if ($tryPath($candidate)) {
        $result['ok'] = true;
        $result['reason'] = 'readable default private files path';
        return $result;
      }
    }

    // Known fallback for INS bootstrap file.
    if ($filename !== '' && strcasecmp($filename, 'INS-PMSR.xlsx') === 0) {
      try {
        $pmsrPath = \Drupal::service('extension.list.module')->getPath('pmsr');
        if (is_string($pmsrPath) && $pmsrPath !== '') {
          $candidate = DRUPAL_ROOT . '/' . trim($pmsrPath, '/') . '/mts/' . $filename;
          if ($tryPath($candidate)) {
            $result['ok'] = true;
            $result['reason'] = 'readable pmsr module mts fallback';
            return $result;
          }
        }
      } catch (\Throwable $e) {
        // Continue.
      }
    }

    $result['reason'] = 'no readable local path for FID [' . $fileId . '], URI [' . $fileUri . ']';
    $result['tried'] = array_values(array_unique($result['tried']));
    return $result;
  }

  /**
   * Read DataFile state/log to explain ingest failures when API returns no direct reason.
   */
  protected function extractDataFileFailureDetail($api, $template): string {
    if (!isset($template->hasDataFileUri) || !is_string($template->hasDataFileUri) || $template->hasDataFileUri === '') {
      return '';
    }

    $dfUri = Utils::plainUri($template->hasDataFileUri) ?: $template->hasDataFileUri;
    $df = $api->parseObjectResponse($api->getUri($dfUri), 'getUri');
    if (!is_object($df)) {
      return '';
    }

    $parts = [];
    if (isset($df->fileStatus) && is_string($df->fileStatus) && trim($df->fileStatus) !== '') {
      $parts[] = 'DataFile status: ' . trim($df->fileStatus);
    }

    $log = '';
    if (isset($df->log) && is_string($df->log)) {
      $log = trim($df->log);
    } else if (isset($df->hasLog) && is_string($df->hasLog)) {
      $log = trim($df->hasLog);
    }

    if ($log !== '') {
      $logOneLine = preg_replace('/\s+/', ' ', $log);
      if (preg_match('/Error in INSGenerator:[^\n\r]*/i', $logOneLine, $m)) {
        $parts[] = trim($m[0]);
      } else {
        $parts[] = mb_substr($logOneLine, 0, 240);
      }
    }

    return implode(' | ', $parts);
  }

  /**
   * Persist Drupal-side ingestion diagnostics when HASCO DataFile log is empty.
   */
  protected function appendLocalDataFileDiagnosticLog($template, string $message): void {
    if (!is_object($template)) {
      return;
    }

    $dataFileUri = '';
    if (isset($template->hasDataFileUri) && is_string($template->hasDataFileUri) && trim($template->hasDataFileUri) !== '') {
      $dataFileUri = trim((string) $template->hasDataFileUri);
    } else if (isset($template->hasDataFile) && is_object($template->hasDataFile) && isset($template->hasDataFile->uri) && is_string($template->hasDataFile->uri)) {
      $dataFileUri = trim((string) $template->hasDataFile->uri);
    }

    $dataFileUri = Utils::plainUri($dataFileUri) ?: $dataFileUri;
    if ($dataFileUri === '' || trim($message) === '') {
      return;
    }

    $stateKey = 'rep.datafile_local_logs';
    $store = \Drupal::state()->get($stateKey, []);
    if (!is_array($store)) {
      $store = [];
    }

    $existing = '';
    if (isset($store[$dataFileUri]) && is_string($store[$dataFileUri])) {
      $existing = $store[$dataFileUri];
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message);
    $combined = trim($existing) === '' ? $line : ($existing . "\n" . $line);

    // Keep only tail to avoid unbounded growth in state.
    $maxChars = 16000;
    if (strlen($combined) > $maxChars) {
      $combined = substr($combined, -$maxChars);
    }

    $store[$dataFileUri] = $combined;
    \Drupal::state()->set($stateKey, $store);
  }

  /**
   * UNINGEST FUNCTION
   *
   * Behavior:
   *  1) Fetch current MT + DF from the API and preserve them locally (in-memory).
   *  2) Call API to UNINGEST the MT.
   *  3) Recreate/persist the preserved MT/DF locally (status UNPROCESSED).
   *  4) If the DF has a Drupal File entity and its origin is 'api', purge ONLY the
   *     physical binary from private:// (keep the File entity). This forces a
   *     fresh download from the API on the next "Get It".
   *     - If origin is 'local', do NOT purge (API does not own that asset).
   */
  protected function performUningest(array $uris, FormStateInterface $form_state) {
    $api        = \Drupal::service('rep.api_connector');
    $originMgr  = \Drupal::service('rep.file_origin_manager'); // RepFileOriginManager (as discussed)
    $fs         = \Drupal::service('file_system');

    // Expecting a single URI in $uris.
    $uri = reset($uris);

    // 1) Retrieve and preserve current MT.
    $newMT = new MetadataTemplate();
    $mt = $api->parseObjectResponse($api->getUri($uri), 'getUri');
    if ($mt == NULL) {
      \Drupal::messenger()->addError(t('Failed to recover @type for uningestion.', ['@type' => $this->single_class_name]));
      return;
    }
    $newMT->setPreservedMT($mt);

    // 2) Retrieve and preserve DF.
    // Validate that MT has a DataFile URI
    if (empty($mt->hasDataFileUri)) {
      \Drupal::messenger()->addError(t('The @type does not have an associated DataFile URI. Cannot uningest.', ['@type' => $this->single_class_name]));
      \Drupal::logger('rep')->error('performUningest: MT @uri has no hasDataFileUri', ['@uri' => $uri]);
      return;
    }
    
    $df = $api->parseObjectResponse($api->getUri($mt->hasDataFileUri), 'getUri');
    if ($df == NULL) {
      \Drupal::messenger()->addError(t('Failed to recover DataFile of @type before uningestion.', ['@type' => $this->single_class_name]));
      return;
    }
    $newMT->setPreservedDF($df);

    // 3) Call API to UNINGEST.
    $msg = $api->parseObjectResponse($api->uningestMT($mt->uri), 'uningestMT');
    if ($msg == NULL) {
      \Drupal::messenger()->addError(t('The selected @type failed to be uningested.', ['@type' => $this->single_class_name]));
      return;
    }

    // 4) Recreate/persist preserved MT + DF locally (DataFile UNPROCESSED, etc.).
    $savedOk = $newMT->savePreservedMT($this->element_type);
    if (!$savedOk) {
      \Drupal::messenger()->addWarning(t('Uningest succeeded, but local preservation of @type could not be saved.', ['@type' => $this->single_class_name]));
    }

    // 5) Conditional purge of the local binary to enforce freshness on next download.
    //    We only purge if:
    //      - The DataFile has a Drupal File entity (fid).
    //      - The file origin is 'api' (remote owns the asset).
    //    We KEEP the File entity (do NOT delete from DB); we only delete the physical file
    //    so the download controller will re-fetch from the API on next "Get It".
    try {
      if (!empty($df->id)) {
        /** @var \Drupal\file\Entity\File|null $file */
        $file = \Drupal\file\Entity\File::load($df->id);
        if ($file) {
          $origin = $originMgr->getOrigin((int) $file->id());

          // For legacy files without origin tracking, assume 'api' as a reasonable default
          // since most existing files came from the API. Mark it now for future operations.
          if ($origin === NULL) {
            \Drupal::logger('rep')->notice('performUningest: File @fid has unknown origin, assuming API and marking it.', [
              '@fid' => $file->id(),
            ]);
            $originMgr->markApi((int) $file->id(), [
              'df_uri' => $df->uri ?? NULL,
              'migrated' => TRUE,
            ]);
            $origin = 'api';
          }

          if ($origin === 'api') {
            // Purge only the physical binary; keep the File entity record.
            $file_uri  = $file->getFileUri();
            $real_path = $file_uri ? $fs->realpath($file_uri) : NULL;

            if ($real_path && is_file($real_path)) {
              // Delete the physical file from private:// storage.
              $fs->delete($file_uri);
              // Do NOT delete file_managed row; keeping fid allows the controller
              // to trigger a cache-miss and re-download from the API on demand.
              \Drupal::messenger()->addStatus(t('Local binary was purged (API origin). Next "Get It" will fetch a fresh copy from the API.'));
            }
            else {
              // Nothing to purge physically.
              \Drupal::messenger()->addStatus(t('No local binary found to purge (API origin).'));
            }
          }
          elseif ($origin === 'local') {
            // Keep local files created via "Add New" (API does not have the asset).
            \Drupal::messenger()->addWarning(t('Binary was NOT purged because its origin is local. The API does not own this asset.'));
          }
          else {
            // Unexpected origin value (should never reach here after migration logic above).
            \Drupal::messenger()->addWarning(t('Binary was NOT purged due to unexpected origin value "@origin". No action taken.', [
              '@origin' => $origin,
            ]));
          }
        }
      }
    }
    catch (\Throwable $e) {
      // Non-fatal: uningest already succeeded; just warn about purge failure.
      \Drupal::messenger()->addWarning(t('Uningested, but failed to purge local binary: @msg', ['@msg' => $e->getMessage()]));
    }

    \Drupal::messenger()->addStatus(t('The selected @type was successfully uningested.', ['@type' => $this->single_class_name]));
    if ($this->element_type === 'wkf') {
      $refreshEmails = $this->extractManagerEmailsFromTemplateObject($mt);
      if (empty($refreshEmails)) {
        $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($this->manager_email);
      }
      else {
        foreach ($refreshEmails as $email) {
          $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($email);
        }
      }
    }

    // Optional: redirect back to the selector to refresh the list.
    $form_state->setRedirectUrl(self::backSelect($this->element_type, $this->getMode(), $this->studyuri));
    return;
  }


  /**
   * {@inheritdoc}
   */
  public static function backSelect($elementType, $mode, $studyuri)
  {
    $url = Url::fromRoute('rep.select_mt_element');
    $url->setRouteParameter('elementtype', $elementType);
    $url->setRouteParameter('mode', $mode);
    $url->setRouteParameter('page', 0);
    $url->setRouteParameter('pagesize', 9);
    if ($studyuri == NULL || $studyuri == '' || $studyuri == ' ') {
      $url->setRouteParameter('studyuri', 'none');
    } else {
      $url->setRouteParameter('studyuri', $studyuri);
    }
    return $url;
  }

  /**
   * HANDLER FOR DOWNLOAD ONTOLOGIES BUTTON
   */
  public function wkfDownloadOntologiesSubmit(array &$form, FormStateInterface $form_state)
  {
    \Drupal::messenger()->addMessage($this->t('Download Ontologies'));
    // TODO: Implement ontologies download logic
    $form_state->setRebuild();
  }

  /**
   * Build Phase I hierarchy options from Scenario Search process stems.
   */
  private function buildScenarioClinicalProcessOptionsHtml(): string {
    $options = [];
    foreach ($this->getScenarioProcessStemFilters() as $filter) {
      if (!is_array($filter)) {
        continue;
      }

      $label = trim((string) ($filter['label'] ?? ''));
      if ($label === '') {
        continue;
      }

      $value = trim((string) ($filter['slug'] ?? ''));
      if ($value === '') {
        $value = trim((string) ($filter['uri'] ?? ''));
      }
      if ($value === '') {
        $value = $label;
      }

      $options[$value] = $label;
    }

    if (empty($options)) {
      return '<option value="">No clinical process found</option>';
    }

    $html = '';
    foreach ($options as $value => $label) {
      $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
  }

  /**
   * Get aggregated process-stem filters exactly as Scenario Search uses.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getScenarioProcessStemFilters(): array {
    if (!\Drupal::moduleHandler()->moduleExists('std') || !\Drupal::hasService('rep.api_connector')) {
      return [];
    }

    try {
      if (\Drupal::hasService('std.study_variable_search')) {
        $searchService = \Drupal::service('std.study_variable_search');
      }
      else {
        $searchService = new \Drupal\std\Service\StudyVariableSearchService(
          \Drupal::service('rep.api_connector'),
          \Drupal::service('file_system'),
        );
      }

      $currentUser = \Drupal::currentUser();
      $userEmail = trim((string) $currentUser->getEmail());
      $isAdmin = ManageOwnerFilter::isAdmin() || $currentUser->hasPermission('administer study search');

      $context = $searchService->buildContext(
        $userEmail,
        $isAdmin,
        $currentUser->isAuthenticated(),
      );

      $processFilters = is_array($context['process_filters'] ?? NULL)
        ? $context['process_filters']
        : [];

      $aggregated = [];
      foreach ($processFilters as $processData) {
        if (!is_array($processData)) {
          continue;
        }

        $stemSlug = trim((string) ($processData['stem_slug'] ?? ''));
        $stemLabel = trim((string) ($processData['stem_label'] ?? ''));
        $stemUri = trim((string) ($processData['stem_uri'] ?? ''));
        if ($stemSlug === '' || $stemLabel === '') {
          continue;
        }

        if (!isset($aggregated[$stemSlug])) {
          $aggregated[$stemSlug] = [
            'slug' => $stemSlug,
            'label' => $stemLabel,
            'uri' => $stemUri,
            'count' => 0,
          ];
        }

        $aggregated[$stemSlug]['count'] += (int) ($processData['count'] ?? 0);
      }

      uasort($aggregated, static fn(array $a, array $b): int => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
      return array_values($aggregated);
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  /**
   * Convert markdown to HTML for WKF instructions
   */
  private function convertMarkdownToHtml($markdown) {
    if (empty($markdown)) {
      return '';
    }

    $lines = explode("\n", $markdown);
    $html = '';
    $in_ordered_list = false;
    $in_unordered_list = false;

    foreach ($lines as $line) {
      $trimmed = trim($line);
      
      // Headers
      if (preg_match('/^##### (.+)$/', $trimmed, $matches)) {
        if ($in_ordered_list) {
          $html .= '</ol>';
          $in_ordered_list = false;
        }
        if ($in_unordered_list) {
          $html .= '</ul>';
          $in_unordered_list = false;
        }
        $html .= '<h5>' . htmlspecialchars($matches[1]) . '</h5>';
      }
      // Numbered list items (main points)
      elseif (preg_match('/^(\d+)\.\s+\*\*(.+?)\*\*:?\s*$/', $trimmed, $matches)) {
        if (!$in_ordered_list) {
          if ($in_unordered_list) {
            $html .= '</ul>';
            $in_unordered_list = false;
          }
          $html .= '<ol>';
          $in_ordered_list = true;
        }
        $html .= '<li><strong>' . htmlspecialchars($matches[2]) . ':</strong><ul>';
        $in_unordered_list = true;
      }
      // Unordered list items (sub-points with dashes)
      elseif (preg_match('/^\s+-\s+(.+)$/', $line, $matches)) {
        if (!$in_unordered_list) {
          $html .= '<ul>';
          $in_unordered_list = true;
        }
        $html .= '<li>' . htmlspecialchars($matches[1]) . '</li>';
      }
      // Empty line - close unordered list if needed
      elseif (empty($trimmed)) {
        if ($in_unordered_list) {
          $html .= '</ul>';
          $in_unordered_list = false;
        }
      }
    }

    // Close any open lists
    if ($in_unordered_list) {
      $html .= '</ul>';
    }
    if ($in_ordered_list) {
      $html .= '</ol>';
    }

    return $html;
  }
}
