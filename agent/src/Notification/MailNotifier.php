<?php

declare(strict_types=1);

/**
 * Cronmanager Host Agent – MailNotifier
 *
 * Sends failure-alert emails via PHPMailer/SMTP whenever a managed cron job
 * exits with a non-zero exit code and the job has `notify_on_failure` set.
 *
 * Mail configuration is read from the agent's Noodlehaus\Config object under
 * the `mail.*` namespace:
 *
 *   mail.enabled    – (bool)   Master switch; no mail is sent when false.
 *   mail.host       – (string) SMTP server hostname.
 *   mail.port       – (int)    SMTP port (typically 587 for STARTTLS, 465 for SSL).
 *   mail.username   – (string) SMTP authentication user.
 *   mail.password   – (string) SMTP authentication password.
 *   mail.from       – (string) Sender e-mail address.
 *   mail.from_name  – (string) Sender display name.
 *   mail.to         – (string) Recipient e-mail address.
 *   mail.encryption – (string) Transport encryption: "tls" (STARTTLS) or "ssl" (SMTPS).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Agent\Notification;

use Monolog\Logger;
use Noodlehaus\ConfigInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Class MailNotifier
 *
 * Encapsulates all e-mail notification logic for the Cronmanager agent.
 * Instances are constructed with a shared logger and config object and are
 * meant to be reused for the duration of the PHP process.
 */
final class MailNotifier
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * MailNotifier constructor.
     *
     * @param Logger          $logger Monolog logger instance for diagnostic messages.
     * @param ConfigInterface $config Agent configuration (file-based or DB-backed).
     */
    public function __construct(
        private readonly Logger          $logger,
        private readonly ConfigInterface $config,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Send a failure alert for a cron job.
     *
     * Does nothing and returns false if mail is disabled in config.
     *
     * @param int    $jobId       The cron job ID.
     * @param string $description Job description (or command if no description).
     * @param string $linuxUser   The Linux user the job ran as.
     * @param string $schedule    The cron schedule expression.
     * @param int    $exitCode    The non-zero exit code.
     * @param string $output      The captured stdout/stderr output.
     * @param string $startedAt   ISO 8601 start timestamp.
     * @param string $finishedAt  ISO 8601 finish timestamp.
     *
     * @return bool True if the mail was successfully submitted to the SMTP server,
     *              false if mail is disabled or an error occurred.
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
        string $target = '',
        bool   $stillRunning = false,
    ): bool {
        // ------------------------------------------------------------------
        // Guard: respect the master mail.enabled switch
        // ------------------------------------------------------------------

        $enabled = (bool) $this->config->get('mail.enabled', false);

        if (!$enabled) {
            $this->logger->debug('MailNotifier: mail disabled in config, skipping failure alert', [
                'job_id'    => $jobId,
                'exit_code' => $exitCode,
            ]);

            return false;
        }

        // ------------------------------------------------------------------
        // Read mail configuration
        // ------------------------------------------------------------------

        $host        = (string) $this->config->get('mail.host',         'smtp.example.com');
        $port        = (int)    $this->config->get('mail.port',         587);
        $username    = (string) $this->config->get('mail.username',     '');
        $password    = (string) $this->config->get('mail.password',     '');
        $from        = (string) $this->config->get('mail.from',         '');
        $fromName    = (string) $this->config->get('mail.from_name',    'Cronmanager');
        $to          = (string) $this->config->get('mail.to',           '');
        $encryption  = strtolower((string) $this->config->get('mail.encryption',   'tls'));
        $smtpTimeout = (int)    $this->config->get('mail.smtp_timeout', 15);

        // ------------------------------------------------------------------
        // Build message content
        // ------------------------------------------------------------------

        // Truncate output to 10 000 characters to keep the mail readable
        $truncatedOutput = \mb_strlen($output) > 10000
            ? \mb_substr($output, 0, 10000) . "\n\n[... output truncated to 10 000 characters ...]"
            : $output;

        $subject = match (true) {
            $exitCode === -2                    => sprintf('[Cronmanager] Job #%d AUTO-KILLED (limit exceeded): %s', $jobId, $description),
            $exitCode === -3 && $stillRunning   => sprintf('[Cronmanager] Job #%d LIMIT EXCEEDED (still running): %s', $jobId, $description),
            $exitCode === -3                    => sprintf('[Cronmanager] Job #%d LIMIT EXCEEDED (at finish): %s', $jobId, $description),
            default                            => sprintf('[Cronmanager] Job #%d FAILED (exit %d): %s', $jobId, $exitCode, $description),
        };

        $plainBody = $this->buildPlainBody(
            $jobId,
            $description,
            $linuxUser,
            $schedule,
            $exitCode,
            $truncatedOutput,
            $startedAt,
            $finishedAt,
            $notifyAfterFailures,
            $target,
            $stillRunning,
        );

        $htmlBody = $this->buildHtmlBody(
            $jobId,
            $description,
            $linuxUser,
            $schedule,
            $exitCode,
            $truncatedOutput,
            $startedAt,
            $finishedAt,
            $notifyAfterFailures,
            $target,
            $stillRunning,
        );

        // ------------------------------------------------------------------
        // Send via PHPMailer
        // ------------------------------------------------------------------

        try {
            $mail = new PHPMailer(exceptions: true);

            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $username;
            $mail->Password   = $password;
            $mail->Timeout    = $smtpTimeout;
            $mail->SMTPSecure = ($encryption === 'ssl')
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->setFrom($from, $fromName);
            $mail->addAddress($to);

            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $plainBody;

            $mail->send();

            $this->logger->info('MailNotifier: failure alert sent', [
                'job_id'    => $jobId,
                'to'        => $to,
                'exit_code' => $exitCode,
            ]);

            return true;

        } catch (MailerException $e) {
            $this->logger->error('MailNotifier: failed to send failure alert', [
                'job_id'    => $jobId,
                'message'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Test API
    // -------------------------------------------------------------------------

    /**
     * Send a test e-mail through the configured SMTP server.
     *
     * Unlike sendFailureAlert() this method is always attempted (the caller is
     * responsible for checking mail.enabled) and returns a structured result
     * instead of a plain bool so that error details can be surfaced to the UI.
     *
     * @return array{success: true}|array{success: false, message: string}
     */

    /**
     * Send a recovery notification when a job succeeds after a notified failure streak.
     *
     * @param int    $jobId               The cron job ID.
     * @param string $description         Job description or command.
     * @param string $linuxUser           Linux user the job runs as.
     * @param string $schedule            Cron schedule expression.
     * @param int    $consecutiveFailures Number of consecutive failures before this recovery.
     * @param string $startedAt           Start timestamp of the successful execution.
     * @param string $finishedAt          Finish timestamp of the successful execution.
     * @param string $target              Execution target.
     *
     * @return bool True if the mail was submitted, false if mail is disabled or an error occurred.
     */
    public function sendRecoveryAlert(
        int    $jobId,
        string $description,
        string $linuxUser,
        string $schedule,
        int    $consecutiveFailures,
        string $startedAt,
        string $finishedAt,
        string $target = '',
    ): bool {
        $enabled = (bool) $this->config->get('mail.enabled', false);

        if (!$enabled) {
            $this->logger->debug('MailNotifier: mail disabled in config, skipping recovery alert', [
                'job_id' => $jobId,
            ]);
            return false;
        }

        $host        = (string) $this->config->get('mail.host',         'smtp.example.com');
        $port        = (int)    $this->config->get('mail.port',         587);
        $user        = (string) $this->config->get('mail.username',     '');
        $pass        = (string) $this->config->get('mail.password',     '');
        $fromAddr    = (string) $this->config->get('mail.from',      'cronmanager@example.com');
        $fromName    = (string) $this->config->get('mail.from_name', 'Cronmanager');
        $toAddr      = (string) $this->config->get('mail.to',        '');
        $encryption  = (string) $this->config->get('mail.encryption',   'tls');
        $baseUrl     = rtrim((string) $this->config->get('notifications.web_url', ''), '/');

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = ($user !== '');
            $mail->Username   = $user;
            $mail->Password   = $pass;
            $mail->SMTPSecure = $encryption === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $port;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = (int) $this->config->get('mail.smtp_timeout', 15);

            $mail->setFrom($fromAddr, $fromName);
            $mail->addAddress($toAddr);
            $mail->Subject = sprintf('[Cronmanager] Job #%d RECOVERED: %s', $jobId, $description);

            $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

            $mail->Body    = $this->buildRecoveryHtmlBody($jobId, $description, $linuxUser, $schedule,
                                 $consecutiveFailures, $startedAt, $finishedAt, $target, $baseUrl, $e);
            $mail->AltBody = $this->buildRecoveryPlainBody($jobId, $description, $linuxUser, $schedule,
                                 $consecutiveFailures, $startedAt, $finishedAt, $target, $baseUrl);
            $mail->isHTML(true);
            $mail->send();

            $this->logger->info('MailNotifier: recovery alert sent', [
                'job_id' => $jobId,
                'to'     => $toAddr,
            ]);

            return true;
        } catch (\Throwable $ex) {
            $this->logger->error('MailNotifier: failed to send recovery alert', [
                'job_id'  => $jobId,
                'message' => $ex->getMessage(),
            ]);
            return false;
        }
    }

    public function sendSilenceAlert(
        int     $jobId,
        string  $description,
        string  $schedule,
        ?string $lastStartedAt,
        string  $expectedLastRun,
        int     $silenceSinceMinutes,
        string  $target = '',
    ): bool {
        $enabled = (bool) $this->config->get('mail.enabled', false);

        if (!$enabled) {
            $this->logger->debug('MailNotifier: mail disabled in config, skipping silence alert', [
                'job_id' => $jobId,
            ]);
            return false;
        }

        $host        = (string) $this->config->get('mail.host',         'smtp.example.com');
        $port        = (int)    $this->config->get('mail.port',         587);
        $user        = (string) $this->config->get('mail.username',     '');
        $pass        = (string) $this->config->get('mail.password',     '');
        $fromAddr    = (string) $this->config->get('mail.from',      'cronmanager@example.com');
        $fromName    = (string) $this->config->get('mail.from_name', 'Cronmanager');
        $toAddr      = (string) $this->config->get('mail.to',        '');
        $encryption  = (string) $this->config->get('mail.encryption',   'tls');
        $baseUrl     = rtrim((string) $this->config->get('notifications.web_url', ''), '/');

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = ($user !== '');
            $mail->Username   = $user;
            $mail->Password   = $pass;
            $mail->SMTPSecure = $encryption === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $port;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = (int) $this->config->get('mail.smtp_timeout', 15);

            $mail->setFrom($fromAddr, $fromName);
            $mail->addAddress($toAddr);
            $mail->Subject = sprintf('[Cronmanager] Job #%d SILENT: %s', $jobId, $description);

            $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

            $mail->Body    = $this->buildSilenceHtmlBody($jobId, $description, $schedule,
                                 $lastStartedAt, $expectedLastRun, $silenceSinceMinutes, $target, $baseUrl, $e);
            $mail->AltBody = $this->buildSilencePlainBody($jobId, $description, $schedule,
                                 $lastStartedAt, $expectedLastRun, $silenceSinceMinutes, $target, $baseUrl);
            $mail->isHTML(true);
            $mail->send();

            $this->logger->info('MailNotifier: silence alert sent', [
                'job_id' => $jobId,
                'to'     => $toAddr,
            ]);

            return true;
        } catch (\Throwable $ex) {
            $this->logger->error('MailNotifier: failed to send silence alert', [
                'job_id'  => $jobId,
                'message' => $ex->getMessage(),
            ]);
            return false;
        }
    }

    public function sendTest(): array
    {
        $host        = (string) $this->config->get('mail.host',         'smtp.example.com');
        $port        = (int)    $this->config->get('mail.port',         587);
        $username    = (string) $this->config->get('mail.username',     '');
        $password    = (string) $this->config->get('mail.password',     '');
        $from        = (string) $this->config->get('mail.from',         '');
        $fromName    = (string) $this->config->get('mail.from_name',    'Cronmanager');
        $to          = (string) $this->config->get('mail.to',           '');
        $encryption  = strtolower((string) $this->config->get('mail.encryption',   'tls'));
        $smtpTimeout = (int)    $this->config->get('mail.smtp_timeout', 15);

        $now = date('Y-m-d H:i:s');

        $plainBody = implode("\n", [
            'CRONMANAGER – TEST NOTIFICATION',
            str_repeat('=', 60),
            '',
            'This is a test message sent from the Cronmanager Maintenance',
            'page. If you received it, your e-mail configuration is working.',
            '',
            'Sent at: ' . $now,
            str_repeat('=', 60),
            'This message was generated automatically by Cronmanager.',
        ]);

        $htmlBody = <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Cronmanager – Test Notification</title>
<style>
body{font-family:Arial,sans-serif;font-size:14px;color:#333;background:#f4f4f4;margin:0;padding:20px}
.container{background:#fff;border-radius:6px;padding:24px 32px;max-width:600px;margin:0 auto;box-shadow:0 2px 6px rgba(0,0,0,.1)}
h1{color:#2563eb;font-size:20px;margin-top:0}
.footer{font-size:11px;color:#999;margin-top:20px}
</style></head><body>
<div class="container">
<h1>&#x2709; Cronmanager &ndash; Test Notification</h1>
<p>This is a test message sent from the <strong>Cronmanager Maintenance</strong> page.</p>
<p>If you received it, your e-mail configuration is working correctly.</p>
<p style="color:#666;font-size:13px">Sent at: {$now}</p>
<p class="footer">This message was generated automatically by Cronmanager.</p>
</div></body></html>
HTML;

        try {
            $mail = new PHPMailer(exceptions: true);

            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $username;
            $mail->Password   = $password;
            $mail->Timeout    = $smtpTimeout;
            $mail->SMTPSecure = ($encryption === 'ssl')
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($from, $fromName);
            $mail->addAddress($to);
            $mail->Subject = '[Cronmanager] Test Notification';
            $mail->Body    = $htmlBody;
            $mail->AltBody = $plainBody;

            $mail->send();

            $this->logger->info('MailNotifier: test notification sent', ['to' => $to]);

            return ['success' => true];

        } catch (MailerException $e) {
            $this->logger->warning('MailNotifier: test notification failed', [
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers – individual HTML table rows
    // -------------------------------------------------------------------------

    /**
     * Return the HTML table row for the exit code field.
     * For limit-exceeded alerts (exit code -3) the job is still running and
     * has no exit code yet; show a human-readable note instead of "-3".
     *
     * @param int      $exitCode
     * @param callable $e  HTML-escape callable.
     * @return string
     */
    private function exitCodeRow(int $exitCode, callable $e, bool $stillRunning = false): string
    {
        if ($exitCode === -3) {
            $text = $stillRunning
                ? 'N/A &ndash; job still running'
                : 'N/A &ndash; execution limit exceeded';

            return sprintf('<tr><th>Exit Code</th><td><em>%s</em></td></tr>', $text);
        }

        return sprintf(
            '<tr><th>Exit Code</th><td class="exit-bad">%s</td></tr>',
            $e((string) $exitCode)
        );
    }

    /**
     * Return the HTML table row for the finished/notification timestamp.
     * For limit-exceeded alerts (exit code -3) the job has not finished;
     * label the timestamp as "Notified At" to avoid implying completion.
     *
     * @param int      $exitCode
     * @param string   $finishedAt  Finish time (or notification time for -3).
     * @param callable $e           HTML-escape callable.
     * @return string
     */
    private function finishedRow(int $exitCode, string $finishedAt, callable $e, bool $stillRunning = false): string
    {
        $label = ($exitCode === -3 && $stillRunning) ? 'Notified At' : 'Finished';

        return sprintf('<tr><th>%s</th><td>%s</td></tr>', $label, $e($finishedAt));
    }

    // -------------------------------------------------------------------------
    // Private helpers – message body builders
    // -------------------------------------------------------------------------

    /**
     * Build the plain-text version of the failure alert.
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
     * @return string Plain-text e-mail body.
     */
    private function buildPlainBody(
        int    $jobId,
        string $description,
        string $linuxUser,
        string $schedule,
        int    $exitCode,
        string $output,
        string $startedAt,
        string $finishedAt,
        int    $notifyAfterFailures = 1,
        string $target = '',
        bool   $stillRunning = false,
    ): string {
        $headline = match (true) {
            $exitCode === -2                  => 'CRONMANAGER – JOB AUTO-KILLED (EXECUTION LIMIT EXCEEDED)',
            $exitCode === -3 && $stillRunning => 'CRONMANAGER – JOB EXECUTION LIMIT EXCEEDED (STILL RUNNING)',
            $exitCode === -3                  => 'CRONMANAGER – JOB EXECUTION LIMIT EXCEEDED (AT FINISH)',
            default                          => 'CRONMANAGER – JOB FAILURE ALERT',
        };

        $exitCodeLine = match (true) {
            $exitCode === -3 && $stillRunning => 'Exit Code  : N/A (job still running)',
            $exitCode === -3                  => 'Exit Code  : N/A (execution limit exceeded)',
            default                          => sprintf('Exit Code  : %d', $exitCode),
        };
        $finishedLine = ($exitCode === -3 && $stillRunning)
            ? sprintf('Notified At: %s', $finishedAt)
            : sprintf('Finished   : %s', $finishedAt);

        $lines = [
            $headline,
            str_repeat('=', 60),
            '',
            sprintf('Job ID     : %d',   $jobId),
            sprintf('Description: %s',   $description),
            sprintf('User       : %s',   $linuxUser),
            sprintf('Schedule   : %s',   $schedule),
            $exitCodeLine,
            sprintf('Started    : %s',   $startedAt),
            $finishedLine,
            '',
            str_repeat('-', 60),
            'OUTPUT:',
            str_repeat('-', 60),
            $output !== '' ? $output : '(no output)',
            '',
            str_repeat('=', 60),
        ];

        if ($notifyAfterFailures > 1) {
            $lines[] = sprintf(
                'NOTE: This alert was triggered after %d consecutive failures.',
                $notifyAfterFailures,
            );
            $lines[] = '      No further failure alerts will be sent until this job recovers.';
            $lines[] = '';
        }

        $webUrl = (string) $this->config->get('notifications.web_url', '');
        if ($webUrl !== '') {
            $link = $stillRunning
                ? rtrim($webUrl, '/') . '/crons/' . $jobId
                : rtrim($webUrl, '/') . '/timeline?job_id=' . $jobId . '&target=' . urlencode($target) . '&status=failed&_direct=1';
            $lines[] = 'View details: ' . $link;
            $lines[] = '';
        }

        $lines[] = 'This message was generated automatically by Cronmanager.';

        return implode("\n", $lines);
    }

    /**
     * Build the HTML version of the failure alert.
     *
     * Contains a styled table with job metadata and a <pre> block with the
     * captured output.
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
     * @return string HTML e-mail body.
     */
    private function buildHtmlBody(
        int    $jobId,
        string $description,
        string $linuxUser,
        string $schedule,
        int    $exitCode,
        string $output,
        string $startedAt,
        string $finishedAt,
        int    $notifyAfterFailures = 1,
        string $target = '',
        bool   $stillRunning = false,
    ): string {
        // Helper: escape a value for safe HTML embedding
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $outputHtml = $output !== '' ? $e($output) : '<em>(no output)</em>';

        $noFurtherAlertsHtml = $notifyAfterFailures > 1
            ? sprintf(
                '<div style="margin-top:16px;padding:10px 14px;background:#fef9c3;border-left:4px solid #ca8a04;border-radius:4px;font-size:13px;color:#713f12;">'
                . '<strong>&#x26A0; Alert threshold reached:</strong> This notification was triggered after <strong>%d consecutive failures</strong>. '
                . 'No further failure alerts will be sent until this job recovers successfully.</div>',
                $notifyAfterFailures,
            )
            : '';

        [$h1Text, $introText, $h1Color] = match (true) {
            $exitCode === -2 => [
                '&#x1F6AB; Cron Job Auto-Killed',
                'A managed cron job was automatically terminated because it exceeded its configured execution time limit.',
                '#e67e22',
            ],
            $exitCode === -3 && $stillRunning => [
                '&#x23F0; Cron Job Execution Limit Exceeded',
                'A managed cron job has been running longer than its configured execution time limit.',
                '#8e44ad',
            ],
            $exitCode === -3 => [
                '&#x23F0; Cron Job Execution Limit Exceeded (at Finish)',
                'A managed cron job exceeded its configured execution time limit and has since finished.',
                '#8e44ad',
            ],
            default => [
                '&#x26A0; Cron Job Failure Alert',
                'A managed cron job has exited with a non-zero exit code.',
                '#c0392b',
            ],
        };

        $webUrl  = (string) $this->config->get('notifications.web_url', '');
        $linkHtml = '';
        if ($webUrl !== '') {
            $link     = $stillRunning
                ? rtrim($webUrl, '/') . '/crons/' . $jobId
                : rtrim($webUrl, '/') . '/timeline?job_id=' . $jobId . '&target=' . urlencode($target) . '&status=failed&_direct=1';
            $linkHtml = sprintf(
                '<p style="margin-top:16px;"><a href="%s" style="display:inline-block;padding:8px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:4px;font-size:13px;">&#x1F517; View in Cronmanager</a></p>',
                $e($link),
            );
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cronmanager – Job Alert</title>
    <style>
        body      { font-family: Arial, sans-serif; font-size: 14px; color: #333; background: #f4f4f4; margin: 0; padding: 20px; }
        .container{ background: #fff; border-radius: 6px; padding: 24px 32px; max-width: 800px; margin: 0 auto; box-shadow: 0 2px 6px rgba(0,0,0,.1); }
        h1        { color: {$h1Color}; font-size: 20px; margin-top: 0; }
        table     { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th        { background: #f0f0f0; text-align: left; padding: 8px 12px; width: 160px; font-weight: 600; border: 1px solid #ddd; }
        td        { padding: 8px 12px; border: 1px solid #ddd; }
        .exit-bad { color: #c0392b; font-weight: bold; }
        pre       { background: #1e1e1e; color: #d4d4d4; padding: 14px; border-radius: 4px; white-space: pre-wrap; word-break: break-all; font-size: 12px; max-height: 500px; overflow: auto; }
        .footer   { font-size: 11px; color: #999; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h1>{$h1Text}</h1>
    <p>{$introText}</p>

    <table>
        <tr><th>Job ID</th>      <td>{$e((string)$jobId)}</td></tr>
        <tr><th>Description</th> <td>{$e($description)}</td></tr>
        <tr><th>User</th>        <td>{$e($linuxUser)}</td></tr>
        <tr><th>Schedule</th>    <td>{$e($schedule)}</td></tr>
        {$this->exitCodeRow($exitCode, $e, $stillRunning)}
        <tr><th>Started</th>     <td>{$e($startedAt)}</td></tr>
        {$this->finishedRow($exitCode, $finishedAt, $e, $stillRunning)}
    </table>

    <h2 style="font-size:15px; margin-bottom:6px;">Output</h2>
    <pre>{$outputHtml}</pre>

    {$noFurtherAlertsHtml}

    {$linkHtml}

    <p class="footer">This message was generated automatically by Cronmanager.</p>
</div>
</body>
</html>
HTML;
    }

    private function buildRecoveryPlainBody(
        int      $jobId,
        string   $description,
        string   $linuxUser,
        string   $schedule,
        int      $consecutiveFailures,
        string   $startedAt,
        string   $finishedAt,
        string   $target,
        string   $baseUrl,
    ): string {
        $lines = [
            'CRONMANAGER – JOB RECOVERED',
            str_repeat('=', 60),
            '',
            sprintf('Job ID      : %d', $jobId),
            sprintf('Description : %s', $description),
            sprintf('User        : %s', $linuxUser),
            sprintf('Schedule    : %s', $schedule),
        ];

        if ($target !== '' && $target !== 'local') {
            $lines[] = sprintf('Target      : %s', $target);
        }

        $lines[] = sprintf('Recovered   : %s', $finishedAt);
        $lines[] = sprintf('Previous failures: %d consecutive failure(s) before this successful run.', $consecutiveFailures);
        $lines[] = '';

        if ($baseUrl !== '') {
            $lines[] = sprintf('View in Cronmanager: %s/crons/%d', $baseUrl, $jobId);
        }

        return implode("\n", $lines);
    }

    private function buildSilencePlainBody(
        int     $jobId,
        string  $description,
        string  $schedule,
        ?string $lastStartedAt,
        string  $expectedLastRun,
        int     $silenceSinceMinutes,
        string  $target,
        string  $baseUrl,
    ): string {
        $lastStartLine = $lastStartedAt !== null
            ? sprintf('Last seen   : %s', $lastStartedAt)
            : 'Last seen   : never (job has not run yet)';

        $hours   = intdiv($silenceSinceMinutes, 60);
        $minutes = $silenceSinceMinutes % 60;
        $silent  = $hours > 0
            ? sprintf('%dh %02dm', $hours, $minutes)
            : sprintf('%dm', $minutes);

        $lines = [
            'CRONMANAGER – JOB SILENCE ALERT',
            str_repeat('=', 60),
            '',
            sprintf('Job ID      : %d', $jobId),
            sprintf('Description : %s', $description),
            sprintf('Schedule    : %s', $schedule),
        ];

        if ($target !== '' && $target !== 'local') {
            $lines[] = sprintf('Target      : %s', $target);
        }

        $lines[] = sprintf('Expected at : %s', $expectedLastRun);
        $lines[] = $lastStartLine;
        $lines[] = sprintf('Silent for  : %s', $silent);
        $lines[] = '';
        $lines[] = 'The job has not started as scheduled. Please investigate.';
        $lines[] = '';

        if ($baseUrl !== '') {
            $lines[] = sprintf('View in Cronmanager: %s/crons/%d', $baseUrl, $jobId);
        }

        return implode("\n", $lines);
    }

    private function buildSilenceHtmlBody(
        int     $jobId,
        string  $description,
        string  $schedule,
        ?string $lastStartedAt,
        string  $expectedLastRun,
        int     $silenceSinceMinutes,
        string  $target,
        string  $baseUrl,
        callable $e,
    ): string {
        $targetRow = ($target !== '' && $target !== 'local')
            ? "<tr><th>Target</th><td>{$e($target)}</td></tr>"
            : '';

        $lastSeenCell = $lastStartedAt !== null
            ? $e($lastStartedAt)
            : '<em>never (job has not run yet)</em>';

        $hours   = intdiv($silenceSinceMinutes, 60);
        $minutes = $silenceSinceMinutes % 60;
        $silent  = $hours > 0
            ? sprintf('%dh&nbsp;%02dm', $hours, $minutes)
            : sprintf('%dm', $minutes);

        $linkHtml = $baseUrl !== ''
            ? sprintf(
                '<p style="margin-top:16px;"><a href="%s/crons/%d" style="display:inline-block;padding:8px 16px;background:#b45309;color:#fff;text-decoration:none;border-radius:4px;font-size:13px;">&#x1F517; View in Cronmanager</a></p>',
                $e($baseUrl),
                $jobId
            )
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cronmanager &ndash; Job Silence Alert</title>
    <style>
        body{font-family:Arial,sans-serif;font-size:14px;color:#333;background:#f4f4f4;margin:0;padding:20px}
        .card{background:#fff;border-radius:6px;padding:24px;max-width:600px;margin:0 auto;box-shadow:0 1px 3px rgba(0,0,0,.1)}
        h1{font-size:20px;margin-top:0;color:#b45309}
        table{border-collapse:collapse;width:100%;margin-top:12px}
        th{text-align:left;width:38%;padding:6px 8px;background:#fffbeb;font-weight:600;color:#92400e;border-bottom:1px solid #fde68a}
        td{padding:6px 8px;border-bottom:1px solid #e5e7eb}
        .footer{font-size:11px;color:#999;margin-top:20px}
    </style>
</head>
<body>
<div class="card">
    <h1>&#x1F910; Job Silence Alert</h1>
    <p style="color:#92400e">The job has not started as scheduled. Please investigate.</p>
    <table>
        <tr><th>Job ID</th>       <td>{$e((string)$jobId)}</td></tr>
        <tr><th>Description</th>  <td>{$e($description)}</td></tr>
        <tr><th>Schedule</th>     <td>{$e($schedule)}</td></tr>
        {$targetRow}
        <tr><th>Expected at</th>  <td>{$e($expectedLastRun)}</td></tr>
        <tr><th>Last seen</th>    <td>{$lastSeenCell}</td></tr>
        <tr><th>Silent for</th>   <td>{$silent}</td></tr>
    </table>
    {$linkHtml}
    <p class="footer">Cronmanager &ndash; automated job monitoring</p>
</div>
</body>
</html>
HTML;
    }

    private function buildRecoveryHtmlBody(
        int      $jobId,
        string   $description,
        string   $linuxUser,
        string   $schedule,
        int      $consecutiveFailures,
        string   $startedAt,
        string   $finishedAt,
        string   $target,
        string   $baseUrl,
        callable $e,
    ): string {
        $targetRow = ($target !== '' && $target !== 'local')
            ? "<tr><th>Target</th><td>{$e($target)}</td></tr>"
            : '';

        $linkHtml = $baseUrl !== ''
            ? sprintf(
                '<p style="margin-top:16px;"><a href="%s/crons/%d" style="display:inline-block;padding:8px 16px;background:#16a34a;color:#fff;text-decoration:none;border-radius:4px;font-size:13px;">&#x1F517; View in Cronmanager</a></p>',
                $e($baseUrl),
                $jobId
            )
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cronmanager – Job Recovered</title>
    <style>
        body{font-family:Arial,sans-serif;font-size:14px;color:#333;background:#f4f4f4;margin:0;padding:20px}
        .card{background:#fff;border-radius:6px;padding:24px;max-width:600px;margin:0 auto;box-shadow:0 1px 3px rgba(0,0,0,.1)}
        h1{font-size:20px;margin-top:0;color:#15803d}
        table{border-collapse:collapse;width:100%;margin-top:12px}
        th{text-align:left;width:38%;padding:6px 8px;background:#f0fdf4;font-weight:600;color:#166534;border-bottom:1px solid #bbf7d0}
        td{padding:6px 8px;border-bottom:1px solid #e5e7eb}
        .footer{font-size:11px;color:#999;margin-top:20px}
    </style>
</head>
<body>
<div class="card">
    <h1>&#x2705; Job Recovered</h1>
    <table>
        <tr><th>Job ID</th>      <td>{$e((string)$jobId)}</td></tr>
        <tr><th>Description</th> <td>{$e($description)}</td></tr>
        <tr><th>User</th>        <td>{$e($linuxUser)}</td></tr>
        <tr><th>Schedule</th>    <td>{$e($schedule)}</td></tr>
        {$targetRow}
        <tr><th>Recovered at</th><td>{$e($finishedAt)}</td></tr>
        <tr><th>Previous failures</th><td>{$e((string)$consecutiveFailures)} consecutive failure(s)</td></tr>
    </table>
    {$linkHtml}
    <p class="footer">Cronmanager – automated job monitoring</p>
</div>
</body>
</html>
HTML;
    }
}
