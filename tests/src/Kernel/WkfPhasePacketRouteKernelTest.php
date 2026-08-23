<?php

namespace Drupal\Tests\rep\Kernel;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel route tests for WKF phase packet endpoint.
 *
 * Hits the real route path via http_kernel and validates HTTP status plus
 * response JSON shape/contract snippets for phases II/III/IV.
 *
 * @group rep
 */
class WkfPhasePacketRouteKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'rep',
    'pmsr',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('system', ['key_value', 'key_value_expire', 'sequences']);

    $this->container->set('rep.api_connector', new WkfPhasePacketApiConnectorStub());

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('hasPermission')->willReturnCallback(function (string $permission): bool {
      return $permission === 'access content';
    });
    $currentUser->method('id')->willReturn(1);
    $currentUser->method('getEmail')->willReturn('wkf.test@example.org');
    $currentUser->method('getRoles')->willReturn(['authenticated']);
    $currentUser->method('isAuthenticated')->willReturn(TRUE);
    $currentUser->method('isAnonymous')->willReturn(FALSE);

    $this->container->set('current_user', $currentUser);
  }

  /**
   * Validate route response status, shape, and phase-specific contract lines.
   *
   * @dataProvider packetPhaseProvider
   */
  public function testPacketEndpointRouteKernel(int $phase, array $expectedPacketSnippets): void {
    $payload = [
      'wkfUri' => 'https://pmsr.net/ont/WKF17874419111195412/PROC/PROC001',
      'sourceDocument' => 'Clinical source document content for kernel route test.',
      'phase1WkfTableTsv' => "hasURI\trdf:type\thasco:hascoType\tvstoi:hasSupertask\nhttps://pmsr.net/ont/TASK001\tvstoi:Task\tvstoi:Task\t",
      'wkfContent' => implode("\n", [
        '### SHEET: Tasks',
        'hasURI\trdf:type\thasco:hascoType\tvstoi:hasSupertask\tvstoi:usesComponentInstance',
        'https://pmsr.net/ont/TASK001\tvstoi:Task\tvstoi:Task\t\t',
        '### SHEET: STD',
        'hasURI\tvstoi:hasLearningObjectives\tvstoi:hasCriticalActions\tSignificance',
        'https://pmsr.net/ont/STD001\tObjective A\tAction A\tHigh',
      ]),
    ];

    $request = Request::create(
      '/rep/wkf/phase/packet/' . $phase,
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($payload, JSON_UNESCAPED_SLASHES)
    );

    $response = $this->container->get('http_kernel')->handle($request);

    $this->assertSame(200, $response->getStatusCode());

    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($data, 'Endpoint must return JSON object.');
    $this->assertTrue((bool) ($data['success'] ?? FALSE));
    $this->assertSame($phase, (int) ($data['phase'] ?? 0));
    $this->assertArrayHasKey('phaseTitle', $data);
    $this->assertArrayHasKey('packetText', $data);
    $this->assertArrayHasKey('fileName', $data);
    $this->assertArrayHasKey('packetId', $data);

    $packetText = (string) ($data['packetText'] ?? '');
    $this->assertStringContainsString('RESPONSE CONTRACT', $packetText);
    $this->assertStringContainsString('SUGGESTED_OUTPUT_FILENAME:', $packetText);

    foreach ($expectedPacketSnippets as $snippet) {
      $this->assertStringContainsString($snippet, $packetText);
    }
  }

  /**
   * Packet phase expectations for route-level contract checks.
   */
  public static function packetPhaseProvider(): array {
    return [
      'phase2' => [
        2,
        [
          'SUGGESTED_OUTPUT_FILENAME: WKF17874419111195412_Phase2_tasks.tsv',
          'OUTPUT_TASKS_SHEET_TSV',
          'OUTPUT_SUMMARY_JSON',
          'OUTPUT_ASSUMPTIONS',
          'PHASE1_TASKS_SHEET_TSV',
        ],
      ],
      'phase3' => [
        3,
        [
          'SUGGESTED_OUTPUT_FILENAME: WKF17874419111195412_Phase3_std.tsv',
          'CURRENT_STD_SHEET_TSV',
          'PHASE1_WKF_TABLE_TSV',
          'Return ONLY the STD TSV file body',
        ],
      ],
      'phase4' => [
        4,
        [
          'SUGGESTED_OUTPUT_FILENAME: WKF17874419111195412_Phase4_tasks.tsv',
          'LATEST_TASKS_SHEET_TSV',
          'INSTRUMENT_INSTANCES_WITH_COMPONENT_INSTANCES',
          'vstoi:usesComponentInstance',
        ],
      ],
    ];
  }

}

/**
 * Minimal API connector stub for route-kernel packet tests.
 */
class WkfPhasePacketApiConnectorStub {

  /**
   * Return lightweight objects expected by packet builder calls.
   */
  public function getUri(string $uri): string {
    if (strpos($uri, '/WKF') !== FALSE) {
      return json_encode([
        'hasOrganizationUri' => 'https://pmsr.net/ont/ORG001',
      ]);
    }

    if (strpos($uri, '/ORG') !== FALSE) {
      return json_encode([
        'hasSIRManagerEmail' => ['wkf.test@example.org'],
      ]);
    }

    return json_encode(new \stdClass());
  }

  /**
   * Match rep.api_connector parse behavior used by controller.
   */
  public function parseObjectResponse($raw, string $operationName) {
    if (is_object($raw)) {
      return $raw;
    }

    if (!is_string($raw) || trim($raw) === '') {
      return new \stdClass();
    }

    $decoded = json_decode($raw);
    return is_object($decoded) ? $decoded : new \stdClass();
  }

  /**
   * Return no managed elements by default.
   */
  public function listByManagerEmail(string $elementType, string $email, int $limit, int $offset): string {
    return json_encode([]);
  }

}
