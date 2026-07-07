<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\UserRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Cache behavior tests for {@see UserRepository}.
 *
 * Tests cache hit, cache miss, and cache invalidation scenarios.
 *
 * @covers \Phlix\Auth\UserRepository
 */
final class UserRepositoryCacheTest extends TestCase
{
    private Connection $db;
    private UserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(Connection::class);
        $this->repo = new UserRepository($this->db);
        $this->repo->clearCache();
    }

    protected function tearDown(): void
    {
        $this->repo->clearCache();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────
    // findById cache tests
    // ─────────────────────────────────────────────────────────────────

    public function testFindByIdHitsCacheOnSecondCall(): void
    {
        $user = ['id' => 'user-1', 'username' => 'test', 'email' => 'test@example.com'];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT * FROM users WHERE id = ?'),
                ['user-1']
            )
            ->willReturn([$user]);

        // First call - should hit DB
        $result1 = $this->repo->findById('user-1');
        $this->assertSame('user-1', $result1['id']);

        // Second call - should hit cache (DB not called again)
        $result2 = $this->repo->findById('user-1');
        $this->assertSame('user-1', $result2['id']);
    }

    public function testFindByIdCacheStoresCorrectData(): void
    {
        $user = ['id' => 'user-1', 'username' => 'test', 'email' => 'test@example.com'];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn([$user]);

        // First call hits DB
        $result1 = $this->repo->findById('user-1');

        // Second call should return identical data from cache
        $result2 = $this->repo->findById('user-1');

        $this->assertSame($result1['id'], $result2['id']);
        $this->assertSame($result1['username'], $result2['username']);
        $this->assertSame($result1['email'], $result2['email']);
    }

    public function testFindByIdCacheNotUsedForDifferentId(): void
    {
        $user1 = ['id' => 'user-1', 'username' => 'test1', 'email' => 'test1@example.com'];
        $user2 = ['id' => 'user-2', 'username' => 'test2', 'email' => 'test2@example.com'];

        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls([$user1], [$user2]);

        $this->repo->findById('user-1');
        $this->repo->findById('user-2');
    }

    public function testFindByIdCacheMissWhenKeyNotCached(): void
    {
        $user = ['id' => 'user-1', 'username' => 'test', 'email' => 'test@example.com'];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn([$user]);

        // Clear cache to ensure test isolation
        $this->repo->clearCache();

        $result = $this->repo->findById('user-1');
        $this->assertSame('user-1', $result['id']);
    }

    // ─────────────────────────────────────────────────────────────────
    // findByUsername cache tests
    // ─────────────────────────────────────────────────────────────────

    public function testFindByUsernameHitsCacheOnSecondCall(): void
    {
        $user = ['id' => 'user-1', 'username' => 'testuser', 'email' => 'test@example.com'];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT * FROM users WHERE username = ?'),
                ['testuser']
            )
            ->willReturn([$user]);

        // First call - should hit DB
        $result1 = $this->repo->findByUsername('testuser');
        $this->assertSame('testuser', $result1['username']);

        // Second call - should hit cache
        $result2 = $this->repo->findByUsername('testuser');
        $this->assertSame('testuser', $result2['username']);
    }

    public function testFindByUsernameCacheNotUsedForDifferentUsername(): void
    {
        $user1 = ['id' => 'user-1', 'username' => 'user1', 'email' => 'user1@example.com'];
        $user2 = ['id' => 'user-2', 'username' => 'user2', 'email' => 'user2@example.com'];

        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls([$user1], [$user2]);

        $this->repo->findByUsername('user1');
        $this->repo->findByUsername('user2');
    }

    // ─────────────────────────────────────────────────────────────────
    // findByEmail cache tests
    // ─────────────────────────────────────────────────────────────────

    public function testFindByEmailHitsCacheOnSecondCall(): void
    {
        $user = ['id' => 'user-1', 'username' => 'test', 'email' => 'test@example.com'];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT * FROM users WHERE email = ?'),
                ['test@example.com']
            )
            ->willReturn([$user]);

        // First call - should hit DB
        $result1 = $this->repo->findByEmail('test@example.com');
        $this->assertSame('test@example.com', $result1['email']);

        // Second call - should hit cache
        $result2 = $this->repo->findByEmail('test@example.com');
        $this->assertSame('test@example.com', $result2['email']);
    }

    // ─────────────────────────────────────────────────────────────────
    // getStatus cache tests
    // ─────────────────────────────────────────────────────────────────

    public function testGetStatusHitsCacheOnSecondCall(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT status FROM users WHERE id = ?'),
                ['user-1']
            )
            ->willReturn([['status' => 'active']]);

        // First call - should hit DB
        $result1 = $this->repo->getStatus('user-1');
        $this->assertSame('active', $result1);

        // Second call - should hit cache
        $result2 = $this->repo->getStatus('user-1');
        $this->assertSame('active', $result2);
    }

    // ─────────────────────────────────────────────────────────────────
    // Cache invalidation tests
    // ─────────────────────────────────────────────────────────────────

    public function testDeleteInvalidatesCache(): void
    {
        $user = ['id' => 'user-1', 'username' => 'test', 'email' => 'test@example.com'];

        // Call sequence:
        // 1. findById - SELECT (caches result)
        // 2. delete - DELETE (invalidates cache)
        // 3. findById - SELECT (cache miss after delete)
        $callCount = 0;
        $this->db->method('query')->willReturnCallback(
            function (string $sql) use (&$callCount, $user): mixed {
                $callCount++;
                if (str_contains($sql, 'DELETE FROM users')) {
                    return true;
                }
                return [$user];
            }
        );

        $this->repo->findById('user-1');
        $this->assertSame(1, $callCount, 'First findById should hit DB');

        $this->repo->delete('user-1');
        $this->assertSame(2, $callCount, 'Delete should be called');

        $this->repo->findById('user-1');
        $this->assertSame(3, $callCount, 'Second findById should hit DB after cache invalidation');
    }

    public function testSetAdminInvalidatesCache(): void
    {
        $user = ['id' => 'user-1', 'username' => 'test', 'email' => 'test@example.com'];

        $callCount = 0;
        $this->db->method('query')->willReturnCallback(
            function (string $sql) use (&$callCount, $user): mixed {
                $callCount++;
                if (str_contains($sql, 'UPDATE users SET is_admin')) {
                    return true;
                }
                return [$user];
            }
        );

        $this->repo->findById('user-1');
        $this->assertSame(1, $callCount, 'First findById should hit DB');

        $this->repo->setAdmin('user-1', true);
        $this->assertSame(2, $callCount, 'setAdmin should be called');

        $this->repo->findById('user-1');
        $this->assertSame(3, $callCount, 'Second findById should hit DB after cache invalidation');
    }

    public function testSetStatusInvalidatesBothUserAndStatusCache(): void
    {
        $user = ['id' => 'user-1', 'username' => 'test', 'email' => 'test@example.com'];

        $callCount = 0;
        $this->db->method('query')->willReturnCallback(
            function (string $sql) use (&$callCount, $user): mixed {
                $callCount++;
                if (str_contains($sql, 'UPDATE users SET status')) {
                    return true;
                }
                if (str_contains($sql, 'SELECT status')) {
                    return [['status' => 'active']];
                }
                return [$user];
            }
        );

        $this->repo->findById('user-1');
        $this->assertSame(1, $callCount);

        $this->repo->getStatus('user-1');
        $this->assertSame(2, $callCount);

        $this->repo->setStatus('user-1', 'disabled');
        $this->assertSame(3, $callCount, 'setStatus should be called');

        $this->repo->findById('user-1');
        $this->assertSame(4, $callCount, 'findById should hit DB after invalidation');

        $this->repo->getStatus('user-1');
        $this->assertSame(5, $callCount, 'getStatus should hit DB after invalidation');
    }

    public function testClearCacheRemovesAllCachedEntries(): void
    {
        $user1 = ['id' => 'user-1', 'username' => 'test1', 'email' => 'test1@example.com'];
        $user2 = ['id' => 'user-2', 'username' => 'test2', 'email' => 'test2@example.com'];

        $callCount = 0;
        $this->db->method('query')->willReturnCallback(
            function (string $sql) use (&$callCount, $user1, $user2): mixed {
                $callCount++;
                if (str_contains($sql, 'SELECT status')) {
                    return [['status' => 'active']];
                }
                return [$user1];
            }
        );

        // Populate caches (3 DB calls)
        $this->repo->findById('user-1');
        $this->repo->findById('user-2');
        $this->repo->getStatus('user-1');
        $this->assertSame(3, $callCount, 'Should have 3 DB calls for cache population');

        // Clear all caches
        $this->repo->clearCache();

        // Next calls should all hit DB again (3 more DB calls)
        $this->repo->findById('user-1');
        $this->repo->findById('user-2');
        $this->repo->getStatus('user-1');
        $this->assertSame(6, $callCount, 'Should have 6 DB calls after cache clear');
    }

    // ─────────────────────────────────────────────────────────────────
    // Cache returns null for non-existent users (no caching null)
    // ─────────────────────────────────────────────────────────────────

    public function testFindByIdDoesNotCacheNullResult(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturn([]);

        // First call returns null
        $result1 = $this->repo->findById('ghost');
        $this->assertNull($result1);

        // Second call should also hit DB (null is not cached)
        $result2 = $this->repo->findById('ghost');
        $this->assertNull($result2);
    }

    public function testFindByUsernameDoesNotCacheNullResult(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturn([]);

        $result1 = $this->repo->findByUsername('ghost');
        $this->assertNull($result1);

        $result2 = $this->repo->findByUsername('ghost');
        $this->assertNull($result2);
    }

    public function testFindByEmailDoesNotCacheNullResult(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturn([]);

        $result1 = $this->repo->findByEmail('ghost@example.com');
        $this->assertNull($result1);

        $result2 = $this->repo->findByEmail('ghost@example.com');
        $this->assertNull($result2);
    }
}
