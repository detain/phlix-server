<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

use function shell_exec;

/**
 * S266 — the leak guard the skip-determinism fix needs its own tripwire for.
 *
 * ## WHAT THIS PINS
 *
 * f35d994b deleted six `isTimerAvailable()` probe-gates from the
 * tests/Unit/LiveTv/Relay/ classes and instead made their outcome
 * deterministic: the new WorkermanTimerFixture (SURVIVAL_TOKEN
 * S266SKIPDETERMX9B3) installs and restores `Workerman\Timer::$event`, and
 * the four historical `Workerman\Worker::$workers` leakers (WebhookService,
 * StreamSessionService, RecordingScheduler, WebSocketServer) now snapshot
 * and restore the registry. The acceptance property is about the CHILD
 * PROCESS, not this one: after a phpunit process has actually RAN all six
 * classes, `Worker::$workers` must be [] and `Timer::$event` must be back at
 * its process-start value (null). A fixture that "restores" nothing, or a
 * re-introduced bare `new Worker()` with no restore, breaks determinism for
 * every future random seed while still passing in isolation — the exact
 * order-dependence class S266 exists to end.
 *
 * ## HOW IT MEASURES (and why not in-process)
 *
 * Statics are process-global; this process's end-state says nothing about a
 * child's. So each assertion shells out a real `vendor/bin/phpunit` child
 * with `--bootstrap tests/Support/Workerman/StaticProbeBootstrap.php`, which
 * dumps the two statics as JSON at child shutdown; the parent parses the
 * dump AND the child's `Tests:` summary line. Non-vacuity is enforced from
 * BOTH sides: a `--filter` that matches nothing would leave the statics
 * trivially pristine, so the child must report >= MIN_TESTS and 0 skipped;
 * and Test B is the positive control — the very same dump mechanism fed a
 * bare `new Worker()` with no restore must report a leak. If the probe can
 * ever stop detecting, Test B reddens first.
 *
 * The relay fixture restores `Timer::$event` per test; the four ex-leakers
 * restore `Worker::$workers` per test; neither class family leaves residue,
 * so all three gates below share one acceptance shape: exit 0, tests run,
 * zero skips, workers 0, event null.
 *
 * Runtime: two discovery-wide filtered children (~20-30 s each) + one
 * inline child (<1 s). Do not add children here; the ~90 s budget is spent.
 */
final class WorkermanStaticStateLeakGuardTest extends TestCase
{
    /** Six classes: 2 relay (fixture side) + 4 ex-leakers (registry side). */
    private const ALL_SIX_FILTER =
        '(HlsRelayManagerTest|HlsSegmentPrefetcherTest|WebhookServiceTest'
        . '|StreamSessionServiceTest|RecordingSchedulerTest|WebSocketServerTest)';

    /** Four Worker::$workers ex-leakers only — isolates them from the fixture. */
    private const EX_LEAKERS_FILTER =
        '(WebhookServiceTest|StreamSessionServiceTest|RecordingSchedulerTest|WebSocketServerTest)';

    /**
     * 6 classes hold 67 test methods (13+17 relay, 3+8+12+14 ex-leakers);
     * the floor is margin, not aspiration — it exists to catch a --filter
     * that silently matched nothing.
     */
    private const MIN_TESTS_ALL_SIX = 40;

    /** 4 ex-leaker classes hold 37 test methods; same margin rationale. */
    private const MIN_TESTS_EX_LEAKERS = 30;

    /** @var list<string> temp files (child scripts, static dumps) to unlink in tearDown */
    private array $tempPaths = [];

    public function testKnownExLeakersLeaveNoStaticResidue(): void
    {
        $probe = $this->runProbeChild(self::ALL_SIX_FILTER);

        $this->assertSame(
            0,
            $probe['exit'],
            "S266: probe child exited {$probe['exit']}.\n--- child output ---\n" . $probe['output']
        );
        $this->assertGreaterThanOrEqual(
            self::MIN_TESTS_ALL_SIX,
            $probe['tests'],
            'S266: the filter ran only ' . $probe['tests'] . " tests — non-vacuity floor is "
            . self::MIN_TESTS_ALL_SIX . '; a run that matched nothing makes the pristine '
            . "statics meaningless.\n--- child output ---\n" . $probe['output']
        );
        $this->assertSame(
            0,
            $probe['skipped'],
            "S266: the six classes must ALWAYS run (the probe-gates are deleted);\n" . $probe['output']
        );
        $this->assertStaticResidue($probe['dump']);
    }

    /**
     * Positive control: a bare `new Worker()` with no restore — the exact
     * original defect shape — must be DETECTED by the same shutdown dump the
     * two pristine-gates rely on. Guards the guard against a probe that
     * silently stopped measuring.
     */
    public function testProbeDetectsABareWorkerLeak(): void
    {
        $root = $this->repoRoot();
        $dumpPath = $this->freshDumpPath();
        $scriptPath = $this->probeControlScript($root);

        $output = (string) shell_exec(
            'PHLIX_S266_STATIC_DUMP=' . escapeshellarg($dumpPath)
            . ' php ' . escapeshellarg($scriptPath) . ' 2>&1; echo "__PHLIX_S266_EXIT=$?"'
        );
        $exit = $this->childExit($output);
        $dump = $this->readDump($dumpPath);

        $this->assertSame(
            0,
            $exit,
            "S266: positive-control child exited $exit.\n--- output ---\n$output"
        );
        $this->assertSame(
            1,
            $dump['workers'],
            'S266: the probe mechanism must SEE a leaked bare Worker (this is the exact'
            . " S266 defect shape). Seeing it here proves the dump is live.\n--- output ---\n$output"
        );
        $this->assertTrue(
            $dump['timerEventNull'],
            "S266: a bare Worker must not be misread as a Timer::\$event writer.\n--- output ---\n$output"
        );
    }

    /**
     * The four Worker::$workers fixes measured alone (no relay fixture in the
     * run), so a future fixture regression cannot mask a registry leak — and
     * vice versa.
     */
    public function testFourExLeakersAloneLeaveNoStaticResidue(): void
    {
        $probe = $this->runProbeChild(self::EX_LEAKERS_FILTER);

        $this->assertSame(0, $probe['exit'], "S266: probe child exited {$probe['exit']}.\n" . $probe['output']);
        $this->assertGreaterThanOrEqual(
            self::MIN_TESTS_EX_LEAKERS,
            $probe['tests'],
            "S266: filter matched only {$probe['tests']} tests; statics from a no-op run prove nothing."
        );
        $this->assertSame(
            0,
            $probe['skipped'],
            "S266: an ex-leaker skipped — investigate the gate.\n" . $probe['output']
        );
        $this->assertStaticResidue($probe['dump']);
    }

    /**
     * Shared acceptance: registry empty, event back at process-start null.
     *
     * @param array<string, mixed> $dump
     */
    private function assertStaticResidue(array $dump): void
    {
        $this->assertSame(
            0,
            $dump['workers'],
            'S266: Worker::$workers is not [] at child process end — a class left a bare '
            . 'Worker registered, re-arming the Timer::add() order-dependence that made '
            . 'the six LiveTv/Relay skips seed-random. Fix the leaker (snapshot/restore '
            . 'the registry in setUp/tearDown), do not re-pin this.'
        );
        $this->assertTrue(
            $dump['timerEventNull'],
            'S266: Timer::$event is still set at child process end (class: '
            . (isset($dump['timerEventClass']) ? (string) $dump['timerEventClass'] : '?')
            . ') — whoever installed it must restore it, exactly as the Ssdp/Trakt'
            . ' loops and the WorkermanTimerFixture do.'
        );
    }

    /**
     * @return array{exit: int, output: string, tests: int|null, skipped: int, dump: array<string, mixed>}
     */
    private function runProbeChild(string $filter): array
    {
        $root = $this->repoRoot();
        $dumpPath = $this->freshDumpPath();

        $output = (string) shell_exec(
            'cd ' . escapeshellarg($root)
            . ' && PHLIX_S266_STATIC_DUMP=' . escapeshellarg($dumpPath)
            . ' php vendor/bin/phpunit --no-coverage'
            . ' --bootstrap tests/Support/Workerman/StaticProbeBootstrap.php'
            . ' --filter ' . escapeshellarg($filter)
            . ' 2>&1; echo "__PHLIX_S266_EXIT=$?"'
        );

        $tests = null;
        if (preg_match('/^Tests:\s*(\d+)/m', $output, $m) === 1) {
            $tests = (int) $m[1];
        } elseif (preg_match('/OK \((\d+) tests?,/', $output, $m) === 1) {
            $tests = (int) $m[1];
        }
        $skipped = preg_match('/Skipped:\s*(\d+)/m', $output, $m) === 1 ? (int) $m[1] : 0;

        return [
            'exit' => $this->childExit($output),
            'output' => $output,
            'tests' => $tests,
            'skipped' => $skipped,
            'dump' => $this->readDump($dumpPath),
        ];
    }

    /**
     * The mutation child: the SAME StaticProbeBootstrap shutdown hook, fed a
     * bare `new Workerman\Worker()` and nothing else. Lives outside the repo
     * (TMPDIR) so the census never sees it.
     */
    private function probeControlScript(string $root): string
    {
        $bootstrap = var_export($root . '/tests/Support/Workerman/StaticProbeBootstrap.php', true);
        $path = $this->freshTempPath('s266-probe-control-', '.php');
        $php = <<<PHP
        <?php

        declare(strict_types=1);

        require {$bootstrap};

        new \Workerman\Worker();
        PHP;

        file_put_contents($path, $php . "\n");

        return $path;
    }

    private function freshDumpPath(): string
    {
        return $this->freshTempPath('s266-static-dump-', '.json');
    }

    private function freshTempPath(string $prefix, string $suffix): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid($prefix, true) . $suffix;
        $this->tempPaths[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $tempPath) {
            @unlink($tempPath);
        }
        $this->tempPaths = [];

        parent::tearDown();
    }

    private function childExit(string $output): int
    {
        if (preg_match('/__PHLIX_S266_EXIT=(\d+)\s*$/', $output, $m) === 1) {
            return (int) $m[1];
        }

        return -1;
    }

    /**
     * @return array<string, mixed>
     */
    private function readDump(string $dumpPath): array
    {
        $json = is_readable($dumpPath) ? file_get_contents($dumpPath) : false;
        $this->assertIsString(
            $json,
            'S266: no static dump was written — the child died before shutdown '
            . '(exit 142 / SIGALRM class of failure) or the probe bootstrap never loaded.'
        );
        $decoded = json_decode((string) $json, true);
        $this->assertIsArray(
            $decoded,
            'S266: static dump at ' . $dumpPath . ' is not valid JSON: ' . $json
        );

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function repoRoot(): string
    {
        $root = realpath(__DIR__ . '/../../..');
        $this->assertIsString(
            $root,
            'S266: guard cannot locate the repo root from its own file.'
        );
        $this->assertFileExists(
            $root . '/tests/Support/Workerman/StaticProbeBootstrap.php',
            'S266: the probe bootstrap is missing — the guard mechanism is broken.'
        );

        return $root;
    }
}
