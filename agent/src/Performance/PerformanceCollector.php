<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – PerformanceCollector
 *
 * Singleton that accumulates per-request timing data:
 *   - Total request duration (measured from the moment configure() records the
 *     start timestamp until collectForResponse() is called inside jsonResponse()).
 *   - Aggregated database query time and query count (each timed DB execution
 *     calls addDbQuery() via Connection::timedExecute()).
 *
 * Behaviour is controlled by two flags set from agent_settings:
 *   persist_data      → write a row to performance_log on every request
 *   show_in_frontend  → include a _perf key in every JSON response
 *
 * Both flags are off by default (zero-cost when the feature is disabled).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Performance;

/**
 * Class PerformanceCollector
 *
 * Singleton timing accumulator for the Performance Monitor feature.
 * Thread-safety is not required because the agent is single-threaded.
 */
final class PerformanceCollector
{
    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null The single class instance. */
    private static ?self $instance = null;

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** Whether configure() has been called for the current request. */
    private bool $configured = false;

    /** Whether to persist timing data to the performance_log table. */
    private bool $persist = false;

    /** Whether to include _perf in every JSON response. */
    private bool $showInFrontend = false;

    /** microtime(true) captured at the start of the request. */
    private float $requestStart = 0.0;

    /** Agent endpoint path for the current request (e.g. /crons). */
    private string $endpoint = '';

    /** Accumulated DB query duration in milliseconds. */
    private float $dbMs = 0.0;

    /** Number of timed DB queries in the current request. */
    private int $dbQueries = 0;

    // -------------------------------------------------------------------------
    // Constructor (private – singleton)
    // -------------------------------------------------------------------------

    private function __construct() {}

    // -------------------------------------------------------------------------
    // Singleton access
    // -------------------------------------------------------------------------

    /** Return (or create) the singleton instance. */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * Activate the collector for the current request.
     *
     * Must be called after the agent_settings performance_monitor section is
     * available (i.e. after DbConfig is initialised in agent.php).
     *
     * @param bool   $persist        Write a row to performance_log.
     * @param bool   $showInFrontend Include _perf in JSON responses.
     * @param float  $requestStart   microtime(true) captured before routing.
     * @param string $endpoint       Request path (e.g. '/crons').
     */
    public function configure(
        bool   $persist,
        bool   $showInFrontend,
        float  $requestStart,
        string $endpoint,
    ): void {
        $this->persist        = $persist;
        $this->showInFrontend = $showInFrontend;
        $this->requestStart   = $requestStart;
        $this->endpoint       = $endpoint;
        $this->configured     = true;
    }

    // -------------------------------------------------------------------------
    // Accumulation
    // -------------------------------------------------------------------------

    /**
     * Record the duration of a single timed DB query.
     *
     * Called by Connection::timedExecute() after every measured execute() call.
     *
     * @param float $ms Duration in milliseconds.
     */
    public function addDbQuery(float $ms): void
    {
        $this->dbMs     += $ms;
        $this->dbQueries++;
    }

    // -------------------------------------------------------------------------
    // Collection
    // -------------------------------------------------------------------------

    /**
     * Collect timing data at the end of the request.
     *
     * Called from jsonResponse() in agent.php immediately before the response
     * is encoded and sent. If persistence is enabled a row is written to
     * performance_log. If frontend display is enabled the timing array is
     * returned for injection into the JSON body as _perf; otherwise null.
     *
     * @return array{request_ms: float, db_ms: float, db_queries: int}|null
     *         Performance payload for _perf, or null when display is disabled.
     */
    public function collectForResponse(): ?array
    {
        if (!$this->configured) {
            return null;
        }

        $requestMs = (microtime(true) - $this->requestStart) * 1000.0;

        if ($this->persist) {
            $this->writeToDb($requestMs);
        }

        if ($this->showInFrontend) {
            return [
                'request_ms' => round($requestMs, 2),
                'db_ms'      => round($this->dbMs, 2),
                'db_queries' => $this->dbQueries,
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Accessors (for unit tests)
    // -------------------------------------------------------------------------

    /** Whether configure() has been called. */
    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /** Accumulated DB time in ms. */
    public function getDbMs(): float
    {
        return $this->dbMs;
    }

    /** Number of recorded DB queries. */
    public function getDbQueries(): int
    {
        return $this->dbQueries;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Insert a performance_log row.
     *
     * Non-fatal: if the table does not exist (migration not yet applied) the
     * exception is silently swallowed so the agent response is never blocked.
     *
     * @param float $requestMs Total request duration in milliseconds.
     */
    private function writeToDb(float $requestMs): void
    {
        try {
            $pdo  = \Cronmanager\Agent\Database\Connection::getInstance()->getPdo();
            $stmt = $pdo->prepare(
                'INSERT INTO performance_log (endpoint, request_ms, db_ms, db_queries, created_at)
                 VALUES (:endpoint, :request_ms, :db_ms, :db_queries, NOW())'
            );
            $stmt->execute([
                ':endpoint'   => substr($this->endpoint, 0, 255),
                ':request_ms' => round($requestMs, 3),
                ':db_ms'      => round($this->dbMs, 3),
                ':db_queries' => $this->dbQueries,
            ]);
        } catch (\Throwable) {
            // Non-fatal: table absent before migration — skip silently.
        }
    }

    // -------------------------------------------------------------------------
    // Prevent cloning / unserialization
    // -------------------------------------------------------------------------

    /** @codeCoverageIgnore */
    private function __clone() {}

    /** @throws \RuntimeException Always. */
    public function __wakeup(): never
    {
        throw new \RuntimeException('Cannot unserialize a singleton.');
    }
}
