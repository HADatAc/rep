<?php

namespace Drupal\rep\Controller;

use Drupal\Core\Controller\ControllerBase;
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

    $wkf_name = trim((string) $request->query->get('wkfName', ''));
    $process_stem_uri = trim((string) $request->query->get('processStemUri', ''));
    $process_stem_label = trim((string) $request->query->get('processStemLabel', ''));

    if ($wkf_name === '' || $process_stem_uri === '') {
      return new Response('Missing required parameters: wkfName and processStemUri.', 400);
    }

    $safe_id = $this->slugifyWkfId($wkf_name);
    $safe_label = $process_stem_label !== '' ? $process_stem_label : 'Selected Clinical Process';
    $base_uri = 'https://pmsr.net/ont/' . $safe_id;

    $rows = $this->buildWorkbookRows($wkf_name, $safe_id, $base_uri, $process_stem_uri, $safe_label);
    $xlsx_binary = $this->buildXlsxBinary($rows);

    if ($xlsx_binary === '') {
      return new Response('Failed to generate WKF workbook.', 500);
    }

    $filename = $safe_id . '.xlsx';
    $response = new Response($xlsx_binary, 200, [
      'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
      'Pragma' => 'no-cache',
    ]);

    return $response;
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
