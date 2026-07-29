<?php

namespace Drupal\Tests\rep\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Tests for entry point color-coding functionality.
 *
 * Regression tests for JavaScript issues with color-coding bound/unbound entry points.
 *
 * @group rep
 * @group rep_javascript
 */
class EntryPointColorCodingTest extends WebDriverTestBase {

  /**
   * Avoid unrelated schema failures from module config during test install.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Fetch bound entry points JSON payload.
   */
  private function getBoundEntryPointsPayload(): array {
    $this->drupalGet('/rep/bound-entry-points?_format=json');
    $json = $this->getSession()->getPage()->getContent();
    $data = json_decode($json, true);

    $this->assertIsArray($data, 'Bound entry points endpoint must return JSON object.');
    $this->assertArrayHasKey('bound', $data, 'Bound entry points payload must contain "bound".');
    $this->assertArrayHasKey('count', $data, 'Bound entry points payload must contain "count".');

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'hasco_barrio';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'rep', 'pmsr'];

  /**
   * Test that entry point nodes have color-coding CSS classes applied.
   *
   * Regression test for Issue: Color-coding not being applied
   * Root cause: Event handlers attached after tree initialization
   *
   * @javascript
   */
  public function testEntryPointNodesHaveColorCodingClasses() {
    $adminUser = $this->drupalCreateUser([
      'access content',
      'administer site configuration',
    ]);
    $this->drupalLogin($adminUser);

    $this->drupalGet('/rep/manage/map-entry-points');
    
    // Wait for tree to load
    $this->assertSession()->waitForElement('css', '#current-tree .jstree-anchor');
    
    // Expand HASCO CLASSES root node
    $this->click('#current-tree .jstree-closed > i.jstree-icon');
    $this->assertSession()->waitForElement('css', '[title*="EntryPoint"]');
    
    // Wait for color-coding to be applied
    $this->getSession()->wait(2000);
    
    // Count nodes with color classes
    $page = $this->getSession()->getPage();
    $boundNodes = $page->findAll('css', '.entry-point-bound');
    $unboundNodes = $page->findAll('css', '.entry-point-unbound');
    
    $totalColored = count($boundNodes) + count($unboundNodes);
    
    // All 23 entry points should have color classes
    $this->assertEquals(
      23,
      $totalColored,
      'All 23 entry points should have color-coding classes applied'
    );
    
    // Since all entry points are bound in a properly configured system, expect 23 green
    $this->assertEquals(
      23,
      count($boundNodes),
      'All 23 entry points should be colored green (bound)'
    );
    
    $this->assertEquals(
      0,
      count($unboundNodes),
      'No entry points should be colored red (unbound) in properly configured system'
    );
  }

  /**
   * Test that only entry points get colored, not their children.
   *
   * Regression test for Issue: Bound terms being colored
   * Only URIs ending with "EntryPoint" should have colors
   */
  public function testOnlyEntryPointsGetColored() {
    $adminUser = $this->drupalCreateUser([
      'access content',
      'administer site configuration',
    ]);
    $this->drupalLogin($adminUser);

    $this->drupalGet('/rep/manage/map-entry-points');
    $this->assertSession()->waitForElement('css', '#current-tree .jstree-anchor');
    
    // Expand HASCO CLASSES
    $this->click('#current-tree .jstree-closed > i.jstree-icon');
    $this->assertSession()->waitForElement('css', '[title*="EntryPoint"]');
    
    // Expand an entry point (e.g., AnatomicalPartEntryPoint)
    $anatomicalPartNode = $this->getSession()->getPage()->find(
      'css',
      '[title="http://hadatac.org/ont/hasco/AnatomicalPartEntryPoint"]'
    );
    
    if ($anatomicalPartNode) {
      // Get parent li and find expand icon
      $parentLi = $anatomicalPartNode->getParent()->getParent();
      $expandIcon = $parentLi->find('css', 'i.jstree-icon.jstree-ocl');
      if ($expandIcon) {
        $expandIcon->click();
        $this->getSession()->wait(1000);
        
        // Wait for children to load
        $this->assertSession()->waitForElement('css', '[title*="uberon"]', 10000);
        
        // Verify child nodes (e.g., UBERON terms) do NOT have color classes
        $uberonNodes = $this->getSession()->getPage()->findAll('css', '[title*="uberon"]');
        
        foreach ($uberonNodes as $node) {
          $classes = $node->getAttribute('class');
          $this->assertStringNotContainsString(
            'entry-point-bound',
            $classes,
            'Child nodes should not have entry-point-bound class'
          );
          $this->assertStringNotContainsString(
            'entry-point-unbound',
            $classes,
            'Child nodes should not have entry-point-unbound class'
          );
        }
      }
    }
  }

  /**
   * Test fetchBoundEntryPoints API is called on tree load.
   *
   * Regression test for Issue: JavaScript event timing
   * fetchBoundEntryPoints must be called and complete before colors applied
   */
  public function testFetchBoundEntryPointsCalledOnTreeLoad() {
    $adminUser = $this->drupalCreateUser([
      'access content',
      'administer site configuration',
    ]);
    $this->drupalLogin($adminUser);

    // Monitor network requests
    $this->getSession()->getDriver()->executeScript(
      "window.apiCallsMade = [];" .
      "jQuery(document).ajaxComplete(function(event, xhr, settings) {" .
      "  if (settings.url && settings.url.includes('/rep/bound-entry-points')) {" .
      "    window.apiCallsMade.push({url: settings.url, status: xhr.status});" .
      "  }" .
      "});"
    );

    $this->drupalGet('/rep/manage/map-entry-points');
    $this->assertSession()->waitForElement('css', '#current-tree .jstree-anchor');
    
    // Expand tree to trigger ready event
    $this->click('#current-tree .jstree-closed > i.jstree-icon');
    $this->getSession()->wait(2000);
    
    // Check that API was called
    $apiCalls = $this->getSession()->evaluateScript('return window.apiCallsMade;');
    
    $this->assertNotEmpty($apiCalls, 'fetchBoundEntryPoints API should be called');
    $this->assertEquals(200, $apiCalls[0]['status'], 'API call should succeed');
  }

  /**
   * Test color-coding updates when tree is refreshed.
   *
   * Regression test: Colors should re-apply after tree refresh
   */
  public function testColorCodingUpdatesOnTreeRefresh() {
    $adminUser = $this->drupalCreateUser([
      'access content',
      'administer site configuration',
    ]);
    $this->drupalLogin($adminUser);

    $this->drupalGet('/rep/manage/map-entry-points');
    $this->assertSession()->waitForElement('css', '#current-tree .jstree-anchor');
    
    // Expand tree
    $this->click('#current-tree .jstree-closed > i.jstree-icon');
    $this->assertSession()->waitForElement('css', '[title*="EntryPoint"]');
    $this->getSession()->wait(2000);
    
    // Count initial colored nodes
    $initialBound = count($this->getSession()->getPage()->findAll('css', '.entry-point-bound'));
    
    // Trigger tree refresh via JavaScript
    $this->getSession()->evaluateScript(
      "jQuery('#current-tree').jstree('refresh');"
    );
    
    $this->getSession()->wait(3000);
    
    // Expand again after refresh
    $this->click('#current-tree .jstree-closed > i.jstree-icon');
    $this->getSession()->wait(2000);
    
    // Count colored nodes after refresh
    $afterBound = count($this->getSession()->getPage()->findAll('css', '.entry-point-bound'));
    
    $this->assertEquals(
      $initialBound,
      $afterBound,
      'Color-coding should be re-applied after tree refresh'
    );
  }

  /**
   * Test CSS styles are correctly applied to colored entry points.
   */
  public function testCssStylesAppliedToColoredEntryPoints() {
    $adminUser = $this->drupalCreateUser([
      'access content',
      'administer site configuration',
    ]);
    $this->drupalLogin($adminUser);

    $this->drupalGet('/rep/manage/map-entry-points');
    $this->assertSession()->waitForElement('css', '#current-tree .jstree-anchor');
    
    // Expand tree
    $this->click('#current-tree .jstree-closed > i.jstree-icon');
    $this->assertSession()->waitForElement('css', '.entry-point-bound');
    $this->getSession()->wait(2000);
    
    // Find a bound entry point node
    $boundNode = $this->getSession()->getPage()->find('css', '.entry-point-bound');
    
    if ($boundNode) {
      // Get computed color style
      $color = $this->getSession()->evaluateScript(
        "return window.getComputedStyle(document.querySelector('.entry-point-bound')).color;"
      );
      
      // Green color: rgb(40, 167, 69) or #28a745
      $this->assertStringContainsString(
        '40, 167, 69',
        $color,
        'Bound entry points should be displayed in green color'
      );
    }
  }

  /**
   * Baseline regression test for Manage Class Entry Points.
   *
   * Ensures the Resync action:
   * - does not reduce bound entry-point coverage,
   * - and preserves key HASCO entry-point bindings expected in baseline.
   *
   * @javascript
   */
  public function testResyncPreservesBaselineKeyEntryPointBindings() {
    $adminUser = $this->drupalCreateUser([
      'access content',
      'administer site configuration',
      'administer semantic ontologies',
    ]);
    $this->drupalLogin($adminUser);

    $before = $this->getBoundEntryPointsPayload();
    $beforeCount = (int) ($before['count'] ?? 0);

    $this->drupalGet('/rep/manage/map-entry-points');
    $this->assertSession()->waitForElement('css', '#edit-resync-from-kg');

    // Trigger server-side resync from the same UI used by users.
    $this->click('#edit-resync-from-kg');
    $this->assertSession()->waitForText('Resync completed', 20000);

    $after = $this->getBoundEntryPointsPayload();
    $afterCount = (int) ($after['count'] ?? 0);

    $this->assertGreaterThanOrEqual(
      $beforeCount,
      $afterCount,
      'Resync must not reduce bound entry-point coverage.'
    );

    $bound = array_map('strval', (array) ($after['bound'] ?? []));

    $expectedKeyEntryPoints = [
      'http://hadatac.org/ont/hasco/CodeBookEntryPoint',
      'http://hadatac.org/ont/hasco/QuestionnaireEntryPoint',
      'http://hadatac.org/ont/hasco/ResponseOptionEntryPoint',
      'http://hadatac.org/ont/hasco/StudyEntryPoint',
      'http://hadatac.org/ont/hasco/TaskEntryPoint',
    ];

    foreach ($expectedKeyEntryPoints as $entryPointUri) {
      $this->assertContains(
        $entryPointUri,
        $bound,
        sprintf('Expected baseline entry point to be bound after resync: %s', $entryPointUri)
      );
    }
  }

}
