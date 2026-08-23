<?php

namespace Drupal\Tests\rep\Unit;

use Drupal\rep\Controller\WkfPhasePacketController;
use Drupal\Tests\UnitTestCase;

/**
 * Regression tests for WKF phase generation/enrichment invariants.
 *
 * @group rep
 * @coversDefaultClass \Drupal\rep\Controller\WkfPhasePacketController
 */
class WkfPhaseGenerationRegressionTest extends UnitTestCase {

  /**
   * Controller under test.
   *
   * @var \Drupal\rep\Controller\WkfPhasePacketController
   */
  protected WkfPhasePacketController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->controller = new WkfPhasePacketController();
  }

  /**
   * Invoke non-public controller method.
   */
  protected function invokeControllerMethod(string $methodName, array $args = []) {
    $ref = new \ReflectionMethod($this->controller, $methodName);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($this->controller, $args);
  }

  /**
   * Ensures legacy/public phase ids map to backend phase ids predictably.
   *
   * @covers ::normalizeLegacyPhase
   */
  public function testNormalizeLegacyPhaseMapping(): void {
    $this->assertSame(2, $this->invokeControllerMethod('normalizeLegacyPhase', [1]));
    $this->assertSame(2, $this->invokeControllerMethod('normalizeLegacyPhase', [2]));
    $this->assertSame(3, $this->invokeControllerMethod('normalizeLegacyPhase', [3]));
    $this->assertSame(4, $this->invokeControllerMethod('normalizeLegacyPhase', [4]));
    $this->assertSame(4, $this->invokeControllerMethod('normalizeLegacyPhase', [5]));
  }

  /**
   * Ensures WKF hash extraction prefers WKF numeric id in URI.
   *
   * @covers ::extractWkfHashFromUri
   */
  public function testExtractWkfHashFromWkfUri(): void {
    $uri = 'https://pmsr.net/ont/WKF17874419111195412/PROC/PROC001';
    $hash = $this->invokeControllerMethod('extractWkfHashFromUri', [$uri]);

    $this->assertSame('17874419111195412', $hash);
  }

  /**
   * Ensures fallback extraction uses large digit tokens when WKF token missing.
   *
   * @covers ::extractWkfHashFromUri
   */
  public function testExtractWkfHashFromGenericNumericToken(): void {
    $uri = 'https://pmsr.net/ont/PRS174550522469430265';
    $hash = $this->invokeControllerMethod('extractWkfHashFromUri', [$uri]);

    $this->assertSame('174550522469430265', $hash);
  }

  /**
   * Ensures deterministic filename convention for enrichment/generation phases.
   *
   * @covers ::buildSuggestedPhaseTsvFileName
   */
  public function testSuggestedPhaseTsvFileNameConvention(): void {
    $wkfUri = 'https://pmsr.net/ont/WKF17874419111195412/PROC/PROC001';

    $phase2 = $this->invokeControllerMethod('buildSuggestedPhaseTsvFileName', [2, $wkfUri]);
    $phase3 = $this->invokeControllerMethod('buildSuggestedPhaseTsvFileName', [3, $wkfUri]);
    $phase4 = $this->invokeControllerMethod('buildSuggestedPhaseTsvFileName', [4, $wkfUri]);

    $this->assertSame('WKF17874419111195412_Phase2_tasks.tsv', $phase2);
    $this->assertSame('WKF17874419111195412_Phase3_std.tsv', $phase3);
    $this->assertSame('WKF17874419111195412_Phase4_tasks.tsv', $phase4);
  }

  /**
   * Ensures same WKF+phase stays deterministic and cross-phase names differ.
   *
   * @covers ::buildSuggestedPhaseTsvFileName
   */
  public function testSuggestedFileNameDeterminismAndUniquenessWithinWkf(): void {
    $wkfUri = 'https://pmsr.net/ont/WKF17874419111195412/PROC/PROC001';

    $phase2a = $this->invokeControllerMethod('buildSuggestedPhaseTsvFileName', [2, $wkfUri]);
    $phase2b = $this->invokeControllerMethod('buildSuggestedPhaseTsvFileName', [2, $wkfUri]);
    $phase3 = $this->invokeControllerMethod('buildSuggestedPhaseTsvFileName', [3, $wkfUri]);
    $phase4 = $this->invokeControllerMethod('buildSuggestedPhaseTsvFileName', [4, $wkfUri]);

    $this->assertSame($phase2a, $phase2b, 'Same WKF and phase must produce same filename.');
    $this->assertNotSame($phase2a, $phase3, 'Different phases must produce distinct filenames.');
    $this->assertNotSame($phase3, $phase4, 'Different phases must produce distinct filenames.');
    $this->assertStringContainsString('WKF17874419111195412', $phase2a);
    $this->assertStringContainsString('Phase2', $phase2a);
    $this->assertStringContainsString('Phase3', $phase3);
    $this->assertStringContainsString('Phase4', $phase4);
  }

  /**
   * Ensures each phase keeps its expected artifact role in file naming.
   *
   * @covers ::buildSuggestedPhaseTsvFileName
   */
  public function testPhaseArtifactRoleNaming(): void {
    $wkfUri = 'https://pmsr.net/ont/WKF17874419111195412/PROC/PROC001';

    $phase2 = $this->invokeControllerMethod('buildSuggestedPhaseTsvFileName', [2, $wkfUri]);
    $phase3 = $this->invokeControllerMethod('buildSuggestedPhaseTsvFileName', [3, $wkfUri]);
    $phase4 = $this->invokeControllerMethod('buildSuggestedPhaseTsvFileName', [4, $wkfUri]);

    $this->assertStringEndsWith('_tasks.tsv', $phase2, 'Phase II must generate Tasks TSV naming.');
    $this->assertStringEndsWith('_std.tsv', $phase3, 'Phase III must generate STD TSV naming.');
    $this->assertStringEndsWith('_tasks.tsv', $phase4, 'Phase IV must generate Tasks TSV naming.');
  }

}
