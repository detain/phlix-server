<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Provider;

use PHPUnit\Framework\TestCase;

/**
 * SV-4.5 / S-F16 — the MusicBrainz/AudioDb rate limiter must be:
 *  - static-per-host (SHARED across provider instances, keyed by the target
 *    host), so N concurrent instances don't collectively exceed the limit;
 *  - BOUNDED (LRU-capped) so the host map can't grow without limit in a
 *    resident Workerman worker;
 *  - coroutine-aware — inside a Swoole coroutine the wait yields the event
 *    loop via {@see \Swoole\Coroutine::sleep()} rather than blocking usleep.
 */
final class MusicMetadataRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        RateLimitTraitFixture::resetRateLimiterState();
    }

    protected function tearDown(): void
    {
        RateLimitTraitFixture::resetRateLimiterState();
    }

    /**
     * The bucket key is derived from the provider's BASE_URL host, so instances
     * of the same provider (or different providers on the same host) share one
     * bucket while distinct hosts stay independent.
     */
    public function testBucketKeyIsHostFromBaseUrl(): void
    {
        $fixture = new RateLimitTraitFixture();
        $this->assertSame('ratelimit.test', $fixture->bucketKey());
    }

    /**
     * The limiter state is static, so a SECOND instance observes the FIRST
     * instance's usage for the same host: it must wait out the remaining
     * window. A per-instance limiter (the old bug) would let the second
     * instance fire immediately with no wait.
     */
    public function testStateIsSharedAcrossInstancesForSameHost(): void
    {
        $first = new RateLimitTraitFixture();
        $second = new RateLimitTraitFixture();

        // First instance records a request timestamp (no prior state → no wait).
        $first->applyRateLimit(0.2);

        // Second instance, a DISTINCT object, must observe the first's usage
        // and block for (close to) the remaining 0.2s window.
        $start = hrtime(true);
        $second->applyRateLimit(0.2);
        $elapsedSec = (hrtime(true) - $start) / 1_000_000_000.0;

        $this->assertGreaterThanOrEqual(
            0.15,
            $elapsedSec,
            'A second instance must observe the shared static state and wait out the window.'
        );
    }

    /**
     * A fresh bucket (no prior request) must NOT wait.
     */
    public function testFirstRequestForHostDoesNotWait(): void
    {
        $fixture = new RateLimitTraitFixture();

        $start = hrtime(true);
        $fixture->applyRateLimit(1.0);
        $elapsedSec = (hrtime(true) - $start) / 1_000_000_000.0;

        $this->assertLessThan(0.05, $elapsedSec, 'The first request for a host must not block.');
    }

    /**
     * The static host map is LRU-bounded: once it exceeds the cap a new host
     * evicts the oldest entry so it can never grow without limit.
     */
    public function testHostMapIsBoundedWithLruEviction(): void
    {
        $cap = RateLimitTraitFixture::hostCap();

        // Pre-fill the static map to exactly the cap with synthetic hosts,
        // oldest-first (insertion order == LRU order).
        $prefill = [];
        for ($i = 0; $i < $cap; $i++) {
            $prefill['host-' . $i . '.example'] = microtime(true);
        }
        RateLimitTraitFixture::setRateLimiterState($prefill);

        // A real request adds this fixture's host (cap+1) → one eviction.
        (new RateLimitTraitFixture())->applyRateLimit(1.0);

        $state = RateLimitTraitFixture::getRateLimiterState();

        $this->assertCount($cap, $state, 'The host map must stay bounded at the cap.');
        $this->assertArrayNotHasKey('host-0.example', $state, 'The oldest (LRU) host must be evicted.');
        $this->assertArrayHasKey('ratelimit.test', $state, 'The newest host must be present.');
    }

    /**
     * In-coroutine the wait must use the cooperative {@see \Swoole\Coroutine::sleep()}
     * (which yields the scheduler) and NOT a blocking usleep. Proven behaviorally:
     * a second coroutine started alongside a sleeping rate-limit wait must get to
     * run DURING the wait. Under a blocking usleep the scheduler would be pinned
     * and the sibling could not interleave.
     *
     * @requires extension swoole
     */
    public function testWaitYieldsEventLoopInsideCoroutine(): void
    {
        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required for the coroutine-yield test.');
        }

        $order = [];

        \Swoole\Coroutine\run(function () use (&$order): void {
            RateLimitTraitFixture::resetRateLimiterState();

            // Seed the bucket "now" so the next request must wait the full window.
            RateLimitTraitFixture::setRateLimiterState(['ratelimit.test' => microtime(true)]);

            $chan = new \Swoole\Coroutine\Channel(2);

            \Swoole\Coroutine\go(function () use (&$order, $chan): void {
                $order[] = 'wait-start';
                (new RateLimitTraitFixture())->applyRateLimit(0.2);
                $order[] = 'wait-end';
                $chan->push(true);
            });

            \Swoole\Coroutine\go(function () use (&$order, $chan): void {
                // Sibling runs while the first coroutine is cooperatively sleeping.
                \Swoole\Coroutine::sleep(0.02);
                $order[] = 'sibling-ran';
                $chan->push(true);
            });

            $chan->pop(2.0);
            $chan->pop(2.0);
        });

        $siblingIdx = array_search('sibling-ran', $order, true);
        $waitEndIdx = array_search('wait-end', $order, true);

        $this->assertNotFalse($siblingIdx, 'sibling coroutine must run');
        $this->assertNotFalse($waitEndIdx, 'rate-limit wait must complete');
        $this->assertLessThan(
            $waitEndIdx,
            $siblingIdx,
            'sibling must interleave DURING the cooperative wait — proving Coroutine::sleep (not blocking usleep).'
        );
    }
}
