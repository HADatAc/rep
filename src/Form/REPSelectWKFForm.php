<?php

namespace Drupal\rep\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;

/**
 * Dedicated WKF selector and workflow form.
 *
 * This class isolates WKF routing and provides a safe extension point for
 * progressively moving WKF-specific UX out of REPSelectMTForm.
 */
class REPSelectWKFForm extends REPSelectMTForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rep_select_wkf_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $elementtype = NULL, $mode = NULL, $page = 1, $pagesize = 9, $studyuri = NULL) {
    // WKF management is card-first: keep generation panel visible and avoid
    // switching back to the generic table/list workflow.
    $session = \Drupal::request()->getSession();
    $session->set('rep_select_mt_view_type', 'card');
    $form_state->set('view_type', 'card');

    $form = parent::buildForm($form, $form_state, 'wkf', 'card', $page, $pagesize, $studyuri);

    if (isset($form['view_toggle'])) {
      unset($form['view_toggle']);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWkfSpecializedSection(array &$form, FormStateInterface $form_state): void {
    $form['#attached']['library'][] = 'rep/rep_modal';
    $form['#attached']['library'][] = 'rep/wkf_instructions_modal';
    $form['#attached']['library'][] = 'rep/wkf_ingestion_status_poll';
    $form['#attached']['library'][] = 'rep/wkf_validation_panel';
    $form['#attached']['library'][] = 'rep/wkf_phase_packets';

    $wkfWorkflowState = $this->getWkfWorkflowState();
    $hasValidation = !empty($wkfWorkflowState['hasValidation']);
    $lastValidationValid = !empty($wkfWorkflowState['lastValidationValid']);
    $workflowWkfUri = isset($wkfWorkflowState['wkfUri']) && is_string($wkfWorkflowState['wkfUri']) ? $wkfWorkflowState['wkfUri'] : '';
    $workflowSnapshotId = isset($wkfWorkflowState['snapshotId']) && is_string($wkfWorkflowState['snapshotId']) ? $wkfWorkflowState['snapshotId'] : '';
    $workflowVersionLabel = $this->buildWkfVersionLabel($workflowWkfUri, $workflowSnapshotId);
    $workflowValidationState = $hasValidation
      ? ($lastValidationValid ? 'PASS' : 'FAIL')
      : 'N/A';

    $phase3Enabled = TRUE;
    $phase4Enabled = ($hasValidation && $lastValidationValid);
    $phase5Enabled = ($hasValidation && $lastValidationValid);

    $phase3PromptDisabledAttr = '';
    $phase4PromptDisabledAttr = $phase4Enabled
      ? ''
      : ' disabled aria-disabled="true" title="Official Phase III opens after successful validation of the current WKF version."';
    $phase5PromptDisabledAttr = $phase5Enabled
      ? ''
      : ' disabled aria-disabled="true" title="Official Phase IV opens after successful validation of the current WKF version."';

    $phaseGateHint = 'Official phases are II, III, and IV. Task-model verification/correction is optional and can run when needed.';

    $form['wkf_validation_panel_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'wkf-validation-panel-wrapper'],
    ];
    $validationPanel = $this->buildWkfValidationPanel();
    if ($validationPanel !== NULL) {
      $form['wkf_validation_panel_wrapper']['wkf_validation_panel'] = $validationPanel;
    }

    $form['wkf_generation_section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wkf-generation-section', 'mb-4', 'p-3', 'border', 'rounded', 'bg-light']],
    ];

    $form['wkf_generation_section']['section_title'] = [
      '#type' => 'item',
      '#markup' => '<h5 class="mb-3">WKF Generation</h5>',
    ];

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

    $form['wkf_generation_section']['packet_context_hidden'] = [
      '#type' => 'markup',
      '#markup' => '<input type="hidden" id="wkf-active-uri" value="' . Html::escape($workflowWkfUri) . '" />'
        . '<input type="hidden" id="wkf-source-document-context" value="" />'
        . '<input type="hidden" id="wkf-phase1-core-context" value="" />'
        . '<input type="hidden" id="wkf-version-label" value="' . Html::escape($workflowVersionLabel) . '" />',
    ];

    $form['wkf_generation_section']['phase_grid'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'g-3', 'mt-1', 'flex-nowrap'],
        'style' => 'overflow-x:auto;'
      ],
    ];

    $clinicalHierarchyOptionsHtml = $this->buildScenarioClinicalProcessOptionsHtml();
    $phase1DownloadUrl = Url::fromRoute('rep.wkf_phase1_download')->toString();
    $knowledgeGraphHierarchyUrl = Url::fromRoute('rep.tree_form', [
      'mode' => 'modal',
      'elementtype' => 'workflowstem',
      'silent' => 'false',
      'prefix' => 'false',
    ])->toString();

    $form['wkf_generation_section']['phase_grid']['phase1'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col'], 'style' => 'min-width:240px;'],
    ];
    $form['wkf_generation_section']['phase_grid']['phase1']['card'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div class="card h-100 shadow-sm">
          <div class="card-body d-flex flex-column gap-2">
            <h6 class="card-title">Phase I - Core WKF Generation</h6>
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
            <div class="mb-2">
              <label for="phase1SourceDocument" class="form-label mb-1">Source document</label>
              <input type="file" id="phase1SourceDocument" class="form-control form-control-sm" accept=".txt,.md,.doc,.docx,.pdf,.rtf" />
            </div>
            <button type="button" class="btn btn-primary btn-sm mt-auto" id="generatePhase1DraftWkf" data-download-url="' . $phase1DownloadUrl . '" disabled aria-disabled="true" title="Provide name, clinical process, and source document.">Generate Draft WKF</button>
          </div>
        </div>
      '),
    ];

    $form['wkf_generation_section']['phase_grid']['phase2'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col'], 'style' => 'min-width:240px;'],
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

    $form['wkf_generation_section']['phase_grid']['phase4'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col'], 'style' => 'min-width:240px;'],
    ];
    $form['wkf_generation_section']['phase_grid']['phase4']['card'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div class="card h-100 shadow-sm">
          <div class="card-body d-flex flex-column gap-2">
            <h6 class="card-title">Phase III - Properties Extraction</h6>
            <button type="button" class="btn btn-outline-info btn-sm open-wkf-phase-instructions-window" data-instructions-target="#wkf-phase4-instructions">Instruction</button>
            <p class="small text-muted mb-2">Extract and encode detailed properties into the same core WKF.</p>
            <button type="button" class="btn btn-primary btn-sm mt-auto" data-bs-toggle="modal" data-bs-target="#wkfPhase4PromptModal"' . $phase4PromptDisabledAttr . '>Open Prompt</button>
          </div>
        </div>
      '),
    ];

    $form['wkf_generation_section']['phase_grid']['phase5'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col'], 'style' => 'min-width:240px;'],
    ];
    $form['wkf_generation_section']['phase_grid']['phase5']['card'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div class="card h-100 shadow-sm">
          <div class="card-body d-flex flex-column gap-2">
            <h6 class="card-title">Phase IV - Simulations Assignments</h6>
            <button type="button" class="btn btn-outline-info btn-sm open-wkf-phase-instructions-window" data-instructions-target="#wkf-phase5-instructions">Instruction</button>
            <p class="small text-muted mb-2">Assign simulation assets and finalize WKF ingestion readiness.</p>
            <button type="button" class="btn btn-primary btn-sm mt-auto" data-bs-toggle="modal" data-bs-target="#wkfPhase5PromptModal"' . $phase5PromptDisabledAttr . '>Open Prompt</button>
          </div>
        </div>
      '),
    ];

    $pmsr_module_path = \Drupal::service('extension.list.module')->getPath('pmsr');
    $instructions_en_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/instructions/instructions_EN.md';
    $instructions_pt_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/instructions/instructions_PT.md';

    $instructions_en_md = file_exists($instructions_en_path) ? file_get_contents($instructions_en_path) : '';
    $instructions_pt_md = file_exists($instructions_pt_path) ? file_get_contents($instructions_pt_path) : '';

    $instructions_en = $this->convertMarkdownToHtml($instructions_en_md);
    $instructions_pt = $this->convertMarkdownToHtml($instructions_pt_md);

    $form['wkf_generation_section']['phase_instruction_payloads'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div id="wkf-phase1-instructions" class="d-none">
          <div class="instructions-content-phase" data-lang="en"><h5>Phase I - Core WKF Generation</h5><p>Fill the small form and generate a real ingestion-ready Draft WKF (version 1) directly from the panel.</p><ul><li>Provide a Core WKF Name.</li><li>Select a Clinical Process from the hierarchy used in Scenario Search.</li><li>Download the generated WKF file and reuse it in Phases II to V.</li></ul></div>
          <div class="instructions-content-phase" data-lang="pt"><h5>Phase I - Core WKF Generation</h5><p>Preencha o formulario e gere/baixe um WKF draft real diretamente do painel. Anexe o documento original no ChatGPT e use o WKF base gerado para as fases seguintes.</p><ul><li>Foque em entidades e relacoes estruturais.</li><li>Evite detalhes que pertencem as fases posteriores.</li><li>Baixe o WKF core para reutilizacao.</li></ul></div>
        </div>
        <div id="wkf-phase2-instructions" class="d-none">
          <div class="instructions-content-phase" data-lang="en"><h5>Phase II - Task Model Generation</h5><p>Use the original document and the Phase I core WKF together. Ask ChatGPT to derive and encode task-model semantics into the same WKF.</p></div>
          <div class="instructions-content-phase" data-lang="pt"><h5>Phase II - Task Model Generation</h5><p>Use o documento original e o WKF core da Fase I. Solicite ao ChatGPT a geracao do modelo de tarefas no mesmo WKF.</p></div>
        </div>
        <div id="wkf-phase4-instructions" class="d-none">
          <div class="instructions-content-phase" data-lang="en"><h5>Phase III - Properties Extraction</h5><p>Inspect detailed attributes from the source document and encode them into the existing WKF without breaking previously generated structure.</p></div>
          <div class="instructions-content-phase" data-lang="pt"><h5>Phase III - Properties Extraction</h5><p>Extraia propriedades detalhadas do documento e codifique no WKF existente sem quebrar a estrutura das fases anteriores.</p></div>
        </div>
        <div id="wkf-phase5-instructions" class="d-none">
          <div class="instructions-content-phase" data-lang="en"><h5>Phase IV - Simulations Assignments</h5><p>Complete simulation assignments and final readiness checks so the WKF can be ingested into PMSR.</p></div>
          <div class="instructions-content-phase" data-lang="pt"><h5>Phase IV - Simulations Assignments</h5><p>Finalize atribuicoes de simulacao e verificacoes finais para ingestao do WKF no PMSR.</p></div>
        </div>
        <div id="wkf-legacy-instructions" class="d-none">
          <div id="instructions-en" class="instructions-content">' . $instructions_en . '</div>
          <div id="instructions-pt" class="instructions-content">' . $instructions_pt . '</div>
        </div>
      '),
    ];

    $phase2_prompt_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/prompts/PROMPT-WKF-PHASE2-TASKS-TSV.md';
    $phase3_prompt_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/prompts/PROMPT-WKF-PHASE3-TASK-MODEL-CORRECTION.md';
    $phase4_prompt_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/prompts/PROMPT-WKF-PHASE3-STD-TSV.md';
    $phase5_prompt_path = DRUPAL_ROOT . '/' . $pmsr_module_path . '/prompts/PROMPT-WKF-PHASE4-TASKS-USES-COMPONENT-TSV.md';

    $phase2_prompt = file_exists($phase2_prompt_path) ? file_get_contents($phase2_prompt_path) : "PHASE II - TASK MODEL GENERATION\n\nUse the original document and the Phase I core WKF.\nEncode task model details into the same WKF and return the updated file.";
    $phase3_prompt = file_exists($phase3_prompt_path) ? file_get_contents($phase3_prompt_path) : "OPTIONAL ACTIVITY - TASK MODEL VERIFICATION\n\nUse the original document and the current WKF to verify/correct task-model issues only when needed. Return only the Tasks sheet TSV (header + rows).";
    $phase4_prompt = file_exists($phase4_prompt_path) ? file_get_contents($phase4_prompt_path) : "PHASE III - PROPERTIES EXTRACTION\n\nUse the original document and the current WKF from previous phases.\nExtract missing properties and encode them in the same WKF. Return the updated file.";
    $phase5_prompt = file_exists($phase5_prompt_path) ? file_get_contents($phase5_prompt_path) : "PHASE IV - SIMULATIONS ASSIGNMENTS\n\nUse the original document and the current WKF.\nAssign simulation mappings and finalize ingestion-readiness fields. Return the final WKF file.";

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
                <h5 class="modal-title" id="wkfPhase3PromptModalLabel">Optional Activity - Task Model Verification Prompt</h5>
                <div class="ms-auto d-flex gap-2">
                  <button type="button" class="btn btn-primary wkf-copy-prompt" data-source="#phase3PromptText"><i class="fas fa-copy"></i> Copy to Clipboard</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
              </div>
              <div class="modal-body">
                <div class="alert alert-info">
                  <i class="fas fa-info-circle"></i> Use this to review and correct task model inconsistencies before property extraction.
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
                <h5 class="modal-title" id="wkfPhase4PromptModalLabel">Phase III - Properties Extraction Prompt</h5>
                <div class="ms-auto d-flex gap-2">
                  <button type="button" class="btn btn-primary wkf-copy-prompt" data-source="#phase4PromptText"><i class="fas fa-copy"></i> Copy to Clipboard</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
              </div>
              <div class="modal-body">
                <div class="alert alert-info">
                  <i class="fas fa-info-circle"></i> Use this with the original document and current WKF to extract and encode detailed properties.
                </div>
                <pre id="phase4PromptText" class="p-3 bg-light border rounded" style="white-space: pre-wrap;">' . $phase4_prompt . '</pre>
              </div>
            </div>
          </div>
        </div>
      '),
    ];

    $form['wkf_generation_section']['phase5_prompt_modal'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div class="modal fade" id="wkfPhase5PromptModal" tabindex="-1" aria-labelledby="wkfPhase5PromptModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="wkfPhase5PromptModalLabel">Phase IV - Simulations Assignments Prompt</h5>
                <div class="ms-auto d-flex gap-2">
                  <button type="button" class="btn btn-primary wkf-copy-prompt" data-source="#phase5PromptText"><i class="fas fa-copy"></i> Copy to Clipboard</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
              </div>
              <div class="modal-body">
                <div class="alert alert-info">
                  <i class="fas fa-info-circle"></i> Use this final phase prompt with the original document and current WKF to complete PMSR ingestion readiness.
                </div>
                <pre id="phase5PromptText" class="p-3 bg-light border rounded" style="white-space: pre-wrap;">' . $phase5_prompt . '</pre>
              </div>
            </div>
          </div>
        </div>
      '),
    ];

    $form['wkf_generation_section']['phase_packet_modal'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div class="modal fade" id="wkfPhasePacketModal" tabindex="-1" aria-labelledby="wkfPhasePacketModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="wkfPhasePacketModalLabel">WKF Phase Packet</h5>
                <div class="ms-auto d-flex gap-2">
                  <button type="button" class="btn btn-primary" id="wkf-phase-packet-copy"><i class="fas fa-copy"></i> Copy</button>
                  <button type="button" class="btn btn-outline-primary" id="wkf-phase-packet-download"><i class="fas fa-download"></i> Download</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
              </div>
              <div class="modal-body">
                <input type="hidden" id="wkf-phase-packet-filename" value="wkf-phase-packet.txt" />
                <div class="d-flex align-items-center gap-2 mb-2">
                  <label class="mb-0" for="wkf-phase-packet-history" style="white-space:nowrap;">Saved packets</label>
                  <select id="wkf-phase-packet-history" class="form-select form-select-sm">
                    <option value="">Select saved packet...</option>
                  </select>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="wkf-phase-packet-refresh-history">Refresh</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="wkf-phase-packet-load-history">Load</button>
                  <button type="button" class="btn btn-outline-danger btn-sm" id="wkf-phase-packet-delete-history">Delete Selected</button>
                  <button type="button" class="btn btn-danger btn-sm" id="wkf-phase-packet-clear-history">Clear All</button>
                </div>
                <textarea id="wkf-phase-packet-output" class="form-control" rows="16" readonly></textarea>
              </div>
            </div>
          </div>
        </div>
      '),
    ];

    $form['wkf_generation_section']['phase_response_modal'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div class="modal fade" id="wkfPhaseResponseModal" tabindex="-1" aria-labelledby="wkfPhaseResponseModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="wkfPhaseResponseModalLabel">Apply ChatGPT Response to WKF</h5>
                <div class="ms-auto d-flex gap-2">
                  <button type="button" class="btn btn-success" id="wkf-phase-response-apply">Apply Response</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
              </div>
              <div class="modal-body">
                <input type="hidden" id="wkf-phase-response-phase" value="2" />
                <div class="d-flex align-items-center gap-2 mb-2">
                  <label class="mb-0" for="wkf-phase-response-history" style="white-space:nowrap;">Saved responses</label>
                  <select id="wkf-phase-response-history" class="form-select form-select-sm">
                    <option value="">Select saved response...</option>
                  </select>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="wkf-phase-response-refresh-history">Refresh</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="wkf-phase-response-load-history">Load</button>
                  <button type="button" class="btn btn-outline-danger btn-sm" id="wkf-phase-response-delete-history">Delete Selected</button>
                  <button type="button" class="btn btn-danger btn-sm" id="wkf-phase-response-clear-history">Clear All</button>
                </div>
                <div class="row g-2 mb-2">
                  <div class="col-md-4">
                    <label for="wkf-phase-response-status" class="form-label mb-1">Save as</label>
                    <select id="wkf-phase-response-status" class="form-select form-select-sm">
                      <option value="current">CURRENT</option>
                      <option value="draft">DRAFT</option>
                    </select>
                  </div>
                  <div class="col-md-8 d-flex align-items-end">
                    <div class="form-check mb-1">
                      <input class="form-check-input" type="checkbox" id="wkf-phase-response-auto-validate" checked />
                      <label class="form-check-label" for="wkf-phase-response-auto-validate">Validate immediately after apply</label>
                    </div>
                  </div>
                </div>
                <textarea id="wkf-phase-response-input" class="form-control" rows="16" placeholder="Paste ChatGPT full WKF response text here..."></textarea>
                <div id="wkf-phase-response-status-msg" class="small mt-2 text-muted"></div>
              </div>
            </div>
          </div>
        </div>
      '),
    ];

    $form['wkf_generation_section']['sheet_update_modal'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div class="modal fade" id="wkfSheetUpdateModal" tabindex="-1" aria-labelledby="wkfSheetUpdateModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="wkfSheetUpdateModalLabel">WKF Sheet Update</h5>
                <div class="ms-auto d-flex gap-2">
                  <button type="button" class="btn btn-success" id="wkf-sheet-update-apply">Apply Update</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
              </div>
              <div class="modal-body">
                <input type="hidden" id="wkf-sheet-update-wkf-uri" value="" />
                <input type="hidden" id="wkf-sheet-update-type" value="" />
                <input type="hidden" id="wkf-sheet-update-phase" value="" />
                <div class="small text-muted mb-2" id="wkf-sheet-update-hint"></div>
                <label for="wkf-sheet-update-input" class="form-label">Paste TSV content</label>
                <textarea id="wkf-sheet-update-input" class="form-control" rows="14" placeholder="Paste TSV rows here..."></textarea>
                <div id="wkf-sheet-update-status" class="small mt-2 text-muted"></div>
              </div>
            </div>
          </div>
        </div>
      '),
    ];
  }

  /**
   * Redirect helper for the dedicated WKF selector route.
   */
  public static function backSelect($elementType, $mode, $studyuri) {
    $url = Url::fromRoute('rep.select_wkf_element');
    $url->setRouteParameter('mode', $mode);
    $url->setRouteParameter('page', 0);
    $url->setRouteParameter('pagesize', 9);

    if ($studyuri == NULL || $studyuri == '' || $studyuri == ' ') {
      $url->setRouteParameter('studyuri', 'none');
    }
    else {
      $url->setRouteParameter('studyuri', $studyuri);
    }

    return $url;
  }

}
