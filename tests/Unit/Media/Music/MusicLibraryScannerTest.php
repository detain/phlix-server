<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;
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
                        $mediaItemTypes[] = (string) (($params ?? [])[0] ?? '');
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
