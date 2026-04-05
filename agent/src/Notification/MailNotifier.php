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
use Noodlehaus\Config;
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
        string $finishedAt
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
            $exitCode === -2 => sprintf('[Cronmanager] Job #%d AUTO-KILLED (limit exceeded): %s', $jobId, $description),
            $exitCode === -3 => sprintf('[Cronmanager] Job #%d LIMIT EXCEEDED (still running): %s', $jobId, $description),
            default          => sprintf('[Cronmanager] Job #%d FAILED (exit %d): %s', $jobId, $exitCode, $description),
        };

        $plainBody = $this->buildPlainBody(
            $jobId,
            $description,
            $linuxUser,
            $schedule,
            $exitCode,
            $truncatedOutput,
            $startedAt,
            $finishedAt
        );

        $htmlBody = $this->buildHtmlBody(
            $jobId,
            $description,
            $linuxUser,
            $schedule,
            $exitCode,
            $truncatedOutput,
            $startedAt,
            $finishedAt
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
    private function exitCodeRow(int $exitCode, callable $e): string
    {
        if ($exitCode === -3) {
            return '<tr><th>Exit Code</th><td><em>N/A &ndash; job still running</em></td></tr>';
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
    private function finishedRow(int $exitCode, string $finishedAt, callable $e): string
    {
        $label = $exitCode === -3 ? 'Notified At' : 'Finished';

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
        string $finishedAt
    ): string {
        $headline = match (true) {
            $exitCode === -2 => 'CRONMANAGER – JOB AUTO-KILLED (EXECUTION LIMIT EXCEEDED)',
            $exitCode === -3 => 'CRONMANAGER – JOB EXECUTION LIMIT EXCEEDED',
            default          => 'CRONMANAGER – JOB FAILURE ALERT',
        };

        // For limit-exceeded alerts the job is still running: there is no exit
        // code yet and finishedAt is the notification time, not a finish time.
        $exitCodeLine  = $exitCode === -3
            ? 'Exit Code  : N/A (job still running)'
            : sprintf('Exit Code  : %d', $exitCode);
        $finishedLine  = $exitCode === -3
            ? sprintf('Notified At: %s', $finishedAt)
            : sprintf('Finished   : %s', $finishedAt);

        return implode("\n", [
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
            'This message was generated automatically by Cronmanager.',
        ]);
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
        string $finishedAt
    ): string {
        // Helper: escape a value for safe HTML embedding
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $outputHtml = $output !== '' ? $e($output) : '<em>(no output)</em>';

        [$h1Text, $introText, $h1Color] = match (true) {
            $exitCode === -2 => [
                '&#x1F6AB; Cron Job Auto-Killed',
                'A managed cron job was automatically terminated because it exceeded its configured execution time limit.',
                '#e67e22',
            ],
            $exitCode === -3 => [
                '&#x23F0; Cron Job Execution Limit Exceeded',
                'A managed cron job has been running longer than its configured execution time limit.',
                '#8e44ad',
            ],
            default => [
                '&#x26A0; Cron Job Failure Alert',
                'A managed cron job has exited with a non-zero exit code.',
                '#c0392b',
            ],
        };

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
        {$this->exitCodeRow($exitCode, $e)}
        <tr><th>Started</th>     <td>{$e($startedAt)}</td></tr>
        {$this->finishedRow($exitCode, $finishedAt, $e)}
    </table>

    <h2 style="font-size:15px; margin-bottom:6px;">Output</h2>
    <pre>{$outputHtml}</pre>

    <p class="footer">This message was generated automatically by Cronmanager.</p>
</div>
</body>
</html>
HTML;
    }
}
