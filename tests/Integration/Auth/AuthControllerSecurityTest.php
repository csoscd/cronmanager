<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: AuthController security invariants
 *
 * Tests the security-critical behaviours of AuthController that cannot be
 * covered purely at the AuthTokenRepository level.
 *
 * Architecture note on testing limitations
 * ----------------------------------------
 * Response::redirect() calls exit(), which terminates the PHP process.  Testing
 * the HAPPY PATH (valid token → password set → redirect) through the actual
 * controller action is therefore not practical without process isolation.
 *
 * Strategy used here:
 *   - Non-exit paths (failure / rejection rendering): tested through the real
 *     AuthController instance, with Connection::$instance injected via
 *     Reflection so the controller uses the test-database PDO connection.
 *   - Exit paths (success → redirect): verified at the repository level by
 *     asserting the invariant the controller relies on (token cannot be reused
 *     after consume()), clearly labelled as such.
 *
 * Covered scenarios
 * -----------------
 * 1. No user-enumeration: handleForgotPassword with unknown email → same
 *    success response as for known email; no auth_token row created
 * 2. handleReset with an expired token → error rendered (no redirect / exit)
 * 3. handleReset with a wrong-type token (invite used as reset) → rejection
 * 4. handleInvite with password too short → rejection; token stays valid in DB
 * 5. Token one-time use: a reset token cannot be used a second time after
 *    consume() (directly verifying the invariant handleReset relies on)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Auth;

use Cronmanager\Web\Controller\AuthController;
use Cronmanager\Web\Auth\AuthTokenRepository;
use Cronmanager\Web\Database\Connection;
use Monolog\Logger;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json as JsonParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\IntegrationTestCase;

final class AuthControllerSecurityTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    private AuthController $controller;
    private Config $config;
    private AuthTokenRepository $repo;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal config: mail.host set so isMailEnabled() returns true,
        // preventing the early redirect in handleForgotPassword.
        $this->config = new Config(
            json_encode([
                'mail'    => ['host' => 'localhost', 'port' => 1025, 'from' => 'noreply@test.local', 'to' => 'admin@test.local'],
                'app'     => ['web_url' => 'http://test.local'],
                'i18n'    => ['default_language' => 'en', 'available' => ['en', 'de']],
                'session' => ['name' => 'test_sess', 'lifetime' => 3600, 'idle_timeout' => 3600],
                'logging' => ['path' => '/tmp/cronmanager-test-auth.log', 'level' => 'debug', 'max_days' => 1],
            ]),
            new JsonParser(),
            true,
        );

        $this->injectTestConnectionSingleton();

        $this->controller = new AuthController($this->config, new Logger('test'));
        $this->repo       = new AuthTokenRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->resetConnectionSingleton();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Replace the Connection singleton with a stub that returns the test PDO.
     * This lets AuthController::handleForgotPassword/handleReset/handleInvite
     * use the same in-transaction connection as the test itself.
     */
    private function injectTestConnectionSingleton(): void
    {
        $refl = new \ReflectionClass(Connection::class);

        // Build a Connection instance without calling the real constructor
        // (which reads config files and opens a real DB connection).
        $conn = $refl->newInstanceWithoutConstructor();

        $pdoProp = $refl->getProperty('pdo');
        $pdoProp->setAccessible(true);
        $pdoProp->setValue($conn, $this->pdo);

        $instanceProp = $refl->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, $conn);
    }

    /**
     * Tear down the injected Connection singleton so it does not leak into
     * other test classes that might need a real DB connection.
     */
    private function resetConnectionSingleton(): void
    {
        $refl = new \ReflectionClass(Connection::class);
        $prop = $refl->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * Insert a user with an email address and return the user ID.
     * Uses a raw INSERT because seedUser() does not include the email column.
     */
    private function seedUserWithEmail(string $email): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, role, email)
             VALUES (:username, :password_hash, :role, :email)'
        )->execute([
            ':username'      => 'testuser_' . uniqid(),
            ':password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            ':role'          => 'admin',
            ':email'         => $email,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Call a controller action, suppressing all output.
     * Returns the output as a string for optional inspection.
     */
    private function callSilently(callable $action): string
    {
        ob_start();
        try {
            $action();
        } catch (\Throwable) {
            // Session / header warnings from template rendering are acceptable in CLI tests.
        }

        return (string) ob_get_clean();
    }

    /**
     * Count auth_token rows in the DB for a given user.
     */
    private function countTokens(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM auth_tokens WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    // =========================================================================
    // 1. No user-enumeration in handleForgotPassword
    // =========================================================================

    #[Test]
    public function forgotPasswordDoesNotRevealWhetherUserExists(): void
    {
        $knownEmail   = 'known-' . uniqid() . '@example.com';
        $unknownEmail = 'unknown-' . uniqid() . '@example.com';

        $userId = $this->seedUserWithEmail($knownEmail);

        // Unknown email: handleForgotPassword must NOT create a token
        $_POST['email'] = $unknownEmail;
        $this->callSilently(fn() => $this->controller->handleForgotPassword([]));
        unset($_POST['email']);

        $this->assertSame(
            0,
            $this->countTokens($userId),
            'No auth_token must be created when the email address is not registered'
        );

        // Sanity check: known email DOES produce a token (may fail on SMTP, which is caught)
        $_POST['email'] = $knownEmail;
        $this->callSilently(fn() => $this->controller->handleForgotPassword([]));
        unset($_POST['email']);

        $this->assertGreaterThanOrEqual(
            1,
            $this->countTokens($userId),
            'A token must be created for a registered email address'
        );
    }

    // =========================================================================
    // 2. handleReset rejects an expired token
    // =========================================================================

    #[Test]
    public function handleResetRejectsExpiredToken(): void
    {
        $userId    = $this->seedUser();
        $plain     = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plain);
        $pastDate  = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, type, expires_at)
             VALUES (:uid, :hash, "reset", :exp)'
        )->execute([':uid' => $userId, ':hash' => $hash, ':exp' => $pastDate]);

        $_POST = ['token' => $plain, 'password' => 'NewPassword1!', 'password_confirm' => 'NewPassword1!'];
        $output = $this->callSilently(fn() => $this->controller->handleReset([]));
        $_POST  = [];

        // The controller renders an error page (no redirect) – password must not change
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $storedHash = (string) $stmt->fetchColumn();

        $this->assertFalse(
            password_verify('NewPassword1!', $storedHash),
            'Password must not be updated when token is expired'
        );
    }

    // =========================================================================
    // 3. handleReset rejects a token of the wrong type
    // =========================================================================

    #[Test]
    public function handleResetRejectsInviteTokenUsedAsReset(): void
    {
        $userId = $this->seedUser();

        // Create an 'invite' token and try to use it for a password reset
        $plain = $this->repo->create($userId, 'invite', 72);

        $_POST = ['token' => $plain, 'password' => 'NewPassword1!', 'password_confirm' => 'NewPassword1!'];
        $this->callSilently(fn() => $this->controller->handleReset([]));
        $_POST = [];

        // Token must still be valid (invite type) – reset must not have consumed it
        $this->assertNotNull(
            $this->repo->find($plain, 'invite'),
            'An invite token presented as a reset token must be rejected and left unconsumed'
        );

        // Password must not have been changed
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $storedHash = (string) $stmt->fetchColumn();

        $this->assertFalse(
            password_verify('NewPassword1!', $storedHash),
            'Password must not be updated when wrong token type is used'
        );
    }

    // =========================================================================
    // 4. handleInvite rejects a short password; token remains valid
    // =========================================================================

    #[Test]
    public function handleInviteRejectsShortPasswordAndPreservesToken(): void
    {
        $userId = $this->seedUser();
        $plain  = $this->repo->create($userId, 'invite', 72);

        // Submit password that is too short (< 8 chars)
        $_POST = ['token' => $plain, 'password' => 'short', 'password_confirm' => 'short'];
        $this->callSilently(fn() => $this->controller->handleInvite([]));
        $_POST = [];

        // Token must still be valid – rejection must not consume it
        $this->assertNotNull(
            $this->repo->find($plain, 'invite'),
            'Invite token must remain valid after a password-validation failure'
        );
    }

    // =========================================================================
    // 5. Token cannot be reused after consume() (invariant handleReset relies on)
    // =========================================================================

    #[Test]
    public function resetTokenCannotBeReusedAfterConsumption(): void
    {
        // This test verifies the one-time-use invariant at the repository level.
        // The controller's handleReset() calls repo->consume() on success, after
        // which the same token must be rejected on any subsequent request.
        // (Testing through the controller itself is impractical because
        // Response::redirect() calls exit() on the success path.)

        $userId = $this->seedUserWithEmail('user-' . uniqid() . '@example.com');
        $plain  = $this->repo->create($userId, 'reset', 2);

        // First use – find succeeds, then consume (mirrors controller success path)
        $row = $this->repo->find($plain, 'reset');
        $this->assertNotNull($row, 'Precondition: token must be valid before first use');

        $this->repo->consume((int) $row['id']);

        // Second use – must be rejected (mirrors what a second reset-request would do)
        $this->assertNull(
            $this->repo->find($plain, 'reset'),
            'Reset token must be invalid after it has been consumed – cannot be used a second time'
        );
    }
}
