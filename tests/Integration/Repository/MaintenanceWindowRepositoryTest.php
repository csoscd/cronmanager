<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: MaintenanceWindowRepository
 *
 * Tests the full CRUD surface and the two business-logic methods:
 *   - isTargetInMaintenance() / isAgentInMaintenance()
 *   - detectConflicts()
 *
 * Every test runs inside a DB transaction that is rolled back in tearDown(),
 * so windows inserted in one test never affect another.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Repository;

use Cronmanager\Agent\Repository\MaintenanceWindowRepository;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\IntegrationTestCase;

final class MaintenanceWindowRepositoryTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Test infrastructure
    // -------------------------------------------------------------------------

    private function makeRepo(): MaintenanceWindowRepository
    {
        return new MaintenanceWindowRepository($this->pdo, new Logger('test'));
    }

    // =========================================================================
    // 1. CRUD – create / findById
    // =========================================================================

    #[Test]
    public function createReturnsNewIdAndFindByIdRetrievesRow(): void
    {
        $repo = $this->makeRepo();

        $id = $repo->create(
            target:          'local',
            cronSchedule:    '0 2 * * *',
            durationMinutes: 60,
            description:     'Nightly backup window',
            active:          true,
        );

        $this->assertGreaterThan(0, $id);

        $row = $repo->findById($id);

        $this->assertNotNull($row);
        $this->assertSame('local',                 $row['target']);
        $this->assertSame('0 2 * * *',             $row['cron_schedule']);
        $this->assertSame('60',                    (string) $row['duration_minutes']);
        $this->assertSame('Nightly backup window', $row['description']);
        $this->assertSame('1',                     (string) $row['active']);
    }

    #[Test]
    public function findByIdReturnsNullForUnknownId(): void
    {
        $row = $this->makeRepo()->findById(999999);

        $this->assertNull($row);
    }

    // =========================================================================
    // 2. findAll
    // =========================================================================

    #[Test]
    public function findAllReturnsAllInsertedWindows(): void
    {
        $repo = $this->makeRepo();

        $id1 = $this->seedMaintenanceWindow(['target' => 'local',  'description' => 'W1']);
        $id2 = $this->seedMaintenanceWindow(['target' => 'remote', 'description' => 'W2']);

        $all = $repo->findAll();

        $ids = array_column($all, 'id');
        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
    }

    #[Test]
    public function findAllFilteredByTargetReturnsOnlyMatchingRows(): void
    {
        $repo = $this->makeRepo();

        $id1 = $this->seedMaintenanceWindow(['target' => 'local']);
        $id2 = $this->seedMaintenanceWindow(['target' => 'remote-server']);

        $localRows = $repo->findAll('local');

        $ids = array_column($localRows, 'id');
        $this->assertContains($id1, $ids);
        $this->assertNotContains($id2, $ids);
    }

    #[Test]
    public function findAllReturnsEmptyArrayWhenNoWindowsExist(): void
    {
        // Transaction isolation: no windows seeded in this test
        $rows = $this->makeRepo()->findAll('nonexistent-target');

        $this->assertSame([], $rows);
    }

    // =========================================================================
    // 3. update
    // =========================================================================

    #[Test]
    public function updateModifiesExistingRow(): void
    {
        $repo = $this->makeRepo();
        $id   = $this->seedMaintenanceWindow(['active' => 1, 'duration_minutes' => 30]);

        $result = $repo->update(
            id:              $id,
            target:          'updated-host',
            cronSchedule:    '0 4 * * 0',
            durationMinutes: 120,
            description:     'Updated description',
            active:          false,
        );

        $this->assertTrue($result);

        $row = $repo->findById($id);
        $this->assertSame('updated-host',        $row['target']);
        $this->assertSame('0 4 * * 0',           $row['cron_schedule']);
        $this->assertSame('120',                  (string) $row['duration_minutes']);
        $this->assertSame('Updated description',  $row['description']);
        $this->assertSame('0',                    (string) $row['active']);
    }

    #[Test]
    public function updateReturnsFalseForUnknownId(): void
    {
        $result = $this->makeRepo()->update(
            id:              999999,
            target:          'x',
            cronSchedule:    '* * * * *',
            durationMinutes: 10,
            description:     null,
            active:          true,
        );

        $this->assertFalse($result);
    }

    // =========================================================================
    // 4. delete
    // =========================================================================

    #[Test]
    public function deleteRemovesRowAndFindByIdReturnsNull(): void
    {
        $repo = $this->makeRepo();
        $id   = $this->seedMaintenanceWindow();

        $result = $repo->delete($id);

        $this->assertTrue($result);
        $this->assertNull($repo->findById($id));
    }

    #[Test]
    public function deleteReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->makeRepo()->delete(999999));
    }

    // =========================================================================
    // 5. isTargetInMaintenance()
    // =========================================================================

    #[Test]
    public function activeWindowWithLongDurationMeansTargetIsInMaintenance(): void
    {
        // * * * * * fires every minute, 1440 min = 24 h → always active
        $this->seedActiveMaintenanceWindow('local');

        $this->assertTrue($this->makeRepo()->isTargetInMaintenance('local'));
    }

    #[Test]
    public function inactiveWindowDoesNotTriggerMaintenance(): void
    {
        $this->seedMaintenanceWindow([
            'target'           => 'local',
            'cron_schedule'    => '* * * * *',
            'duration_minutes' => 1440,
            'active'           => 0,
        ]);

        $this->assertFalse($this->makeRepo()->isTargetInMaintenance('local'));
    }

    #[Test]
    public function noWindowsForTargetReturnsFalse(): void
    {
        $this->assertFalse($this->makeRepo()->isTargetInMaintenance('no-such-target'));
    }

    #[Test]
    public function windowForDifferentTargetDoesNotAffectRequestedTarget(): void
    {
        $this->seedActiveMaintenanceWindow('remote-only');

        $this->assertFalse($this->makeRepo()->isTargetInMaintenance('local'));
    }

    #[Test]
    public function isAgentInMaintenanceReturnsTrueWhenAgentTargetWindowIsActive(): void
    {
        $this->seedActiveMaintenanceWindow(MaintenanceWindowRepository::AGENT_TARGET);

        $this->assertTrue($this->makeRepo()->isAgentInMaintenance());
    }

    #[Test]
    public function isAgentInMaintenanceReturnsFalseWhenNoAgentWindowExists(): void
    {
        // No _agent_ window seeded in this test
        $this->assertFalse($this->makeRepo()->isAgentInMaintenance());
    }

    // =========================================================================
    // 6. detectConflicts()
    // =========================================================================

    #[Test]
    public function detectConflictsFindsConflictsWhenJobRunsInsideAlwaysActiveWindow(): void
    {
        $this->seedActiveMaintenanceWindow('local');

        $conflicts = $this->makeRepo()->detectConflicts('* * * * *', 'local', 3);

        // Every one of the next 3 run-minutes falls inside the 24-hour window
        $this->assertCount(3, $conflicts);
    }

    #[Test]
    public function detectConflictsReturnsEmptyArrayWhenNoWindowsExist(): void
    {
        $conflicts = $this->makeRepo()->detectConflicts('* * * * *', 'no-windows-target', 5);

        $this->assertSame([], $conflicts);
    }

    #[Test]
    public function detectConflictsReturnsEmptyArrayWhenWindowIsInactive(): void
    {
        $this->seedMaintenanceWindow([
            'target'           => 'local',
            'cron_schedule'    => '* * * * *',
            'duration_minutes' => 1440,
            'active'           => 0,
        ]);

        $conflicts = $this->makeRepo()->detectConflicts('* * * * *', 'local', 3);

        $this->assertSame([], $conflicts);
    }

    #[Test]
    public function detectConflictsResultContainsRequiredKeys(): void
    {
        $this->seedActiveMaintenanceWindow('local');

        $conflicts = $this->makeRepo()->detectConflicts('* * * * *', 'local', 1);

        $this->assertCount(1, $conflicts);
        $entry = $conflicts[0];

        $this->assertArrayHasKey('run_time',    $entry);
        $this->assertArrayHasKey('window_id',   $entry);
        $this->assertArrayHasKey('window_start', $entry);
        $this->assertArrayHasKey('window_end',  $entry);
        $this->assertIsInt($entry['window_id']);
        $this->assertIsString($entry['run_time']);
        $this->assertIsString($entry['window_start']);
        $this->assertIsString($entry['window_end']);
    }

    #[Test]
    public function detectConflictsWindowIdMatchesSeedRow(): void
    {
        $windowId = $this->seedActiveMaintenanceWindow('local');

        $conflicts = $this->makeRepo()->detectConflicts('* * * * *', 'local', 1);

        $this->assertCount(1, $conflicts);
        $this->assertSame($windowId, $conflicts[0]['window_id']);
    }
}
