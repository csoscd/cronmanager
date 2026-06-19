<?php

declare(strict_types=1);

/**
 * Cronmanager – Unit Tests: ExitCodeMatcher
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Unit\Util;

use Cronmanager\Agent\Util\ExitCodeMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExitCodeMatcherTest extends TestCase
{
    // =========================================================================
    // matches()
    // =========================================================================

    // -------------------------------------------------------------------------
    // Null / empty expression → any non-zero exit code matches
    // -------------------------------------------------------------------------

    #[Test]
    public function matchesNullExpressionReturnsFalseForExitCodeZero(): void
    {
        $this->assertFalse(ExitCodeMatcher::matches(null, 0));
    }

    #[Test]
    public function matchesNullExpressionReturnsTrueForExitCodeOne(): void
    {
        $this->assertTrue(ExitCodeMatcher::matches(null, 1));
    }

    #[Test]
    public function matchesNullExpressionReturnsTrueForExitCode127(): void
    {
        $this->assertTrue(ExitCodeMatcher::matches(null, 127));
    }

    #[Test]
    public function matchesEmptyExpressionBehavesLikeNull(): void
    {
        $this->assertFalse(ExitCodeMatcher::matches('', 0));
        $this->assertTrue(ExitCodeMatcher::matches('', 1));
    }

    #[Test]
    public function matchesWhitespaceOnlyExpressionBehavesLikeNull(): void
    {
        $this->assertFalse(ExitCodeMatcher::matches('   ', 0));
        $this->assertTrue(ExitCodeMatcher::matches('   ', 255));
    }

    // -------------------------------------------------------------------------
    // Single integer token
    // -------------------------------------------------------------------------

    #[Test]
    public function matchesSingleTokenMatchesExactValue(): void
    {
        $this->assertTrue(ExitCodeMatcher::matches('1', 1));
    }

    #[Test]
    public function matchesSingleTokenDoesNotMatchOtherValue(): void
    {
        $this->assertFalse(ExitCodeMatcher::matches('1', 2));
        $this->assertFalse(ExitCodeMatcher::matches('1', 0));
    }

    #[Test]
    public function matchesSingleTokenZeroMatchesExitCodeZero(): void
    {
        $this->assertTrue(ExitCodeMatcher::matches('0', 0));
    }

    #[Test]
    public function matchesSingleToken255MatchesMax(): void
    {
        $this->assertTrue(ExitCodeMatcher::matches('255', 255));
    }

    // -------------------------------------------------------------------------
    // Range token
    // -------------------------------------------------------------------------

    #[Test]
    public function matchesRangeMatchesBoundaries(): void
    {
        $this->assertTrue(ExitCodeMatcher::matches('1-5', 1));
        $this->assertTrue(ExitCodeMatcher::matches('1-5', 5));
    }

    #[Test]
    public function matchesRangeMatchesMidpoint(): void
    {
        $this->assertTrue(ExitCodeMatcher::matches('1-5', 3));
    }

    #[Test]
    public function matchesRangeDoesNotMatchOutsideBoundaries(): void
    {
        $this->assertFalse(ExitCodeMatcher::matches('1-5', 0));
        $this->assertFalse(ExitCodeMatcher::matches('1-5', 6));
    }

    #[Test]
    public function matchesWideRange1To127(): void
    {
        $this->assertTrue(ExitCodeMatcher::matches('1-127', 64));
        $this->assertFalse(ExitCodeMatcher::matches('1-127', 0));
        $this->assertFalse(ExitCodeMatcher::matches('1-127', 128));
    }

    // -------------------------------------------------------------------------
    // Comma-separated list
    // -------------------------------------------------------------------------

    #[Test]
    public function matchesCommaListMatchesAnyListedValue(): void
    {
        $this->assertTrue(ExitCodeMatcher::matches('1,5,10', 1));
        $this->assertTrue(ExitCodeMatcher::matches('1,5,10', 5));
        $this->assertTrue(ExitCodeMatcher::matches('1,5,10', 10));
    }

    #[Test]
    public function matchesCommaListDoesNotMatchUnlistedValue(): void
    {
        $this->assertFalse(ExitCodeMatcher::matches('1,5,10', 2));
        $this->assertFalse(ExitCodeMatcher::matches('1,5,10', 11));
    }

    // -------------------------------------------------------------------------
    // Mixed list (ranges + singles)
    // -------------------------------------------------------------------------

    #[Test]
    public function matchesMixedListWithRangesAndSingles(): void
    {
        $expr = '1-5,10,255';

        $this->assertTrue(ExitCodeMatcher::matches($expr, 1));
        $this->assertTrue(ExitCodeMatcher::matches($expr, 3));
        $this->assertTrue(ExitCodeMatcher::matches($expr, 5));
        $this->assertTrue(ExitCodeMatcher::matches($expr, 10));
        $this->assertTrue(ExitCodeMatcher::matches($expr, 255));

        $this->assertFalse(ExitCodeMatcher::matches($expr, 0));
        $this->assertFalse(ExitCodeMatcher::matches($expr, 6));
        $this->assertFalse(ExitCodeMatcher::matches($expr, 100));
        $this->assertFalse(ExitCodeMatcher::matches($expr, 254));
    }

    // -------------------------------------------------------------------------
    // Whitespace tolerance
    // -------------------------------------------------------------------------

    #[Test]
    public function matchesToleratesWhitespaceAroundTokens(): void
    {
        $this->assertTrue(ExitCodeMatcher::matches(' 1 , 3-5 ', 4));
        $this->assertFalse(ExitCodeMatcher::matches(' 1 , 3-5 ', 2));
    }

    // =========================================================================
    // validate()
    // =========================================================================

    // -------------------------------------------------------------------------
    // Valid expressions → null returned
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function validExpressions(): array
    {
        return [
            'empty string'             => [''],
            'whitespace only'          => ['   '],
            'single zero'              => ['0'],
            'single non-zero'          => ['1'],
            'max value 255'            => ['255'],
            'simple range'             => ['1-5'],
            'range starting at zero'   => ['0-10'],
            'range ending at max'      => ['200-255'],
            'comma list of singles'    => ['1,2,3'],
            'mixed singles and ranges' => ['1-5,10,20-30,255'],
            'whitespace in list'       => [' 1 , 2-4 , 10 '],
        ];
    }

    #[Test]
    #[DataProvider('validExpressions')]
    public function validateReturnsNullForValidExpression(string $expression): void
    {
        $this->assertNull(
            ExitCodeMatcher::validate($expression),
            "Expected valid, got error for expression: '{$expression}'"
        );
    }

    // -------------------------------------------------------------------------
    // Invalid expressions → error string returned
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidExpressions(): array
    {
        return [
            'alphabetic token'        => ['abc',      'must be an integer'],
            'negative number'         => ['-1',       'both parts must be non-negative integers'],
            'value over 255'          => ['256',      'out of range'],
            'range value over 255'    => ['1-256',    'must be between 0 and 255'],
            'reversed range'          => ['5-3',      'must be less than the second'],
            'equal range bounds'      => ['5-5',      'must be less than the second'],
            'trailing comma'          => ['1,2,',     'empty token'],
            'double comma'            => ['1,,3',     'empty token'],
            'leading comma'           => [',1',       'empty token'],
            'non-digit in range'      => ['a-5',      'both parts must be non-negative integers'],
            'non-digit range end'     => ['1-b',      'both parts must be non-negative integers'],
        ];
    }

    #[Test]
    #[DataProvider('invalidExpressions')]
    public function validateReturnsErrorMessageForInvalidExpression(
        string $expression,
        string $expectedSubstring
    ): void {
        $result = ExitCodeMatcher::validate($expression);

        $this->assertIsString($result, "Expected error string, got null for: '{$expression}'");
        $this->assertStringContainsStringIgnoringCase(
            $expectedSubstring,
            $result,
            "Error message for '{$expression}' should contain '{$expectedSubstring}'"
        );
    }
}
