<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – InfluxDB Writer
 *
 * Writes a single cron_execution data point to InfluxDB 2.x via the HTTP
 * write API using InfluxDB line protocol. Guzzle is used for the HTTP call
 * (already a project dependency via composer).
 *
 * Line protocol format:
 *   cron_execution,job_id=42,status=success,... duration_seconds=3.14,exit_code=0i <ns_timestamp>
 *
 * Tags (indexed, string):
 *   job_id, description, linux_user, target, status, job_tags
 *
 * Fields (values):
 *   duration_seconds (float), exit_code (int), output_length (int), during_maintenance (int)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Influx;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Noodlehaus\Config;

/**
 * Class InfluxWriter
 *
 * Sends execution metrics to InfluxDB 2.x using the line protocol write API.
 */
final class InfluxWriter
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Logger $logger Monolog logger instance.
     * @param Config $config Noodlehaus configuration.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly Config $config,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Write a single cron execution data point to InfluxDB.
     *
     * Expected keys in $data:
     *   job_id (int), description (string), linux_user (string), target (string),
     *   exit_code (int), output_length (int), duration_seconds (float),
     *   during_maintenance (int), job_tags (string), finished_at_ns (int)
     *
     * @param array<string, mixed> $data Execution data to write.
     *
     * @return bool True on success, false on failure.
     */
    public function writeExecution(array $data): bool
    {
        if (!(bool) $this->config->get('influxdb.enabled', false)) {
            return false;
        }

        $url    = rtrim((string) $this->config->get('influxdb.url',    'http://influxdb:8086'), '/');
        $token  = (string) $this->config->get('influxdb.token',  '');
        $org    = (string) $this->config->get('influxdb.org',    '');
        $bucket = (string) $this->config->get('influxdb.bucket', 'cronmanager');
        $timeout = (int)   $this->config->get('influxdb.timeout', 10);

        if ($token === '' || $org === '') {
            $this->logger->warning('InfluxWriter: token or org not configured – skipping write');
            return false;
        }

        $line = $this->buildLineProtocol($data);

        if ($line === '') {
            $this->logger->warning('InfluxWriter: empty line protocol – skipping write');
            return false;
        }

        $endpoint = sprintf(
            '%s/api/v2/write?org=%s&bucket=%s&precision=ns',
            $url,
            rawurlencode($org),
            rawurlencode($bucket),
        );

        try {
            $client = new Client(['timeout' => $timeout]);
            $client->post($endpoint, [
                'headers' => [
                    'Authorization' => 'Token ' . $token,
                    'Content-Type'  => 'text/plain; charset=utf-8',
                ],
                'body' => $line,
            ]);

            $this->logger->debug('InfluxWriter: data point written', [
                'job_id' => $data['job_id'] ?? null,
                'bucket' => $bucket,
            ]);

            return true;

        } catch (GuzzleException $e) {
            $this->logger->error('InfluxWriter: failed to write to InfluxDB', [
                'message' => $e->getMessage(),
                'url'     => $endpoint,
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build an InfluxDB line protocol string from the execution data.
     *
     * @param array<string, mixed> $data Execution data.
     *
     * @return string Line protocol string, or empty string on error.
     */
    private function buildLineProtocol(array $data): string
    {
        $exitCode          = (int)    ($data['exit_code']          ?? 0);
        $durationSeconds   = (float)  ($data['duration_seconds']   ?? 0.0);
        $outputLength      = (int)    ($data['output_length']      ?? 0);
        $duringMaintenance = (int)    ($data['during_maintenance'] ?? 0);
        $finishedAtNs      = (int)    ($data['finished_at_ns']     ?? 0);

        $tags = [
            'job_id'      => (string) ($data['job_id']      ?? '0'),
            'description' => (string) ($data['description'] ?? ''),
            'linux_user'  => (string) ($data['linux_user']  ?? ''),
            'target'      => (string) ($data['target']      ?? 'local'),
            'status'      => $this->deriveStatus($exitCode),
            'job_tags'    => (string) ($data['job_tags']    ?? ''),
        ];

        // Remove empty tags – InfluxDB rejects empty tag values
        $tags = array_filter($tags, static fn(string $v): bool => $v !== '');

        $tagStr = '';
        foreach ($tags as $k => $v) {
            $tagStr .= ',' . $this->escapeTagKey($k) . '=' . $this->escapeTagValue($v);
        }

        $fields = sprintf(
            'duration_seconds=%s,exit_code=%di,output_length=%di,during_maintenance=%di',
            $this->formatFloat($durationSeconds),
            $exitCode,
            $outputLength,
            $duringMaintenance,
        );

        return sprintf('cron_execution%s %s %d', $tagStr, $fields, $finishedAtNs);
    }

    /**
     * Derive a human-readable status string from an exit code.
     *
     * @param int $exitCode Raw exit code from execution_log.
     *
     * @return string Status string for use as an InfluxDB tag value.
     */
    private function deriveStatus(int $exitCode): string
    {
        return match ($exitCode) {
            0    => 'success',
            -2   => 'killed',
            -3   => 'limit_exceeded',
            -4   => 'maintenance',
            -5   => 'interrupted',
            default => 'failed',
        };
    }

    /**
     * Format a float value for InfluxDB line protocol (no scientific notation).
     *
     * @param float $value Value to format.
     *
     * @return string Formatted float string.
     */
    private function formatFloat(float $value): string
    {
        // Ensure at least one decimal place so InfluxDB treats it as a float field.
        // rtrim zeros may leave a trailing dot (e.g. "3.") – append "0" in that case.
        $str = rtrim(number_format($value, 6, '.', ''), '0');
        return str_ends_with($str, '.') ? $str . '0' : $str;
    }

    /**
     * Escape a tag key for InfluxDB line protocol.
     * Escapes: comma, equals sign, space.
     *
     * @param string $key Tag key string.
     *
     * @return string Escaped tag key.
     */
    private function escapeTagKey(string $key): string
    {
        return str_replace([',', '=', ' '], ['\,', '\=', '\ '], $key);
    }

    /**
     * Escape a tag value for InfluxDB line protocol.
     * Escapes: comma, equals sign, space.
     *
     * @param string $value Tag value string.
     *
     * @return string Escaped tag value.
     */
    private function escapeTagValue(string $value): string
    {
        return str_replace([',', '=', ' '], ['\,', '\=', '\ '], $value);
    }
}
