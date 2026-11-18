<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\SegmentProcessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Real processes, the finding-1 scenario: a process group whose leader exits on TERM while
 * a child in the same group ignores TERM.
 *
 * The leader is an outer `sh` with no trap, so TERM ends it at once. It stands in for the
 * `timeout` wrapper, which exits about 1 s after the group TERM. Its child is
 * `sh -c 'trap "" TERM; ...; exec sleep 60'`. That child ignores TERM, and the ignore
 * disposition survives the exec. Before the fix the registry dropped the record as soon as
 * the leader was dead. The child then survived, and no SIGKILL was ever sent.
 *
 * The test drives sweep() with an injected clock: the wall cap reaps, then the grace
 * elapses. It uses the registry's real signal sender, liveness probe and `/proc` reader. The
 * temp cleaner is a recorder, so no real file is touched. Time budget: the wall cap is 5 s on
 * the injected clock (no real wait), plus a 0.3 s grace and a few 20 ms polls, so the real
 * time is about 1 s. The test is skipped when /proc or setsid is unavailable.
 */
final class SegmentProcessRegistryGroupKillRealProcessTest extends TestCase
{
    private const SEC = 1_000_000_000;

    /** Grace period for the test registry. The shipped default is 0.5 s. */
    private const GRACE_SECONDS = 0.3;

    /** The SIGKILL number, for the cleanup path. */
    private const SIGKILL_NUMBER = 9;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix_group_kill_' . uniqid('', true);
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob(addcslashes($this->dir, '*?[]\\') . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** True once the child has written its PID file (the file is written after the trap is set). */
    private static function pidFileReady(string $pidFile): bool
    {
        return is_file($pidFile) && trim((string) file_get_contents($pidFile)) !== '';
    }

    /** The state letter of a PID from `/proc`, or null when no such process exists. */
    private static function stateOf(int $pid): ?string
    {
        $raw = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($raw)) {
            return null;
        }
        $parsed = SegmentProcessRegistry::parseProcStat($raw);

        return $parsed === null ? null : $parsed['state'];
    }

    /**
     * The PIDs of the non-zombie processes whose process group is $pgid. This is the test's
     * own oracle, read straight from `/proc`, independent of the registry.
     *
     * @return list<int>
     */
    private static function groupMembers(int $pgid): array
    {
        $members = [];
        foreach (scandir('/proc') ?: [] as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $raw = @file_get_contents('/proc/' . $entry . '/stat');
            if (!is_string($raw)) {
                continue;
            }
            $close = strrpos($raw, ')');
            if ($close === false) {
                continue;
            }
            $fields = preg_split('/\s+/', trim(substr($raw, $close + 1)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($fields) < 3 || $fields[0] === 'Z') {
                continue;
            }
            if ((int) $fields[2] === $pgid) {
                $members[] = (int) $entry;
            }
        }

        return $members;
    }

    public function testAChildThatIgnoresTermIsKilledWithItsGroupAfterTheGrace(): void
    {
        if (!is_readable('/proc/self/stat') || !function_exists('posix_kill')) {
            self::markTestSkipped('needs a Linux /proc and posix_kill');
        }
        $setsid = trim((string) shell_exec('command -v setsid 2>/dev/null'));
        if ($setsid === '') {
            self::markTestSkipped('needs setsid to make the launch a process-group leader');
        }

        $started = hrtime(true);
        $pidFile = $this->dir . '/child.pid';
        $tmp = $this->dir . '/seg-00000.ts.part-0a0b0c0d';

        // "$0" is $pidFile. The outer shell has no trap, so TERM ends it. Its background child
        // traps TERM, records its PID, and then execs sleep, which keeps the ignore disposition.
        $script = <<<'SH'
sh -c 'trap "" TERM; echo $$ > "$0"; exec sleep 60' "$0" &
wait
SH;
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $process = proc_open([$setsid, 'sh', '-c', $script, $pidFile], $descriptors, $pipes);
        self::assertIsResource($process, 'the group leader must launch');
        $leader = (int) proc_get_status($process)['pid'];

        try {
            self::assertGreaterThan(0, $leader, 'the leader pid must be known');

            // Wait until the child has trapped TERM and written its PID. The outer shell only
            // runs after setsid, so the group already exists by then.
            $deadline = hrtime(true) + 5 * self::SEC;
            while (hrtime(true) < $deadline && !self::pidFileReady($pidFile)) {
                usleep(20_000);
            }
            $child = self::pidFileReady($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;
            self::assertGreaterThan(0, $child, 'the child must start');
            self::assertContains(
                $leader,
                self::groupMembers($leader),
                'setsid must make the leader its own group leader',
            );
            self::assertContains($child, self::groupMembers($leader), 'the child must be in the leader group');

            $cleaned = [];
            $registry = new SegmentProcessRegistry(
                null,
                null,
                null,
                self::GRACE_SECONDS,
                static function (string $path) use (&$cleaned): void {
                    $cleaned[] = $path;
                },
            );
            // The stall window is generous. The wall cap (5 s) is what reaps, on the injected clock.
            $registry->configureWatchdog(60.0, 5.0);
            $registry->register($this->dir . '/seg-00000.ts', $leader, null, $tmp);

            // Wall-clock reap at +6 s: TERM to the group, with both processes live.
            $t = hrtime(true) + 6 * self::SEC;
            self::assertSame(1, $registry->sweep($t), 'the wall cap reaps the group once');

            // The leader dies on TERM. Wait for it to become a zombie, and do not reap it here.
            $deadline = hrtime(true) + 5 * self::SEC;
            while (hrtime(true) < $deadline && !in_array(self::stateOf($leader), ['Z', null], true)) {
                usleep(10_000);
            }
            $stateAfterTerm = self::stateOf($leader);
            self::assertTrue(
                $stateAfterTerm === 'Z' || $stateAfterTerm === null,
                'the TERMed leader must exit (state was ' . var_export($stateAfterTerm, true) . ')',
            );
            self::assertContains(
                $child,
                self::groupMembers($leader),
                'the TERM-ignoring child survives the group TERM',
            );

            // Inside the grace the record is held: no temp removed, no KILL, child still alive.
            $registry->sweep($t + intdiv(self::SEC, 10));
            self::assertSame(1, $registry->orphanCount(), 'the group record is held through the grace');
            self::assertSame([], $cleaned, 'no temp is removed while the group may still be alive');
            self::assertContains($child, self::groupMembers($leader), 'the child is still alive inside the grace');

            // Past the grace: one SIGKILL to the group, then the record is dropped.
            $registry->sweep($t + (int) (2 * self::GRACE_SECONDS * self::SEC));
            self::assertSame(0, $registry->orphanCount(), 'the record is dropped after the SIGKILL step');
            self::assertSame([$tmp], $cleaned, 'the launch temp is removed once the group is escalated');

            // The SIGKILL is asynchronous, so poll briefly. The child must be gone.
            $deadline = hrtime(true) + 5 * self::SEC;
            $members = self::groupMembers($leader);
            while ($members !== [] && hrtime(true) < $deadline) {
                usleep(20_000);
                $members = self::groupMembers($leader);
            }
            self::assertSame([], $members, 'the TERM-ignoring child is gone after the SIGKILL step');
        } finally {
            // Never leave a sleeper behind, even when an assertion fails. A negative PID
            // targets the group, and a missing group is a harmless ESRCH.
            if ($leader > 0) {
                @posix_kill(-$leader, self::SIGKILL_NUMBER);
            }
            proc_close($process);
        }

        self::assertLessThan(
            15.0,
            (hrtime(true) - $started) / self::SEC,
            'the whole test must stay under 15 s'
        );
    }
}
