<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;

/**
 * Review finding 1: {@see SegmentProcessRegistry::kill()} must never wait.
 *
 * It used to wait out the grace between SIGTERM and SIGKILL. That wait yielded inside a
 * Swoole coroutine and blocked outside one, so a client disconnect during a live encode
 * stalled every connection on the worker. Now kill() sends TERM and returns. The SIGKILL
 * comes from sweep(). These tests run with a 2 s grace and a live group member. A kill that
 * still waited would take about 2 s, so a return inside 50 ms proves that it does not wait.
 * Both arms of the old fork are covered: inside a coroutine (skipped without ext-swoole)
 * and on the main stack.
 */
final class SegmentProcessRegistryCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    /** The grace a waiting kill would have blocked for, in seconds. */
    private const GRACE_SECONDS = 2.0;

    /** A kill that returns inside this many milliseconds is not waiting out the grace. */
    private const NO_WAIT_MS = 50.0;

    /** @var list<array{0: int, 1: int}> [pid, signal] in send order */
    private array $sent = [];

    /**
     * A registry with one live leader (4242) in its own group, under a 2 s grace, with every
     * `/proc` read and every signal answered by fakes.
     */
    private function liveRegistry(): SegmentProcessRegistry
    {
        $this->sent = [];
        $registry = new SegmentProcessRegistry(
            null,
            function (int $pid, int $signal): void {
                $this->sent[] = [$pid, $signal];
            },
            static fn (int $pid): bool => true,
            self::GRACE_SECONDS,
        );
        $registry->setProcStatReader(static fn (int $pid): ?string => $pid === 4242 ? self::statLine(4242, 100) : null);
        $registry->setGroupMembersProbe(static fn (array $leaders): array => [4242 => 100]);
        $registry->setProcessSignalSender(function (int $pid, int $signal): void {
            $this->sent[] = [$pid, $signal];
        });
        $registry->register('seg-a', 4242);

        return $registry;
    }

    /** A `/proc/<pid>/stat` line with the given start time (field 22). */
    private static function statLine(int $pid, int $start): string
    {
        $fields = [
            'S', '1', (string) $pid, (string) $pid, '0', '-1',
            '0', '0', '0', '0', '0', '0', '0', '0', '0',
            '20', '0', '1', '0', (string) $start,
        ];

        return $pid . ' (ffmpeg) ' . implode(' ', $fields) . ' 0 0';
    }

    /**
     * INSIDE a coroutine, kill() must return at once. A waiting kill would have yielded
     * for the whole grace, so a sibling would have run in the meantime. It does not wait.
     */
    public function testKillReturnsAtOnceInsideACoroutine(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $elapsedMs = null;
        $this->runInCoroutine(function () use (&$elapsedMs): void {
            $registry = $this->liveRegistry();
            $start = hrtime(true);
            $registry->kill('seg-a');
            $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
        });

        $this->assertLessThan(self::NO_WAIT_MS, $elapsedMs, 'kill() must not yield for the grace inside a coroutine');
        $this->assertSame([[4242, 15]], $this->sent, 'kill() sends TERM only, and the SIGKILL waits for sweep()');
    }

    /**
     * OUTSIDE a coroutine, kill() must also return at once. A waiting kill would have
     * blocked the worker for the whole grace.
     */
    public function testKillReturnsAtOnceOnTheMainStack(): void
    {
        $registry = $this->liveRegistry();

        $start = hrtime(true);
        $registry->kill('seg-a');
        $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

        $this->assertLessThan(self::NO_WAIT_MS, $elapsedMs, 'kill() must not block the worker for the grace');
        $this->assertSame([[4242, 15]], $this->sent, 'kill() sends TERM only, and the SIGKILL waits for sweep()');
    }
}
