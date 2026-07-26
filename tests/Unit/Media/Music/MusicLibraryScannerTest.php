<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Shared\Events\Library\MediaItemAdded;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see MusicLibraryScanner}.
 *
 * Focus is the music-scan-hang fix:
 *  - `media_items.type` uses a VALID ENUM member (`artist`/`album`/`track`),
 *    never the old `music_*` value that hard-errors under STRICT_TRANS_TABLES;
 *  - the scan streams per-file progress instead of leaving the UI frozen;
 *  - tags are read once (getID3 first, ffprobe fallback) and reused.
 *
 * @covers \Phlix\Media\Music\MusicLibraryScanner
 */
final class MusicLibraryScannerTest extends TestCase
{
    /**
     * Retained bytes per buffered file, for the memory ceiling below.
     *
     * MEASURED, not guessed: one `['file' => SplFileInfo, 'meta' => [...]]` entry
     * costs **1,463 B** on PHP 8.3.6 with ~60-character paths — 11,656,488 B
     * observed with 7,968 entries buffered simultaneously. 1,700 leaves ~16 %
     * headroom for a different PHP build or a longer temp path without letting a
     * genuine regression through: the pre-S95 whole-tree map needs **≈24.3 MB** on
     * this exact fixture (24,336,768 B measured, independently re-measured at
     * 24,308,904 B — 3,051 B per file for all 14,000 of them), i.e. **≈79 % over**
     * the 13,600,000 B ceiling this constant produces.
     */
    private const BYTES_PER_BUFFERED_FILE = 1700;

    /** @var list<string> Temp files/dirs to remove in tearDown. */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    /**
     * Build a Connection mock that behaves like an empty DB: every SELECT
     * returns no rows (so every entity is a fresh insert), every INSERT bumps
     * the auto-increment surfaced by lastInsertId(), and the `type` column of
     * each media_items INSERT is captured for assertion.
     *
     * @param list<string> $mediaItemTypes Captured media_items.type values (by ref).
     * @param list<array{sql:string, params:array<int,mixed>}> $inserts All INSERTs (by ref).
     */
    private function emptyDbMock(&$mediaItemTypes, &$inserts): Connection
    {
        $mediaItemTypes = [];
        $inserts = [];
        $lastId = 0;

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$mediaItemTypes, &$inserts, &$lastId) {
                $trimmed = ltrim($sql);
                if (str_starts_with($trimmed, 'SELECT')) {
                    return [];
                }
                if (str_starts_with($trimmed, 'INSERT')) {
                    $inserts[] = ['sql' => $trimmed, 'params' => $params ?? []];
                    if (str_starts_with($trimmed, 'INSERT INTO media_items')) {
                        // Columns: (id, library_id, type, name, path, metadata_json, ...)
                        // so the ENUM type is the THIRD bound parameter.
                        $mediaItemTypes[] = (string) (($params ?? [])[2] ?? '');
                    }
                    $lastId++;
                    return 1;
                }
                // UPDATE / anything else.
                return 1;
            },
        );
        $db->method('lastInsertId')->willReturnCallback(function () use (&$lastId): string {
            return (string) $lastId;
        });

        return $db;
    }

    /**
     * FfmpegRunner mock whose probe() returns caller-supplied tags keyed by the
     * file's basename.
     *
     * @param array<string, array<string, string>> $tagsByBasename basename => format tags
     */
    private function ffmpegReturning(array $tagsByBasename): FfmpegRunner
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturnCallback(
            static function (string $path) use ($tagsByBasename): ?array {
                $tags = $tagsByBasename[basename($path)] ?? null;
                if ($tags === null) {
                    return null;
                }
                return ['format' => ['duration' => '180.0', 'tags' => $tags]];
            },
        );
        return $ffmpeg;
    }

    /** A scanner wired with throwaway mock dependencies (no DB/ffprobe needed). */
    private function plainScanner(): MusicLibraryScanner
    {
        return new MusicLibraryScanner(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
        );
    }

    /** Create a temp directory registered for cleanup. */
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix_music_test_' . uniqid();
        mkdir($dir, 0777, true);
        $this->cleanup[] = $dir;
        return $dir;
    }

    /** Create a file with the given contents, registered for cleanup. */
    private function touchFile(string $dir, string $name, string $contents = 'not-real-audio'): string
    {
        $path = $dir . '/' . $name;
        file_put_contents($path, $contents);
        $this->cleanup[] = $path;
        return $path;
    }

    // -- The core fix: valid ENUM types + progress + indexing -----------------

    public function testScanIndexesAlbumWithValidEnumTypesAndStreamsProgress(): void
    {
        $dir = $this->tempDir();
        // Untagged bytes → getID3 finds nothing → ffprobe fallback supplies tags.
        $this->touchFile($dir, '01-song.mp3');
        $this->touchFile($dir, '02-song.mp3');

        $ffmpeg = $this->ffmpegReturning([
            '01-song.mp3' => [
                'artist' => 'The Band', 'album' => 'Greatest Hits',
                'title' => 'Song A', 'track' => '1/2', 'disc' => '1/1', 'date' => '2001-05-01',
            ],
            '02-song.mp3' => [
                'artist' => 'The Band', 'album' => 'Greatest Hits',
                'title' => 'Song B', 'track' => '2/2', 'disc' => '1/1', 'date' => '2001-05-01',
            ],
        ]);

        $db = $this->emptyDbMock($mediaItemTypes, $inserts);
        $scanner = new MusicLibraryScanner($db, $ffmpeg);

        $ticks = [];
        $result = $scanner->scanDirectory($dir, function (int $p, int $t, string $path) use (&$ticks): void {
            $ticks[] = [$p, $t, $path];
        });

        // Two tracks indexed.
        $this->assertSame(2, $result->added);

        // The crux: every media_items.type is a valid ENUM member, never music_*.
        $this->assertSame(['artist', 'album', 'track', 'track'], $mediaItemTypes);
        foreach ($mediaItemTypes as $type) {
            $this->assertContains($type, ['artist', 'album', 'track']);
            $this->assertStringStartsNotWith('music_', $type);
        }

        // Progress ticked once per file, monotonically, against a total of 2.
        $this->assertCount(2, $ticks);
        $this->assertSame([1, 2], array_column($ticks, 0));
        $this->assertSame([2, 2], array_column($ticks, 1));
        $this->assertEqualsCanonicalizing(
            [$dir . '/01-song.mp3', $dir . '/02-song.mp3'],
            array_column($ticks, 2),
        );
    }

    public function testScanWithoutProgressSinkStillIndexes(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'track.mp3');
        $ffmpeg = $this->ffmpegReturning([
            'track.mp3' => ['artist' => 'Solo', 'album' => 'Demo', 'title' => 'One', 'track' => '1'],
        ]);

        $db = $this->emptyDbMock($mediaItemTypes, $inserts);
        $scanner = new MusicLibraryScanner($db, $ffmpeg);

        $result = $scanner->scanDirectory($dir); // no sink
        $this->assertSame(1, $result->added);
        $this->assertSame(['artist', 'album', 'track'], $mediaItemTypes);
    }

    public function testUnknownArtistAlbumIsSkipped(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'mystery.mp3');
        // ffprobe returns null → filename fallback → artist null → 'Unknown Artist'.
        $ffmpeg = $this->ffmpegReturning([]);

        $db = $this->emptyDbMock($mediaItemTypes, $inserts);
        $scanner = new MusicLibraryScanner($db, $ffmpeg);

        $result = $scanner->scanDirectory($dir);
        $this->assertSame(0, $result->added);
        $this->assertSame([], $mediaItemTypes, 'No media_items should be written for an unknown-artist album');
    }

    public function testTrackTitleFallsBackToFilenameWhenTitleTagMissing(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'Nice Track.mp3');
        $ffmpeg = $this->ffmpegReturning([
            'Nice Track.mp3' => ['artist' => 'A', 'album' => 'B'], // no title tag
        ]);

        $db = $this->emptyDbMock($mediaItemTypes, $inserts);
        $scanner = new MusicLibraryScanner($db, $ffmpeg);
        $scanner->scanDirectory($dir);

        $trackInsert = null;
        foreach ($inserts as $ins) {
            if (str_starts_with($ins['sql'], 'INSERT INTO music_tracks')) {
                $trackInsert = $ins;
            }
        }
        $this->assertNotNull($trackInsert);
        // music_tracks params: [media_item_id, album_id, artist_id, title, ...]
        $this->assertSame('Nice Track', $trackInsert['params'][3]);
    }

    // -- countAudioFiles (progress denominator) -------------------------------

    public function testCountAudioFilesAppliesExtensionAndSkipFilters(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'a.mp3');
        $this->touchFile($dir, 'b.flac');
        $this->touchFile($dir, 'c.m4a');
        $this->touchFile($dir, 'readme.txt');   // not audio
        $this->touchFile($dir, 'cover.jpg');     // not audio
        $this->touchFile($dir, 'folder.jpg');    // skip pattern
        $this->touchFile($dir, '.hidden.mp3');   // hidden → skipped

        $scanner = $this->plainScanner();
        $this->assertSame(3, $scanner->countAudioFiles($dir));
    }

    public function testCountAudioFilesReturnsZeroForMissingPath(): void
    {
        $scanner = $this->plainScanner();
        $this->assertSame(0, $scanner->countAudioFiles('/no/such/path/anywhere'));
    }

    // -- getID3 mapping (probeViaGetId3 / mapId3Comments) ---------------------

    public function testMapId3CommentsMapsAllFieldsAndParsesPositions(): void
    {
        $scanner = new TestableMusicScanner(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
        );

        $mapped = $scanner->mapPublic(
            [
                'artist' => ['Pink Floyd'],
                'album' => ['The Wall'],
                'title' => ['Hey You'],
                'track_number' => ['3/12'],
                'part_of_a_set' => ['2/3'],
                'year' => ['1979-11-30'],
                'genre' => ['Progressive Rock'],
            ],
            ['playtime_seconds' => 260.7],
        );

        $this->assertNotNull($mapped);
        $this->assertSame('Pink Floyd', $mapped['artist']);
        $this->assertSame('The Wall', $mapped['album']);
        $this->assertSame('Hey You', $mapped['title']);
        $this->assertSame(3, $mapped['track_number']);
        $this->assertSame(2, $mapped['disc_number']);
        $this->assertSame(1979, $mapped['year']);
        $this->assertSame('Progressive Rock', $mapped['genre']);
        $this->assertSame(260, $mapped['duration_secs']);
    }

    public function testMapId3CommentsFallsBackToAlbumArtistAndDefaultsDisc(): void
    {
        $scanner = new TestableMusicScanner(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
        );

        $mapped = $scanner->mapPublic(
            ['band' => ['Album Artist Name'], 'album' => ['Compilation']],
            [],
        );

        $this->assertNotNull($mapped);
        $this->assertSame('Album Artist Name', $mapped['artist']);
        $this->assertSame(1, $mapped['disc_number'], 'disc defaults to 1 when absent');
        $this->assertNull($mapped['track_number']);
        $this->assertSame(0, $mapped['duration_secs']);
    }

    public function testMapId3CommentsReturnsNullWhenNoIdentifyingTag(): void
    {
        $scanner = new TestableMusicScanner(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
        );

        $this->assertNull($scanner->mapPublic([], []));
        $this->assertNull($scanner->mapPublic(['genre' => ['Rock']], ['playtime_seconds' => 100]));
    }

    public function testProbeViaGetId3ReturnsNullForUntaggedFile(): void
    {
        $dir = $this->tempDir();
        $path = $this->touchFile($dir, 'empty.mp3', '');

        $scanner = new TestableMusicScanner(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
        );

        // Real getID3 read of an untagged file yields nothing usable → null,
        // which is what triggers the ffprobe fallback in probeMetadata().
        $this->assertNull($scanner->probeViaGetId3Public($path));
    }

    public function testGetId3HappyPathIsPreferredOverFfprobe(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'song.mp3');

        // ffprobe would return DIFFERENT tags — if getID3 wins, these are unused.
        $ffmpeg = $this->ffmpegReturning([
            'song.mp3' => ['artist' => 'WRONG', 'album' => 'WRONG', 'title' => 'WRONG'],
        ]);

        $db = $this->emptyDbMock($mediaItemTypes, $inserts);
        $scanner = new FakeGetId3Scanner($db, $ffmpeg);
        $scanner->fakeComments = [
            'artist' => ['Right Artist'],
            'album' => ['Right Album'],
            'title' => ['Right Title'],
            'track_number' => ['1'],
        ];

        $scanner->scanDirectory($dir);

        // Track title came from the (native) getID3 tags, not ffprobe's WRONG.
        $trackInsert = null;
        foreach ($inserts as $ins) {
            if (str_starts_with($ins['sql'], 'INSERT INTO music_tracks')) {
                $trackInsert = $ins;
            }
        }
        $this->assertNotNull($trackInsert);
        $this->assertSame('Right Title', $trackInsert['params'][3]);
    }

    // -- Review fixes: idempotency, MediaItemAdded, library_id, ignore_patterns

    /**
     * A STATEFUL Connection mock: INSERTs persist into in-memory tables and the
     * relevant SELECTs read them back, so a SECOND scan of the same library sees
     * the rows the first scan wrote. This is what exercises rescan idempotency —
     * the empty-DB mock (every SELECT → []) cannot.
     *
     * @param list<array<int,mixed>> $mediaItemInserts Captured media_items INSERT params (by ref).
     * @param list<array<int,mixed>> $trackInserts     Captured music_tracks INSERT params (by ref).
     */
    private function statefulDbMock(&$mediaItemInserts, &$trackInserts): Connection
    {
        $mediaItemInserts = [];
        $trackInserts = [];

        $artists = [];      // nameLower => ['id'=>int, 'media_item_id'=>string]
        $albums = [];       // "artistId|titleLower" => ['id'=>int, 'media_item_id'=>string]
        $mediaItems = [];   // list of ['id','library_id','type','name','path']
        $tracks = [];       // media_item_id => track row
        $autoInt = 0;

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (
                string $sql,
                ?array $params = null
            ) use (
                &$mediaItemInserts,
                &$trackInserts,
                &$artists,
                &$albums,
                &$mediaItems,
                &$tracks,
                &$autoInt
            ) {
                $p = $params ?? [];
                $t = ltrim($sql);

                if (str_starts_with($t, 'SELECT')) {
                    if (str_contains($t, 'FROM music_artists WHERE name')) {
                        $name = strtolower((string) ($p[0] ?? ''));
                        return isset($artists[$name]) ? [$artists[$name]] : [];
                    }
                    if (str_contains($t, 'FROM music_albums WHERE artist_id')) {
                        $key = ((string) ($p[0] ?? '')) . '|' . strtolower((string) ($p[1] ?? ''));
                        return isset($albums[$key]) ? [$albums[$key]] : [];
                    }
                    if (str_contains($t, 'FROM media_items WHERE type')) {
                        foreach ($mediaItems as $mi) {
                            if (
                                $mi['type'] === 'track'
                                && $mi['path'] === ($p[0] ?? null)
                                && $mi['library_id'] === ($p[1] ?? null)
                            ) {
                                return [['id' => $mi['id']]];
                            }
                        }
                        return [];
                    }
                    if (str_contains($t, 'FROM music_tracks WHERE media_item_id')) {
                        $mid = (string) ($p[0] ?? '');
                        return isset($tracks[$mid]) ? [$tracks[$mid]] : [];
                    }
                    return [];
                }

                if (str_starts_with($t, 'INSERT INTO media_items')) {
                    $mediaItemInserts[] = $p;
                    $mediaItems[] = [
                        'id' => (string) ($p[0] ?? ''),
                        'library_id' => $p[1] ?? null,
                        'type' => (string) ($p[2] ?? ''),
                        'name' => (string) ($p[3] ?? ''),
                        'path' => (string) ($p[4] ?? ''),
                    ];
                    return 1;
                }
                if (str_starts_with($t, 'INSERT INTO music_artists')) {
                    $autoInt++;
                    $artists[strtolower((string) ($p[0] ?? ''))] =
                        ['id' => $autoInt, 'media_item_id' => $p[2] ?? null];
                    return 1;
                }
                if (str_starts_with($t, 'INSERT INTO music_albums')) {
                    $autoInt++;
                    $key = ((string) ($p[0] ?? '')) . '|' . strtolower((string) ($p[2] ?? ''));
                    $albums[$key] = ['id' => $autoInt, 'media_item_id' => $p[1] ?? null];
                    return 1;
                }
                if (str_starts_with($t, 'INSERT INTO music_tracks')) {
                    $autoInt++;
                    $trackInserts[] = $p;
                    $mid = (string) ($p[0] ?? '');
                    $tracks[$mid] = [
                        'id' => $autoInt,
                        'title' => $p[3] ?? '',
                        'track_number' => $p[4] ?? null,
                        'disc_number' => $p[5] ?? 1,
                        'duration_secs' => $p[6] ?? 0,
                    ];
                    return 1;
                }

                // UPDATE / anything else.
                return 1;
            },
        );
        $db->method('lastInsertId')->willReturnCallback(function () use (&$autoInt): string {
            return (string) $autoInt;
        });

        return $db;
    }

    /**
     * Tags supplied via the ffprobe fallback (untagged bytes → getID3 finds
     * nothing → ffprobe wins), the same reliable path the passing scan tests use.
     *
     * @return FfmpegRunner
     */
    private function ffmpegSong(): FfmpegRunner
    {
        return $this->ffmpegReturning([
            'song.mp3' => [
                'artist' => 'The Band', 'album' => 'Greatest Hits',
                'title' => 'Song A', 'track' => '1/1', 'disc' => '1/1', 'date' => '2001',
            ],
        ]);
    }

    public function testRescanDoesNotDuplicateTrackOrMediaItem(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'song.mp3');

        $db = $this->statefulDbMock($mediaItemInserts, $trackInserts);
        $scanner = new MusicLibraryScanner($db, $this->ffmpegSong());

        // Two full scans of the same library (resident scanner instance).
        $first = $scanner->scanDirectory($dir, null, 'lib-1');
        $second = $scanner->scanDirectory($dir, null, 'lib-1');

        // First pass adds the track; second pass finds it unchanged → skipped.
        $this->assertSame(1, $first->added, 'first scan should add the track');
        $this->assertSame(0, $second->added, 'rescan must NOT re-add the track');
        $this->assertSame(0, $second->updated, 'unchanged rescan must not update');

        // The crux: exactly ONE track media_item and ONE music_tracks row across
        // both scans — the dup bug produced two of each on the second pass.
        $trackMediaItems = array_filter(
            $mediaItemInserts,
            static fn(array $params): bool => ($params[2] ?? null) === 'track',
        );
        $this->assertCount(1, $trackMediaItems, 'exactly one track media_items row across two scans');
        $this->assertCount(1, $trackInserts, 'exactly one music_tracks row across two scans');
    }

    public function testNewTrackDispatchesExactlyOneMediaItemAdded(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'song.mp3');

        $db = $this->statefulDbMock($mediaItemInserts, $trackInserts);
        $dispatcher = new RecordingEventDispatcher();
        $scanner = new MusicLibraryScanner($db, $this->ffmpegSong(), null, $dispatcher);

        $scanner->scanDirectory($dir, null, 'lib-42');

        $this->assertCount(1, $dispatcher->events, 'a new track dispatches exactly one event');
        $event = $dispatcher->events[0];
        $this->assertInstanceOf(MediaItemAdded::class, $event);
        $this->assertSame('track', $event->type);
        $this->assertSame('lib-42', $event->libraryId);
        $this->assertSame($dir . '/song.mp3', $event->path);

        // mediaItemId must be the id of the track's media_items row (params[0]).
        $trackMediaItemId = null;
        foreach ($mediaItemInserts as $params) {
            if (($params[2] ?? null) === 'track') {
                $trackMediaItemId = $params[0] ?? null;
            }
        }
        $this->assertIsString($trackMediaItemId);
        $this->assertNotSame('', $trackMediaItemId);
        $this->assertSame($trackMediaItemId, $event->mediaItemId);
    }

    public function testSkippedTrackDispatchesNoMediaItemAdded(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'song.mp3');

        $db = $this->statefulDbMock($mediaItemInserts, $trackInserts);
        $dispatcher = new RecordingEventDispatcher();
        $scanner = new MusicLibraryScanner($db, $this->ffmpegSong(), null, $dispatcher);

        $scanner->scanDirectory($dir, null, 'lib-42'); // first: 1 event
        $dispatcher->events = [];                        // reset
        $scanner->scanDirectory($dir, null, 'lib-42'); // second: track skipped

        $this->assertCount(0, $dispatcher->events, 'a skipped (already-present) track dispatches no event');
    }

    public function testInsertedMediaItemsCarryLibraryId(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'song.mp3');

        $db = $this->statefulDbMock($mediaItemInserts, $trackInserts);
        $scanner = new MusicLibraryScanner($db, $this->ffmpegSong());

        $scanner->scanDirectory($dir, null, 'lib-99');

        $this->assertNotEmpty($mediaItemInserts);
        foreach ($mediaItemInserts as $params) {
            // Columns: (id, library_id, type, ...) — library_id is params[1].
            $this->assertSame('lib-99', $params[1], 'every music media_item must carry the scanning library id');
        }
    }

    public function testFileMatchingIgnorePatternSettingIsSkipped(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'sample.mp3');   // matches configured 'sample'
        $this->touchFile($dir, 'realsong.mp3'); // does not

        // The OLD hardcoded skip list (folder.jpg/cover.jpg/…) would NOT skip
        // 'sample.mp3'; only reading the effective setting does.
        $settings = $this->createMock(\Phlix\Admin\SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            static fn(string $key): mixed => $key === 'scanner.ignore_patterns' ? ['sample'] : null,
        );
        $ignore = new \Phlix\Media\Library\ScanIgnorePatterns($settings);

        $scanner = new MusicLibraryScanner(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            null,
            null,
            $ignore,
        );

        // countAudioFiles applies the exact same skip filter the scan uses.
        $this->assertSame(1, $scanner->countAudioFiles($dir), 'sample.mp3 must be skipped via the setting');
    }

    // -- S95: incremental flush ------------------------------------------------

    /**
     * Builds `$albums` directories under one root, each holding `$tracks` audio
     * files, and returns `[rootDir, totalFiles]`.
     *
     * One album per directory, and no directory holds a subdirectory, so
     * `RecursiveIteratorIterator` yields each album's files consecutively — which
     * makes the flush cadence below exactly predictable.
     *
     * @return array{0: string, 1: int}
     */
    private function buildAlbumTree(int $albums, int $tracks): array
    {
        $root = $this->tempDir();

        for ($a = 0; $a < $albums; $a++) {
            $albumDir = $root . sprintf('/album-%04d', $a);
            mkdir($albumDir, 0777, true);
            $this->cleanup[] = $albumDir;
            for ($t = 1; $t <= $tracks; $t++) {
                $this->touchFile($albumDir, sprintf('%03d-track.mp3', $t));
            }
        }

        return [$root, $albums * $tracks];
    }

    /**
     * Builds the WORST-CASE buffer fixture for
     * {@see self::testMemoryStaysBoundedAcrossALargeTree()}.
     *
     * `interleaved/` = 32 album keys × 249 files (one short of
     * MAX_TRACKS_PER_FLUSH, so no key ever chunk-flushes and the 32-album window
     * never overflows) → all 7,968 entries buffered at once, whatever order
     * `readdir()` yields. `tail/` = one album key per file, which forces an
     * eviction per file and is what makes an unbounded map exceed the ceiling.
     *
     * @return array{0: string, 1: int, 2: int} `[root, totalFiles, simultaneouslyBufferedEntries]`
     */
    private function buildWorstCaseBufferTree(): array
    {
        $keys = 32;
        $perKey = 249;          // MAX_TRACKS_PER_FLUSH (250) minus one
        $buffered = $keys * $perKey;
        $tail = 6032;

        $root = $this->tempDir();
        foreach (['interleaved', 'tail'] as $sub) {
            mkdir($root . '/' . $sub, 0777, true);
            $this->cleanup[] = $root . '/' . $sub;
        }

        for ($i = 0; $i < $buffered; $i++) {
            $this->touchFile($root . '/interleaved', sprintf('i%05d.mp3', $i));
        }
        for ($i = 0; $i < $tail; $i++) {
            $this->touchFile($root . '/tail', sprintf('t%05d.mp3', $i));
        }

        return [$root, $buffered + $tail, $buffered];
    }

    /**
     * A scanner whose tag reader is replaced by a pure function of the path, so a
     * large synthetic tree costs no getID3 or ffprobe work at all.
     *
     * Default tagging: one album per directory, with its own artist.
     */
    private function taggedScanner(
        Connection $db,
        ?\Closure $tagger = null,
        ?LoggerInterface $logger = null
    ): TaggedScanner {
        // ⚠ A NullLogger, NOT the production fallback (review r1 MED-3, half ii).
        // Since S96(a) the fallback is `LoggerFactory::get(MEDIA)`, and `LoggerFactory`
        // caches its config path in a PROCESS-GLOBAL static — so as soon as any other
        // test in the run calls `LoggerFactory::init(config/logger.php)` (19 of them
        // do), every scanner built here starts writing into the working tree's real
        // `.logs/app-*.log`. Measured on this file's synthetic trees: 27,094 lines /
        // 4.33 MiB per run, none of it assertable. Tests that DO care about the log
        // pass their own recording double, so this default weakens nothing; the
        // production fallback is pinned separately by
        // {@see self::testTheDefaultLoggerIsTheSharedMediaChannelLogger()}.
        $scanner = new TaggedScanner($db, $this->createMock(FfmpegRunner::class), $logger ?? new NullLogger());
        $scanner->tagger = $tagger ?? static function (string $path): array {
            $albumDir = basename(dirname($path));
            return [
                'artist' => 'Artist ' . $albumDir,
                'album' => 'Album ' . $albumDir,
                'title' => basename($path, '.mp3'),
                'track_number' => (int) substr(basename($path), 0, 3),
                'disc_number' => 1,
                'duration_secs' => 200,
                'year' => 2001,
                'genre' => 'Rock',
            ];
        };

        return $scanner;
    }

    /**
     * THE regression guard. Before S95 the scanner tag-probed the ENTIRE tree into
     * one in-memory map and only then began writing, so the very first row landed
     * after the last file had been read — measured on production as 4 h 09 m of
     * zero durable work on a 61,135-file library, and four consecutive rescans
     * that were killed mid-walk therefore persisted nothing at all.
     *
     * With one album per directory and two tracks each, the 33rd album opens at
     * file 65, which pushes the open-album window over MAX_OPEN_ALBUMS (32) and
     * evicts — and writes — the least-recently-touched album. So the first INSERT
     * must land at file 65 of 200, not at file 200.
     *
     * Reverting the fix leaves $firstInsertTick at NULL for all 200 ticks (the old
     * code issues its first statement only after the walk generator is exhausted),
     * which fails the first assertion outright.
     */
    public function testFirstRowIsWrittenLongBeforeTheWalkEnds(): void
    {
        [$dir, $total] = $this->buildAlbumTree(100, 2);
        $this->assertSame(200, $total);

        $db = new CountingConnection();
        $scanner = $this->taggedScanner($db);

        $tick = 0;
        $firstInsertTick = null;
        $db->onStatement = static function (string $sql) use (&$tick, &$firstInsertTick): void {
            if ($firstInsertTick === null && str_starts_with($sql, 'INSERT')) {
                $firstInsertTick = $tick;
            }
        };

        $scanner->scanDirectory($dir, static function (int $processed) use (&$tick): void {
            $tick = $processed;
        }, 'lib-s95');

        $this->assertNotNull(
            $firstInsertTick,
            'Rows must be written DURING the walk. NULL here is exactly the pre-S95 behaviour: '
            . 'nothing at all is persisted until the whole tree has been tag-probed.',
        );
        $this->assertLessThan($total, $firstInsertTick, 'the first write must precede the last file');
        $this->assertSame(
            65,
            $firstInsertTick,
            'the 33rd album (file 65) is what overflows the 32-album window and triggers the first flush',
        );

        // And the walk keeps writing: 100 albums minus the 32 still buffered when
        // the walk ends = 68 albums flushed mid-walk.
        $this->assertSame(100, $db->inserts['music_artists'] ?? 0);
        $this->assertSame(100, $db->inserts['music_albums'] ?? 0);
        $this->assertSame(200, $db->inserts['music_tracks'] ?? 0);
    }

    /**
     * Memory must not scale with the size of the tree, and the documented ceiling
     * must be the one the WORST case actually reaches.
     *
     * The pre-S95 map retained one `['file' => SplFileInfo, 'meta' => [...]]` entry
     * per audio file for the whole walk, so a 14,000-file tree held ≈ 20 MB and the
     * 61,135-file path that motivated this step ≈ 89 MB. The window retains at most
     * MAX_OPEN_ALBUMS (32) × MAX_TRACKS_PER_FLUSH (250) entries no matter how many
     * files follow.
     *
     * The fixture exercises that ceiling head-on instead of a shape that stays far
     * below it (300 albums × 20 tracks only ever buffers 32 × 20 = 640 entries — a
     * 3 MB assertion then passes for the wrong reason):
     *
     *  - `interleaved/` holds 32 album keys × 249 files. 249 is one short of
     *    MAX_TRACKS_PER_FLUSH, so NO key can chunk-flush and the 32-album window
     *    never overflows — every one of the 7,968 entries is buffered at once. That
     *    is independent of the order `readdir()` hands the files over, which is what
     *    keeps this deterministic.
     *  - `tail/` gives every file its own album key, forcing an eviction per file.
     *    It is what makes the pre-S95 whole-tree map blow the ceiling while the
     *    bounded window does not move.
     *
     * `RecursiveIteratorIterator` walks one directory at a time, so the peak is the
     * same whichever of the two it happens to visit first.
     */
    public function testMemoryStaysBoundedAcrossALargeTree(): void
    {
        [$dir, $total, $buffered] = $this->buildWorstCaseBufferTree();
        $this->assertSame(14000, $total);
        $this->assertSame(7968, $buffered, '32 keys x 249 files stay buffered simultaneously');

        $db = new CountingConnection();
        $scanner = $this->taggedScanner($db, static function (string $path): array {
            $base = basename($path, '.mp3');
            // 'i…' → one of 32 interleaved albums; 't…' → a unique album per file.
            $n = (int) substr($base, 1);
            $slot = $base[0] === 'i' ? 'inter-' . ($n % 32) : 'tail-' . $n;
            return [
                'artist' => 'Artist ' . $slot,
                'album' => 'Album ' . $slot,
                'title' => $base,
                'track_number' => 1,
                'disc_number' => 1,
                'duration_secs' => 200,
                'year' => 2001,
                'genre' => 'Rock',
            ];
        });

        gc_collect_cycles();
        $baseline = memory_get_usage();
        $peak = 0;

        // Sampled on EVERY tick: the worst case is reached at file 7,968, which no
        // fixed stride is guaranteed to land on.
        $scanner->scanDirectory($dir, static function () use ($baseline, &$peak): void {
            $peak = max($peak, memory_get_usage() - $baseline);
        }, 'lib-s95');

        $ceiling = 32 * 250 * self::BYTES_PER_BUFFERED_FILE;
        $this->assertLessThan(
            $ceiling,
            $peak,
            sprintf(
                'Walk-time memory must stay within MAX_OPEN_ALBUMS x MAX_TRACKS_PER_FLUSH entries; '
                . 'peaked at %d bytes (%.0f B/entry over %d buffered) across %d files, ceiling %d. '
                . 'The pre-S95 whole-tree map would hold ~%d bytes here.',
                $peak,
                $peak / $buffered,
                $buffered,
                $total,
                $ceiling,
                $total * self::BYTES_PER_BUFFERED_FILE,
            ),
        );

        // The bound is only meaningful if the fixture really did fill the window:
        // a peak far below it would mean the worst case was never assembled.
        $this->assertGreaterThan(
            $buffered * 1000,
            $peak,
            'the fixture must actually buffer 7,968 entries — otherwise the ceiling proves nothing',
        );

        // Sanity: it really did index the whole tree while staying flat.
        $this->assertSame(32 + ($total - $buffered), $db->inserts['music_albums'] ?? 0);
        $this->assertSame($total, $db->inserts['music_tracks'] ?? 0);
    }

    /**
     * The album-across-directories case, which is why the flush key is the album's
     * TAG identity and not its directory.
     *
     * `Album/CD1` and `Album/CD2` carry the same artist+album tags. A
     * directory-triggered flush would have written this as two separate batches;
     * the tag-keyed window keeps it as ONE album (the two directories are adjacent
     * in walk order, so the album never leaves the window).
     */
    public function testAlbumSplitAcrossTwoDirectoriesIsWrittenAsOneAlbum(): void
    {
        $root = $this->tempDir();
        foreach (['CD1', 'CD2'] as $cd) {
            $discDir = $root . '/' . $cd;
            mkdir($discDir, 0777, true);
            $this->cleanup[] = $discDir;
            $this->touchFile($discDir, '01-a.mp3');
            $this->touchFile($discDir, '02-b.mp3');
        }

        $db = $this->statefulDbMock($mediaItemInserts, $trackInserts);
        $scanner = $this->taggedScanner($db, static fn(string $path): array => [
            'artist' => 'One Artist',
            'album' => 'One Album',
            'title' => basename($path, '.mp3'),
            'track_number' => (int) substr(basename($path), 0, 2),
            'disc_number' => str_contains($path, 'CD2') ? 2 : 1,
            'duration_secs' => 100,
            'year' => 1999,
            'genre' => null,
        ]);

        $result = $scanner->scanDirectory($root, null, 'lib-1');

        $this->assertSame(4, $result->added);
        $this->assertCount(
            1,
            array_filter($mediaItemInserts, static fn(array $p): bool => ($p[2] ?? null) === 'album'),
            'an album spread over two directories must still be ONE album row',
        );
        $this->assertCount(
            1,
            array_filter($mediaItemInserts, static fn(array $p): bool => ($p[2] ?? null) === 'artist'),
        );
        $this->assertCount(4, $trackInserts, 'all four tracks belong to that one album');
    }

    /**
     * The mirror-image case: one directory holding several albums (a singles or
     * mixed folder). A window of exactly one album — the naive reading of "flush
     * when the album changes" — would thrash here; the 32-album window keeps all
     * three open and writes three distinct albums.
     */
    public function testMultipleAlbumsInOneDirectoryBecomeSeparateAlbums(): void
    {
        $dir = $this->tempDir();
        // Interleaved on purpose: A, B, C, A, B, C.
        foreach ([['A', 1], ['B', 1], ['C', 1], ['A', 2], ['B', 2], ['C', 2]] as [$album, $n]) {
            $this->touchFile($dir, sprintf('%s-%02d.mp3', $album, $n));
        }

        $db = $this->statefulDbMock($mediaItemInserts, $trackInserts);
        $scanner = $this->taggedScanner($db, static function (string $path): array {
            $letter = substr(basename($path), 0, 1);
            return [
                'artist' => 'Artist ' . $letter,
                'album' => 'Album ' . $letter,
                'title' => basename($path, '.mp3'),
                'track_number' => (int) substr(basename($path), 2, 2),
                'disc_number' => 1,
                'duration_secs' => 100,
                'year' => 2000,
                'genre' => null,
            ];
        });

        $result = $scanner->scanDirectory($dir, null, 'lib-1');

        $this->assertSame(6, $result->added);
        $this->assertCount(
            3,
            array_filter($mediaItemInserts, static fn(array $p): bool => ($p[2] ?? null) === 'album'),
            'three album tags in one directory must produce three album rows',
        );
        $this->assertCount(6, $trackInserts);
    }

    /**
     * The pathological single album: one directory tagged as ONE album but holding
     * more files than any album ever would. The tag-keyed window cannot bound that
     * on its own (the album never leaves the window), so MAX_TRACKS_PER_FLUSH (250)
     * chunks it — and because the album upsert is find-or-create, the two chunks
     * still converge on a single album row.
     */
    public function testAnOverlongAlbumIsChunkedButStillProducesOneAlbumRow(): void
    {
        $dir = $this->tempDir();
        for ($i = 1; $i <= 260; $i++) {
            $this->touchFile($dir, sprintf('%03d-t.mp3', $i));
        }

        $db = $this->statefulDbMock($mediaItemInserts, $trackInserts);
        $scanner = $this->taggedScanner($db, static fn(string $path): array => [
            'artist' => 'Bulk Artist',
            'album' => 'Bulk Album',
            'title' => basename($path, '.mp3'),
            'track_number' => (int) substr(basename($path), 0, 3),
            'disc_number' => 1,
            'duration_secs' => 100,
            'year' => 2000,
            'genre' => null,
        ]);

        $result = $scanner->scanDirectory($dir, null, 'lib-1');

        $this->assertSame(260, $result->added, 'every track lands even though the album was flushed twice');
        $this->assertCount(
            1,
            array_filter($mediaItemInserts, static fn(array $p): bool => ($p[2] ?? null) === 'album'),
            'a chunked album must NOT produce a second album row',
        );
        $this->assertCount(260, $trackInserts);
    }

    /**
     * `ScanResult::scanned` now counts audio FILES, matching the property's own
     * documented meaning and `countAudioFiles()`. It used to count album groups,
     * which is no longer well defined (one album can be flushed in several
     * batches).
     */
    public function testScannedCountsAudioFilesNotAlbumGroups(): void
    {
        [$dir, $total] = $this->buildAlbumTree(4, 3);

        $db = new CountingConnection();
        $scanner = $this->taggedScanner($db);

        $result = $scanner->scanDirectory($dir, null, 'lib-1');

        $this->assertSame(12, $total);
        $this->assertSame($total, $result->scanned);
        $this->assertSame(12, $result->added);
    }

    // -- S95 fix r1/r2: per-album resilience + orphan adoption -----------------

    /**
     * One album directory of `$files` files, all carrying the same artist/album tag
     * and ascending track numbers, plus the tagger that reads them.
     *
     * @return array{0: string, 1: \Closure(string): array<string, mixed>}
     */
    private function oneAlbumFixture(int $files, string $artist = 'Fault Artist', string $album = 'Fault Album'): array
    {
        $dir = $this->tempDir();
        for ($i = 1; $i <= $files; $i++) {
            $this->touchFile($dir, sprintf('%02d-t.mp3', $i));
        }

        $tagger = static fn(string $path): array => [
            'artist' => $artist,
            'album' => $album,
            'title' => basename($path, '.mp3'),
            'track_number' => (int) substr(basename($path), 0, 2),
            'disc_number' => 1,
            'duration_secs' => 100,
            'year' => 2001,
            'genre' => null,
        ];

        return [$dir, $tagger];
    }

    /**
     * `music_albums.total_tracks` must be refreshed even when the track loop is
     * ABANDONED part-way — which is why the call sits in a `finally` and not at the
     * tail of the `try`.
     *
     * The abort is modelled the only way it can still be CONSTRUCTED once every
     * `upsertTrack()` has its own catch: the track INSERT fails AND the write of the
     * "skipping track" log record fails too. ⚠ That second half is **not reachable in
     * production today** and this test does not claim it is — `StructuredLogger` wraps
     * every routed handler in a `WhatFailureGroupHandler`
     * ({@see \Phlix\Common\Logger\StructuredLogger}, deliberately, for the PHP 8.5 +
     * Swoole `set_error_handler` hazard), so a log write cannot propagate (verified
     * three ways in review r3: unwritable path, path-is-a-directory, `/dev/full`
     * ENOSPC — no throw in any). The `finally` is therefore defence-in-depth with no
     * live trigger, and this is the pin that keeps it: reverting the call to the tail
     * of the `try` — the exact pre-fix shape — skips it on that path and leaves an
     * album that HAS a track row advertising `total_tracks = 0`, which
     * `MusicLibraryService::getArtistWithAlbums()` sums into "0 tracks" on the artist
     * page, and nothing ever heals it. Any future statement added to the inner `try`
     * that CAN throw (or any logger without that wrapper) makes the trigger live
     * again, which is exactly why the `finally` stays.
     */
    public function testAlbumTrackTotalIsRefreshedEvenWhenTheTrackLoopIsAbandoned(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(4);

        $db = new MusicSchemaConnection();
        $db->faultOnNth('INSERT INTO music_tracks', 2);

        $logger = new LogWriteFailureLogger();
        $logger->throwOn = 'Skipping track';

        $scanner = $this->taggedScanner($db, $tagger, $logger);
        $result = $scanner->scanDirectory($dir, null, 'lib-s95');

        // The abort really did happen mid-album: one track in, three lost.
        $this->assertCount(1, $db->tracks, 'the injected log-write failure must abandon the rest of the album');
        $this->assertSame(1, $result->added);
        $this->assertSame(1, $logger->countMessages('Skipping album after error during indexing'));

        // S96(f) — THE `$handled` ACCOUNTING, which only this shape can pin. The outer
        // catch fires with some files already accounted for (file 1 added, file 2
        // charged by the per-track catch), so it must charge the REMAINDER, 2, and not
        // `count($files)` = 4. Total: 1 added + 3 failed = 4 files read, no silent
        // remainder and no double count.
        $this->assertSame(
            3,
            $result->failed,
            'the outer catch must charge only the files the track loop never reached (2 here) on top of '
            . 'the one the per-track catch already charged — 4 would be a double count, 1 would mean the '
            . 'abandoned remainder is not counted at all',
        );
        $this->assertSame($result->scanned, $result->added + $result->updated + $result->failed);

        // THE POINT: the column was still recomputed, and it matches the rows.
        $this->assertNotSame(
            [],
            $db->totalTracksWrites,
            'total_tracks must be recomputed even when the track loop is abandoned — that is what the '
            . '`finally` is for. Nothing here means the UPDATE was skipped, leaving total_tracks at 0 '
            . 'against a real track row (the pre-fix defect).',
        );
        $albumId = array_key_first($db->totalTracksWrites);
        $this->assertIsInt($albumId);
        $this->assertSame(
            count($db->tracks),
            $db->totalTracksWrites[$albumId],
            'total_tracks must equal COUNT(*) FROM music_tracks for the album',
        );
        $this->assertNotSame(0, $db->totalTracksWrites[$albumId], 'and must never be 0 while a track row exists');
    }

    /**
     * A single failing file must cost exactly that file: the per-track `try`/`catch`
     * is what keeps the REST of the album (and, with incremental flushing, every
     * album the walk has not reached yet) from being abandoned with it.
     *
     * Deleting that catch lets the throw unwind the whole loop — measured before the
     * fix: 1 of 4 tracks written when the 2nd file failed.
     */
    public function testOneFailingTrackCostsOnlyThatTrackAndTheAlbumStaysConsistent(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(4);

        $db = new MusicSchemaConnection();
        $db->faultOnNth('INSERT INTO music_tracks', 2);

        $logger = new LogWriteFailureLogger();
        $scanner = $this->taggedScanner($db, $tagger, $logger);

        $result = $scanner->scanDirectory($dir, null, 'lib-s95');

        $this->assertCount(
            3,
            $db->tracks,
            'the 2nd file failed, so tracks 3 and 4 must STILL be written — one bad file costs one file',
        );
        $this->assertSame(3, $result->added);

        // The lost file is the faulted one, not "everything after it".
        $titles = [];
        foreach ($db->tracks as $track) {
            $titles[] = $track['title'];
        }
        sort($titles);
        $this->assertSame(['01-t', '03-t', '04-t'], $titles);

        // The failure is logged per track, and the counts stay self-consistent.
        $this->assertSame(1, $logger->countMessages('Skipping track after error during indexing'));
        $this->assertSame(0, $logger->countMessages('Skipping album after error during indexing'));
        $albumId = array_key_first($db->totalTracksWrites);
        $this->assertIsInt($albumId);
        $this->assertSame(3, $db->totalTracksWrites[$albumId]);
    }

    /**
     * An artist `media_items` row orphaned by an interrupted scan must be ADOPTED,
     * not leaked: `upsertArtist()` finds-or-creates on the natural key (`name`), so
     * without the adoption lookup the next scan mints a second row and the first is
     * never reclaimed — `media_items` counts BY TYPE are what the music read path
     * and the stats maps report.
     */
    public function testAnOrphanedArtistMediaItemIsAdoptedInsteadOfLeaked(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(1, 'Orphaned Artist', 'Some Album');

        $db = new MusicSchemaConnection();
        $orphanId = $db->plantOrphan('artist', 'Orphaned Artist', 'lib-s95');

        $scanner = $this->taggedScanner($db, $tagger);
        $scanner->scanDirectory($dir, null, 'lib-s95');

        $this->assertSame(
            [$orphanId],
            $db->mediaItemIds('artist'),
            'the orphan must be re-used, so NO second artist media_items row may be minted',
        );
        $this->assertArrayHasKey('orphaned artist', $db->artists);
        $this->assertSame(
            $orphanId,
            $db->artists['orphaned artist']['media_item_id'],
            'the new music_artists row must point AT the adopted orphan',
        );
        // The gate must have enabled the lookup, since an orphan really is present.
        $this->assertGreaterThan(0, $db->countStatements('LEFT JOIN music_artists ma'));
    }

    /**
     * Album adoption, plus the constraint S97 will depend on: an orphaned album
     * `media_items` row is adoptable only by the artist it is parented to.
     *
     * Two artists here share the title `Greatest Hits`. One planted orphan is
     * unparented (today's shape — adoptable by whichever artist reaches it first)
     * and one is parented to a THIRD artist, modelling what S97 will write. The
     * second must never be adopted, or artist B silently inherits artist A's album
     * row and the hierarchy is wrong in a way `ma.id IS NULL` cannot detect.
     */
    public function testAnOrphanedAlbumIsAdoptedOnlyWhenItIsNotParentedToAnotherArtist(): void
    {
        $root = $this->tempDir();
        foreach (['artist-a', 'artist-b'] as $sub) {
            mkdir($root . '/' . $sub, 0777, true);
            $this->cleanup[] = $root . '/' . $sub;
            $this->touchFile($root . '/' . $sub, '01-t.mp3');
        }

        $db = new MusicSchemaConnection();
        $freeOrphan = $db->plantOrphan('album', 'Greatest Hits', 'lib-s95');
        $foreignOrphan = $db->plantOrphan('album', 'Greatest Hits', 'lib-s95', 'someone-elses-artist-id');

        $scanner = $this->taggedScanner($db, static fn(string $path): array => [
            'artist' => basename(dirname($path)),
            'album' => 'Greatest Hits',
            'title' => basename($path, '.mp3'),
            'track_number' => 1,
            'disc_number' => 1,
            'duration_secs' => 100,
            'year' => 2000,
            'genre' => null,
        ]);

        $scanner->scanDirectory($root, null, 'lib-s95');

        $this->assertCount(2, $db->albums, 'two artists with the same album title are two albums');

        // The unparented orphan was adopted — exactly one of the two albums points at it.
        $albumMediaItemIds = [];
        foreach ($db->albums as $album) {
            $albumMediaItemIds[] = $album['media_item_id'];
        }
        $this->assertContains($freeOrphan, $albumMediaItemIds, 'the unparented orphan must be adopted, not leaked');

        // The foreign-parented orphan was NOT: it is still unreferenced, and the
        // other album got a freshly minted row instead.
        $this->assertNotContains(
            $foreignOrphan,
            $albumMediaItemIds,
            'an album row parented to a DIFFERENT artist must never be adopted — that is S97 mis-parenting',
        );
        $this->assertFalse($db->isReferenced($foreignOrphan));
        $this->assertCount(
            3,
            $db->mediaItemIds('album'),
            'two planted orphans + exactly one freshly minted album row',
        );
    }

    /**
     * The cost side of adoption: on a library with no orphans at all, the per-entity
     * lookups must not be issued even once.
     *
     * `media_items` has no b-tree index on `name` (only `FULLTEXT idx_name`), so each
     * lookup is a full `type`-partition scan — measured at 5.2 ms per artist and
     * 17.1 ms per album on a prod-shaped population, ≈98 s over a first scan of the
     * real music library, and quadratic in albums. One gate query per scan replaces
     * all of it whenever the answer is "there is nothing to adopt".
     */
    public function testACleanLibrarySkipsThePerEntityAdoptionLookupsEntirely(): void
    {
        [$dir, $total] = $this->buildAlbumTree(3, 2);
        $this->assertSame(6, $total);

        $db = new MusicSchemaConnection();
        $scanner = $this->taggedScanner($db);

        $result = $scanner->scanDirectory($dir, null, 'lib-s95');

        $this->assertSame(
            1,
            $db->countStatements('LEFT JOIN music_artists ar'),
            'the orphan gate must be asked exactly ONCE per scan',
        );
        $this->assertSame(
            0,
            $db->countStatements('LEFT JOIN music_artists ma'),
            'with no orphan to adopt, the unindexed per-artist lookup must never run',
        );
        $this->assertSame(
            0,
            $db->countStatements('LEFT JOIN music_albums ma'),
            'with no orphan to adopt, the unindexed per-album lookup must never run',
        );

        // And the scan is otherwise unchanged: everything still indexed exactly once.
        $this->assertSame(6, $result->added);
        $this->assertCount(3, $db->artists);
        $this->assertCount(3, $db->albums);
        $this->assertCount(6, $db->tracks);
        $this->assertCount(3, $db->mediaItemIds('artist'));
        $this->assertCount(3, $db->mediaItemIds('album'));
        $this->assertCount(6, $db->mediaItemIds('track'));
    }

    /**
     * The gate must FAIL OPEN mid-walk: a caught write failure that orphans an artist
     * `media_items` row has to re-enable adoption for the REST OF THE SAME SCAN.
     *
     * 50 albums, one file each, all artists distinct except albums 3 and 50, which
     * share one. The library starts clean, so `hasAdoptableMusicMediaItem()` answers
     * "nothing to adopt" and `$mayAdopt` starts FALSE. The shared artist's first
     * `INSERT INTO music_artists` then fails: its `media_items` row is already
     * committed, `flushAlbum()` catches, logs "Skipping album …" and **the scan carries
     * on** — so that row is an orphan while the walk is still running. When the same
     * artist comes round again the natural-key `SELECT` is still empty, and if the
     * one-per-scan answer were final the scanner would mint a SECOND row and the first
     * would be unreachable FOREVER (every later scan short-circuits on the natural key
     * before the adoption lookup — measured on real MySQL: `media_items[artist] = 2`
     * against `music_artists = 1`, surviving two clean rescans).
     *
     * This also pins the plumbing, not just the flip: `$mayAdopt` is written inside
     * `upsertArtist()` and consumed there too, two by-reference hops from
     * `scanDirectory()`'s local (`upsertArtist` ← `flushAlbum` ← `scanDirectory`). If
     * any hop is by VALUE the counted adoption lookup below stays at 0 while every
     * other assertion still passes.
     */
    public function testAnArtistOrphanedMidWalkIsAdoptedWhenTheSameArtistRecursInTheSameScan(): void
    {
        $root = $this->tempDir();
        for ($a = 1; $a <= 50; $a++) {
            $albumDir = $root . sprintf('/album-%02d', $a);
            mkdir($albumDir, 0777, true);
            $this->cleanup[] = $albumDir;
            $this->touchFile($albumDir, '01-t.mp3');
        }

        $tagger = static function (string $path): array {
            $n = (int) substr(basename(dirname($path)), -2);

            return [
                // Albums 3 and 50 are the SAME artist — prod averages ≈2.3 albums
                // per artist, so a recurring artist is the normal case.
                'artist' => in_array($n, [3, 50], true) ? 'Shared Artist' : sprintf('Artist %02d', $n),
                'album' => sprintf('Album %02d', $n),
                'title' => basename($path, '.mp3'),
                'track_number' => 1,
                'disc_number' => 1,
                'duration_secs' => 100,
                'year' => 2001,
                'genre' => null,
            ];
        };

        $db = new MusicSchemaConnection();
        $db->faultOnNth('INSERT INTO music_artists', 1, 'Shared Artist');

        $logger = new LogWriteFailureLogger();
        $scanner = $this->taggedScanner($db, $tagger, $logger);

        $scanner->scanDirectory($root, null, 'lib-s95');

        // Preconditions: the library really was clean (one gate query, answered "no"),
        // and the modelled failure really did happen and really was survived.
        $this->assertSame(1, $db->countStatements('LEFT JOIN music_artists ar'), 'the gate is asked exactly once');
        $this->assertSame(1, $logger->countMessages('Skipping album after error during indexing'));
        $this->assertCount(49, $db->albums, 'the faulted album is skipped; the other 49 are indexed');

        // THE POINT: the orphan was re-adopted inside the same scan, so there is
        // exactly ONE artist media_items row for the shared artist and nothing is left
        // dangling. Without the fail-open flip: 50 artist rows for 49 artists, one of
        // them unreferenced and unreachable for good.
        $this->assertSame(
            [],
            $db->orphanedMusicMediaItems(),
            'a caught mid-walk write failure must not leave an unreferenced artist/album media_items row: '
            . 'the gate has to fail OPEN so the next encounter with the same name adopts it',
        );
        $this->assertCount(49, $db->mediaItemIds('artist'), '49 distinct artists must own exactly 49 rows');
        $this->assertCount(49, $db->artists);
        $referencedIds = array_values(array_map(
            static fn(array $artist): string => (string) $artist['media_item_id'],
            $db->artists,
        ));
        $mintedIds = $db->mediaItemIds('artist');
        sort($referencedIds);
        sort($mintedIds);
        $this->assertSame(
            $mintedIds,
            $referencedIds,
            'every artist media_items row must be pointed at by exactly one music_artists row',
        );

        // The plumbing proof: adoption was switched back ON at the CONSUMPTION site
        // even though the gate said "clean".
        $this->assertGreaterThan(
            0,
            $db->countStatements('LEFT JOIN music_artists ma'),
            'the per-artist adoption lookup must run again after the scan orphaned a row — '
            . '0 here means the flip never reached upsertArtist() (a by-value hop)',
        );
    }

    /**
     * The album half of the same window, and it needs no recurring artist at all:
     * `MAX_TRACKS_PER_FLUSH` (250) chunks one oversized album into TWO flushes of the
     * SAME album key inside ONE scan, so 251 files plus a failed first
     * `INSERT INTO music_albums` deterministically meets the orphaned album
     * `media_items` row again while the walk is still running.
     *
     * Same failure shape as the artist case: the album's `media_items` row is
     * committed, the `music_albums` INSERT fails, `flushAlbum()` catches and the scan
     * continues. With the one-per-scan answer treated as final, the second chunk mints
     * a second album row and the first is orphaned permanently (measured on real
     * MySQL: `media_items[album] = 2` against `music_albums = 1`, surviving two clean
     * rescans).
     */
    public function testAnAlbumOrphanedByAChunkedFlushIsAdoptedByTheNextChunkOfTheSameScan(): void
    {
        // 251 = MAX_TRACKS_PER_FLUSH + 1: one chunk flush plus the terminal flush.
        [$dir, $tagger] = $this->oneAlbumFixture(251, 'Chunked Artist', 'Chunked Album');

        $db = new MusicSchemaConnection();
        $db->faultOnNth('INSERT INTO music_albums', 1);

        $logger = new LogWriteFailureLogger();
        $scanner = $this->taggedScanner($db, $tagger, $logger);

        $result = $scanner->scanDirectory($dir, null, 'lib-s95');

        // Preconditions: clean library (gate asked once, answered "no"), the album was
        // really flushed twice, and the first flush was really abandoned.
        $this->assertSame(1, $db->countStatements('LEFT JOIN music_artists ar'), 'the gate is asked exactly once');
        $this->assertSame(
            2,
            $db->countStatements('INSERT INTO music_albums'),
            '251 files must produce TWO flushes of the one album key',
        );
        $this->assertSame(1, $logger->countMessages('Skipping album after error during indexing'));
        $this->assertSame(1, $result->added, 'the 250 tracks of the abandoned chunk are lost; the 251st is written');

        // THE POINT: the second chunk adopted the row the first one orphaned.
        $this->assertSame(
            [],
            $db->orphanedMusicMediaItems(),
            'the album media_items row orphaned by the first chunk must be adopted by the second, '
            . 'not left behind while a rival row is minted',
        );
        $this->assertCount(1, $db->mediaItemIds('album'), 'one album must own exactly one album media_items row');
        $this->assertCount(1, $db->albums);
        $albumId = array_key_first($db->albums);
        $this->assertIsInt($albumId);
        $this->assertSame(
            $db->mediaItemIds('album')[0],
            $db->albums[$albumId]['media_item_id'],
            'the surviving music_albums row must point AT the adopted row',
        );

        // The plumbing proof, at the album consumption site this time.
        $this->assertGreaterThan(
            0,
            $db->countStatements('LEFT JOIN music_albums ma'),
            'the per-album adoption lookup must run again after the scan orphaned a row — '
            . '0 here means the flip never reached upsertAlbum() (a by-value hop)',
        );
    }

    /**
     * The third arm of the fail-open rule, and the reason "referenced" is defined as
     * *the `music_*` row carries the id we minted* rather than *the INSERT succeeded*:
     * a mint that reports failure leaves the scanner unable to prove no row exists.
     *
     * `createMediaItem()` swallows its own Throwable and returns `''`, so the
     * `music_artists` INSERT then binds `media_item_id = NULL` and SUCCEEDS. If a
     * dropped connection (or any error raised after the server committed) is what
     * produced that `''`, the `media_items` row is really there and really orphaned —
     * and nothing downstream can tell that case apart from the ordinary one.
     *
     * ⚠ What this test pins is therefore the CODE PATH, not a reclaimed row: the fault
     * is injected before the double inserts anything, so no orphan exists here and
     * none is adopted. It asserts only that an unconfirmed mint re-enables adoption for
     * the rest of the scan, which is what covers the committed-but-reported-failed
     * variant. The orphan in that variant is not reclaimed by THIS scan either way,
     * because `music_artists` now holds a row for the name and every later lookup
     * short-circuits on that natural key with `media_item_id = NULL`. Reclaiming THAT
     * residue is S96(e)'s backfill, which runs from inside the natural-key branch and
     * issues these very adoption lookups — which is why the flag has to be open
     * exactly here. Each artist appears once in this fixture, so the backfill has no
     * second encounter to fire on and the NULL below is still expected;
     * {@see self::testANullArtistMediaItemIdIsBackfilledWhenTheArtistRecurs()} is where
     * the healing itself is pinned.
     */
    public function testAMintThatIsNotConfirmedReEnablesAdoptionForTheRestOfTheScan(): void
    {
        [$dir, $total] = $this->buildAlbumTree(3, 1);
        $this->assertSame(3, $total);

        $db = new MusicSchemaConnection();
        // The first media_items INSERT of a flush is always the artist mint.
        $db->faultOnNth('INSERT INTO media_items', 1);

        $logger = new LogWriteFailureLogger();
        $scanner = $this->taggedScanner($db, null, $logger);

        $scanner->scanDirectory($dir, null, 'lib-s95');

        // The library was clean, so the gate answered "no" — once.
        $this->assertSame(1, $db->countStatements('LEFT JOIN music_artists ar'), 'the gate is asked exactly once');
        $this->assertSame(1, $logger->countMessages('Failed to create media_item'));

        // THE POINT: adoption is back on for the remaining artists/albums even though
        // the gate said the library was clean and nothing threw past flushAlbum().
        $this->assertGreaterThan(
            0,
            $db->countStatements('LEFT JOIN music_artists ma'),
            'an unconfirmed mint must re-enable the adoption lookups: with `referenced` defined as merely '
            . '"the INSERT succeeded", a media_items row that WAS committed but reported as failed stays '
            . 'orphaned with adoption switched off for the rest of the scan',
        );

        // And the scan is otherwise intact: 3 artists indexed, one of them with the
        // pre-existing NULL media_item_id gap that S96(e) owns, no orphan anywhere.
        $this->assertCount(3, $db->artists);
        $this->assertCount(2, $db->mediaItemIds('artist'), 'the faulted mint wrote no row at all');
        $this->assertSame([], $db->orphanedMusicMediaItems());
        $nullLinked = 0;
        foreach ($db->artists as $artist) {
            if ($artist['media_item_id'] === null) {
                $nullLinked++;
            }
        }
        $this->assertSame(1, $nullLinked, 'exactly one music_artists row keeps the S96(e) NULL media_item_id');
    }

    // -- S96(a): the log reaches the app log, and leaks no temp directory ------

    /**
     * Constructing the scanner must create NO directory under `sys_get_temp_dir()`.
     *
     * `createLogger()` used to `mkdir()` a `phlix_music_scanner_<uniqid>` directory on
     * EVERY construction and point a private `StructuredLogger` at a log file inside
     * it. Two failures in one: the file was invisible to operators (the `phlix-server`
     * unit runs with `PrivateTmp`, so it lived in a per-unit mount namespace, was
     * unreadable without `nsenter`-ing the MainPID, and was destroyed on restart), and
     * the directories accumulated — 66 counted on production, 6,346 on the dev box.
     * That invisible log is why the empty music library survived four wrong diagnoses.
     *
     * Six production sites construct this class with no logger at all
     * (`Application.php` x5, `NewsletterSender`), so "no logger" is the common case,
     * not an edge one.
     */
    public function testConstructingTheScannerCreatesNoTemporaryLogDirectory(): void
    {
        $pattern = sys_get_temp_dir() . '/phlix_music_scanner_*';
        $before = glob($pattern);
        $this->assertIsArray($before);

        // Three, so a per-construction leak cannot hide behind a coincidence.
        for ($i = 0; $i < 3; $i++) {
            new MusicLibraryScanner(
                $this->createMock(Connection::class),
                $this->createMock(FfmpegRunner::class),
            );
        }

        $after = glob($pattern);
        $this->assertIsArray($after);
        $this->assertSame(
            count($before),
            count($after),
            'the scanner must not mkdir a private log directory: on production those land inside the '
            . "unit's PrivateTmp, where no operator can read them, and they leak one per instantiation",
        );
    }

    /**
     * A caller-supplied PSR-3 logger must actually be USED.
     *
     * The old `createLogger()` accepted only a `StructuredLogger` and silently threw
     * every other `LoggerInterface` away, falling through to the temp-directory logger.
     * Combined with `MediaServicesProvider` passing no logger at all, that made the
     * "fallback" the only live path. This asserts the injected logger receives the
     * scan's own lines — the property `MediaServicesProvider`'s
     * `constructorParameter('logger', get('logger.media'))` relies on.
     */
    public function testAnInjectedPlainPsrLoggerReceivesTheScanLines(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, '01-song.mp3');

        // A MOCK of the INTERFACE, not a bespoke double: it is provably not a
        // `StructuredLogger`, which is the whole discrimination, and it removes the ninth
        // class from this file (review r2 F9 — PSR-12 "each class must be in a file by
        // itself"). The callback only RECORDS; every assertion is in the test body, so
        // nothing can be swallowed by a `catch` inside the scanner (the S120 hazard).
        $messages = [];
        $record = static function (string|\Stringable $message) use (&$messages): void {
            $messages[] = (string) $message;
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback($record);
        $logger->method('warning')->willReturnCallback($record);
        $logger->method('error')->willReturnCallback($record);
        $logger->method('debug')->willReturnCallback($record);

        // A schema-aware double, not a bare Connection mock: since review r2 F1 an INSERT
        // that reports writing nothing is correctly treated as a LOST file, so a mock whose
        // query() returns null would make this fixture take the failure path and close with
        // the "…with skipped files" summary. This test is about the logger, so the scan
        // itself must succeed.
        $scanner = new TaggedScanner(
            new MusicSchemaConnection(),
            $this->createMock(FfmpegRunner::class),
            $logger,
        );
        $scanner->tagger = static fn(string $path): array => [
            'artist' => 'Injected Logger Artist',
            'album' => 'Album',
            'title' => basename($path, '.mp3'),
            'track_number' => 1,
            'disc_number' => 1,
            'duration_secs' => 10,
            'year' => 2000,
            'genre' => null,
        ];

        $scanner->scanDirectory($dir, null, 'lib-s96');

        $this->assertContains(
            'Starting music directory scan',
            $messages,
            'a caller-supplied PSR-3 logger must receive the scan log lines. Absent here means the '
            . 'scanner discarded it and wrote into its own temp directory instead — the S96(a) defect.',
        );
        $this->assertContains(
            'Music directory scan complete',
            $messages,
            'the CLEAN summary, exactly: this fixture indexes its one file successfully, so the lossy '
            . '"…with skipped files" variant here would mean the scan silently failed',
        );
    }

    /**
     * With NO logger supplied, the scanner must use the SHARED media-channel logger —
     * the one `config/logger.php` routes into `.logs/app.log` and `.logs/error.log`.
     *
     * This is the other half of S96(a), and it needs its own guard now that this file
     * injects a {@see NullLogger} by default to stop the suite writing megabytes into
     * the working tree (review r1 MED-3). Without it, "silence the tests" and "silence
     * production" look identical: swapping the fallback in `createLogger()` for a
     * NullLogger would make the whole suite pass while restoring the exact invisibility
     * that let the empty music library survive four wrong diagnoses. Identity — not
     * just "is a StructuredLogger" — because `LoggerFactory::get()` returns the one
     * cached instance per channel, so this also proves the channel is MEDIA.
     */
    public function testTheDefaultLoggerIsTheSharedMediaChannelLogger(): void
    {
        $scanner = new MusicLibraryScanner(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
        );

        $property = new \ReflectionProperty(MusicLibraryScanner::class, 'logger');
        $property->setAccessible(true);
        $logger = $property->getValue($scanner);

        $this->assertSame(
            LoggerFactory::get(LogChannels::MEDIA),
            $logger,
            'a scanner built with no logger must log to the shared MEDIA channel. A NullLogger (or a '
            . 'privately-built one) here is the S96(a) defect: the music scan goes silent exactly where '
            . 'an operator looks, which is how the root cause stayed undiagnosed.',
        );
    }

    /**
     * MED-3 (review r1): the scan must NOT log once per track, and must still log once
     * per album/artist.
     *
     * S96(a) routed this logger into `.logs/app.log` (handler level `debug`), which
     * turned an invisible per-track line into ~89 % of everything the scan writes there
     * — measured 61,135 of 68,247 lines for the production library, burying the loss
     * lines the step exists to surface. Both halves of the decision are pinned here
     * because both are easy to undo by accident: re-adding a per-track line restores the
     * volume, and deleting the per-album lines would leave a successful scan with no
     * write trace at all at any level. The album is S95's flush unit, so one line per
     * flush boundary is the granularity that stays.
     */
    public function testTheScanLogsPerAlbumButNotPerTrack(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(3);

        $logger = new LogWriteFailureLogger();
        $result = $this->taggedScanner(new MusicSchemaConnection(), $tagger, $logger)
            ->scanDirectory($dir, null, 'lib-s96');

        $this->assertSame(3, $result->added, 'the fixture must really index three tracks');
        $this->assertSame(
            0,
            $logger->countMessages('Upserted track'),
            'a per-TRACK success line scales with file count and is redundant with both the '
            . 'music_tracks row it announces and the live items_added counter — it made app.log '
            . 'unreadable, which defeats the point of routing the log there at all',
        );
        $this->assertSame(1, $logger->countMessages('Upserted album'), 'the per-album trace stays');
        $this->assertSame(1, $logger->countMessages('Upserted artist'), 'the per-artist trace stays');
    }

    // -- S96(f): a scan that loses files says so -------------------------------

    /**
     * **THE r2 HIGH REGRESSION GUARD: a total silent loss must not look like a benign
     * rescan of an unchanged library.**
     *
     * Before the `'skipped'` / `'failed'` split, `upsertTrack()` returned `'skipped'`
     * both when a file was LOST (no `media_items` row, no `music_tracks` row) and when
     * it was simply UNCHANGED — so `flushAlbum()` charged neither, and review r2
     * measured the two cases as byte-identical on every surface S96 built:
     *
     * ```
     * S3 every music_tracks INSERT wrote nothing  scanned=5 added=0 updated=0 failed=0  INFO
     * S6 second scan, nothing changed (benign)    scanned=5 added=0 updated=0 failed=0  INFO
     * ```
     *
     * `items_failed` is migration 095's entire reason to exist and the step's acceptance
     * criterion is that a scan which skipped files reports a non-zero count somewhere an
     * operator can see. For this shape the count was zero everywhere. This test asserts
     * the two scenarios and then asserts they DIFFER — the discrimination is the point,
     * so a fix that merely made both louder would not satisfy it.
     */
    public function testATotalSilentLossIsDistinguishableFromABenignUnchangedRescan(): void
    {
        // --- S3: every music_tracks INSERT reports that it wrote nothing.
        [$lossyDir, $tagger] = $this->oneAlbumFixture(5);
        $lossyDb = new MusicSchemaConnection();
        $lossyDb->returnFalseFor('INSERT INTO music_tracks');
        $lossyLog = new LogWriteFailureLogger();
        $lossy = $this->taggedScanner($lossyDb, $tagger, $lossyLog)->scanDirectory($lossyDir, null, 'lib-s96');

        $this->assertCount(0, $lossyDb->tracks, 'the fixture must really lose every file');
        $this->assertSame(0, $lossy->added);
        $this->assertSame(
            5,
            $lossy->failed,
            'all five lost files must be charged. 0 here is the r2 HIGH defect: upsertTrack() returned '
            . "'skipped' for a LOST file, exactly as it does for an unchanged one, so nothing was charged",
        );
        $this->assertSame(
            5,
            $lossyLog->countAtLevel('error', 'Track was not indexed'),
            'and each lost file must name itself at ERROR — this shape used to emit NO log line at any level',
        );
        $this->assertSame(
            1,
            $lossyLog->countAtLevel('error', 'Music directory scan complete with skipped files'),
            'the summary must say the scan lost files, at ERROR so it reaches .logs/error.log',
        );

        // --- S6: a second scan of an unchanged library. Every file takes the BENIGN
        //     'skipped' path, which must stay charged to nothing.
        [$benignDir, $benignTagger] = $this->oneAlbumFixture(5);
        $benignDb = new MusicSchemaConnection();
        $first = $this->taggedScanner($benignDb, $benignTagger, new LogWriteFailureLogger())
            ->scanDirectory($benignDir, null, 'lib-s96');
        $this->assertSame(5, $first->added, 'the first scan must actually index the album');

        $benignLog = new LogWriteFailureLogger();
        $benign = $this->taggedScanner($benignDb, $benignTagger, $benignLog)
            ->scanDirectory($benignDir, null, 'lib-s96');

        $this->assertSame(0, $benign->added);
        $this->assertSame(0, $benign->updated);
        $this->assertSame(
            0,
            $benign->failed,
            'an UNCHANGED library must report zero failures — charging every benign skip would make '
            . 'every rescan of every healthy library look like a total loss, which is the opposite error',
        );
        $this->assertSame(0, $benignLog->countAtLevel('error', 'Track was not indexed'));
        $this->assertSame(1, $benignLog->countMessages('Music directory scan complete', true));
        $this->assertCount(5, $benignDb->tracks, 'and the rows are still there — nothing was lost');

        // --- THE DISCRIMINATION. Same `scanned`, same `added`, same `updated`: before
        //     the split these two ScanResults were equal, so no consumer could tell a
        //     library that lost everything from one that changed nothing.
        $this->assertSame($lossy->scanned, $benign->scanned, 'both read the same number of files');
        $this->assertSame($lossy->added, $benign->added, 'and both added nothing');
        $this->assertNotSame(
            $benign->toArray(),
            $lossy->toArray(),
            'the API response for a total loss must differ from the one for an unchanged library. '
            . 'Equal arrays here mean POST /api/v1/music/scan, library_scan_jobs.items_failed and '
            . '`library:scan` are all still reporting a silent total loss as a clean success',
        );
        $this->assertNotSame($benign->failed, $lossy->failed, 'items_failed is what tells them apart');
    }

    /**
     * The PRODUCTION-REACHABLE half of the r2 HIGH finding (its S2 scenario).
     *
     * `createMediaItem()` catches its own `\Throwable` and returns `''`, so a DB error
     * while minting a track's `media_items` row NEVER reaches `flushAlbum()`'s per-track
     * `catch` — the only signal is `upsertTrack()`'s return value. This is the shape that
     * needs no modelled `false` at all: a duplicate key, a bad ENUM value or a lost
     * connection all land here.
     *
     * It also pins the log content: `createMediaItem()`'s own error line carries the type
     * and title but NOT the path, so without the `Track was not indexed` line an operator
     * could see that something failed and still not know which file to look at.
     */
    public function testAFileLostToAFailedMediaItemMintIsChargedAndNamed(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(5);

        $db = new MusicSchemaConnection();
        // Fault ONLY the track's media_items INSERT (the param narrowing matches the
        // bound `type` value), leaving the artist and album mints healthy.
        $db->faultOnNth('INSERT INTO media_items', 1, 'track');
        $logger = new LogWriteFailureLogger();

        $result = $this->taggedScanner($db, $tagger, $logger)->scanDirectory($dir, null, 'lib-s96');

        $this->assertSame(4, $result->added, 'the other four files still land');
        $this->assertCount(4, $db->tracks);
        $this->assertSame(
            1,
            $result->failed,
            'the file whose media_item could not be minted is LOST and must be charged: it has no '
            . 'media_items row and no music_tracks row, and createMediaItem() swallowed the throw',
        );
        $this->assertSame(1, $logger->countAtLevel('error', 'Failed to create media_item'));

        $named = 0;
        foreach ($logger->records as $record) {
            if ($record['level'] === 'error' && str_contains($record['message'], 'Track was not indexed')) {
                $named++;
            }
        }
        $this->assertSame(
            1,
            $named,
            'exactly one lost file, named at ERROR. createMediaItem()\'s own line has no path in it, '
            . 'so this is the only line that tells an operator WHICH file was dropped',
        );
        $this->assertSame(1, $logger->countAtLevel('error', 'Music directory scan complete with skipped files'));
    }

    /**
     * One failing track increments `ScanResult::$failed` by exactly one.
     *
     * S95 left this measurable ONLY by the file's absence: with its `finally`
     * recomputing `total_tracks` from the rows that exist, a 4-track album that lost
     * its 2nd file reported `scanned=4 added=3` with `music_tracks=3`,
     * `total_tracks=3` and zero inconsistencies — the database was perfectly
     * self-consistent while the library was one file short.
     */
    public function testAFailedTrackIsCountedInScanResultFailed(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(4);

        $db = new MusicSchemaConnection();
        $db->faultOnNth('INSERT INTO music_tracks', 2);

        $logger = new LogWriteFailureLogger();
        $scanner = $this->taggedScanner($db, $tagger, $logger);

        $result = $scanner->scanDirectory($dir, null, 'lib-s96');

        $this->assertSame(3, $result->added);
        $this->assertSame(
            1,
            $result->failed,
            'the lost file must be counted. 0 here is the S96(f) defect: `scanned=4 added=3` with a '
            . 'self-consistent database and no counter anywhere saying a file was skipped',
        );
        $this->assertSame(4, $result->scanned, 'it did read all four files');
        $this->assertSame(
            $result->scanned,
            $result->added + $result->updated + $result->failed,
            'every file read is accounted for as added, updated or failed — no silent remainder',
        );
        $this->assertCount(3, $db->tracks, 'and the counter agrees with the rows that really landed');

        // The counter reaches the API surface too: POST /api/v1/music/scan returns
        // exactly this array.
        $this->assertArrayHasKey('failed', $result->toArray());
        $this->assertSame(1, $result->toArray()['failed']);

        // And it is stated in the completion line — at ERROR since review r1 MED-2, so
        // it reaches `.logs/error.log` and not only `app.log`. The level itself is
        // pinned by testEveryPathThatLosesFilesLogsAtErrorLevel().
        $this->assertSame(
            1,
            $logger->countMessages('Music directory scan complete with skipped files'),
            'a scan that lost files must not log the same clean "scan complete" line as one that did not',
        );
        $this->assertSame(0, $logger->countMessages('Music directory scan complete', true));
    }

    /**
     * When the whole album is lost — here because its artist row could not be written —
     * every one of its files is charged to `failed`, not just one.
     *
     * The throw unwinds into `flushAlbum()`'s outer catch, which is the path that used
     * to log "Skipping album …" into `PrivateTmp` and report nothing at all.
     */
    public function testAnAlbumLostToAFailedArtistWriteChargesEveryFileToFailed(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(5);

        $db = new MusicSchemaConnection();
        $db->faultOnNth('INSERT INTO music_artists', 1);

        $logger = new LogWriteFailureLogger();
        $scanner = $this->taggedScanner($db, $tagger, $logger);

        $result = $scanner->scanDirectory($dir, null, 'lib-s96');

        $this->assertSame(0, $result->added);
        $this->assertCount(0, $db->tracks);
        $this->assertSame(
            5,
            $result->failed,
            'all five files were lost with the album, so all five must be counted',
        );
        $this->assertSame(1, $logger->countMessages('Skipping album after error during indexing'));
    }

    /**
     * A track that fails part-way through an album must not be double-counted by the
     * outer catch, and a clean scan must report exactly zero.
     *
     * This is the over-counting guard for `$handled`: if the outer catch charged
     * `count($files)` unconditionally the first assertion would read 4 instead of 1.
     */
    public function testACleanScanReportsZeroFailedAndFailuresAreNotDoubleCounted(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(4);

        $db = new MusicSchemaConnection();
        $db->faultOnNth('INSERT INTO music_tracks', 2);
        $scanner = $this->taggedScanner($db, $tagger, new LogWriteFailureLogger());
        $this->assertSame(1, $scanner->scanDirectory($dir, null, 'lib-s96')->failed);

        [$cleanDir, $total] = $this->buildAlbumTree(3, 2);
        $cleanDb = new MusicSchemaConnection();
        $cleanLogger = new LogWriteFailureLogger();
        $clean = $this->taggedScanner($cleanDb, null, $cleanLogger)->scanDirectory($cleanDir, null, 'lib-s96');

        $this->assertSame($total, $clean->added);
        $this->assertSame(0, $clean->failed, 'nothing failed, so the counter must be 0');
        $this->assertSame(1, $cleanLogger->countMessages('Music directory scan complete', true));
        $this->assertSame(0, $cleanLogger->countMessages('Music directory scan complete with skipped files'));
    }

    /**
     * Files dropped by the "unknown artist" rule are NOT failures.
     *
     * That rule is a documented scan POLICY (a whole album group is discarded when its
     * artist tag is missing) with its own follow-up step, and a rescan drops the same
     * files again. Folding it into `items_failed` would make every untagged library
     * look like it is erroring. It is still reported — as `skipped_no_artist` in the
     * completion line, once per scan rather than once per album, so an untagged
     * library costs one log line and not thousands.
     */
    public function testUnknownArtistFilesAreReportedAsSkippedNotFailed(): void
    {
        $dir = $this->tempDir();
        $this->touchFile($dir, 'mystery-a.mp3');
        $this->touchFile($dir, 'mystery-b.mp3');

        $db = new MusicSchemaConnection();
        $logger = new LogWriteFailureLogger();
        // ffprobe returns nothing → filename fallback → artist null → 'Unknown Artist'.
        $scanner = new MusicLibraryScanner($db, $this->ffmpegReturning([]), $logger);

        $result = $scanner->scanDirectory($dir, null, 'lib-s96');

        $this->assertSame(0, $result->added);
        $this->assertSame(0, $result->failed, 'a policy skip is not an error and must not alarm items_failed');
        $this->assertSame(
            1,
            $logger->countMessages('Music directory scan complete with skipped files'),
            'but the operator must still be told: two files were read and none indexed',
        );
        // Two, not one: with no album tag either, the album title falls back to the
        // FILENAME, so each untagged file is its own album group. That is also why the
        // per-album line stays at `debug` and the operator-facing tally is the
        // once-per-scan `skipped_no_artist` in the summary above.
        $this->assertSame(2, $logger->countMessages('Skipping album with unknown artist'));
        // MED-2: a POLICY skip is a WARNING, never an error — nothing malfunctioned and
        // a rescan discards the same files again. Routing an untagged library into
        // `.logs/error.log` would train the operator to ignore that file.
        $this->assertSame(
            1,
            $logger->countAtLevel('warning', 'Music directory scan complete with skipped files'),
            'a policy-only skip must summarise at WARNING',
        );
        $this->assertSame([], array_filter(
            $logger->records,
            static fn (array $r): bool => $r['level'] === 'error',
        ), 'nothing failed, so this scan must emit no error-level record at all');
    }

    /**
     * MED-2 (review r1): log LEVEL must track how much data was lost.
     *
     * The inversion this pins shut: losing a WHOLE ALBUM logged at `warning` while
     * losing ONE FILE logged at `error`, and `config/logger.php:45-50` gates
     * `.logs/error.log` at `error` — so the quietest signal got a clean dedicated file
     * and the three loudest ones were buried in `app.log` alongside every per-entity
     * `debug` line of the same scan. "Make a music scan debuggable" is the whole point
     * of S96, and the level assignment worked against it.
     *
     * All four loss paths are asserted at `error`, on the actual failure shape each one
     * needs (`returnFalseFor()` for the two "upsert returned null" branches, a throwing
     * fault for the outer catch and the per-track catch), plus the summary line that an
     * operator greps FIRST.
     */
    public function testEveryPathThatLosesFilesLogsAtErrorLevel(): void
    {
        // (1) Artist row cannot be written → the whole 5-file album is lost.
        [$dirA, $taggerA] = $this->oneAlbumFixture(5);
        $dbA = new MusicSchemaConnection();
        $dbA->returnFalseFor('INSERT INTO music_artists');
        $loggerA = new LogWriteFailureLogger();
        $resultA = $this->taggedScanner($dbA, $taggerA, $loggerA)->scanDirectory($dirA, null, 'lib-s96');

        $this->assertSame(5, $resultA->failed, 'the fixture must actually land on the artist branch');
        $this->assertSame(
            1,
            $loggerA->countAtLevel('error', 'Failed to upsert artist'),
            'losing an entire album must log at ERROR so it reaches .logs/error.log — at `warning` it '
            . 'was buried in app.log while a ONE-FILE loss got the clean file to itself',
        );
        $this->assertSame(0, $loggerA->countAtLevel('warning', 'Failed to upsert artist'));
        $this->assertSame(
            1,
            $loggerA->countAtLevel('error', 'Music directory scan complete with skipped files'),
            'and so must the summary line, which is the one an operator greps first',
        );

        // (2) Album row cannot be written → same, one level down the hierarchy.
        [$dirB, $taggerB] = $this->oneAlbumFixture(4);
        $dbB = new MusicSchemaConnection();
        $dbB->returnFalseFor('INSERT INTO music_albums');
        $loggerB = new LogWriteFailureLogger();
        $resultB = $this->taggedScanner($dbB, $taggerB, $loggerB)->scanDirectory($dirB, null, 'lib-s96');

        $this->assertSame(4, $resultB->failed);
        $this->assertSame(1, $loggerB->countAtLevel('error', 'Failed to upsert album'));
        $this->assertSame(0, $loggerB->countAtLevel('warning', 'Failed to upsert album'));

        // (3) One track throws → one file lost. Already ERROR before MED-2; it is the
        //     REFERENCE level the two branches above now match, so it must not drift.
        [$dirC, $taggerC] = $this->oneAlbumFixture(4);
        $dbC = new MusicSchemaConnection();
        $dbC->faultOnNth('INSERT INTO music_tracks', 2);
        $loggerC = new LogWriteFailureLogger();
        $resultC = $this->taggedScanner($dbC, $taggerC, $loggerC)->scanDirectory($dirC, null, 'lib-s96');

        $this->assertSame(1, $resultC->failed);
        $this->assertSame(1, $loggerC->countAtLevel('error', 'Skipping track after error during indexing'));
        $this->assertSame(1, $loggerC->countAtLevel('error', 'Music directory scan complete with skipped files'));

        // (4) The outer catch (the artist INSERT throws rather than returning false).
        [$dirD, $taggerD] = $this->oneAlbumFixture(3);
        $dbD = new MusicSchemaConnection();
        $dbD->faultOnNth('INSERT INTO music_artists', 1);
        $loggerD = new LogWriteFailureLogger();
        $resultD = $this->taggedScanner($dbD, $taggerD, $loggerD)->scanDirectory($dirD, null, 'lib-s96');

        $this->assertSame(3, $resultD->failed);
        $this->assertSame(1, $loggerD->countAtLevel('error', 'Skipping album after error during indexing'));
    }

    // -- review r3 MED-1: the insert-result contract, arm by arm ----------------

    /**
     * **`statementWroteNothing()` must be pinned on EACH of its arms separately, and `'0'`
     * must be a SUCCESS.**
     *
     * Review r3 measured the gap this closes: deleting `|| $result === null` from the
     * helper left `MusicLibraryScannerTest` + `tests/Unit/Media/Library/` +
     * `LibraryScanCommandTest` + `tests/Integration/Media/` at
     * `OK (1113 tests, 10122 assertions)` — **byte-identical to the unmutated baseline**.
     * The reason was in the doubles, not the production code: the only way any test made
     * an INSERT report "wrote nothing" was `MusicSchemaConnection::returnFalseFor()`, which
     * returns literal `false`, while `runInsert()` returned `int 1`. So the suite exercised
     * only the arm the measured contract says this client NEVER produces, and none of the
     * arm the whole r2 HIGH finding turns on.
     *
     * The measured contract (real MySQL 8.0.46 via `PhlixMySQLConnection`), one row per
     * assertion below:
     *
     * | statement outcome                  | `query()` returns        | wroteNothing |
     * |---|---|---|
     * | wrote 0 rows (`INSERT IGNORE`, …)  | `null`                   | **true**     |
     * | a client that reports failure so   | `false`                  | **true**     |
     * | wrote 1 row into `media_items`     | `'0'` (UUID PK, no AI)   | **false**    |
     * | wrote 1 row into an AI table       | `'42'`                   | **false**    |
     * | any real SQL error                 | THROWS                   | n/a          |
     *
     * Reflection, deliberately: the helper is `private static`, and the point is to assert
     * the two falsy arms INDEPENDENTLY — an end-to-end scenario can only ever drive one
     * value at a time, which is how the `null` arm went unpinned in the first place. The
     * production consequence is pinned separately by
     * {@see self::testAnInsertThatReturnsNullIsChargedAsALossOnEveryPath()}.
     */
    public function testTheInsertResultContractIsPinnedOnBothOfItsFalsyArms(): void
    {
        $wroteNothing = new \ReflectionMethod(MusicLibraryScanner::class, 'statementWroteNothing');

        $this->assertTrue(
            $wroteNothing->invoke(null, null),
            'NULL is the ONLY falsy value this client returns for an INSERT that wrote nothing. '
            . 'Dropping this arm is invisible to every other test in the repo (measured), and it '
            . 'restores the r2 HIGH defect: a lost file reported as a clean success',
        );
        $this->assertTrue(
            $wroteNothing->invoke(null, false),
            'and the `false` arm stays — a bare `createMock(Connection::class)` returns null, but a '
            . 'different client (or a double, as r2 S3/S4 model) can report failure this way',
        );
        $this->assertFalse(
            $wroteNothing->invoke(null, '0'),
            "'0' is a SUCCESSFUL media_items insert (CHAR(36) UUID primary key, no AUTO_INCREMENT, "
            . 'so lastInsertId() is 0) and it is FALSY in PHP. True here means the helper was '
            . '"simplified" to !$result, which reports every healthy mint as a failure',
        );
        $this->assertFalse(
            $wroteNothing->invoke(null, '42'),
            'and an AUTO_INCREMENT table returns its id as a STRING, not an int',
        );
    }

    /**
     * The production consequence of the `null` arm, on every one of the four INSERTs whose
     * result the scanner branches on.
     *
     * Each scenario arms the NEW `returnNullFor()` — the value the measured contract says
     * the client really returns for "wrote nothing" — and asserts that the files are
     * CHARGED and the rows are genuinely absent. Without the `null` arm every one of these
     * reports a clean success over an empty table.
     */
    public function testAnInsertThatReturnsNullIsChargedAsALossOnEveryPath(): void
    {
        // (1) Every track INSERT writes nothing. The media_items row is minted, so this is
        //     the shape where the DB looks self-consistent and the library is empty.
        [$dirA, $taggerA] = $this->oneAlbumFixture(5);
        $dbA = new MusicSchemaConnection();
        $dbA->returnNullFor('INSERT INTO music_tracks');
        $logA = new LogWriteFailureLogger();
        $resultA = $this->taggedScanner($dbA, $taggerA, $logA)->scanDirectory($dirA, null, 'lib-s96');

        $this->assertSame(0, $resultA->added);
        $this->assertCount(0, $dbA->tracks, 'the fixture must really lose every file');
        $this->assertSame(
            5,
            $resultA->failed,
            'all five files must be charged. 0 here means the `null` arm is gone and a total loss is '
            . 'being reported as a clean scan — through ScanResult, library_scan_jobs.items_failed '
            . 'and `library:scan`\'s exit status alike',
        );
        $this->assertSame(5, $logA->countAtLevel('error', 'Track was not indexed'));

        // (2) The artist INSERT writes nothing → upsertArtist() returns null and the whole
        //     album is charged, at ERROR.
        [$dirB, $taggerB] = $this->oneAlbumFixture(4);
        $dbB = new MusicSchemaConnection();
        $dbB->returnNullFor('INSERT INTO music_artists');
        $logB = new LogWriteFailureLogger();
        $resultB = $this->taggedScanner($dbB, $taggerB, $logB)->scanDirectory($dirB, null, 'lib-s96');

        $this->assertSame(4, $resultB->failed, 'losing the artist row loses the album, so all four files');
        $this->assertCount(0, $dbB->tracks);
        $this->assertSame(1, $logB->countAtLevel('error', 'Failed to upsert artist'));

        // (3) The album INSERT writes nothing → same, one level down.
        [$dirC, $taggerC] = $this->oneAlbumFixture(3);
        $dbC = new MusicSchemaConnection();
        $dbC->returnNullFor('INSERT INTO music_albums');
        $logC = new LogWriteFailureLogger();
        $resultC = $this->taggedScanner($dbC, $taggerC, $logC)->scanDirectory($dirC, null, 'lib-s96');

        $this->assertSame(3, $resultC->failed);
        $this->assertCount(0, $dbC->tracks);
        $this->assertSame(1, $logC->countAtLevel('error', 'Failed to upsert album'));

        // (4) The media_items INSERT writes nothing → createMediaItem() must report ''.
        //     This is the site whose docblock carries the `'0'`-is-falsy warning, so it is
        //     the one where a "simplification" to !$result and a missing `null` arm are
        //     each other's exact inverse.
        [$dirD, $taggerD] = $this->oneAlbumFixture(5);
        $dbD = new MusicSchemaConnection();
        $dbD->returnNullFor('INSERT INTO media_items');
        $logD = new LogWriteFailureLogger();
        $resultD = $this->taggedScanner($dbD, $taggerD, $logD)->scanDirectory($dirD, null, 'lib-s96');

        $this->assertSame(0, $resultD->added);
        $this->assertSame(5, $resultD->failed, 'no media_items row can be minted, so no file can be indexed');
        $this->assertCount(0, $dbD->mediaItems, 'and nothing was written');
        // 1 artist + 1 album + 5 tracks = 7 mints attempted, every one of them reported.
        $this->assertSame(7, $logD->countAtLevel('error', 'Failed to create media_item'));
    }

    // -- S96(b): the live counter snapshot on the progress sink ----------------

    /**
     * The progress sink receives the live `added`/`updated`/`failed` snapshot as its
     * 4th argument, and it MOVES during the walk.
     *
     * This is what makes `library_scan_jobs.items_added` answer "is this scan writing
     * anything?" instead of reading 0 for four hours. Without it the only way to tell
     * was to compare `music_artists.created_at` timestamps against the walk order —
     * which is literally how the production investigation had to proceed.
     */
    public function testTheProgressSinkCarriesTheLiveCounterSnapshot(): void
    {
        // 40 albums x 2 tracks: the 33rd album evicts at file 65, so writes begin
        // long before the walk ends and the snapshot must be non-zero by then.
        [$dir, $total] = $this->buildAlbumTree(40, 2);
        $this->assertSame(80, $total);

        $db = new MusicSchemaConnection();
        $scanner = $this->taggedScanner($db);

        $snapshots = [];
        $scanner->scanDirectory(
            $dir,
            static function (int $processed, int $totalFiles, string $path, array $counts = []) use (&$snapshots): void {
                $snapshots[$processed] = $counts;
            },
            'lib-s96',
        );

        $this->assertCount($total, $snapshots, 'one snapshot per file, exactly as before');

        $first = $snapshots[1] ?? null;
        $this->assertIsArray($first);
        $this->assertSame(
            ['added' => 0, 'updated' => 0, 'failed' => 0],
            $first,
            'the snapshot must carry all three counters, zeroed, from the very first tick',
        );

        $last = $snapshots[$total] ?? null;
        $this->assertIsArray($last);
        $this->assertGreaterThan(
            0,
            $last['added'],
            'by the LAST file the scan has already written rows, so a live counter must be non-zero. '
            . '0 here means items_added stays 0 for the whole walk — the S96(b) defect',
        );
        $this->assertSame(0, $last['failed']);
        // Monotonic: a counter that went backwards would make the job row lie.
        $previous = 0;
        foreach ($snapshots as $counts) {
            $this->assertGreaterThanOrEqual($previous, $counts['added']);
            $previous = $counts['added'];
        }
    }

    // -- S96(e): a NULL media_item_id is healed, not kept forever --------------

    /**
     * A `music_artists` row whose `media_item_id` is NULL must be BACKFILLED when the
     * scanner meets that artist again.
     *
     * How the NULL happens: `createMediaItem()` swallows its own Throwable and returns
     * `''`, so ONE transient failure writes the `music_artists` row with a NULL link.
     * Before this fix nothing ever repaired it — the natural-key branch returned the
     * stored NULL and short-circuited before the adoption lookup, so the S95 reviewer
     * measured it still NULL after two clean rescans. `NULL UNIQUE` (migration 065)
     * means nothing fails loudly either; the artist just stays artwork-less and
     * invisible to every `media_items`-driven surface.
     */
    public function testANullArtistMediaItemIdIsBackfilledWhenTheArtistRecurs(): void
    {
        $root = $this->tempDir();
        foreach (['album-a', 'album-b'] as $sub) {
            mkdir($root . '/' . $sub, 0777, true);
            $this->cleanup[] = $root . '/' . $sub;
            $this->touchFile($root . '/' . $sub, '01-t.mp3');
        }

        $db = new MusicSchemaConnection();
        // The shape a previous scan left behind.
        $artistId = $db->plantArtistWithNullMediaItem('Poisoned Artist');

        $logger = new LogWriteFailureLogger();
        $scanner = $this->taggedScanner($db, static fn(string $path): array => [
            'artist' => 'Poisoned Artist',
            'album' => basename(dirname($path)),
            'title' => basename($path, '.mp3'),
            'track_number' => 1,
            'disc_number' => 1,
            'duration_secs' => 100,
            'year' => 2000,
            'genre' => null,
        ], $logger);

        $scanner->scanDirectory($root, null, 'lib-s96');

        $healed = $db->artists['poisoned artist']['media_item_id'] ?? null;
        $this->assertIsString(
            $healed,
            'the NULL media_item_id must be backfilled. NULL here is the S96(e) defect: every later scan '
            . 'finds this row by its natural key and returns the NULL without ever repairing it',
        );
        $this->assertNotSame('', $healed);
        $this->assertSame($artistId, $db->artists['poisoned artist']['id'], 'the SAME row was healed, not replaced');
        $this->assertContains($healed, $db->mediaItemIds('artist'), 'and the media_items row really exists');
        $this->assertSame([], $db->orphanedMusicMediaItems(), 'nothing was left unreferenced');
        $this->assertSame(1, $logger->countMessages('Backfilled a NULL media_item_id on a music row'));

        // The UPDATE must carry `AND media_item_id IS NULL`. Not cosmetic: the column is
        // UNIQUE (migration 065), so clobbering an id a concurrent writer had already
        // stored would leave two rows pointing at one media_item and fail some later
        // INSERT — losing a whole album. The guard also makes the backfill idempotent.
        $this->assertSame(
            1,
            $db->countStatements('UPDATE music_artists SET media_item_id = ? WHERE id = ? AND media_item_id IS NULL'),
            'the backfill UPDATE must be guarded on media_item_id IS NULL',
        );
    }

    /**
     * A backfill UPDATE that wrote nothing must NOT be reported as a heal — on BOTH of the
     * guard's arms, and the FIRST scenario is the one production reaches with the statement as
     * it is written today.
     *
     * Review r3 finding 4: `backfillMusicMediaItemId()`'s guard was
     * `$affected === false || (is_int($affected) && $affected < 1)` — written before the
     * insert-result contract was measured, and permissive in the wrong direction.
     * `null === false` is false and `is_int(null)` is false, so a `null` fell through to
     * `$referenced = true` and logged *"Backfilled a NULL media_item_id on a music row"* at
     * `info` for a row that is still NULL. That is the inverse of the r2 HIGH finding (a
     * success reported for work that did not happen) in the same file.
     *
     * ⚠ **Review r4: fix r3 pinned only the arm real MySQL CANNOT return here.** It armed
     * the double with `null`, which is the measured 0-row shape for an **INSERT** and for
     * nothing else — `Connection::query()` returns `rowCount()` for a statement whose
     * leading keyword is `update`, so this statement's "wrote nothing" is **`int 0`**
     * (measured r4: guard-excluded row → `int 0`, healable row → `int 1`, re-run → `int 0`,
     * matched-but-unchanged → `int 0`, bad column → THROWS). With only the `null` arm
     * pinned, deleting `|| (is_int($affected) && $affected < 1)` from the guard in
     * `backfillMusicMediaItemId()` left the FULL suite byte-identical to baseline, assertion
     * count included. Scenario (A) below is that pin; scenario (B) keeps the `null` arm.
     *
     * ⚠ **Review r5: scenario (B) is NOT purely hypothetical, and calling it "the arm the real
     * client cannot reach" was itself an over-claim.** `Connection.php:1854` splits the
     * statement with `explode(" ", …)`, so the real client returns `null` for an `UPDATE`
     * whose keyword is followed by anything but a single space — measured on real MySQL 8.0.46:
     * `"UPDATE\nmusic_artists SET …"`, `"UPDATE\t…"` and a leading block comment all → `null`.
     * Reformatting the `match` arms that build `$sql` into a heredoc is all it would take. So
     * (B) is defence-in-depth against a bare `createMock(Connection::class)` AND a live
     * reachable client answer, and both arms are load-bearing.
     */
    public function testABackfillUpdateThatWroteNothingIsNotReportedAsHealed(): void
    {
        // (A) THE PRODUCTION-REACHABLE ARM. The client reports "wrote nothing" for an
        //     UPDATE as an affected-row count of 0 — measured, not assumed — which reaches
        //     the guard's `is_int($affected) && $affected < 1` half and nothing else.
        [$dir, $tagger] = $this->oneAlbumFixture(1, 'Zero Rows Artist', 'Some Album');

        $db = new MusicSchemaConnection();
        $db->plantArtistWithNullMediaItem('Zero Rows Artist');
        $db->returnAffectedRowsFor('UPDATE music_artists SET media_item_id', 0);

        $logger = new LogWriteFailureLogger();
        $this->taggedScanner($db, $tagger, $logger)->scanDirectory($dir, null, 'lib-s96');

        $this->assertNull(
            $db->artists['zero rows artist']['media_item_id'],
            'the row really is still NULL — the UPDATE matched 0 rows',
        );
        $this->assertSame(
            0,
            $logger->countMessages('Backfilled a NULL media_item_id on a music row'),
            'so nothing may claim it was healed. 1 here means the `is_int($affected) && $affected < 1` '
            . 'half of the guard is gone — the half a real UPDATE trips AS THE STATEMENT IS WRITTEN '
            . 'TODAY (r5: `null` becomes reachable the moment it is reformatted, because '
            . 'Connection.php:1854 splits on spaces only) — and every row the `AND media_item_id IS '
            . 'NULL` predicate excludes now gets a false `info` heal line',
        );

        // (B) THE OTHER ARM. `null` is not what the client returns for this statement AS
        //     WRITTEN (see returnNullFor()'s per-keyword table), but it is NOT unreachable
        //     either (r5): a bare `createMock(Connection::class)` returns it for every method,
        //     `Connection.php:1866` returns it for any unrecognised leading keyword, and
        //     "unrecognised" includes an `UPDATE` reformatted so the keyword is not followed
        //     by a single space — measured on real MySQL. So `statementWroteNothing()` must
        //     catch it here, and is not dead code.
        [$dirB, $taggerB] = $this->oneAlbumFixture(1, 'Null Update Artist', 'Some Album');

        $dbB = new MusicSchemaConnection();
        $dbB->plantArtistWithNullMediaItem('Null Update Artist');
        $dbB->returnNullFor('UPDATE music_artists SET media_item_id');

        $loggerB = new LogWriteFailureLogger();
        $this->taggedScanner($dbB, $taggerB, $loggerB)->scanDirectory($dirB, null, 'lib-s96');

        $this->assertNull(
            $dbB->artists['null update artist']['media_item_id'],
            'the row really is still NULL — the UPDATE wrote nothing',
        );
        $this->assertSame(
            0,
            $loggerB->countMessages('Backfilled a NULL media_item_id on a music row'),
            'and a `null` from a bare mock must not be read as a heal either — this is the arm '
            . '`statementWroteNothing()` contributes at this site',
        );
    }

    /**
     * The double's `query()` must return what the REAL client returns FOR THAT KEYWORD.
     *
     * Review r4's finding was not a production defect — it was a double that modelled the
     * INSERT contract on an UPDATE, so the arm production really takes was pinned by
     * nothing. That is the third occurrence of the same class in this step (r3 MED-1: the
     * double could only produce `false`; fix r3: the doubles learned `null` — then armed
     * `null` on an UPDATE too), and the reason it keeps recurring is that the return domain
     * is a property of the STATEMENT KEYWORD, not of the client. So the mapping is asserted
     * directly, per keyword, rather than left to the next reader to infer:
     *
     * | keyword | `Connection::query()` (`vendor/workerman/mysql/src/Connection.php:1854-1869`) |
     * |---|---|
     * | `select`/`show` | `fetchAll()` → a `list` |
     * | `insert` | `lastInsertId()` as a STRING, or `null` when `rowCount() === 0` |
     * | `update`/`delete`/`replace` | `rowCount()` → an `int` |
     * | anything else | `null` |
     *
     * All four rows were measured against real MySQL 8.0.46 through `PhlixMySQLConnection`
     * (reviews r3/r4/r5). ⚠ **EVERY ROW OF THAT TABLE IS ASSERTED HERE, not just the three
     * keywords this file happens to issue (review r5 LOW-1).** Fix r4 asserted three and cited
     * this test as pinning the whole table — and `SHOW` was in fact WRONG in the double
     * (`int 1`, because the dispatch had no `show` arm and fell through to `runUpdate()`) while
     * the client returns an array. A signpost that claims more than it checks is what let this
     * defect class recur three times, so the claim and the assertions are now the same size.
     *
     * The last assertions are the WHITESPACE family, and they exist because
     * `Connection.php:1854` splits with **`explode(" ", …)`** and `:1856` then `trim()`s the
     * token it took. BOTH halves of that derivation are observable, so both are asserted here:
     * an `UPDATE` whose keyword is followed by a newline, followed by a bare tab, or preceded
     * by a block comment is NOT recognised and the client returns **`null`** (measured on real
     * MySQL at r5) — which is precisely why both halves of the backfill guard are required and
     * why `statementWroteNothing()` must not be deleted there as dead code — while an `UPDATE`
     * whose keyword is followed by a **tab THEN a space** IS recognised, because `:1856` strips
     * the tab off the token it split out. Review r6 measured that last row as the one nothing
     * pinned: dropping the inner `trim()` from {@see MusicSchemaConnection::keywordOf()} left
     * this whole file GREEN while the double answered `null` for a statement whose real client
     * answer is an `int`. See `keywordOf()`'s table for which rows are asserted and which one
     * is unreachable through `query()` at all.
     */
    public function testTheSchemaDoubleModelsTheClientsPerKeywordReturnDomain(): void
    {
        $db = new MusicSchemaConnection();

        $this->assertIsArray(
            $db->query('SELECT id FROM music_artists WHERE name = ?', ['nobody']),
            'a SELECT returns fetchAll() — a list, empty when nothing matched, never null',
        );
        $this->assertIsArray(
            $db->query('SHOW TABLES'),
            'a SHOW shares the SELECT arm (Connection.php:1857) and returns a list too. The double '
            . 'returned int 1 here until review r5 — there was no `show` arm at all, so it fell '
            . 'through to runUpdate()',
        );
        $this->assertIsString(
            $db->query(
                'INSERT INTO music_artists (name, media_item_id, created_at) VALUES (?, ?, NOW())',
                ['Keyword Contract Artist', null],
            ),
            'a 1-row INSERT returns lastInsertId() as a STRING (int 1 was fix-r3 finding 1)',
        );
        $this->assertIsInt(
            $db->query('UPDATE music_artists SET media_item_id = ? WHERE id = ? AND media_item_id IS NULL', [
                'mi-keyword-contract',
                1,
            ]),
            'an UPDATE returns rowCount() — an INT. This is the row fix r3 got wrong: it armed the '
            . 'INSERT contract (null) on an UPDATE, so the int-0 arm production really takes was '
            . 'exercised by nothing in the repo',
        );
        $this->assertIsInt(
            $db->query('DELETE FROM music_tracks WHERE id = ?', [1]),
            'a DELETE shares that same rowCount() arm (Connection.php:1859). Unasserted until r5, '
            . 'which proved the hole: regressing DELETE to the INSERT contract (null) left this '
            . 'whole file GREEN',
        );
        $this->assertIsInt(
            $db->query('REPLACE INTO music_tracks (media_item_id) VALUES (?)', ['mi-keyword-contract']),
            'and so does a REPLACE — the third keyword on that arm, and the one whose needle also '
            . 'contains INTO, so it must not be mistaken for an INSERT',
        );
        $this->assertNull(
            $db->query('TRUNCATE TABLE music_artists'),
            'anything else returns null (Connection.php:1866) — indistinguishable from a no-op, '
            . 'which is why production must treat null as "wrote nothing"',
        );
        $this->assertNull(
            $db->query("UPDATE\nmusic_artists SET media_item_id = ? WHERE id = ?", ['mi-x', 1]),
            'and THIS is the "anything else" row that can bite an UPDATE: Connection.php:1854 splits '
            . 'the statement with explode(" "), so a keyword followed by a newline is never '
            . 'recognised and the client returns null (measured on real MySQL 8.0.46 at r5). '
            . 'Reformatting the backfill match arms into a heredoc would move that site from the int '
            . 'arm to the null arm — so statementWroteNothing() is NOT dead code there',
        );
        $this->assertNull(
            $db->query("UPDATE\tmusic_artists SET media_item_id = ? WHERE id = ?", ['mi-x', 1]),
            'a TAB with NO following space loses the keyword the same way: explode(" ") yields the '
            . 'single token "UPDATE\tmusic_artists", which trim() cannot shorten. Review r6 measured '
            . 'this row as documented-but-unasserted — a mutation making \t a separator left the file '
            . 'GREEN',
        );
        $this->assertIsInt(
            $db->query("UPDATE\t music_artists SET media_item_id = ? WHERE id = ?", ['mi-x', 1]),
            'but a TAB followed by a SPACE is still an UPDATE: explode(" ") yields "UPDATE\t" and '
            . 'Connection.php:1856 trim()s that token before lowercasing it, so the client returns '
            . 'rowCount() — an INT. This is the ONLY assertion that pins keywordOf()\'s inner trim(): '
            . 'review r6 dropped it and the whole file stayed GREEN while the double answered null for '
            . 'a statement the real client reports an int for',
        );
        $this->assertNull(
            $db->query('/* hint */ UPDATE music_artists SET media_item_id = ? WHERE id = ?', ['mi-x', 1]),
            'and a leading block comment makes the first space-delimited token "/*", so the client '
            . 'returns null for that too — the third whitespace row review r6 found documented but '
            . 'unasserted (stripping a leading comment in keywordOf() left the file GREEN)',
        );

        $armed = new MusicSchemaConnection();
        $armed->returnAffectedRowsFor('UPDATE music_artists SET media_item_id', 0);
        $this->assertSame(
            0,
            $armed->query('UPDATE music_artists SET media_item_id = ? WHERE id = ?', ['mi-x', 1]),
            'and the armed "wrote nothing" value for an UPDATE is int 0, not null',
        );
    }

    /**
     * The double REFUSES to hand back an affected-row count for a statement whose keyword
     * cannot report one — **at dispatch, where the statement is visible**.
     *
     * ⚠ **THE ARM-TIME REFUSAL r4 AND r5 BUILT IN `returnAffectedRowsFor()` IS GONE, ON
     * PURPOSE (review r6 LOW-1).** Its three-round history IS the argument for deleting it
     * rather than patching it a fourth time:
     *
     * - **r4** tokenised the needle's first word and declared the inverse defect "no longer
     *   expressible". **r5 measured FOUR bypasses**, one of which armed an `INSERT` with
     *   `int 0` — a shape the client cannot produce — with no throw at all.
     * - **r5** replaced it with two `str_contains()` checks on the needle. **r6 measured the
     *   OTHER direction:** `'SET a.total_tracks = (SELECT COUNT(*)'` — lifted VERBATIM from
     *   {@see \Phlix\Media\Music\MusicLibraryScanner::refreshAlbumTrackTotal()}, an `UPDATE`
     *   whose real client answer is an `int` — was REFUSED because of its `(SELECT COUNT(*)`
     *   **subquery**, and so were `'UPDATE settings SET selected'` (a column name) and
     *   `'DELETE FROM tv_shows'` (a table name). All three armed fine before r5.
     * - Worse than the false refusal: its message said *"Use returnNullFor()… instead"*, i.e.
     *   it told the next reader to model **`null` for an UPDATE** — precisely the r3/r4 defect
     *   this whole thread exists to prevent. **A guard whose failure message recommends the
     *   bug is worse than no guard.**
     *
     * The cause is structural, not a bad predicate: **a needle is a substring, and a substring
     * cannot be keyword-classified.** The driver classifies the *statement*
     * (`Connection.php:1854-1856`), so that is the only place the question has an answer — and
     * for this arm the answer there is TOTAL rather than heuristic: `returnAffectedRowsFor()`
     * can only arm an `int`, and an `int` is faithful for `update`/`delete`/`replace` and for
     * nothing else, so ANY arm of it that fires on a statement of any other keyword is thrown
     * out by {@see MusicSchemaConnection::keywordFaithful()}, however the needle was spelled.
     * An arm that never matches a statement returns nothing and so needs no refusal. One rule,
     * checked where the truth is known.
     *
     * Every needle either round measured is therefore re-checked HERE, at dispatch: r4's
     * caught set, all four r5 bypasses (incl. the dangerous `'INTO media_items'`), the r5
     * residue `'INTO metadata_updates'`, and a `TRUNCATE` that drives `keywordFaithful()`'s
     * `default` arm — which review r6 found was never evaluated in the green suite (LOW-3).
     * Then the six needles that MUST arm, checked for their **effect** at dispatch and not
     * merely for the absence of a throw.
     *
     * No assertion sits inside any `try` — every outcome is captured into a variable and
     * asserted afterwards, because `ExpectationFailedException` is itself a `RuntimeException`
     * and would be swallowed by a `catch` that is looking for the double's own throw.
     */
    public function testTheDoubleRefusesAnAffectedRowCountForAKeywordThatCannotReportOne(): void
    {
        // needle => [statement it matches, the reason the message must give].
        // The reasons are deliberately DISTINGUISHABLE per keyword: naming the real return
        // is the whole point, and identical fixtures are what left this unpinned before.
        $cannotReport = [
            // r4's caught set — the leading keyword, in either case.
            'INSERT INTO music_tracks' => [
                'INSERT INTO music_tracks (media_item_id) VALUES (?)',
                'keyword is "insert", and the real client returns string for it',
            ],
            'insert into music_tracks' => [
                'insert into music_tracks (media_item_id) VALUES (?)',
                'keyword is "insert", and the real client returns string for it',
            ],
            'SELECT id' => [
                'SELECT id FROM music_artists WHERE name = ?',
                'keyword is "select", and the real client returns array for it',
            ],
            'show tables' => [
                'show tables',
                'keyword is "show", and the real client returns array for it',
            ],
            // The four r5 BYPASSES of the arm-time check. The first is the dangerous one: it
            // armed an INSERT with int 0 and the suite stayed green.
            'INTO media_items' => [
                'INSERT INTO media_items (id, library_id, type, name, path) VALUES (?, ?, ?, ?, ?)',
                'keyword is "insert", and the real client returns string for it',
            ],
            '/* hint */ INSERT INTO music_tracks' => [
                '/* hint */ INSERT INTO music_tracks (media_item_id) VALUES (?)',
                'keyword is "/*", and the real client returns null for it',
            ],
            "INSERT\r\nINTO music_tracks" => [
                "INSERT\r\nINTO music_tracks (media_item_id) VALUES (?)",
                "keyword is \"insert\r\ninto\", and the real client returns null for it",
            ],
            'WITH c AS (SELECT 1) SELECT * FROM c' => [
                'WITH c AS (SELECT 1) SELECT * FROM c',
                'keyword is "with", and the real client returns null for it',
            ],
            // review r6 LOW-3: keywordFaithful()'s `default => 'null'` arm was never
            // evaluated in the green suite (`default => 'int'` was a GREEN mutation), so the
            // claim "for any other keyword nothing but the sentinels" was unpinned. These
            // three `null`-permitted rows are what evaluate it.
            'TRUNCATE TABLE music_artists' => [
                'TRUNCATE TABLE music_artists',
                'keyword is "truncate", and the real client returns null for it',
            ],
            // The r5 residue: `update` inside an IDENTIFIER. Unarmable-by-inspection was
            // never achievable — this row is why, and why the check lives at dispatch.
            'INTO metadata_updates' => [
                'INSERT INTO metadata_updates (media_item_id) VALUES (?)',
                'keyword is "insert", and the real client returns string for it',
            ],
        ];

        $atDispatch = [];
        foreach ($cannotReport as $needle => [$statement, $reason]) {
            $db = new MusicSchemaConnection();
            $db->returnAffectedRowsFor((string) $needle, 4);
            try {
                $value = $db->query($statement, ['mi-x', 'mi-x', 'artist', 'n', '']);
                $atDispatch[(string) $needle] = 'RETURNED ' . get_debug_type($value) . ', NO THROW';
            } catch (\LogicException $e) {
                $atDispatch[(string) $needle] = $e->getMessage();
            }
            $this->assertStringContainsString(
                $reason,
                $atDispatch[(string) $needle],
                sprintf(
                    'arming an affected-row count on "%s" must be refused at dispatch, naming the '
                    . 'statement keyword AND what the real client returns for it. A needle cannot be '
                    . 'classified (r6 LOW-1: r5\'s arm-time check refused three legitimate needles, '
                    . 'one of them lifted from production) — the statement can',
                    addcslashes((string) $needle, "\r\n\t"),
                ),
            );
        }

        // A needle with leading whitespace can never match anything, because query() ltrim()s
        // the statement first — so it is INERT rather than dangerous, which is the strongest
        // possible outcome and the reason r4's '  insert into …' row needs no refusal at all.
        $inert = new MusicSchemaConnection();
        $inert->returnAffectedRowsFor('  insert into music_tracks', 4);
        $this->assertIsString(
            $inert->query('  insert into music_tracks (media_item_id) VALUES (?)', ['mi-x']),
            'a needle that cannot match a query() statement arms nothing, so the INSERT contract '
            . '(a string) is what comes back — an inert arm produces no unfaithful shape',
        );

        // needle => [statement, params]. Every one of these MUST arm and MUST take effect:
        // the armed value is 4, which no runUpdate() branch ever returns, so `4` proves the
        // arm fired rather than merely that nothing threw.
        $mustArm = [
            'UPDATE music_artists SET media_item_id' => [
                'UPDATE music_artists SET media_item_id = ? WHERE id = ? AND media_item_id IS NULL',
                ['mi-x', 1],
            ],
            'DELETE FROM music_tracks' => ['DELETE FROM music_tracks WHERE id = ?', [1]],
            'REPLACE INTO music_tracks' => [
                'REPLACE INTO music_tracks (media_item_id) VALUES (?)',
                ['mi-x'],
            ],
            // ⚠ review r6 LOW-1's THREE FALSE REFUSALS. The first is the scanner's own
            // production UPDATE (refreshAlbumTrackTotal(), MusicLibraryScanner) — the
            // `(SELECT COUNT(*)` subquery is what r5's str_contains('select') tripped over;
            // the other two carry `select`/`show` inside a column and a table name.
            'SET a.total_tracks = (SELECT COUNT(*)' => [
                "UPDATE music_albums a\n"
                . "    SET a.total_tracks = (SELECT COUNT(*) FROM music_tracks t WHERE t.album_id = a.id)\n"
                . '  WHERE a.id = ?',
                [1],
            ],
            'UPDATE settings SET selected' => [
                'UPDATE settings SET selected = ? WHERE id = ?',
                ['x', 1],
            ],
            'DELETE FROM tv_shows' => ['DELETE FROM tv_shows WHERE id = ?', [1]],
        ];

        foreach ($mustArm as $needle => [$statement, $params]) {
            $db = new MusicSchemaConnection();
            $db->returnAffectedRowsFor((string) $needle, 4);
            $this->assertSame(
                4,
                $db->query($statement, $params),
                sprintf(
                    'the needle "%s" must arm AND take effect. Review r6 measured r5\'s arm-time '
                    . 'refusal rejecting three of these six, including one drawn verbatim from the '
                    . 'scanner\'s own production UPDATE — and its message recommended returnNullFor(), '
                    . 'i.e. modelling null for an UPDATE, which is the r3/r4 defect itself',
                    $needle,
                ),
            );
        }
    }

    /**
     * `MusicSchemaConnection::query()` must keep funnelling EVERY return through the
     * keyword-fidelity check.
     *
     * Review r5's last defeat of the per-keyword pin was to add a NEW arming method with its
     * own dispatch loop and an early `return`, plus a test authored against it: `int 0` for an
     * `INSERT` — unproducible by the real client — and the suite stayed
     * `OK (51 tests, 429 assertions)`. An arm added inside `resolveQueryReturn()` is now
     * validated by `keywordFaithful()` automatically; the only remaining escape is an early
     * `return` in `query()` itself, which is what this test forbids.
     *
     * ⚠ **KNOWN LIMIT, recorded so round 6 does not over-trust this:** a test that defines its
     * OWN double class instead of using this one is outside anything either mechanism can see.
     * The `T_RETURN` count is deliberately token-based, so comments and docblocks mentioning
     * the word cannot affect it.
     *
     * ⚠ **SECOND KNOWN LIMIT — r7 LOW-1's and r8 finding 2's TWO structural preconditions, and
     * the reason for the six structural assertions below (every one but the `T_RETURN` count).**
     * {@see MusicSchemaConnection::keywordFaithful()} covers {@see
     * MusicSchemaConnection::returnAffectedRowsFor()} *completely* only while BOTH of these hold:
     * `$this->affectedOn` has exactly ONE reader, {@see
     * MusicSchemaConnection::resolveQueryReturn()}, and `resolveQueryReturn()` has exactly ONE
     * caller, {@see MusicSchemaConnection::query()}. The funnel can only see what flows through
     * `query()`. Review r7 MEASURED the first loss — add one public reader that loops
     * `affectedOn` itself and it hands an `int 0` back for a `SELECT` with nothing to stop it.
     * Review r8 MEASURED the second — a public wrapper that calls `resolveQueryReturn()` directly
     * skips `keywordFaithful()` entirely while adding **zero** new `affectedOn` references, and
     * `query()`'s single-`return` rule constrains `query()` only. Both preconditions were prose,
     * i.e. guards that had to be re-read to work; both are now **counted**.
     *
     * ⚠ **THREE EVASIONS REVIEW r8 MEASURED GREEN against the first version of this pin — a bare
     * "exactly two `->affectedOn` references" count — and how each is now CLOSED.** Every one of
     * them handed an `int 0` back for a `SELECT` with the count still reading exactly 2:
     * - **widen the declaration from `private` to `public` and read the store from a TEST body.**
     *   No new method at all: a counter scoped to the class's own line range cannot see a read
     *   outside the class, and this is the file's own house style — `$mediaItems`, `$artists` and
     *   `$albums` are already `public` and read directly from tests. **CLOSED** by the
     *   `isPrivate()` assertion: `private` makes an out-of-class read a fatal error, and it
     *   closes the obvious fourth evasion — a subclass — for the same reason, independently of
     *   the `final` keyword.
     * - **relocate the single read into a NEW method that `resolveQueryReturn()` delegates to.**
     *   A count sees the NUMBER of references, not their LOCATION, so the total stayed 2.
     *   **CLOSED** by also counting per-METHOD, which is what makes this test's claim about "the
     *   ONE read in `resolveQueryReturn()`" a pinned fact rather than an aspiration.
     * - **`get_object_vars($this)['affectedOn']`** — no `->` operator at all, so a counter keyed
     *   on `T_OBJECT_OPERATOR` was blind to it. **CLOSED** by counting the NAME in every
     *   spelling — `->affectedOn`, `$affectedOn` and any `'affectedOn'` string literal — which
     *   also closes r7's two disclosed residues, the dynamic access `$this->{'affectedOn'}` and
     *   an in-class reflection read.
     *
     * ⚠ **A residue r7 NAMED is measurably NOT one: a RENAME fails LOUD.** Review r8 measured a
     * rename of all three sites driving the bare count to 0 — *"Failed asserting that 0 is
     * identical to 2"*. Against THIS version it is louder still: the `isPrivate()` assertion runs
     * first, so a rename never reaches a count at all and the test ERRORS with *"ReflectionException:
     * Property …MusicSchemaConnection::$affectedOn does not exist"*. Either way it is RED, so the
     * rename is named here only to un-name it — listing it as a residue made the block look more
     * complete than it was.
     *
     * ⚠ **The residue that IS left, stated rather than implied:**
     * - a test that defines its **OWN double class** instead of using this one — as above,
     *   outside anything either mechanism can see;
     * - a name that is never written down literally: `$this->{'affected' . 'On'}`, or any
     *   reflection API handed such a computed value. Only a literal name is countable;
     * - a `ReflectionProperty` read from **OUTSIDE** the class, which no visibility keyword and
     *   no in-class token count can stop;
     * - **duplicating** `resolveQueryReturn()`'s body into a new method instead of calling it —
     *   the caller count sees calls, not copies;
     * - `nullOn`/`falseOn`, whose two sentinels `keywordFaithful()` exempts for every keyword on
     *   purpose. Nothing here constrains them.
     */
    public function testEveryQueryReturnFunnelsThroughTheKeywordFidelityCheck(): void
    {
        $method = new \ReflectionMethod(MusicSchemaConnection::class, 'query');
        $file = (string) $method->getFileName();
        $lines = (array) file($file);
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine()
            - $method->getStartLine() + 1));

        $returns = 0;
        foreach (token_get_all('<?php ' . $body) as $token) {
            if (is_array($token) && $token[0] === T_RETURN) {
                $returns++;
            }
        }

        $this->assertSame(
            1,
            $returns,
            'query() must have exactly ONE return — `return $this->keywordFaithful($sql, '
            . '$this->resolveQueryReturn($sql, $p));` — so that no arm can hand a value back '
            . 'without its shape being checked against the statement keyword. Add new arms to '
            . 'resolveQueryReturn(), never to query().',
        );

        $this->assertTrue(
            (new \ReflectionProperty(MusicSchemaConnection::class, 'affectedOn'))->isPrivate(),
            '$affectedOn must stay PRIVATE. Review r8 defeated the reference counts below by '
            . 'widening it to public and reading the store from a test body — reads outside the '
            . 'class are invisible to a counter scoped to the class, and every sibling store on '
            . 'this double is public, so the instinct is the file\'s own house style. private is '
            . 'also what makes a subclass read impossible, independently of `final`.',
        );

        $this->assertSame(
            3,
            self::countNameTokens(MusicSchemaConnection::class, 'affectedOn'),
            'affectedOn must be NAMED exactly three times inside MusicSchemaConnection — the '
            . 'declaration, the write in returnAffectedRowsFor() and the ONE read in '
            . 'resolveQueryReturn() — because keywordFaithful() only sees values that flow '
            . 'through query(). Review r7 measured this: a second, non-funnelling reader of this '
            . 'store hands an int 0 back for a SELECT and every layer stays green. If you need '
            . 'the armed value somewhere else, route it through resolveQueryReturn() instead of '
            . 'reading the store again.',
        );

        $this->assertSame(
            1,
            self::countNameTokens(MusicSchemaConnection::class, 'affectedOn', 'returnAffectedRowsFor'),
            'the ONE write to affectedOn must stay inside returnAffectedRowsFor(), the arming '
            . 'method its docblock documents.',
        );

        $this->assertSame(
            1,
            self::countNameTokens(MusicSchemaConnection::class, 'affectedOn', 'resolveQueryReturn'),
            'the ONE read of affectedOn must stay INSIDE resolveQueryReturn(), the only method '
            . 'whose returns keywordFaithful() validates. A per-class count pins the NUMBER of '
            . 'references, not their LOCATION: review r8 held the total at 2 while moving the '
            . 'read into a new method that resolveQueryReturn() merely delegates to.',
        );

        $resolve = new \ReflectionMethod(MusicSchemaConnection::class, 'resolveQueryReturn');
        $this->assertTrue(
            $resolve->isPrivate(),
            'resolveQueryReturn() must stay PRIVATE, or a test can call it directly and bypass '
            . 'keywordFaithful() without adding a single reference inside this class.',
        );

        $this->assertSame(
            2,
            self::countNameTokens(MusicSchemaConnection::class, 'resolveQueryReturn'),
            'resolveQueryReturn() must be NAMED exactly twice inside MusicSchemaConnection — its '
            . 'own declaration and the ONE call from query(). The single-return rule above '
            . 'constrains query() ONLY: review r8 measured that a public wrapper calling '
            . 'resolveQueryReturn() directly skips keywordFaithful() entirely while leaving both '
            . 'the affectedOn count and query()\'s return count untouched. query() is the funnel '
            . '— route every new caller through it.',
        );
    }

    /**
     * Count every token inside one class's — or one of its methods' — own source range that
     * NAMES `$name`, in ANY spelling: `->name`, `$name`, or a `'name'` string literal.
     *
     * Token-based on purpose, for the same reason the `T_RETURN` count above is: a comment or
     * docblock naming the member must not be able to move the number, and it cannot, because a
     * whole comment is a single `T_COMMENT`/`T_DOC_COMMENT` token that matches none of the three
     * spellings. Scoped to the class's own line range so a same-named member on any of this
     * file's other classes cannot move it either — and to a single method's range when `$method`
     * is given, which is what pins WHERE a reference lives rather than only how many exist.
     *
     * **Spelling-agnostic because review r8 defeated a `T_OBJECT_OPERATOR`-keyed counter with
     * `get_object_vars($this)['affectedOn']`, which contains no `->` at all.** Counting the name
     * itself also covers `$this->{'affectedOn'}` and an in-class reflection read. The residue is
     * a name never written down literally (`'affected' . 'On'`), which is disclosed at the
     * caller.
     *
     * @param string      $class  Class whose source range is scanned.
     * @param string      $name   Property or method name, with no leading `$` and no `()`.
     * @param string|null $method Narrow the scan to this method's own line range.
     */
    private static function countNameTokens(string $class, string $name, ?string $method = null): int
    {
        $scope = $method === null
            ? new \ReflectionClass($class)
            : new \ReflectionMethod($class, $method);
        $lines = (array) file((string) $scope->getFileName());
        $body = implode('', array_slice(
            $lines,
            $scope->getStartLine() - 1,
            $scope->getEndLine() - $scope->getStartLine() + 1,
        ));

        $hits = 0;
        foreach (token_get_all('<?php ' . $body) as $token) {
            if (!is_array($token)) {
                continue;
            }

            $named = match ($token[0]) {
                T_STRING => $token[1] === $name,
                T_VARIABLE => $token[1] === '$' . $name,
                T_CONSTANT_ENCAPSED_STRING => trim($token[1], '\'"') === $name,
                default => false,
            };

            if ($named) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * The backfill ADOPTS an existing orphan rather than minting a rival.
     *
     * This is case (c) of `findAdoptableArtistMediaItemId()`'s residue list — an
     * orphan from a mint the server COMMITTED but `createMediaItem()` reported as
     * failed. It was unreclaimable BY CONSTRUCTION: the `music_artists` row holds the
     * natural key with a NULL link, so every later scan short-circuited before the
     * adoption lookup could run. Driving the lookup from INSIDE that branch is the
     * only route to it.
     */
    public function testTheBackfillAdoptsAnExistingOrphanInsteadOfMintingARival(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(1, 'Committed Orphan Artist', 'Some Album');

        $db = new MusicSchemaConnection();
        $orphanId = $db->plantOrphan('artist', 'Committed Orphan Artist', 'lib-s96');
        $db->plantArtistWithNullMediaItem('Committed Orphan Artist');

        $scanner = $this->taggedScanner($db, $tagger);
        $scanner->scanDirectory($dir, null, 'lib-s96');

        $this->assertSame(
            $orphanId,
            $db->artists['committed orphan artist']['media_item_id'] ?? null,
            'the committed-but-unreferenced media_items row must be adopted by the row that should own it',
        );
        $this->assertSame(
            [$orphanId],
            $db->mediaItemIds('artist'),
            'and NO second artist media_items row may be minted — that is the leak this closes',
        );
    }

    /**
     * The album twin: a `music_albums` row with a NULL `media_item_id` is healed on the
     * next encounter, through the same helper and the artist-scoped adoption predicate.
     */
    public function testANullAlbumMediaItemIdIsBackfilledOnTheNextScan(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(2, 'Album Backfill Artist', 'Poisoned Album');

        $db = new MusicSchemaConnection();
        $artistId = $db->plantArtistWithNullMediaItem('Album Backfill Artist');
        $albumId = $db->plantAlbumWithNullMediaItem($artistId, 'Poisoned Album');

        $scanner = $this->taggedScanner($db, $tagger);
        $scanner->scanDirectory($dir, null, 'lib-s96');

        $healed = $db->albums[$albumId]['media_item_id'] ?? null;
        $this->assertIsString($healed, 'the album row must be healed too, not only the artist row');
        $this->assertNotSame('', $healed);
        $this->assertContains($healed, $db->mediaItemIds('album'));
        $this->assertCount(1, $db->albums, 'the SAME album row was healed, not duplicated');
        $this->assertSame([], $db->orphanedMusicMediaItems());
    }

    /**
     * The album twin of {@see self::testTheBackfillAdoptsAnExistingOrphanInsteadOfMintingARival()}:
     * the ALBUM backfill adopts an existing orphan instead of minting a rival.
     *
     * ⚠ The artist twin's adoption closure is exercised (`count=2`); the album one measured
     * **`count=0`** at the S96 coverage pass, i.e. the whole `$mayAdopt ?
     * findAdoptableAlbumMediaItemId(...) : null` true branch inside `upsertAlbum()`'s
     * backfill call was dead in test. That is the arm that reclaims case (c) of the residue
     * list for albums, and with it unpinned a wrong argument order — the lookup is
     * ARTIST-SCOPED, unlike the artist one — or a plain `: null` would both stay GREEN.
     *
     * The distinguishing fixture detail: an orphaned album `media_items` row is planted, so
     * the one-per-scan gate answers YES and the closure's `$mayAdopt` branch is really the
     * one taken. {@see self::testANullAlbumMediaItemIdIsBackfilledOnTheNextScan()} is the
     * same shape with NO orphan (gate shut, the `: null` arm), so the two arms of that
     * ternary are pinned by two tests with different observable outcomes — a minted id there
     * against the ADOPTED id here.
     */
    public function testTheAlbumBackfillAdoptsAnExistingOrphanInsteadOfMintingARival(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(1, 'Album Adopt Artist', 'Committed Orphan Album');

        $db = new MusicSchemaConnection();
        $artistId = $db->plantArtistWithNullMediaItem('Album Adopt Artist');
        $albumId = $db->plantAlbumWithNullMediaItem($artistId, 'Committed Orphan Album');
        // Unreferenced album media_items row => the gate answers YES for this library.
        $orphanId = $db->plantOrphan('album', 'Committed Orphan Album', 'lib-s96');

        $this->taggedScanner($db, $tagger)->scanDirectory($dir, null, 'lib-s96');

        $this->assertGreaterThan(
            0,
            $db->countStatements('LEFT JOIN music_albums ma'),
            'the album backfill must ISSUE the artist-scoped adoption lookup — 0 here means the '
            . 'closure took its `: null` arm and no orphan can ever be reclaimed for an album',
        );
        $this->assertSame(
            $orphanId,
            $db->albums[$albumId]['media_item_id'] ?? null,
            'the committed-but-unreferenced ALBUM media_items row must be adopted by the '
            . 'music_albums row that should own it',
        );
        $this->assertSame(
            [$orphanId],
            $db->mediaItemIds('album'),
            'and NO second album media_items row may be minted — that is the leak this closes',
        );
        $this->assertSame([], $db->orphanedMusicMediaItems());
    }

    /**
     * A healthy library pays NOTHING for the backfill: it is reached only when a
     * stored `media_item_id` is genuinely NULL.
     *
     * Worth pinning because the adoption lookups this helper calls scan an unindexed
     * `media_items.name` — measured at 5.078 ms per artist, ~31 s over a first scan of
     * the production library — which is exactly why S95 put a one-per-scan gate in
     * front of them. A backfill that ran unconditionally would hand that cost back.
     */
    public function testTheBackfillIssuesNoStatementsWhenNoLinkIsNull(): void
    {
        [$dir, $tagger] = $this->oneAlbumFixture(2, 'Healthy Artist', 'Healthy Album');

        $db = new MusicSchemaConnection();
        $scanner = $this->taggedScanner($db, $tagger);
        $scanner->scanDirectory($dir, null, 'lib-s96');

        $this->assertSame(0, $db->countStatements('UPDATE music_artists SET media_item_id'));
        $this->assertSame(0, $db->countStatements('UPDATE music_albums SET media_item_id'));
    }

    /**
     * When the backfill's OWN mint reports failure it must (i) report nothing healed and
     * (ii) re-open the one-per-scan adoption gate for the rest of the scan.
     *
     * ⚠ **The S96 coverage pass measured this block at `count=0`** — the two lines
     * `$mayAdopt = true; return null;` inside `backfillMusicMediaItemId()` were executed by
     * no test, while the `if ($mediaItemId === '')` above them ran 6 times. So both halves
     * were GREEN mutations, and each loses something real:
     *
     * - drop the `return null` and the method falls through to
     *   `UPDATE music_artists SET media_item_id = ?` with the EMPTY STRING, i.e. it writes a
     *   bogus link over a NULL and logs *"Backfilled a NULL media_item_id"* for a row that
     *   now points at no `media_items` row at all — strictly worse than the NULL S96(e)
     *   exists to repair;
     * - drop the `$mayAdopt = true` and the gate stays shut for the rest of the scan, so the
     *   `media_items` row this mint MAY have committed (`createMediaItem()` swallows its own
     *   Throwable and cannot tell) is unreclaimable — the exact "orphaned forever" shape.
     *
     * Both are asserted against a CONTROL run of the same fixture with no fault, because a
     * one-sided assertion on "the gate is open" cannot tell an opened gate from one that was
     * never shut. The control also proves the fixture's gate really starts shut.
     */
    public function testAFailedMintInsideTheBackfillHealsNothingAndReOpensAdoption(): void
    {
        // CONTROL: same fixture, no fault. The gate is shut (no orphan exists), the
        // backfill mints successfully, and no album adoption lookup is ever issued.
        [$cleanDir, $cleanTagger] = $this->oneAlbumFixture(1, 'Control Artist', 'Control Album');
        $cleanDb = new MusicSchemaConnection();
        $cleanDb->plantArtistWithNullMediaItem('Control Artist');
        $this->taggedScanner($cleanDb, $cleanTagger)->scanDirectory($cleanDir, null, 'lib-s96');

        $this->assertSame(
            1,
            $cleanDb->countStatements('LEFT JOIN music_artists ar'),
            'precondition: the one-per-scan gate is asked exactly once',
        );
        $this->assertSame(
            0,
            $cleanDb->countStatements('LEFT JOIN music_albums ma'),
            'precondition: with no orphan planted the gate answers NO, so the per-album adoption '
            . 'lookup must NOT run — this is what makes the faulted run below falsifiable',
        );
        $this->assertIsString(
            $cleanDb->artists['control artist']['media_item_id'] ?? null,
            'precondition: an unfaulted backfill really does heal the row',
        );

        // THE SHAPE: the backfill's own createMediaItem() fails. The artist row is found by
        // its natural key with a NULL link, so the FIRST `INSERT INTO media_items` of the
        // whole scan is the one the backfill issues.
        [$dir, $tagger] = $this->oneAlbumFixture(1, 'Unminted Artist', 'Unminted Album');
        $db = new MusicSchemaConnection();
        $db->plantArtistWithNullMediaItem('Unminted Artist');
        $db->faultOnNth('INSERT INTO media_items', 1);

        $logger = new LogWriteFailureLogger();
        $this->taggedScanner($db, $tagger, $logger)->scanDirectory($dir, null, 'lib-s96');

        // (i) NOTHING healed: no UPDATE issued, the link is still NULL, no heal logged.
        $this->assertSame(
            0,
            $db->countStatements('UPDATE music_artists SET media_item_id'),
            'a mint that reported failure must not be written over the NULL — falling through '
            . 'here stores the EMPTY STRING as the link',
        );
        $this->assertArrayHasKey('unminted artist', $db->artists);
        $this->assertNull(
            $db->artists['unminted artist']['media_item_id'],
            'the row must be left NULL for the next scan to retry, not linked to nothing',
        );
        $this->assertSame(
            0,
            $logger->countMessages('Backfilled a NULL media_item_id on a music row'),
            'and nothing may be REPORTED as healed either',
        );

        // (ii) The gate is re-opened, so the possibly-committed orphan stays reclaimable.
        $this->assertGreaterThan(
            0,
            $db->countStatements('LEFT JOIN music_albums ma'),
            'the failed mint must re-open $mayAdopt: 0 lookups here means the gate stayed shut '
            . 'and a committed-but-unreported media_items row can never be adopted',
        );
    }

    /**
     * A file whose `media_items` row survives but whose `music_tracks` row does NOT —
     * the partial-prior-scan shape — is `'updated'` when the repair INSERT lands and
     * **`'failed'`** when it writes nothing.
     *
     * ⚠ Review r2's HIGH finding was that this branch returned `'skipped'`: the file has no
     * `music_tracks` row, so it is NOT indexed, and charging it to the benign bucket is
     * precisely the "a partially failed scan reports clean success" defect S96(f) exists to
     * end. The S96 coverage pass then found the whole branch at `count=0` — including the
     * `? 'failed' : 'updated'` line the r2 fix added — so both arms were unpinned.
     *
     * The two arms are asserted with DIFFERENT observable counters (`failed` vs `updated`,
     * plus the per-file `error` log line), because a fixture that made them look alike
     * would leave the ternary uncovered while the test still passed.
     */
    public function testAMediaItemWhoseTrackRowIsMissingIsRepairedOrChargedAsFailed(): void
    {
        // (A) The repair INSERT lands → 'updated', nothing charged to failed.
        [$dirA, $taggerA] = $this->oneAlbumFixture(1, 'Partial Artist', 'Partial Album');
        $dbA = new MusicSchemaConnection();
        $dbA->plantOrphan('track', '01-t', 'lib-s96', null, $dirA . '/01-t.mp3');

        $resultA = $this->taggedScanner($dbA, $taggerA)->scanDirectory($dirA, null, 'lib-s96');

        $this->assertSame(
            1,
            $dbA->countStatements('INSERT INTO music_tracks'),
            'precondition: the existing media_items row is REUSED and only the missing '
            . 'music_tracks row is inserted',
        );
        $this->assertSame(
            [0, 1, 0],
            [$resultA->added, $resultA->updated, $resultA->failed],
            'the media_item already existed, so this is an update — not an add, not a failure',
        );

        // (B) The same repair INSERT writes nothing → 'failed', NOT 'skipped'.
        [$dirB, $taggerB] = $this->oneAlbumFixture(1, 'Partial Artist', 'Partial Album');
        $dbB = new MusicSchemaConnection();
        $dbB->plantOrphan('track', '01-t', 'lib-s96', null, $dirB . '/01-t.mp3');
        // `null` is the measured "wrote nothing" shape for an INSERT (Connection.php:1866).
        $dbB->returnNullFor('INSERT INTO music_tracks');

        $loggerB = new LogWriteFailureLogger();
        $resultB = $this->taggedScanner($dbB, $taggerB, $loggerB)->scanDirectory($dirB, null, 'lib-s96');

        $this->assertSame(
            [0, 0, 1],
            [$resultB->added, $resultB->updated, $resultB->failed],
            'no music_tracks row means the file is NOT indexed: it must be charged to failed, '
            . 'and `skipped` (the pre-r2 answer) is what made a lossy scan look clean',
        );
        $this->assertSame(
            1,
            $loggerB->countMessages('Track was not indexed'),
            'and the lost path must be logged at error, where an operator can find it — '
            . 'flushAlbum() logs it there because that is where the PATH is in hand',
        );
    }
}

/**
 * A PSR-14 dispatcher that records every event it is handed, for assertions.
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;
        return $event;
    }
}

/**
 * Exposes the protected mapping helpers for direct unit testing.
 */
final class TestableMusicScanner extends MusicLibraryScanner
{
    /**
     * @param array<string, mixed> $comments
     * @param array<string, mixed> $info
     * @return array<string, mixed>|null
     */
    public function mapPublic(array $comments, array $info): ?array
    {
        return $this->mapId3Comments($comments, $info);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function probeViaGetId3Public(string $path): ?array
    {
        return $this->probeViaGetId3($path);
    }
}

/**
 * A scanner whose tag reader is replaced by a pure function of the file path.
 *
 * Overriding {@see MusicLibraryScanner::probeViaGetId3()} short-circuits
 * `probeMetadata()` before getID3 or ffprobe is ever touched, which is what makes
 * a 6,000-file synthetic tree cheap enough to assert memory behaviour on.
 */
final class TaggedScanner extends MusicLibraryScanner
{
    /** @var \Closure(string): array<string, mixed> Path → canonical metadata. */
    public \Closure $tagger;

    /**
     * @param string $path Absolute filesystem path.
     * @return array<string, mixed>|null
     */
    protected function probeViaGetId3(string $path): ?array
    {
        return ($this->tagger)($path);
    }
}

/**
 * A zero-overhead {@see Connection} stand-in that COUNTS statements instead of
 * recording them.
 *
 * Deliberately not a PHPUnit mock: `createMock()` retains every invocation (and
 * its arguments) for later verification, which on a 6,000-file walk is tens of
 * thousands of retained arrays — enough to swamp the very memory measurement
 * {@see MusicLibraryScannerTest::testMemoryStaysBoundedAcrossALargeTree()} makes.
 * The parent constructor is bypassed so nothing connects to a database.
 */
final class CountingConnection extends Connection
{
    /** @var array<string, int> INSERT counts, keyed by target table. */
    public array $inserts = [];

    /** @var int Number of UPDATE statements issued. */
    public int $updates = 0;

    /** @var \Closure(string): void|null Observer called with each statement's SQL. */
    public ?\Closure $onStatement = null;

    /** @var int Fake AUTO_INCREMENT counter. */
    private int $autoInc = 0;

    /** Intentionally does not call the parent constructor (which would connect). */
    public function __construct()
    {
    }

    /**
     * @param string $query SQL statement.
     * @param array<int, mixed>|null $params Bound parameters.
     * @param int $fetchmode PDO fetch mode (unused).
     * @return array<int, mixed>|int|string Rows for SELECT, else an affected-row stand-in.
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        unset($params, $fetchmode);

        $sql = ltrim((string) $query);

        if ($this->onStatement !== null) {
            ($this->onStatement)($sql);
        }

        if (str_starts_with($sql, 'SELECT')) {
            return [];
        }

        if (str_starts_with($sql, 'INSERT')) {
            if (preg_match('/^INSERT INTO (\w+)/', $sql, $m) === 1) {
                $this->inserts[$m[1]] = ($this->inserts[$m[1]] ?? 0) + 1;
            }
            $this->autoInc++;
            return 1;
        }

        $this->updates++;
        return 1;
    }

    /** @return string */
    public function lastInsertId()
    {
        return (string) $this->autoInc;
    }
}

/**
 * A stateful, fault-injectable stand-in for the music half of the schema.
 *
 * {@see CountingConnection} only counts statements and {@see
 * MusicLibraryScannerTest::statefulDbMock()} cannot fail a statement (a PHPUnit
 * mock callback that throws also swallows the surrounding call bookkeeping), so
 * neither can express "the 2nd `INSERT INTO music_tracks` fails". This one keeps
 * real in-memory tables — including `media_items.parent_id` and
 * `music_albums.total_tracks` — answers the scanner's SELECTs from them, emulates
 * `refreshAlbumTrackTotal()`'s correlated subquery against the rows actually
 * present, and can be told to throw on the Nth statement matching a needle.
 */
final class MusicSchemaConnection extends Connection
{
    /**
     * @var list<array{id:string, library_id:?string, type:string, name:string,
     *     path:string, parent_id:?string}> `media_items`.
     */
    public array $mediaItems = [];

    /** @var array<string, array{id:int, name:string, media_item_id:?string}> `music_artists` by lower-case name. */
    public array $artists = [];

    /**
     * @var array<int, array{id:int, artist_id:int, title:string, media_item_id:?string, total_tracks:int}>
     *     `music_albums` by id.
     */
    public array $albums = [];

    /**
     * @var array<string, array{id:int, album_id:int, title:string, track_number:int,
     *     disc_number:int, duration_secs:int}> `music_tracks` by media_item_id.
     */
    public array $tracks = [];

    /** @var array<int, int> Every `total_tracks` value refreshAlbumTrackTotal() wrote, by album id. */
    public array $totalTracksWrites = [];

    /** @var list<string> Every statement, in order. */
    public array $statements = [];

    /** @var list<array{needle:string, param:?string, occurrence:int, seen:int}> Injected faults. */
    private array $faults = [];

    /** @var list<string> Statement substrings whose query() returns false (see returnFalseFor()). */
    private array $falseOn = [];

    /** @var list<string> Statement substrings whose query() returns NULL (see returnNullFor()). */
    private array $nullOn = [];

    /**
     * @var list<array{needle:string, affected:int}> Statement substrings whose query()
     *      returns an AFFECTED-ROW COUNT (see returnAffectedRowsFor()).
     */
    private array $affectedOn = [];

    private int $autoInc = 0;

    private int $uuid = 0;

    /** Intentionally does not call the parent constructor (which would connect). */
    public function __construct()
    {
    }

    /**
     * Make the `$occurrence`-th statement containing `$needle` throw.
     *
     * `$param` narrows the match to statements that also BIND a parameter containing
     * that string, which is what makes "fail this one artist's insert" expressible: a
     * 50-album fixture issues 49 byte-identical `INSERT INTO music_artists`
     * statements, and `RecursiveDirectoryIterator` yields directories in `readdir()`
     * order, so counting occurrences alone would fault whichever artist happened to be
     * walked third rather than the one the test is about.
     *
     * ⚠ **A FAULT ARM IS A `Connection`-DOUBLE ARM TOO — COUNT IT (review r5 INFO-2).** The
     * fix-r4 audit header said "13 `Connection`-double arms added by this branch"; that was the
     * count of *return-value* arms only. This method adds **6** more on the same double, so the
     * complete figure at `1ed09c76` is **19**. The fidelity verdict is unchanged, because
     * `execute()` re-throws at `Connection.php:1773-1775` / `:1777-1783` and is called at
     * `:1852` — BEFORE the keyword dispatch at `:1854-1856` — so "throws" is
     * keyword-independent and faithful for every keyword, and it is
     * the one shape {@see self::keywordFaithful()} therefore never has to judge. (Shape
     * divergence, inert: this double throws `\RuntimeException`, the client a `PDOException`;
     * production catches `\Throwable`.) Five program rounds in a row have quoted a count that
     * was a lower bound — state the method you counted with, not just the number.
     */
    public function faultOnNth(string $needle, int $occurrence, ?string $param = null): void
    {
        $this->faults[] = ['needle' => $needle, 'param' => $param, 'occurrence' => $occurrence, 'seen' => 0];
    }

    /**
     * Make every statement containing `$needle` return `false` instead of throwing.
     *
     * A DIFFERENT failure shape from {@see self::faultOnNth()}, and the only way to
     * reach two of the scanner's loss paths: `upsertArtist()`/`upsertAlbum()` return
     * `null` on `if ($result === false)`, which makes `flushAlbum()` log
     * "Failed to upsert artist"/"…album" and charge the WHOLE album — whereas a throw
     * unwinds past those branches into the outer catch and logs
     * "Skipping album after error during indexing" instead. Review r1 MED-2 is about
     * the level of exactly those two branches, so the tests need to land on them.
     */
    public function returnFalseFor(string $needle): void
    {
        $this->falseOn[] = $needle;
    }

    /**
     * Make every statement containing `$needle` return **`null`** instead of throwing.
     *
     * ⚠ **THIS IS THE ONE THAT MODELS THE REAL CLIENT FOR AN `INSERT` — AND ONLY FOR AN
     * `INSERT` (review r3 finding 1, narrowed by review r4).**
     * `MusicLibraryScanner::statementWroteNothing()`'s measured contract (real MySQL
     * 8.0.46 through `PhlixMySQLConnection`) is three-outcome: a 1-row INSERT returns
     * `lastInsertId()` **as a string**, a 0-row INSERT returns **`null`**, and any real
     * SQL error **THROWS**. This double could express only `false` — a value that client
     * NEVER returns — and `runInsert()` returned `int 1`, so nothing in the suite could
     * ever produce `null`. Review r3 measured the consequence: deleting
     * `|| $result === null` from the helper left the whole scanner + library + command +
     * integration selection at `OK (1113 tests, 10122 assertions)`, byte-identical to
     * baseline. The mocks were still modelling the OLD, unreachable `=== false` contract
     * that the fix had replaced.
     *
     * ⚠ **THE RETURN DOMAIN IS PER STATEMENT KEYWORD, NOT PER CLIENT (review r4).** Fix r3
     * transplanted the INSERT contract onto an **UPDATE**, where it does not hold, and the
     * arm that UPDATE really produces was left dead in test. `Connection::query()`
     * (`vendor/workerman/mysql/src/Connection.php:1854-1869`) `trim()`s the SQL, lowercases
     * the leading word and branches on it — so:
     *
     * | leading keyword | driver call | "wrote nothing" is | arm it with |
     * |---|---|---|---|
     * | `insert` | `lastInsertId()` (only when `rowCount() > 0`) | **`null`** | `returnNullFor()` |
     * | `update`, `delete`, `replace` | `rowCount()` | **`int 0`** | {@see self::returnAffectedRowsFor()} |
     * | `select`, `show` | `fetchAll()` | — (a `list`, or THROWS) | the `runSelect()` handlers |
     * | anything else | — | **`null`**, indistinguishable from a no-op | `returnNullFor()` |
     *
     * ⚠ **THAT TABLE IS NOW IMPLEMENTED BY THIS DOUBLE, NOT MERELY DOCUMENTED BY IT (review
     * r5 LOW-1).** Until r5 the dispatch had arms for `SELECT` and `INSERT` and sent everything
     * else to `runUpdate()`, so the double returned `int 1` for a `SHOW` (client: an array) and
     * for `TRUNCATE`/`SET` (client: `null`) — it contradicted two rows of the very table fix r4
     * cited it as pinning. {@see self::keywordOf()} now derives the keyword exactly as the
     * driver does and {@see self::resolveQueryReturn()} branches on all four rows, including
     * the `default`.
     *
     * So arming `returnNullFor()` on an `UPDATE`/`DELETE`/`REPLACE` needle models **no real
     * MySQL outcome for a well-formed single-line statement** — measured at review r4: 0 rows
     * matched → `int 0`, 1 row matched → `int 1`, 1 row matched but unchanged → `int 0`, bad
     * column → THROWS. It is still worth pinning as DEFENCE-IN-DEPTH, and it is deliberately
     * exempt from {@see self::keywordFaithful()}, because THREE real sources hand back `null`
     * for an UPDATE: a bare `createMock(Connection::class)` (its default return for every
     * method), the keyword-miss branch at `Connection.php:1866`, and — measured on real MySQL
     * 8.0.46 at review r5 — **the real client itself** whenever the `UPDATE` keyword is not
     * followed by a single space (`"UPDATE\nmusic_artists SET …"`, a tab, or a leading block
     * comment), because `Connection.php:1854` splits with `explode(" ", …)`. Production must
     * treat all of them as "not applied". Label it defence-in-depth when you use it on a
     * verbatim single-line UPDATE; the client's normal answer there is an `int`.
     *
     * `returnFalseFor()` stays — it is what r2's S3/S4 scenarios model and it keeps the
     * `false` arm (a different client / a bare PHPUnit mock) pinned independently.
     */
    public function returnNullFor(string $needle): void
    {
        $this->nullOn[] = $needle;
    }

    /**
     * Make every statement containing `$needle` return an **affected-row count** —
     * the shape the real client returns for `UPDATE`/`DELETE`/`REPLACE`.
     *
     * ⚠ **`$affected = 0` IS "WROTE NOTHING" FOR THESE KEYWORDS, and it is the ONLY
     * production-reachable way to say so (review r4).** Measured against real MySQL 8.0.46
     * through `PhlixMySQLConnection`, for the scanner's verbatim backfill statement
     * `UPDATE music_artists SET media_item_id = ? WHERE id = ? AND media_item_id IS NULL`:
     * the row the `AND media_item_id IS NULL` guard excludes → **`int 0`**, a row it lets
     * through → `int 1`, a re-run of the same statement → `int 0` again, a matched row whose
     * value does not change → `int 0`, an unknown column → THROWS. `null` never appears.
     * Before this arm existed, `backfillMusicMediaItemId()`'s only pinned outcome was the
     * `null` one, so deleting `|| (is_int($affected) && $affected < 1)` from
     * {@see \Phlix\Media\Music\MusicLibraryScanner::backfillMusicMediaItemId()}'s guard
     * (`if (self::statementWroteNothing($affected) || (is_int($affected) && $affected < 1))`,
     * cited by snippet because the line moves) — the half that actually fires in production —
     * left the FULL suite byte-identical to baseline, assertion count included.
     *
     * Returns BEFORE the table handlers, exactly like {@see self::returnNullFor()}, so the
     * in-memory row is not mutated either — which is what "affected 0 rows" means.
     *
     * ⚠ **THIS METHOD DELIBERATELY HAS NO ARM-TIME REFUSAL — DO NOT ADD ONE BACK (review r6
     * LOW-1).** Rounds r4 and r5 each built one here and each one was wrong in a new direction:
     *
     * - **r4** read the needle's first `" \t\n"`-delimited token. **r5 measured four bypasses**
     *   (`'INTO media_items'`, a needle whose `INSERT` follows a block comment,
     *   `"INSERT\r\nINTO …"`, `'WITH c AS (SELECT 1) SELECT …'`), one of which armed an
     *   **`INSERT` with `int 0`** and left the suite green.
     * - **r5** made it two `str_contains()` checks on the needle. **r6 measured the opposite
     *   failure:** `'SET a.total_tracks = (SELECT COUNT(*)'` — taken VERBATIM from
     *   {@see \Phlix\Media\Music\MusicLibraryScanner::refreshAlbumTrackTotal()}, an `UPDATE`
     *   whose real return is an `int` — was REFUSED over its `(SELECT COUNT(*)` **subquery**,
     *   and so were `'UPDATE settings SET selected'` and `'DELETE FROM tv_shows'`.
     * - And the refusal's message pointed the reader at `returnNullFor()`, i.e. at modelling
     *   **`null` for an `UPDATE`** — the exact r3/r4 defect this thread exists to prevent.
     *   **A guard whose failure message recommends the bug is worse than no guard.**
     *
     * A needle is a **substring**; the driver classifies the **statement**
     * (`Connection.php:1854-1856`). Nothing here can do that job, and the layer that can does
     * it completely for this arm: this method can only arm an `int`, and
     * {@see self::keywordFaithful()} permits an `int` for `update`/`delete`/`replace` and for
     * no other keyword — so an arm that fires on any other statement throws, however the needle
     * was spelled, and an arm that never fires produces no shape at all. Both directions —
     * every needle either round measured, plus the six that must still arm AND take effect —
     * are pinned by
     * `MusicLibraryScannerTest::testTheDoubleRefusesAnAffectedRowCountForAKeywordThatCannotReportOne()`.
     *
     * @param string $needle   Statement substring to arm.
     * @param int    $affected The `rowCount()` the client should report.
     */
    public function returnAffectedRowsFor(string $needle, int $affected): void
    {
        $this->affectedOn[] = ['needle' => $needle, 'affected' => $affected];
    }

    /**
     * Plant a `music_artists` row whose `media_item_id` is NULL — the S96(e) shape a
     * previous scan left behind when `createMediaItem()` reported failure.
     *
     * `NULL UNIQUE` (migration 065) makes this perfectly legal, which is why nothing
     * ever failed loudly.
     */
    public function plantArtistWithNullMediaItem(string $name): int
    {
        $this->autoInc++;
        $this->artists[strtolower($name)] = [
            'id' => $this->autoInc,
            'name' => $name,
            'media_item_id' => null,
        ];

        return $this->autoInc;
    }

    /** The album twin of {@see self::plantArtistWithNullMediaItem()}. */
    public function plantAlbumWithNullMediaItem(int $artistId, string $title): int
    {
        $this->autoInc++;
        $this->albums[$this->autoInc] = [
            'id' => $this->autoInc,
            'artist_id' => $artistId,
            'title' => $title,
            'media_item_id' => null,
            'total_tracks' => 0,
        ];

        return $this->autoInc;
    }

    /**
     * Plant an orphaned `media_items` row of `$type` that no music_* row references.
     *
     * `$path` defaults to `''` because an orphaned artist/album row has no file. Pass a real
     * file path to build the TRACK variant — a `media_items` row a previous scan committed
     * whose `music_tracks` row is missing, which is the only way to reach
     * `upsertTrack()`'s repair branch (it is selected by
     * `findExistingTrackMediaItemId()`, i.e. by `type = 'track' AND path = ?`).
     */
    public function plantOrphan(
        string $type,
        string $name,
        ?string $libraryId,
        ?string $parentId = null,
        string $path = ''
    ): string {
        $id = sprintf('orphan-%s-%02d', $type, ++$this->uuid);
        $this->mediaItems[] = [
            'id' => $id,
            'library_id' => $libraryId,
            'type' => $type,
            'name' => $name,
            'path' => $path,
            'parent_id' => $parentId,
        ];

        return $id;
    }

    /** How many statements contained `$needle`. */
    public function countStatements(string $needle): int
    {
        $n = 0;
        foreach ($this->statements as $sql) {
            if (str_contains($sql, $needle)) {
                $n++;
            }
        }

        return $n;
    }

    /** @return list<string> ids of `media_items` rows of `$type`. */
    public function mediaItemIds(string $type): array
    {
        $out = [];
        foreach ($this->mediaItems as $row) {
            if ($row['type'] === $type) {
                $out[] = $row['id'];
            }
        }

        return $out;
    }

    /**
     * Every artist/album `media_items` row that no `music_*` row points at — i.e. the
     * leak this scanner must not produce.
     *
     * @return list<string>
     */
    public function orphanedMusicMediaItems(): array
    {
        $out = [];
        foreach ($this->mediaItems as $row) {
            if (in_array($row['type'], ['artist', 'album'], true) && !$this->isReferenced($row['id'])) {
                $out[] = $row['id'];
            }
        }

        return $out;
    }

    /** Is this `media_items` id referenced by any `music_artists`/`music_albums` row? */
    public function isReferenced(string $mediaItemId): bool
    {
        foreach ($this->artists as $artist) {
            if ($artist['media_item_id'] === $mediaItemId) {
                return true;
            }
        }
        foreach ($this->albums as $album) {
            if ($album['media_item_id'] === $mediaItemId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $query SQL statement.
     * @param array<int, mixed>|null $params Bound parameters.
     * @param int $fetchmode PDO fetch mode (unused).
     * @return array<int, mixed>|int|string|false|null Rows for SELECT/SHOW, the insert id as
     *         a STRING for an INSERT (`'0'` for `media_items` — UUID PK, no AUTO_INCREMENT),
     *         an affected-row count (int) for an UPDATE/DELETE/REPLACE — armable via
     *         {@see self::returnAffectedRowsFor()} — `null` for ANY OTHER leading keyword
     *         (including an UPDATE the driver fails to recognise, see {@see self::keywordOf()}),
     *         `false` when {@see self::returnFalseFor()} armed this statement, or `null` when
     *         {@see self::returnNullFor()} did.
     *
     * ⚠ **THIS METHOD HAS EXACTLY ONE `return`, AND IT MUST STAY THAT WAY.** Every armed
     * value and every dispatch result funnels through {@see self::keywordFaithful()}, which
     * is what makes "a double models a shape the real client cannot produce" — the defect
     * class that hit this step in r3, r4 and r5 — impossible to add by writing a NEW arm.
     * An early `return` here would route around that check, so the single-return structure is
     * itself pinned by
     * `MusicLibraryScannerTest::testEveryQueryReturnFunnelsThroughTheKeywordFidelityCheck()`.
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        unset($fetchmode);

        $sql = ltrim((string) $query);
        $p = $params ?? [];
        $this->statements[] = $sql;
        $this->maybeFault($sql, $p);

        return $this->keywordFaithful($sql, $this->resolveQueryReturn($sql, $p));
    }

    /**
     * The leading statement keyword, derived EXACTLY the way the real driver derives it.
     *
     * `Connection::query()` does `trim($query)`, then **`explode(" ", $query)`** — a split on
     * the SPACE character only — then `strtolower(trim($rawStatement[0]))`
     * (`vendor/workerman/mysql/src/Connection.php:1835`, `:1854`, `:1856`). So the keyword is
     * only recognised when the token that split out `trim()`s down to it, and this is mirrored
     * rather than approximated because the difference is observable and was measured against
     * real MySQL 8.0.46 at review r5.
     *
     * ⚠ **EVERY ROW BELOW THAT IS REACHABLE THROUGH `query()` IS ASSERTED, AND THE ONE THAT IS
     * NOT SAYS WHY (review r6 LOW-2).** r5 wrote this table and cited the fidelity test as
     * pinning it while only two rows were asserted — the same "claims more than it pins" defect
     * r5 itself had just closed one helper over. All assertions live in
     * `MusicLibraryScannerTest::testTheSchemaDoubleModelsTheClientsPerKeywordReturnDomain()`:
     *
     * | statement (as `query()` sees it: already `ltrim()`ed) | `keywordOf()` | client | pinned |
     * |---|---|---|---|
     * | `UPDATE music_artists SET …` | `update` | `int` | **yes** |
     * | `UPDATE\nmusic_artists SET …` | `update\nmusic_artists` | **`null`** | **yes** |
     * | `UPDATE\tmusic_artists SET …` (tab, NO space) | `update\tmusic_artists` | **`null`** | **r6** |
     * | `UPDATE\t music_artists SET …` (tab THEN space) | `update` | `int` | **r6** |
     * | a leading block comment, then `UPDATE …` | the comment opener | **`null`** | **r6** |
     * | `   update music_artists SET …` | `update` | `int` | **NO — unreachable** |
     *
     * Which part of the derivation each asserted row is the pin on:
     * - **row 1** — `strtolower()` and the `[0]` index; drop either and it stops being `update`.
     * - **row 2** — `explode(' ')` as against any whitespace-aware split. A
     *   `preg_split('/\s+/')` "tidy-up" fails HERE.
     * - **row 3** — the split being by SPACE *only*. Making `\t` a separator fails HERE.
     * - **row 4** — the INNER `trim()`, i.e. `Connection.php:1856`'s, and **nothing else pins
     *   it**: review r6 dropped that `trim()` and this file stayed GREEN while the double
     *   answered `null` for a statement whose real client answer is an `int`.
     * - **row 5** — that a leading comment is NOT stripped before the split.
     *
     * The last row is the OUTER `trim()`, mirroring `Connection.php:1835`. It cannot be reached
     * from this double's public surface: {@see self::query()} `ltrim()`s the statement before
     * anything else, so no `$sql` arriving here can carry leading whitespace, and trailing
     * whitespace cannot change `explode(' ')[0]` once the inner `trim()` exists. It is kept for
     * fidelity with the driver, not for behaviour, and no assertion can falsify it — which is
     * stated rather than papered over, because "pinned by a test that cannot reach it" is how
     * this step lost three rounds.
     *
     * The `null` rows are why {@see \Phlix\Media\Music\MusicLibraryScanner::statementWroteNothing()}
     * is NOT dead code at the backfill site: reformatting that `UPDATE` into a heredoc would
     * move it from the int arm to the `null` arm.
     */
    private function keywordOf(string $sql): string
    {
        return strtolower(trim(explode(' ', trim($sql))[0]));
    }

    /**
     * Picks the value to return, from the arms first and the table handlers second.
     *
     * Every `return` in here is validated by {@see self::keywordFaithful()} before it leaves
     * {@see self::query()} — so this is the right place to add a new arm, and the wrong place
     * to try to smuggle one in.
     *
     * @param array<int, mixed> $p
     * @return array<int, mixed>|int|string|false|null
     */
    private function resolveQueryReturn(string $sql, array $p): array|int|string|false|null
    {
        foreach ($this->falseOn as $needle) {
            if (str_contains($sql, $needle)) {
                return false;
            }
        }

        // The real "wrote nothing" signal FOR AN INSERT — checked before the table
        // handlers so the in-memory row is not written either, exactly like a statement
        // that affected zero rows. See returnNullFor() for why this arm has to exist and
        // for the keywords it does NOT model.
        foreach ($this->nullOn as $needle) {
            if (str_contains($sql, $needle)) {
                return null;
            }
        }

        // The real "wrote nothing" signal for an UPDATE/DELETE/REPLACE: an affected-row
        // count of 0. Same placement, same reason. See returnAffectedRowsFor().
        foreach ($this->affectedOn as $armed) {
            if (str_contains($sql, $armed['needle'])) {
                return $armed['affected'];
            }
        }

        // Keyword dispatch, mirroring Connection.php:1857-1869 — including the `default`,
        // which is the driver's "anything else" row and returns null just as it does.
        return match ($this->keywordOf($sql)) {
            'select', 'show' => $this->runSelect($sql, $p),
            'insert' => $this->runInsert($sql, $p),
            'update', 'delete', 'replace' => $this->runUpdate($sql, $p),
            default => null,
        };
    }

    /**
     * REFUSES, at dispatch time, to hand back a shape the real client cannot produce.
     *
     * ⚠ **THIS IS THE ONLY LAYER, BY DESIGN (review r6 LOW-1).** {@see self::returnAffectedRowsFor()}
     * used to refuse suspicious NEEDLES too, and a needle is matched with `str_contains()`, so
     * that check could never be complete in either direction: it let `'INTO metadata_updates'`
     * arm an `INSERT`, and it refused `'SET a.total_tracks = (SELECT COUNT(*)'` — a fragment of
     * the scanner's own production `UPDATE`. It is gone. This check sees the **statement**,
     * which is what the driver classifies, so it is exact rather than heuristic.
     *
     * The `default` arm is not defence-only: three statements in
     * `testTheDoubleRefusesAnAffectedRowCountForAKeywordThatCannotReportOne()` (a `TRUNCATE`, a
     * `WITH`, and a comment-prefixed `INSERT`) drive it with an armed `int`, so
     * `default => 'int'` is a RED mutation. Review r6 found it never evaluated at all (LOW-3).
     *
     * `false` and `null` are exempt ON PURPOSE and are the only exemptions: they are the two
     * documented non-client sentinels this double models — `false` for a different client / a
     * bare PHPUnit mock ({@see self::returnFalseFor()}), `null` both as the real INSERT
     * "wrote nothing" and as the value a bare `createMock(Connection::class)` and
     * `Connection.php:1866` hand back for every keyword ({@see self::returnNullFor()}).
     *
     * @param array<int, mixed>|int|string|false|null $value
     * @return array<int, mixed>|int|string|false|null
     */
    private function keywordFaithful(string $sql, array|int|string|false|null $value): array|int|string|false|null
    {
        if ($value === false || $value === null) {
            return $value;
        }

        $keyword = $this->keywordOf($sql);
        $permitted = match ($keyword) {
            'select', 'show' => 'array',
            'insert' => 'string',
            'update', 'delete', 'replace' => 'int',
            default => 'null',
        };

        if (get_debug_type($value) !== $permitted) {
            throw new \LogicException(sprintf(
                'This double just returned %s for a statement whose leading keyword is "%s", and '
                . 'the real client returns %s for it (Connection.php:1857-1869). A double that '
                . 'models a shape the client cannot produce leaves the arm production really takes '
                . 'pinned by nothing — that is review r3/r4/r5\'s finding, three rounds running. '
                . 'Statement: %s',
                get_debug_type($value),
                $keyword,
                $permitted,
                substr($sql, 0, 80),
            ));
        }

        return $value;
    }

    /** @return string */
    public function lastInsertId()
    {
        return (string) $this->autoInc;
    }

    /**
     * @param array<int, mixed> $p Bound parameters of the statement.
     */
    private function maybeFault(string $sql, array $p): void
    {
        foreach ($this->faults as $i => $fault) {
            if (!str_contains($sql, $fault['needle'])) {
                continue;
            }
            if ($fault['param'] !== null && !$this->paramsContain($p, $fault['param'])) {
                continue;
            }
            $this->faults[$i]['seen']++;
            if ($this->faults[$i]['seen'] === $fault['occurrence']) {
                throw new \RuntimeException(sprintf(
                    'INJECTED FAULT: %s%s #%d',
                    $fault['needle'],
                    $fault['param'] !== null ? ' [' . $fault['param'] . ']' : '',
                    $fault['occurrence'],
                ));
            }
        }
    }

    /**
     * @param array<int, mixed> $p Bound parameters.
     */
    private function paramsContain(array $p, string $needle): bool
    {
        foreach ($p as $value) {
            if (is_scalar($value) && str_contains((string) $value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $p
     * @return list<array<string, mixed>>
     */
    private function runSelect(string $sql, array $p): array
    {
        // The one-per-scan orphan gate: BOTH music_* tables joined.
        if (str_contains($sql, 'LEFT JOIN music_artists ar') && str_contains($sql, 'LEFT JOIN music_albums al')) {
            foreach ($this->mediaItems as $row) {
                if (
                    in_array($row['type'], ['artist', 'album'], true)
                    && $row['path'] === ''
                    && $row['library_id'] === ($p[0] ?? null)
                    && !$this->isReferenced($row['id'])
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        // Artist adoption lookup.
        if (str_contains($sql, 'LEFT JOIN music_artists ma')) {
            foreach ($this->mediaItems as $row) {
                if (
                    $row['type'] === 'artist'
                    && $row['name'] === ($p[0] ?? null)
                    && $row['path'] === ''
                    && $row['library_id'] === ($p[1] ?? null)
                    && !$this->isReferenced($row['id'])
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        // Album adoption lookup. The artist scoping is applied ONLY when the
        // statement actually asks for it — a fake that filtered on parent_id
        // unconditionally would keep passing after the predicate was deleted from
        // the SQL, which is exactly the blind spot this test exists to close.
        if (str_contains($sql, 'LEFT JOIN music_albums ma')) {
            $artistScoped = str_contains($sql, 'mi.parent_id');
            foreach ($this->mediaItems as $row) {
                if (
                    $row['type'] === 'album'
                    && $row['name'] === ($p[0] ?? null)
                    && $row['path'] === ''
                    && $row['library_id'] === ($p[1] ?? null)
                    && !$this->isReferenced($row['id'])
                    && (
                        !$artistScoped
                        || $row['parent_id'] === null
                        || $row['parent_id'] === ($p[2] ?? null)
                    )
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, 'FROM music_artists WHERE name')) {
            $key = strtolower((string) ($p[0] ?? ''));

            return isset($this->artists[$key])
                ? [['id' => $this->artists[$key]['id'], 'media_item_id' => $this->artists[$key]['media_item_id']]]
                : [];
        }

        if (str_contains($sql, 'FROM music_albums WHERE artist_id')) {
            foreach ($this->albums as $album) {
                if ($album['artist_id'] === (int) ($p[0] ?? 0) && $album['title'] === ($p[1] ?? null)) {
                    return [['id' => $album['id'], 'media_item_id' => $album['media_item_id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, "FROM media_items WHERE type = 'track'")) {
            foreach ($this->mediaItems as $row) {
                if (
                    $row['type'] === 'track'
                    && $row['path'] === ($p[0] ?? null)
                    && $row['library_id'] === ($p[1] ?? null)
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, 'FROM music_tracks WHERE media_item_id')) {
            $mid = (string) ($p[0] ?? '');

            return isset($this->tracks[$mid]) ? [$this->tracks[$mid]] : [];
        }

        return [];
    }

    /**
     * A successful INSERT, returning what the REAL client returns for one.
     *
     * ⚠ **NOT `int 1` (review r3 finding 1 / finding 8).** `PhlixMySQLConnection::query()`
     * hands back `lastInsertId()` **as a string** for a statement that wrote a row, and
     * `media_items` has a CHAR(36) UUID primary key with no `AUTO_INCREMENT`, so a
     * SUCCESSFUL insert there returns the string **`'0'`** — which is FALSY in PHP. That
     * is the entire reason
     * {@see \Phlix\Media\Music\MusicLibraryScanner::statementWroteNothing()} must not be
     * "simplified" to `if (!$result)`, and while this double returned `int 1` the warning
     * was unfalsifiable off a real MySQL box: r3 planted `return !$result;` and this file
     * alone stayed `OK (46 tests, 399 assertions)`.
     *
     * @param array<int, mixed> $p
     * @return string The insert id, as the client reports it.
     */
    private function runInsert(string $sql, array $p): string
    {
        if (str_starts_with($sql, 'INSERT INTO media_items')) {
            $this->mediaItems[] = [
                'id' => (string) ($p[0] ?? ''),
                'library_id' => is_string($p[1] ?? null) ? $p[1] : null,
                'type' => (string) ($p[2] ?? ''),
                'name' => (string) ($p[3] ?? ''),
                'path' => (string) ($p[4] ?? ''),
                'parent_id' => null,
            ];

            // The measured `media_items` success value: no AUTO_INCREMENT column, so
            // lastInsertId() is '0'. A truthiness test here reports a healthy mint as a
            // failure — which is what makes this the single most valuable literal in the
            // whole double.
            return '0';
        }

        if (str_starts_with($sql, 'INSERT INTO music_artists')) {
            $this->autoInc++;
            $name = (string) ($p[0] ?? '');
            $this->artists[strtolower($name)] = [
                'id' => $this->autoInc,
                'name' => $name,
                'media_item_id' => is_string($p[2] ?? null) ? $p[2] : null,
            ];

            return (string) $this->autoInc;
        }

        if (str_starts_with($sql, 'INSERT INTO music_albums')) {
            $this->autoInc++;
            $this->albums[$this->autoInc] = [
                'id' => $this->autoInc,
                'artist_id' => (int) ($p[0] ?? 0),
                'title' => (string) ($p[2] ?? ''),
                'media_item_id' => is_string($p[1] ?? null) ? $p[1] : null,
                'total_tracks' => 0,
            ];

            return (string) $this->autoInc;
        }

        if (str_starts_with($sql, 'INSERT INTO music_tracks')) {
            $this->autoInc++;
            $this->tracks[(string) ($p[0] ?? '')] = [
                'id' => $this->autoInc,
                'album_id' => (int) ($p[1] ?? 0),
                'title' => (string) ($p[3] ?? ''),
                'track_number' => (int) ($p[4] ?? 0),
                'disc_number' => (int) ($p[5] ?? 1),
                'duration_secs' => (int) ($p[6] ?? 0),
            ];

            return (string) $this->autoInc;
        }

        // The scanner issues no other INSERT; `'0'` is the no-AUTO_INCREMENT shape.
        return '0';
    }

    /**
     * @param array<int, mixed> $p
     * @return int
     */
    private function runUpdate(string $sql, array $p): int
    {
        // S96(e) backfill. Honours the `AND media_item_id IS NULL` guard exactly, and
        // returns the AFFECTED-ROW count — 0 when the guard excludes the row — because
        // that is what the scanner reads to decide whether the row now references the
        // id it just minted. A fake that always returned 1 would keep passing after
        // the guard was deleted from the SQL.
        if (str_contains($sql, 'UPDATE music_artists SET media_item_id')) {
            $guarded = str_contains($sql, 'media_item_id IS NULL');
            foreach ($this->artists as $key => $artist) {
                if ($artist['id'] !== (int) ($p[1] ?? 0)) {
                    continue;
                }
                if ($guarded && $artist['media_item_id'] !== null) {
                    return 0;
                }
                $this->artists[$key]['media_item_id'] = is_string($p[0] ?? null) ? $p[0] : null;

                return 1;
            }

            return 0;
        }

        if (str_contains($sql, 'UPDATE music_albums SET media_item_id')) {
            $guarded = str_contains($sql, 'media_item_id IS NULL');
            $albumId = (int) ($p[1] ?? 0);
            if (!isset($this->albums[$albumId])) {
                return 0;
            }
            if ($guarded && $this->albums[$albumId]['media_item_id'] !== null) {
                return 0;
            }
            $this->albums[$albumId]['media_item_id'] = is_string($p[0] ?? null) ? $p[0] : null;

            return 1;
        }

        // refreshAlbumTrackTotal(): derive the count from the rows that really
        // exist, exactly as the correlated subquery does.
        if (str_contains($sql, 'total_tracks = (SELECT COUNT(*)')) {
            $albumId = (int) ($p[0] ?? 0);
            $count = 0;
            foreach ($this->tracks as $track) {
                if ($track['album_id'] === $albumId) {
                    $count++;
                }
            }
            if (isset($this->albums[$albumId])) {
                $this->albums[$albumId]['total_tracks'] = $count;
            }
            $this->totalTracksWrites[$albumId] = $count;
        }

        return 1;
    }
}

/**
 * A {@see StructuredLogger} whose write can be made to FAIL.
 *
 * Every PSR-3 level funnels through `log()`, so overriding that one method both
 * records what the scanner logged and lets a test model "the failure handler itself
 * failed" — the only remaining way an exception can escape `flushAlbum()`'s track loop
 * now that every `upsertTrack()` call has its own catch.
 *
 * ⚠ That is a MODELLED failure, not a live one. A real `StructuredLogger` cannot throw
 * on a write: it wraps every routed handler in a `WhatFailureGroupHandler`
 * ({@see \Phlix\Common\Logger\StructuredLogger}) precisely so a handler exception can
 * never propagate — measured three ways in review r3 (unwritable path, path is a
 * directory, `/dev/full` ENOSPC: no throw in any of them). So the `finally` this double
 * exercises is defence-in-depth against a future throwing statement in that loop, and
 * this double is how it stays pinned rather than evidence that a log volume filling up
 * would trip it today. The parent constructor is bypassed, so no Monolog handler and no
 * log file is created.
 */
final class LogWriteFailureLogger extends StructuredLogger
{
    /** @var list<string> Every message logged, in order. */
    public array $messages = [];

    /**
     * @var list<array{level: string, message: string}> Every record with its PSR-3
     *      level, in order. Recorded since review r1 MED-2: the SEVERITY is now part
     *      of the contract (`config/logger.php` routes only `error`-and-above into
     *      `.logs/error.log`), and a double that discarded the level — as this one
     *      did — cannot tell a fix from a regression.
     */
    public array $records = [];

    /** Substring whose log write throws; '' never throws. */
    public string $throwOn = '';

    /** Intentionally does not call the parent constructor (no Monolog, no file). */
    public function __construct()
    {
    }

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        unset($context);

        $text = (string) $message;
        $this->messages[] = $text;
        $this->records[] = ['level' => self::levelName($level), 'message' => $text];

        if ($this->throwOn !== '' && str_contains($text, $this->throwOn)) {
            throw new \RuntimeException('LOG WRITE FAILED: ' . $this->throwOn);
        }
    }

    /**
     * Normalise whatever `StructuredLogger`'s level helpers pass through to a PSR-3
     * level name. They hand over a Monolog {@see \Monolog\Level} enum.
     *
     * @param mixed $level
     */
    private static function levelName($level): string
    {
        if ($level instanceof \Monolog\Level) {
            return $level->toPsrLogLevel();
        }

        return is_scalar($level) ? strtolower((string) $level) : 'unknown';
    }

    /**
     * How many records at PSR-3 level `$level` contain `$needle` — or EQUAL it when
     * `$exact`.
     */
    public function countAtLevel(string $level, string $needle, bool $exact = false): int
    {
        $n = 0;
        foreach ($this->records as $record) {
            if ($record['level'] !== $level) {
                continue;
            }
            $hit = $exact ? $record['message'] === $needle : str_contains($record['message'], $needle);
            if ($hit) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * How many recorded messages contain `$needle` — or EQUAL it when `$exact`.
     *
     * The exact form exists because S96(f) added a second completion line whose text
     * starts with the first one ("Music directory scan complete" vs "… complete with
     * skipped files"), so a substring count cannot tell the clean case from the lossy
     * one, and telling them apart is the whole point of the pair.
     */
    public function countMessages(string $needle, bool $exact = false): int
    {
        $n = 0;
        foreach ($this->messages as $message) {
            $hit = $exact ? $message === $needle : str_contains($message, $needle);
            if ($hit) {
                $n++;
            }
        }

        return $n;
    }
}

/**
 * A scanner whose getID3 reader is faked to return caller-controlled comments,
 * so the native happy path is testable without real tagged audio fixtures.
 */
final class FakeGetId3Scanner extends MusicLibraryScanner
{
    /** @var array<string, mixed> */
    public array $fakeComments = [];

    protected function getId3Reader(): \getID3
    {
        $comments = $this->fakeComments;
        return new class ($comments) extends \getID3 {
            /** @param array<string, mixed> $comments */
            public function __construct(private array $comments)
            {
            }

            /**
             * @param string $filename
             * @return array<string, mixed>
             */
            public function analyze($filename, $filesize = null, $original_filename = '', $fp = null): array
            {
                unset($filename, $filesize, $original_filename, $fp);
                return ['comments' => $this->comments, 'playtime_seconds' => 123.0];
            }
        };
    }
}
