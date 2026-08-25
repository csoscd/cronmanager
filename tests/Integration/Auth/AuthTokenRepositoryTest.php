<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: AuthTokenRepository
 *
 * Verifies the one-time-token primitive used by the invite and password-reset
 * flows.  These invariants are the foundation of the controller-level security
 * logic: if the repository misbehaves, the controller cannot make up for it.
 *
 * Covered scenarios
 * -----------------
 * 1. create() returns a 64-hex plain token; only its sha256 hash lands in the DB
 * 2. create() invalidates previous unused tokens of the same type for the same user
 * 3. Type isolation: create('reset') must NOT invalidate an existing 'invite' token
 * 4. find() accepts a valid, unexpired, unused token
 * 5. find() rejects an expired token
 * 6. find() rejects an already-consumed token (used_at IS NOT NULL)
 * 7. find() rejects a token presented with the wrong type
 * 8. find() rejects a completely fabricated token
 * 9. consume() makes a token permanently unusable (second find() returns null)
 * 10. purgeExpired() removes expired and used tokens; valid tokens survive
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Auth;

use Cronmanager\Web\Auth\AuthTokenRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\IntegrationTestCase;

final class AuthTokenRepositoryTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRepo(): AuthTokenRepository
    {
        return new AuthTokenRepository($this->pdo);
    }

    /**
     * Insert a user with an email address and return the user ID.
     * Uses a raw INSERT rather than seedUser() because seedUser() does not
     * include the email column in its prepared statement.
     */
    private function seedUserWithEmail(): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, role, email)
             VALUES (:username, :password_hash, :role, :email)'
        )->execute([
            ':username'      => 'testuser_' . uniqid(),
            ':password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            ':role'          => 'admin',
            ':email'         => 'test-' . uniqid() . '@example.com',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Directly insert an auth_token row and return its ID.
     * Used to set up expired / used tokens that repository::create() would not produce.
     */
    private function insertRawToken(int $userId, string $type, string $expiresAt, ?string $usedAt = null): int
    {
        $plain = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $plain);

        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, type, expires_at, used_at)
             VALUES (:uid, :hash, :type, :exp, :used)'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':hash' => $hash,
            ':type' => $type,
            ':exp'  => $expiresAt,
            ':used' => $usedAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // =========================================================================
    // 1. create() returns plain token; DB stores only the hash
    // =========================================================================

    #[Test]
    public function createReturns64HexPlainToken(): void
    {
        $userId = $this->seedUserWithEmail();
        $repo   = $this->makeRepo();

        $plain = $repo->create($userId, 'reset', 2);

        // Plain token must be a 64-character hex string
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plain);

        // DB must store only the sha256 hash, never the plain token
        $row = $this->pdo->prepare('SELECT token_hash FROM auth_tokens WHERE user_id = :uid')
                         ->execute([':uid' => $userId]) && true;
        $stmt = $this->pdo->prepare('SELECT token_hash FROM auth_tokens WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $dbHash = (string) $stmt->fetchColumn();

        $this->assertSame(hash('sha256', $plain), $dbHash, 'DB stores sha256 hash, not plain token');
        $this->assertNotSame($plain, $dbHash, 'Plain token must never be stored in DB');
    }

    // =========================================================================
    // 2. create() invalidates previous unused tokens of the same type
    // =========================================================================

    #[Test]
    public function createInvalidatesPreviousUnusedTokenOfSameType(): void
    {
        $userId = $this->seedUserWithEmail();
        $repo   = $this->makeRepo();

        $first  = $repo->create($userId, 'reset', 2);
        $second = $repo->create($userId, 'reset', 2);

        // First token must now be invalid (deleted by create())
        $this->assertNull($repo->find($first, 'reset'), 'First token must be invalidated after second create()');

        // Second token must still be valid
        $this->assertNotNull($repo->find($second, 'reset'), 'Second token must be valid');
    }

    // =========================================================================
    // 3. Type isolation: reset-token create() must NOT invalidate invite tokens
    // =========================================================================

    #[Test]
    public function createResetDoesNotInvalidateExistingInviteToken(): void
    {
        $userId = $this->seedUserWithEmail();
        $repo   = $this->makeRepo();

        $inviteToken = $repo->create($userId, 'invite', 72);

        // Creating a reset token for the same user must leave the invite token intact
        $repo->create($userId, 'reset', 2);

        $this->assertNotNull(
            $repo->find($inviteToken, 'invite'),
            'invite token must survive when a reset token is created for the same user'
        );
    }

    // =========================================================================
    // 4. find() accepts a valid, unexpired, unused token
    // =========================================================================

    #[Test]
    public function findAcceptsValidToken(): void
    {
        $userId = $this->seedUserWithEmail();
        $repo   = $this->makeRepo();

        $plain = $repo->create($userId, 'reset', 2);
        $row   = $repo->find($plain, 'reset');

        $this->assertNotNull($row, 'find() must return a row for a valid token');
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame('reset', $row['type']);
        $this->assertNull($row['used_at']);
    }

    // =========================================================================
    // 5. find() rejects an expired token
    // =========================================================================

    #[Test]
    public function findRejectsExpiredToken(): void
    {
        $userId = $this->seedUserWithEmail();

        // Insert a token that expired in the past
        $plain     = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plain);
        $pastDate  = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, type, expires_at)
             VALUES (:uid, :hash, :type, :exp)'
        )->execute([':uid' => $userId, ':hash' => $hash, ':type' => 'reset', ':exp' => $pastDate]);

        $this->assertNull(
            $this->makeRepo()->find($plain, 'reset'),
            'find() must reject an expired token'
        );
    }

    // =========================================================================
    // 6. find() rejects an already-consumed token
    // =========================================================================

    #[Test]
    public function findRejectsConsumedToken(): void
    {
        $userId = $this->seedUserWithEmail();
        $repo   = $this->makeRepo();

        $plain = $repo->create($userId, 'reset', 2);
        $row   = $repo->find($plain, 'reset');
        $this->assertNotNull($row);

        $repo->consume((int) $row['id']);

        $this->assertNull(
            $repo->find($plain, 'reset'),
            'find() must reject a token that has already been consumed'
        );
    }

    // =========================================================================
    // 7. find() rejects a token presented with the wrong type
    // =========================================================================

    #[Test]
    public function findRejectsTokenWithWrongType(): void
    {
        $userId = $this->seedUserWithEmail();
        $repo   = $this->makeRepo();

        // Create an 'invite' token but try to use it as a 'reset' token
        $plain = $repo->create($userId, 'invite', 72);

        $this->assertNull(
            $repo->find($plain, 'reset'),
            'find() must reject a token whose type does not match'
        );
    }

    // =========================================================================
    // 8. find() rejects a completely fabricated token
    // =========================================================================

    #[Test]
    public function findRejectsFabricatedToken(): void
    {
        $this->assertNull(
            $this->makeRepo()->find(str_repeat('a', 64), 'reset'),
            'find() must return null for a token that does not exist in the DB'
        );
    }

    // =========================================================================
    // 9. consume() makes a token permanently unusable
    // =========================================================================

    #[Test]
    public function consumeMakesTokenPermanentlyUnusable(): void
    {
        $userId = $this->seedUserWithEmail();
        $repo   = $this->makeRepo();

        $plain = $repo->create($userId, 'reset', 2);
        $row   = $repo->find($plain, 'reset');
        $this->assertNotNull($row, 'Precondition: token must be findable before consume()');

        $repo->consume((int) $row['id']);

        $this->assertNull(
            $repo->find($plain, 'reset'),
            'Token must be unusable after consume() – must not be accepted on a second request'
        );
    }

    // =========================================================================
    // 10. purgeExpired() removes expired and used tokens; valid tokens survive
    // =========================================================================

    #[Test]
    public function purgeExpiredRemovesStaleTokensLeavesValidOnes(): void
    {
        $userId = $this->seedUserWithEmail();
        $repo   = $this->makeRepo();

        $future = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $past   = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $now    = date('Y-m-d H:i:s');

        // Valid token – must survive
        $validPlain = $repo->create($userId, 'reset', 2);

        // Expired token (past expires_at)
        $expiredPlain = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, type, expires_at)
             VALUES (:uid, :hash, "invite", :exp)'
        )->execute([':uid' => $userId, ':hash' => hash('sha256', $expiredPlain), ':exp' => $past]);

        // Used token (used_at IS NOT NULL, still within validity window)
        $usedPlain = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, type, expires_at, used_at)
             VALUES (:uid, :hash, "invite", :exp, :used)'
        )->execute([':uid' => $userId, ':hash' => hash('sha256', $usedPlain), ':exp' => $future, ':used' => $now]);

        $deleted = $repo->purgeExpired();

        $this->assertGreaterThanOrEqual(2, $deleted, 'purgeExpired() must remove at least the expired and used tokens');
        $this->assertNotNull(
            $repo->find($validPlain, 'reset'),
            'Valid token must survive purgeExpired()'
        );
    }
}
