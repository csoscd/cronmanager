<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – TelegramNotifier
 *
 * Sends failure-alert messages via the Telegram Bot API whenever a managed
 * cron job exits with a non-zero exit code and the job has `notify_on_failure`
 * set.
 *
 * Telegram configuration is read from the agent's Noodlehaus\Config object
 * under the `telegram.*` namespace:
 *
 *   telegram.enabled   – (bool)   Master switch; no message is sent when false.
 *   telegram.bot_token – (string) Telegram Bot API token (from @BotFather).
 *   telegram.chat_id   – (string) Target chat/channel/group ID.
 *   telegram.timeout   – (int)    HTTP request timeout in seconds (default: 15).
 *
 * Messages are formatted in Telegram's HTML parse mode using supported tags
 * (<b>, <i>, <pre>).  All user-supplied content is HTML-escaped before
 * embedding.  The total message is capped at Telegram's 4 096-character limit;
 * job output is pre-truncated to 2 000 characters to leave headroom for the
 * metadata block.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Notification;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Noodlehaus\Config;

/**
 * Class TelegramNotifier
 *
 * Encapsulates all Telegram notification logic for the Cronmanager agent.
 * Instances are constructed with a shared logger and config object and are
 * meant to be reused for the duration of the PHP process.
 */
final class TelegramNotifier
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /**
     * Base URL for the Telegram Bot API.  The bot token is appended at runtime.
     *
     * @var string
     */
    private const API_BASE = 'https://api.telegram.org/bot';

    /**
     * Maximum number of job-output characters to include in the message.
     * Kept deliberately short so the metadata block always fits within
     * Telegram's hard 4 096-character message limit.
     *
     * @var int
     */
    private const MAX_OUTPUT_LENGTH = 2000;

    /**
     * Telegram's hard upper bound on message length (in characters).
     *
     * @var int
     */
    private const MAX_MESSAGE_LENGTH = 4096;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * TelegramNotifier constructor.
     *
     * @param Logger $logger Monolog logger instance for diagnostic messages.
     * @param Config $config Noodlehaus Config loaded from the agent config.json.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly Config $config,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Send a failure alert for a cron job via Telegram.
     *
     * Does nothing and returns false if Telegram is disabled in config or if
     * bot_token / chat_id are not configured.
     *
     * @param int    $jobId       The cron job ID.
     * @param string $description Job description (or command if no description).
     * @param string $linuxUser   The Linux user the job ran as.
     * @param string $schedule    The cron schedule expression.
     * @param int    $exitCode    The non-zero exit code (or -2/-3 sentinel).
     * @param string $output      The captured stdout/stderr output.
     * @param string $startedAt   ISO 8601 / MySQL datetime start timestamp.
     * @param string $finishedAt  ISO 8601 / MySQL datetime finish (or notification) timestamp.
     *
     * @return bool True if the message was successfully delivered to the Bot API,
     *              false if Telegram is disabled, misconfigured, or an error occurred.
     */
    public function sendFailureAlert(
        int    $jobId,
        string $description,
        string $linuxUser,
        string $schedule,
        int    $exitCode,
        string $output,
        string $startedAt,
        string $finishedAt,
        int    $notifyAfterFailures = 1,
    ): bool {
        // ------------------------------------------------------------------
        // Guard: respect the master telegram.enabled switch
        // ------------------------------------------------------------------

        $enabled = (bool) $this->config->get('telegram.enabled', false);

        if (!$enabled) {
            $this->logger->debug('TelegramNotifier: disabled in config, skipping alert', [
                'job_id'    => $jobId,
                'exit_code' => $exitCode,
            ]);

            return false;
        }

        // ------------------------------------------------------------------
        // Read Telegram configuration
        // ------------------------------------------------------------------

        $botToken = (string) $this->config->get('telegram.bot_token', '');
        $chatId   = (string) $this->config->get('telegram.chat_id',   '');
        $timeout  = (int)    $this->config->get('telegram.timeout',   15);

        if ($botToken === '' || $chatId === '') {
            $this->logger->warning('TelegramNotifier: bot_token or chat_id not configured, skipping alert', [
                'job_id' => $jobId,
            ]);

            return false;
        }

        // ------------------------------------------------------------------
        // Build message text
        // ------------------------------------------------------------------

        // Truncate output before building the message so the pre block has a
        // predictable maximum size.
        $truncatedOutput = \mb_strlen($output) > self::MAX_OUTPUT_LENGTH
            ? \mb_substr($output, 0, self::MAX_OUTPUT_LENGTH) . "\n[... output truncated]"
            : $output;

        $text = $this->buildMessage(
            $jobId,
            $description,
            $linuxUser,
            $schedule,
            $exitCode,
            $truncatedOutput,
            $startedAt,
            $finishedAt,
            $notifyAfterFailures,
        );

        // ------------------------------------------------------------------
        // Send via Telegram Bot API
        // ------------------------------------------------------------------

        try {
            $client = new Client(['timeout' => $timeout]);

            $client->post(
                self::API_BASE . $botToken . '/sendMessage',
                [
                    'json' => [
                        'chat_id'    => $chatId,
                        'text'       => $text,
                        'parse_mode' => 'HTML',
                    ],
                ]
            );

            $this->logger->info('TelegramNotifier: alert sent', [
                'job_id'    => $jobId,
                'exit_code' => $exitCode,
            ]);

            return true;

        } catch (GuzzleException $e) {
            $this->logger->error('TelegramNotifier: failed to send alert', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Test API
    // -------------------------------------------------------------------------

    /**
     * Send a test message through the configured Telegram bot.
     *
     * Unlike sendFailureAlert() this method is always attempted (the caller is
     * responsible for checking telegram.enabled) and returns a structured result
     * so that error details can be surfaced to the UI.
     *
     * @return array{success: true}|array{success: false, message: string}
     */
    public function sendTest(): array
    {
        $botToken = (string) $this->config->get('telegram.bot_token', '');
        $chatId   = (string) $this->config->get('telegram.chat_id',   '');
        $timeout  = (int)    $this->config->get('telegram.timeout',   15);

        if ($botToken === '' || $chatId === '') {
            return ['success' => false, 'message' => 'bot_token or chat_id is not configured in the agent config.'];
        }

        $now  = date('Y-m-d H:i:s');
        $text = "\u{2709} <b>Cronmanager \u{2013} Test Notification</b>\n\n"
              . "This is a test message sent from the <b>Cronmanager Maintenance</b> page.\n"
              . "If you received it, your Telegram configuration is working correctly.\n\n"
              . "<b>Sent at:</b> " . htmlspecialchars($now, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        try {
            $client = new Client(['timeout' => $timeout]);

            $client->post(
                self::API_BASE . $botToken . '/sendMessage',
                [
                    'json' => [
                        'chat_id'    => $chatId,
                        'text'       => $text,
                        'parse_mode' => 'HTML',
                    ],
                ]
            );

            $this->logger->info('TelegramNotifier: test notification sent');

            return ['success' => true];

        } catch (GuzzleException $e) {
            $this->logger->warning('TelegramNotifier: test notification failed', [
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the HTML-formatted Telegram message.
     *
     * Telegram's HTML parse mode supports: <b>, <i>, <u>, <s>, <a>, <code>,
     * <pre>.  All dynamic values are passed through htmlspecialchars() to
     * prevent them from being interpreted as markup.
     *
     * The finished message is capped at MAX_MESSAGE_LENGTH characters to
     * satisfy Telegram's hard limit.
     *
     * @param int    $jobId
     * @param string $description
     * @param string $linuxUser
     * @param string $schedule
     * @param int    $exitCode
     * @param string $output      Already-truncated job output.
     * @param string $startedAt
     * @param string $finishedAt
     *
     * @return string HTML-formatted message string.
     */
    private function buildMessage(
        int    $jobId,
        string $description,
        string $linuxUser,
        string $schedule,
        int    $exitCode,
        string $output,
        string $startedAt,
        string $finishedAt,
        int    $notifyAfterFailures = 1,
    ): string {
        // Helper: escape a value for safe HTML embedding in Telegram messages
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Determine heading and emoji based on exit-code sentinel
        [$emoji, $title] = match (true) {
            $exitCode === -2 => ["\u{1F6AB}", 'Job Auto-Killed (Limit Exceeded)'],
            $exitCode === -3 => ["\u{23F0}",  'Job Execution Limit Exceeded'],
            default          => ["\u{26A0}",  'Job Failure Alert'],
        };

        // Exit-code line: show N/A for limit-exceeded alerts where the job is
        // still running and has no real exit code yet.
        $exitCodeDisplay = $exitCode === -3
            ? "<i>N/A \u{2013} job still running</i>"
            : sprintf('<b>%s</b>', $e((string) $exitCode));

        // Timestamp label: use "Notified At" for still-running jobs.
        $finishedLabel = $exitCode === -3 ? 'Notified At' : 'Finished';

        // Output block
        $outputBlock = $output !== ''
            ? sprintf("\n\n<b>Output:</b>\n<pre>%s</pre>", $e($output))
            : "\n\n<b>Output:</b> <i>(none)</i>";

        // "No further alerts" footer – only shown when threshold > 1
        $noFurtherAlertsBlock = $notifyAfterFailures > 1
            ? sprintf(
                "\n\n<i>\u{2139}\u{FE0F} Alert threshold reached (%d consecutive failures). "
                . "No further failure alerts will be sent until this job recovers.</i>",
                $notifyAfterFailures,
            )
            : '';

        $message = sprintf(
            "%s <b>Cronmanager \u{2013} %s</b>\n\n"
            . "<b>Job ID:</b> %s\n"
            . "<b>Description:</b> %s\n"
            . "<b>User:</b> %s\n"
            . "<b>Schedule:</b> %s\n"
            . "<b>Exit Code:</b> %s\n"
            . "<b>Started:</b> %s\n"
            . "<b>%s:</b> %s"
            . "%s"
            . "%s",
            $emoji,
            $e($title),
            $e((string) $jobId),
            $e($description),
            $e($linuxUser),
            $e($schedule),
            $exitCodeDisplay,
            $e($startedAt),
            $e($finishedLabel),
            $e($finishedAt),
            $outputBlock,
            $noFurtherAlertsBlock,
        );

        // Final safety cap: truncate to Telegram's hard 4 096-character limit
        if (\mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = \mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - 3) . '...';
        }

        return $message;
    }
}
