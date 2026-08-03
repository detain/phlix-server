<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\Dto\LibraryRow;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ScanResult;
use Phlix\Media\Music\MusicLibraryService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Tests the music-library progress wiring in {@see LibraryManager}: the branch
 * that streams a real percentage onto the scan-job row (the fix for the "sits
 * there forever" music scan). Exercises the private scanMusicLibrary() directly
 * via reflection so we do not have to reconstruct fetchLibraryRow()'s DB shape.
 */
final class LibraryManagerMusicScanTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    private function tempDir(string $suffix): string
    {
        $dir = sys_get_temp_dir() . '/phlix_lib_music_' . $suffix . '_' . uniqid();
        mkdir($dir, 0777, true);
        $this->cleanup[] = $dir;
        return $dir;
    }

    private function makeManager(MusicLibraryService $music): LibraryManager
    {
        return new LibraryManager(
            $this->createMock(Connection::class),
            $this->createMock(MediaScanner::class),
            $this->createMock(FolderWatcher::class),
            $music,
            $this->createMock(StructuredLogger::class),
        );
    }

    /**
     * @param LibraryManager $manager
     * @param LibraryRow     $library
     * @param callable|null  $onProgress
     */
    private function invokeScanMusic(
        LibraryManager $manager,
        LibraryRow $library,
        ?callable $onProgress
    ): ScanResult {
        $method = new \ReflectionMethod(LibraryManager::class, 'scanMusicLibrary');
        $method->setAccessible(true);
        $result = $method->invoke($manager, $library->id, $library, $onProgress);
        self::assertInstanceOf(ScanResult::class, $result);

        return $result;
    }

    private function musicLibrary(string ...$paths): LibraryRow
    {
        return new LibraryRow('lib-music', 'Music', 'music', array_values($paths), [], []);
    }

    public function testProgressIsPreCountedAndOffsetAcrossPaths(): void
    {
        $pathA = $this->tempDir('a');
        $pathB = $this->tempDir('b');

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('countFiles')->willReturnCallback(
            static fn (string $p): int => $p === $pathA ? 2 : 3,
        );
        // Each path's scanner ticks its own per-path progress; the manager must
        // offset those into one library-wide processed/total series.
        $music->method('scanDirectory')->willReturnCallback(
            static function (string $p, ?callable $cb) use ($pathA): ScanResult {
                if ($cb !== null) {
                    if ($p === $pathA) {
                        $cb(1, 2, $p . '/f1');
                        $cb(2, 2, $p . '/f2');
                    } else {
                        $cb(1, 3, $p . '/g1');
                        $cb(3, 3, $p . '/g3');
                    }
                }
                return new ScanResult();
            },
        );

        $manager = $this->makeManager($music);

        $ticks = [];
        $this->invokeScanMusic($manager, $this->musicLibrary($pathA, $pathB), function (
            int $processed,
            int $total,
            string $path
        ) use (&$ticks): void {
            $ticks[] = [$processed, $total, $path];
        });

        // Denominator is the sum across both paths (2 + 3 = 5) for every tick,
        // and path B's ticks are offset by path A's 2 files.
        $this->assertSame([
            [1, 5, $pathA . '/f1'],
            [2, 5, $pathA . '/f2'],
            [3, 5, $pathB . '/g1'],
            [5, 5, $pathB . '/g3'],
        ], $ticks);
    }

    public function testNoProgressSinkScansEachPathWithoutCallback(): void
    {
        $pathA = $this->tempDir('x');

        $music = $this->createMock(MusicLibraryService::class);
        $music->expects($this->never())->method('countFiles');
        $music->expects($this->once())
            ->method('scanDirectory')
            ->with($pathA, null)
            ->willReturn(new ScanResult());

        $manager = $this->makeManager($music);
        $this->invokeScanMusic($manager, $this->musicLibrary($pathA), null);
    }

    public function testMissingPathIsSkippedButCountedAsZero(): void
    {
        $pathA = $this->tempDir('present');
        $missing = '/no/such/music/dir';

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('countFiles')->willReturnCallback(
            static fn (string $p): int => $p === $pathA ? 4 : 0,
        );
        // Only the present path is scanned; the missing one is skipped.
        $music->expects($this->once())
            ->method('scanDirectory')
            ->with($pathA, $this->isType('callable'))
            ->willReturnCallback(static function (string $p, ?callable $cb): ScanResult {
                if ($cb !== null) {
                    $cb(4, 4, $p . '/last');
                }
                return new ScanResult();
            });

        $manager = $this->makeManager($music);

        $ticks = [];
        $this->invokeScanMusic($manager, $this->musicLibrary($pathA, $missing), function (
            int $processed,
            int $total,
            string $path
        ) use (&$ticks): void {
            $ticks[] = [$processed, $total, $path];
        });

        // Total denominator is 4 (missing path contributes 0).
        $this->assertSame([[4, 4, $pathA . '/last']], $ticks);
    }

    /**
     * S96(b): the library-wide counters are SUMMED across paths, and the live snapshot
     * the sink receives is offset by the same running base as `processed`.
     *
     * Without the offset, `items_added` would jump backwards to the second path's own
     * `added` the moment that path starts — a job row that goes 12 → 3 is worse than
     * one that stays at 0, because it looks like rows are being lost.
     */
    public function testCountersAreSummedAndTheLiveSnapshotIsOffsetAcrossPaths(): void
    {
        $pathA = $this->tempDir('ca');
        $pathB = $this->tempDir('cb');

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('countFiles')->willReturnCallback(
            static fn (string $p): int => $p === $pathA ? 2 : 2,
        );
        $music->method('scanDirectory')->willReturnCallback(
            static function (string $p, ?callable $cb) use ($pathA): ScanResult {
                $result = new ScanResult();
                if ($p === $pathA) {
                    // Path A: both files added, one failure on the way.
                    if ($cb !== null) {
                        $cb(1, 2, $p . '/f1', ['added' => 0, 'updated' => 0, 'failed' => 0]);
                        $cb(2, 2, $p . '/f2', ['added' => 1, 'updated' => 0, 'failed' => 1]);
                    }
                    $result->scanned = 2;
                    $result->added = 2;
                    $result->failed = 1;

                    return $result;
                }
                // Path B reports its OWN counters, starting again from zero.
                if ($cb !== null) {
                    $cb(1, 2, $p . '/g1', ['added' => 0, 'updated' => 0, 'failed' => 0]);
                    $cb(2, 2, $p . '/g2', ['added' => 1, 'updated' => 1, 'failed' => 0]);
                }
                $result->scanned = 2;
                $result->added = 3;
                $result->updated = 1;

                return $result;
            },
        );

        $manager = $this->makeManager($music);

        $live = [];
        $result = $this->invokeScanMusic($manager, $this->musicLibrary($pathA, $pathB), function (
            int $processed,
            int $total,
            string $path,
            array $counts = []
        ) use (&$live): void {
            $live[] = [$processed, $counts];
        });

        // Path B's snapshots carry path A's completed totals as their base.
        $this->assertSame([
            [1, ['added' => 0, 'updated' => 0, 'failed' => 0]],
            [2, ['added' => 1, 'updated' => 0, 'failed' => 1]],
            [3, ['added' => 2, 'updated' => 0, 'failed' => 1]],
            [4, ['added' => 3, 'updated' => 1, 'failed' => 1]],
        ], $live);

        // And the returned totals are the sums, which is what markCompleted() stamps.
        $this->assertSame(5, $result->added);
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->failed);
        $this->assertSame(4, $result->scanned);
    }

    /**
     * MED-2 (review r1): the library-wide music-scan summary logs at ERROR when the scan
     * lost files, and at INFO when it did not.
     *
     * It was `warning` for the lossy case, which meant the one line that says
     * "this library's scan lost files" reached only `.logs/app.log` — the same file that
     * carries every per-entity `debug` line of the same scan — while `config/logger.php`
     * gates the dedicated `.logs/error.log` at `error`. `MusicLibraryScanner` logs a
     * per-PATH summary; this is its per-LIBRARY twin, and the two must not disagree
     * about severity.
     */
    public function testTheLibraryWideSummaryLogsAtErrorWhenFilesWereLost(): void
    {
        foreach ([['failed' => 2, 'level' => 'error'], ['failed' => 0, 'level' => 'info']] as $case) {
            $path = $this->tempDir('sev' . $case['failed']);

            $music = $this->createMock(MusicLibraryService::class);
            $music->method('countFiles')->willReturn(2);
            $music->method('scanDirectory')->willReturnCallback(
                static function () use ($case): ScanResult {
                    $result = new ScanResult();
                    $result->scanned = 2;
                    $result->added = 2 - $case['failed'];
                    $result->failed = $case['failed'];

                    return $result;
                },
            );

            $logger = $this->createMock(StructuredLogger::class);
            // The lossy case must use `error` and NOT `warning`; the clean case `info`.
            $logger->expects($this->once())
                ->method($case['level'])
                ->with($this->stringContains('Music library scan complete'));
            $logger->expects($this->never())->method('warning');

            $manager = new LibraryManager(
                $this->createMock(Connection::class),
                $this->createMock(MediaScanner::class),
                $this->createMock(FolderWatcher::class),
                $music,
                $logger,
            );

            // No progress sink: that is the branch that calls logMusicScanComplete().
            $this->invokeScanMusic($manager, $this->musicLibrary($path), null);
        }
    }

    /**
     * A 3-parameter sink (every pre-S96 caller) must keep working: PHP ignores surplus
     * arguments to a user-defined function, and the manager's own wrapper defaults the
     * counts array. Pinned because the whole design of S96(b) rests on it — a 4th
     * argument was chosen over a second callback so no extra DB write is needed.
     */
    public function testAThreeParameterSinkStillWorks(): void
    {
        $pathA = $this->tempDir('legacy');

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('countFiles')->willReturn(1);
        $music->method('scanDirectory')->willReturnCallback(
            static function (string $p, ?callable $cb): ScanResult {
                if ($cb !== null) {
                    $cb(1, 1, $p . '/only.mp3', ['added' => 1, 'updated' => 0, 'failed' => 0]);
                }
                $result = new ScanResult();
                $result->added = 1;

                return $result;
            },
        );

        $manager = $this->makeManager($music);

        $ticks = [];
        $this->invokeScanMusic($manager, $this->musicLibrary($pathA), function (
            int $processed,
            int $total,
            string $path
        ) use (&$ticks): void {
            $ticks[] = [$processed, $total, $path];
        });

        $this->assertSame([[1, 1, $pathA . '/only.mp3']], $ticks);
    }
}
