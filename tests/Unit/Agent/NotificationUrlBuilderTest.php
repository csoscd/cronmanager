<?php

declare(strict_types=1);

/**
 * Cronmanager – Unit Tests: Notification URL Builder
 *
 * Verifies the private buildNotificationUrl() helper present in both
 * MailNotifier and TelegramNotifier via PHP Reflection.
 *
 * The helper appends ?agent_id=X when the agent ID is non-zero, ensuring that
 * notification links route users to the correct agent after login.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Unit\Agent;

use Cronmanager\Agent\Notification\MailNotifier;
use Cronmanager\Agent\Notification\TelegramNotifier;
use Monolog\Logger;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json as JsonParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationUrlBuilderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Data providers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, int, string}>
     */
    public static function urlCases(): array
    {
        return [
            'no agent_id when agentId is 0' => [
                'https://cm.example.com',
                '/crons/42',
                0,
                'https://cm.example.com/crons/42',
            ],
            'appends ?agent_id when positive' => [
                'https://cm.example.com',
                '/crons/7',
                3,
                'https://cm.example.com/crons/7?agent_id=3',
            ],
            'appends &agent_id when path already has query string' => [
                'https://cm.example.com',
                '/timeline?job_id=5&status=failed&_direct=1',
                2,
                'https://cm.example.com/timeline?job_id=5&status=failed&_direct=1&agent_id=2',
            ],
            'no parameter when agentId is 0 and path has query string' => [
                'https://cm.example.com',
                '/timeline?job_id=5',
                0,
                'https://cm.example.com/timeline?job_id=5',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildUrl(object $notifier, string $baseUrl, string $path, int $agentId): string
    {
        $ref    = new \ReflectionClass($notifier);
        $method = $ref->getMethod('buildNotificationUrl');
        $method->setAccessible(true);

        return (string) $method->invoke($notifier, $baseUrl, $path, $agentId);
    }

    private function makeMailNotifier(): MailNotifier
    {
        $config = new Config('{}', new JsonParser(), true);

        return new MailNotifier(new Logger('test'), $config);
    }

    private function makeTelegramNotifier(): TelegramNotifier
    {
        $config = new Config('{}', new JsonParser(), true);

        return new TelegramNotifier(new Logger('test'), $config);
    }

    // =========================================================================
    // MailNotifier
    // =========================================================================

    #[Test]
    #[DataProvider('urlCases')]
    public function mailNotifierBuildsCorrectUrl(
        string $baseUrl,
        string $path,
        int    $agentId,
        string $expected,
    ): void {
        $url = $this->buildUrl($this->makeMailNotifier(), $baseUrl, $path, $agentId);

        $this->assertSame($expected, $url);
    }

    // =========================================================================
    // TelegramNotifier
    // =========================================================================

    #[Test]
    #[DataProvider('urlCases')]
    public function telegramNotifierBuildsCorrectUrl(
        string $baseUrl,
        string $path,
        int    $agentId,
        string $expected,
    ): void {
        $url = $this->buildUrl($this->makeTelegramNotifier(), $baseUrl, $path, $agentId);

        $this->assertSame($expected, $url);
    }
}
