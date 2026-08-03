<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Verifies the SV-2.7 per-request auth-status cache in {@see AuthManager}:
 *
 *  - a repeat lookup for the same user within the TTL is served from the
 *    in-worker cache (no repeat `UserRepository::getStatus()` call);
 *  - a stale (TTL-expired) entry recomputes from the DB;
 *  - {@see AuthManager::invalidateUserStatusCache()} forces an immediate
 *    recompute even while the entry is still within its TTL — the real
 *    in-process trigger being {@see \Phlix\Server\Http\Controllers\Admin\AdminUserController},
 *    covered separately in {@see \Phlix\Tests\Unit\Server\Http\Controllers\Admin\AdminUserControllerTest};
 *  - the cache is bounded (LRU) so a worker cannot accumulate one entry per
 *    distinct user forever.
 *
 */
final class AuthManagerStatusCacheTest extends TestCase
{
    private function silentLogger(): StructuredLogger
    {
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => sys_get_temp_dir() . '/phlix_statuscache_' . uniqid('', true) . '.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ]);
    }

    private function manager(UserRepository $repo, JwtHandler $jwt): AuthManager
    {
        return new AuthManager(
            $repo,
            $jwt,
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );
    }

    private function jwt(): JwtHandler
    {
        return new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
    }

    // ─────────────────────────────────────────────────────────────────
    // cache hit — repeat lookups within the TTL serve from cache
    // ─────────────────────────────────────────────────────────────────

    public function test_repeat_validate_access_token_within_ttl_hits_db_only_once(): void
    {
        $jwt = $this->jwt();
        $token = $jwt->createAccessToken('user-1');

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('getStatus')
            ->with('user-1')
            ->willReturn('active');

        $manager = $this->manager($repo, $jwt);

        $first = $manager->validateAccessToken($token);
        $second = $manager->validateAccessToken($token);
        $third = $manager->validateAccessToken($token);

        $this->assertIsArray($first);
        $this->assertSame('user-1', $first['user_id']);
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    public function test_refresh_token_within_ttl_after_validate_access_token_hits_db_only_once(): void
    {
        // The cache is keyed by userId, not by call site, so a validateAccessToken()
        // hit warms the cache for a subsequent refreshToken() call on the same user.
        $jwt = $this->jwt();
        $accessToken = $jwt->createAccessToken('user-1');
        $refreshToken = $jwt->createRefreshToken('user-1');

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('getStatus')
            ->with('user-1')
            ->willReturn('active');
        $repo->method('findById')->willReturn([
            'id' => 'user-1',
            'username' => 'nina',
            'status' => 'active',
        ]);
        $repo->method('mustChangePassword')->willReturn(false);

        $manager = $this->manager($repo, $jwt);

        $this->assertIsArray($manager->validateAccessToken($accessToken));
        $result = $manager->refreshToken($refreshToken);
        $this->assertArrayHasKey('access_token', $result);
    }

    // ─────────────────────────────────────────────────────────────────
    // expiry — a stale (TTL-elapsed) entry recomputes from the DB
    // ─────────────────────────────────────────────────────────────────

    public function test_validate_access_token_recomputes_after_ttl_expires(): void
    {
        $jwt = $this->jwt();
        $token = $jwt->createAccessToken('user-1');

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->exactly(2))
            ->method('getStatus')
            ->with('user-1')
            ->willReturn('active');

        $manager = $this->manager($repo, $jwt);

        $this->assertIsArray($manager->validateAccessToken($token));

        // Force the cached entry to be older than the TTL without waiting 5
        // real seconds — directly age the recorded hrtime() timestamp.
        $this->expireCachedEntry($manager, 'user-1');

        $this->assertIsArray($manager->validateAccessToken($token));
    }

    // ─────────────────────────────────────────────────────────────────
    // revocation within the TTL — the real in-process invalidation trigger
    // ─────────────────────────────────────────────────────────────────

    /**
     * Proves invalidateUserStatusCache() forces an immediate recompute even
     * though the cached entry is still fresh (well within the TTL) — this is
     * exactly what an in-process admin disable/approve/delete action
     * ({@see \Phlix\Server\Http\Controllers\Admin\AdminUserController}) relies
     * on so the SAME worker's very next request for that user sees the change
     * immediately, instead of waiting out the TTL.
     */
    public function test_invalidate_user_status_cache_forces_recompute_within_ttl(): void
    {
        $jwt = $this->jwt();
        $token = $jwt->createAccessToken('user-1');

        $callCount = 0;
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->exactly(2))
            ->method('getStatus')
            ->with('user-1')
            ->willReturnCallback(function () use (&$callCount): string {
                $callCount++;
                // First read (still active): the account is disabled by an
                // admin action *between* the two validateAccessToken() calls.
                return $callCount === 1 ? 'active' : 'disabled';
            });

        $manager = $this->manager($repo, $jwt);

        // First request: caches 'active'.
        $this->assertIsArray($manager->validateAccessToken($token));

        // Simulate AdminUserController::disable() calling this immediately
        // after UserRepository::setStatus() — no TTL wait involved.
        $manager->invalidateUserStatusCache('user-1');

        // Second request, still well within the 5s TTL: must re-hit the DB
        // (not serve the stale cached 'active') and see the account revoked.
        $this->assertNull($manager->validateAccessToken($token));
    }

    public function test_invalidate_user_status_cache_on_unknown_user_is_a_harmless_noop(): void
    {
        $jwt = $this->jwt();
        $repo = $this->createMock(UserRepository::class);
        $manager = $this->manager($repo, $jwt);

        // Nothing cached yet for 'ghost-user' — invalidating must not error.
        $manager->invalidateUserStatusCache('ghost-user');
        $this->addToAssertionCount(1);
    }

    // ─────────────────────────────────────────────────────────────────
    // bounded cache — LRU eviction beyond the hard cap
    // ─────────────────────────────────────────────────────────────────

    /**
     * Without a bound, a single long-lived worker would accumulate one entry
     * per distinct user that ever authenticated against it. This drives the
     * cache past USER_STATUS_CACHE_MAX to exercise the eviction branch and
     * proves both the hard cap and that a recently-touched (hot) user
     * survives while a genuinely cold one is evicted first — the same
     * insertion-order LRU contract as
     * {@see \Phlix\Media\Library\ItemRepository::$genreFacetCache}.
     */
    public function test_user_status_cache_evicts_oldest_user_beyond_bound(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('getStatus')->willReturn('active');

        $manager = $this->manager($repo, $this->jwt());

        $maxConst = (new ReflectionClassConstant(AuthManager::class, 'USER_STATUS_CACHE_MAX'))->getValue();
        $max = is_int($maxConst) ? $maxConst : 0;
        $this->assertGreaterThan(0, $max);

        $getCachedUserStatus = new ReflectionMethod(AuthManager::class, 'getCachedUserStatus');
        $getCachedUserStatus->setAccessible(true);

        // Fill exactly to the bound (user-0 .. user-(max-1)) — no eviction yet.
        for ($i = 0; $i < $max; $i++) {
            $getCachedUserStatus->invoke($manager, 'user-' . $i);
        }

        $cacheProp = new ReflectionProperty(AuthManager::class, 'userStatusCache');
        $cacheProp->setAccessible(true);
        $cacheAtBound = $cacheProp->getValue($manager);
        $this->assertCount($max, is_array($cacheAtBound) ? $cacheAtBound : [], 'at the bound, nothing evicted yet');

        // Touch the oldest user so it becomes MRU (hot), making user-1 the new oldest.
        $getCachedUserStatus->invoke($manager, 'user-0');

        // One more distinct user overflows the bound → the coldest (untouched)
        // user is evicted, not the just-touched user-0.
        $getCachedUserStatus->invoke($manager, 'user-' . $max);

        $cacheAfter = $cacheProp->getValue($manager);
        $keys = array_keys(is_array($cacheAfter) ? $cacheAfter : []);
        $this->assertCount($max, $keys, 'the map stays hard-capped at the bound');
        $this->assertNotContains('user-1', $keys, 'the coldest (untouched) user was evicted first (LRU)');
        $this->assertContains('user-0', $keys, 'a recently-touched hot user survives eviction');
        $this->assertContains('user-' . $max, $keys, 'the newest user is retained');
    }

    /**
     * Directly ages a cached entry's `cachedAt` hrtime beyond the TTL so the
     * next lookup takes the "expired" branch without a real 5-second sleep.
     */
    private function expireCachedEntry(AuthManager $manager, string $userId): void
    {
        $ttlConst = (new ReflectionClassConstant(AuthManager::class, 'USER_STATUS_CACHE_TTL_NS'))->getValue();
        $ttl = is_int($ttlConst) ? $ttlConst : 5_000_000_000;

        $cacheProp = new ReflectionProperty(AuthManager::class, 'userStatusCache');
        $cacheProp->setAccessible(true);

        /** @var array<string, array{status: string, cachedAt: int}> $cache */
        $cache = $cacheProp->getValue($manager);
        $this->assertArrayHasKey($userId, $cache, 'entry must already be cached before it can be expired');
        $cache[$userId]['cachedAt'] = (int) hrtime(true) - $ttl - 1_000_000_000;
        $cacheProp->setValue($manager, $cache);
    }
}
