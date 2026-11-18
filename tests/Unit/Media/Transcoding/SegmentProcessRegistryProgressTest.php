<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\SegmentProcessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The stall watchdog's progress signal, read from real files.
 *
 * A CMAF encode writes its fragment only at the end, so its temp family does not
 * grow during the encode. The watchdog reads the encode's `-progress` file instead:
 * `out_time_us`, or that file's mtime while out_time is still N/A. These tests drive
 * the DEFAULT probe against real files, and the injected clock is what decides a
 * stall. The file mtime is set explicitly, because the probe reads it in whole
 * seconds.
 */
final class SegmentProcessRegistryProgressTest extends TestCase
{
    private const SEC = 1_000_000_000;

    private const SIGTERM = 15;

    private string $dir;

    /** @var list<array{0: int, 1: int}> */
    private array $signals = [];

    /** @var array<int, bool> pid => alive */
    private array $alive = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix_srp_progress_' . uniqid('', true);
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        foreach (glob(addcslashes($dir, '*?[]\\') . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    private function registry(): SegmentProcessRegistry
    {
        $registry = new SegmentProcessRegistry(
            null,
            function (int $pid, int $signal): void {
                $this->signals[] = [$pid, $signal];
            },
            fn (int $pid): bool => $this->alive[$pid] ?? false,
            0.5,
            static function (string $tmp): void {
                // The tests here never clean a temp; the default cleaner is tested apart.
            },
        );
        // No real /proc for the fake PIDs.
        $registry->setProcStatReader(static fn (int $pid): ?string => null);
        // These scenarios were written against a 30 s stall window. The default is tested apart.
        $registry->configureWatchdog(30.0, 300.0);

        return $registry;
    }

    /** @return list<int> */
    private function signalsFor(int $pid): array
    {
        return array_values(array_map(
            static fn (array $s): int => $s[1],
            array_filter($this->signals, static fn (array $s): bool => $s[0] === $pid),
        ));
    }

    /** One `-progress` block as ffmpeg writes it, with out_time_us (or N/A). */
    private function block(string $outTime): string
    {
        return "frame=1\nfps=25.00\nbitrate=N/A\nout_time_us={$outTime}\nout_time={$outTime}\nprogress=continue\n";
    }

    public function testAnEncodeWhoseOutTimeAdvancesIsNeverStalled(): void
    {
        $tmp = $this->dir . '/seg-v360p-00000.m4s.part-0a1b2c3d';
        $prog = $tmp . '.prog';
        file_put_contents($prog, $this->block('N/A'));
        $this->alive[4101] = true;
        $registry = $this->registry();
        $registry->register($tmp, 4101, null, $tmp);
        $base = time();
        $t0 = hrtime(true);

        // out_time advances every 5 s of the injected clock. Past the 30 s stall window
        // the encode is still healthy, because its progress is moving.
        for ($s = 1; $s <= 56; $s += 5) {
            file_put_contents($prog, $this->block((string) ($s * 1_000_000)), FILE_APPEND);
            touch($prog, $base + $s);
            $registry->sweep($t0 + $s * self::SEC);
        }

        self::assertSame([], $this->signals, 'an encode whose out_time advances is never reaped');
    }

    public function testAFrozenOutTimeIsAStallEvenWhileTheProgressFileIsStillRewritten(): void
    {
        $tmp = $this->dir . '/seg-v360p-00001.m4s.part-1a2b3c4d';
        $prog = $tmp . '.prog';
        file_put_contents($prog, $this->block('56000000'));
        $this->alive[4102] = true;
        $registry = $this->registry();
        $registry->register($tmp, 4102, null, $tmp);
        $base = time();
        $t0 = hrtime(true);

        // The first pass sees out_time 56 s as progress. Then out_time stops, but the
        // file keeps being rewritten (the encoder's main loop still runs). The stall
        // rule judges out_time, so it is a stall once the window has passed.
        $registry->sweep($t0 + 1 * self::SEC);
        file_put_contents($prog, $this->block('56000000'), FILE_APPEND);
        touch($prog, $base + 20);
        $registry->sweep($t0 + 20 * self::SEC); // 19 s since the last change: inside the window
        self::assertSame([], $this->signals);

        file_put_contents($prog, $this->block('56000000'), FILE_APPEND);
        touch($prog, $base + 40);
        $registry->sweep($t0 + 32 * self::SEC); // 31 s since out_time last moved: stalled
        self::assertSame([self::SIGTERM], $this->signalsFor(4102));
    }

    public function testAnEncodeWithNoNumericOutTimeIsKeptAliveByItsProgressFileMtime(): void
    {
        // Before the first packet ffmpeg writes out_time_us=N/A (and the lookahead can
        // hold the first packet for a long time). The file's mtime carries the signal.
        $tmp = $this->dir . '/seg-v720p-00002.m4s.part-2b3c4d5e';
        $prog = $tmp . '.prog';
        file_put_contents($prog, $this->block('N/A'));
        $this->alive[4103] = true;
        $registry = $this->registry();
        $registry->register($tmp, 4103, null, $tmp);
        $base = time();
        $t0 = hrtime(true);

        for ($s = 1; $s <= 56; $s += 5) {
            touch($prog, $base + $s); // ffmpeg's periodic rewrite: the mtime moves
            $registry->sweep($t0 + $s * self::SEC);
        }
        self::assertSame([], $this->signals, 'a live progress file with N/A out_time is not stalled');

        // The rewrites stop: the mtime is frozen, so the encode is stalled.
        $registry->sweep($t0 + 88 * self::SEC); // 32 s after the last mtime move
        self::assertSame([self::SIGTERM], $this->signalsFor(4103));
    }

    public function testAnMpegtsTempIsStillJudgedByItsGrowingBytes(): void
    {
        // No progress file: the MPEG-TS branch. Its temp grows continuously.
        $tmp = $this->dir . '/seg-v720p-00003.ts.part-3c4d5e6f';
        file_put_contents($tmp, str_repeat('x', 100));
        $this->alive[4104] = true;
        $registry = $this->registry();
        $registry->register($tmp, 4104, null, $tmp);
        $t0 = hrtime(true);

        file_put_contents($tmp, str_repeat('x', 200));
        $registry->sweep($t0 + 20 * self::SEC); // growth at +20 s
        $registry->sweep($t0 + 45 * self::SEC); // 25 s since growth: inside the window
        self::assertSame([], $this->signals);

        $registry->sweep($t0 + 51 * self::SEC); // 31 s since growth: stalled
        self::assertSame([self::SIGTERM], $this->signalsFor(4104));
    }

    public function testTheShippedStallWindowIsNinetySeconds(): void
    {
        // A flat progress token (no growth at all), so only the stall rule can fire.
        // The window must cover a healthy 4K end-of-input flush (see the registry docblock).
        $this->alive[4107] = true;
        $registry = new SegmentProcessRegistry(
            null,
            function (int $pid, int $signal): void {
                $this->signals[] = [$pid, $signal];
            },
            fn (int $pid): bool => $this->alive[$pid] ?? false,
            0.5,
            static function (string $tmp): void {
            },
        );
        $registry->setProcStatReader(static fn (int $pid): ?string => null);
        $registry->setProgressProbe(static fn (array $tmps): int => 0);
        $tmp = $this->dir . '/seg-v720p-00005.ts.part-5e6f7081';
        $registry->register($tmp, 4107, null, $tmp);
        $t0 = hrtime(true);

        $registry->sweep($t0 + 89 * self::SEC);
        self::assertSame([], $this->signals, 'a 89 s flat progress token is inside the 90 s window');

        $registry->sweep($t0 + 91 * self::SEC);
        self::assertSame([self::SIGTERM], $this->signalsFor(4107), 'a 91 s flat token is a stall');
    }

    public function testTheShippedWallCapIsFiveMinutes(): void
    {
        self::assertSame(300.0, (new SegmentProcessRegistry())->maxWallSeconds());
        self::assertSame(300.0, SegmentProcessRegistry::DEFAULT_MAX_WALL_SECONDS);

        // A progress token that changes on every pass: the stall rule can never fire,
        // so only the wall-clock cap can reap this encode.
        $tmp = $this->dir . '/seg-v720p-00004.ts.part-4d5e6f70';
        $this->alive[4105] = true;
        $registry = $this->registry();
        $registry->setProgressProbe(static function (): int {
            static $n = 0;

            return ++$n;
        });
        $registry->register($tmp, 4105, null, $tmp);
        $t0 = hrtime(true);

        $registry->sweep($t0 + 121 * self::SEC);
        self::assertSame([], $this->signals, 'a 121 s encode is under the 300 s cap');

        $registry->sweep($t0 + 301 * self::SEC);
        self::assertSame([self::SIGTERM], $this->signalsFor(4105));
    }

    public function testTheDefaultTempCleanerRemovesTheProgressFileAndEscapesTheStem(): void
    {
        // The directory name holds glob metacharacters. An unescaped `seg[1]` is a
        // character class, so the cleaner would match nothing and leave the sidecars.
        $sub = $this->dir . '/seg[1]';
        mkdir($sub, 0o755, true);
        $tmp = $sub . '/seg-v360p-00000.m4s.part-0a1b2c3d';
        $sidecars = ['', '.prog', '.i', '.s0', '.m3u8'];
        foreach ($sidecars as $suffix) {
            file_put_contents($tmp . $suffix, 'x');
        }
        // A sibling worker's live temp for the same final segment must survive.
        $sibling = $sub . '/seg-v360p-00000.m4s.part-ffffffff';
        file_put_contents($sibling, 'x');

        // Default cleaner (null), dead process: releaseAfterWaitTimeout cleans the temp.
        $registry = new SegmentProcessRegistry(
            null,
            static function (int $pid, int $signal): void {
            },
            static fn (int $pid): bool => false,
            0.5,
            null,
        );
        $registry->setProcStatReader(static fn (int $pid): ?string => null);
        $registry->register('/key/seg-v360p-00000.m4s', 4106, null, $tmp);
        $registry->releaseAfterWaitTimeout('/key/seg-v360p-00000.m4s');

        foreach ($sidecars as $suffix) {
            self::assertFileDoesNotExist($tmp . $suffix, "the '{$suffix}' sidecar must be removed");
        }
        self::assertFileExists($sibling, 'a sibling temp is never removed');
    }
}
