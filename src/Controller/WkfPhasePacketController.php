<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\VSTOI;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds ChatGPT-ready packet payloads for WKF phases II-IV.
 */
class WkfPhasePacketController extends ControllerBase {

  /**
   * Download latest WKF workbook-like TSV for a given WKF URI.
   */
  public function downloadCurrentWkf(string $wkfuri, Request $request): Response {
    $decoded = base64_decode($wkfuri, TRUE);
    $wkfUri = is_string($decoded) ? trim($decoded) : '';
    if ($wkfUri === '') {
      return new Response('Invalid WKF URI.', 400);
    }

    $scope = $this->resolveWkfScope($request, $wkfUri);
    $content = $this->resolveCurrentWkfWorkbookContent($request, $wkfUri, $scope);
    if ($content === '') {
      $phase1 = $this->getPersistedPhase1ContextByUri($wkfUri);
      if (isset($phase1['phase1WkfTableTsv']) && is_string($phase1['phase1WkfTableTsv'])) {
        $content = trim($phase1['phase1WkfTableTsv']);
      }
    }

    if ($content !== '') {
      $phase1 = $this->getPersistedPhase1ContextByUri($wkfUri);
      $wkfName = isset($phase1['wkfName']) && is_string($phase1['wkfName']) ? trim($phase1['wkfName']) : '';
      if ($wkfName === '') {
        $wkfName = $wkfUri;
      }

      $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $wkfName) ?? 'wkf';
      $safeName = trim((string) $safeName, '_');
      if ($safeName === '') {
        $safeName = 'wkf';
      }

      $filename = $safeName . '-current.tsv';
      $response = new Response($content . "\n", 200, [
        'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
      ]);

      return $response;
    }

    // Fallback to DataFile download route when no scoped WKF copy exists.
    $api = \Drupal::service('rep.api_connector');
    $wkf = $api->parseObjectResponse($api->getUri($wkfUri), 'getUri');
    if (is_object($wkf) && isset($wkf->hasDataFile) && is_object($wkf->hasDataFile) && !empty($wkf->hasDataFile->uri)) {
      $routeUrl = \Drupal\Core\Url::fromRoute('rep.datafile_download', [
        'datafileuri' => base64_encode((string) $wkf->hasDataFile->uri),
      ])->toString();
      return new RedirectResponse($routeUrl);
    }

    return new Response('Could not resolve current WKF content for download.', 404);
  }

  /**
   * Build packet text payload for a WKF phase.
   */
  public function buildPacket(int $phase, Request $request): JsonResponse {
    $phase = $this->normalizeLegacyPhase($phase);
    if ($phase < 2 || $phase > 4) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Only phases II to IV are supported by this endpoint.',
      ], 400);
    }

    // Phase IV can require large organization/deployment aggregation.
    // Extend request budget to prevent 30s hard-fail during packet assembly.
    if ($phase === 4) {
      @set_time_limit(180);
    }

    $payload = $this->decodePayload($request);
    $wkfUri = trim((string) ($payload['wkfUri'] ?? ''));
    $sourceDoc = trim((string) ($payload['sourceDocument'] ?? ''));
    $phase1WkfTableTsv = trim((string) ($payload['phase1WkfTableTsv'] ?? ''));
    $promptOverride = trim((string) ($payload['promptOverride'] ?? ''));
    $validationMessages = trim((string) ($payload['validationMessages'] ?? ''));
    $wkfContent = trim((string) ($payload['wkfContent'] ?? ''));
    $versionLabel = trim((string) ($payload['versionLabel'] ?? ''));

    // Frontend may send only filename metadata (e.g. "Supporting document filename: x").
    // Treat that as missing content so backend can recover persisted full source text.
    if ($this->isSourceDocumentMetadataOnly($sourceDoc)) {
      $sourceDoc = '';
    }
    if ($this->isInvalidSourceDocumentContent($sourceDoc)) {
      $sourceDoc = '';
    }

    $sessionContext = $this->resolveWkfScopedSessionContext($request, $wkfUri);
    if ($sourceDoc === '' && isset($sessionContext['sourceDocument']) && is_string($sessionContext['sourceDocument'])) {
      $sourceDoc = trim($sessionContext['sourceDocument']);
    }
    if ($this->isSourceDocumentMetadataOnly($sourceDoc)) {
      $sourceDoc = '';
      $phase1Context = $this->getPersistedPhase1ContextByUri($wkfUri);
      if (isset($phase1Context['sourceDocumentContent']) && is_string($phase1Context['sourceDocumentContent'])) {
        $candidate = trim($phase1Context['sourceDocumentContent']);
        if ($candidate !== '') {
          $sourceDoc = $candidate;
        }
      }
    }
    if ($this->isInvalidSourceDocumentContent($sourceDoc)) {
      $sourceDoc = '';
    }
    if ($phase1WkfTableTsv === '' && isset($sessionContext['phase1WkfTableTsv']) && is_string($sessionContext['phase1WkfTableTsv'])) {
      $phase1WkfTableTsv = trim($sessionContext['phase1WkfTableTsv']);
    }
    if ($validationMessages === '' && isset($sessionContext['validationMessages']) && is_string($sessionContext['validationMessages'])) {
      $validationMessages = trim($sessionContext['validationMessages']);
    }
    if ($wkfContent === '' && isset($sessionContext['wkfContent']) && is_string($sessionContext['wkfContent'])) {
      $wkfContent = trim($sessionContext['wkfContent']);
    }
    $scope = $this->resolveWkfScope($request, $wkfUri);
    if ($wkfContent === '') {
      $workingCopy = $this->getWorkingCopyForScope($scope);
      if (isset($workingCopy['wkfContent']) && is_string($workingCopy['wkfContent'])) {
        $wkfContent = trim($workingCopy['wkfContent']);
      }
    }
    $snapshotId = isset($sessionContext['snapshotId']) && is_string($sessionContext['snapshotId'])
      ? trim($sessionContext['snapshotId'])
      : '';
    $validatedAt = isset($sessionContext['validatedAt']) && is_string($sessionContext['validatedAt'])
      ? trim($sessionContext['validatedAt'])
      : '';
    if ($versionLabel === '' && isset($sessionContext['versionLabel']) && is_string($sessionContext['versionLabel'])) {
      $versionLabel = trim($sessionContext['versionLabel']);
    }

    $promptText = $this->getPhasePromptText($phase);
    if ($promptOverride !== '' && $this->isPromptOverrideCompatibleWithPhase($promptOverride, $phase)) {
      $promptText = $promptOverride;
    }
    $latestWorkbookTsv = $this->resolveCurrentWkfWorkbookContent($request, $wkfUri, $scope);
    if ($latestWorkbookTsv === '') {
      if ($wkfContent !== '') {
        $latestWorkbookTsv = $wkfContent;
      }
      elseif ($phase1WkfTableTsv !== '') {
        $latestWorkbookTsv = $phase1WkfTableTsv;
      }
    }

    $latestTasksSheetTsv = '';
    if ($latestWorkbookTsv !== '') {
      $latestTasksSheetTsv = $this->extractSheetBlockFromWorkbookTsv($latestWorkbookTsv, 'Tasks');
      if ($latestTasksSheetTsv === '') {
        $latestTasksSheetTsv = $this->extractTasksSheetFromAnyTsv($latestWorkbookTsv);
      }
    }

    $stdSheetTsv = '';
    if (($phase === 2 || $phase === 3) && $latestWorkbookTsv !== '') {
      $stdSheetTsv = $this->extractSheetBlockFromWorkbookTsv($latestWorkbookTsv, 'STD');
      if ($stdSheetTsv === '') {
        $stdSheetTsv = $this->extractStdSheetFromAnyTsv($latestWorkbookTsv);
      }
      if ($phase === 3 && $stdSheetTsv !== '') {
        $stdSheetTsv = $this->canonicalizeStdSheetForPhase3($stdSheetTsv, $latestWorkbookTsv);
      }
    }

    $instrumentInstanceComponentList = '';
    if ($phase === 4) {
      $instrumentInstanceComponentList = $this->buildInstrumentInstanceComponentList($wkfUri, $latestWorkbookTsv);
    }

    $wkfSpecV3Text = $phase === 2 ? $this->getWkfSpecV3Text() : '';
    $phase1TasksSheetTsv = '';
    if ($phase === 2) {
      // Keep legacy section name for compatibility while feeding latest Tasks sheet content.
      $phase1TasksSheetTsv = $latestTasksSheetTsv;
    }

    $phaseTitle = $this->getPhaseTitle($phase);
    $lines = [];
    $lines[] = 'WKF PHASE PACKET - ' . $phaseTitle;
    $lines[] = 'Generated at: ' . date('Y-m-d H:i:s');
    if ($wkfUri !== '') {
      $lines[] = 'WKF URI: ' . $wkfUri;
    }
    if ($versionLabel !== '') {
      $lines[] = 'WKF Version: ' . $versionLabel;
    }
    if ($snapshotId !== '') {
      $lines[] = 'Validation Snapshot ID: ' . $snapshotId;
    }
    if ($validatedAt !== '') {
      $lines[] = 'Validated at: ' . $validatedAt;
    }
    $lines[] = '';
    if ($phase === 4) {
      $lines[] = 'EXECUTION INSTRUCTION';
      $lines[] = 'Process this as the current Phase IV request. Ignore any prior Phase III request or response in this conversation.';
      $lines[] = 'Return the complete updated Tasks TSV now, applying the Phase IV component-instance assignment rules below.';
      $lines[] = '';
    }
    $lines[] = 'GOAL';
    $lines[] = $this->getPhaseGoal($phase);
    $lines[] = '';
    $lines[] = 'PRIMARY PROMPT';
    $lines[] = $promptText !== '' ? $promptText : '(phase prompt is unavailable)';
    $lines[] = '';

    $lines[] = 'SOURCE DOCUMENT CONTENT GATE - ABSOLUTE';
    $lines[] = 'SOURCE_DOCUMENT_CONTENT MUST contain the actual textual content of the supporting document.';
    $lines[] = 'A filename alone is NOT source-document content.';
    $lines[] = 'If SOURCE_DOCUMENT_CONTENT contains only a filename, file path, document title, document identifier, metadata, or attachment reference, the source document is considered missing.';
    $lines[] = 'If actual source document content is not present, return exactly:';
    $lines[] = 'ABORT: Missing required inputs (supporting document and Phase I WKF are both required).';
    $lines[] = '';

    $lines[] = 'INPUT VALIDATION GATE';
    if ($phase === 2) {
      $lines[] = 'If WKF_SPEC_V3_CONTENT is empty OR PHASE1_TASKS_SHEET_TSV is empty, return exactly:';
      $lines[] = 'ABORT: Missing required inputs (WKF-SPEC-V3 and Phase I Tasks sheet are both required).';
    }
    else {
      $lines[] = 'If PHASE1_WKF_TABLE_TSV is empty, return exactly:';
      $lines[] = 'ABORT: Missing required inputs (supporting document and Phase I WKF are both required).';
    }
    $lines[] = '';

    if ($phase === 2) {
      $lines[] = 'WKF_SPEC_V3_CONTENT';
      $lines[] = 'BEGIN_WKF_SPEC_V3_CONTENT';
      $lines[] = $wkfSpecV3Text !== '' ? $wkfSpecV3Text : '[MISSING_WKF_SPEC_V3_CONTENT]';
      $lines[] = 'END_WKF_SPEC_V3_CONTENT';
      $lines[] = '';

      $lines[] = 'PHASE1_TASKS_SHEET_TSV';
      $lines[] = 'BEGIN_PHASE1_TASKS_SHEET_TSV';
      $lines[] = $phase1TasksSheetTsv !== '' ? $phase1TasksSheetTsv : '[MISSING_PHASE1_TASKS_SHEET_TSV]';
      $lines[] = 'END_PHASE1_TASKS_SHEET_TSV';
      $lines[] = '';

      $lines[] = 'STD_SHEET_TSV';
      $lines[] = 'BEGIN_STD_SHEET_TSV';
      $lines[] = $stdSheetTsv !== '' ? $stdSheetTsv : '[MISSING_STD_SHEET_TSV]';
      $lines[] = 'END_STD_SHEET_TSV';
      $lines[] = '';

      $lines[] = 'INPUT CONSISTENCY GATE';
      $lines[] = 'If PHASE1_TASKS_SHEET_TSV clearly refers to a different clinical process than SOURCE_DOCUMENT_CONTENT, return exactly:';
      $lines[] = 'ABORT: Inconsistent inputs (Phase I Tasks sheet and source document refer to different processes).';
      $lines[] = '';
    }

    $lines[] = 'SOURCE_DOCUMENT_CONTENT';
    $lines[] = 'BEGIN_SOURCE_DOCUMENT_CONTENT';
    $lines[] = $sourceDoc !== '' ? $sourceDoc : '[MISSING_SOURCE_DOCUMENT_CONTENT]';
    $lines[] = 'END_SOURCE_DOCUMENT_CONTENT';
    $lines[] = '';

    if ($phase !== 2) {
      $lines[] = 'PHASE1_WKF_TABLE_TSV';
      $lines[] = 'BEGIN_PHASE1_WKF_TABLE_TSV';
      if ($phase1WkfTableTsv !== '') {
        $lines[] = $phase1WkfTableTsv;
      }
      elseif ($wkfContent !== '') {
        $lines[] = $wkfContent;
      }
      else {
        $lines[] = '[MISSING_PHASE1_WKF_TABLE_TSV]';
      }
      $lines[] = 'END_PHASE1_WKF_TABLE_TSV';
      $lines[] = '';
    }

    if ($phase === 3) {
      $lines[] = 'CURRENT_STD_SHEET_TSV';
      $lines[] = 'BEGIN_CURRENT_STD_SHEET_TSV';
      $lines[] = $stdSheetTsv !== '' ? $stdSheetTsv : '[MISSING_STD_SHEET_TSV]';
      $lines[] = 'END_CURRENT_STD_SHEET_TSV';
      $lines[] = '';
    }

    if ($phase === 4) {
      $lines[] = 'LATEST_TASKS_SHEET_TSV';
      $lines[] = 'BEGIN_LATEST_TASKS_SHEET_TSV';
      $lines[] = $latestTasksSheetTsv !== '' ? $latestTasksSheetTsv : '[MISSING_LATEST_TASKS_SHEET_TSV]';
      $lines[] = 'END_LATEST_TASKS_SHEET_TSV';
      $lines[] = '';

      $lines[] = 'INSTRUMENT_INSTANCES_WITH_COMPONENT_INSTANCES';
      $lines[] = 'BEGIN_INSTRUMENT_INSTANCES_WITH_COMPONENT_INSTANCES';
      $lines[] = $instrumentInstanceComponentList !== '' ? $instrumentInstanceComponentList : '[MISSING_INSTRUMENT_INSTANCE_COMPONENT_LIST]';
      $lines[] = 'END_INSTRUMENT_INSTANCES_WITH_COMPONENT_INSTANCES';
      $lines[] = '';
    }

    if ($validationMessages !== '' && ($phase === 2 || $phase === 3)) {
      $lines[] = 'OPTIONAL_VALIDATION_MESSAGES';
      $lines[] = 'BEGIN_VALIDATION_MESSAGES';
      $lines[] = $validationMessages;
      $lines[] = 'END_VALIDATION_MESSAGES';
      $lines[] = '';
    }

    $suggestedTsvFileName = $this->buildSuggestedPhaseTsvFileName($phase, $wkfUri);

    $lines[] = 'RESPONSE CONTRACT';
    $lines[] = 'SUGGESTED_OUTPUT_FILENAME: ' . $suggestedTsvFileName;
    if ($phase === 2) {
      $lines[] = 'Using SOURCE_DOCUMENT_CONTENT, PHASE1_TASKS_SHEET_TSV, and the rules in WKF_SPEC_V3_CONTENT, generate the updated Phase II Tasks sheet as a .tsv FILE artifact.';
      $lines[] = 'Return the .tsv FILE content (the exact file body), not a description and not a copy-link wrapper.';
      $lines[] = 'Return exactly these 3 sections in this order:';
      $lines[] = '1) OUTPUT_TASKS_SHEET_TSV';
      $lines[] = '2) OUTPUT_SUMMARY_JSON';
      $lines[] = '3) OUTPUT_ASSUMPTIONS';
      $lines[] = 'Section OUTPUT_TASKS_SHEET_TSV must contain only the Tasks header row followed by task rows (tab-separated).';
      $lines[] = 'TSV SERIALIZATION — NON-NEGOTIABLE:';
      $lines[] = '- OUTPUT_TASKS_SHEET_TSV is the machine-readable content of a .tsv FILE.';
      $lines[] = '- Header line MUST use real TAB characters (U+0009) between exact Tasks-sheet column names.';
      $lines[] = '- Every task row MUST use real TAB characters (U+0009) and have the same field count as the header.';
      $lines[] = '- NEVER use spaces, markdown tables, pipes, commas, or literal "\\t" as separators.';
      $lines[] = '- Do not wrap OUTPUT_TASKS_SHEET_TSV in markdown fences and do not prepend/append prose to the TSV section.';
      $lines[] = '- FIELD COUNT RULE: internally verify split(line, "\\t") count equals header count for every task row; if not, correct and revalidate before returning.';
      $lines[] = '- HEADER RULE: first line MUST be the Tasks-sheet header extracted from Phase I workbook; do not recreate, reorder, rename, or infer.';
      $lines[] = 'Do NOT output a full WKF workbook, multiple sheets, JSON object, or .xlsx content.';
      $lines[] = 'Do NOT include InfoSheet, Namespaces, STD, ProcessStems, Processes, or RequiredInstruments in the response.';
      $lines[] = 'Preserve valid existing Phase I task rows and relationships unless changes are required by WKF_SPEC_V3_CONTENT rules or source evidence.';
      $lines[] = 'Do NOT repeat or summarize any packet sections (header, goal, prompt, inputs, or TSV blocks).';
      $lines[] = 'Copy/link packaging is handled by application logic, not by this generation response.';
      $lines[] = 'If you cannot comply exactly, return exactly one line: ABORT: Could not produce compliant Phase II Tasks TSV output.';
      $lines[] = 'No markdown fences and no extra prose.';
    }
    elseif ($phase === 4) {
      $lines[] = 'Review BOTH inputs: (1) LATEST_TASKS_SHEET_TSV and (2) INSTRUMENT_INSTANCES_WITH_COMPONENT_INSTANCES.';
      $lines[] = 'Use DEPLOYMENT_COMPONENT_TO_INSTRUMENT_MAP as the primary compatibility source when assigning vstoi:usesComponentInstance.';
      $lines[] = 'Every vstoi:InteractionTask or vstoi:AutomatedTask MUST receive one or more vstoi:usesComponentInstance URIs from the provided organization inventory.';
      $lines[] = 'If no exact deployment-compatible component exists, assign the closest semantically relevant URI from the organization inventory; do not change a task type to avoid assignment.';
      $lines[] = 'Review leaf tasks first: change vstoi:ManualTask to vstoi:InteractionTask only where a learner action or its outcome is directly observable by a compatible simulator component.';
      $lines[] = 'For secretion aspiration, assess aspirator checks, pre-oxygenation, suction execution, patient-response monitoring, and ventilator/O2 readaptation as potential InteractionTask candidates; keep all non-observable manual work unchanged.';
      $lines[] = 'Before returning, verify every InteractionTask or AutomatedTask row has one or more vstoi:usesComponentInstance URIs, including rows without an exact deployment match.';
      $lines[] = 'Return ONLY the updated Tasks TSV file body (header row + all task rows), with real TAB separators.';
      $lines[] = 'Treat your response as the literal content of one .tsv file ready for Task Model Update upload.';
      $lines[] = 'Do NOT return markdown links, data URLs, markdown fences, JSON, summaries, or extra prose.';
      $lines[] = 'Use exactly the same header columns from LATEST_TASKS_SHEET_TSV; do not add vstoi:hasRequiredInstrument or any other extra column.';
      $lines[] = 'Every data row must have exactly the same number of TAB-separated columns as the header.';
      $lines[] = 'Preserve all existing task rows and relationships unless a change is required to set valid usesComponentInstance values.';
      $lines[] = 'No markdown fences and no extra prose.';
      $lines[] = '';
      $lines[] = 'EXECUTE NOW: Return the complete updated Phase IV Tasks TSV only.';
    }
    elseif ($phase === 3) {
      $lines[] = 'Return ONLY the STD TSV file body (header row + all STD rows), using real TAB separators.';
      $lines[] = 'Treat your response as the literal content of one .tsv file.';
      $lines[] = 'Do NOT return markdown links, data URLs, markdown fences, JSON, summaries, or extra prose.';
      $lines[] = 'Preserve canonical row URIs exactly as provided whenever hasURI is already present; do not mint alternate namespaces or replacement URIs.';
      $lines[] = 'Use this exact canonical STD header and column order: hasURI, hasco:hasProcess, Study ID, Title, Specific Aims, Significance, Institution, Principal Investigator, Email, Start Date, End Date, vstoi:hasLearningObjectives, vstoi:hasCriticalActions, vstoi:hasDebriefingFocus.';
      $lines[] = 'Preserve supplied hasURI, hasco:hasProcess, Study ID, Title, Institution, Principal Investigator, and Email values. When hasco:hasProcess is missing, use the current WKF Processes-sheet URI.';
      $lines[] = 'If prior assumptions conflict with current SOURCE_DOCUMENT_CONTENT + PHASE1_WKF_TABLE_TSV, ignore prior assumptions and use current inputs only.';
      $lines[] = 'If evidence is partial or uncertain, still return the canonical STD TSV, retaining supplied identity and process values and leaving unsupported optional fields empty.';
      $lines[] = 'Return ABORT only when required inputs are actually missing per the input gate.';
      $lines[] = 'No markdown fences and no extra prose.';
    }
    else {
      $lines[] = 'Return exactly 3 sections in this order:';
      $lines[] = '1) OUTPUT_WKF_TABLE_TSV';
      $lines[] = '2) OUTPUT_SUMMARY_JSON';
      $lines[] = '3) OUTPUT_ASSUMPTIONS';
      $lines[] = 'No extra prose outside those sections.';
    }

    $packetText = trim(implode("\n", $lines));
    // Phase IV can include large organization inventories; keep a high cap so
    // RESPONSE CONTRACT remains present and machine-actionable.
    $maxChars = 1200000;
    if (strlen($packetText) > $maxChars) {
      $packetText = substr($packetText, 0, $maxChars) . "\n\n[TRUNCATED: packet exceeded size limit]";
    }

    $packetId = $this->persistPacket($request, [
      'phase' => $phase,
      'phaseTitle' => $phaseTitle,
      'wkfUri' => $wkfUri,
      'versionLabel' => $versionLabel,
      'snapshotId' => $snapshotId,
      'validatedAt' => $validatedAt,
      'fileName' => 'wkf-phase' . $phase . '-packet.txt',
      'packetText' => $packetText,
    ]);

    return new JsonResponse([
      'success' => TRUE,
      'phase' => $phase,
      'phaseTitle' => $phaseTitle,
      'packetText' => $packetText,
      'fileName' => 'wkf-phase' . $phase . '-packet.txt',
      'packetId' => $packetId,
    ]);
  }

  /**
   * Detects metadata-only source document placeholders.
   */
  protected function isSourceDocumentMetadataOnly(string $value): bool {
    $normalized = trim($value);
    if ($normalized === '') {
      return false;
    }

    // Multi-line content usually indicates actual extracted text.
    if (strpos($normalized, "\n") !== false) {
      return false;
    }

    $singleLine = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

    // Explicit metadata markers from UI/session payloads.
    if (preg_match('/^(supporting\s+document|source\s+document)\s+(filename|file\s+name|name|title|id|identifier|uri|path)\s*:/i', $singleLine) === 1) {
      return true;
    }
    if (preg_match('/^(attachment|attached\s+file)\s*:/i', $singleLine) === 1) {
      return true;
    }

    // Bare path/filename references are placeholders, not extracted content.
    if (preg_match('/^[\w .\-\/\\:]+\.(pdf|docx?|rtf|txt|md)$/i', $singleLine) === 1) {
      return true;
    }

    // Detect short single-line metadata/reference values.
    if (strlen($singleLine) <= 280 && preg_match('/\b(filename|file\s+path|path|document\s+title|document\s+id|identifier|attachment|reference)\b/i', $singleLine) === 1) {
      return true;
    }

    return false;
  }

  /**
   * Detects raw binary/object payloads that are not actual extracted source text.
   */
  protected function isInvalidSourceDocumentContent(string $value): bool {
    $normalized = trim($value);
    if ($normalized === '') {
      return false;
    }

    $sample = substr($normalized, 0, 5000);

    if (preg_match('/^%PDF-/i', $sample) === 1) {
      return true;
    }

    $pdfTokens = ['endobj', 'obj', 'xref', 'endstream', 'stream'];
    $tokenHits = 0;
    foreach ($pdfTokens as $token) {
      if (stripos($sample, $token) !== false) {
        $tokenHits++;
      }
    }
    if ($tokenHits >= 3) {
      return true;
    }

    $len = strlen($sample);
    if ($len > 0) {
      $whitespaceCount = preg_match_all('/\s/', $sample);
      $symbolCount = preg_match_all('/[^\w\s\.,;:\-\(\)\[\]\/{}/%]/u', $sample);
      $whitespaceRatio = $whitespaceCount / $len;
      $symbolRatio = $symbolCount / $len;
      if ($whitespaceRatio < 0.05 && $symbolRatio > 0.25) {
        return true;
      }
    }

    return false;
  }

  /**
   * List stored packet artifacts for a WKF scope.
   */
  public function listHistory(Request $request): JsonResponse {
    $wkfUri = trim((string) $request->query->get('wkfUri', ''));
    $scope = $this->resolveWkfScope($request, $wkfUri);
    $history = $this->getPacketHistoryForScope($request, $scope);

    $items = [];
    foreach ($history as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $items[] = [
        'packetId' => (string) ($entry['packetId'] ?? ''),
        'phase' => (int) ($entry['phase'] ?? 0),
        'phaseTitle' => (string) ($entry['phaseTitle'] ?? ''),
        'createdAt' => (string) ($entry['createdAt'] ?? ''),
        'fileName' => (string) ($entry['fileName'] ?? ''),
        'versionLabel' => (string) ($entry['versionLabel'] ?? ''),
        'snapshotId' => (string) ($entry['snapshotId'] ?? ''),
      ];
    }

    return new JsonResponse([
      'success' => TRUE,
      'scope' => $scope,
      'items' => $items,
    ]);
  }

  /**
   * Retrieve a stored packet artifact by id within a WKF scope.
   */
  public function getHistoryItem(string $packetId, Request $request): JsonResponse {
    $wkfUri = trim((string) $request->query->get('wkfUri', ''));
    $scope = $this->resolveWkfScope($request, $wkfUri);
    $history = $this->getPacketHistoryForScope($request, $scope);

    foreach ($history as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if ((string) ($entry['packetId'] ?? '') !== $packetId) {
        continue;
      }

      $entry = $this->hydratePacketEntry($entry);
      unset($entry['packetUri']);

      return new JsonResponse([
        'success' => TRUE,
        'item' => $entry,
      ]);
    }

    return new JsonResponse([
      'success' => FALSE,
      'error' => 'Packet history item not found for current WKF scope.',
    ], 404);
  }

  /**
   * Delete one stored packet artifact by id within a WKF scope.
   */
  public function deleteHistoryItem(string $packetId, Request $request): JsonResponse {
    $wkfUri = trim((string) $request->query->get('wkfUri', ''));
    $scope = $this->resolveWkfScope($request, $wkfUri);
    $history = $this->getPacketHistoryForScope($request, $scope);

    $remaining = [];
    $deleted = FALSE;
    foreach ($history as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if ((string) ($entry['packetId'] ?? '') === $packetId) {
        $this->deletePacketTextForEntry($entry);
        $deleted = TRUE;
        continue;
      }
      $remaining[] = $entry;
    }

    if (!$deleted) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Packet history item not found for current WKF scope.',
      ], 404);
    }

    $this->setPacketHistoryForScope($scope, $remaining);

    return new JsonResponse([
      'success' => TRUE,
      'scope' => $scope,
      'deletedPacketId' => $packetId,
      'remainingCount' => count($remaining),
    ]);
  }

  /**
   * Clear all stored packet artifacts for a WKF scope.
   */
  public function clearHistory(Request $request): JsonResponse {
    $wkfUri = trim((string) $request->query->get('wkfUri', ''));
    $scope = $this->resolveWkfScope($request, $wkfUri);
    $history = $this->getPacketHistoryForScope($request, $scope);

    $deletedCount = 0;
    foreach ($history as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $this->deletePacketTextForEntry($entry);
      $deletedCount++;
    }

    $this->clearPacketHistoryForScope($scope);

    return new JsonResponse([
      'success' => TRUE,
      'scope' => $scope,
      'deletedCount' => $deletedCount,
    ]);
  }

  /**
   * Apply a ChatGPT phase response into WKF content and optionally validate.
   */
  public function applyResponse(int $phase, Request $request): JsonResponse {
    $phase = $this->normalizeLegacyPhase($phase);
    if ($phase < 2 || $phase > 4) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Only phases II to IV are supported by this endpoint.',
      ], 400);
    }

    $payload = $this->decodePayload($request);
    $wkfUri = trim((string) ($payload['wkfUri'] ?? ''));
    $responseText = trim((string) ($payload['responseText'] ?? ''));
    $targetStatus = strtolower(trim((string) ($payload['targetStatus'] ?? 'current')));
    $autoValidate = !empty($payload['autoValidate']);

    if ($wkfUri === '') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'WKF URI is required.',
      ], 400);
    }
    if ($responseText === '') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'ChatGPT response text is required.',
      ], 400);
    }

    if ($targetStatus !== 'draft' && $targetStatus !== 'current') {
      $targetStatus = 'current';
    }

    $scope = $this->resolveWkfScope($request, $wkfUri);
    $responseTextForApply = $responseText;
    if ($phase === 2) {
      $decodeError = '';
      $tasksTsv = $this->extractTasksTsvFromPhase2Response($responseText, $decodeError);
      if ($tasksTsv === NULL) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => $decodeError !== ''
            ? $decodeError
            : 'Phase II response must be a valid Copy Task Model data-link or raw Tasks TSV.',
        ], 400);
      }

      $baseContent = $this->resolveCurrentWkfWorkbookContent($request, $wkfUri, $scope);
      if ($baseContent === '') {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Could not resolve current WKF content to merge Phase II Tasks TSV.',
        ], 400);
      }

      $responseTextForApply = $this->replaceSheetBlockInWorkbookTsv($baseContent, 'Tasks', $tasksTsv);
    }

    $applyMessage = '';
    $applied = $this->applyResponseTextToWkf($wkfUri, $responseTextForApply, $targetStatus, $applyMessage);
    $applyMode = 'ingestion';

    // Fallback: keep a local working copy so users can continue Phase II-IV
    // even before the WKF URI is available in hascoapi.
    if (!$applied) {
      $this->setWorkingCopyForScope($scope, [
        'wkfUri' => $wkfUri,
        'wkfContent' => $responseTextForApply,
        'phase' => $phase,
        'targetStatus' => $targetStatus,
      ]);
      $applied = TRUE;
      $applyMode = 'local_fallback';
      $applyMessage = 'Applied to local working copy only (WKF URI not resolvable in hascoapi yet).';
    }

    $validation = NULL;
    if ($applied && $autoValidate) {
      if ($applyMode === 'ingestion') {
        $validation = $this->validateWkfAndUpdateScopedSession($request, $wkfUri, $scope, $responseTextForApply);
      }
      else {
        $validation = $this->validateLocalWkfAndUpdateScopedSession($request, $wkfUri, $scope, $responseTextForApply);
      }
    }

    $responseId = $this->persistResponseArtifact($request, [
      'phase' => $phase,
      'phaseTitle' => $this->getPhaseTitle($phase),
      'wkfUri' => $wkfUri,
      'targetStatus' => $targetStatus,
      'applied' => $applied,
      'applyMode' => $applyMode,
      'applyMessage' => $applyMessage,
      'autoValidate' => $autoValidate,
      'validated' => is_array($validation) ? (bool) ($validation['valid'] ?? FALSE) : NULL,
      'validationSummary' => is_array($validation) ? (string) ($validation['summary'] ?? '') : '',
      'responseText' => $responseText,
    ]);

    return new JsonResponse([
      'success' => TRUE,
      'responseId' => $responseId,
      'applied' => TRUE,
      'applyMode' => $applyMode,
      'applyMessage' => $applyMessage,
      'validation' => $validation,
    ]);
  }

  /**
   * Resolve current WKF workbook-like TSV content for a scope.
   */
  protected function resolveCurrentWkfWorkbookContent(Request $request, string $wkfUri, string $scope): string {
    $sessionContext = $this->resolveWkfScopedSessionContext($request, $wkfUri);

    $workingCopy = $this->getWorkingCopyForScope($scope);
    if (isset($workingCopy['wkfContent']) && is_string($workingCopy['wkfContent']) && trim($workingCopy['wkfContent']) !== '') {
      return trim($workingCopy['wkfContent']);
    }
    if (isset($sessionContext['wkfContent']) && is_string($sessionContext['wkfContent']) && trim($sessionContext['wkfContent']) !== '') {
      return trim($sessionContext['wkfContent']);
    }
    if (isset($sessionContext['phase1WkfTableTsv']) && is_string($sessionContext['phase1WkfTableTsv']) && trim($sessionContext['phase1WkfTableTsv']) !== '') {
      return trim($sessionContext['phase1WkfTableTsv']);
    }

    return '';
  }

  /**
   * Extract raw Tasks TSV from Phase II response (Copy Task Model link or raw TSV).
   */
  protected function extractTasksTsvFromPhase2Response(string $responseText, string &$error): ?string {
    $error = '';
    $text = trim($responseText);
    if ($text === '') {
      $error = 'Phase II response text is empty.';
      return NULL;
    }

    if (stripos($text, 'ABORT:') === 0) {
      $error = $text;
      return NULL;
    }

    // Accept sectioned responses and extract only OUTPUT_TASKS_SHEET_TSV content.
    $sectionTsv = $this->extractTasksTsvFromSectionedResponse($text);
    if ($sectionTsv !== NULL) {
      return $sectionTsv;
    }

    $dataUrl = '';
    if (preg_match('/\[[^\]]*Copy\s+Task\s+Model[^\]]*\]\((data:text\/plain[^)]*)\)/i', $text, $m) === 1) {
      $dataUrl = trim((string) ($m[1] ?? ''));
    }
    elseif (preg_match('/^(data:text\/plain[^\s]+)$/i', $text, $m) === 1) {
      $dataUrl = trim((string) ($m[1] ?? ''));
    }

    if ($dataUrl !== '') {
      $commaPos = strpos($dataUrl, ',');
      if ($commaPos === FALSE) {
        $error = 'Phase II Copy Task Model link is missing TSV payload.';
        return NULL;
      }
      $encodedPayload = substr($dataUrl, $commaPos + 1);
      $decoded = rawurldecode($encodedPayload);
      $normalized = $this->normalizeSheetTsv($decoded);
      if ($normalized === '') {
        $error = 'Phase II Copy Task Model link payload decoded to empty TSV.';
        return NULL;
      }
      return $normalized;
    }

    // Backward-compatible fallback: accept raw TSV pasted directly.
    $rawNormalized = $this->normalizeSheetTsv($text);
    if ($rawNormalized !== '' && strpos($rawNormalized, "\t") !== FALSE) {
      return $rawNormalized;
    }

    $error = 'Phase II response is not valid. Expected OUTPUT_TASKS_SHEET_TSV section, Copy Task Model data-link, or raw Tasks TSV.';
    return NULL;
  }

  /**
   * Extract Tasks TSV from a sectioned response body.
   */
  protected function extractTasksTsvFromSectionedResponse(string $responseText): ?string {
    $normalized = str_replace(["\r\n", "\r"], "\n", trim($responseText));
    if ($normalized === '') {
      return NULL;
    }

    if (preg_match('/OUTPUT_TASKS_SHEET_TSV\s*(.*?)\s*OUTPUT_SUMMARY_JSON/si', $normalized, $m) !== 1) {
      return NULL;
    }

    $sectionBody = trim((string) ($m[1] ?? ''));
    if ($sectionBody === '') {
      return NULL;
    }

    // Remove optional markdown fences if the model wrapped TSV content.
    if (preg_match('/^```(?:tsv|text)?\s*(.*?)\s*```$/si', $sectionBody, $fenced) === 1) {
      $sectionBody = trim((string) ($fenced[1] ?? ''));
    }

    $normalizedSheet = $this->normalizeSheetTsv($sectionBody);
    if ($normalizedSheet === '' || strpos($normalizedSheet, "\t") === FALSE) {
      return NULL;
    }

    return $normalizedSheet;
  }

  /**
   * List stored ChatGPT response artifacts for a WKF scope.
   */
  public function listResponseHistory(Request $request): JsonResponse {
    $wkfUri = trim((string) $request->query->get('wkfUri', ''));
    $scope = $this->resolveWkfScope($request, $wkfUri);
    $history = $this->getResponseHistoryForScope($request, $scope);

    $items = [];
    foreach ($history as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $items[] = [
        'responseId' => (string) ($entry['responseId'] ?? ''),
        'phase' => (int) ($entry['phase'] ?? 0),
        'phaseTitle' => (string) ($entry['phaseTitle'] ?? ''),
        'createdAt' => (string) ($entry['createdAt'] ?? ''),
        'targetStatus' => (string) ($entry['targetStatus'] ?? ''),
        'applied' => !empty($entry['applied']),
        'applyMode' => (string) ($entry['applyMode'] ?? ''),
        'validated' => isset($entry['validated']) ? (bool) $entry['validated'] : NULL,
      ];
    }

    return new JsonResponse([
      'success' => TRUE,
      'scope' => $scope,
      'items' => $items,
    ]);
  }

  /**
   * Retrieve one stored ChatGPT response artifact for a WKF scope.
   */
  public function getResponseHistoryItem(string $responseId, Request $request): JsonResponse {
    $wkfUri = trim((string) $request->query->get('wkfUri', ''));
    $scope = $this->resolveWkfScope($request, $wkfUri);
    $history = $this->getResponseHistoryForScope($request, $scope);

    foreach ($history as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if ((string) ($entry['responseId'] ?? '') !== $responseId) {
        continue;
      }

      $entry = $this->hydrateResponseEntry($entry);
      unset($entry['responseUri']);

      return new JsonResponse([
        'success' => TRUE,
        'item' => $entry,
      ]);
    }

    return new JsonResponse([
      'success' => FALSE,
      'error' => 'Response history item not found for current WKF scope.',
    ], 404);
  }

  /**
   * Delete one stored response artifact from a WKF scope.
   */
  public function deleteResponseHistoryItem(string $responseId, Request $request): JsonResponse {
    $wkfUri = trim((string) $request->query->get('wkfUri', ''));
    $scope = $this->resolveWkfScope($request, $wkfUri);
    $history = $this->getResponseHistoryForScope($request, $scope);

    $remaining = [];
    $deleted = FALSE;
    foreach ($history as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if ((string) ($entry['responseId'] ?? '') === $responseId) {
        $this->deleteResponseTextForEntry($entry);
        $deleted = TRUE;
        continue;
      }
      $remaining[] = $entry;
    }

    if (!$deleted) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Response history item not found for current WKF scope.',
      ], 404);
    }

    $this->setResponseHistoryForScope($scope, $remaining);

    return new JsonResponse([
      'success' => TRUE,
      'scope' => $scope,
      'deletedResponseId' => $responseId,
      'remainingCount' => count($remaining),
    ]);
  }

  /**
   * Clear all stored response artifacts for a WKF scope.
   */
  public function clearResponseHistory(Request $request): JsonResponse {
    $wkfUri = trim((string) $request->query->get('wkfUri', ''));
    $scope = $this->resolveWkfScope($request, $wkfUri);
    $history = $this->getResponseHistoryForScope($request, $scope);

    $deletedCount = 0;
    foreach ($history as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $this->deleteResponseTextForEntry($entry);
      $deletedCount++;
    }

    $this->clearResponseHistoryForScope($scope);

    return new JsonResponse([
      'success' => TRUE,
      'scope' => $scope,
      'deletedCount' => $deletedCount,
    ]);
  }

  /**
   * Normalize legacy public phase numbering to current backend phases.
   */
  private function normalizeLegacyPhase(int $phase): int {
    if ($phase === 5) {
      return 4;
    }
    if ($phase === 1) {
      return 2;
    }
    return $phase;
  }

  /**
   * Build deterministic suggested output TSV filename for Phase II-IV packets.
   */
  protected function buildSuggestedPhaseTsvFileName(int $phase, string $wkfUri): string {
    $wkfHash = $this->extractWkfHashFromUri($wkfUri);
    if ($phase === 2) {
      return 'WKF' . $wkfHash . '_Phase2_tasks.tsv';
    }
    if ($phase === 3) {
      return 'WKF' . $wkfHash . '_Phase3_std.tsv';
    }
    if ($phase === 4) {
      return 'WKF' . $wkfHash . '_Phase4_tasks.tsv';
    }

    return 'WKF' . $wkfHash . '_Phase' . $phase . '_response.tsv';
  }

  /**
   * Extract WKF hash digits from URI; fallback to stable numeric hash token.
   */
  protected function extractWkfHashFromUri(string $wkfUri): string {
    $uri = trim($wkfUri);
    if ($uri === '') {
      return 'UNKNOWN';
    }

    if (preg_match('/\\bWKF(\\d{6,})\\b/i', $uri, $wkfMatch) === 1) {
      return (string) ($wkfMatch[1] ?? 'UNKNOWN');
    }

    if (preg_match('/(\\d{6,})/', $uri, $digitsMatch) === 1) {
      return (string) ($digitsMatch[1] ?? 'UNKNOWN');
    }

    return sprintf('%u', crc32($uri));
  }

  /**
   * Replace a target WKF TSV sheet block with user-provided TSV content.
   */
  public function updateSheet(Request $request): JsonResponse {
    $payload = $this->decodePayload($request);

    $wkfUri = trim((string) ($request->request->get('wkfUri', $payload['wkfUri'] ?? '')));
    $updateType = strtolower(trim((string) ($request->request->get('updateType', $payload['updateType'] ?? ''))));
    $tsvContent = trim((string) ($request->request->get('tsvContent', $payload['tsvContent'] ?? '')));
    $phase = (int) ($request->request->get('phase', $payload['phase'] ?? 0));
    $uploadedTsvFile = $request->files->get('tsvFile');

    if ($wkfUri === '') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'WKF URI is required.',
      ], 400);
    }

    if ($updateType !== 'scenario' && $updateType !== 'task_model') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Update type must be scenario or task_model.',
      ], 400);
    }

    if ($updateType === 'task_model' || $updateType === 'scenario') {
      if ($uploadedTsvFile === NULL) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => $updateType === 'task_model'
            ? 'Tasks TSV file is required for Task Model Update.'
            : 'STD TSV file is required for Scenario Update.',
        ], 400);
      }

      if (!method_exists($uploadedTsvFile, 'isValid') || !$uploadedTsvFile->isValid()) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => $updateType === 'task_model'
            ? 'Uploaded Tasks TSV file is invalid.'
            : 'Uploaded STD TSV file is invalid.',
        ], 400);
      }

      $originalName = method_exists($uploadedTsvFile, 'getClientOriginalName')
        ? strtolower(trim((string) $uploadedTsvFile->getClientOriginalName()))
        : '';
      if ($originalName === '' || substr($originalName, -4) !== '.tsv') {
        return new JsonResponse([
          'success' => FALSE,
          'error' => $updateType === 'task_model'
            ? 'Task Model Update accepts only .tsv files.'
            : 'Scenario Update accepts only .tsv files.',
        ], 400);
      }

      $realPath = method_exists($uploadedTsvFile, 'getRealPath') ? (string) $uploadedTsvFile->getRealPath() : '';
      if ($realPath === '' || !is_readable($realPath)) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => $updateType === 'task_model'
            ? 'Could not read uploaded Tasks TSV file.'
            : 'Could not read uploaded STD TSV file.',
        ], 400);
      }

      $fileBytes = @file_get_contents($realPath);
      if (!is_string($fileBytes) || trim($fileBytes) === '') {
        return new JsonResponse([
          'success' => FALSE,
          'error' => $updateType === 'task_model'
            ? 'Uploaded Tasks TSV file is empty.'
            : 'Uploaded STD TSV file is empty.',
        ], 400);
      }

      $tsvContent = trim(str_replace(["\r\n", "\r"], "\n", $fileBytes));
    }

    if ($tsvContent === '') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'TSV content is required.',
      ], 400);
    }

    $sheetValidation = NULL;
    $taskMergeStats = NULL;
    $scenarioMergeStats = NULL;
    if ($updateType === 'task_model') {
      $sheetValidation = $this->validateTaskModelSheetTsv($tsvContent);
      if (empty($sheetValidation['valid'])) {
        $summary = isset($sheetValidation['summary']) ? trim((string) $sheetValidation['summary']) : '';
        return new JsonResponse([
          'success' => FALSE,
          'sheetName' => 'Tasks',
          'sheetValidation' => $sheetValidation,
          'error' => $summary !== '' ? $summary : 'Tasks TSV is not well-formed.',
        ], 400);
      }

      if (isset($sheetValidation['normalizedTsv']) && is_string($sheetValidation['normalizedTsv'])) {
        $tsvContent = $sheetValidation['normalizedTsv'];
      }
    }
    elseif ($updateType === 'scenario') {
      $sheetValidation = $this->validateStdSheetTsv($tsvContent);
      if (empty($sheetValidation['valid'])) {
        $summary = isset($sheetValidation['summary']) ? trim((string) $sheetValidation['summary']) : '';
        return new JsonResponse([
          'success' => FALSE,
          'sheetName' => 'STD',
          'sheetValidation' => $sheetValidation,
          'error' => $summary !== '' ? $summary : 'STD TSV is not well-formed.',
        ], 400);
      }

      if (isset($sheetValidation['normalizedTsv']) && is_string($sheetValidation['normalizedTsv'])) {
        $tsvContent = $sheetValidation['normalizedTsv'];
      }
    }

    $scope = $this->resolveWkfScope($request, $wkfUri);
    $sessionContext = $this->resolveWkfScopedSessionContext($request, $wkfUri);
    $baseContent = '';

    $workingCopy = $this->getWorkingCopyForScope($scope);
    if (isset($workingCopy['wkfContent']) && is_string($workingCopy['wkfContent']) && trim($workingCopy['wkfContent']) !== '') {
      $baseContent = trim($workingCopy['wkfContent']);
    }
    elseif (isset($sessionContext['wkfContent']) && is_string($sessionContext['wkfContent']) && trim($sessionContext['wkfContent']) !== '') {
      $baseContent = trim($sessionContext['wkfContent']);
    }
    elseif (isset($sessionContext['phase1WkfTableTsv']) && is_string($sessionContext['phase1WkfTableTsv']) && trim($sessionContext['phase1WkfTableTsv']) !== '') {
      $baseContent = trim($sessionContext['phase1WkfTableTsv']);
    }

    if ($baseContent === '') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Could not resolve current WKF TSV content for this WKF scope.',
      ], 400);
    }

    if ($updateType === 'task_model') {
      $mergeResult = $this->mergeTaskModelSheetIntoWorkbook($baseContent, $tsvContent);
      $tsvContent = isset($mergeResult['mergedSheetTsv']) && is_string($mergeResult['mergedSheetTsv'])
        ? $mergeResult['mergedSheetTsv']
        : $tsvContent;
      $taskMergeStats = isset($mergeResult['stats']) && is_array($mergeResult['stats'])
        ? $mergeResult['stats']
        : NULL;
    }
    elseif ($updateType === 'scenario') {
      $mergeResult = $this->mergeStdSheetIntoWorkbook($baseContent, $tsvContent);
      $tsvContent = isset($mergeResult['mergedSheetTsv']) && is_string($mergeResult['mergedSheetTsv'])
        ? $mergeResult['mergedSheetTsv']
        : $tsvContent;
      $scenarioMergeStats = isset($mergeResult['stats']) && is_array($mergeResult['stats'])
        ? $mergeResult['stats']
        : NULL;
    }

    $sheetName = $updateType === 'scenario' ? 'STD' : 'Tasks';
    $updatedContent = $this->replaceSheetBlockInWorkbookTsv($baseContent, $sheetName, $tsvContent);

    // Persist latest workbook TSV locally for scope-consistent task counting/UI,
    // regardless of whether hascoapi apply succeeds.
    $this->setWorkingCopyForScope($scope, [
      'wkfUri' => $wkfUri,
      'wkfContent' => $updatedContent,
      'phase' => ($phase > 0 ? $phase : 2),
      'targetStatus' => 'current',
    ]);

    $applyMessage = '';
    $applied = $this->applyResponseTextToWkf($wkfUri, $updatedContent, 'current', $applyMessage);
    $applyMode = 'ingestion';
    if (!$applied) {
      $applyMode = 'local_fallback';
      $applyMessage = 'Sheet update applied to local working copy only (WKF URI not resolvable in hascoapi yet).';
    }

    $validation = NULL;
    $validationAdvisory = NULL;
    if ($updateType === 'task_model') {
      if ($applyMode === 'ingestion') {
        $validation = $this->validateWkfAndUpdateScopedSession($request, $wkfUri, $scope, $updatedContent);
      }
      else {
        $validation = $this->validateLocalWkfAndUpdateScopedSession($request, $wkfUri, $scope, $updatedContent);
      }

      $isValidationValid = is_array($validation) && !empty($validation['valid']);
      if (!$isValidationValid) {
        $summary = is_array($validation) && isset($validation['summary'])
          ? trim((string) $validation['summary'])
          : '';
        if ($summary === '') {
          $summary = 'WKF validation failed.';
        }
        $validationAdvisory = [
          'level' => 'warning',
          'summary' => $summary,
          'blocking' => FALSE,
        ];
      }
    }
    elseif ($updateType === 'scenario') {
      // Scenario Update validates only the STD payload/sheet shape.
      $validation = [
        'valid' => is_array($sheetValidation) ? !empty($sheetValidation['valid']) : TRUE,
        'summary' => is_array($sheetValidation) && isset($sheetValidation['summary'])
          ? (string) $sheetValidation['summary']
          : 'STD sheet validated and merged.',
        'scope' => 'STD',
      ];

      // Prevent stale whole-WKF validation snapshots from surfacing in the
      // dedicated panel after STD-only scenario updates.
      $this->clearScopedWkfValidationPanelState($request, $scope);
    }

    $nextPublicPhase = $phase > 0 ? $phase : 2;
    if ($updateType === 'task_model' && $nextPublicPhase === 2) {
      $nextPublicPhase = 3;
    }
    if ($updateType === 'scenario' && $nextPublicPhase === 3) {
      $nextPublicPhase = 4;
    }

    if ($nextPublicPhase >= 2 && $nextPublicPhase <= 4) {
      \Drupal::keyValue('rep.wkf.public_phase.by_scope')->set($scope, [
        'phase' => $nextPublicPhase,
        'wkfUri' => $wkfUri,
        'updatedAt' => date('Y-m-d H:i:s'),
      ]);

      // Persist in table-backed model for traceability/querying.
      try {
        $normalizedWkfUriForPhase = Utils::plainUri($wkfUri) ?: trim($wkfUri);
        \Drupal::database()->merge('rep_wkf_phase_state')
          ->key(['scope' => $scope])
          ->fields([
            'wkf_uri' => $normalizedWkfUriForPhase,
            'public_phase' => $nextPublicPhase,
            'updated' => time(),
          ])
          ->execute();
      }
      catch (\Throwable $e) {
        // Keep key-value as fallback when DB table is unavailable.
      }
    }

    if ($updateType === 'task_model') {
      $normalizedWkfUri = Utils::plainUri($wkfUri) ?: trim($wkfUri);
      if ($normalizedWkfUri !== '') {
        $persistedCount = NULL;
        if (is_array($taskMergeStats) && isset($taskMergeStats['mergedRows']) && is_numeric($taskMergeStats['mergedRows'])) {
          $persistedCount = (int) $taskMergeStats['mergedRows'];
        }
        elseif (is_array($sheetValidation) && isset($sheetValidation['rows']) && is_numeric($sheetValidation['rows'])) {
          $persistedCount = (int) $sheetValidation['rows'];
        }

        if ($persistedCount !== NULL && $persistedCount >= 0) {
          \Drupal::keyValue('rep.wkf.task_count.by_uri')->set($normalizedWkfUri, [
            'count' => $persistedCount,
            'updatedAt' => date('Y-m-d H:i:s'),
            'scope' => $scope,
          ]);
        }
      }
    }

    return new JsonResponse([
      'success' => TRUE,
      'sheetName' => $sheetName,
      'applyMode' => $applyMode,
      'applyMessage' => $applyMessage,
      'phase' => ($phase > 0 ? $phase : 2),
      'nextPublicPhase' => $nextPublicPhase,
      'appliedTasksRows' => ($updateType === 'task_model' && is_array($sheetValidation) && isset($sheetValidation['rows']))
        ? (int) ($taskMergeStats['incomingRowsAccepted'] ?? $sheetValidation['rows'])
        : NULL,
      'taskMerge' => $taskMergeStats,
      'scenarioMerge' => $scenarioMergeStats,
      'sheetValidation' => $sheetValidation,
      'validation' => $validation,
      'validationAdvisory' => $validationAdvisory,
    ]);
  }

  /**
   * Clear scoped WKF validation panel/session state.
   */
  protected function clearScopedWkfValidationPanelState(Request $request, string $scope): void {
    $session = $request->getSession();
    if ($session === NULL || $scope === '') {
      return;
    }

    $panelStore = $session->get('rep.wkf.validation.panel.by_scope', []);
    if (is_array($panelStore) && array_key_exists($scope, $panelStore)) {
      unset($panelStore[$scope]);
      $session->set('rep.wkf.validation.panel.by_scope', $panelStore);
    }

    $workflowStore = $session->get('rep.wkf.workflow.state.by_scope', []);
    if (is_array($workflowStore) && array_key_exists($scope, $workflowStore)) {
      unset($workflowStore[$scope]);
      $session->set('rep.wkf.workflow.state.by_scope', $workflowStore);
    }
  }

  /**
   * Validate whether a Scenario Update payload is a well-formed STD TSV.
   */
  protected function validateStdSheetTsv(string $sheetTsv): array {
    $normalized = $this->normalizeSheetTsv($sheetTsv);
    if ($normalized === '') {
      return [
        'valid' => FALSE,
        'summary' => 'STD TSV is empty after normalization.',
      ];
    }

    $lines = explode("\n", $normalized);
    if (count($lines) < 2) {
      return [
        'valid' => FALSE,
        'summary' => 'STD TSV must include a header row and at least one data row.',
      ];
    }

    $headerLine = trim((string) $lines[0]);
    if ($headerLine === '' || strpos($headerLine, "\t") === FALSE) {
      return [
        'valid' => FALSE,
        'summary' => 'STD TSV header must be tab-separated using real TAB characters (U+0009).',
      ];
    }

    $headerCols = array_map('trim', explode("\t", $headerLine));
    $colCount = count($headerCols);
    $normalizedLines = [$headerLine];
    $rows = 0;

    for ($i = 1; $i < count($lines); $i++) {
      $line = (string) $lines[$i];
      if (trim($line) === '') {
        continue;
      }

      $cols = explode("\t", $line);
      if (count($cols) > $colCount) {
        while (count($cols) > $colCount && trim((string) end($cols)) === '') {
          array_pop($cols);
        }
      }
      if (count($cols) > $colCount) {
        return [
          'valid' => FALSE,
          'summary' => 'STD TSV row ' . ($i + 1) . ' has ' . count($cols) . ' columns; expected ' . $colCount . '.',
        ];
      }

      if (count($cols) < $colCount) {
        $cols = array_pad($cols, $colCount, '');
      }

      $normalizedLines[] = implode("\t", $cols);
      $rows++;
    }

    return [
      'valid' => TRUE,
      'summary' => 'STD TSV is well-formed.',
      'rows' => $rows,
      'columns' => $colCount,
      'normalizedTsv' => implode("\n", $normalizedLines),
    ];
  }

  /**
   * Convert legacy ownership-only STD rows to the canonical Phase III schema.
   */
  protected function canonicalizeStdSheetForPhase3(string $stdSheetTsv, string $workbookTsv): string {
    $parsed = $this->parseGenericSheetTsv($stdSheetTsv);
    if (empty($parsed['valid'])) {
      return $stdSheetTsv;
    }

    $header = ['hasURI', 'hasco:hasProcess', 'Study ID', 'Title', 'Specific Aims', 'Significance', 'Institution', 'Principal Investigator', 'Email', 'Start Date', 'End Date', 'vstoi:hasLearningObjectives', 'vstoi:hasCriticalActions', 'vstoi:hasDebriefingFocus'];
    $source = $this->buildHeaderLookup($parsed['header']);
    $target = $this->buildHeaderLookup($header);
    $process = $this->parseGenericSheetTsv($this->extractSheetBlockFromWorkbookTsv($workbookTsv, 'Processes'));
    $processLookup = !empty($process['valid']) ? $this->buildHeaderLookup($process['header']) : [];
    $processRow = !empty($process['rows'][0]) ? $process['rows'][0] : [];
    $processUri = isset($processLookup['hasuri']) ? trim((string) ($processRow[$processLookup['hasuri']] ?? '')) : '';
    $processTitle = isset($processLookup['rdfs:label']) ? trim((string) ($processRow[$processLookup['rdfs:label']] ?? '')) : '';
    $outputRows = [];

    foreach ($parsed['rows'] as $sourceRow) {
      $row = array_fill(0, count($header), '');
      foreach ($target as $name => $index) {
        if (isset($source[$name])) {
          $row[$index] = trim((string) ($sourceRow[$source[$name]] ?? ''));
        }
      }
      if ($row[$target['email']] === '' && isset($source['principalinvestigatoremail'])) {
        $row[$target['email']] = trim((string) ($sourceRow[$source['principalinvestigatoremail']] ?? ''));
      }
      if ($row[$target['hasco:hasprocess']] === '') {
        $row[$target['hasco:hasprocess']] = $processUri;
      }
      if ($row[$target['title']] === '') {
        $row[$target['title']] = $processTitle;
      }
      if ($row[$target['studyid']] === '') {
        $base = preg_replace('#/STD/[^/]+$#', '', $row[$target['hasuri']]) ?? '';
        $token = trim((string) basename($base));
        $row[$target['studyid']] = $token !== '' ? 'STD-' . $token : 'STD-Generated';
      }
      $outputRows[] = $row;
    }

    $lines = [implode("\t", $header)];
    foreach ($outputRows as $row) {
      $lines[] = implode("\t", $row);
    }
    return implode("\n", $lines);
  }

  /**
   * Merge incoming Scenario STD TSV rows into existing workbook STD sheet.
   */
  protected function mergeStdSheetIntoWorkbook(string $workbookTsv, string $incomingStdTsv): array {
    $existingSheet = $this->extractSheetBlockFromWorkbookTsv($workbookTsv, 'STD');
    if ($existingSheet === '') {
      $existingSheet = $this->extractStdSheetFromAnyTsv($workbookTsv);
    }
    if ($existingSheet !== '') {
      $existingSheet = $this->canonicalizeStdSheetForPhase3($existingSheet, $workbookTsv);
    }

    $incomingParsed = $this->parseGenericSheetTsv($incomingStdTsv);
    if (empty($incomingParsed['valid'])) {
      return [
        'mergedSheetTsv' => $this->normalizeSheetTsv($incomingStdTsv),
        'stats' => [
          'strategy' => 'replace_incoming_only',
          'incomingRowsAccepted' => 0,
          'existingRows' => 0,
          'addedRows' => 0,
          'updatedRows' => 0,
          'unchangedRows' => 0,
          'mergedRows' => 0,
          'propertyCoverage' => [],
        ],
      ];
    }

    if ($existingSheet === '') {
      return [
        'mergedSheetTsv' => $incomingParsed['normalizedTsv'],
        'stats' => [
          'strategy' => 'replace_no_existing_std_sheet',
          'incomingRowsAccepted' => count($incomingParsed['rows']),
          'existingRows' => 0,
          'addedRows' => count($incomingParsed['rows']),
          'updatedRows' => 0,
          'unchangedRows' => 0,
          'mergedRows' => count($incomingParsed['rows']),
          'propertyCoverage' => $this->inspectStdPropertyCoverage($incomingParsed['header'], $incomingParsed['rows']),
        ],
      ];
    }

    $existingParsed = $this->parseGenericSheetTsv($existingSheet);
    if (empty($existingParsed['valid'])) {
      return [
        'mergedSheetTsv' => $incomingParsed['normalizedTsv'],
        'stats' => [
          'strategy' => 'replace_existing_unparseable',
          'incomingRowsAccepted' => count($incomingParsed['rows']),
          'existingRows' => 0,
          'addedRows' => count($incomingParsed['rows']),
          'updatedRows' => 0,
          'unchangedRows' => 0,
          'mergedRows' => count($incomingParsed['rows']),
          'propertyCoverage' => $this->inspectStdPropertyCoverage($incomingParsed['header'], $incomingParsed['rows']),
        ],
      ];
    }

    $targetHeader = $existingParsed['header'];
    $targetLookup = $this->buildHeaderLookup($targetHeader);
    $uriIdx = $targetLookup['hasuri'] ?? -1;
    // Track all STD property columns dynamically (excluding identifier).
    $trackedProperties = [];
    foreach ($targetHeader as $idx => $columnName) {
      $label = trim((string) $columnName);
      $normalized = $this->normalizeTsvHeaderColumn($label);
      if ($normalized === '' || $normalized === 'hasuri') {
        continue;
      }
      $trackedProperties[$normalized] = $label !== '' ? $label : ('column_' . (string) $idx);
    }
    $trackedPropertyIndexes = [];
    $propertyFieldChanges = [];
    foreach ($trackedProperties as $normalized => $label) {
      $idx = isset($targetLookup[$normalized]) ? (int) $targetLookup[$normalized] : -1;
      $trackedPropertyIndexes[$label] = $idx;
      $propertyFieldChanges[$label] = [
        'columnPresent' => $idx >= 0,
        'added' => 0,
        'updated' => 0,
      ];
    }

    $existingRows = $existingParsed['rows'];
    $incomingRows = $this->remapRowsToTargetHeader(
      $incomingParsed['rows'],
      $incomingParsed['header'],
      $targetHeader
    );

    $existingByUri = [];
    $existingNoUriSignatures = [];
    for ($i = 0; $i < count($existingRows); $i++) {
      $row = $existingRows[$i];
      $uri = $this->readRowUri($row, $uriIdx);
      if ($uri !== '') {
        if (!isset($existingByUri[$uri])) {
          $existingByUri[$uri] = $i;
        }
      }
      else {
        $existingNoUriSignatures[$this->buildRowSignature($row)] = TRUE;
      }
    }

    $addedRows = 0;
    $updatedRows = 0;
    $unchangedRows = 0;
    $incomingAccepted = 0;
    $addedFields = 0;
    $updatedFields = 0;

    foreach ($incomingRows as $incomingRow) {
      $incomingAccepted++;
      $uri = $this->readRowUri($incomingRow, $uriIdx);
      if ($uri !== '' && isset($existingByUri[$uri])) {
        $idx = (int) $existingByUri[$uri];
        $beforeRow = $existingRows[$idx];
        $merged = $this->mergeTaskRowsPreferIncoming($beforeRow, $incomingRow);
        if ($this->rowsEqual($beforeRow, $merged)) {
          $unchangedRows++;
        }
        else {
          $existingRows[$idx] = $merged;
          $updatedRows++;

          foreach ($trackedPropertyIndexes as $label => $colIdx) {
            if ($colIdx < 0) {
              continue;
            }
            $oldVal = isset($beforeRow[$colIdx]) ? trim((string) $beforeRow[$colIdx]) : '';
            $newVal = isset($merged[$colIdx]) ? trim((string) $merged[$colIdx]) : '';
            if ($oldVal === '' && $newVal !== '') {
              $propertyFieldChanges[$label]['added']++;
              $addedFields++;
            }
            elseif ($oldVal !== '' && $newVal !== '' && $oldVal !== $newVal) {
              $propertyFieldChanges[$label]['updated']++;
              $updatedFields++;
            }
          }
        }
        continue;
      }

      if ($uri === '') {
        $sig = $this->buildRowSignature($incomingRow);
        if (isset($existingNoUriSignatures[$sig])) {
          $unchangedRows++;
          continue;
        }
        $existingNoUriSignatures[$sig] = TRUE;
      }
      else {
        $existingByUri[$uri] = count($existingRows);
      }

      $existingRows[] = $incomingRow;
      $addedRows++;

      foreach ($trackedPropertyIndexes as $label => $colIdx) {
        if ($colIdx < 0) {
          continue;
        }
        $newVal = isset($incomingRow[$colIdx]) ? trim((string) $incomingRow[$colIdx]) : '';
        if ($newVal !== '') {
          $propertyFieldChanges[$label]['added']++;
          $addedFields++;
        }
      }
    }

    $mergedLines = [implode("\t", $targetHeader)];
    foreach ($existingRows as $row) {
      $mergedLines[] = implode("\t", array_map(function ($value) {
        return is_string($value) ? $value : '';
      }, $row));
    }

    return [
      'mergedSheetTsv' => implode("\n", $mergedLines),
      'stats' => [
        'strategy' => 'merge_into_existing_std',
        'incomingRowsAccepted' => $incomingAccepted,
        'existingRows' => count($existingParsed['rows']),
        'addedRows' => $addedRows,
        'updatedRows' => $updatedRows,
        'unchangedRows' => $unchangedRows,
        'mergedRows' => count($existingRows),
        'addedFields' => $addedFields,
        'updatedFields' => $updatedFields,
        'propertyFieldChanges' => $propertyFieldChanges,
        'propertyCoverage' => $this->inspectStdPropertyCoverage($targetHeader, $existingRows),
      ],
    ];
  }

  /**
   * Inspect coverage of expected Phase III STD properties.
   */
  protected function inspectStdPropertyCoverage(array $header, array $rows): array {
    $targets = [
      'vstoi:haslearningobjectives' => 'vstoi:hasLearningObjectives',
      'vstoi:hascriticalactions' => 'vstoi:hasCriticalActions',
      'vstoi:hasdebriefingfocus' => 'vstoi:hasDebriefingFocus',
      'specificaims' => 'Specific Aims',
      'significance' => 'Significance',
    ];

    $lookup = $this->buildHeaderLookup($header);
    $coverage = [];
    foreach ($targets as $normalized => $label) {
      $coverage[$label] = [
        'columnPresent' => isset($lookup[$normalized]),
        'rowsWithValue' => 0,
      ];
    }

    foreach ($targets as $normalized => $label) {
      if (!isset($lookup[$normalized])) {
        continue;
      }
      $idx = (int) $lookup[$normalized];
      $rowsWithValue = 0;
      foreach ($rows as $row) {
        if (!is_array($row)) {
          continue;
        }
        $cell = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
        if ($cell !== '') {
          $rowsWithValue++;
        }
      }
      $coverage[$label]['rowsWithValue'] = $rowsWithValue;
    }

    return $coverage;
  }

  /**
   * Parse a generic TSV sheet into header and padded rows.
   */
  protected function parseGenericSheetTsv(string $sheetTsv): array {
    $normalized = $this->normalizeSheetTsv($sheetTsv);
    if ($normalized === '') {
      return ['valid' => FALSE];
    }

    $lines = explode("\n", $normalized);
    if (count($lines) < 1) {
      return ['valid' => FALSE];
    }

    $headerLine = trim((string) $lines[0]);
    if ($headerLine === '' || strpos($headerLine, "\t") === FALSE) {
      return ['valid' => FALSE];
    }

    $header = array_map('trim', explode("\t", $headerLine));
    $expected = count($header);
    if ($expected < 1) {
      return ['valid' => FALSE];
    }

    $rows = [];
    for ($i = 1; $i < count($lines); $i++) {
      $line = (string) $lines[$i];
      if (trim($line) === '') {
        continue;
      }

      $cols = explode("\t", $line);
      if (count($cols) > $expected) {
        while (count($cols) > $expected && trim((string) end($cols)) === '') {
          array_pop($cols);
        }
      }
      if (count($cols) < $expected) {
        $cols = array_pad($cols, $expected, '');
      }
      if (count($cols) > $expected) {
        $cols = array_slice($cols, 0, $expected);
      }
      $rows[] = $cols;
    }

    return [
      'valid' => TRUE,
      'header' => $header,
      'rows' => $rows,
      'normalizedTsv' => $normalized,
    ];
  }

  /**
   * Validate whether a Task Model Update payload is a well-formed Tasks TSV.
   */
  protected function validateTaskModelSheetTsv(string $sheetTsv): array {
    $normalizationNotes = [];
    $canonical = $this->canonicalizeTaskModelSheetInput($sheetTsv, $normalizationNotes);
    $normalized = $this->normalizeSheetTsv($canonical);
    if ($normalized === '') {
      return [
        'valid' => FALSE,
        'summary' => 'Tasks TSV is empty after normalization.',
      ];
    }

    $lines = explode("\n", $normalized);
    if (count($lines) < 2) {
      return [
        'valid' => FALSE,
        'summary' => 'Tasks TSV must include a header row and at least one task row.',
      ];
    }

    $headerLine = trim((string) $lines[0]);
    if ($headerLine === '' || strpos($headerLine, "\t") === FALSE) {
      $hint = 'Tasks TSV header must be tab-separated using real TAB characters (U+0009).';
      if (strpos($headerLine, '\\t') !== FALSE) {
        $hint .= ' Detected literal "\\t" text; replace it with actual tab characters.';
      }
      elseif (strpos($headerLine, '|') !== FALSE) {
        $hint .= ' Detected "|" separators; use real tabs instead.';
      }
      elseif (strpos($headerLine, ',') !== FALSE) {
        $hint .= ' Detected comma separators; use real tabs instead.';
      }
      elseif (preg_match('/\s{2,}/', $headerLine) === 1) {
        $hint .= ' Detected repeated spaces; spaces are not valid separators.';
      }
      return [
        'valid' => FALSE,
        'summary' => $hint,
      ];
    }

    $headerCols = array_map('trim', explode("\t", $headerLine));
    $headerLookup = [];
    foreach ($headerCols as $col) {
      if ($col === '') {
        continue;
      }
      $headerLookup[$this->normalizeTsvHeaderColumn($col)] = TRUE;
    }

    $requiredHeaders = ['hasuri', 'rdf:type', 'hasco:hascotype'];
    foreach ($requiredHeaders as $required) {
      if (!isset($headerLookup[$required])) {
        return [
          'valid' => FALSE,
          'summary' => 'Tasks TSV header is missing required column: ' . $required,
        ];
      }
    }

    if (!isset($headerLookup['vstoi:hassupertask']) && !isset($headerLookup['vstoi:hassubtask'])) {
      return [
        'valid' => FALSE,
        'summary' => 'Tasks TSV header must include vstoi:hasSupertask or vstoi:hasSubtask.',
      ];
    }

    $columnCount = count($headerCols);
    $normalizedLines = [$headerLine];
    $dataRowCount = 0;
    $paddedRows = [];
    for ($i = 1; $i < count($lines); $i++) {
      $line = (string) $lines[$i];
      if (trim($line) === '') {
        continue;
      }

      $rowCols = explode("\t", $line);
      $rowCols = $this->normalizeTaskModelRowColumnCount($rowCols, $headerCols, $normalizationNotes, $i + 1);
      if (count($rowCols) > $columnCount) {
        while (count($rowCols) > $columnCount && trim((string) end($rowCols)) === '') {
          array_pop($rowCols);
        }
      }

      if (count($rowCols) > $columnCount) {
        return [
          'valid' => FALSE,
          'summary' => 'Tasks TSV row ' . ($i + 1) . ' has ' . count($rowCols) . ' columns; expected ' . $columnCount . '.',
        ];
      }

      if (count($rowCols) < $columnCount) {
        $rowCols = array_pad($rowCols, $columnCount, '');
        $paddedRows[] = $i + 1;
      }

      $rowCols = $this->normalizeTaskModelRowValues($rowCols, $headerCols, $normalizationNotes, $i + 1);

      $normalizedLines[] = implode("\t", $rowCols);
      $dataRowCount++;
    }

    if (!empty($paddedRows)) {
      $normalizationNotes[] = 'Auto-padded missing trailing columns in rows: ' . implode(', ', $paddedRows) . '.';
    }

    $normalizedConsistentTsv = implode("\n", $normalizedLines);

    return [
      'valid' => TRUE,
      'summary' => 'Tasks TSV is well-formed.',
      'rows' => $dataRowCount,
      'columns' => $columnCount,
      'normalizationNotes' => $normalizationNotes,
      'normalizedTsv' => $normalizedConsistentTsv,
    ];
  }

  /**
   * Repair a common Phase IV LLM output: one extra component column in task rows.
   */
  protected function normalizeTaskModelRowColumnCount(array $rowCols, array $headerCols, array &$normalizationNotes, int $rowNumber): array {
    if (count($rowCols) !== count($headerCols) + 1) {
      return $rowCols;
    }

    $lookup = $this->buildHeaderLookup($headerCols);
    if (!isset($lookup['vstoi:usescomponentinstance']) || isset($lookup['vstoi:hasrequiredinstrument'])) {
      return $rowCols;
    }

    $usesIdx = (int) $lookup['vstoi:usescomponentinstance'];
    $currentUses = trim((string) ($rowCols[$usesIdx] ?? ''));
    $nextValue = trim((string) ($rowCols[$usesIdx + 1] ?? ''));
    $looksLikeComponentUris = static function (string $value): bool {
      if ($value === '') {
        return FALSE;
      }
      foreach (preg_split('/\s*;\s*/', $value) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '' && stripos($part, 'http') !== 0) {
          return FALSE;
        }
      }
      return TRUE;
    };

    if ($currentUses === '' && $looksLikeComponentUris($nextValue)) {
      $rowCols[$usesIdx] = $nextValue;
      array_splice($rowCols, $usesIdx + 1, 1);
      $normalizationNotes[] = 'Moved extra Phase IV component-instance column into vstoi:usesComponentInstance on row ' . $rowNumber . '.';
      return $rowCols;
    }

    if ($currentUses !== '' && ($nextValue === '' || $nextValue === $currentUses || $looksLikeComponentUris($nextValue))) {
      array_splice($rowCols, $usesIdx + 1, 1);
      $normalizationNotes[] = 'Removed extra Phase IV component-instance column on row ' . $rowNumber . '.';
      return $rowCols;
    }

    return $rowCols;
  }

  /**
   * Normalize task row values that are valid data but fragile for WKF ingestion.
   */
  protected function normalizeTaskModelRowValues(array $rowCols, array $headerCols, array &$normalizationNotes, int $rowNumber): array {
    $lookup = $this->buildHeaderLookup($headerCols);
    $uriIdx = $lookup['hasuri'] ?? -1;
    $superIdx = $lookup['vstoi:hassupertask'] ?? -1;
    $subIdx = $lookup['vstoi:hassubtask'] ?? -1;
    $temporalIdx = $lookup['vstoi:hastemporaldependency'] ?? -1;
    $usesIdx = $lookup['vstoi:usescomponentinstance'] ?? -1;

    if ($subIdx >= 0 && isset($rowCols[$subIdx])) {
      $normalized = $this->normalizeTaskModelUriListCell((string) $rowCols[$subIdx]);
      if ($normalized !== (string) $rowCols[$subIdx]) {
        $rowCols[$subIdx] = $normalized;
        $normalizationNotes[] = 'Normalized comma-separated vstoi:hasSubtask URI list on row ' . $rowNumber . '.';
      }
    }

    if ($usesIdx >= 0 && isset($rowCols[$usesIdx])) {
      $normalized = $this->normalizeTaskModelUriListCell((string) $rowCols[$usesIdx]);
      if ($normalized !== (string) $rowCols[$usesIdx]) {
        $rowCols[$usesIdx] = $normalized;
        $normalizationNotes[] = 'Normalized comma-separated vstoi:usesComponentInstance URI list on row ' . $rowNumber . '.';
      }
    }

    if ($superIdx >= 0 && $subIdx >= 0 && $temporalIdx >= 0 && $usesIdx >= 0) {
      $super = trim((string) ($rowCols[$superIdx] ?? ''));
      $sub = trim((string) ($rowCols[$subIdx] ?? ''));
      $temporal = trim((string) ($rowCols[$temporalIdx] ?? ''));
      $uses = trim((string) ($rowCols[$usesIdx] ?? ''));

      if ($super === ''
        && $this->looksLikeSingleTaskUri($sub)
        && $this->looksLikeTaskUriList($temporal)
        && $this->looksLikeTemporalDependency($uses)) {
        $rowCols[$superIdx] = $sub;
        $rowCols[$subIdx] = $this->normalizeTaskModelUriListCell($temporal);
        $rowCols[$temporalIdx] = $uses;
        $rowCols[$usesIdx] = '';
        $normalizationNotes[] = 'Repaired shifted hasSupertask/hasSubtask/hasTemporalDependency columns on row ' . $rowNumber . '.';
      }
      elseif ($super === ''
        && $this->looksLikeSingleTaskUri($sub)
        && $this->looksLikeTaskUriList($temporal)
        && $uses === '') {
        $rowCols[$superIdx] = $sub;
        $rowCols[$subIdx] = $this->normalizeTaskModelUriListCell($temporal);
        $rowCols[$temporalIdx] = '';
        $normalizationNotes[] = 'Repaired shifted hasSupertask/hasSubtask columns on row ' . $rowNumber . '.';
      }
      elseif ($super === ''
        && $this->looksLikeSingleTaskUri($sub)
        && $temporal === '') {
        $rowCols[$superIdx] = $sub;
        $rowCols[$subIdx] = '';
        $normalizationNotes[] = 'Repaired shifted leaf hasSupertask column on row ' . $rowNumber . '.';
      }
      elseif ($this->looksLikeTemporalDependency($super) && $temporal === '') {
        $rowCols[$superIdx] = '';
        $rowCols[$temporalIdx] = $super;
        $normalizationNotes[] = 'Moved temporal dependency out of vstoi:hasSupertask on row ' . $rowNumber . '.';
      }
    }

    return $rowCols;
  }

  /**
   * Normalize multi-URI cells to semicolon separators.
   */
  protected function normalizeTaskModelUriListCell(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $parts = preg_split('/\s*[,;|]\s*/', $value) ?: [];
    $uris = [];
    foreach ($parts as $part) {
      $part = trim((string) $part);
      if ($part !== '') {
        $uris[] = $part;
      }
    }

    if (count($uris) <= 1) {
      return $value;
    }

    return implode(';', $uris);
  }

  protected function looksLikeSingleTaskUri(string $value): bool {
    $value = trim($value);
    return preg_match('#^https?://[^\s,;|]+/TSK/[^\s,;|]+$#i', $value) === 1;
  }

  protected function looksLikeTaskUriList(string $value): bool {
    $value = trim($value);
    if ($value === '') {
      return FALSE;
    }
    $parts = preg_split('/\s*[,;|]\s*/', $value) ?: [];
    if (count(array_filter($parts, static fn($part) => trim((string) $part) !== '')) < 1) {
      return FALSE;
    }
    foreach ($parts as $part) {
      $part = trim((string) $part);
      if ($part !== '' && preg_match('#^https?://[^\s,;|]+/TSK/[^\s,;|]+$#i', $part) !== 1) {
        return FALSE;
      }
    }
    return TRUE;
  }

  protected function looksLikeTemporalDependency(string $value): bool {
    return preg_match('/^(after|before|parallel|choice|independent|disables|interrupts)\b/i', trim($value)) === 1;
  }

  /**
   * Merge incoming Task Model TSV rows into existing workbook Tasks sheet.
   */
  protected function mergeTaskModelSheetIntoWorkbook(string $workbookTsv, string $incomingTasksTsv): array {
    $existingSheet = $this->extractSheetBlockFromWorkbookTsv($workbookTsv, 'Tasks');
    if ($existingSheet === '') {
      $existingSheet = $this->extractTasksSheetFromAnyTsv($workbookTsv);
    }

    $incomingParsed = $this->parseTaskSheetTsv($incomingTasksTsv);
    if (empty($incomingParsed['valid'])) {
      return [
        'mergedSheetTsv' => $this->normalizeSheetTsv($incomingTasksTsv),
        'stats' => [
          'strategy' => 'replace_incoming_only',
          'incomingRowsAccepted' => 0,
          'existingRows' => 0,
          'addedRows' => 0,
          'updatedRows' => 0,
          'unchangedRows' => 0,
          'mergedRows' => 0,
        ],
      ];
    }

    if ($existingSheet === '') {
      return [
        'mergedSheetTsv' => $incomingParsed['normalizedTsv'],
        'stats' => [
          'strategy' => 'replace_no_existing_tasks_sheet',
          'incomingRowsAccepted' => count($incomingParsed['rows']),
          'existingRows' => 0,
          'addedRows' => count($incomingParsed['rows']),
          'updatedRows' => 0,
          'unchangedRows' => 0,
          'mergedRows' => count($incomingParsed['rows']),
        ],
      ];
    }

    $existingParsed = $this->parseTaskSheetTsv($existingSheet);
    if (empty($existingParsed['valid'])) {
      return [
        'mergedSheetTsv' => $incomingParsed['normalizedTsv'],
        'stats' => [
          'strategy' => 'replace_existing_unparseable',
          'incomingRowsAccepted' => count($incomingParsed['rows']),
          'existingRows' => 0,
          'addedRows' => count($incomingParsed['rows']),
          'updatedRows' => 0,
          'unchangedRows' => 0,
          'mergedRows' => count($incomingParsed['rows']),
        ],
      ];
    }

    // Task Model Update uploads are complete Tasks sheets. Treat the submitted
    // sheet as authoritative so intentionally removed tasks are not retained.
    $existingUris = [];
    $existingUriIndex = $this->buildHeaderLookup($existingParsed['header'])['hasuri'] ?? -1;
    if ($existingUriIndex >= 0) {
      foreach ($existingParsed['rows'] as $row) {
        $uri = trim((string) ($row[$existingUriIndex] ?? ''));
        if ($uri !== '') {
          $existingUris[$uri] = TRUE;
        }
      }
    }
    $incomingUriIndex = $this->buildHeaderLookup($incomingParsed['header'])['hasuri'] ?? -1;
    $addedRows = 0;
    $updatedRows = 0;
    foreach ($incomingParsed['rows'] as $row) {
      $uri = $incomingUriIndex >= 0 ? trim((string) ($row[$incomingUriIndex] ?? '')) : '';
      if ($uri !== '' && isset($existingUris[$uri])) {
        $updatedRows++;
      }
      else {
        $addedRows++;
      }
    }
    return [
      'mergedSheetTsv' => $incomingParsed['normalizedTsv'],
      'stats' => [
        'strategy' => 'replace_complete_tasks_sheet',
        'incomingRowsAccepted' => count($incomingParsed['rows']),
        'existingRows' => count($existingParsed['rows']),
        'addedRows' => $addedRows,
        'updatedRows' => $updatedRows,
        'unchangedRows' => 0,
        'mergedRows' => count($incomingParsed['rows']),
      ],
    ];

    $existingRows = $existingParsed['rows'];
    $targetHeader = $existingParsed['header'];

    // If incoming payload carries usesComponentInstance but the existing
    // Tasks sheet does not, extend the target schema so enrichment is retained.
    $incomingLookup = $this->buildHeaderLookup($incomingParsed['header']);
    $targetLookup = $this->buildHeaderLookup($targetHeader);
    $usesKey = 'vstoi:usescomponentinstance';
    if (isset($incomingLookup[$usesKey]) && !isset($targetLookup[$usesKey])) {
      $incomingUsesIdx = (int) $incomingLookup[$usesKey];
      $incomingUsesHeader = isset($incomingParsed['header'][$incomingUsesIdx])
        ? trim((string) $incomingParsed['header'][$incomingUsesIdx])
        : '';
      $targetHeader[] = $incomingUsesHeader !== '' ? $incomingUsesHeader : 'vstoi:usesComponentInstance';

      for ($i = 0; $i < count($existingRows); $i++) {
        $existingRows[$i][] = '';
      }

      $targetLookup = $this->buildHeaderLookup($targetHeader);
    }

    $uriIdx = $targetLookup['hasuri'] ?? -1;

    $incomingRows = $this->remapRowsToTargetHeader(
      $incomingParsed['rows'],
      $incomingParsed['header'],
      $targetHeader
    );

    $existingByUri = [];
    $existingNoUriSignatures = [];
    for ($i = 0; $i < count($existingRows); $i++) {
      $row = $existingRows[$i];
      $uri = $this->readRowUri($row, $uriIdx);
      if ($uri !== '') {
        if (!isset($existingByUri[$uri])) {
          $existingByUri[$uri] = $i;
        }
      }
      else {
        $existingNoUriSignatures[$this->buildRowSignature($row)] = TRUE;
      }
    }

    $addedRows = 0;
    $updatedRows = 0;
    $unchangedRows = 0;
    $incomingAccepted = 0;

    foreach ($incomingRows as $incomingRow) {
      $incomingAccepted++;
      $uri = $this->readRowUri($incomingRow, $uriIdx);
      if ($uri !== '' && isset($existingByUri[$uri])) {
        $idx = (int) $existingByUri[$uri];
        $merged = $this->mergeTaskRowsPreferIncoming($existingRows[$idx], $incomingRow);
        if ($this->rowsEqual($existingRows[$idx], $merged)) {
          $unchangedRows++;
        }
        else {
          $existingRows[$idx] = $merged;
          $updatedRows++;
        }
        continue;
      }

      if ($uri === '') {
        $sig = $this->buildRowSignature($incomingRow);
        if (isset($existingNoUriSignatures[$sig])) {
          $unchangedRows++;
          continue;
        }
        $existingNoUriSignatures[$sig] = TRUE;
      }
      else {
        $existingByUri[$uri] = count($existingRows);
      }

      $existingRows[] = $incomingRow;
      $addedRows++;
    }

    $mergedLines = [implode("\t", $targetHeader)];
    foreach ($existingRows as $row) {
      $mergedLines[] = implode("\t", array_map(function ($value) {
        return is_string($value) ? $value : '';
      }, $row));
    }
    $mergedTsv = implode("\n", $mergedLines);

    return [
      'mergedSheetTsv' => $mergedTsv,
      'stats' => [
        'strategy' => 'merge_into_existing',
        'incomingRowsAccepted' => $incomingAccepted,
        'existingRows' => count($existingParsed['rows']),
        'addedRows' => $addedRows,
        'updatedRows' => $updatedRows,
        'unchangedRows' => $unchangedRows,
        'mergedRows' => count($existingRows),
      ],
    ];
  }

  /**
   * Parse Tasks TSV into normalized header and padded row arrays.
   */
  protected function parseTaskSheetTsv(string $sheetTsv): array {
    $normalized = $this->normalizeSheetTsv($sheetTsv);
    if ($normalized === '') {
      return ['valid' => FALSE];
    }

    $lines = explode("\n", $normalized);
    if (count($lines) < 1) {
      return ['valid' => FALSE];
    }

    $header = array_map('trim', explode("\t", (string) $lines[0]));
    if (empty($header) || count($header) < 2) {
      return ['valid' => FALSE];
    }

    $rows = [];
    $expected = count($header);
    for ($i = 1; $i < count($lines); $i++) {
      $line = (string) $lines[$i];
      if (trim($line) === '') {
        continue;
      }

      $cols = explode("\t", $line);
      if (count($cols) > $expected) {
        while (count($cols) > $expected && trim((string) end($cols)) === '') {
          array_pop($cols);
        }
      }
      if (count($cols) < $expected) {
        $cols = array_pad($cols, $expected, '');
      }
      if (count($cols) > $expected) {
        $cols = array_slice($cols, 0, $expected);
      }
      $rows[] = $cols;
    }

    return [
      'valid' => TRUE,
      'header' => $header,
      'rows' => $rows,
      'normalizedTsv' => $normalized,
    ];
  }

  /**
   * Build lookup map for normalized header names.
   */
  protected function buildHeaderLookup(array $header): array {
    $lookup = [];
    for ($i = 0; $i < count($header); $i++) {
      $name = is_string($header[$i]) ? $this->normalizeTsvHeaderColumn($header[$i]) : '';
      if ($name === '' || isset($lookup[$name])) {
        continue;
      }
      $lookup[$name] = $i;
    }
    return $lookup;
  }

  /**
   * Remap rows from one header layout into another.
   */
  protected function remapRowsToTargetHeader(array $rows, array $sourceHeader, array $targetHeader): array {
    $sourceLookup = $this->buildHeaderLookup($sourceHeader);
    $targetLookup = $this->buildHeaderLookup($targetHeader);
    $targetCount = count($targetHeader);
    $remapped = [];

    foreach ($rows as $row) {
      $mapped = array_fill(0, $targetCount, '');
      foreach ($targetLookup as $normalizedName => $targetIdx) {
        if (!isset($sourceLookup[$normalizedName])) {
          continue;
        }
        $sourceIdx = (int) $sourceLookup[$normalizedName];
        $mapped[$targetIdx] = isset($row[$sourceIdx]) && is_string($row[$sourceIdx]) ? $row[$sourceIdx] : '';
      }
      $remapped[] = $mapped;
    }

    return $remapped;
  }

  /**
   * Read task URI from a row using resolved hasURI index.
   */
  protected function readRowUri(array $row, int $uriIdx): string {
    if ($uriIdx < 0 || !isset($row[$uriIdx])) {
      return '';
    }
    return trim((string) $row[$uriIdx]);
  }

  /**
   * Merge two task rows, preferring non-empty incoming values.
   */
  protected function mergeTaskRowsPreferIncoming(array $existingRow, array $incomingRow): array {
    $length = max(count($existingRow), count($incomingRow));
    $merged = [];
    for ($i = 0; $i < $length; $i++) {
      $existing = isset($existingRow[$i]) ? (string) $existingRow[$i] : '';
      $incoming = isset($incomingRow[$i]) ? (string) $incomingRow[$i] : '';
      $merged[] = trim($incoming) !== '' ? $incoming : $existing;
    }
    return $merged;
  }

  /**
   * Compare row arrays with exact column/value equality.
   */
  protected function rowsEqual(array $a, array $b): bool {
    if (count($a) !== count($b)) {
      return FALSE;
    }
    for ($i = 0; $i < count($a); $i++) {
      if ((string) $a[$i] !== (string) $b[$i]) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Build deduplication signature for rows without stable URI.
   */
  protected function buildRowSignature(array $row): string {
    $normalized = array_map(function ($value) {
      return strtolower(trim((string) $value));
    }, $row);
    return implode('|', $normalized);
  }

  /**
   * Canonicalize common Task Model input variants into plain TSV rows.
   */
  protected function canonicalizeTaskModelSheetInput(string $sheetTsv, array &$notes = []): string {
    $text = str_replace(["\r\n", "\r"], "\n", trim($sheetTsv));
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $text);
    if ($text === '') {
      return '';
    }

    // Accept sectioned responses and isolate only the output TSV section.
    if (preg_match('/OUTPUT_TASKS_SHEET_TSV\s*(.*?)\s*(?:OUTPUT_SUMMARY_JSON|OUTPUT_ASSUMPTIONS|\z)/si', $text, $m) === 1) {
      $text = trim((string) $m[1]);
      $notes[] = 'Extracted OUTPUT_TASKS_SHEET_TSV section.';
    }

    // Remove optional markdown code fences around TSV content.
    if (preg_match('/^```(?:tsv|text|plaintext)?\s*(.*?)\s*```$/si', $text, $fenced) === 1) {
      $text = trim((string) $fenced[1]);
      $notes[] = 'Removed markdown code fence wrapper.';
    }

    if ($text === '') {
      return '';
    }

    $lines = explode("\n", $text);
    if (empty($lines)) {
      return $text;
    }

    // If the paste includes prose/labels above the TSV, trim to first likely
    // Tasks header candidate line.
    $headerStart = -1;
    for ($i = 0; $i < count($lines); $i++) {
      $candidate = trim((string) $lines[$i]);
      if ($candidate === '') {
        continue;
      }

      $candidateLower = strtolower($candidate);
      $candidateCompact = preg_replace('/\s+/', '', $candidateLower);
      $looksLikeHeader = (strpos($candidateCompact, 'hasuri') !== FALSE)
        && (strpos($candidateCompact, 'rdf:type') !== FALSE)
        && (strpos($candidateCompact, 'hasco:hascotype') !== FALSE);

      if ($looksLikeHeader) {
        $headerStart = $i;
        break;
      }
    }

    if ($headerStart > 0) {
      $lines = array_slice($lines, $headerStart);
      $text = implode("\n", $lines);
      $notes[] = 'Skipped leading prose and started from detected Tasks header line.';
    }

    $header = trim((string) $lines[0]);

    if (strpos($header, "\t") === FALSE && strpos($text, '\\t') !== FALSE) {
      $text = str_replace('\\t', "\t", $text);
      $lines = explode("\n", $text);
      $header = trim((string) $lines[0]);
      $notes[] = 'Converted literal \\t sequences to real tab characters.';
    }

    if (strpos($header, "\t") !== FALSE) {
      return $text;
    }

    if (strpos($header, '|') !== FALSE) {
      $converted = [];
      foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
          continue;
        }
        if (preg_match('/^\|?\s*:?-{3,}.*$/', $trimmed) === 1) {
          continue;
        }

        $clean = trim($trimmed, '|');
        $cells = array_map('trim', explode('|', $clean));
        $converted[] = implode("\t", $cells);
      }
      if (!empty($converted)) {
        $notes[] = 'Converted pipe-delimited rows to tab-delimited TSV.';
        return implode("\n", $converted);
      }
    }

    if (strpos($header, ',') !== FALSE) {
      $converted = [];
      foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
          continue;
        }
        $cells = str_getcsv($trimmed, ',');
        $converted[] = implode("\t", array_map('trim', $cells));
      }
      if (!empty($converted)) {
        $notes[] = 'Converted comma-delimited rows to tab-delimited TSV.';
        return implode("\n", $converted);
      }
    }

    if (strpos($header, ';') !== FALSE) {
      $converted = [];
      foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
          continue;
        }
        $cells = str_getcsv($trimmed, ';');
        $converted[] = implode("\t", array_map('trim', $cells));
      }
      if (!empty($converted)) {
        $notes[] = 'Converted semicolon-delimited rows to tab-delimited TSV.';
        return implode("\n", $converted);
      }
    }

    if (preg_match('/\s{2,}/', $header) === 1) {
      $converted = [];
      foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
          continue;
        }
        $cells = preg_split('/\s{2,}/', $trimmed);
        if (!is_array($cells) || empty($cells)) {
          continue;
        }
        $converted[] = implode("\t", array_map('trim', $cells));
      }
      if (!empty($converted)) {
        $notes[] = 'Converted repeated-space-delimited rows to tab-delimited TSV.';
        return implode("\n", $converted);
      }
    }

    // Last-resort fallback for plain whitespace-delimited content (single spaces).
    // This is only accepted when all rows keep a consistent field count after split.
    if (strpos($header, "\t") === FALSE
      && strpos($header, '|') === FALSE
      && strpos($header, ',') === FALSE
      && strpos($header, ';') === FALSE
      && strpos(preg_replace('/\s+/', '', strtolower($header)), 'hasuri') !== FALSE
      && strpos(preg_replace('/\s+/', '', strtolower($header)), 'rdf:type') !== FALSE
      && strpos(preg_replace('/\s+/', '', strtolower($header)), 'hasco:hascotype') !== FALSE
    ) {
      $headerCols = preg_split('/\s+/', trim($header));
      $expected = is_array($headerCols) ? count($headerCols) : 0;
      if ($expected > 1) {
        $converted = [];
        $allConsistent = TRUE;
        foreach ($lines as $line) {
          $trimmed = trim($line);
          if ($trimmed === '') {
            continue;
          }
          $cells = preg_split('/\s+/', $trimmed);
          if (!is_array($cells) || count($cells) !== $expected) {
            $allConsistent = FALSE;
            break;
          }
          $converted[] = implode("\t", array_map('trim', $cells));
        }

        if ($allConsistent && !empty($converted)) {
          $notes[] = 'Converted whitespace-delimited rows to tab-delimited TSV.';
          return implode("\n", $converted);
        }
      }
    }

    return $text;
  }

  /**
   * Normalize TSV header column names for tolerant comparison.
   */
  protected function normalizeTsvHeaderColumn(string $column): string {
    $normalized = strtolower(trim($column));
    // Allow pasted variants like "rdf : type" or "hasco : hascoType".
    $normalized = preg_replace('/\s+/', '', $normalized);
    return is_string($normalized) ? $normalized : '';
  }

  /**
   * Replace one sheet section in workbook-like TSV content.
   */
  protected function replaceSheetBlockInWorkbookTsv(string $workbookTsv, string $sheetName, string $sheetTsv): string {
    $normalizedWorkbook = str_replace(["\r\n", "\r"], "\n", trim($workbookTsv));
    $normalizedSheet = $this->normalizeSheetTsv($sheetTsv);

    $header = '### SHEET: ' . $sheetName;
    $replacement = $header . "\n" . $normalizedSheet . "\n";
    $pattern = '/^### SHEET:\s*' . preg_quote($sheetName, '/') . '\s*$\n.*?(?=^### SHEET:\s*|\z)/ms';

    if (preg_match($pattern, $normalizedWorkbook) === 1) {
      $updated = preg_replace($pattern, $replacement, $normalizedWorkbook, 1);
      return is_string($updated) ? trim($updated) : trim($normalizedWorkbook . "\n\n" . $replacement);
    }

    return trim($normalizedWorkbook . "\n\n" . $replacement);
  }

  /**
   * Extract one sheet section from workbook-like TSV content.
   */
  protected function extractSheetBlockFromWorkbookTsv(string $workbookTsv, string $sheetName): string {
    $normalizedWorkbook = str_replace(["\r\n", "\r"], "\n", trim($workbookTsv));
    if ($normalizedWorkbook === '') {
      return '';
    }

    $pattern = '/^### SHEET:\s*' . preg_quote($sheetName, '/') . '\s*$\n(.*?)(?=^### SHEET:\s*|\z)/ms';
    if (preg_match($pattern, $normalizedWorkbook, $matches) !== 1) {
      return '';
    }

    $body = isset($matches[1]) ? trim((string) $matches[1]) : '';
    return $body !== '' ? $body : '';
  }

  /**
   * Best-effort extraction of Tasks sheet when input is plain TSV (no sheet markers).
   */
  protected function extractTasksSheetFromAnyTsv(string $content): string {
    $normalized = str_replace(["\r\n", "\r"], "\n", trim($content));
    if ($normalized === '') {
      return '';
    }

    // If this looks like full workbook text with sheet markers, rely on explicit parser only.
    if (stripos($normalized, '### SHEET:') !== false) {
      return '';
    }

    $lines = explode("\n", $normalized);
    if (empty($lines)) {
      return '';
    }

    $header = trim((string) $lines[0]);
    if ($header === '' || strpos($header, "\t") === false) {
      return '';
    }

    // Accept both v1 and v2 style task headers.
    $hasUri = stripos($header, 'hasURI') !== false;
    $hasType = stripos($header, 'rdf:type') !== false;
    $hasTaskType = stripos($header, 'hasco:hascoType') !== false;
    $hasTaskLink = (stripos($header, 'vstoi:hasSupertask') !== false) || (stripos($header, 'vstoi:hasSubtask') !== false);
    if (!($hasUri && $hasType && $hasTaskType && $hasTaskLink)) {
      return '';
    }

    return $normalized;
  }

  /**
   * Best-effort extraction of STD sheet when input is plain TSV (no sheet markers).
   */
  protected function extractStdSheetFromAnyTsv(string $content): string {
    $normalized = str_replace(["\r\n", "\r"], "\n", trim($content));
    if ($normalized === '') {
      return '';
    }

    if (stripos($normalized, '### SHEET:') !== FALSE) {
      return '';
    }

    $lines = explode("\n", $normalized);
    if (empty($lines)) {
      return '';
    }

    $header = trim((string) $lines[0]);
    if ($header === '' || strpos($header, "\t") === FALSE) {
      return '';
    }

    // STD rows usually include process and study identifiers.
    $headerLower = strtolower($header);
    $looksLikeStd = (strpos($headerLower, 'hasco:hasprocess') !== FALSE)
      || (strpos($headerLower, 'study id') !== FALSE)
      || (strpos($headerLower, 'workflow') !== FALSE);
    if (!$looksLikeStd) {
      return '';
    }

    return $normalized;
  }

  /**
   * Build organization-scoped instrument instance list with related component instances.
   */
  protected function buildInstrumentInstanceComponentList(string $wkfUri, string $workbookTsv = ''): string {
    $api = \Drupal::service('rep.api_connector');

    $organizationUri = $this->extractOrganizationUriFromWkf($api, $wkfUri, $workbookTsv);
    $emails = $this->extractOrganizationManagerEmailsFromWkf($api, $wkfUri);
    if (empty($emails)) {
      $current = trim((string) $this->currentUser()->getEmail());
      if ($current !== '') {
        $emails[] = strtolower($current);
      }
    }

    if ($organizationUri === '' && empty($emails)) {
      return '';
    }

    $componentPool = $this->loadComponentInstancesForPhase4Context($api, $organizationUri, $emails);
    $componentPoolByUri = [];
    foreach ($componentPool as $componentObj) {
      if (!is_object($componentObj) || empty($componentObj->uri)) {
        continue;
      }
      $componentUri = (string) $componentObj->uri;
      $componentPoolByUri[$componentUri] = $componentObj;
    }

    if (empty($componentPoolByUri)) {
      return '';
    }

    $mapping = $this->buildDeploymentComponentInstrumentMap($api, $organizationUri, $emails, $componentPoolByUri);
    $componentsByInstrumentUri = $mapping['componentsByInstrumentUri'] ?? [];
    $instrumentLabels = $mapping['instrumentLabels'] ?? [];
    $mappedComponentUris = $mapping['mappedComponentUris'] ?? [];

    if (!is_array($componentsByInstrumentUri)) {
      $componentsByInstrumentUri = [];
    }
    if (!is_array($instrumentLabels)) {
      $instrumentLabels = [];
    }
    if (!is_array($mappedComponentUris)) {
      $mappedComponentUris = [];
    }

    $lines = [];
    if ($organizationUri !== '') {
      $lines[] = 'ORGANIZATION_URI: ' . $organizationUri;
      $lines[] = '';
    }

    $lines[] = 'DEPLOYMENT_COMPONENT_TO_INSTRUMENT_MAP';
    if (empty($componentsByInstrumentUri)) {
      $lines[] = '(no deployment mapping found)';
    }

    foreach ($componentsByInstrumentUri as $instUri => $components) {
      $label = trim((string) ($instrumentLabels[$instUri] ?? $instUri));
      $lines[] = 'InstrumentInstance: ' . $label . ' [' . $instUri . ']';
      if (empty($components)) {
        $lines[] = '  Components: (none found)';
        continue;
      }

      foreach ($components as $component) {
        if (!is_object($component) || empty($component->uri)) {
          continue;
        }
        $compUri = trim((string) $component->uri);
        $compLabel = trim((string) ($component->label ?? $compUri));
        $lines[] = '  - ComponentInstance: ' . $compLabel . ' [' . $compUri . ']';
      }
    }

    $unmapped = [];
    foreach ($componentPoolByUri as $componentUri => $componentObj) {
      if (isset($mappedComponentUris[$componentUri])) {
        continue;
      }
      $unmapped[$componentUri] = $componentObj;
    }

    $lines[] = '';
    $lines[] = 'UNMAPPED_COMPONENT_INSTANCES_WITH_NO_DEPLOYMENT_LINK';
    if (empty($unmapped)) {
      $lines[] = '(none)';
    }
    else {
      foreach ($unmapped as $componentUri => $componentObj) {
        $componentLabel = trim((string) ($componentObj->label ?? $componentUri));
        $lines[] = '- ComponentInstance: ' . $componentLabel . ' [' . $componentUri . ']';
      }
    }

    $lines[] = '';
    $lines[] = 'ALL_COMPONENT_INSTANCES_IN_ORGANIZATION';
    foreach ($componentPoolByUri as $componentUri => $componentObj) {
      $componentLabel = trim((string) ($componentObj->label ?? $componentUri));
      $lines[] = '- ComponentInstance: ' . $componentLabel . ' [' . $componentUri . ']';
    }

    return trim(implode("\n", $lines));
  }

  /**
   * Resolve WKF organization URI.
   */
  protected function extractOrganizationUriFromWkf($api, string $wkfUri, string $workbookTsv = ''): string {
    $normalizedWkfUri = Utils::plainUri($wkfUri) ?: trim($wkfUri);
    if ($normalizedWkfUri === '') {
      return '';
    }

    $wkf = $api->parseObjectResponse($api->getUri($normalizedWkfUri), 'getUri');
    if (is_object($wkf) && !empty($wkf->hasOrganizationUri)) {
      return trim((string) $wkf->hasOrganizationUri);
    }

    $stdOrganizationUri = $this->extractOrganizationUriFromStdSheet($workbookTsv);
    if ($stdOrganizationUri !== '') {
      return $stdOrganizationUri;
    }

    $ownerEmails = is_object($wkf) ? $this->extractEmailsFromObject($wkf, ['hasSIRManagerEmail', 'hasWKFManagerEmail', 'mbox', 'hasEmail', 'email']) : [];
    foreach ($ownerEmails as $email) {
      $affiliationUri = $this->resolvePersonAffiliationUriByEmail($api, $email);
      if ($affiliationUri !== '') {
        return $affiliationUri;
      }
    }

    return '';
  }

  /**
   * Resolve organization URI from the workbook STD sheet.
   */
  protected function extractOrganizationUriFromStdSheet(string $workbookTsv): string {
    $stdSheet = $this->extractSheetBlockFromWorkbookTsv($workbookTsv, 'STD');
    if ($stdSheet === '') {
      return '';
    }

    $parsed = $this->parseGenericSheetTsv($stdSheet);
    if (empty($parsed['valid']) || empty($parsed['rows'])) {
      return '';
    }

    $lookup = $this->buildHeaderLookup($parsed['header']);
    foreach (['institution', 'hasco:hasinstitution', 'organization', 'organizationuri', 'hasorganizationuri'] as $key) {
      if (!isset($lookup[$key])) {
        continue;
      }
      $value = trim((string) ($parsed['rows'][0][(int) $lookup[$key]] ?? ''));
      if ($value !== '') {
        return Utils::plainUri($value) ?: Utils::canonicalizePmsrUri($value);
      }
    }

    return '';
  }

  /**
   * Load component instances for Phase IV context.
   *
   * Priority:
   * 1) organization-filtered componentinstance endpoint
   * 2) manager email fallback
   */
  protected function loadComponentInstancesForPhase4Context($api, string $organizationUri, array $emails): array {
    $indexed = [];

    if ($organizationUri !== '') {
      foreach ($this->resolveOrganizationScopeUris($api, $organizationUri) as $scopeOrganizationUri) {
        $organizationItems = $this->loadComponentInstancesByOrganizationUri($scopeOrganizationUri);
        foreach ($organizationItems as $item) {
          if (!is_object($item) || empty($item->uri)) {
            continue;
          }
          $indexed[(string) $item->uri] = $item;
        }
      }
    }

    if (empty($indexed)) {
      $fallbackItems = $this->loadManagerOwnedComponentInstances($api, $emails);
      foreach ($fallbackItems as $item) {
        if (!is_object($item) || empty($item->uri)) {
          continue;
        }
        $indexed[(string) $item->uri] = $item;
      }
    }

    return array_values($indexed);
  }

  /**
   * Resolve organization plus suborganizations for component inventory lookup.
   *
   * @return array<int, string>
   */
  protected function resolveOrganizationScopeUris($api, string $organizationUri): array {
    $root = Utils::plainUri($organizationUri) ?: Utils::canonicalizePmsrUri($organizationUri);
    $root = trim($root);
    if ($root === '') {
      return [];
    }

    $seen = [$root => TRUE];
    $queue = [$root];
    $maxOrganizations = 200;

    $parentQueue = [$root];
    while (!empty($parentQueue) && count($seen) < $maxOrganizations) {
      $current = array_shift($parentQueue);
      $org = $api->parseObjectResponse($api->getUri($current), 'getUri');
      if (!is_object($org)) {
        continue;
      }
      $parent = Utils::plainUri((string) ($org->parentOrganizationUri ?? '')) ?: Utils::canonicalizePmsrUri((string) ($org->parentOrganizationUri ?? ''));
      $parent = trim($parent);
      if ($parent === '' || isset($seen[$parent])) {
        continue;
      }
      $seen[$parent] = TRUE;
      $queue[] = $parent;
      $parentQueue[] = $parent;
    }

    while (!empty($queue) && count($seen) < $maxOrganizations) {
      $current = array_shift($queue);
      $raw = $api->getSubOrganizations($current, 100, 0);
      $subOrganizations = $api->parseObjectResponse($raw, 'getSubOrganizations');
      if (!is_array($subOrganizations)) {
        continue;
      }

      foreach ($subOrganizations as $organization) {
        if (!is_object($organization) || empty($organization->uri)) {
          continue;
        }
        $uri = Utils::plainUri((string) $organization->uri) ?: Utils::canonicalizePmsrUri((string) $organization->uri);
        $uri = trim($uri);
        if ($uri === '' || isset($seen[$uri])) {
          continue;
        }
        $seen[$uri] = TRUE;
        $queue[] = $uri;
      }
    }

    return array_keys($seen);
  }

  /**
   * Load all component instances filtered by organization URI via HASCOAPI endpoint.
   */
  protected function loadComponentInstancesByOrganizationUri(string $organizationUri): array {
    $baseUrl = trim((string) \Drupal::config('rep.settings')->get('api_url'));
    if ($baseUrl === '') {
      return [];
    }

    $baseUrl = rtrim($baseUrl, '/');
    $encodedOrg = rawurlencode($organizationUri);
    $pageSize = 250;
    $offset = 0;
    $maxPages = 200;
    $all = [];
    $client = \Drupal::httpClient();

    for ($page = 0; $page < $maxPages; $page++) {
      $url = $baseUrl . '/hascoapi/api/componentinstance/list/' . $pageSize . '/' . $offset . '?organizationUri=' . $encodedOrg;
      try {
        $response = $client->get($url, [
          'timeout' => 8,
          'connect_timeout' => 2,
          'http_errors' => FALSE,
        ]);
      }
      catch (\Exception $e) {
        break;
      }

      $payload = json_decode((string) $response->getBody());
      if (!is_object($payload) || !isset($payload->body) || !is_array($payload->body) || empty($payload->body)) {
        break;
      }

      foreach ($payload->body as $item) {
        $all[] = $item;
      }

      if (count($payload->body) < $pageSize) {
        break;
      }

      $offset += $pageSize;
    }

    return $all;
  }

  /**
   * Build deployment-backed component to instrument mapping.
   */
  protected function buildDeploymentComponentInstrumentMap($api, string $organizationUri, array $emails, array $componentPoolByUri): array {
    $componentsByInstrumentUri = [];
    $instrumentLabels = [];
    $mappedComponentUris = [];
    $remainingComponentUris = array_fill_keys(array_keys($componentPoolByUri), TRUE);
    $startTime = microtime(TRUE);
    $timeBudgetSeconds = 18.0;

    $emails = array_values(array_unique(array_filter(array_map(function ($value) {
      return is_string($value) ? trim($value) : '';
    }, $emails))));

    foreach ($emails as $email) {
      if ((microtime(TRUE) - $startTime) > $timeBudgetSeconds) {
        break;
      }

      $deployments = $this->loadDeploymentsByManagerEmailFast($email, $startTime, $timeBudgetSeconds);
      foreach ($deployments as $deployment) {
        if (!is_object($deployment)) {
          continue;
        }

        $instrumentUri = trim((string) ($deployment->instrumentInstanceUri ?? ''));
        if ($instrumentUri === '') {
          continue;
        }

        if (!isset($componentsByInstrumentUri[$instrumentUri])) {
          $componentsByInstrumentUri[$instrumentUri] = [];
        }

        if (!isset($instrumentLabels[$instrumentUri])) {
          $instrumentLabel = '';
          if (isset($deployment->instrumentInstance) && is_object($deployment->instrumentInstance) && isset($deployment->instrumentInstance->label)) {
            $instrumentLabel = trim((string) $deployment->instrumentInstance->label);
          }
          $instrumentLabels[$instrumentUri] = $instrumentLabel !== '' ? $instrumentLabel : $instrumentUri;
        }

        if (!isset($deployment->componentInstanceUri) || !is_array($deployment->componentInstanceUri)) {
          continue;
        }

        foreach ($deployment->componentInstanceUri as $componentUriCandidate) {
          $componentUri = trim((string) $componentUriCandidate);
          if ($componentUri === '' || !isset($componentPoolByUri[$componentUri])) {
            continue;
          }

          $componentsByInstrumentUri[$instrumentUri][$componentUri] = $componentPoolByUri[$componentUri];
          $mappedComponentUris[$componentUri] = TRUE;
          unset($remainingComponentUris[$componentUri]);
        }

        if (empty($remainingComponentUris)) {
          break 2;
        }
      }
    }

    return [
      'componentsByInstrumentUri' => $componentsByInstrumentUri,
      'instrumentLabels' => $instrumentLabels,
      'mappedComponentUris' => $mappedComponentUris,
    ];
  }

  /**
   * Load deployments by manager email using direct endpoint calls.
   *
   * This avoids expensive generic object expansion and supports early-stop
   * by honoring the shared time budget.
   */
  protected function loadDeploymentsByManagerEmailFast(string $managerEmail, float $startTime, float $timeBudgetSeconds): array {
    $managerEmail = trim($managerEmail);
    if ($managerEmail === '') {
      return [];
    }

    $baseUrl = trim((string) \Drupal::config('rep.settings')->get('api_url'));
    if ($baseUrl === '') {
      return [];
    }

    $baseUrl = rtrim($baseUrl, '/');
    $encodedEmail = rawurlencode($managerEmail);
    $pageSize = 80;
    $offset = 0;
    $maxPages = 120;
    $all = [];
    $client = \Drupal::httpClient();

    for ($page = 0; $page < $maxPages; $page++) {
      if ((microtime(TRUE) - $startTime) > $timeBudgetSeconds) {
        break;
      }

      $url = $baseUrl . '/hascoapi/api/deployment/manageremail/' . $encodedEmail . '/' . $pageSize . '/' . $offset;
      try {
        $response = $client->get($url, [
          'timeout' => 6,
          'connect_timeout' => 2,
          'http_errors' => FALSE,
        ]);
      }
      catch (\Exception $e) {
        break;
      }

      $payload = json_decode((string) $response->getBody());
      if (!is_object($payload) || !isset($payload->body) || !is_array($payload->body) || empty($payload->body)) {
        break;
      }

      foreach ($payload->body as $item) {
        $all[] = $item;
      }

      if (count($payload->body) < $pageSize) {
        break;
      }

      $offset += $pageSize;
    }

    return $all;
  }

  /**
   * Resolve organization manager emails from the WKF's organization relation.
   */
  protected function extractOrganizationManagerEmailsFromWkf($api, string $wkfUri): array {
    $normalizedWkfUri = Utils::plainUri($wkfUri) ?: trim($wkfUri);
    if ($normalizedWkfUri === '') {
      return [];
    }

    $wkf = $api->parseObjectResponse($api->getUri($normalizedWkfUri), 'getUri');
    if (!is_object($wkf)) {
      return [];
    }

    $emails = [];
    foreach (['hasSIRManagerEmail', 'hasWKFManagerEmail', 'mbox', 'hasEmail', 'email'] as $field) {
      if (!isset($wkf->{$field})) {
        continue;
      }
      $value = $wkf->{$field};
      if (is_string($value)) {
        $normalized = $this->normalizeManagerEmail($value);
        if ($normalized !== '') {
          $emails[$normalized] = TRUE;
        }
      }
      elseif (is_array($value)) {
        foreach ($value as $entry) {
          if (!is_string($entry)) {
            continue;
          }
          $normalized = $this->normalizeManagerEmail($entry);
          if ($normalized !== '') {
            $emails[$normalized] = TRUE;
          }
        }
      }
    }

    if (empty($wkf->hasOrganizationUri)) {
      return array_keys($emails);
    }

    $orgUri = trim((string) $wkf->hasOrganizationUri);
    if ($orgUri === '') {
      return array_keys($emails);
    }

    $org = $api->parseObjectResponse($api->getUri($orgUri), 'getUri');
    if (!is_object($org)) {
      return array_keys($emails);
    }

    foreach (['hasSIRManagerEmail', 'mbox', 'hasEmail', 'email'] as $field) {
      if (!isset($org->{$field})) {
        continue;
      }
      $value = $org->{$field};
      if (is_string($value)) {
        $normalized = $this->normalizeManagerEmail($value);
        if ($normalized !== '') {
          $emails[$normalized] = TRUE;
        }
      }
      elseif (is_array($value)) {
        foreach ($value as $entry) {
          if (!is_string($entry)) {
            continue;
          }
          $normalized = $this->normalizeManagerEmail($entry);
          if ($normalized !== '') {
            $emails[$normalized] = TRUE;
          }
        }
      }
    }

    return array_keys($emails);
  }

  /**
   * Normalize and validate manager email values.
   */
  protected function normalizeManagerEmail(string $value): string {
    $email = trim($value);
    if ($email === '') {
      return '';
    }

    if (stripos($email, 'mailto:') === 0) {
      $email = trim(substr($email, 7));
    }

    return strtolower($email);
  }

  /**
   * Extract normalized email values from an API object.
   */
  protected function extractEmailsFromObject($object, array $fields): array {
    $emails = [];
    if (!is_object($object)) {
      return [];
    }

    foreach ($fields as $field) {
      if (!isset($object->{$field})) {
        continue;
      }
      $value = $object->{$field};
      $values = is_array($value) ? $value : [$value];
      foreach ($values as $entry) {
        if (!is_string($entry)) {
          continue;
        }
        $email = $this->normalizeManagerEmail($entry);
        if ($email !== '') {
          $emails[$email] = TRUE;
        }
      }
    }

    return array_keys($emails);
  }

  /**
   * Resolve a person's affiliation organization URI from an email address.
   */
  protected function resolvePersonAffiliationUriByEmail($api, string $email): string {
    $email = $this->normalizeManagerEmail($email);
    if ($email === '') {
      return '';
    }

    foreach ([$api->listByManagerEmail('person', $email, 200, 0), $api->listByKeyword('person', '_', 1000, 0)] as $raw) {
      $people = $api->parseObjectResponse($raw, 'resolvePersonAffiliationUriByEmail');
      if (!is_array($people)) {
        continue;
      }

      foreach ($people as $person) {
        if (!is_object($person)) {
          continue;
        }
        $personEmails = $this->extractEmailsFromObject($person, ['hasEmail', 'email', 'mbox', 'hasSIRManagerEmail']);
        if (!in_array($email, $personEmails, TRUE)) {
          continue;
        }
        $affiliation = trim((string) ($person->hasAffiliationUri ?? ''));
        if ($affiliation === '' && isset($person->hasAffiliation) && is_object($person->hasAffiliation)) {
          $affiliation = trim((string) ($person->hasAffiliation->uri ?? ''));
        }
        if ($affiliation !== '') {
          return Utils::plainUri($affiliation) ?: Utils::canonicalizePmsrUri($affiliation);
        }
      }
    }

    return '';
  }

  /**
   * Load manager-owned component instances.
   */
  protected function loadManagerOwnedComponentInstances($api, array $emails): array {
    $indexed = [];
    foreach ($emails as $email) {
      if (!is_string($email) || trim($email) === '') {
        continue;
      }

      $objects = $this->listAllByManagerEmail($api, 'componentinstance', trim($email));

      foreach ($objects as $object) {
        if (!is_object($object) || empty($object->uri)) {
          continue;
        }
        $indexed[(string) $object->uri] = $object;
      }
    }

    return array_values($indexed);
  }

  /**
   * Retrieve all listByManagerEmail pages for one element type and manager.
   *
   * Uses paged retrieval until a page returns fewer rows than page size.
   * A high page guard avoids infinite loops on malformed backends.
   *
   * @return array<int, mixed>
   */
  protected function listAllByManagerEmail($api, string $elementType, string $managerEmail): array {
    $pageSize = 250;
    $offset = 0;
    $maxPages = 200;
    $all = [];

    for ($page = 0; $page < $maxPages; $page++) {
      $raw = $api->listByManagerEmail($elementType, $managerEmail, $pageSize, $offset);
      $chunk = $api->parseObjectResponse($raw, 'listByManagerEmail');
      if (!is_array($chunk) || empty($chunk)) {
        break;
      }

      foreach ($chunk as $item) {
        $all[] = $item;
      }

      if (count($chunk) < $pageSize) {
        break;
      }

      $offset += $pageSize;
    }

    return $all;
  }

  /**
   * Extract local identifier token from URI path.
   */
  protected function extractLocalIdFromUri(string $uri): string {
    $trimmed = trim($uri);
    if ($trimmed === '') {
      return '';
    }

    $parts = explode('/', $trimmed);
    $last = end($parts);
    return is_string($last) ? trim($last) : '';
  }

  /**
   * Accept raw rows or pasted sheet snippets and return clean TSV rows only.
   */
  protected function normalizeSheetTsv(string $sheetTsv): string {
    $normalized = str_replace(["\r\n", "\r"], "\n", trim($sheetTsv));
    if ($normalized === '') {
      return '';
    }

    $lines = explode("\n", $normalized);
    $filtered = [];
    foreach ($lines as $line) {
      $trimmed = trim($line);
      if ($trimmed === '' || stripos($trimmed, '### SHEET:') === 0) {
        continue;
      }
      $filtered[] = $line;
    }

    return trim(implode("\n", $filtered));
  }

  /**
   * Decode request payload from JSON body or form fields.
   */
  protected function decodePayload(Request $request): array {
    $content = (string) $request->getContent();
    if ($content !== '') {
      $decoded = json_decode($content, TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }

    $all = $request->request->all();
    return is_array($all) ? $all : [];
  }

  /**
   * Return phase-specific title.
   */
  protected function getPhaseTitle(int $phase): string {
    $map = [
      2 => 'Phase II - Task Model Generation',
      3 => 'Phase III - Properties Extraction',
      4 => 'Phase IV - Simulations Assignments',
    ];
    return $map[$phase] ?? ('Phase ' . $phase);
  }

  /**
   * Return phase-specific objective.
   */
  protected function getPhaseGoal(int $phase): string {
    $map = [
      2 => 'Generate the Tasks sheet TSV from the source document using WKF-SPEC-V3 rules.',
      3 => 'Extract and encode detailed properties into the WKF without regressing earlier phases.',
      4 => 'Complete simulations assignments and ingestion-readiness details.',
    ];
    return $map[$phase] ?? 'Update WKF according to phase objectives.';
  }

  /**
   * Load phase prompt from pmsr/prompts with fallback text.
   */
  protected function getPhasePromptText(int $phase): string {
    $modulePath = \Drupal::service('extension.list.module')->getPath('pmsr');
    $promptMap = [
      2 => 'PROMPT-WKF-PHASE2-TASKS-TSV.md',
      3 => 'PROMPT-WKF-PHASE3-STD-TSV.md',
      4 => 'PROMPT-WKF-PHASE4-TASKS-USES-COMPONENT-TSV.md',
    ];

    $fileName = $promptMap[$phase] ?? '';
    if ($fileName !== '') {
      $fullPath = DRUPAL_ROOT . '/' . $modulePath . '/prompts/' . $fileName;
      if (is_file($fullPath)) {
        $content = file_get_contents($fullPath);
        if (is_string($content) && trim($content) !== '') {
          return trim($content);
        }
      }
    }

    return 'Use the current WKF and source document to complete Phase ' . $phase . ' requirements and return the full updated WKF.';
  }

  /**
   * Accept a prompt override only when it does not explicitly target a
   * different phase than the backend phase being built.
   */
  protected function isPromptOverrideCompatibleWithPhase(string $promptOverride, int $phase): bool {
    $text = trim($promptOverride);
    if ($text === '') {
      return false;
    }

    $expectedMarkerMap = [
      2 => 'II',
      3 => 'III',
      4 => 'IV',
    ];
    $expectedMarker = $expectedMarkerMap[$phase] ?? '';
    if ($expectedMarker === '') {
      return true;
    }

    if (preg_match('/\bPHASE\s+(II|III|IV)\b/i', $text, $match) === 1) {
      $declared = strtoupper((string) ($match[1] ?? ''));
      if ($declared !== $expectedMarker) {
        return false;
      }
    }

    return true;
  }

  /**
   * Load WKF-SPEC-V3 text from pmsr module.
   */
  protected function getWkfSpecV3Text(): string {
    $modulePath = \Drupal::service('extension.list.module')->getPath('pmsr');
    $fullPath = DRUPAL_ROOT . '/' . $modulePath . '/WKF-SPEC-V3.md';
    if (!is_file($fullPath)) {
      return '';
    }

    $content = file_get_contents($fullPath);
    if (!is_string($content)) {
      return '';
    }

    return trim($content);
  }

  /**
   * Recover WKF-scoped validation/session context using the same scope strategy as REPSelectMTForm.
   */
  protected function resolveWkfScopedSessionContext(Request $request, string $wkfUri): array {
    $session = $request->getSession();
    if ($session === NULL) {
      return [];
    }

    $scope = $this->resolveWkfScope($request, $wkfUri);

    $context = [];

    $panelStore = $session->get('rep.wkf.validation.panel.by_scope', []);
    if (is_array($panelStore) && isset($panelStore[$scope]) && is_array($panelStore[$scope])) {
      $panel = $panelStore[$scope];
      if (isset($panel['wkfCopyContent']) && is_string($panel['wkfCopyContent'])) {
        $context['wkfContent'] = $panel['wkfCopyContent'];
      }
      if (isset($panel['snapshotId']) && is_string($panel['snapshotId'])) {
        $context['snapshotId'] = $panel['snapshotId'];
      }
      if (isset($panel['validatedAt']) && is_string($panel['validatedAt'])) {
        $context['validatedAt'] = $panel['validatedAt'];
      }

      // Use server-side summary/rules as fallback validation payload text.
      $summary = isset($panel['summary']) && is_string($panel['summary']) ? trim($panel['summary']) : '';
      $lines = [];
      if ($summary !== '') {
        $lines[] = $summary;
      }
      if (isset($panel['rules']) && is_array($panel['rules'])) {
        foreach ($panel['rules'] as $rule) {
          if (!is_array($rule)) {
            continue;
          }
          $rid = isset($rule['ruleId']) && is_string($rule['ruleId']) ? trim($rule['ruleId']) : '';
          $msg = isset($rule['message']) && is_string($rule['message']) ? trim($rule['message']) : '';
          if ($rid === '' && $msg === '') {
            continue;
          }
          $line = $rid !== '' ? ($rid . ': ' . $msg) : $msg;
          $lines[] = '- ' . trim($line);
        }
      }
      if (!empty($lines)) {
        $context['validationMessages'] = implode("\n", $lines);
      }
    }

    $workflowStore = $session->get('rep.wkf.workflow.state.by_scope', []);
    if (is_array($workflowStore) && isset($workflowStore[$scope]) && is_array($workflowStore[$scope])) {
      $wf = $workflowStore[$scope];
      if (!isset($context['snapshotId']) && isset($wf['snapshotId']) && is_string($wf['snapshotId'])) {
        $context['snapshotId'] = $wf['snapshotId'];
      }
      if (!isset($context['validatedAt']) && isset($wf['validatedAt']) && is_string($wf['validatedAt'])) {
        $context['validatedAt'] = $wf['validatedAt'];
      }
      if (isset($wf['wkfUri']) && is_string($wf['wkfUri']) && trim($wf['wkfUri']) !== '') {
        $uriPath = parse_url($wf['wkfUri'], PHP_URL_PATH);
        $base = is_string($uriPath) && $uriPath !== '' ? basename($uriPath) : basename($wf['wkfUri']);
        if ($base !== '') {
          $snapshot = isset($context['snapshotId']) ? trim((string) $context['snapshotId']) : '';
          $context['versionLabel'] = $snapshot !== '' ? ($base . ' @ ' . $snapshot) : $base;
        }
      }
    }

    $phase1Context = $this->getPersistedPhase1ContextByUri($wkfUri);
    if (!empty($phase1Context)) {
      $existingSource = isset($context['sourceDocument']) && is_string($context['sourceDocument'])
        ? trim($context['sourceDocument'])
        : '';
      if ($existingSource === '') {
        if (isset($phase1Context['sourceDocumentContent']) && is_string($phase1Context['sourceDocumentContent']) && trim($phase1Context['sourceDocumentContent']) !== '') {
          $context['sourceDocument'] = trim($phase1Context['sourceDocumentContent']);
        }
        elseif (isset($phase1Context['sourceDocumentContext']) && is_string($phase1Context['sourceDocumentContext'])) {
          $context['sourceDocument'] = trim($phase1Context['sourceDocumentContext']);
        }
      }

      $existingWkfContent = isset($context['wkfContent']) && is_string($context['wkfContent'])
        ? trim($context['wkfContent'])
        : '';
      if ($existingWkfContent === '') {
        if (isset($phase1Context['phase1WkfTableTsv']) && is_string($phase1Context['phase1WkfTableTsv']) && trim($phase1Context['phase1WkfTableTsv']) !== '') {
          $context['wkfContent'] = trim($phase1Context['phase1WkfTableTsv']);
        }
        elseif (isset($phase1Context['phase1CoreContext']) && is_string($phase1Context['phase1CoreContext'])) {
          $context['wkfContent'] = trim($phase1Context['phase1CoreContext']);
        }
      }

      if (isset($phase1Context['phase1WkfTableTsv']) && is_string($phase1Context['phase1WkfTableTsv']) && trim($phase1Context['phase1WkfTableTsv']) !== '') {
        $context['phase1WkfTableTsv'] = trim($phase1Context['phase1WkfTableTsv']);
      }
    }

    return $context;
  }

  /**
   * Read persisted Phase I packet context keyed by WKF URI.
   */
  protected function getPersistedPhase1ContextByUri(string $wkfUri): array {
    $normalized = Utils::plainUri($wkfUri) ?: trim($wkfUri);
    if ($normalized === '') {
      return [];
    }

    $store = \Drupal::keyValue('rep.wkf.phase1.context.by_uri');
    $candidateKeys = [$normalized, trim($wkfUri), Utils::namespaceUri($normalized)];
    foreach (array_values(array_unique(array_filter($candidateKeys))) as $key) {
      $entry = $store->get($key, []);
      if (is_array($entry) && !empty($entry)) {
        return $entry;
      }
    }

    return [];
  }

  /**
   * Resolve WKF scope token from URI/session.
   */
  protected function resolveWkfScope(Request $request, string $wkfUri): string {
    $session = $request->getSession();
    $normalizedUri = Utils::plainUri($wkfUri) ?: $wkfUri;
    $scope = '';

    if ($normalizedUri !== '') {
      $scope = 'wkf_' . substr(sha1($normalizedUri), 0, 16);
    }

    if ($scope === '' && $session !== NULL) {
      $activeScope = $session->get('rep.wkf.scope.active');
      if (is_string($activeScope) && trim($activeScope) !== '') {
        $scope = trim($activeScope);
      }
    }

    if ($scope === '') {
      $scope = '__global__';
    }

    return $scope;
  }

  protected function getPacketStoreCollectionKey(): string {
    return 'rep.wkf.phase_packets.by_scope';
  }

  protected function getPacketPrivateBaseDir(): string {
    return 'private://wkf_phase_packets';
  }

  protected function getResponseStoreCollectionKey(): string {
    return 'rep.wkf.phase_responses.by_scope';
  }

  protected function getResponsePrivateBaseDir(): string {
    return 'private://wkf_phase_responses';
  }

  protected function getWorkingCopyStoreCollectionKey(): string {
    return 'rep.wkf.phase_working_copy.by_scope';
  }

  /**
   * Persist packet entry and return generated packet ID.
   */
  protected function persistPacket(Request $request, array $entry): string {
    $wkfUri = isset($entry['wkfUri']) && is_string($entry['wkfUri']) ? $entry['wkfUri'] : '';
    $scope = $this->resolveWkfScope($request, $wkfUri);
    $history = $this->getPacketHistoryForScope($request, $scope);

    $packetId = 'pkt_' . substr(sha1(($entry['phase'] ?? '') . '|' . ($entry['wkfUri'] ?? '') . '|' . microtime(TRUE)), 0, 12);
    $entry['packetId'] = $packetId;
    $entry['createdAt'] = date('Y-m-d H:i:s');
    $entry['createdByUid'] = (int) $this->currentUser()->id();

    $packetText = isset($entry['packetText']) && is_string($entry['packetText'])
      ? $entry['packetText']
      : '';
    $packetUri = $this->persistPacketText($scope, $packetId, $packetText);
    unset($entry['packetText']);
    if ($packetUri !== '') {
      $entry['packetUri'] = $packetUri;
    }

    array_unshift($history, $entry);
    $maxEntries = 100;
    $removed = array_slice($history, $maxEntries);
    $history = array_slice($history, 0, $maxEntries);

    foreach ($removed as $oldEntry) {
      if (!is_array($oldEntry)) {
        continue;
      }
      $this->deletePacketTextForEntry($oldEntry);
    }

    $this->setPacketHistoryForScope($scope, $history);

    return $packetId;
  }

  /**
   * Retrieve history list for scope.
   */
  protected function getPacketHistoryForScope(Request $request, string $scope): array {
    $store = \Drupal::keyValue($this->getPacketStoreCollectionKey());
    $history = $store->get($scope, []);
    if (is_array($history) && !empty($history)) {
      return $history;
    }

    // Backward compatibility: migrate legacy in-session history if present.
    $session = $request->getSession();
    if ($session === NULL) {
      return [];
    }

    $legacyStore = $session->get($this->getPacketStoreCollectionKey(), []);
    if (!is_array($legacyStore) || !isset($legacyStore[$scope]) || !is_array($legacyStore[$scope])) {
      return [];
    }

    $legacyHistory = $legacyStore[$scope];
    $migrated = [];
    foreach ($legacyHistory as $legacyEntry) {
      if (!is_array($legacyEntry)) {
        continue;
      }
      $entry = $legacyEntry;
      $packetId = isset($entry['packetId']) && is_string($entry['packetId']) && trim($entry['packetId']) !== ''
        ? trim($entry['packetId'])
        : ('pkt_' . substr(sha1(microtime(TRUE) . '|' . rand()), 0, 12));
      $entry['packetId'] = $packetId;

      if (!isset($entry['packetUri']) || !is_string($entry['packetUri']) || trim($entry['packetUri']) === '') {
        $packetText = isset($entry['packetText']) && is_string($entry['packetText'])
          ? $entry['packetText']
          : '';
        $packetUri = $this->persistPacketText($scope, $packetId, $packetText);
        if ($packetUri !== '') {
          $entry['packetUri'] = $packetUri;
        }
      }
      unset($entry['packetText']);
      $migrated[] = $entry;
    }

    if (!empty($migrated)) {
      $store->set($scope, array_slice($migrated, 0, 100));
      return array_slice($migrated, 0, 100);
    }

    return [];
  }

  /**
   * Persist history metadata list for a scope.
   */
  protected function setPacketHistoryForScope(string $scope, array $history): void {
    $store = \Drupal::keyValue($this->getPacketStoreCollectionKey());
    $store->set($scope, array_values($history));
  }

  /**
   * Remove all history metadata for a scope.
   */
  protected function clearPacketHistoryForScope(string $scope): void {
    $store = \Drupal::keyValue($this->getPacketStoreCollectionKey());
    $store->delete($scope);
  }

  /**
   * Persist packet text under private:// and return URI when successful.
   */
  protected function persistPacketText(string $scope, string $packetId, string $packetText): string {
    if ($packetText === '') {
      return '';
    }

    $safeScope = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $scope);
    $dir = rtrim($this->getPacketPrivateBaseDir(), '/') . '/' . $safeScope;
    $fileSystem = \Drupal::service('file_system');
    $prepared = $fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    if (!$prepared) {
      return '';
    }

    $uri = $dir . '/' . $packetId . '.txt';
    $bytes = @file_put_contents($uri, $packetText);
    if ($bytes === FALSE) {
      return '';
    }

    return $uri;
  }

  /**
   * Add packet text to an entry using stored private URI.
   */
  protected function hydratePacketEntry(array $entry): array {
    if (isset($entry['packetText']) && is_string($entry['packetText']) && $entry['packetText'] !== '') {
      return $entry;
    }

    if (!isset($entry['packetUri']) || !is_string($entry['packetUri']) || trim($entry['packetUri']) === '') {
      $entry['packetText'] = '';
      return $entry;
    }

    $uri = trim($entry['packetUri']);
    $content = @file_get_contents($uri);
    $entry['packetText'] = is_string($content) ? $content : '';

    return $entry;
  }

  /**
   * Delete stored packet text referenced by metadata entry.
   */
  protected function deletePacketTextForEntry(array $entry): void {
    if (!isset($entry['packetUri']) || !is_string($entry['packetUri'])) {
      return;
    }

    $uri = trim($entry['packetUri']);
    if ($uri === '') {
      return;
    }

    $fileSystem = \Drupal::service('file_system');
    try {
      $fileSystem->delete($uri);
    }
    catch (\Throwable $e) {
      // Best-effort cleanup.
    }
  }

  /**
   * Persist response artifact metadata + private payload and return response ID.
   */
  protected function persistResponseArtifact(Request $request, array $entry): string {
    $wkfUri = isset($entry['wkfUri']) && is_string($entry['wkfUri']) ? $entry['wkfUri'] : '';
    $scope = $this->resolveWkfScope($request, $wkfUri);
    $history = $this->getResponseHistoryForScope($request, $scope);

    $responseId = 'rsp_' . substr(sha1(($entry['phase'] ?? '') . '|' . ($entry['wkfUri'] ?? '') . '|' . microtime(TRUE)), 0, 12);
    $entry['responseId'] = $responseId;
    $entry['createdAt'] = date('Y-m-d H:i:s');
    $entry['createdByUid'] = (int) $this->currentUser()->id();

    $responseText = isset($entry['responseText']) && is_string($entry['responseText'])
      ? $entry['responseText']
      : '';
    $responseUri = $this->persistResponseText($scope, $responseId, $responseText);
    unset($entry['responseText']);
    if ($responseUri !== '') {
      $entry['responseUri'] = $responseUri;
    }

    array_unshift($history, $entry);
    $maxEntries = 100;
    $removed = array_slice($history, $maxEntries);
    $history = array_slice($history, 0, $maxEntries);

    foreach ($removed as $oldEntry) {
      if (!is_array($oldEntry)) {
        continue;
      }
      $this->deleteResponseTextForEntry($oldEntry);
    }

    $this->setResponseHistoryForScope($scope, $history);

    return $responseId;
  }

  /**
   * Retrieve response history metadata list for scope.
   */
  protected function getResponseHistoryForScope(Request $request, string $scope): array {
    $store = \Drupal::keyValue($this->getResponseStoreCollectionKey());
    $history = $store->get($scope, []);
    return is_array($history) ? $history : [];
  }

  protected function setResponseHistoryForScope(string $scope, array $history): void {
    $store = \Drupal::keyValue($this->getResponseStoreCollectionKey());
    $store->set($scope, array_values($history));
  }

  protected function clearResponseHistoryForScope(string $scope): void {
    $store = \Drupal::keyValue($this->getResponseStoreCollectionKey());
    $store->delete($scope);
  }

  protected function persistResponseText(string $scope, string $responseId, string $responseText): string {
    if ($responseText === '') {
      return '';
    }

    $safeScope = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $scope);
    $dir = rtrim($this->getResponsePrivateBaseDir(), '/') . '/' . $safeScope;
    $fileSystem = \Drupal::service('file_system');
    $prepared = $fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    if (!$prepared) {
      return '';
    }

    $uri = $dir . '/' . $responseId . '.txt';
    $bytes = @file_put_contents($uri, $responseText);
    if ($bytes === FALSE) {
      return '';
    }

    return $uri;
  }

  protected function hydrateResponseEntry(array $entry): array {
    if (isset($entry['responseText']) && is_string($entry['responseText']) && $entry['responseText'] !== '') {
      return $entry;
    }

    if (!isset($entry['responseUri']) || !is_string($entry['responseUri']) || trim($entry['responseUri']) === '') {
      $entry['responseText'] = '';
      return $entry;
    }

    $uri = trim($entry['responseUri']);
    $content = @file_get_contents($uri);
    $entry['responseText'] = is_string($content) ? $content : '';

    return $entry;
  }

  protected function deleteResponseTextForEntry(array $entry): void {
    if (!isset($entry['responseUri']) || !is_string($entry['responseUri'])) {
      return;
    }

    $uri = trim($entry['responseUri']);
    if ($uri === '') {
      return;
    }

    $fileSystem = \Drupal::service('file_system');
    try {
      $fileSystem->delete($uri);
    }
    catch (\Throwable $e) {
      // Best-effort cleanup.
    }
  }

  protected function setWorkingCopyForScope(string $scope, array $state): void {
    $store = \Drupal::keyValue($this->getWorkingCopyStoreCollectionKey());
    $payload = [
      'wkfUri' => isset($state['wkfUri']) ? (string) $state['wkfUri'] : '',
      'wkfContent' => isset($state['wkfContent']) ? (string) $state['wkfContent'] : '',
      'phase' => isset($state['phase']) ? (int) $state['phase'] : 0,
      'targetStatus' => isset($state['targetStatus']) ? (string) $state['targetStatus'] : 'current',
      'updatedAt' => date('Y-m-d H:i:s'),
    ];
    $store->set($scope, $payload);
  }

  protected function getWorkingCopyForScope(string $scope): array {
    $store = \Drupal::keyValue($this->getWorkingCopyStoreCollectionKey());
    $state = $store->get($scope, []);
    return is_array($state) ? $state : [];
  }

  protected function validateLocalWkfAndUpdateScopedSession(Request $request, string $wkfUri, string $scope, string $wkfContent): array {
    $trimmed = trim($wkfContent);
    $requiredMarkers = ['Process', 'Task', 'hasTopTask'];
    $missing = [];
    foreach ($requiredMarkers as $marker) {
      if (stripos($trimmed, $marker) === FALSE) {
        $missing[] = $marker;
      }
    }

    $isValid = empty($missing) && strlen($trimmed) >= 200;
    $summary = $isValid
      ? 'Local fallback validation passed (structure markers present).'
      : ('Local fallback validation failed. Missing markers: ' . implode(', ', $missing));
    $rules = [];
    foreach ($missing as $marker) {
      $rules[] = [
        'ruleId' => 'LOCAL-MARKER',
        'message' => 'Missing expected marker: ' . $marker,
        'specSection' => 'Local fallback checks',
      ];
    }

    $validatedAt = date('Y-m-d H:i:s');
    $snapshotId = substr(sha1($wkfUri . '|' . $validatedAt . '|' . $summary . '|' . json_encode($rules)), 0, 12);
    $session = $request->getSession();
    if ($session !== NULL) {
      $panelStore = $session->get('rep.wkf.validation.panel.by_scope', []);
      if (!is_array($panelStore)) {
        $panelStore = [];
      }
      $panelStore[$scope] = [
        'valid' => $isValid,
        'summary' => $summary,
        'wkfUri' => $wkfUri,
        'rules' => $rules,
        'validatedAt' => $validatedAt,
        'snapshotId' => $snapshotId,
        'wkfCopyContent' => $wkfContent,
        'wkfCopyReason' => '',
      ];
      $session->set('rep.wkf.validation.panel.by_scope', $panelStore);

      $workflowStore = $session->get('rep.wkf.workflow.state.by_scope', []);
      if (!is_array($workflowStore)) {
        $workflowStore = [];
      }
      $workflowStore[$scope] = [
        'hasValidation' => TRUE,
        'lastValidationValid' => $isValid,
        'wkfUri' => $wkfUri,
        'snapshotId' => $snapshotId,
        'validatedAt' => $validatedAt,
      ];
      $session->set('rep.wkf.workflow.state.by_scope', $workflowStore);
      $session->set('rep.wkf.scope.active', $scope);
    }

    return [
      'valid' => $isValid,
      'summary' => $summary,
      'rulesCount' => count($rules),
      'snapshotId' => $snapshotId,
      'validatedAt' => $validatedAt,
      'mode' => 'local_fallback',
    ];
  }

  /**
   * Apply response text using the existing uploadTemplate pipeline.
   */
  protected function applyResponseTextToWkf(string $wkfUri, string $responseText, string $targetStatus, string &$message): bool {
    $message = '';
    $api = \Drupal::service('rep.api_connector');

    $candidateUris = [];
    $candidateUris[] = trim($wkfUri);
    $plain = Utils::plainUri($wkfUri) ?: $wkfUri;
    $candidateUris[] = trim($plain);
    $decoded = rawurldecode(trim($wkfUri));
    if ($decoded !== '') {
      $candidateUris[] = $decoded;
      $candidateUris[] = Utils::plainUri($decoded) ?: $decoded;
    }

    $candidateUris = array_values(array_filter(array_unique($candidateUris), function ($uri) {
      return is_string($uri) && trim($uri) !== '';
    }));

    $template = NULL;
    foreach ($candidateUris as $candidateUri) {
      $template = $api->parseObjectResponse($api->getUri($candidateUri), 'getUri');
      if (is_object($template)) {
        $wkfUri = $candidateUri;
        break;
      }
    }

    if ($template === NULL) {
      $apiError = method_exists($api, 'getErrorMessage') ? trim((string) $api->getErrorMessage()) : '';
      $message = 'Failed to retrieve WKF template from API. Tried: ' . implode(' | ', $candidateUris)
        . ($apiError !== '' ? (' | API detail: ' . $apiError) : '');
      return FALSE;
    }

    if (isset($template->uri) && is_string($template->uri) && trim($template->uri) !== '') {
      $template->uri = Utils::plainUri($template->uri) ?: $template->uri;
    }
    else {
      $template->uri = $wkfUri;
    }

    if ((!isset($template->hasDataFileUri) || !is_string($template->hasDataFileUri) || trim($template->hasDataFileUri) === '')
      && isset($template->hasDataFile) && is_object($template->hasDataFile)
      && isset($template->hasDataFile->uri) && is_string($template->hasDataFile->uri)
    ) {
      $template->hasDataFileUri = Utils::plainUri($template->hasDataFile->uri) ?: $template->hasDataFile->uri;
    }

    if (!isset($template->hasDataFileUri) || !is_string($template->hasDataFileUri) || trim($template->hasDataFileUri) === '') {
      $message = 'WKF template has no data file URI to upload content.';
      return FALSE;
    }

    $xlsxBinary = (new WkfDraftDownloadController())->buildXlsxFromWorkbookTsv($responseText);
    if ($xlsxBinary === '') {
      $message = 'Failed to convert updated WKF content into an XLSX workbook for ingestion.';
      return FALSE;
    }

    $fileMeta = $this->createManagedResponseUploadFile($wkfUri, $xlsxBinary);
    if (empty($fileMeta) || empty($fileMeta['id'])) {
      $message = 'Failed to create temporary WKF upload file from ChatGPT response.';
      return FALSE;
    }

    if (!isset($template->hasDataFile) || !is_object($template->hasDataFile)) {
      $template->hasDataFile = new \stdClass();
    }
    $template->hasDataFile->id = (int) $fileMeta['id'];
    $template->hasDataFile->filename = (string) $fileMeta['filename'];

    $status = ($targetStatus === 'draft') ? VSTOI::DRAFT : VSTOI::CURRENT;
    $uploadResponse = $api->uploadTemplate('wkf', $template, $status);
    $uploadStatus = $api->parseObjectResponse($uploadResponse, 'uploadTemplateStatus');
    if ($uploadStatus === NULL) {
      $apiError = method_exists($api, 'getErrorMessage') ? trim((string) $api->getErrorMessage()) : '';
      $message = $apiError !== ''
        ? ('Upload failed: ' . $apiError)
        : 'Upload failed while applying response to WKF.';
      return FALSE;
    }

    $message = 'Response applied and submitted for ingestion as ' . strtoupper($status) . '.';
    return TRUE;
  }

  protected function createManagedResponseUploadFile(string $wkfUri, string $xlsxBinary): array {
    $local = basename(parse_url($wkfUri, PHP_URL_PATH) ?: $wkfUri);
    if ($local === '') {
      $local = 'wkf';
    }
    if (stripos($local, 'WKF-') !== 0) {
      $local = 'WKF-' . preg_replace('/^WKF[-_]?/i', '', $local);
    }
    $filename = $local . '-chatgpt-' . date('YmdHis') . '.xlsx';

    $dir = 'private://wkf_phase_response_uploads';
    $fileSystem = \Drupal::service('file_system');
    $prepared = $fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    if (!$prepared) {
      return [];
    }

    $uri = $dir . '/' . $filename;
    $bytes = @file_put_contents($uri, $xlsxBinary);
    if ($bytes === FALSE) {
      return [];
    }

    $file = File::create([
      'uid' => (int) $this->currentUser()->id(),
      'filename' => $filename,
      'uri' => $uri,
      'status' => 0,
    ]);
    $file->save();

    return [
      'id' => (int) $file->id(),
      'filename' => $filename,
      'uri' => $uri,
    ];
  }

  protected function validateWkfAndUpdateScopedSession(Request $request, string $wkfUri, string $scope, string $wkfContent): ?array {
    $api = \Drupal::service('rep.api_connector');
    $raw = $api->validateWKF($wkfUri);
    $decoded = is_string($raw) ? json_decode($raw) : NULL;
    if (!is_object($decoded) || empty($decoded->isSuccessful)) {
      return NULL;
    }

    $body = $decoded->body ?? NULL;
    if (is_string($body)) {
      $tmp = json_decode($body);
      if (is_object($tmp)) {
        $body = $tmp;
      }
    }
    if (!is_object($body)) {
      return NULL;
    }

    $rulesRaw = [];
    if (isset($body->rules) && is_array($body->rules)) {
      $rulesRaw = $body->rules;
    }
    elseif (isset($body->violations) && is_array($body->violations)) {
      $rulesRaw = $body->violations;
    }

    $rules = [];
    foreach ($rulesRaw as $rule) {
      if (!is_object($rule) && !is_array($rule)) {
        continue;
      }
      $ruleObj = (object) $rule;
      $rules[] = [
        'ruleId' => isset($ruleObj->ruleId) ? trim((string) $ruleObj->ruleId) : '',
        'message' => isset($ruleObj->message) ? trim((string) $ruleObj->message) : '',
        'specSection' => isset($ruleObj->specSection) ? trim((string) $ruleObj->specSection) : '',
      ];
    }

    $summary = '';
    if (isset($body->summary) && is_string($body->summary)) {
      $summary = trim($body->summary);
    }
    if ($summary === '' && isset($body->message) && is_string($body->message)) {
      $summary = trim($body->message);
    }

    $isValid = FALSE;
    if (isset($body->valid)) {
      $isValid = !empty($body->valid);
    }
    elseif (isset($body->isValid)) {
      $isValid = !empty($body->isValid);
    }
    else {
      $isValid = empty($rules);
    }

    if ($summary === '') {
      $summary = $isValid ? 'WKF is valid.' : 'WKF validation failed.';
    }

    $validatedAt = date('Y-m-d H:i:s');
    $snapshotId = substr(sha1($wkfUri . '|' . $validatedAt . '|' . $summary . '|' . json_encode($rules)), 0, 12);

    $session = $request->getSession();
    if ($session !== NULL) {
      $panelStore = $session->get('rep.wkf.validation.panel.by_scope', []);
      if (!is_array($panelStore)) {
        $panelStore = [];
      }
      $panelStore[$scope] = [
        'valid' => $isValid,
        'summary' => $summary,
        'wkfUri' => $wkfUri,
        'rules' => $rules,
        'validatedAt' => $validatedAt,
        'snapshotId' => $snapshotId,
        'wkfCopyContent' => $wkfContent,
        'wkfCopyReason' => '',
      ];
      $session->set('rep.wkf.validation.panel.by_scope', $panelStore);

      $workflowStore = $session->get('rep.wkf.workflow.state.by_scope', []);
      if (!is_array($workflowStore)) {
        $workflowStore = [];
      }
      $workflowStore[$scope] = [
        'hasValidation' => TRUE,
        'lastValidationValid' => $isValid,
        'wkfUri' => $wkfUri,
        'snapshotId' => $snapshotId,
        'validatedAt' => $validatedAt,
      ];
      $session->set('rep.wkf.workflow.state.by_scope', $workflowStore);
      $session->set('rep.wkf.scope.active', $scope);
    }

    return [
      'valid' => $isValid,
      'summary' => $summary,
      'rulesCount' => count($rules),
      'snapshotId' => $snapshotId,
      'validatedAt' => $validatedAt,
    ];
  }

}
