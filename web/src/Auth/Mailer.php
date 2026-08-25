<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Mailer (PHPMailer wrapper)
 *
 * Sends transactional emails (invite, password reset) from the web container.
 * Configuration is read from the 'mail.*' config keys, which are populated by
 * the web container's entrypoint.sh from WEB_MAIL_* environment variables.
 *
 * Requires PHPMailer (already a project dependency).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Auth;

use Monolog\Logger;
use Noodlehaus\Config;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use RuntimeException;

/**
 * Class Mailer
 *
 * Thin facade over PHPMailer for sending system emails.
 * Returns void on success and throws RuntimeException on failure.
 */
class Mailer
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    /**
     * Send an invitation email to a newly created user.
     *
     * @param string $toEmail   Recipient email address.
     * @param string $username  Recipient username (for personalisation).
     * @param string $inviteUrl Full URL to the invite acceptance page.
     *
     * @return void
     *
     * @throws RuntimeException When the email cannot be dispatched.
     */
    public function sendInvite(string $toEmail, string $username, string $inviteUrl): void
    {
        $subject = (string) $this->config->get('mail.invite_subject', 'Ihr Zugang zum Cronmanager');
        $body    = $this->inviteBody($username, $inviteUrl);

        $this->send($toEmail, $subject, $body);
    }

    /**
     * Send a password-reset email.
     *
     * @param string $toEmail  Recipient email address.
     * @param string $username Recipient username (for personalisation).
     * @param string $resetUrl Full URL to the password-reset page.
     *
     * @return void
     *
     * @throws RuntimeException When the email cannot be dispatched.
     */
    public function sendPasswordReset(string $toEmail, string $username, string $resetUrl): void
    {
        $subject = (string) $this->config->get('mail.reset_subject', 'Passwort zurücksetzen – Cronmanager');
        $body    = $this->resetBody($username, $resetUrl);

        $this->send($toEmail, $subject, $body);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Configure and send one email via SMTP.
     *
     * @param string $to      Recipient address.
     * @param string $subject Email subject line.
     * @param string $body    HTML body.
     *
     * @return void
     *
     * @throws RuntimeException On send failure.
     */
    private function send(string $to, string $subject, string $body): void
    {
        $host       = (string) $this->config->get('mail.host', '');
        $port       = (int)    $this->config->get('mail.port', 587);
        $username   = (string) $this->config->get('mail.username', '');
        $password   = (string) $this->config->get('mail.password', '');
        $from       = (string) $this->config->get('mail.from', $username);
        $fromName   = (string) $this->config->get('mail.from_name', 'Cronmanager');
        $encryption = (string) $this->config->get('mail.encryption', 'tls');

        if ($host === '') {
            throw new RuntimeException('Mail is not configured (mail.host is empty).');
        }

        $mail = new PHPMailer(exceptions: true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPSecure = match (strtolower($encryption)) {
            'ssl'  => PHPMailer::ENCRYPTION_SMTPS,
            'tls'  => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        $mail->SMTPAuth = $username !== '';
        if ($username !== '') {
            $mail->Username = $username;
            $mail->Password = $password;
        }

        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

        try {
            $mail->send();
            $this->logger->info('Mailer: email sent', ['to' => $to, 'subject' => $subject]);
        } catch (\Exception $e) {
            $this->logger->error('Mailer: send failed', [
                'to'      => $to,
                'subject' => $subject,
                'error'   => $e->getMessage(),
            ]);
            throw new RuntimeException('Failed to send email: ' . $e->getMessage(), previous: $e);
        }
    }

    private function inviteBody(string $username, string $link): string
    {
        $h = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        return '<p>Hallo ' . $h($username) . ',</p>'
            . '<p>Sie wurden als Benutzer im Cronmanager angelegt.</p>'
            . '<p>Bitte setzen Sie Ihr Passwort und aktivieren Sie Ihren Zugang unter folgendem Link:</p>'
            . '<p><a href="' . $h($link) . '">' . $h($link) . '</a></p>'
            . '<p>Der Link ist 72 Stunden gültig.</p>'
            . '<p>Cronmanager</p>';
    }

    private function resetBody(string $username, string $link): string
    {
        $h = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        return '<p>Hallo ' . $h($username) . ',</p>'
            . '<p>Für Ihren Cronmanager-Zugang wurde eine Passwortzurücksetzung angefordert.</p>'
            . '<p>Verwenden Sie den folgenden Link, um ein neues Passwort zu setzen:</p>'
            . '<p><a href="' . $h($link) . '">' . $h($link) . '</a></p>'
            . '<p>Der Link ist 2 Stunden gültig. Falls Sie keine Passwortzurücksetzung angefordert haben, ignorieren Sie diese E-Mail.</p>'
            . '<p>Cronmanager</p>';
    }
}
