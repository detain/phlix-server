<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Process;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Process\Bounded;
use Phlix\Common\Process\BoundedResult;
use ReflectionClass;

/**
 * E-12 substrate semantics for the canonical bounded-spawn grammar.
 *
 * The kill ladder is proven with REAL children: a task that traps-and-exits
 * on SIGTERM must die before the grace window ends (SIGTERM-first); a task
 * that IGNORES SIGTERM must be SIGKILLed within timeout + grace + slack
 * (escalation), never left running. Output caps keep exact byte prefixes.
 *
 * The MYSQL_PWD law (BackupManager feeds the DB password through the child
 * environment ONLY) is pinned from three directions: no leak through the
 * result values, no serialisable command/env fields on BoundedResult, and a
 * zero-logging seam census on the substrate source itself.
 */
final class BoundedTest extends TestCase
{
    private const SECRET = 'sup3rs3cr3t-MYSQL-PWD-never-log-me';

    public function testCaptureReadsStdoutAndStderrAndSurfacesExitCode(): void
    {
        $result = Bounded::capture(['/bin/sh', '-c', 'echo out-here; echo err-here >&2; exit 7']);

        self::assertInstanceOf(BoundedResult::class, $result);
        self::assertSame(7, $result->exitCode);
        self::assertSame("out-here\n", $result->stdout);
        self::assertSame("err-here\n", $result->stderr);
        self::assertFalse($result->timedOut);
    }

    public function testCaptureReturnsNullWhenSpawnFails(): void
    {
        [$result, $warnings] = $this->capturingWarnings(
            static fn (): ?BoundedResult => Bounded::capture(['/definitely/not/a/real/binary', 'x'])
        );

        self::assertNull($result);
        // The proc_open() failure warning is part of the historical site shape
        // (the raw sites emitted it too); capture() must return null WITHOUT
        // swallowing or rewriting it into an exception of its own.
        self::assertNotEmpty($warnings, 'proc_open failure must still surface its PHP warning');
        self::assertStringContainsString('proc_open', $warnings[0]);
    }

    public function testCaptureRedirectsFileStreamsWithoutReadingThem(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-bounded-capture-' . bin2hex(random_bytes(6));
        self::assertNotFalse(mkdir($dir, 0700, true));
        $out = $dir . '/stdout.bin';

        try {
            $result = Bounded::capture(
                ['/bin/sh', '-c', 'echo to-file; echo to-pipe >&2'],
                null,
                '/dev/null',
                $out,
                Bounded::PIPE
            );

            self::assertInstanceOf(BoundedResult::class, $result);
            self::assertSame('', $result->stdout);
            self::assertSame("to-pipe\n", $result->stderr);
            self::assertSame("to-file\n", file_get_contents($out));
        } finally {
            @unlink($out);
            @rmdir($dir);
        }
    }

    public function testCaptureStdinFileFeedsTheChild(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-bounded-stdin-' . bin2hex(random_bytes(6));
        self::assertNotFalse(mkdir($dir, 0700, true));
        $in = $dir . '/input.txt';
        file_put_contents($in, 'from-file');

        try {
            $result = Bounded::capture(['/bin/cat'], null, $in, Bounded::PIPE, Bounded::PIPE);

            self::assertInstanceOf(BoundedResult::class, $result);
            self::assertSame('from-file', $result->stdout);
        } finally {
            @unlink($in);
            @rmdir($dir);
        }
    }

    public function testCaptureStdinPipeStartsClosedLikeTheHistoricalSite(): void
    {
        // ChromaPrint passes a stdin pipe and never writes to it; cat must
        // therefore see EOF, not hang.
        $result = Bounded::capture(['/bin/cat'], null, Bounded::PIPE, Bounded::PIPE, Bounded::PIPE, 60);

        self::assertInstanceOf(BoundedResult::class, $result);
        self::assertSame('', $result->stdout);
        self::assertSame(0, $result->exitCode);
    }

    public function testCaptureNeverSurfacesEnvSecrets(): void
    {
        $result = Bounded::capture(
            ['/bin/sh', '-c', 'exit 0'],
            ['MYSQL_PWD' => self::SECRET, 'PATH' => '/usr/bin:/bin']
        );

        self::assertInstanceOf(BoundedResult::class, $result);
        self::assertSame(0, $result->exitCode);
        self::assertStringNotContainsString(self::SECRET, $result->stdout);
        self::assertStringNotContainsString(self::SECRET, $result->stderr);
    }

    public function testRunHappyPathPumpsBothStreamsConcurrently(): void
    {
        $result = Bounded::run(
            ['/bin/sh', '-c', 'echo hi; echo bad >&2; exit 9'],
            null,
            10
        );

        self::assertInstanceOf(BoundedResult::class, $result);
        self::assertSame(9, $result->exitCode);
        self::assertSame("hi\n", $result->stdout);
        self::assertSame("bad\n", $result->stderr);
        self::assertFalse($result->timedOut);
    }

    public function testRunStdinIsNullDevice(): void
    {
        $result = Bounded::run(['/bin/cat'], null, 10);

        self::assertInstanceOf(BoundedResult::class, $result);
        self::assertSame('', $result->stdout);
        self::assertSame(0, $result->exitCode);
    }

    public function testRunNeverDeadlocksOnStderrFloodWhileStdoutUnread(): void
    {
        // The historical blocking-read order would deadlock past the pipe
        // buffer (~64 KiB) here; the pump must not. Keep it short-capped so
        // the child cannot stall on a full stdout pipe forever: it floods
        // stderr, and the parent must still reap it.
        $result = Bounded::run(
            ['/bin/sh', '-c', 'head -c 300000 /dev/zero | tr "\\0" "e" >&2; head -c 300000 /dev/zero | tr "\\0" "o"'],
            null,
            20,
            2,
            64,
            128
        );

        self::assertInstanceOf(BoundedResult::class, $result);
        self::assertSame(64, strlen($result->stdout));
        self::assertSame(128, strlen($result->stderr));
    }

    public function testRunCapsCaptureAtExactBytePrefix(): void
    {
        $result = Bounded::run(['/bin/sh', '-c', 'printf "x%.0s" 1 2 3 4 5 6 7 8 9 10; echo'], null, 20);

        self::assertInstanceOf(BoundedResult::class, $result);
        self::assertStringStartsWith('xxxxxxxxxx', $result->stdout);
        self::assertLessThanOrEqual(Bounded::DEFAULT_CAP_BYTES, strlen($result->stdout));

        $tiny = Bounded::run(['/bin/sh', '-c', 'printf abcdefghij'], null, 20, 2, 4, 4);
        self::assertInstanceOf(BoundedResult::class, $tiny);
        self::assertSame('abcd', $tiny->stdout);
    }

    public function testRunKillLadderPrefersSigtermWhenTheChildHonorsIt(): void
    {
        $started = hrtime(true);
        $result = Bounded::run(
            ['/bin/sh', '-c', 'trap "exit 42" TERM; while :; do sleep 0.05; done'],
            null,
            1,
            5
        );
        $elapsedSec = (hrtime(true) - $started) / 1_000_000_000;

        self::assertInstanceOf(BoundedResult::class, $result);
        self::assertTrue($result->timedOut);
        self::assertSame(42, $result->exitCode);
        // The trap exit must land inside the grace window, nowhere near 6s.
        self::assertGreaterThan(0.9, $elapsedSec);
        self::assertLessThan(3.0, $elapsedSec);
    }

    public function testRunKillLadderEscalatesToSigkillWhenSigtermIsIgnored(): void
    {
        $started = hrtime(true);
        $result = Bounded::run(
            // IGNORES SIGTERM entirely and has no children — a child that
            // survives the first signal; only escalation can end this.
            ['/bin/sh', '-c', 'trap "" TERM; while :; do :; done'],
            null,
            1,
            1
        );
        $elapsedSec = (hrtime(true) - $started) / 1_000_000_000;

        self::assertInstanceOf(BoundedResult::class, $result);
        self::assertTrue($result->timedOut);
        // timeout(1) + grace(1) + scheduling slack; 30 would be the
        // non-escalating alternative (never killed), so < 4s PROVES SIGKILL.
        self::assertGreaterThan(0.9, $elapsedSec);
        self::assertLessThan(4.0, $elapsedSec);
    }

    public function testRunDeadlineZeroMeansNoKill(): void
    {
        $result = Bounded::run(['/bin/sh', '-c', 'sleep 0.2; echo done'], null, 0);

        self::assertInstanceOf(BoundedResult::class, $result);
        self::assertFalse($result->timedOut);
        self::assertSame("done\n", $result->stdout);
    }

    public function testRunReturnsNullWhenSpawnFails(): void
    {
        [$result, $warnings] = $this->capturingWarnings(
            static fn (): ?BoundedResult => Bounded::run(['/definitely/not/a/real/binary'], null, 5)
        );

        self::assertNull($result);
        self::assertNotEmpty($warnings, 'proc_open failure must still surface its PHP warning');
        self::assertStringContainsString('proc_open', $warnings[0]);
    }

    /**
     * Run a callable with an E_WARNING capture installed (repo idiom: see
     * PhlixMySQLConnectionTest) and hand back [result, warning messages].
     *
     * @param callable():mixed $callable
     *
     * @return array{0:mixed,1:list<string>}
     */
    private function capturingWarnings(callable $callable): array
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            if (($errno & E_WARNING) !== 0) {
                $warnings[] = $message;
            }

            return true;
        }, E_WARNING);

        try {
            $result = $callable();
        } finally {
            restore_error_handler();
        }

        return [$result, $warnings];
    }

    public function testTerminateStopsALiveChildWithSigtermByDefault(): void
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open(['/bin/sleep', '30'], $descriptors, $pipes);
        self::assertTrue(is_resource($process));

        $started = hrtime(true);
        Bounded::terminate($process);
        $elapsedSec = (hrtime(true) - $started) / 1_000_000_000;

        self::assertLessThan(2.0, $elapsedSec, 'terminate must not wait out the 30s sleep');
    }

    public function testBoundedResultCarriesNoCommandOrEnvFields(): void
    {
        $actual = [];
        foreach ((new ReflectionClass(BoundedResult::class))->getProperties() as $property) {
            $actual[] = $property->getName();
        }
        sort($actual);

        self::assertSame(['exitCode', 'stderr', 'stdout', 'timedOut'], $actual);
    }

    public function testSubstrateSourceHasNoLoggingSeams(): void
    {
        // The MYSQL_PWD posture requires that NOTHING in this substrate can
        // emit the environment or command it handled: no output, no log, no
        // capture to disk. (Docblock words describing the law are exempt by
        // construction: the pinned tokens are functions, not prose.)
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Common/Process/Bounded.php');

        foreach (
            [
            'error_log', 'var_dump', 'print_r', 'file_put_contents', 'fputs(', 'fwrite(',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }
}
