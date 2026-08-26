<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use Phlix\LiveTv\ComskipRunner;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;

/**
 * S196 — the `ComskipRunner` coroutine forks on both arms.
 *
 * Two guarded sites pick `Coroutine::sleep()` over `usleep()` inside a
 * coroutine: the EDL wait loop in `run()` and the private `nonBlockingSleep()`
 * helper. Neither was ever executed by the suite (the existing tests stay on
 * the main stack), so the branch a production worker's comskip completion
 * actually executes was unproven.
 *
 * The EDL loop is driven deterministically by a mock comskip that backgrounds
 * a grandchild to touch the EDL file one second after the runner starts
 * waiting. Branch identity is OBSERVED behaviorally: a sibling coroutine keeps
 * ticking during the wait only if the runner really yields (Coroutine::sleep),
 * not if it blocks (usleep).
 */
final class ComskipRunnerCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phlix_comskip_fork_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /**
     * A mock comskip that exits immediately but backgrounds a grandchild which
     * touches the EDL file 1s later — the runner's EDL wait loop therefore
     * iterates for ~0.3s, long enough to observe the coroutine yield.
     */
    private function delayedEdlScript(): string
    {
        $script = $this->tempDir . '/comskip_delayed_edl';
        file_put_contents($script, <<<'SCRIPT'
#!/bin/bash
recording_path="${@: -1}"
basename=$(basename "$recording_path" .ts)
edl_dir=$(dirname "$recording_path")
( sleep 0.3; touch "$edl_dir/${basename}.edl" ) &
exit 0
SCRIPT);
        chmod($script, 0755);
        return $script;
    }

    /**
     * INSIDE a real coroutine, run()'s EDL wait loop must yield via
     * Coroutine::sleep: a sibling coroutine keeps ticking during the ~1s wait
     * and the delayed EDL is still picked up.
     */
    public function testEdlWaitYieldsToSiblingCoroutinesInsideCoroutine(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $runner = new ComskipRunner($this->delayedEdlScript(), null, 10);
        $recording = $this->tempDir . '/recording.ts';
        touch($recording);

        $ticks = 0;
        $done = false;
        $edl = $this->runInCoroutine(static function () use ($runner, $recording, &$ticks, &$done): mixed {
            // Sibling coroutine that ticks every 50ms while the runner waits,
            // stopping when the runner finishes (bounded: never spins forever).
            $ticker = static function () use (&$ticks, &$done): void {
                while (!$done) {
                    $ticks++;
                    \Swoole\Coroutine::sleep(0.05);
                }
            };
            \Swoole\Coroutine::create($ticker);
            try {
                return $runner->run($recording);
            } finally {
                $done = true;
            }
        });

        $this->assertFileExists($edl, 'the delayed EDL must be found');
        $this->assertGreaterThan(0, $ticks, 'sibling coroutines must run during the EDL wait '
            . '(Coroutine::sleep, not blocking usleep)');
    }

    /**
     * The private nonBlockingSleep() helper must also yield inside a coroutine
     * (same fork idiom, second call site).
     */
    public function testNonBlockingSleepYieldsInsideCoroutine(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $runner = new ComskipRunner('/bin/true');

        $order = [];
        $sleep = new \ReflectionMethod(ComskipRunner::class, 'nonBlockingSleep');
        $sleep->setAccessible(true);

        $this->runInCoroutine(static function () use ($runner, $sleep, &$order): void {
            $chan = new \Swoole\Coroutine\Channel(2);
            \Swoole\Coroutine\go(static function () use (&$order, $chan, $sleep, $runner): void {
                $order[] = 'sleeper-start';
                $sleep->invoke($runner, 0.05);
                $order[] = 'sleeper-end';
                $chan->push(true);
            });
            \Swoole\Coroutine\go(static function () use (&$order, $chan): void {
                \Swoole\Coroutine::sleep(0.02);
                $order[] = 'sibling';
                $chan->push(true);
            });
            $chan->pop();
            $chan->pop();
        });

        $this->assertSame(
            ['sleeper-start', 'sibling', 'sleeper-end'],
            $order,
            'the sibling must interleave during nonBlockingSleep (Coroutine::sleep, not usleep)'
        );
    }
}
