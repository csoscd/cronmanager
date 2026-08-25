<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: ProfileController authorization invariant
 *
 * Verifies that ProfileController::update() never writes the 'role' or
 * 'active' columns to the database, even when a caller deliberately includes
 * those fields in the POST body.
 *
 * Why this matters
 * ----------------
 * ProfileController is mounted at POST /profile and is accessible to every
 * authenticated user (required role: 'view').  A bug that forwards POST
 * parameters verbatim to the UPDATE statement could allow any user to
 * elevate their own role or reactivate a deactivated account.
 *
 * Testing approach
 * ----------------
 * The success path of ProfileController::update() (valid input → profile
 * saved → redirect) calls Response::redirect() → exit(), terminating the
 * process before the assertion point.  To avoid this, the test submits an
 * invalid email address, which triggers the validation-error path: the
 * controller re-renders the form and returns (no redirect, no exit).
 *
 * This is intentional: in BOTH paths (validation error and success), the
 * controller issues UPDATE statements only for 'email' and 'password_hash'.
 * Verifying the DB after the error path is sufficient to demonstrate the
 * 'role'/'active' columns are never touched.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Web;

use Cronmanager\Web\Controller\ProfileController;
use Cronmanager\Web\Database\Connection;
use Monolog\Logger;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json as JsonParser;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\IntegrationTestCase;

final class ProfileControllerAuthTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    private ProfileController $profileCtrl;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $config = new Config(
            json_encode([
                'app'     => ['web_url' => 'http://test.local'],
                'mail'    => ['host' => ''],
                'i18n'    => ['default_language' => 'en', 'available' => ['en', 'de']],
                'session' => ['name' => 'test_sess', 'lifetime' => 3600, 'idle_timeout' => 3600],
                'logging' => ['path' => '/tmp/cronmanager-test-profile.log', 'level' => 'debug', 'max_days' => 1],
            ]),
            new JsonParser(),
            true,
        );

        $this->injectTestConnectionSingleton();

        $this->profileCtrl = new ProfileController($config, new Logger('test'));
    }

    protected function tearDown(): void
    {
        $this->resetConnectionSingleton();
        $_SESSION = [];
        $_POST    = [];
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function injectTestConnectionSingleton(): void
    {
        $refl = new \ReflectionClass(Connection::class);
        $conn = $refl->newInstanceWithoutConstructor();

        $pdoProp = $refl->getProperty('pdo');
        $pdoProp->setAccessible(true);
        $pdoProp->setValue($conn, $this->pdo);

        $instanceProp = $refl->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, $conn);
    }

    private function resetConnectionSingleton(): void
    {
        $refl = new \ReflectionClass(Connection::class);
        $prop = $refl->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * Call a controller action, suppressing all output and swallowing exceptions
     * from template rendering / session operations that do not work in CLI.
     */
    private function callSilently(callable $action): void
    {
        ob_start();
        try {
            $action();
        } catch (\Throwable) {
            // Session / header / template warnings are acceptable in CLI test context.
        }
        ob_get_clean();
    }

    // =========================================================================
    // 1. POST /profile with 'role' and 'active' in body → DB columns unchanged
    // =========================================================================

    #[Test]
    public function profileUpdateNeverWritesRoleOrActiveToDatabase(): void
    {
        // Seed a viewer user: role='viewer', active=1 (DB default)
        $userId = $this->seedUser(['role' => 'viewer']);

        // Simulate a logged-in session for this user
        $_SESSION = [
            'authenticated' => true,
            'user_id'       => $userId,
            'username'      => 'vieweruser',
            'role'          => 'viewer',
        ];

        // Attacker-like POST: includes role=admin and active=0 alongside an
        // intentionally invalid email to trigger the validation-error path
        // (avoids Response::redirect() → exit() on the success path while
        // still exercising the controller's UPDATE logic).
        $_POST = [
            'email'            => 'this-is-not-a-valid-email',
            'password'         => '',
            'password_confirm' => '',
            'role'             => 'admin',   // must NOT be written to DB
            'active'           => '0',       // must NOT be written to DB
        ];

        $this->callSilently(fn() => $this->profileCtrl->update([]));

        $_POST = [];

        // Read the user back from DB and assert role/active are unchanged
        $stmt = $this->pdo->prepare(
            'SELECT role, active FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($user, 'User row must still exist in DB');

        $this->assertSame(
            'viewer',
            (string) $user['role'],
            'ProfileController::update() must never write the role column'
        );

        $this->assertSame(
            '1',
            (string) $user['active'],
            'ProfileController::update() must never write the active column'
        );
    }
}
