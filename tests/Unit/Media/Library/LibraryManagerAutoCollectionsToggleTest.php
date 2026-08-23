<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ScanResult;
use Phlix\Media\Music\MusicLibraryService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S327 — the S33 auto-collections toggle must be REACHABLE at every
 * {@see MediaScanner::scan()} call site whose library type routes through it.
 *
 * Before S327, three of the four call sites in {@see LibraryManager} invoked
 * `scan()` with THREE arguments, so the scanner's 6th parameter
 * (`$autoCollectionsEnabled`, default `true`) silently ignored whatever the
 * admin had stored for photo / book / audiobook libraries. The movie / series /
 * video main path already passed the toggle.
 *
 * This file pins the full call-site inventory behaviorally (real
 * `scanLibrary()` routing against a recording {@see MediaScanner} double) and
 * statically (a source-shape guard that turns red if a NEW `scan()` call site
 * ever appears without the toggle — the S345 "nothing matched" defence). Every
 * stored-false assertion FAILS on the pre-fix 3-argument call shape: the
 * recorder sees three arguments and the scanner's default `true` leaks through.
 *
 * @internal
 */
final class LibraryManagerAutoCollectionsToggleTest extends TestCase
{
    /**
     * Library type → the scanner-type label LibraryManager passes for it.
     *
     * Photo routes with the scanner's `image` label (the media_items.type ENUM
     * member is `photo`); book and audiobook pass their own labels. Music is
     * deliberately ABSENT — it routes to MusicLibraryScanner, which never
     * reaches the MediaScanner S33 gate.
     */
    private const SCANNER_TYPES = [
        'photo' => 'image',
        'book' => 'book',
        'audiobook' => 'audiobook',
    ];

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

    public function testPhotoLibraryScanPassesStoredToggleOff(): void
    {
        $calls = [];
        $manager = $this->manager('photo', ['autoCollections' => ['enabled' => false]], $calls);

        $manager->scanLibrary('lib-1');

        self::assertCount(1, $calls, 'a photo library must route through MediaScanner::scan()');
        self::assertSame('image', $calls[0]['type']);
        self::assertFalse(
            $calls[0]['autoCollectionsEnabled'],
            'the stored autoCollections.enabled=false must reach scan() for a photo library — '
            . "pre-fix this call was 3-arg and the scanner's default true won",
        );
        self::assertSame(
            6,
            $calls[0]['argCount'],
            'the photo call site must pass the toggle explicitly as the 6th argument',
        );
    }

    public function testBookLibraryScanPassesStoredToggleOff(): void
    {
        $calls = [];
        $manager = $this->manager('book', ['autoCollections' => ['enabled' => false]], $calls);

        $manager->scanLibrary('lib-1');

        self::assertCount(1, $calls, 'a book library must route through MediaScanner::scan()');
        self::assertSame('book', $calls[0]['type']);
        self::assertFalse(
            $calls[0]['autoCollectionsEnabled'],
            'the stored autoCollections.enabled=false must reach scan() for a book library',
        );
        self::assertSame(6, $calls[0]['argCount']);
    }

    public function testAudiobookLibraryScanPassesStoredToggleOff(): void
    {
        $calls = [];
        $manager = $this->manager('audiobook', ['autoCollections' => ['enabled' => false]], $calls);

        $manager->scanLibrary('lib-1');

        self::assertCount(1, $calls, 'an audiobook library must route through MediaScanner::scan()');
        self::assertSame('audiobook', $calls[0]['type']);
        self::assertFalse(
            $calls[0]['autoCollectionsEnabled'],
            'the stored autoCollections.enabled=false must reach scan() for an audiobook library',
        );
        self::assertSame(6, $calls[0]['argCount']);
    }

    /**
     * The full toggle matrix for the three fixed call sites: an explicit stored
     * `true`, an explicit stored `false`, and an absent flag (default `true`).
     * The explicit-true arm would also catch a hypothetical hardcoded `false`.
     */
    public function testImageBookAudiobookToggleMatrix(): void
    {
        foreach (self::SCANNER_TYPES as $libraryType => $scannerType) {
            $calls = [];
            $manager = $this->manager($libraryType, ['autoCollections' => ['enabled' => true]], $calls);
            $manager->scanLibrary('lib-1');
            self::assertCount(1, $calls, "$libraryType must route through MediaScanner::scan()");
            self::assertSame($scannerType, $calls[0]['type']);
            self::assertTrue(
                $calls[0]['autoCollectionsEnabled'],
                "stored autoCollections.enabled=true must reach scan() for $libraryType",
            );

            $calls = [];
            $manager = $this->manager($libraryType, ['autoCollections' => ['enabled' => false]], $calls);
            $manager->scanLibrary('lib-1');
            self::assertFalse(
                $calls[0]['autoCollectionsEnabled'],
                "stored autoCollections.enabled=false must reach scan() for $libraryType",
            );

            $calls = [];
            $manager = $this->manager($libraryType, [], $calls);
            $manager->scanLibrary('lib-1');
            self::assertTrue(
                $calls[0]['autoCollectionsEnabled'],
                "an absent flag must default to enabled for $libraryType (historical behaviour)",
            );
        }
    }

    /**
     * The main movie/series/video path keeps honoring the toggle — movie
     * unchanged, and series/video (which also reach the S33 gate) get the same
     * explicit pass-through.
     */
    public function testMainScanPathStillPassesTheToggleForMovieSeriesVideo(): void
    {
        foreach (['movie', 'series', 'video'] as $type) {
            $calls = [];
            $manager = $this->manager($type, ['autoCollections' => ['enabled' => false]], $calls);
            $manager->scanLibrary('lib-1');
            self::assertCount(1, $calls, "$type must route through MediaScanner::scan()");
            self::assertSame($type, $calls[0]['type']);
            self::assertFalse(
                $calls[0]['autoCollectionsEnabled'],
                "the main scan path must keep passing the stored toggle for $type",
            );
            self::assertSame(6, $calls[0]['argCount'], "the $type call site must pass the toggle explicitly");

            $calls = [];
            $manager = $this->manager($type, ['autoCollections' => ['enabled' => true]], $calls);
            $manager->scanLibrary('lib-1');
            self::assertTrue($calls[0]['autoCollectionsEnabled'], "stored true must reach scan() for $type");
        }
    }

    /**
     * Music libraries never reach the MediaScanner S33 gate — they are routed
     * to MusicLibraryScanner. The toggle must NOT be passed to MediaScanner for
     * them (there is nothing to pass to), so this pins that no scan() call is
     * made at all on the music path.
     */
    public function testMusicLibraryNeverReachesMediaScannerScan(): void
    {
        $calls = [];
        $manager = $this->manager('music', ['autoCollections' => ['enabled' => false]], $calls);

        $manager->scanLibrary('lib-1');

        self::assertSame([], $calls, 'music must route to MusicLibraryScanner, never MediaScanner::scan()');
    }

    /**
     * S345 "nothing matched" defence: the full MediaScanner::scan() call-site
     * inventory in LibraryManager is exactly FOUR (the main movie/series/video
     * path + the three type-specific paths), and each of the three single-line
     * calls passes the toggle as its 6th argument. This test turns red if a NEW
     * call site is ever added without the toggle (the count arm) or if one of
     * the three is reverted to a 3-arg shape (the per-type arms). Music is
     * deliberately not counted: scanMusicLibrary() never calls MediaScanner.
     */
    public function testEveryMediaScannerCallSitePassesTheToggleExplicitly(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../../src/Media/Library/LibraryManager.php');
        self::assertNotSame('', $source, 'LibraryManager source must be readable');

        self::assertSame(
            4,
            preg_match_all('/\$this->scanner->scan\(/', $source),
            'LibraryManager must contain exactly FOUR MediaScanner::scan() call sites '
            . '(main path + photo/book/audiobook); a new call site without the toggle must turn this red',
        );

        foreach (self::SCANNER_TYPES as $libraryType => $scannerType) {
            self::assertStringContainsString(
                "scan(\$libraryId, \$path, '{$scannerType}', false, null, \$autoCollectionsEnabled)",
                $source,
                "the {$libraryType} call site must pass the toggle explicitly as its 6th argument",
            );
        }
    }

    /**
     * A {@see LibraryManager} over a library of the given type whose every
     * {@see MediaScanner::scan()} call is recorded (arguments + argument count)
     * into `$calls` by reference.
     *
     * @param string             $type    Library type ('photo' | 'book' | 'audiobook' | 'movie' | 'series' | 'video' | 'music').
     * @param array<string, mixed> $options Stored `options` blob (autoCollections etc.).
     * @param list<array{type: string, autoCollectionsEnabled: bool, argCount: int}> $calls Recorder, by reference.
     */
    private function manager(string $type, array $options, array &$calls): LibraryManager
    {
        $root = $this->scratchDir();

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use ($root, $type, $options): mixed {
                unset($params);

                if (str_contains($sql, 'FROM libraries WHERE id')) {
                    return [[
                        'id' => 'lib-1',
                        'name' => 'Lib',
                        'type' => $type,
                        'paths' => json_encode([$root]),
                        'options' => json_encode($options),
                    ]];
                }

                return [];
            },
        );

        $scanner = $this->createMock(MediaScanner::class);
        $scanner->method('scan')->willReturnCallback(
            static function (
                string $libraryId,
                string $path,
                string $type,
                bool $seriesPerDirectory = false,
                ?callable $onFile = null,
                bool $autoCollectionsEnabled = true
            ) use (&$calls): int {
                unset($libraryId, $path, $seriesPerDirectory, $onFile);

                $calls[] = [
                    'type' => $type,
                    'autoCollectionsEnabled' => $autoCollectionsEnabled,
                    'argCount' => func_num_args(),
                ];

                return 1;
            },
        );

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('scanDirectory')->willReturn(new ScanResult());

        return new LibraryManager(
            $db,
            $scanner,
            $this->createMock(FolderWatcher::class),
            $music,
            $this->createMock(StructuredLogger::class),
        );
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix_s327_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0777, true));
        $this->cleanup[] = $dir;

        return $dir;
    }
}