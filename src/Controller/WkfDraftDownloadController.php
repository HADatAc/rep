<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\rep\Constant;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds and downloads a minimal ingestion-ready WKF xlsx for Phase I.
 */
class WkfDraftDownloadController extends ControllerBase {

  /**
   * Download generated WKF workbook.
   */
  public function downloadPhase1Wkf(): Response {
    $request = \Drupal::request();

    $wkf_name = trim((string) ($request->request->get('wkfName', $request->query->get('wkfName', ''))));
    $process_stem_uri = trim((string) ($request->request->get('processStemUri', $request->query->get('processStemUri', ''))));
    $process_stem_label = trim((string) ($request->request->get('processStemLabel', $request->query->get('processStemLabel', ''))));
    $source_document_content_override = trim((string) ($request->request->get('sourceDocumentContent', '')));
    $source_document = $request->files->get('sourceDocument');

    if ($wkf_name === '' || $process_stem_uri === '') {
      return new Response('Missing required parameters: wkfName and processStemUri.', 400);
    }

    if (!$source_document || !method_exists($source_document, 'isValid') || !$source_document->isValid()) {
      return new Response('Missing required upload: sourceDocument.', 400);
    }

    $safe_id = $this->slugifyWkfId($wkf_name);
    $safe_label = $process_stem_label !== '' ? $process_stem_label : 'Selected Clinical Process';
    $base_uri = 'https://pmsr.net/ont/' . $safe_id;

    $rows = $this->buildWorkbookRows($wkf_name, $safe_id, $base_uri, $process_stem_uri, $safe_label);
    $xlsx_binary = $this->buildXlsxBinary($rows);
    $phase1WkfTableTsv = $this->buildWorkbookTsvPayload($rows);

    if ($xlsx_binary === '') {
      return new Response('Failed to generate WKF workbook.', 500);
    }

    $filename = $safe_id . '.xlsx';
    $sourceDocumentName = '';
    if (is_object($source_document) && method_exists($source_document, 'getClientOriginalName')) {
      $sourceDocumentName = trim((string) $source_document->getClientOriginalName());
    }
    if ($sourceDocumentName === '') {
      $sourceDocumentName = 'unknown';
    }

    $sourceDocumentContext = 'Supporting document filename: ' . $sourceDocumentName;
    $sourceDocumentContent = '';
    if (!$this->isSourceDocumentMetadataOnly($source_document_content_override)) {
      $sourceDocumentContent = $source_document_content_override;
    }
    if ($sourceDocumentContent === '') {
      $sourceDocumentContent = $this->extractSourceDocumentContent($source_document);
    }
    if ($sourceDocumentContent === '') {
      return new Response(
        'Could not extract textual content from sourceDocument. Please upload a text-readable document (for PDF: OCR/text layer required).',
        400
      );
    }
    $phase1CoreContext = $this->buildPhase1CoreContext($wkf_name, $process_stem_uri, $safe_label, $filename, $base_uri . '/PROC/0001');

    $uploadOutcome = $this->createAndSubmitWkfTemplate(
      $wkf_name,
      $filename,
      $xlsx_binary,
      $process_stem_uri,
      $safe_label,
      $sourceDocumentContext,
      $phase1CoreContext,
      $sourceDocumentContent,
      $phase1WkfTableTsv
    );

    $response = new Response($xlsx_binary, 200, [
      'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
      'Pragma' => 'no-cache',
      'X-WKF-Upload-Status' => !empty($uploadOutcome['ok']) ? 'ok' : 'error',
      'X-WKF-Upload-Message' => $this->sanitizeHeaderValue((string) ($uploadOutcome['message'] ?? '')),
      'X-WKF-Uri' => $this->sanitizeHeaderValue((string) ($uploadOutcome['wkfUri'] ?? '')),
    ]);

    return $response;
  }

  /**
   * Creates a WKF MT/DataFile and submits it for DRAFT ingestion.
   */
  protected function createAndSubmitWkfTemplate(string $wkfName, string $filename, string $xlsxBinary, string $processStemUri, string $processStemLabel, string $sourceDocumentContext = '', string $phase1CoreContext = '', string $sourceDocumentContent = '', string $phase1WkfTableTsv = ''): array {
    if ($xlsxBinary === '') {
      return ['ok' => false, 'message' => 'Generated workbook is empty.'];
    }

    try {
      $fileSystem = \Drupal::service('file_system');
      $directory = 'private://wkf_phase1_generated';
      $prepared = $fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      if (!$prepared) {
        return ['ok' => false, 'message' => 'Could not prepare WKF generated directory.'];
      }

      $managedFileName = date('YmdHis') . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
      $managedFileName = is_string($managedFileName) && $managedFileName !== '' ? $managedFileName : ('wkf-' . date('YmdHis') . '.xlsx');
      $fileUri = $directory . '/' . $managedFileName;
      $savedUri = $fileSystem->saveData($xlsxBinary, $fileUri, FileSystemInterface::EXISTS_RENAME);
      if ($savedUri === FALSE) {
        return ['ok' => false, 'message' => 'Could not persist generated WKF file.'];
      }

      $ownerId = (int) $this->currentUser()->id();
      $fileEntity = File::create([
        'uri' => $savedUri,
        'filename' => basename((string) $savedUri),
        'status' => FileInterface::STATUS_PERMANENT,
        'uid' => $ownerId,
      ]);
      $fileEntity->save();
      $fileId = (int) $fileEntity->id();

      $api = \Drupal::service('rep.api_connector');
      $managerEmail = (string) $this->currentUser()->getEmail();

      $newDataFileUri = Utils::uriGen('datafile');
      if (!is_string($newDataFileUri) || trim($newDataFileUri) === '') {
        return ['ok' => false, 'message' => 'Could not generate DataFile URI.'];
      }

      $newMTUri = str_replace('DFL', Utils::elementPrefix('wkf'), $newDataFileUri);
      if (!is_string($newMTUri) || trim($newMTUri) === '') {
        return ['ok' => false, 'message' => 'Could not generate WKF URI.'];
      }

      $sourceComment = 'Phase I generated draft WKF for process stem ' . $processStemLabel . ' (' . $processStemUri . ').';
      $datafileJson = json_encode([
        'uri' => $newDataFileUri,
        'typeUri' => HASCO::DATAFILE,
        'hascoTypeUri' => HASCO::DATAFILE,
        'label' => $wkfName,
        'filename' => basename((string) $savedUri),
        'fileStatus' => Constant::FILE_STATUS_UNPROCESSED,
        'hasSIRManagerEmail' => $managerEmail,
        'id' => $fileId,
      ]);

      $mtJson = json_encode([
        'uri' => $newMTUri,
        'typeUri' => HASCO::WKF,
        'hascoTypeUri' => HASCO::WKF,
        'label' => $wkfName,
        'hasDataFileUri' => $newDataFileUri,
        'hasVersion' => '1',
        'comment' => $sourceComment,
        'hasSIRManagerEmail' => $managerEmail,
      ]);

      $dfAdded = $api->parseObjectResponse($api->datafileAdd((string) $datafileJson), 'datafileAdd');
      if ($dfAdded === NULL) {
        $detail = method_exists($api, 'getErrorMessage') ? trim((string) $api->getErrorMessage()) : '';
        return ['ok' => false, 'message' => $detail !== '' ? $detail : 'Failed to add DataFile for generated WKF.'];
      }

      $mtAdded = $api->parseObjectResponse($api->elementAdd('wkf', (string) $mtJson), 'elementAdd');
      if ($mtAdded === NULL) {
        $detail = method_exists($api, 'getErrorMessage') ? trim((string) $api->getErrorMessage()) : '';
        return ['ok' => false, 'message' => $detail !== '' ? $detail : 'Failed to add WKF metadata template.'];
      }

      $this->persistPhase1PacketContext($newMTUri, [
        'wkfUri' => $newMTUri,
        'wkfName' => $wkfName,
        'processStemUri' => $processStemUri,
        'processStemLabel' => $processStemLabel,
        'sourceDocumentContext' => $sourceDocumentContext,
        'sourceDocumentContent' => $sourceDocumentContent,
        'phase1CoreContext' => $phase1CoreContext,
        'phase1WkfTableTsv' => $phase1WkfTableTsv,
        'savedAt' => date('c'),
      ]);

      return [
        'ok' => true,
        'message' => 'Draft WKF uploaded to PMSR and ready for later ingestion.',
        'wkfUri' => $newMTUri,
      ];
    }
    catch (\Throwable $t) {
      \Drupal::logger('rep')->error('Phase I WKF create/submit failed: @message', ['@message' => $t->getMessage()]);
      return ['ok' => false, 'message' => 'WKF download succeeded, but PMSR upload failed: ' . $t->getMessage()];
    }
  }

  /**
   * Keep custom header values ASCII-safe and short.
   */
  protected function sanitizeHeaderValue(string $value): string {
    $value = preg_replace('/[\r\n]+/', ' ', $value) ?? '';
    $value = trim($value);
    if ($value === '') {
      return 'n/a';
    }
    if (strlen($value) > 240) {
      return substr($value, 0, 240);
    }
    return $value;
  }

  /**
   * Build a stable Phase I context block for later Phase II-V packet generation.
   */
  protected function buildPhase1CoreContext(string $wkfName, string $processStemUri, string $processStemLabel, string $generatedFileName, string $processUri): string {
    $lines = [
      'Phase I WKF generated file: ' . $generatedFileName,
      'Core WKF Name: ' . $wkfName,
      'Clinical Process: ' . $processStemLabel,
      'Clinical Process URI: ' . $processStemUri,
      'Phase I Process URI: ' . $processUri,
    ];
    return implode("\n", $lines);
  }

  /**
   * Persist Phase I packet context per WKF URI for long-lived Phase II access.
   */
  protected function persistPhase1PacketContext(string $wkfUri, array $context): void {
    $normalizedUri = Utils::plainUri($wkfUri) ?: trim($wkfUri);
    if ($normalizedUri === '') {
      return;
    }

    $store = \Drupal::keyValue('rep.wkf.phase1.context.by_uri');
    $store->set($normalizedUri, $context);
  }

  /**
   * Extract best-effort text payload from uploaded source document.
   */
  protected function extractSourceDocumentContent($uploadedFile): string {
    if (!is_object($uploadedFile) || !method_exists($uploadedFile, 'getRealPath')) {
      return '';
    }

    $path = (string) $uploadedFile->getRealPath();
    if ($path === '' || !is_readable($path)) {
      return '';
    }

    $extension = '';
    if (method_exists($uploadedFile, 'getClientOriginalExtension')) {
      $extension = strtolower(trim((string) $uploadedFile->getClientOriginalExtension()));
    }
    if ($extension === '') {
      $guessed = pathinfo($path, PATHINFO_EXTENSION);
      $extension = is_string($guessed) ? strtolower(trim($guessed)) : '';
    }

    $raw = '';

    if ($extension === 'docx') {
      $raw = $this->extractTextFromDocx($path);
    }
    elseif (in_array($extension, ['pdf', 'doc', 'rtf'], TRUE)) {
      $raw = $this->extractTextViaTextUtil($path);
      if ($raw === '' && $extension === 'pdf') {
        $raw = $this->extractTextViaPdfToText($path);
      }
    }

    if ($raw === '') {
      $rawBytes = @file_get_contents($path);
      if (!is_string($rawBytes) || $rawBytes === '') {
        return '';
      }

      $looksBinary = strpos($rawBytes, "\0") !== FALSE;
      if ($looksBinary) {
        return '';
      }

      // For non-text document formats, never accept raw bytes as extracted text.
      if (in_array($extension, ['pdf', 'doc', 'docx', 'rtf'], TRUE)) {
        return '';
      }

      $raw = $rawBytes;
    }

    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $raw = trim($raw);
    if ($raw === '') {
      return '';
    }

    if ($this->looksLikeDocumentBinaryPayload($raw)) {
      return '';
    }

    $maxChars = 180000;
    if (strlen($raw) > $maxChars) {
      $raw = substr($raw, 0, $maxChars) . "\n\n[TRUNCATED: source document exceeded size limit]";
    }

    return $raw;
  }

  /**
   * Detects metadata-only placeholders from client payloads.
   */
  protected function isSourceDocumentMetadataOnly(string $value): bool {
    $normalized = trim($value);
    if ($normalized === '') {
      return true;
    }

    if (strpos($normalized, "\n") !== false) {
      return false;
    }

    if (preg_match('/^(supporting\s+document|source\s+document)\s+(filename|file\s+name|name|title|id|identifier|uri|path)\s*:/i', $normalized) === 1) {
      return true;
    }

    if (preg_match('/^[\w .\-\/\\:]+\.(pdf|docx?|rtf|txt|md)$/i', $normalized) === 1) {
      return true;
    }

    return false;
  }

  /**
   * Extracts text from DOCX by reading WordprocessingML XML parts.
   */
  protected function extractTextFromDocx(string $path): string {
    if (!class_exists('ZipArchive')) {
      return '';
    }

    $zip = new \ZipArchive();
    if ($zip->open($path) !== TRUE) {
      return '';
    }

    $xmlParts = [
      'word/document.xml',
      'word/header1.xml',
      'word/header2.xml',
      'word/header3.xml',
      'word/footer1.xml',
      'word/footer2.xml',
      'word/footer3.xml',
      'word/footnotes.xml',
      'word/endnotes.xml',
    ];

    $chunks = [];
    foreach ($xmlParts as $part) {
      $xml = $zip->getFromName($part);
      if (!is_string($xml) || $xml === '') {
        continue;
      }

      $text = preg_replace('/<w:p[^>]*>/', "\n", $xml) ?? $xml;
      $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
      $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
      $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
      $text = trim((string) $text);
      if ($text !== '') {
        $chunks[] = $text;
      }
    }

    $zip->close();

    return trim(implode("\n\n", $chunks));
  }

  /**
   * Extracts text via macOS textutil when available.
   */
  protected function extractTextViaTextUtil(string $path): string {
    $binary = '/usr/bin/textutil';
    if (!is_executable($binary)) {
      return '';
    }

    $cmd = escapeshellarg($binary) . ' -convert txt -stdout ' . escapeshellarg($path) . ' 2>/dev/null';
    $output = shell_exec($cmd);
    return is_string($output) ? trim($output) : '';
  }

  /**
   * Extracts PDF text via pdftotext when available.
   */
  protected function extractTextViaPdfToText(string $path): string {
    $binary = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
    if ($binary === '') {
      return '';
    }

    $cmd = escapeshellarg($binary) . ' -layout -nopgbrk ' . escapeshellarg($path) . ' - 2>/dev/null';
    $output = shell_exec($cmd);
    return is_string($output) ? trim($output) : '';
  }

  /**
   * Detects raw document payloads (e.g., PDF object streams) that are not usable text.
   */
  protected function looksLikeDocumentBinaryPayload(string $value): bool {
    $sample = substr($value, 0, 5000);
    if ($sample === '') {
      return true;
    }

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

    // Heuristic: very low whitespace + many non-word symbols indicates encoded/binary payload.
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
   * Build a TSV payload including all WKF workbook sheets.
   */
  protected function buildWorkbookTsvPayload(array $sheets): string {
    $parts = [];

    foreach ($sheets as $sheetName => $rows) {
      if (!is_array($rows) || empty($rows)) {
        continue;
      }

      $parts[] = '### SHEET: ' . $sheetName;
      foreach ($rows as $row) {
        if (!is_array($row)) {
          continue;
        }
        $cells = [];
        foreach ($row as $cell) {
          $value = is_scalar($cell) ? (string) $cell : '';
          $value = str_replace(["\t", "\r", "\n"], [' ', ' ', ' '], $value);
          $cells[] = trim($value);
        }
        $parts[] = implode("\t", $cells);
      }
      $parts[] = '';
    }

    $payload = trim(implode("\n", $parts));
    if ($payload === '') {
      return '';
    }

    $maxChars = 220000;
    if (strlen($payload) > $maxChars) {
      $payload = substr($payload, 0, $maxChars) . "\n\n[TRUNCATED: Phase I WKF TSV exceeded size limit]";
    }

    return $payload;
  }

  /**
   * Build row sets for all mandatory WKF sheets.
   */
  protected function buildWorkbookRows(string $wkf_name, string $wkf_id, string $base_uri, string $process_stem_uri, string $process_stem_label): array {
    $top_task_uri = $base_uri . '/TSK/0001';
    $procedure_label = trim($process_stem_label) !== '' ? trim($process_stem_label) : $wkf_name;
    $top_task_label = 'Performing a ' . $procedure_label;

    $info_sheet = [
      ['Attribute', 'Value'],
      ['hasDependencies', '#Namespaces'],
      ['ProcessStems', '#ProcessStems'],
      ['Processes', '#Processes'],
      ['Tasks', '#Tasks'],
      ['RequiredInstruments', '#RequiredInstruments'],
      ['hasVersion', '1'],
    ];

    $namespaces = [
      ['prefix', 'namespace'],
      ['hasco', 'http://hadatac.org/ont/hasco#'],
      ['vstoi', 'http://hadatac.org/ont/vstoi#'],
      ['prov', 'http://www.w3.org/ns/prov#'],
      ['rdfs', 'http://www.w3.org/2000/01/rdf-schema#'],
      ['rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#'],
      ['owl', 'http://www.w3.org/2002/07/owl#'],
      ['xsd', 'http://www.w3.org/2001/XMLSchema#'],
      ['pmsr', 'https://pmsr.net/ont/'],
    ];

    $process_stems = [
      [
        'hasURI',
        'rdf:type',
        'hasco:hascoType',
        'rdfs:label',
        'rdfs:comment',
        'vstoi:hasStatus',
        'vstoi:hasContent',
        'vstoi:hasLanguage',
        'vstoi:hasVersion',
        'prov:wasDerivedFrom',
        'prov:wasGeneratedBy',
        'vstoi:hasReviewNote',
        'vstoi:hasSIRManagerEmail',
        'vstoi:hasEditorEmail',
        'hasco:hasImage',
        'hasco:hasWebDocument',
      ],
      [
        $process_stem_uri,
        'vstoi:ProcessStem',
        'vstoi:ClinicalWorkflow',
        $process_stem_label,
        'Selected clinical process stem used as parent for this WKF process.',
        'vstoi:Current',
        '',
        'en',
        '1',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
      ],
    ];

    $processes = [
      [
        'hasURI',
        'rdf:type',
        'hasco:hascoType',
        'rdfs:label',
        'rdfs:comment',
        'vstoi:hasStatus',
        'vstoi:hasLanguage',
        'vstoi:hasVersion',
        'prov:wasDerivedFrom',
        'vstoi:hasReviewNote',
        'vstoi:hasSIRManagerEmail',
        'vstoi:hasEditorEmail',
        'vstoi:hasTopTask',
        'hasco:hasImage',
        'hasco:hasWebDocument',
      ],
      [
        $base_uri . '/PROC/0001',
        'vstoi:Process',
        'vstoi:ClinicalWorkflow',
        $wkf_name,
        'Phase I core WKF process generated from REP panel.',
        'vstoi:Current',
        'en',
        '1',
        $process_stem_uri,
        '',
        '',
        '',
        $top_task_uri,
        '',
        '',
      ],
    ];

    $tasks = [
      [
        'hasURI',
        'rdf:type',
        'hasco:hascoType',
        'rdfs:label',
        'rdfs:comment',
        'vstoi:hasStatus',
        'vstoi:hasLanguage',
        'vstoi:hasVersion',
        'prov:wasDerivedFrom',
        'vstoi:hasReviewNote',
        'vstoi:hasSIRManagerEmail',
        'vstoi:hasEditorEmail',
        'vstoi:hasSupertask',
        'vstoi:hasSubtask',
        'vstoi:hasTemporalDependency',
        'vstoi:hasRequiredInstrument',
        'hasco:hasImage',
        'hasco:hasWebDocument',
        'vstoi:hasIterationConstraint',
      ],
      [
        $top_task_uri,
        'vstoi:Task',
        'vstoi:Task',
        $top_task_label,
        'Top-level task derived from selected clinical procedure for Phase I.',
        'vstoi:Current',
        'en',
        '1',
        $process_stem_uri,
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
      ],
    ];

    $required_instruments = [
      ['hasURI', 'rdf:type', 'rdfs:label', 'rdfs:comment', 'vstoi:requiresInstrument', 'vstoi:isRequiredBy'],
    ];

    return [
      'InfoSheet' => $info_sheet,
      'Namespaces' => $namespaces,
      'ProcessStems' => $process_stems,
      'Processes' => $processes,
      'Tasks' => $tasks,
      'RequiredInstruments' => $required_instruments,
    ];
  }

  /**
   * Convert workbook rows into an XLSX zip binary.
   */
  protected function buildXlsxBinary(array $sheets): string {
    $sheet_names = array_keys($sheets);
    $shared_strings = $this->collectSharedStrings($sheets);

    $temp_file = tempnam(sys_get_temp_dir(), 'wkf_phase1_');
    if ($temp_file === FALSE) {
      return '';
    }

    $zip = new \ZipArchive();
    if ($zip->open($temp_file, \ZipArchive::OVERWRITE) !== TRUE) {
      return '';
    }

    $zip->addFromString('[Content_Types].xml', $this->buildContentTypesXml(count($sheet_names)));
    $zip->addFromString('_rels/.rels', $this->buildRootRelsXml());
    $zip->addFromString('xl/workbook.xml', $this->buildWorkbookXml($sheet_names));
    $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRelsXml(count($sheet_names)));
    $zip->addFromString('xl/sharedStrings.xml', $this->buildSharedStringsXml($shared_strings));

    $index = 1;
    foreach ($sheet_names as $name) {
      $xml = $this->buildWorksheetXml($sheets[$name], $shared_strings);
      $zip->addFromString('xl/worksheets/sheet' . $index . '.xml', $xml);
      $index++;
    }

    $zip->close();

    $binary = (string) file_get_contents($temp_file);
    @unlink($temp_file);

    return $binary;
  }

  /**
   * Build normalized WKF id token.
   */
  protected function slugifyWkfId(string $wkf_name): string {
    $id = strtoupper(trim($wkf_name));
    $id = preg_replace('/[^A-Z0-9]+/', '_', $id) ?? '';
    $id = trim($id, '_');
    if ($id === '') {
      $id = 'WKF_GENERATED';
    }
    if (str_starts_with($id, 'WKF_')) {
      $id = 'WKF-' . substr($id, 4);
    }
    if (!str_starts_with($id, 'WKF-')) {
      $id = 'WKF-' . $id;
    }
    return $id;
  }

  /**
   * Shared string table map.
   */
  protected function collectSharedStrings(array $sheets): array {
    $strings = [];
    foreach ($sheets as $rows) {
      foreach ($rows as $row) {
        foreach ($row as $cell) {
          $value = (string) $cell;
          if ($value === '') {
            continue;
          }
          if (!isset($strings[$value])) {
            $strings[$value] = count($strings);
          }
        }
      }
    }
    return $strings;
  }

  protected function buildContentTypesXml(int $sheet_count): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
    $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    for ($i = 1; $i <= $sheet_count; $i++) {
      $xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
    $xml .= '</Types>';
    return $xml;
  }

  protected function buildRootRelsXml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>';
  }

  protected function buildWorkbookXml(array $sheet_names): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    foreach ($sheet_names as $index => $name) {
      $sheet_id = $index + 1;
      $xml .= '<sheet name="' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1) . '" sheetId="' . $sheet_id . '" r:id="rId' . $sheet_id . '"/>';
    }
    $xml .= '</sheets></workbook>';
    return $xml;
  }

  protected function buildWorkbookRelsXml(int $sheet_count): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    for ($i = 1; $i <= $sheet_count; $i++) {
      $xml .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $xml .= '<Relationship Id="rId' . ($sheet_count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $xml .= '</Relationships>';
    return $xml;
  }

  protected function buildSharedStringsXml(array $shared_strings): string {
    $count = count($shared_strings);
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';
    foreach ($shared_strings as $text => $index) {
      unset($index);
      $xml .= '<si><t>' . htmlspecialchars((string) $text, ENT_QUOTES | ENT_XML1) . '</t></si>';
    }
    $xml .= '</sst>';
    return $xml;
  }

  protected function buildWorksheetXml(array $rows, array $shared_strings): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

    foreach ($rows as $row_index => $row_values) {
      $r = $row_index + 1;
      $xml .= '<row r="' . $r . '">';
      foreach ($row_values as $col_index => $value) {
        $text = (string) $value;
        if ($text === '') {
          continue;
        }
        $cell_ref = $this->columnName($col_index + 1) . $r;
        $si = $shared_strings[$text] ?? NULL;
        if ($si === NULL) {
          continue;
        }
        $xml .= '<c r="' . $cell_ref . '" t="s"><v>' . $si . '</v></c>';
      }
      $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
  }

  protected function columnName(int $index): string {
    $name = '';
    while ($index > 0) {
      $mod = ($index - 1) % 26;
      $name = chr(65 + $mod) . $name;
      $index = (int) floor(($index - 1) / 26);
    }
    return $name;
  }

}
