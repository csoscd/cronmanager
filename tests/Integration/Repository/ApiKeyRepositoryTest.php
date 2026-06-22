<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: ApiKeyRepository
 *
 * Tests the full DB lifecycle of API keys: creation, lookup by plain-text,
 * user-scoped listing, deletion, and last-used timestamp update.
 *
 * Each test runs inside a MariaDB transaction that is rolled back on tearDown()
 * so no test data leaks to the next test.
 *
 * Prerequisites:
 *   docker compose -f tests/docker-compose.test.yml up -d
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Repository;

use Cronmanager\Web\Auth\ApiKey;
use Cronmanager\Web\Auth\ApiKeyRepository;
use Cronmanager\Web\Bootstrap\ApiKeySchema;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\IntegrationTestCase;

final class ApiKeyRepositoryTest extends IntegrationTestCase
{
    private ApiKeyRepository $repo;

    // =========================================================================
    // Setup
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the api_keys table exists in the test DB (idempotent)
        ApiKeySchema::ensure($this->pdo, new Logger('test'));

        $this->repo = new ApiKeyRepository($this->pdo);
    }

    // =========================================================================
    // create
    // =========================================================================

    #[Test]
    public function createReturnsBothKeyObjectAndPlainText(): void
    {
        $userId = $this->seedUser();
        $result = $this->repo->create($userId, 'My Key', ['jobs:read'], null, null, null);

        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('plainText', $result);

        $this->assertInstanceOf(ApiKey::class, $result['key']);
        $this->assertStringStartsWith('cm_', $result['plainText']);
    }

    #[Test]
    public function createdKeyHasCorrectProperties(): void
    {
        $userId  = $this->seedUser();
        $scopes  = ['jobs:read', 'export:read'];
        $result  = $this->repo->create($userId, 'Named Key', $scopes, null, null, null);
        $key     = $result['key'];

        $this->assertSame($userId, $key->userId);
        $this->assertSame('Named Key', $key->name);
        $this->assertEqualsCanonicalizing($scopes, $key->scopes);
        $this->assertNull($key->agentIds);
        $this->assertNull($key->expiresAt);
        $this->assertNull($key->ipWhitelist);
        $this->assertNull($key->lastUsedAt);
        $this->assertGreaterThan(0, $key->id);
    }

    #[Test]
    public function createdKeyWithExpiryStoresExpiryCorrectly(): void
    {
        $userId    = $this->seedUser();
        $expiresAt = new \DateTimeImmutable('2030-12-31 23:59:59');

        $result = $this->repo->create($userId, 'Expiring Key', ['jobs:read'], null, $expiresAt, null);

        $this->assertNotNull($result['key']->expiresAt);
        $this->assertSame('2030-12-31', $result['key']->expiresAt->format('Y-m-d'));
    }

    #[Test]
    public function createdKeyWithAgentIdsStoresThemCorrectly(): void
    {
        $userId   = $this->seedUser();
        $agentIds = [1, 3, 7];
        $result   = $this->repo->create($userId, 'Restricted Key', ['jobs:read'], $agentIds, null, null);

        $this->assertSame($agentIds, $result['key']->agentIds);
    }

    #[Test]
    public function createdKeyWithIpWhitelistStoresItCorrectly(): void
    {
        $userId      = $this->seedUser();
        $ipWhitelist = ['10.0.0.0/8', '192.168.1.5/32'];
        $result      = $this->repo->create($userId, 'IP Key', ['jobs:read'], null, null, $ipWhitelist);

        $this->assertSame($ipWhitelist, $result['key']->ipWhitelist);
    }

    #[Test]
    public function plainTextKeyStartsWithCmPrefix(): void
    {
        $userId = $this->seedUser();
        $result = $this->repo->create($userId, 'Prefix Test', ['jobs:read'], null, null, null);

        $this->assertStringStartsWith('cm_', $result['plainText']);
    }

    #[Test]
    public function eachCreateCallGeneratesUniquePlainText(): void
    {
        $userId  = $this->seedUser();
        $result1 = $this->repo->create($userId, 'Key 1', ['jobs:read'], null, null, null);
        $result2 = $this->repo->create($userId, 'Key 2', ['jobs:read'], null, null, null);

        $this->assertNotSame($result1['plainText'], $result2['plainText']);
    }

    // =========================================================================
    // findByPlainText
    // =========================================================================

    #[Test]
    public function findByPlainTextReturnsKeyForValidToken(): void
    {
        $userId    = $this->seedUser();
        $result    = $this->repo->create($userId, 'Find Test', ['jobs:read'], null, null, null);
        $plainText = $result['plainText'];

        $found = $this->repo->findByPlainText($plainText);

        $this->assertNotNull($found);
        $this->assertSame($result['key']->id, $found->id);
        $this->assertSame($userId, $found->userId);
    }

    #[Test]
    public function findByPlainTextReturnsNullForUnknownToken(): void
    {
        $found = $this->repo->findByPlainText('cm_nonexistent_key_that_does_not_exist_1234');

        $this->assertNull($found);
    }

    #[Test]
    public function findByPlainTextReturnsNullForEmptyToken(): void
    {
        $found = $this->repo->findByPlainText('');

        $this->assertNull($found);
    }

    #[Test]
    public function findByPlainTextLoadsAllFields(): void
    {
        $userId      = $this->seedUser();
        $expiresAt   = new \DateTimeImmutable('2035-06-15 00:00:00');
        $agentIds    = [2, 5];
        $ipWhitelist = ['192.168.0.0/16'];
        $scopes      = ['jobs:read', 'export:read'];

        $result    = $this->repo->create($userId, 'Full Key', $scopes, $agentIds, $expiresAt, $ipWhitelist);
        $plainText = $result['plainText'];

        $found = $this->repo->findByPlainText($plainText);

        $this->assertNotNull($found);
        $this->assertEqualsCanonicalizing($scopes, $found->scopes);
        $this->assertSame($agentIds, $found->agentIds);
        $this->assertSame('2035-06-15', $found->expiresAt?->format('Y-m-d'));
        $this->assertSame($ipWhitelist, $found->ipWhitelist);
    }

    // =========================================================================
    // listForUser
    // =========================================================================

    #[Test]
    public function listForUserReturnsEmptyArrayWhenNoKeys(): void
    {
        $userId = $this->seedUser();
        $keys   = $this->repo->listForUser($userId);

        $this->assertSame([], $keys);
    }

    #[Test]
    public function listForUserReturnsOnlyKeysForThatUser(): void
    {
        $userId1 = $this->seedUser();
        $userId2 = $this->seedUser();

        $this->repo->create($userId1, 'U1 Key A', ['jobs:read'], null, null, null);
        $this->repo->create($userId1, 'U1 Key B', ['jobs:read'], null, null, null);
        $this->repo->create($userId2, 'U2 Key',   ['jobs:read'], null, null, null);

        $keys1 = $this->repo->listForUser($userId1);
        $keys2 = $this->repo->listForUser($userId2);

        $this->assertCount(2, $keys1);
        $this->assertCount(1, $keys2);
    }

    #[Test]
    public function listForUserReturnsMostRecentKeyFirst(): void
    {
        $userId = $this->seedUser();

        $this->repo->create($userId, 'Older Key', ['jobs:read'], null, null, null);
        // Brief sleep to ensure different created_at (MariaDB CURRENT_TIMESTAMP resolution = 1s)
        // Instead we manipulate the timestamp directly
        $this->pdo->exec(
            "UPDATE api_keys SET created_at = DATE_SUB(created_at, INTERVAL 5 SECOND) WHERE name = 'Older Key'"
        );
        $this->repo->create($userId, 'Newer Key', ['jobs:read'], null, null, null);

        $keys = $this->repo->listForUser($userId);

        $this->assertSame('Newer Key', $keys[0]->name);
        $this->assertSame('Older Key', $keys[1]->name);
    }

    // =========================================================================
    // deleteForUser
    // =========================================================================

    #[Test]
    public function deleteForUserReturnsTrueAndRemovesRow(): void
    {
        $userId = $this->seedUser();
        $result = $this->repo->create($userId, 'Delete Me', ['jobs:read'], null, null, null);
        $keyId  = $result['key']->id;

        $deleted = $this->repo->deleteForUser($keyId, $userId);
        $this->assertTrue($deleted);

        // Must not be findable afterwards
        $found = $this->repo->findByPlainText($result['plainText']);
        $this->assertNull($found);
    }

    #[Test]
    public function deleteForUserReturnsFalseForNonExistentKey(): void
    {
        $userId  = $this->seedUser();
        $deleted = $this->repo->deleteForUser(99999, $userId);

        $this->assertFalse($deleted);
    }

    #[Test]
    public function deleteForUserReturnsFalseForWrongOwner(): void
    {
        $userId1 = $this->seedUser();
        $userId2 = $this->seedUser();

        $result = $this->repo->create($userId1, 'Other Key', ['jobs:read'], null, null, null);
        $keyId  = $result['key']->id;

        // User2 cannot delete User1's key
        $deleted = $this->repo->deleteForUser($keyId, $userId2);
        $this->assertFalse($deleted);

        // Key must still exist
        $found = $this->repo->findByPlainText($result['plainText']);
        $this->assertNotNull($found);
    }

    // =========================================================================
    // touchLastUsed
    // =========================================================================

    #[Test]
    public function touchLastUsedUpdatesTimestamp(): void
    {
        $userId = $this->seedUser();
        $result = $this->repo->create($userId, 'Touch Key', ['jobs:read'], null, null, null);
        $keyId  = $result['key']->id;

        // Initially null
        $before = $this->repo->findByPlainText($result['plainText']);
        $this->assertNull($before?->lastUsedAt);

        $this->repo->touchLastUsed($keyId);

        $after = $this->repo->findByPlainText($result['plainText']);
        $this->assertNotNull($after?->lastUsedAt);
    }

    #[Test]
    public function touchLastUsedIgnoresUnknownId(): void
    {
        // Must not throw
        $this->expectNotToPerformAssertions();
        $this->repo->touchLastUsed(99999);
    }

    // =========================================================================
    // Cascade delete
    // =========================================================================

    #[Test]
    public function keyIsDeletedWhenOwnerIsDeleted(): void
    {
        $userId = $this->seedUser();
        $result = $this->repo->create($userId, 'Cascade Key', ['jobs:read'], null, null, null);

        // Delete the user – FK CASCADE should remove the api_key row
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);

        $found = $this->repo->findByPlainText($result['plainText']);
        $this->assertNull($found, 'API key must be deleted when its owner is deleted');
    }
}
