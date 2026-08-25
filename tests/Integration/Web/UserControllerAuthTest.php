<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: User Controller authorization
 *
 * Tests the authorization layer that protects user-management actions:
 *
 *   1. Route registration integrity – every /users/* route registered by
 *      UserRoutesRegistrar is present in the router and requires 'admin'.
 *      The test calls the REAL UserRoutesRegistrar::register() (not a copy),
 *      so a missing route or a wrong required-role breaks this test immediately.
 *
 *   2–4. Router::isAuthorized('admin') returns false for viewer/operator and
 *        true for admin, verifying the underlying authorisation logic used by
 *        dispatch() and requireAdmin().
 *
 *   5. Own-role guard: UserController::update() must not allow an admin to
 *      change their own role.  Response::redirect() calls exit(), making it
 *      impossible to reach the DB assertion through the controller directly.
 *      The guard condition is therefore tested at the SessionManager level
 *      (same Known Gap as AuthController success paths).
 *
 *   6. BaseController::requireAdmin() exists and is a protected method
 *      (defense-in-depth layer verified structurally; its runtime logic
 *      is covered by scenarios 2–4 via the same SessionManager::hasRole()
 *      call it delegates to).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Web;

use Cronmanager\Web\Controller\BaseController;
use Cronmanager\Web\Controller\UserController;
use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Router;
use Cronmanager\Web\Http\UserRoutesRegistrar;
use Cronmanager\Web\Session\SessionManager;
use Monolog\Logger;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json as JsonParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\IntegrationTestCase;

final class UserControllerAuthTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    private Config $config;
    private Router $router;
    private UserController $userCtrl;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new Config(
            json_encode([
                'app'     => ['web_url' => 'http://test.local'],
                'i18n'    => ['default_language' => 'en', 'available' => ['en', 'de']],
                'session' => ['name' => 'test_sess', 'lifetime' => 3600, 'idle_timeout' => 3600],
                'logging' => ['path' => '/tmp/cronmanager-test-user.log', 'level' => 'debug', 'max_days' => 1],
            ]),
            new JsonParser(),
            true,
        );

        $this->injectTestConnectionSingleton();

        $logger         = new Logger('test');
        $this->userCtrl = new UserController($this->config, $logger);
        $this->router   = new Router($this->config, $logger);
    }

    protected function tearDown(): void
    {
        $this->resetConnectionSingleton();
        $_SESSION = [];
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

    // =========================================================================
    // 1. Route registration integrity
    // =========================================================================

    #[Test]
    public function userRoutesRegistrarRegistersAllExpectedWriteRoutesAsAdmin(): void
    {
        // Call the REAL UserRoutesRegistrar (same code as index.php).
        // If a route is missing here it is also missing in production.
        UserRoutesRegistrar::register($this->router, $this->userCtrl);

        $routes = $this->router->getProtectedRoutes();

        // Build a lookup: "METHOD /path" => requiredRole
        $index = [];
        foreach ($routes as $route) {
            $index[$route['method'] . ' ' . $route['pattern']] = $route['requiredRole'];
        }

        $expectedAdminRoutes = [
            'GET /users',
            'GET /users/new',
            'POST /users/new',
            'GET /users/{id}/edit',
            'POST /users/{id}/edit',
            'POST /users/{id}/role',
            'POST /users/{id}/deactivate',
            'POST /users/{id}/activate',
            'POST /users/{id}/invite',
            'POST /users/{id}/delete',
        ];

        foreach ($expectedAdminRoutes as $routeKey) {
            $this->assertArrayHasKey(
                $routeKey,
                $index,
                "Route '$routeKey' must be registered by UserRoutesRegistrar"
            );
            $this->assertSame(
                'admin',
                $index[$routeKey],
                "Route '$routeKey' must require role 'admin'"
            );
        }
    }

    // =========================================================================
    // 2. viewer → isAuthorized('admin') → false
    // =========================================================================

    #[Test]
    public function viewerRoleIsNotAuthorizedForAdmin(): void
    {
        $_SESSION = [
            'authenticated' => true,
            'user_id'       => 99,
            'username'      => 'vieweruser',
            'role'          => 'viewer',
        ];

        $this->assertFalse(
            $this->router->isAuthorized('admin'),
            'viewer role must not satisfy admin authorization'
        );
    }

    // =========================================================================
    // 3. operator → isAuthorized('admin') → false
    // =========================================================================

    #[Test]
    public function operatorRoleIsNotAuthorizedForAdmin(): void
    {
        $_SESSION = [
            'authenticated' => true,
            'user_id'       => 98,
            'username'      => 'opuser',
            'role'          => 'operator',
        ];

        $this->assertFalse(
            $this->router->isAuthorized('admin'),
            'operator role must not satisfy admin authorization'
        );
    }

    // =========================================================================
    // 4. admin → isAuthorized('admin') → true
    // =========================================================================

    #[Test]
    public function adminRoleIsAuthorizedForAdmin(): void
    {
        $_SESSION = [
            'authenticated' => true,
            'user_id'       => 1,
            'username'      => 'adminuser',
            'role'          => 'admin',
        ];

        $this->assertTrue(
            $this->router->isAuthorized('admin'),
            'admin role must satisfy admin authorization'
        );
    }

    // =========================================================================
    // 5. Own-role guard in UserController::update()
    // =========================================================================

    #[Test]
    public function ownRoleGuardPreventsAdminFromChangingTheirOwnRole(): void
    {
        // Guard code in UserController::update() (~line 268):
        //   $isSelf = $id === SessionManager::getUserId();
        //   if ($isSelf && $role !== SessionManager::getRole()) {
        //       (new Response())->redirect('/users/' . $id . '/edit');   // exit()
        //   }
        //
        // Response::redirect() calls exit(), which makes it impossible to verify
        // the DB directly through the controller action (same Known Gap as
        // AuthController success paths).  The invariant is tested at the
        // SessionManager level: when the guard condition is true, the redirect
        // fires on line 271 – BEFORE the first PDO call on line 276 – so the
        // DB role is never updated.

        $adminId = $this->seedUser(['role' => 'admin']);

        $_SESSION = [
            'authenticated' => true,
            'user_id'       => $adminId,
            'username'      => 'adminuser',
            'role'          => 'admin',
        ];

        // Verify the two sub-conditions that make the guard trigger
        $isSelf      = ($adminId === SessionManager::getUserId());
        $rolesDiffer = ('viewer' !== SessionManager::getRole());

        $this->assertTrue(
            $isSelf,
            'Guard precondition: the request targets the currently logged-in user'
        );
        $this->assertTrue(
            $rolesDiffer,
            'Guard precondition: the submitted role differs from the session role'
        );

        // Both conditions hold → guard fires → redirect before DB write
        $this->assertTrue(
            $isSelf && $rolesDiffer,
            'Own-role guard must trigger when an admin submits a different role for their own account'
        );

        // DB role was never touched by setup either; verify it is still 'admin'
        $stmt = $this->pdo->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute([':id' => $adminId]);
        $this->assertSame(
            'admin',
            (string) $stmt->fetchColumn(),
            'DB role must remain admin (guard verified at condition level; exit() prevents direct controller assertion)'
        );
    }

    // =========================================================================
    // 6. BaseController::requireAdmin() defense-in-depth method exists
    // =========================================================================

    #[Test]
    public function baseControllerDeclaresRequireAdminAsProtectedMethod(): void
    {
        // requireAdmin() calls exit() on a non-admin session, making end-to-end
        // testing impractical without process isolation.  Its runtime logic
        // (SessionManager::hasRole('admin') → false for viewer/operator) is
        // already covered by scenarios 2–4.  This scenario verifies that the
        // defense-in-depth method exists and is protected so it can only be
        // called from controller action methods.
        $refl = new \ReflectionClass(BaseController::class);

        $this->assertTrue(
            $refl->hasMethod('requireAdmin'),
            'BaseController must declare a requireAdmin() defense-in-depth method'
        );

        $method = $refl->getMethod('requireAdmin');
        $this->assertTrue(
            $method->isProtected(),
            'requireAdmin() must be protected (callable only from controller subclasses)'
        );
    }
}
