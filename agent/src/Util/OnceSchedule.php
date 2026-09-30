<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – OnceSchedule
 *
 * Centralises the computation of a full-date cron schedule string for
 * once-only entries (Run Now, dependency-triggered, retries).
 *
 * Using day-of-month and month in the expression means the entry fires at
 * most once per year even if the cleanup step fails — far safer than
 * "* * * * *".
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Util;

final class OnceSchedule
{
    /**
     * Return a full-date cron schedule string for a once-only entry.
     *
     * The schedule is computed relative to the current host time.
     * When we are within the last 10 seconds of a minute, cron may already
     * have processed the next minute by the time the crontab is written, so
     * an extra minute is added as a safety margin.
     *
     * @param int $delayMinutes Additional minutes to wait before firing.
     *                          0 = fire at the next available cron-minute.
     *
     * @return string Cron expression, e.g. "37 14 30 9 *".
     */
    public static function compute(int $delayMinutes = 0): string
    {
        $tz  = new \DateTimeZone(self::resolveSystemTimezone());
        $now = new \DateTime('now', $tz);

        $safetyMargin = (int) $now->format('s') > 50 ? 2 : 1;
        $totalMinutes = $delayMinutes + $safetyMargin;

        $next = (clone $now)->modify('+' . $totalMinutes . ' minutes');

        return sprintf(
            '%d %d %d %d *',
            (int) $next->format('i'),
            (int) $next->format('G'),
            (int) $next->format('j'),
            (int) $next->format('n'),
        );
    }

    /**
     * Return a DateTimeZone object for the host system timezone.
     *
     * Useful for callers that need the timezone independently of schedule
     * computation (e.g. for stale-entry timestamp comparisons).
     */
    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::resolveSystemTimezone());
    }

    /**
     * Resolve the host system timezone identifier.
     *
     * Detection order:
     *   1. TZ environment variable
     *   2. /etc/localtime symlink target
     *   3. /etc/timezone plain-text file
     *   4. PHP default timezone
     */
    private static function resolveSystemTimezone(): string
    {
        $envTz = getenv('TZ');
        if ($envTz !== false && $envTz !== '') {
            return $envTz;
        }

        $link = @readlink('/etc/localtime');
        if ($link !== false) {
            $pos = strpos($link, 'zoneinfo/');
            if ($pos !== false) {
                $tz = substr($link, $pos + strlen('zoneinfo/'));
                if ($tz !== '') {
                    return $tz;
                }
            }
        }

        if (is_readable('/etc/timezone')) {
            $tz = trim((string) file_get_contents('/etc/timezone'));
            if ($tz !== '') {
                return $tz;
            }
        }

        return date_default_timezone_get();
    }
}
