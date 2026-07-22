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
 *
 * @covers \Phlix\Media\Library\LibraryManager
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
    private function invokeScanMusic(LibraryManager $manager, LibraryRow $library, ?callable $onProgress): void
    {
        $method = new \ReflectionMethod(LibraryManager::class, 'scanMusicLibrary');
        $method->setAccessible(true);
        $method->invoke($manager, $library->id, $library, $onProgress);
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
}
