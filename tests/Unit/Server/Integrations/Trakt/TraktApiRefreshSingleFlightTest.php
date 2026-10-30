<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Integrations\Trakt;

use Phlix\Server\Integrations\Trakt\HttpClientInterface;
use Phlix\Server\Integrations\Trakt\TraktApi;
use Phlix\Server\Integrations\Trakt\TraktApiException;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;

/**
 * M-SERVER-37 — the single-flight refresh registry is bounded and self-cleaning.
 *
 * `refreshAfterAuthFailure()` kept a method-local static map keyed by
 * md5(refreshToken) and only ever removed entries on FAILURE paths: every
 * SUCCESS left the plaintext token result in the store for the lifetime of the
 * resident worker. Trakt rotates refresh tokens per grant, so a completed key
 * is never revisited — permanent growth plus plaintext credential retention
 * (the estate's resident-worker static-poison class). A secondary defect: the
 * Phase-1 pending spin had no ceiling, so a holder cancelled between claim and
 * completion (skipping the Throwable cleanup) poisoned its key forever.
 *
 * The cure follows the `AuthManager::$rateLimitStore` shape (TTL-on-read +
 * hard cap + evict-oldest) with a short grace window so the single-flight
 * dedupe itself keeps working: completed results serve late waiters only for
 * `REFRESH_RESULT_GRACE_SECONDS`, the store is capped at
 * `REFRESH_CACHE_MAX_ENTRIES` entries, waits are capped at
 * `REFRESH_WAIT_MAX_ATTEMPTS` attempts, and a `pending` marker older than
 * `REFRESH_PENDING_STALE_SECONDS` is taken over instead of spun on forever.
 *
 * Seam law: the registry moved from a method-local static to a private static
 * property (reflection precedent: HttpClientCoroutineForkTest in this
 * directory). Tests that PLANT entries require the seam — they are red on the
 * unbounded method-local shape. Tests that only observe dedupe/count behavior
 * (the two final ones) run green before AND after: they pin the single-flight
 * invariant the cure must not regress.
 *
 * @phpstan-type RegistryEntry array{pending:bool,at:int,result:?array<string,mixed>}
 * @phpstan-type RegistryMap array<string,RegistryEntry>
 */
final class TraktApiRefreshSingleFlightTest extends TestCase
{
    use RunsInCoroutine;

    private function api(HttpClientInterface $http): TraktApi
    {
        return new TraktApi($http, 'client-id', 'client-secret');
    }

    private function constant(string $name): mixed
    {
        return (new \ReflectionClass(TraktApi::class))->getConstant($name);
    }

    /** @return RegistryMap */
    private function registry(): array
    {
        $prop = new \ReflectionProperty(TraktApi::class, 'inFlightRefresh');
        $prop->setAccessible(true);

        /** @var RegistryMap $store */
        $store = $prop->getValue();

        return $store;
    }

    /** @param RegistryMap $store */
    private function setRegistry(array $store): void
    {
        $prop = new \ReflectionProperty(TraktApi::class, 'inFlightRefresh');
        $prop->setAccessible(true);
        $prop->setValue(null, $store);
    }

    /** Best-effort reset; tolerant of the seam not existing yet (red-run phase). */
    private function resetRegistryIfSeamExists(): void
    {
        if ((new \ReflectionClass(TraktApi::class))->hasProperty('inFlightRefresh')) {
            $this->setRegistry([]);
        }
    }

    protected function setUp(): void
    {
        $this->resetRegistryIfSeamExists();
    }

    protected function tearDown(): void
    {
        $this->resetRegistryIfSeamExists();
    }

    public static function tearDownAfterClass(): void
    {
        // The flock phase of the method under test mints the production
        // fixed-path temp lock (sys_get_temp_dir()/phlix_trakt_refresh.lock).
        // S439 zero-residue census: every phlix_* entry this suite creates
        // must be torn down by this suite — this class is its minter.
        $lock = sys_get_temp_dir() . '/phlix_trakt_refresh.lock';
        if (is_file($lock)) {
            unlink($lock);
        }
    }

    /**
     * RED on the old shape: entries that ended up as bare token arrays for
     * never-reused rotated keys had no lifetime at all. A completed result
     * older than the grace window must be shed at the next call entry — never
     * served, never lingered — because it is a plaintext credential.
     */
    public function testExpiredSuccessEntryIsPrunedNeverServed(): void
    {
        $grace = $this->constant('REFRESH_RESULT_GRACE_SECONDS');
        $this->assertIsInt($grace, 'the cure must expose REFRESH_RESULT_GRACE_SECONDS');

        $staleKey = md5('stale-rotated-token');
        $this->setRegistry([
            $staleKey => [
                'pending' => false,
                'at' => time() - ($grace + 1),
                'result' => ['access_token' => 'LEAKED', 'refresh_token' => 'LEAKED', 'expires_in' => 1],
            ],
        ]);

        $http = new RefreshCountingHttpClient();
        $result = $this->api($http)->refreshAfterAuthFailure('fresh-rotated-token');

        $this->assertSame(1, $http->postCount);
        $this->assertNotSame('LEAKED', $result['access_token'], 'an expired stored result must never be served');
        $this->assertArrayNotHasKey(
            $staleKey,
            $this->registry(),
            'expired success entries hold plaintext tokens and must be pruned at call entry'
        );
    }

    /**
     * RED on the old shape: the method-local static had no bound whatsoever.
     * A full registry must evict its oldest entry to admit the completing
     * refresh (AuthManager::boundFallbackStore idiom), keeping the store at
     * its hard cap no matter how many distinct rotated tokens flow through.
     */
    public function testRegistryIsHardCappedEvictingOldest(): void
    {
        $cap = $this->constant('REFRESH_CACHE_MAX_ENTRIES');
        $this->assertIsInt($cap, 'the cure must expose REFRESH_CACHE_MAX_ENTRIES');

        $store = [];
        $now = time();
        for ($i = 0; $i < $cap; $i++) {
            $store['seed_' . $i] = [
                'pending' => false,
                'at' => $now,
                'result' => ['access_token' => "a$i", 'refresh_token' => "r$i", 'expires_in' => 1],
            ];
        }
        $this->setRegistry($store);

        $http = new RefreshCountingHttpClient();
        $this->api($http)->refreshAfterAuthFailure('over-cap-token');

        $after = $this->registry();
        $this->assertArrayHasKey(md5('over-cap-token'), $after, 'the completing refresh must be registered');
        $this->assertCount($cap, $after, 'the registry must never exceed its hard cap');
        $this->assertArrayNotHasKey('seed_0', $after, 'the oldest entry must be the eviction victim');
    }

    /**
     * RED on the old shape: the pending spin loop had no attempt ceiling
     * (unlike the flock loop right beside it). A live-but-slow holder must
     * have waiters terminate with a loud error after the bounded wait,
     * without ever adding a second POST and without clearing the holder's
     * marker.
     */
    public function testPendingWaitIsBoundedNotInfinite(): void
    {
        $maxAttempts = $this->constant('REFRESH_WAIT_MAX_ATTEMPTS');
        $this->assertIsInt($maxAttempts, 'the cure must expose REFRESH_WAIT_MAX_ATTEMPTS');
        $this->assertGreaterThan(0, $maxAttempts);

        $token = 'contended-token';
        $this->setRegistry([
            md5($token) => ['pending' => true, 'at' => time(), 'result' => null],
        ]);

        $http = new RefreshCountingHttpClient();
        $started = microtime(true);
        try {
            $this->api($http)->refreshAfterAuthFailure($token);
            $this->fail('a bounded wait must surface a TraktApiException when the ceiling lapses');
        } catch (TraktApiException $e) {
            $this->assertMatchesRegularExpression('/wait|timed out|timeout/i', $e->getMessage());
        }

        $this->assertSame(0, $http->postCount, 'a waiter must never POST behind a live flight');
        $this->assertGreaterThanOrEqual(
            0.5,
            microtime(true) - $started,
            'the waiter must actually attempt the bounded spin (400x5ms), not skip it'
        );
        $this->assertArrayHasKey(
            md5($token),
            $this->registry(),
            'a waiter must not clear the live holder marker it timed out behind'
        );

        // The throw semantics matter as much as the ceiling: a second waiter
        // timing out behind a still-live (not stale) marker must fail loudly,
        // never hijack the flight with a second rotating-token POST.
        $takeoverHttp = new RefreshCountingHttpClient();
        try {
            $this->api($takeoverHttp)->refreshAfterAuthFailure($token);
            $this->fail('a timing-out waiter must not silently take over a live flight');
        } catch (TraktApiException) {
        }
        $this->assertSame(
            0,
            $takeoverHttp->postCount,
            'the second waiter must throw, never POST behind the live marker'
        );
    }

    /**
     * RED on the old shape: a 'pending' marker orphaned by an abnormally
     * cancelled holder poisoned its key forever (spin without ceiling, entry
     * never cleaned). Past the stale ceiling a later call must take over and
     * complete the refresh promptly instead of inheriting the poison.
     */
    public function testStalePendingMarkerIsTakenOver(): void
    {
        $staleCeiling = $this->constant('REFRESH_PENDING_STALE_SECONDS');
        $this->assertIsInt($staleCeiling, 'the cure must expose REFRESH_PENDING_STALE_SECONDS');

        $token = 'orphaned-token';
        $this->setRegistry([
            md5($token) => ['pending' => true, 'at' => time() - ($staleCeiling + 5), 'result' => null],
        ]);

        $http = new RefreshCountingHttpClient();
        $started = microtime(true);
        $result = $this->api($http)->refreshAfterAuthFailure($token);

        $this->assertSame(1, $http->postCount, 'the takeover must complete the refresh itself');
        $this->assertLessThan(1.0, microtime(true) - $started, 'takeover must not spin behind a dead marker');
        $this->assertSame('access-1', $result['access_token']);
    }

    /**
     * RED on the old shape via the seam: failed flights must leave NO marker
     * behind so a later caller retries cleanly instead of waiting on a ghost.
     */
    public function testFailurePathLeavesNoPendingMarker(): void
    {
        $token = 'failure-then-inspection';
        try {
            $this->api(new RefreshCountingHttpClient(asError: true))->refreshAfterAuthFailure($token);
            $this->fail('expected TraktApiException from the OAuth error response');
        } catch (TraktApiException $e) {
            $this->assertSame('token rejected', $e->getMessage());
        }

        $this->assertArrayNotHasKey(
            md5($token),
            $this->registry(),
            'failed flights must leave no pending marker behind'
        );
    }

    // ------------------------------------------------------------------
    // Invariant pins — green before AND after the cure. The single-flight
    // dedupe (and its flock() cross-process sibling) must keep preventing
    // concurrent 401 storms from POSTing rotating tokens over each other.
    // ------------------------------------------------------------------

    public function testLateWaiterWithinGraceReusesResultWithoutSecondPost(): void
    {
        $http = new RefreshCountingHttpClient();
        $api = $this->api($http);
        $token = 'burst-token';

        $first = $api->refreshAfterAuthFailure($token);
        $second = $api->refreshAfterAuthFailure($token);

        $this->assertSame(
            1,
            $http->postCount,
            'the second caller within the grace window must be served from the completed flight'
        );
        $this->assertSame($first, $second);
    }

    public function testConcurrentCoroutinesPostExactlyOnce(): void
    {
        // The holder yields inside the POST; the waiter must find the pending
        // marker and wait for the shared result, never POST alongside it.
        $slowHttp = new RefreshCountingHttpClient(holdMicroseconds: 80_000);
        $api = new TraktApi($slowHttp, 'client-id', 'client-secret');
        $token = 'coroutine-burst';

        $collected = $this->runInCoroutine(static function () use ($api, $token): array {
            /** @var array<string, array<string, mixed>> $results */
            $results = [];
            $wait = new \Swoole\Coroutine\WaitGroup();
            $wait->add(2);
            \Swoole\Coroutine::create(static function () use ($api, $token, $wait, &$results): void {
                $results['holder'] = $api->refreshAfterAuthFailure($token);
                $wait->done();
            });
            \Swoole\Coroutine::create(static function () use ($api, $token, $wait, &$results): void {
                $results['waiter'] = $api->refreshAfterAuthFailure($token);
                $wait->done();
            });
            $wait->wait();

            return $results;
        });

        $this->assertSame(1, $slowHttp->postCount, 'a concurrent burst must POST exactly once (single-flight)');
        $this->assertSame($collected['holder'], $collected['waiter'], 'the waiter must receive the holder result');
        $this->assertSame('shared-access', $collected['waiter']['access_token']);
    }
}

/**
 * Test-double transport for the refresh flight: counts POSTs, optionally
 * yields inside one (coroutine stampede drills) or answers with Trakt's
 * invalid_grant error body (failure-path drills). In-file fixture per the
 * tests/ phpcs ruleset (PSR1.Classes.ClassDeclaration.MultipleClasses
 * exclusion) — the estate's established shape for small doubles.
 */
final class RefreshCountingHttpClient implements HttpClientInterface
{
    public int $postCount = 0;

    public function __construct(
        private readonly int $holdMicroseconds = 0,
        private readonly bool $asError = false,
    ) {
    }

    public function get(string $url, array $params = [], array $headers = []): array
    {
        return [];
    }

    public function getWithHeaders(string $url, array $params = [], array $headers = []): array
    {
        return ['body' => [], 'headers' => []];
    }

    public function post(string $url, array $data = [], array $headers = []): array
    {
        ++$this->postCount;

        if ($this->asError) {
            return ['error' => 'invalid_grant', 'error_description' => 'token rejected'];
        }

        if ($this->holdMicroseconds > 0) {
            usleep($this->holdMicroseconds);

            return [
                'access_token' => 'shared-access',
                'refresh_token' => 'shared-rotated',
                'expires_in' => 1,
            ];
        }

        return [
            'access_token' => 'access-' . $this->postCount,
            'refresh_token' => 'rotated-' . $this->postCount,
            'expires_in' => 7_776_000,
        ];
    }
}
