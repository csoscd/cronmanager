<?php

declare(strict_types=1);

/**
 * Cronmanager – Unit Tests: WebAuditLogger
 *
 * Verifies that WebAuditLogger calls the correct SQL via PDO and that
 * failures are silently caught without propagating exceptions.
 *
 * Uses PHPUnit mock objects so no database driver is required.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Unit\Web;

use Cronmanager\Web\Audit\WebAuditLogger;
use Monolog\Logger;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebAuditLoggerTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(Logger::class);
    }

    #[Test]
    public function it_executes_insert_with_correct_parameters(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
             ->method('execute')
             ->with($this->callback(static function (array $params): bool {
                 return $params[':user_id']        === 1
                     && $params[':username']       === 'admin'
                     && $params[':action']         === 'user.delete'
                     && $params[':resource_type']  === 'user'
                     && $params[':resource_id']    === 42
                     && $params[':resource_label'] === 'alice'
                     && $params[':details']        === null
                     && $params[':ip_address']     === '127.0.0.1';
             }));

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->willReturn($stmt);

        $logger = new WebAuditLogger($pdo, $this->logger, 1, 'admin', '127.0.0.1');
        $logger->log('user.delete', 'user', 42, 'alice');
    }

    #[Test]
    public function it_json_encodes_details(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
             ->method('execute')
             ->with($this->callback(static function (array $params): bool {
                 $decoded = json_decode($params[':details'], true);
                 return $decoded['from'] === 'view' && $decoded['to'] === 'admin';
             }));

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->willReturn($stmt);

        $logger = new WebAuditLogger($pdo, $this->logger, 2, 'bob', '10.0.0.1');
        $logger->log('user.update_role', 'user', 7, 'charlie', ['from' => 'view', 'to' => 'admin']);
    }

    #[Test]
    public function it_does_not_throw_on_db_failure(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new \PDOException('connection lost'));

        $this->logger->expects($this->once())->method('error');

        $logger = new WebAuditLogger($pdo, $this->logger, 0, 'system', '127.0.0.1');

        // Must not propagate the exception
        $logger->log('settings.update');
    }
}
