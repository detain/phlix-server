<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

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
