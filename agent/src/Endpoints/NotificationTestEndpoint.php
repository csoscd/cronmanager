<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – NotificationTestEndpoint
 *
 * Handles POST /maintenance/notification/test requests.
 *
 * Sends a synthetic test message through the requested notification channel
 * (email or Telegram) so administrators can verify their configuration without
 * waiting for a real job failure.
 *
 * Request body:
 * ```json
 * { "channel": "mail" }
 * ```
 * or
 * ```json
 * { "channel": "telegram" }
 * ```
 *
 * Response on success (HTTP 200):
 * ```json
 * { "success": true }
 * ```
 *
 * Response when the channel is disabled in config (HTTP 200):
 * ```json
 * { "success": false, "reason": "disabled" }
 * ```
 *
 * Response when the channel is enabled but the send attempt failed (HTTP 200):
 * ```json
 * { "success": false, "reason": "send_failed" }
 * ```
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Endpoints;

use Cronmanager\Agent\Notification\MailNotifier;
use Cronmanager\Agent\Notification\TelegramNotifier;
use Monolog\Logger;
use Noodlehaus\Config;

/**
 * Class NotificationTestEndpoint
 *
 * Sends a test notification via the configured mail or Telegram channel.
 */
final class NotificationTestEndpoint
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param MailNotifier     $mailNotifier     Mail notification service.
     * @param TelegramNotifier $telegramNotifier Telegram notification service.
     * @param Logger           $logger           Monolog logger instance.
     * @param Config           $config           Agent configuration.
     */
    public function __construct(
        private readonly MailNotifier     $mailNotifier,
        private readonly TelegramNotifier $telegramNotifier,
        private readonly Logger           $logger,
        private readonly Config           $config,
    ) {}

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Send a test notification.
     *
     * @param array<string, string> $params   Path parameters (unused).
     * @param array<string, mixed>  $body     Parsed JSON request body.
     */
    public function handle(array $params, array $body): void
    {
        $channel = strtolower(trim((string) ($body['channel'] ?? '')));

        if (!in_array($channel, ['mail', 'telegram'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid channel. Use "mail" or "telegram".']);
            return;
        }

        // ------------------------------------------------------------------
        // Synthetic test payload – clearly labelled as a test message
        // ------------------------------------------------------------------

        $now        = date('Y-m-d H:i:s');
        $jobId      = 0;
        $desc       = 'Cronmanager Test Notification';
        $linuxUser  = 'cronmanager';
        $schedule   = '* * * * *';
        $exitCode   = 1;
        $output     = 'This is a test notification sent from the Cronmanager Maintenance page. '
                    . 'If you received this message your notification configuration is working correctly.';

        // ------------------------------------------------------------------
        // Dispatch to the appropriate channel
        // ------------------------------------------------------------------

        if ($channel === 'mail') {
            $enabled = (bool) $this->config->get('mail.enabled', false);

            if (!$enabled) {
                $this->logger->info('NotificationTestEndpoint: mail test skipped – channel disabled');
                echo json_encode(['success' => false, 'reason' => 'disabled']);
                return;
            }

            $sent = $this->mailNotifier->sendFailureAlert(
                $jobId, $desc, $linuxUser, $schedule, $exitCode, $output, $now, $now
            );

        } else {
            $enabled = (bool) $this->config->get('telegram.enabled', false);

            if (!$enabled) {
                $this->logger->info('NotificationTestEndpoint: telegram test skipped – channel disabled');
                echo json_encode(['success' => false, 'reason' => 'disabled']);
                return;
            }

            $sent = $this->telegramNotifier->sendFailureAlert(
                $jobId, $desc, $linuxUser, $schedule, $exitCode, $output, $now, $now
            );
        }

        if ($sent) {
            $this->logger->info('NotificationTestEndpoint: test notification sent', [
                'channel' => $channel,
            ]);
            echo json_encode(['success' => true]);
        } else {
            $this->logger->warning('NotificationTestEndpoint: test notification failed to send', [
                'channel' => $channel,
            ]);
            echo json_encode(['success' => false, 'reason' => 'send_failed']);
        }
    }
}
