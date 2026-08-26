<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;

/**
 * S196 — the `SegmentProcessRegistry::cooperativeSleep()` coroutine fork on
 * both arms.
 *
 * `cooperativeSleep()` yields via `Coroutine::sleep` inside a coroutine and
 * blocks via `usleep` outside one (the same fork idiom as `ComskipRunner`'s
 * `nonBlockingSleep()`). The existing registry tests never enter a coroutine,
 * so the cooperative arm — the one a production worker's encode kill/wait
 * paths execute — was unexecuted by the suite (the S170 defect class).
 *
 * Branch identity is OBSERVED behaviorally: a sibling coroutine keeps ticking
 * during the sleep only if the runner really yields (`Coroutine::sleep`), not
 * if it blocks (`usleep` parks the whole scheduler under PHPUnit).
 */
final class SegmentProcessRegistryCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    /**
     * INSIDE a real coroutine, cooperativeSleep() must yield via
     * Coroutine::sleep: a sibling coroutine keeps ticking during the sleep.
     */
    public function testCooperativeSleepYieldsToSiblingCoroutinesInsideCoroutine(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $registry = new SegmentProcessRegistry();
        $sleep = new \ReflectionMethod(SegmentProcessRegistry::class, 'cooperativeSleep');
        $sleep->setAccessible(true);

        $ticks = 0;
        $done = false;

        $this->runInCoroutine(static function () use ($registry, $sleep, &$ticks, &$done): void {
            $ticker = static function () use (&$ticks, &$done): void {
                while (!$done) {
                    $ticks++;
                    \Swoole\Coroutine::sleep(0.02);
                }
            };
            \Swoole\Coroutine::create($ticker);
            try {
                $sleep->invoke($registry, 0.1);
            } finally {
                $done = true;
            }
        });

        $this->assertGreaterThan(0, $ticks, 'sibling coroutines must run during cooperativeSleep '
            . '(Coroutine::sleep, not blocking usleep)');
    }

    /**
     * OUTSIDE a coroutine the same call must take the blocking usleep arm: the
     * sleep still elapses in real time.
     */
    public function testCooperativeSleepBlocksOnMainStack(): void
    {
        $registry = new SegmentProcessRegistry();
        $sleep = new \ReflectionMethod(SegmentProcessRegistry::class, 'cooperativeSleep');
        $sleep->setAccessible(true);

        $start = hrtime(true);
        $sleep->invoke($registry, 0.1);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

        $this->assertGreaterThanOrEqual(90, $elapsedMs, 'the blocking arm must actually sleep');
    }
}
