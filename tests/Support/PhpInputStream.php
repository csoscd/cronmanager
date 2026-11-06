<?php

declare(strict_types=1);

/**
 * Cronmanager – php://input stream wrapper for integration tests.
 *
 * Agent endpoints read the raw HTTP body via file_get_contents('php://input').
 * This class replaces the built-in php:// stream wrapper for the duration of
 * an endpoint call so that tests can inject arbitrary JSON bodies.
 *
 * Usage:
 *   PhpInputStream::set('{"job_id":1,"started_at":"2026-01-01T10:00:00Z"}');
 *   $endpoint->handle([]);
 *   PhpInputStream::restore();
 *
 * Only `php://input` is intercepted; all other `php://` paths are handled via
 * a minimal passthrough (they are not used inside endpoint handle() calls).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Support;

/**
 * Class PhpInputStream
 *
 * Replaces the built-in `php://` protocol handler with a minimal wrapper
 * that serves controllable content for `php://input`.
 */
final class PhpInputStream
{
    // -------------------------------------------------------------------------
    // Static state shared across all stream instances
    // -------------------------------------------------------------------------

    private static string $content  = '';
    private static bool   $replaced = false;

    // -------------------------------------------------------------------------
    // Instance state (one per stream_open call)
    // -------------------------------------------------------------------------

    /**
     * Stream context resource – PHP's stream wrapper mechanism sets this
     * automatically; must be declared explicitly to avoid a dynamic-property
     * deprecation in PHP 8.2+.
     *
     * @var resource|null
     */
    public $context;

    private int $position = 0;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Set the content that `php://input` will return and activate the wrapper.
     *
     * @param string $content Raw body content (usually a JSON string).
     */
    public static function set(string $content): void
    {
        self::$content  = $content;
        self::$replaced = false;

        if (in_array('php', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('php');
            self::$replaced = true;
        }

        stream_wrapper_register('php', self::class);
    }

    /**
     * Restore the built-in `php://` stream wrapper.
     *
     * Only acts when set() was actually called ($replaced is true).
     * Unregisters PhpInputStream, then re-instates the built-in handler so
     * that subsequent code (e.g. GuzzleHttp PSR7 Utils::streamFor) can open
     * php://temp and php://memory normally again.
     */
    public static function restore(): void
    {
        if (self::$replaced) {
            // Remove our handler from the 'php' slot.
            if (in_array('php', stream_get_wrappers(), true)) {
                stream_wrapper_unregister('php');
            }
            // Re-instate the built-in php:// handler.
            stream_wrapper_restore('php');
        }

        self::$content  = '';
        self::$replaced = false;
    }

    // -------------------------------------------------------------------------
    // Stream wrapper interface methods
    // -------------------------------------------------------------------------

    /** @param resource $context */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->stream_eof()) {
            return false;
        }

        $chunk          = substr(self::$content, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$content);
    }

    /**
     * No-op write: silences PHP warnings when Guzzle opens php://temp for the
     * request body while our wrapper is active. The MockHandler discards the
     * request body anyway, so silent loss is harmless in tests.
     */
    public function stream_write(string $data): int
    {
        return strlen($data);
    }

    /**
     * No-op seek: silences PHP warnings from Guzzle's stream utilities.
     * Returns false so callers know seeking is unsupported.
     */
    public function stream_seek(int $offset, int $whence): bool
    {
        return false;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return [];
    }
}
