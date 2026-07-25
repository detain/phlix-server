<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Shared\Events\Library\MediaItemAdded;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
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
        ?StructuredLogger $logger = null
    ): TaggedScanner {
        $scanner = new TaggedScanner($db, $this->createMock(FfmpegRunner::class), $logger);
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
     * short-circuits on that natural key with `media_item_id = NULL` — that residue is
     * S96(e)'s backfill, and when S96(e) lands it will reclaim through these very
     * adoption lookups, so it needs the flag to be open exactly here.
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
     */
    public function faultOnNth(string $needle, int $occurrence, ?string $param = null): void
    {
        $this->faults[] = ['needle' => $needle, 'param' => $param, 'occurrence' => $occurrence, 'seen' => 0];
    }

    /** Plant an orphaned `media_items` row of `$type` that no music_* row references. */
    public function plantOrphan(string $type, string $name, ?string $libraryId, ?string $parentId = null): string
    {
        $id = sprintf('orphan-%s-%02d', $type, ++$this->uuid);
        $this->mediaItems[] = [
            'id' => $id,
            'library_id' => $libraryId,
            'type' => $type,
            'name' => $name,
            'path' => '',
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
     * @return array<int, mixed>|int|string Rows for SELECT, else an affected-row stand-in.
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        unset($fetchmode);

        $sql = ltrim((string) $query);
        $p = $params ?? [];
        $this->statements[] = $sql;
        $this->maybeFault($sql, $p);

        if (str_starts_with($sql, 'SELECT')) {
            return $this->runSelect($sql, $p);
        }
        if (str_starts_with($sql, 'INSERT')) {
            return $this->runInsert($sql, $p);
        }

        return $this->runUpdate($sql, $p);
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
     * @param array<int, mixed> $p
     * @return int
     */
    private function runInsert(string $sql, array $p): int
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

            return 1;
        }

        if (str_starts_with($sql, 'INSERT INTO music_artists')) {
            $this->autoInc++;
            $name = (string) ($p[0] ?? '');
            $this->artists[strtolower($name)] = [
                'id' => $this->autoInc,
                'name' => $name,
                'media_item_id' => is_string($p[2] ?? null) ? $p[2] : null,
            ];

            return 1;
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

            return 1;
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

            return 1;
        }

        return 1;
    }

    /**
     * @param array<int, mixed> $p
     * @return int
     */
    private function runUpdate(string $sql, array $p): int
    {
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
        unset($level, $context);

        $text = (string) $message;
        $this->messages[] = $text;

        if ($this->throwOn !== '' && str_contains($text, $this->throwOn)) {
            throw new \RuntimeException('LOG WRITE FAILED: ' . $this->throwOn);
        }
    }

    /** How many recorded messages contain `$needle`. */
    public function countMessages(string $needle): int
    {
        $n = 0;
        foreach ($this->messages as $message) {
            if (str_contains($message, $needle)) {
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
