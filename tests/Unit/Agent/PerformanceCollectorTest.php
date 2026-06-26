<?php

declare(strict_types=1);

/**
 * Cronmanager – Unit Tests: PerformanceCollector
 *
 * Tests the PerformanceCollector singleton for correct accumulation logic,
 * singleton identity, and conditional _perf payload generation.
 *
 * The singleton state is reset via ReflectionProperty between tests so each
 * test starts with a clean instance.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Unit\Agent;

use Cronmanager\Agent\Performance\PerformanceCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PerformanceCollectorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Reset the singleton so each test starts with a fresh instance.
     */
    private function resetSingleton(): void
    {
        $prop = new \ReflectionProperty(PerformanceCollector::class, 'instance');
        $prop->setValue(null, null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetSingleton();
        parent::tearDown();
    }

    // =========================================================================
    // Singleton identity
    // =========================================================================

    #[Test]
    public function getInstanceReturnsSameObject(): void
    {
        $a = PerformanceCollector::getInstance();
        $b = PerformanceCollector::getInstance();

        $this->assertSame($a, $b);
    }

    // =========================================================================
    // Initial state
    // =========================================================================

    #[Test]
    public function isNotConfiguredByDefault(): void
    {
        $this->assertFalse(PerformanceCollector::getInstance()->isConfigured());
    }

    #[Test]
    public function collectForResponseReturnsNullWhenNotConfigured(): void
    {
        $this->assertNull(PerformanceCollector::getInstance()->collectForResponse());
    }

    // =========================================================================
    // DB query accumulation
    // =========================================================================

    #[Test]
    public function addDbQueryAccumulatesTimeAndCount(): void
    {
        $pc = PerformanceCollector::getInstance();

        $pc->addDbQuery(10.5);
        $pc->addDbQuery(5.25);

        $this->assertEqualsWithDelta(15.75, $pc->getDbMs(), 0.001);
        $this->assertSame(2, $pc->getDbQueries());
    }

    #[Test]
    public function addDbQueryStartsAtZero(): void
    {
        $pc = PerformanceCollector::getInstance();

        $this->assertSame(0.0, $pc->getDbMs());
        $this->assertSame(0, $pc->getDbQueries());
    }

    // =========================================================================
    // collectForResponse — timing
    // =========================================================================

    #[Test]
    public function collectForResponseReturnsNullWhenShowInFrontendDisabled(): void
    {
        $pc = PerformanceCollector::getInstance();
        $pc->configure(
            persist:        false,
            showInFrontend: false,
            requestStart:   microtime(true) - 0.05,
            endpoint:       '/crons',
        );

        $this->assertNull($pc->collectForResponse());
    }

    #[Test]
    public function collectForResponseReturnsPerfArrayWhenFrontendEnabled(): void
    {
        $pc = PerformanceCollector::getInstance();
        $pc->configure(
            persist:        false,
            showInFrontend: true,
            requestStart:   microtime(true) - 0.1,
            endpoint:       '/crons',
        );
        $pc->addDbQuery(8.0);
        $pc->addDbQuery(4.0);

        $result = $pc->collectForResponse();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('request_ms', $result);
        $this->assertArrayHasKey('db_ms', $result);
        $this->assertArrayHasKey('db_queries', $result);
        // Request should take at least 100ms (we slept ~100ms via requestStart offset)
        $this->assertGreaterThan(0, $result['request_ms']);
        $this->assertEqualsWithDelta(12.0, $result['db_ms'], 0.001);
        $this->assertSame(2, $result['db_queries']);
    }

    #[Test]
    public function configureMarksCollectorAsConfigured(): void
    {
        $pc = PerformanceCollector::getInstance();
        $pc->configure(
            persist:        false,
            showInFrontend: false,
            requestStart:   microtime(true),
            endpoint:       '/tags',
        );

        $this->assertTrue($pc->isConfigured());
    }
}
