<?php

namespace Drupal\Tests\rep\Unit;

use Drupal\rep\Controller\WkfPhasePacketController;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Integration-style endpoint tests for WKF phase packet contracts.
 *
 * Exercises buildPacket() with mocked request payloads and mocked backend
 * dependencies, validating packet text contracts end-to-end for phases 2/3/4.
 *
 * @group rep
 * @coversDefaultClass \Drupal\rep\Controller\WkfPhasePacketController
 */
class WkfPhasePacketEndpointIntegrationTest extends UnitTestCase {

  /**
   * Controller test double.
   *
   * @var \Drupal\Tests\rep\Unit\WkfPhasePacketControllerEndpointDouble
   */
  protected WkfPhasePacketControllerEndpointDouble $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->controller = new WkfPhasePacketControllerEndpointDouble();
  }

  /**
   * Ensures Phase II packet endpoint contract is complete and deterministic.
   *
   * @covers ::buildPacket
   */
  public function testPhase2PacketEndpointContract(): void {
    $payload = [
      'wkfUri' => 'https://pmsr.net/ont/WKF17874419111195412/PROC/PROC001',
      'sourceDocument' => 'Clinical source content for intubation process.',
      'phase1WkfTableTsv' => "hasURI\trdf:type\thasco:hascoType\tvstoi:hasSupertask\nhttps://pmsr.net/ont/TASK001\tvstoi:Task\tvstoi:Task\t",
      'wkfContent' => $this->controller->workbookFixture(),
      'validationMessages' => 'No blocking rule violations.',
    ];

    $response = $this->controller->buildPacket(2, $this->jsonRequest('/rep/wkf/phase/packet/2', $payload));
    $data = json_decode((string) $response->getContent(), TRUE);

    $this->assertTrue((bool) ($data['success'] ?? FALSE));
    $this->assertSame(2, (int) ($data['phase'] ?? 0));
    $packet = (string) ($data['packetText'] ?? '');

    $this->assertStringContainsString('RESPONSE CONTRACT', $packet);
    $this->assertStringContainsString('SUGGESTED_OUTPUT_FILENAME: WKF17874419111195412_Phase2_tasks.tsv', $packet);
    $this->assertStringContainsString('OUTPUT_TASKS_SHEET_TSV', $packet);
    $this->assertStringContainsString('OUTPUT_SUMMARY_JSON', $packet);
    $this->assertStringContainsString('OUTPUT_ASSUMPTIONS', $packet);
    $this->assertStringContainsString('SOURCE_DOCUMENT_CONTENT', $packet);
    $this->assertStringContainsString('PHASE1_TASKS_SHEET_TSV', $packet);
  }

  /**
   * Ensures Phase III packet endpoint contract is complete and deterministic.
   *
   * @covers ::buildPacket
   */
  public function testPhase3PacketEndpointContract(): void {
    $payload = [
      'wkfUri' => 'https://pmsr.net/ont/WKF17874419111195412/PROC/PROC001',
      'sourceDocument' => 'Clinical source content for scenario extraction.',
      'phase1WkfTableTsv' => "hasURI\trdf:type\thasco:hascoType\tvstoi:hasSupertask\nhttps://pmsr.net/ont/TASK001\tvstoi:Task\tvstoi:Task\t",
      'wkfContent' => $this->controller->workbookFixture(),
      'validationMessages' => 'No blocking rule violations.',
    ];

    $response = $this->controller->buildPacket(3, $this->jsonRequest('/rep/wkf/phase/packet/3', $payload));
    $data = json_decode((string) $response->getContent(), TRUE);

    $this->assertTrue((bool) ($data['success'] ?? FALSE));
    $this->assertSame(3, (int) ($data['phase'] ?? 0));
    $packet = (string) ($data['packetText'] ?? '');

    $this->assertStringContainsString('RESPONSE CONTRACT', $packet);
    $this->assertStringContainsString('SUGGESTED_OUTPUT_FILENAME: WKF17874419111195412_Phase3_std.tsv', $packet);
    $this->assertStringContainsString('CURRENT_STD_SHEET_TSV', $packet);
    $this->assertStringContainsString('Return ONLY the STD TSV file body', $packet);
    $this->assertStringContainsString('PHASE1_WKF_TABLE_TSV', $packet);
  }

  /**
   * Ensures Phase IV packet endpoint contract is complete and deterministic.
   *
   * @covers ::buildPacket
   */
  public function testPhase4PacketEndpointContract(): void {
    $payload = [
      'wkfUri' => 'https://pmsr.net/ont/WKF17874419111195412/PROC/PROC001',
      'sourceDocument' => 'Clinical source content for component assignment.',
      'phase1WkfTableTsv' => "hasURI\trdf:type\thasco:hascoType\tvstoi:hasSupertask\nhttps://pmsr.net/ont/TASK001\tvstoi:Task\tvstoi:Task\t",
      'wkfContent' => $this->controller->workbookFixture(),
    ];

    $response = $this->controller->buildPacket(4, $this->jsonRequest('/rep/wkf/phase/packet/4', $payload));
    $data = json_decode((string) $response->getContent(), TRUE);

    $this->assertTrue((bool) ($data['success'] ?? FALSE));
    $this->assertSame(4, (int) ($data['phase'] ?? 0));
    $packet = (string) ($data['packetText'] ?? '');

    $this->assertStringContainsString('RESPONSE CONTRACT', $packet);
    $this->assertStringContainsString('SUGGESTED_OUTPUT_FILENAME: WKF17874419111195412_Phase4_tasks.tsv', $packet);
    $this->assertStringContainsString('LATEST_TASKS_SHEET_TSV', $packet);
    $this->assertStringContainsString('INSTRUMENT_INSTANCES_WITH_COMPONENT_INSTANCES', $packet);
    $this->assertStringContainsString('vstoi:usesComponentInstance', $packet);
  }

  /**
   * Build a JSON request object to simulate endpoint payload submission.
   */
  protected function jsonRequest(string $path, array $payload): Request {
    return Request::create(
      $path,
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
  }

}

/**
 * Endpoint-focused controller test double.
 */
class WkfPhasePacketControllerEndpointDouble extends WkfPhasePacketController {

  /**
   * Stable workbook-like TSV fixture used by all phases.
   */
  public function workbookFixture(): string {
    return implode("\n", [
      '### SHEET: Tasks',
      'hasURI\trdf:type\thasco:hascoType\tvstoi:hasSupertask\tvstoi:usesComponentInstance',
      'https://pmsr.net/ont/TASK001\tvstoi:Task\tvstoi:Task\t\t',
      '',
      '### SHEET: STD',
      'hasURI\tvstoi:hasLearningObjectives\tvstoi:hasCriticalActions\tSignificance',
      'https://pmsr.net/ont/STD001\tObjective A\tAction A\tHigh',
    ]);
  }

  /**
   * Keep endpoint contract tests focused on packet composition logic.
   */
  protected function isSourceDocumentMetadataOnly(string $value): bool {
    return FALSE;
  }

  /**
   * Keep endpoint contract tests focused on packet composition logic.
   */
  protected function isInvalidSourceDocumentContent(string $value): bool {
    return FALSE;
  }

  /**
   * Avoid session dependencies for endpoint-style contract tests.
   */
  protected function resolveWkfScopedSessionContext(Request $request, string $wkfUri): array {
    return [];
  }

  /**
   * Use deterministic scope in tests.
   */
  protected function resolveWkfScope(Request $request, string $wkfUri): string {
    return 'test_scope';
  }

  /**
   * Provide deterministic workbook payload in tests.
   */
  protected function resolveCurrentWkfWorkbookContent(Request $request, string $wkfUri, string $scope): string {
    return $this->workbookFixture();
  }

  /**
   * Avoid filesystem lookup for prompt files in tests.
   */
  protected function getPhasePromptText(int $phase): string {
    return 'TEST PROMPT FOR PHASE ' . $phase;
  }

  /**
   * Provide in-memory WKF-SPEC-V3 fixture for Phase II.
   */
  protected function getWkfSpecV3Text(): string {
    return 'WKF-SPEC-V3 TEST CONTENT';
  }

  /**
   * Avoid API calls while preserving Phase IV contract sections.
   */
  protected function buildInstrumentInstanceComponentList(string $wkfUri): string {
    return implode("\n", [
      'DEPLOYMENT_COMPONENT_TO_INSTRUMENT_MAP',
      'Instrument: https://pmsr.net/ont/INS001',
      '  - ComponentInstance: https://pmsr.net/ont/CI001',
    ]);
  }

  /**
   * Avoid persistent storage dependencies in endpoint tests.
   */
  protected function persistPacket(Request $request, array $entry): string {
    return 'pkt_test_001';
  }

}
