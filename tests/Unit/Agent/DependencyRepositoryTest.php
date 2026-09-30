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
    public function exitCodeMatchesReturnsFalseForEmptyList(): void
    {
        self::assertFalse(DependencyRepository::exitCodeMatches(0, ''));
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
    public function validateExitCodesAcceptsNegativeCode(): void
    {
        // Negative exit codes are valid (e.g. -1, -7 for system/dependency signals)
        self::assertNull(DependencyRepository::validateExitCodes('-1'));
        self::assertNull(DependencyRepository::validateExitCodes('-7'));
        self::assertNull(DependencyRepository::validateExitCodes('0,-1,2'));
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
    public function validateExitCodesRejectsEmptyString(): void
    {
        $error = DependencyRepository::validateExitCodes('');
        self::assertIsString($error);
    }

    #[Test]
    public function validateExitCodesRejectsEmptyToken(): void
    {
        // "0,,1" has an empty token between commas
        $error = DependencyRepository::validateExitCodes('0,,1');
        self::assertIsString($error);
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
            'empty list = no match'      => [0,   '',        false],
            'negative system code (-7)'  => [-7,  '0',       false],
            'negative system code (-4)'  => [-4,  '0',       false],
        ];
    }
}
