<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – AnsiStripper
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Util;

/**
 * Strips ANSI and OSC terminal escape sequences from a string.
 *
 * Remote shells often emit escape sequences from their startup files
 * (.bashrc/.zshrc) even during non-interactive SSH commands.  These
 * sequences must be removed before storing or comparing command output.
 *
 * Handled sequence types:
 *   - CSI  ESC [ … final-byte   (colour, cursor, erase, …)
 *   - OSC  ESC ] … BEL|ST       (title, background colour, shell integration, …)
 *   - Fe   ESC @-_              (single-character escape sequences)
 */
final class AnsiStripper
{
    /**
     * Regular expression that matches all three sequence families above.
     *
     * Groups:
     *   1. CSI: ESC [ <parameter bytes> <intermediate bytes> <final byte>
     *   2. OSC: ESC ] <any chars except BEL/ESC> (BEL | ESC \)
     *   3. Fe:  ESC followed by a single byte in 0x40–0x5F
     */
    private const PATTERN = '/\x1b(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07\x1b]*(?:\x07|\x1b\\\\)|[@-_])/';

    /**
     * Remove all ANSI/OSC escape sequences from the given string.
     *
     * @param string $text Raw text that may contain escape sequences.
     *
     * @return string Cleaned text with all escape sequences removed.
     */
    public static function strip(string $text): string
    {
        return (string) preg_replace(self::PATTERN, '', $text);
    }
}
