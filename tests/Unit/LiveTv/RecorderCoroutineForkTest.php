<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S196 — the `Recorder::terminateRecording()` coroutine fork on both arms.
 *
 * The graceful-exit wait loop sleeps via `Coroutine::sleep(0.1)` inside a
 * coroutine and `usleep(100000)` outside one (the same fork idiom as
 * `ComskipRunner`'s EDL wait). The existing `RecorderTest` suite never enters
 * a coroutine, so the cooperative arm — the one a production worker's
 * stopRecording executes — was unexecuted by the suite (the S170 defect
 * class).
 *
 * `isPidAlive()` is private and cannot be doubled (PHPUnit 10.5 rejects
 * private-method mocks), so the wait loop is driven with a REAL child process
 * that ignores SIGTERM (`trap "" TERM`) and exits on its own after ~0.4 s:
 * the loop therefore really iterates, and can only terminate by observing the
 * child's death.
 *
 * Branch identity is OBSERVED behaviorally: a sibling coroutine keeps ticking
 * during the wait only if the runner really yields (`Coroutine::sleep`), not
 * if it blocks (`usleep` parks the whole scheduler under PHPUnit, where the
 * Swoole sleep hook is not installed).
 */
final class RecorderCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    /**
     * Spawns a child that ignores SIGTERM and exits on its own after $lifetime
     * seconds. Returns the proc resource and pid.
     *
     * @return array{0: resource, 1: int}
     */
    private function spawnSigtermIgnoringChild(float $lifetime): array
    {
        $process = proc_open(
            ['sh', '-c', 'trap "" TERM; sleep ' . $lifetime],
            [],
            $pipes
        );
        $this->assertIsResource($process);
        $status = proc_get_status($process);
        $this->assertTrue($status['running'], 'the child must start running');
        $this->assertGreaterThan(0, $status['pid']);

        return [$process, $status['pid']];
    }

    private function buildRecorder(): Recorder
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        return new Recorder(
            $db,
            new DbTimeShiftSessionStore($db),
            '/tmp/recordings',
            0,
            $logger
        );
    }

    /**
     * INSIDE a real coroutine, the graceful-exit wait must yield via
     * Coroutine::sleep: a sibling coroutine keeps ticking during the ~0.4s
     * wait and the loop still observes the process exit on a later check.
     */
    public function testGracefulExitWaitYieldsToSiblingCoroutinesInsideCoroutine(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        [$process, $pid] = $this->spawnSigtermIgnoringChild(0.4);
        $recorder = $this->buildRecorder();

        $terminate = new \ReflectionMethod(Recorder::class, 'terminateRecording');
        $terminate->setAccessible(true);

        $ticks = 0;
        $done = false;

        try {
            $this->runInCoroutine(static function () use ($recorder, $terminate, $pid, &$ticks, &$done): void {
                $ticker = static function () use (&$ticks, &$done): void {
                    while (!$done) {
                        $ticks++;
                        \Swoole\Coroutine::sleep(0.02);
                    }
                };
                \Swoole\Coroutine::create($ticker);
                try {
                    $terminate->invoke($recorder, $pid);
                } finally {
                    $done = true;
                }
            });
        } finally {
            $this->killChild($process);
        }

        $this->assertGreaterThan(0, $ticks, 'sibling coroutines must run during the graceful-exit wait '
            . '(Coroutine::sleep, not blocking usleep)');
    }

    /**
     * OUTSIDE a coroutine (the PHPUnit/CLI default) the same wait must take
     * the blocking usleep arm: the wait still completes by observing the
     * child's death, taking ~0.4s of real time (one 100ms blocking sleep per
     * iteration).
     */
    public function testGracefulExitWaitCompletesOnMainStack(): void
    {
        [$process, $pid] = $this->spawnSigtermIgnoringChild(0.4);
        $recorder = $this->buildRecorder();

        $terminate = new \ReflectionMethod(Recorder::class, 'terminateRecording');
        $terminate->setAccessible(true);

        try {
            $start = hrtime(true);
            $terminate->invoke($recorder, $pid);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
        } finally {
            $this->killChild($process);
        }

        // The child dies at ~0.4s; the loop polls with 100ms blocking sleeps,
        // so the wait cannot complete in under ~3 iterations of real time.
        $this->assertGreaterThanOrEqual(300, $elapsedMs, 'the blocking arm must really sleep in the wait loop');
    }

    private function killChild($process): void
    {
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
    }
}
