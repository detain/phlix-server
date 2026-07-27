<?php

/**
 * Phlix media server test: Media\Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ScanResult;
use Phlix\Media\Music\MusicLibraryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S145 — which job type asks the music scanner to read every file.
 *
 * The repair in `MusicLibraryScanner::upsertTrack()` can only run on a file the scan
 * actually opens, and the S122(a) fast path skips an unchanged file before opening
 * it. `rescan` is what turns that skip off. This file pins the two ends of that
 * decision, because it is invisible from either one alone:
 *
 *  - `scanLibrary()` (the `scan` job type, the folder watcher, the CLI without
 *    `--rescan`) must keep the fast path. A `true` leaking in here would turn every
 *    routine scan of the production library from minutes into ~3.5 hours.
 *  - `rescanLibrary()` (the `rescan` job type, the admin Rescan action, the CLI with
 *    `--rescan`) must set it. A `false` here makes the whole step cosmetic while
 *    every other test in S145 stays green, because the healing scan would simply
 *    never be requested.
 *
 * Migration 084 has documented `rescan` as the heavy option since it was written;
 * until S145 the code ran the same incremental scan for both. This is the assertion
 * that the ENUM comment and the code agree.
 *
 * @internal
 */
#[CoversClass(LibraryManager::class)]
final class LibraryRescanFullReadTest extends TestCase
{
    /** @var list<string> Scratch directories to remove. */
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

    /** A plain `scan` must leave the S122(a) unchanged-file fast path switched on. */
    public function testAnOrdinaryScanDoesNotAskForAFullRead(): void
    {
        $seen = [];
        $manager = $this->manager($seen);

        $manager->scanLibrary('lib-music');

        self::assertSame(
            [false],
            $seen,
            'the incremental scan must keep the skip index: this is the path the folder watcher and '
            . 'every routine scan take, and a full read here is the 6.1-hour regression S122 removed'
        );
    }

    /** `rescan` must ask for the full read — the whole operator trigger for S145. */
    public function testARescanAsksForAFullRead(): void
    {
        $seen = [];
        $manager = $this->manager($seen);

        $manager->rescanLibrary('lib-music');

        self::assertSame(
            [true],
            $seen,
            'rescan is the ONLY surface that repairs a track filed under the wrong album/artist. '
            . 'Reverting this wiring leaves every other S145 test green while nothing can ever request '
            . 'the healing scan.'
        );
    }

    /** A multi-path music library must not heal one path and skip the next. */
    public function testEveryPathOfAMultiPathLibraryGetsTheSameFlag(): void
    {
        $seen = [];
        $manager = $this->manager($seen, 2);

        $manager->rescanLibrary('lib-music');

        self::assertSame([true, true], $seen);
    }

    /**
     * A {@see LibraryManager} over a music library whose every
     * `MusicLibraryService::scanDirectory()` call records its `$readEveryFile`
     * argument into `$seen`.
     *
     * @param list<bool> $seen  Recorder, by reference.
     * @param int        $paths Number of configured library paths.
     * @return LibraryManager
     */
    private function manager(array &$seen, int $paths = 1): LibraryManager
    {
        $roots = [];
        for ($i = 0; $i < $paths; $i++) {
            $dir = sys_get_temp_dir() . '/phlix_s145_mgr_' . bin2hex(random_bytes(6));
            mkdir($dir, 0o777, true);
            $this->cleanup[] = $dir;
            $roots[] = $dir;
        }

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('countFiles')->willReturn(0);
        $music->method('scanDirectory')->willReturnCallback(
            static function (
                string $path,
                ?callable $onProgress = null,
                ?string $libraryId = null,
                bool $readEveryFile = false
            ) use (&$seen): ScanResult {
                $seen[] = $readEveryFile;

                return new ScanResult();
            },
        );

        return new LibraryManager(
            $this->musicLibraryDb($roots),
            $this->createMock(MediaScanner::class),
            $this->createMock(FolderWatcher::class),
            $music,
            $this->createMock(StructuredLogger::class),
        );
    }

    /**
     * The minimum database a music `scanLibrary()`/`rescanLibrary()` needs: one
     * `libraries` row and empty answers for the prune/count passes.
     *
     * @param list<string> $roots Configured library paths.
     * @return Connection
     */
    private function musicLibraryDb(array $roots): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use ($roots): mixed {
                unset($params);

                if (str_contains($sql, 'FROM libraries WHERE id')) {
                    return [[
                        'id' => 'lib-music',
                        'name' => 'Music',
                        'type' => 'music',
                        'paths' => json_encode($roots),
                        'options' => json_encode([]),
                    ]];
                }
                if (str_contains($sql, 'COUNT(*) AS item_count')) {
                    return [['item_count' => 0]];
                }

                return [];
            },
        );

        return $db;
    }
}
