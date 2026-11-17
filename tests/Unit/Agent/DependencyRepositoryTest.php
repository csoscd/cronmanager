<?php

declare(strict_types=1);

/**
 * Cronmanager – Unit Tests: DependencyRepository
 *
 * Tests the static utility methods and pure-logic helpers of DependencyRepository
 * that do not require a database connection.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Unit\Agent;

use Cronmanager\Agent\Repository\DependencyRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DependencyRepositoryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // exitCodeMatches
    // -------------------------------------------------------------------------

    #[Test]
    public function exitCodeMatchesReturnsTrueForExactMatch(): void
    {
        self::assertTrue(DependencyRepository::exitCodeMatches(0, '0'));
        self::assertTrue(DependencyRepository::exitCodeMatches(1, '1'));
        self::assertTrue(DependencyRepository::exitCodeMatches(255, '255'));
    }

    #[Test]
    public function exitCodeMatchesReturnsTrueForOneOfMultiple(): void
    {
        self::assertTrue(DependencyRepository::exitCodeMatches(0, '0,1,2'));
        self::assertTrue(DependencyRepository::exitCodeMatches(2, '0,1,2'));
    }

    #[Test]
    public function exitCodeMatchesReturnsFalseForNonMatch(): void
    {
        self::assertFalse(DependencyRepository::exitCodeMatches(1, '0'));
        self::assertFalse(DependencyRepository::exitCodeMatches(3, '0,1,2'));
    }

    #[Test]
    public function exitCodeMatchesHandlesWhitespaceInList(): void
    {
        self::assertTrue(DependencyRepository::exitCodeMatches(0, ' 0 , 1 '));
        self::assertTrue(DependencyRepository::exitCodeMatches(1, ' 0 , 1 '));
    }

    #[Test]
    public function exitCodeMatchesReturnsTrueForEmptyList(): void
    {
        // empty = match any exit code (always trigger/satisfy)
        self::assertTrue(DependencyRepository::exitCodeMatches(0, ''));
        self::assertTrue(DependencyRepository::exitCodeMatches(1, ''));
        self::assertTrue(DependencyRepository::exitCodeMatches(-1, ''));
    }

    // -------------------------------------------------------------------------
    // validateExitCodes
    // -------------------------------------------------------------------------

    #[Test]
    public function validateExitCodesAcceptsValidSingleCode(): void
    {
        self::assertNull(DependencyRepository::validateExitCodes('0'));
        self::assertNull(DependencyRepository::validateExitCodes('255'));
    }

    #[Test]
    public function validateExitCodesAcceptsValidList(): void
    {
        self::assertNull(DependencyRepository::validateExitCodes('0,1,2'));
        self::assertNull(DependencyRepository::validateExitCodes('0, 1, 2'));
    }

    #[Test]
    public function validateExitCodesRejectsNegativeCode(): void
    {
        // Negative exit codes are rejected; internal sentinels (-4, -7) are not
        // user-configurable dependency criteria — a user typing -7 would get no
        // match because the exit-code sentinel is never stored in exit_codes.
        self::assertIsString(DependencyRepository::validateExitCodes('-1'));
        self::assertIsString(DependencyRepository::validateExitCodes('-7'));
        self::assertIsString(DependencyRepository::validateExitCodes('0,-1,2'));
    }

    #[Test]
    public function validateExitCodesRejectsOutOfRangeCode(): void
    {
        $error = DependencyRepository::validateExitCodes('256');
        self::assertIsString($error);
    }

    #[Test]
    public function validateExitCodesRejectsNonNumeric(): void
    {
        $error = DependencyRepository::validateExitCodes('ok');
        self::assertIsString($error);
    }

    #[Test]
    public function validateExitCodesAcceptsEmptyString(): void
    {
        // empty = match any exit code (always trigger/satisfy)
        self::assertNull(DependencyRepository::validateExitCodes(''));
    }

    #[Test]
    public function validateExitCodesRejectsEmptyToken(): void
    {
        // "0,,1" has an empty token between commas
        $error = DependencyRepository::validateExitCodes('0,,1');
        self::assertIsString($error);
    }

    // -------------------------------------------------------------------------
    // validateDependency – field-level validation (no DB needed for these cases)
    //
    // Tests use an invalid predecessor_id (0) so the method returns an error
    // before reaching the SELECT query, keeping these as pure unit tests.
    // -------------------------------------------------------------------------

    #[Test]
    public function validateDependencyRejectsInvalidType(): void
    {
        $pdo  = $this->createMock(\PDO::class);
        $repo = new DependencyRepository($pdo);

        // predecessor_id = 0 fails the positive-integer guard, type is also invalid
        $errors = $repo->validateDependency(['predecessor_id' => 0, 'type' => 'unknown'], 99);

        self::assertArrayHasKey('dependency.predecessor_id', $errors);
        self::assertArrayHasKey('dependency.type', $errors);
    }

    #[Test]
    public function validateDependencyRejectsSelfReferentialDependency(): void
    {
        $pdo  = $this->createMock(\PDO::class);
        $repo = new DependencyRepository($pdo);

        // predecessor_id === jobId → self-reference error (no DB query, guarded before SELECT)
        $errors = $repo->validateDependency(['predecessor_id' => 5, 'type' => 'requires'], 5);

        self::assertArrayHasKey('dependency.predecessor_id', $errors);
    }

    #[Test]
    public function validateDependencyRejectsNegativeTriggerDelayMinutes(): void
    {
        $pdo  = $this->createMock(\PDO::class);
        $repo = new DependencyRepository($pdo);

        $errors = $repo->validateDependency([
            'predecessor_id'        => 0, // invalid → no DB query
            'type'                  => 'triggered_by',
            'trigger_delay_minutes' => -1,
        ], 0);

        self::assertArrayHasKey('dependency.trigger_delay_minutes', $errors);
    }

    #[Test]
    public function validateDependencyAcceptsZeroTriggerDelayMinutes(): void
    {
        $pdo  = $this->createMock(\PDO::class);
        $repo = new DependencyRepository($pdo);

        $errors = $repo->validateDependency([
            'predecessor_id'        => 0, // invalid → no DB query
            'type'                  => 'triggered_by',
            'trigger_delay_minutes' => 0,
        ], 0);

        // trigger_delay_minutes = 0 is valid; only predecessor_id should error
        self::assertArrayNotHasKey('dependency.trigger_delay_minutes', $errors);
    }

    #[Test]
    public function validateDependencyRejectsZeroMaxAgeMinutes(): void
    {
        $pdo  = $this->createMock(\PDO::class);
        $repo = new DependencyRepository($pdo);

        $errors = $repo->validateDependency([
            'predecessor_id'  => 0, // invalid → no DB query
            'type'            => 'requires',
            'max_age_minutes' => 0,
        ], 0);

        self::assertArrayHasKey('dependency.max_age_minutes', $errors);
    }

    #[Test]
    public function validateDependencyRejectsNegativeExitCodesInDepField(): void
    {
        $pdo  = $this->createMock(\PDO::class);
        $repo = new DependencyRepository($pdo);

        $errors = $repo->validateDependency([
            'predecessor_id' => 0, // invalid → no DB query
            'type'           => 'requires',
            'exit_codes'     => '-1',
        ], 0);

        self::assertArrayHasKey('dependency.exit_codes', $errors);
    }

    // -------------------------------------------------------------------------
    // detectCycle: pure static graph walk (no DB needed for simple cases)
    // The DB-backed cycle detection tests live in Integration/.
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('provideExitCodeMatchCases')]
    public function exitCodeMatchesDataDriven(int $code, string $list, bool $expected): void
    {
        self::assertSame($expected, DependencyRepository::exitCodeMatches($code, $list));
    }

    /**
     * @return array<string, array{int, string, bool}>
     */
    public static function provideExitCodeMatchCases(): array
    {
        return [
            'zero matches zero'          => [0,   '0',       true],
            'one does not match zero'    => [1,   '0',       false],
            'code in list'               => [2,   '0,1,2',   true],
            'code not in list'           => [5,   '0,1,2',   false],
            'whitespace ignored'         => [1,   ' 0 , 1 ', true],
            'empty list = match any'     => [0,   '',        true],
            'negative system code (-7)'  => [-7,  '0',       false],
            'negative system code (-4)'  => [-4,  '0',       false],
        ];
    }

    // -------------------------------------------------------------------------
    // findActiveSuccessorsRecursive – BFS logic (mocked PDO)
    // -------------------------------------------------------------------------

    /**
     * Build a mock PDO that returns the given rows from fetchAll().
     *
     * @param list<array<string, mixed>> $rows
     */
    private function buildMockPdoForSuccessors(array $rows): \PDO
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($rows);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return $pdo;
    }

    #[Test]
    public function findActiveSuccessorsRecursiveReturnsEmptyForEmptyInput(): void
    {
        $pdo  = $this->createMock(\PDO::class);
        $repo = new DependencyRepository($pdo);

        self::assertSame([], $repo->findActiveSuccessorsRecursive([]));
    }

    #[Test]
    public function findActiveSuccessorsRecursiveReturnsDirectSuccessor(): void
    {
        $rows = [
            ['predecessor_id' => 1, 'job_id' => 2, 'type' => 'requires', 'description' => 'Job B'],
        ];
        $repo   = new DependencyRepository($this->buildMockPdoForSuccessors($rows));
        $result = $repo->findActiveSuccessorsRecursive([1]);

        self::assertCount(1, $result[1]);
        self::assertSame(2, $result[1][0]['id']);
        self::assertSame('requires', $result[1][0]['dependency_type']);
        self::assertSame(1, $result[1][0]['depth']);
    }

    #[Test]
    public function findActiveSuccessorsRecursiveReturnsTransitiveSuccessors(): void
    {
        // Chain: 1 → 2 → 3
        $rows = [
            ['predecessor_id' => 1, 'job_id' => 2, 'type' => 'requires',     'description' => 'B'],
            ['predecessor_id' => 2, 'job_id' => 3, 'type' => 'triggered_by', 'description' => 'C'],
        ];
        $repo   = new DependencyRepository($this->buildMockPdoForSuccessors($rows));
        $result = $repo->findActiveSuccessorsRecursive([1]);

        self::assertCount(2, $result[1]);
        $ids = array_column($result[1], 'id');
        self::assertContains(2, $ids);
        self::assertContains(3, $ids);
    }

    #[Test]
    public function findActiveSuccessorsRecursiveHandlesCycleGracefully(): void
    {
        // A → B → A (cycle). BFS visited guard must prevent infinite loop.
        $rows = [
            ['predecessor_id' => 1, 'job_id' => 2, 'type' => 'requires', 'description' => 'B'],
            ['predecessor_id' => 2, 'job_id' => 1, 'type' => 'requires', 'description' => 'A'],
        ];
        $repo   = new DependencyRepository($this->buildMockPdoForSuccessors($rows));
        $result = $repo->findActiveSuccessorsRecursive([1]);

        // Only job 2 is a successor; job 1 is the root so it is in $visited from the start
        self::assertCount(1, $result[1]);
        self::assertSame(2, $result[1][0]['id']);
    }

    #[Test]
    public function findActiveSuccessorsRecursiveReturnsEmptyForJobWithNoSuccessors(): void
    {
        $rows = [
            ['predecessor_id' => 99, 'job_id' => 100, 'type' => 'requires', 'description' => 'X'],
        ];
        $repo   = new DependencyRepository($this->buildMockPdoForSuccessors($rows));
        $result = $repo->findActiveSuccessorsRecursive([1]);

        self::assertSame([], $result[1] ?? []);
    }

    #[Test]
    public function findActiveSuccessorsRecursiveHandlesMultipleRoots(): void
    {
        // Root 1 → 3; Root 2 → 3; both should report job 3 as a successor
        $rows = [
            ['predecessor_id' => 1, 'job_id' => 3, 'type' => 'requires', 'description' => 'C'],
            ['predecessor_id' => 2, 'job_id' => 3, 'type' => 'requires', 'description' => 'C'],
        ];
        $repo   = new DependencyRepository($this->buildMockPdoForSuccessors($rows));
        $result = $repo->findActiveSuccessorsRecursive([1, 2]);

        self::assertSame(3, $result[1][0]['id']);
        self::assertSame(3, $result[2][0]['id']);
    }
}
