<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\VSTOI;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds ChatGPT-ready packet payloads for WKF phases II-V.
 */
class WkfPhasePacketController extends ControllerBase {

  /**
   * Build packet text payload for a WKF phase.
   */
  public function buildPacket(int $phase, Request $request): JsonResponse {
    if ($phase < 2 || $phase > 5) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Only phases II to V are supported by this endpoint.',
      ], 400);
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
    if ($wkfContent === '') {
      $scope = $this->resolveWkfScope($request, $wkfUri);
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

    $promptText = $promptOverride !== ''
      ? $promptOverride
      : $this->getPhasePromptText($phase);
    $wkfSpecV3Text = $phase === 2 ? $this->getWkfSpecV3Text() : '';
    $phase1TasksSheetTsv = '';
    if ($phase === 2) {
      $phase1BaseWorkbook = '';
      if ($phase1WkfTableTsv !== '') {
        $phase1BaseWorkbook = $phase1WkfTableTsv;
      }
      elseif ($wkfContent !== '') {
        $phase1BaseWorkbook = $wkfContent;
      }
      $phase1TasksSheetTsv = $this->extractSheetBlockFromWorkbookTsv($phase1BaseWorkbook, 'Tasks');
      if ($phase1TasksSheetTsv === '') {
        $phase1TasksSheetTsv = $this->extractTasksSheetFromAnyTsv($phase1BaseWorkbook);
      }
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

    if ($validationMessages !== '') {
      $lines[] = 'OPTIONAL_VALIDATION_MESSAGES';
      $lines[] = 'BEGIN_VALIDATION_MESSAGES';
      $lines[] = $validationMessages;
      $lines[] = 'END_VALIDATION_MESSAGES';
      $lines[] = '';
    }

    $lines[] = 'RESPONSE CONTRACT';
    if ($phase === 2) {
      $lines[] = 'Using SOURCE_DOCUMENT_CONTENT, PHASE1_TASKS_SHEET_TSV, and the rules in WKF_SPEC_V3_CONTENT, generate the updated Phase II Tasks sheet TSV.';
      $lines[] = 'Return the actual Tasks TSV artifact, not a description and not a copy-link wrapper.';
      $lines[] = 'Return exactly these 3 sections in this order:';
      $lines[] = '1) OUTPUT_TASKS_SHEET_TSV';
      $lines[] = '2) OUTPUT_SUMMARY_JSON';
      $lines[] = '3) OUTPUT_ASSUMPTIONS';
      $lines[] = 'Section OUTPUT_TASKS_SHEET_TSV must contain only the Tasks header row followed by task rows (tab-separated).';
      $lines[] = 'Do NOT output a full WKF workbook, multiple sheets, JSON object, or .xlsx content.';
      $lines[] = 'Do NOT include InfoSheet, Namespaces, STD, ProcessStems, Processes, or RequiredInstruments in the response.';
      $lines[] = 'Preserve valid existing Phase I task rows and relationships unless changes are required by WKF_SPEC_V3_CONTENT rules or source evidence.';
      $lines[] = 'Do NOT repeat or summarize any packet sections (header, goal, prompt, inputs, or TSV blocks).';
      $lines[] = 'Copy/link packaging is handled by application logic, not by this generation response.';
      $lines[] = 'If you cannot comply exactly, return exactly one line: ABORT: Could not produce compliant Phase II Tasks TSV output.';
      $lines[] = 'No markdown fences and no extra prose.';
    }
    elseif ($phase === 5) {
      $lines[] = 'Do NOT show the Tasks TSV inline.';
      $lines[] = 'Return exactly one markdown link labeled Copy Simulation Tasks.';
      $lines[] = 'Link target format: data:text/plain;charset=utf-8,<URL-ENCODED-TSV>.';
      $lines[] = 'Encoded payload must contain ONLY Tasks TSV (header row + all task rows).';
      $lines[] = 'No markdown fences and no extra prose.';
    }
    elseif ($phase === 4) {
      $lines[] = 'Do NOT show the STD TSV inline.';
      $lines[] = 'Return exactly one markdown link labeled Copy Scenario.';
      $lines[] = 'Link target format: data:text/plain;charset=utf-8,<URL-ENCODED-TSV>.';
      $lines[] = 'Encoded payload must contain ONLY STD TSV (header row + all STD rows).';
      $lines[] = 'No markdown fences and no extra prose.';
    }
    elseif ($phase === 3) {
      $lines[] = 'Return ONLY raw Tasks TSV text (header row + all task rows).';
      $lines[] = 'Do NOT return markdown links, markdown fences, JSON, summaries, or extra prose.';
    }
    else {
      $lines[] = 'Return exactly 3 sections in this order:';
      $lines[] = '1) OUTPUT_WKF_TABLE_TSV';
      $lines[] = '2) OUTPUT_SUMMARY_JSON';
      $lines[] = '3) OUTPUT_ASSUMPTIONS';
      $lines[] = 'No extra prose outside those sections.';
    }

    $packetText = trim(implode("\n", $lines));
    $maxChars = 300000;
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
    if ($phase < 2 || $phase > 5) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Only phases II to V are supported by this endpoint.',
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

    // Fallback: keep a local working copy so users can continue Phase II-V
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
   * Replace a target WKF TSV sheet block with user-provided TSV content.
   */
  public function updateSheet(Request $request): JsonResponse {
    $payload = $this->decodePayload($request);

    $wkfUri = trim((string) ($payload['wkfUri'] ?? ''));
    $updateType = strtolower(trim((string) ($payload['updateType'] ?? '')));
    $tsvContent = trim((string) ($payload['tsvContent'] ?? ''));
    $phase = (int) ($payload['phase'] ?? 0);

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

    if ($tsvContent === '') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'TSV content is required.',
      ], 400);
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

    $sheetName = $updateType === 'scenario' ? 'STD' : 'Tasks';
    $updatedContent = $this->replaceSheetBlockInWorkbookTsv($baseContent, $sheetName, $tsvContent);

    $applyMessage = '';
    $applied = $this->applyResponseTextToWkf($wkfUri, $updatedContent, 'current', $applyMessage);
    $applyMode = 'ingestion';
    if (!$applied) {
      $this->setWorkingCopyForScope($scope, [
        'wkfUri' => $wkfUri,
        'wkfContent' => $updatedContent,
        'phase' => ($phase > 0 ? $phase : 2),
        'targetStatus' => 'current',
      ]);
      $applyMode = 'local_fallback';
      $applyMessage = 'Sheet update applied to local working copy only (WKF URI not resolvable in hascoapi yet).';
    }

    $validation = NULL;
    if ($updateType === 'task_model' || $updateType === 'scenario') {
      if ($applyMode === 'ingestion') {
        $validation = $this->validateWkfAndUpdateScopedSession($request, $wkfUri, $scope, $updatedContent);
      }
      else {
        $validation = $this->validateLocalWkfAndUpdateScopedSession($request, $wkfUri, $scope, $updatedContent);
      }

      if (!is_array($validation) || empty($validation['valid'])) {
        $summary = is_array($validation) && isset($validation['summary'])
          ? trim((string) $validation['summary'])
          : '';
        $updateLabel = $updateType === 'scenario' ? 'Scenario' : 'Task model';
        return new JsonResponse([
          'success' => FALSE,
          'sheetName' => $sheetName,
          'applyMode' => $applyMode,
          'applyMessage' => $applyMessage,
          'phase' => ($phase > 0 ? $phase : 2),
          'validation' => $validation,
          'error' => $summary !== ''
            ? ($updateLabel . ' update applied but validation failed: ' . $summary)
            : ($updateLabel . ' update applied but validation failed.'),
        ], 400);
      }
    }

    $nextPublicPhase = $phase > 0 ? $phase : 2;
    if ($updateType === 'task_model' && $nextPublicPhase === 2) {
      $nextPublicPhase = 3;
    }
    if ($updateType === 'scenario' && $nextPublicPhase === 3) {
      $nextPublicPhase = 4;
    }

    return new JsonResponse([
      'success' => TRUE,
      'sheetName' => $sheetName,
      'applyMode' => $applyMode,
      'applyMessage' => $applyMessage,
      'phase' => ($phase > 0 ? $phase : 2),
      'nextPublicPhase' => $nextPublicPhase,
      'validation' => $validation,
    ]);
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
      3 => 'Optional Activity - Task Model Verification',
      4 => 'Phase III - Properties Extraction',
      5 => 'Phase IV - Simulations Assignments',
    ];
    return $map[$phase] ?? ('Phase ' . $phase);
  }

  /**
   * Return phase-specific objective.
   */
  protected function getPhaseGoal(int $phase): string {
    $map = [
      2 => 'Generate the Tasks sheet TSV from the source document using WKF-SPEC-V3 rules.',
      3 => 'Optionally verify/correct task model issues while preserving valid content.',
      4 => 'Extract and encode detailed properties into the WKF without regressing earlier phases.',
      5 => 'Complete simulations assignments and ingestion-readiness details.',
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
      3 => 'PROMPT-WKF-PHASE3-TASK-MODEL-CORRECTION.md',
      4 => 'PROMPT-WKF-PHASE3-STD-TSV.md',
      5 => 'PROMPT-WKF-PHASE4-TASKS-USES-COMPONENT-TSV.md',
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
    $entry = $store->get($normalized, []);
    return is_array($entry) ? $entry : [];
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

    $fileMeta = $this->createManagedResponseUploadFile($wkfUri, $responseText);
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

  protected function createManagedResponseUploadFile(string $wkfUri, string $content): array {
    $local = basename(parse_url($wkfUri, PHP_URL_PATH) ?: $wkfUri);
    if ($local === '') {
      $local = 'wkf';
    }
    $filename = $local . '-chatgpt-' . date('YmdHis') . '.ttl';

    $dir = 'private://wkf_phase_response_uploads';
    $fileSystem = \Drupal::service('file_system');
    $prepared = $fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    if (!$prepared) {
      return [];
    }

    $uri = $dir . '/' . $filename;
    $bytes = @file_put_contents($uri, $content);
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
