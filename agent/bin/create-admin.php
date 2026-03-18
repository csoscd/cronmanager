<?php

declare(strict_types=1);

/**
 * Cronmanager – Create Initial Admin User
 *
 * Run this script once on the host after the first deployment and after the
 * database schema has been applied, to create the initial admin account.
 *
 * Usage:
 *   php /opt/phpscripts/cronmanager/agent/bin/create-admin.php
 *
 * You will be prompted for username, password, and role.
 * The script connects to MariaDB using the agent's config.json.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

require_once '/opt/phplib/vendor/autoload.php';

// PSR-4 autoloader for agent classes
spl_autoload_register(function (string $class): void {
    $prefix  = 'Cronmanager\\Agent\\';
    $baseDir = __DIR__ . '/../src/';
    if (str_starts_with($class, $prefix)) {
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use Noodlehaus\Config;
use Cronmanager\Agent\Database\Connection;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function prompt(string $question, bool $hidden = false): string
{
    echo $question;
    if ($hidden && PHP_OS_FAMILY !== 'Windows') {
        // Suppress terminal echo for password input
        system('stty -echo');
        $input = trim((string) fgets(STDIN));
        system('stty echo');
        echo PHP_EOL;
    } else {
        $input = trim((string) fgets(STDIN));
    }
    return $input;
}

function writeLine(string $msg): void
{
    echo $msg . PHP_EOL;
}

// ─── Config ──────────────────────────────────────────────────────────────────

$configPath = '/opt/phpscripts/cronmanager/agent/config/config.json';
$devPath    = __DIR__ . '/../config/config.json';

if (!file_exists($configPath) && file_exists($devPath)) {
    $configPath = $devPath;
}

if (!file_exists($configPath)) {
    writeLine('ERROR: config.json not found at: ' . $configPath);
    exit(1);
}

writeLine('');
writeLine('╔══════════════════════════════════════╗');
writeLine('║  Cronmanager – Create Admin User      ║');
writeLine('╚══════════════════════════════════════╝');
writeLine('');

// ─── Input ───────────────────────────────────────────────────────────────────

$username = prompt('Username [admin]: ');
if ($username === '') {
    $username = 'admin';
}

$password = prompt('Password: ', hidden: true);
if (strlen($password) < 8) {
    writeLine('ERROR: Password must be at least 8 characters.');
    exit(1);
}

$passwordConfirm = prompt('Confirm password: ', hidden: true);
if ($password !== $passwordConfirm) {
    writeLine('ERROR: Passwords do not match.');
    exit(1);
}

$roleInput = prompt('Role (view/admin) [admin]: ');
$role = match(strtolower(trim($roleInput))) {
    'view'  => 'view',
    'admin' => 'admin',
    ''      => 'admin',
    default => null,
};

if ($role === null) {
    writeLine("ERROR: Invalid role '{$roleInput}'. Must be 'view' or 'admin'.");
    exit(1);
}

// ─── Create User ─────────────────────────────────────────────────────────────

try {
    $pdo  = Connection::getInstance()->getPdo();
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Check for existing user
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);

    if ($stmt->fetch()) {
        $overwrite = prompt("User '{$username}' already exists. Update password/role? (yes/no) [no]: ");
        if (strtolower(trim($overwrite)) !== 'yes') {
            writeLine('Aborted.');
            exit(0);
        }

        $update = $pdo->prepare(
            'UPDATE users SET password_hash = :hash, role = :role WHERE username = :username'
        );
        $update->execute([
            ':hash'     => $hash,
            ':role'     => $role,
            ':username' => $username,
        ]);

        writeLine('');
        writeLine("✔ User '{$username}' updated successfully (role: {$role}).");
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role) VALUES (:username, :hash, :role)'
        );
        $insert->execute([
            ':username' => $username,
            ':hash'     => $hash,
            ':role'     => $role,
        ]);

        $id = (int) $pdo->lastInsertId();
        writeLine('');
        writeLine("✔ User '{$username}' created successfully (id: {$id}, role: {$role}).");
    }

    writeLine('');

} catch (\Throwable $e) {
    writeLine('ERROR: ' . $e->getMessage());
    exit(1);
}
