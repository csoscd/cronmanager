<?php

declare(strict_types=1);

/**
 * Cronmanager – OnceScheduleTest
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Tests\Unit\Util;

use Cronmanager\Agent\Util\OnceSchedule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OnceSchedule utility.
 */
final class OnceScheduleTest extends TestCase
{
    // -------------------------------------------------------------------------
    // compute()
    // -------------------------------------------------------------------------

    public function testComputeReturnsFiveFieldExpression(): void
    {
        $expr = OnceSchedule::compute();
        $parts = preg_split('/\s+/', trim($expr));
        $this->assertIsArray($parts);
        $this->assertCount(5, $parts, 'Cron expression must have exactly 5 fields');
        $this->assertSame('*', $parts[4], 'Day-of-week field must be wildcard');
    }

    public function testComputeWithZeroDelayIsNumericMinHour(): void
    {
        $expr  = OnceSchedule::compute(0);
        $parts = preg_split('/\s+/', trim($expr));
        $this->assertIsArray($parts);

        // min (0–59), hour (0–23), dom (1–31), month (1–12)
        $this->assertGreaterThanOrEqual(0,  (int) $parts[0]);
        $this->assertLessThanOrEqual(59,    (int) $parts[0]);
        $this->assertGreaterThanOrEqual(0,  (int) $parts[1]);
        $this->assertLessThanOrEqual(23,    (int) $parts[1]);
        $this->assertGreaterThanOrEqual(1,  (int) $parts[2]);
        $this->assertLessThanOrEqual(31,    (int) $parts[2]);
        $this->assertGreaterThanOrEqual(1,  (int) $parts[3]);
        $this->assertLessThanOrEqual(12,    (int) $parts[3]);
    }

    public function testComputeWithDelayIsLaterThanWithoutDelay(): void
    {
        // Both computed at (nearly) the same time; the delayed one must be ≥ the non-delayed one.
        $expr0     = OnceSchedule::compute(0);
        $exprDelay = OnceSchedule::compute(5);

        $tz   = OnceSchedule::timezone();
        $now  = new \DateTime('now', $tz);
        $year = (int) $now->format('Y');

        $toTimestamp = static function (string $expr) use ($tz, $year): int {
            $parts = preg_split('/\s+/', trim($expr));
            [$min, $hour, $dom, $month] = $parts;
            $dt = \DateTime::createFromFormat(
                'Y-m-d H:i',
                sprintf('%04d-%02d-%02d %02d:%02d', $year, (int) $month, (int) $dom, (int) $hour, (int) $min),
                $tz
            );
            return $dt !== false ? (int) $dt->format('U') : 0;
        };

        $this->assertGreaterThanOrEqual(
            $toTimestamp($expr0),
            $toTimestamp($exprDelay),
            'A 5-minute delayed schedule must not be earlier than a 0-minute one'
        );
    }

    public function testComputeWithLargeDelayAdvancesCorrectly(): void
    {
        // A delay of 120 minutes must produce a schedule at least 2 hours from now.
        $expr  = OnceSchedule::compute(120);
        $tz    = OnceSchedule::timezone();
        $now   = new \DateTime('now', $tz);
        $year  = (int) $now->format('Y');

        $parts = preg_split('/\s+/', trim($expr));
        [$min, $hour, $dom, $month] = $parts;

        $scheduled = \DateTime::createFromFormat(
            'Y-m-d H:i',
            sprintf('%04d-%02d-%02d %02d:%02d', $year, (int) $month, (int) $dom, (int) $hour, (int) $min),
            $tz
        );
        $this->assertNotFalse($scheduled);

        $twoHoursFromNow = (clone $now)->modify('+119 minutes');
        $this->assertGreaterThan(
            $twoHoursFromNow->format('U'),
            $scheduled->format('U'),  // @phpstan-ignore-line
            'A 120-minute delay must be scheduled at least ~2 hours from now'
        );
    }

    // -------------------------------------------------------------------------
    // timezone()
    // -------------------------------------------------------------------------

    public function testTimezoneReturnsDateTimeZone(): void
    {
        $tz = OnceSchedule::timezone();
        $this->assertInstanceOf(\DateTimeZone::class, $tz);
    }

    public function testTimezoneNameIsNonEmpty(): void
    {
        $tz = OnceSchedule::timezone();
        $this->assertNotEmpty($tz->getName());
    }
}
