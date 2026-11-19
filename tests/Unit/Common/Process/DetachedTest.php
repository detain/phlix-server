<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Process;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Process\Detached;

/**
 * E-12 substrate semantics for the canonical detached-spawn grammar.
 *
 * The byte-identity of the two MIGRATED sites is pinned elsewhere
 * ({@see SpawnSiteCorpusTest} vs the pristine capture). What lives here is
 * the grammar's own contract: sentinel opt-in separation, the trailing-list
 * filter, the timeout arms, pid parsing, and the liveness probe.
 */
final class DetachedTest extends TestCase
{
    public function testSentinellessGrammarIsTheRecorderShape(): void
    {
        $line = Detached::buildLaunch('ffmpeg -i in.ts rec.mp4', '/logs/rec.log', 0, null);

        self::assertSame(
            "nohup sh -c 'ffmpeg -i in.ts rec.mp4' > '/logs/rec.log' 2>&1 & echo $!",
            $line
        );
        self::assertStringNotContainsString('.complete', $line);
        self::assertStringNotContainsString('.failed', $line);
        self::assertStringNotContainsString('if ', $line);
    }

    public function testSentinelGrammarIsTheFfmpegShape(): void
    {
        $line = Detached::buildLaunch('ffmpeg -y out.ts', '/jobs/o/ffmpeg.log', 0, '/jobs/o');

        self::assertSame(
            'nohup sh -c '
            . "'if ffmpeg -y out.ts; then touch '\\''/jobs/o/.complete'\\''; "
            . "else touch '\\''/jobs/o/.failed'\\''; fi' "
            . "> '/jobs/o/ffmpeg.log' 2>&1 & echo $!",
            $line
        );
    }

    public function testTimeoutArmWrapsBeforeNohup(): void
    {
        $withTimeout = Detached::buildLaunch('cmd', '/log', 7200, null);
        $without = Detached::buildLaunch('cmd', '/log', 0, null);

        self::assertStringContainsString('timeout 7200 sh -c ', $withTimeout);
        self::assertStringNotContainsString('timeout ', $without);
        self::assertStringStartsWith('nohup timeout 7200 sh -c ', $withTimeout);
    }

    public function testNegativeTimeoutEmitsNoWrapper(): void
    {
        self::assertSame(
            Detached::buildLaunch('cmd', '/log', 0, null),
            Detached::buildLaunch('cmd', '/log', -5, null)
        );
    }

    public function testTrailingCommandsAreFilteredLikeTheHistoricalSite(): void
    {
        $line = Detached::buildLaunch('cmd', '/jobs/x', 0, '/jobs/x', ['a; b', '', null, 42, 'c']);

        // Only non-empty strings ride; empty/null/int entries are dropped.
        self::assertStringContainsString("; a; b; c; else touch", $line);
    }

    public function testTrailingCommandsRequireASentinelDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Detached::buildLaunch('cmd', '/log', 0, null, ['touch /tmp/late']);
    }

    public function testLogPathAndSentinelDirAreIndependent(): void
    {
        $line = Detached::buildLaunch('cmd', '/other/place.log', 0, '/jobs/x');

        self::assertStringEndsWith("> '/other/place.log' 2>&1 & echo $!", $line);
        self::assertStringContainsString('/jobs/x/.complete', $line);
    }

    #[DataProvider('launchOutputProvider')]
    public function testPidFromLaunchTruthTable(mixed $output, int $expected): void
    {
        self::assertSame($expected, Detached::pidFromLaunch($output));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function launchOutputProvider(): array
    {
        return [
            'plain' => ["4242\n", 4242],
            'padded' => [' 4242 ', 4242],
            'no newline' => ['4242', 4242],
            'garbage leading digits' => ['42abc', 42],
            'empty' => ['', 0],
            'whitespace' => [" \n", 0],
            'null' => [null, 0],
            'int' => [123, 0],
            'bool' => [true, 0],
        ];
    }

    public function testIsAliveRejectsNonPositivePidsWithoutSyscalls(): void
    {
        self::assertFalse(Detached::isAlive(0));
        self::assertFalse(Detached::isAlive(-7));
    }

    public function testIsAliveSeesTheCurrentProcess(): void
    {
        self::assertTrue(Detached::isAlive(getmypid()));
    }

    public function testIsAliveRejectsImplausiblePid(): void
    {
        // pid_max is at most 2^22 on Linux; 999999 < that, but is never taken
        // by this test's process lifetime — the historical probe semantics are
        // "no such process" regardless of which arm (posix /proc) answers.
        self::assertFalse(Detached::isAlive(999999));
    }
}
