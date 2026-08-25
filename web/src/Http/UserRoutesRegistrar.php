<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – User Route Registrar
 *
 * Centralises the registration of all /users/* admin routes so that both
 * the front controller (index.php) and integration tests call the identical
 * code path.  A copy of this logic in either place would make it possible
 * for the test to pass while index.php silently left a route un-protected.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Http;

use Cronmanager\Web\Controller\UserController;

/**
 * Class UserRoutesRegistrar
 *
 * Registers all /users/* routes on a Router instance.  Every route requires
 * the 'admin' role; the ordering constraint (/users/new before /users/{id})
 * is preserved here so that swapping order in one place can never desync.
 */
final class UserRoutesRegistrar
{
    /**
     * Register all user-management routes on the given router.
     *
     * Must be called after the router instance is created and before dispatch().
     * All routes require the 'admin' role.
     *
     * @param Router         $router   The application router.
     * @param UserController $userCtrl The user controller instance to dispatch to.
     *
     * @return void
     */
    public static function register(Router $router, UserController $userCtrl): void
    {
        $router->addProtectedRoute('GET',  '/users',               [$userCtrl, 'index'],       'admin');
        // /users/new must be before /users/{id} to avoid matching 'new' as an ID
        $router->addProtectedRoute('GET',  '/users/new',           [$userCtrl, 'create'],      'admin');
        $router->addProtectedRoute('POST', '/users/new',           [$userCtrl, 'store'],       'admin');
        $router->addProtectedRoute('GET',  '/users/{id}/edit',     [$userCtrl, 'edit'],        'admin');
        $router->addProtectedRoute('POST', '/users/{id}/edit',     [$userCtrl, 'update'],      'admin');
        $router->addProtectedRoute('POST', '/users/{id}/role',     [$userCtrl, 'updateRole'],  'admin');
        $router->addProtectedRoute('POST', '/users/{id}/deactivate', [$userCtrl, 'deactivate'], 'admin');
        $router->addProtectedRoute('POST', '/users/{id}/activate', [$userCtrl, 'activate'],   'admin');
        $router->addProtectedRoute('POST', '/users/{id}/invite',   [$userCtrl, 'resendInvite'], 'admin');
        $router->addProtectedRoute('POST', '/users/{id}/delete',   [$userCtrl, 'destroy'],    'admin');
    }
}
