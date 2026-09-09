<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Writer;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItem;
use Phlix\Media\Metadata\LocalNfoProvider;
use Phlix\Media\Metadata\Writer\SidecarNotWritableException;
use Phlix\Media\Metadata\Writer\SidecarWriter;
use Phlix\Media\Storage\ArtworkStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * S88 — the sidecar writer: NFO + poster.jpg + fanart.jpg next to the media
 * file, round-tripping against the existing {@see LocalNfoProvider} reader.
 *
 * Acceptance criteria pinned here, clause by clause:
 *  - "A round-trip test writes an NFO/sidecar set and confirms
 *    LocalNfoProvider can read it back correctly" → the movie/episode/image
 *    round-trip tests (real reader class, real filesystem, zero mocks).
 *  - "a deliberately-read-only media directory produces a clear status
 *    message, not an unhandled exception" → realized per ruling R2 as the
 *    NAMED {@see SidecarNotWritableException} (item id + target dir + reason)
 *    logged by the worker's per-writer catch as a warning: the worker-path
 *    test drives the REAL {@see \Phlix\Media\Metadata\Writer\MetadataWriteWorker}
 *    and asserts the warning line + untouched prior on-disk bytes.
 *  - re-callable/idempotence and supports() coverage → their own tests.
 *
 * Every fixture is a REAL artefact written with real PHP primitives (DOM,
 * file_put_contents) into a temp dir and parsed by the real reader — no
 * hand-guessed NFO strings, per the S345 lesson that a hand-written fixture
 * cannot tell you the real thing differs.
 */
final class SidecarWriterTest extends TestCase
{
    /** @var list<string> temp dirs to remove in tearDown */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->removeTree($dir);
        }
        $this->dirs = [];
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/phlix_s88_' . $prefix . '_' . uniqid('', true);
        self::assertTrue(mkdir($dir, 0777, true));
        $this->dirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        @chmod($dir, 0777);
        /** @var list<string> $entries */
        $entries = glob($dir . '/*') ?: [];
        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                $this->removeTree($entry);
            } else {
                @chmod($entry, 0644);
                @unlink($entry);
            }
        }
        @rmdir($dir);
    }

    /**
     * Canonical movie metadata in the EXACT shape TmdbProvider::formatMovieDetails()
     * persists into metadata_json (string year from date('Y'), runtime_ticks in
     * 6e8-per-minute units, actors as {name, role, order} maps, studio as a
     * single nullable string).
     *
     * @return array<string, mixed>
     */
    private function canonicalMovieMetadata(): array
    {
        return [
            'name' => 'Inception',
            'original_name' => 'Inception & Company',
            'overview' => 'A thief who steals corporate secrets <through dream-sharing> & more.',
            'official_rating' => 'PG-13',
            'vote_average' => 8.4,
            'vote_count' => 36900,
            'year' => '2010',
            'runtime_ticks' => 148 * 600000000,
            'genres' => ['Action', 'Science Fiction', 'Thriller'],
            'tags' => ['heist', 'dreams'],
            'studio' => 'Warner Bros. Pictures',
            'tagline' => 'Your mind is the scene of the crime.',
            'imdb_id' => 'tt1375666',
            'tmdb_id' => '27205',
            'poster_path' => '/abc123.jpg',
            'backdrop_path' => '/xyz789.jpg',
            'actors' => [
                ['name' => 'Leonardo DiCaprio', 'role' => 'Cobb', 'order' => 0],
                ['name' => 'Marion Cotillard', 'role' => 'Mal', 'order' => 1],
            ],
            'director' => 'Christopher Nolan',
        ];
    }

    private function movieItem(string $dir): MediaItem
    {
        $path = $dir . '/Inception (2010).mkv';
        file_put_contents($path, 'not-a-real-mkv');

        return new MediaItem('item-s88-1', 'Inception', 'movie', $path, []);
    }

    public function test_supports_declares_exactly_movie_episode_track(): void
    {
        $writer = new SidecarWriter();

        foreach (['movie', 'episode', 'track'] as $type) {
            $this->assertTrue($writer->supports($type), "writer must support {$type}");
        }

        foreach (['', 'clip', 'book', 'audiobook', 'image', 'tvshow', 'series', 'Movie'] as $type) {
            $this->assertFalse($writer->supports($type), "writer must NOT support {$type}");
        }
    }

    public function test_movie_nfo_round_trips_through_the_real_local_nfo_reader(): void
    {
        $dir = $this->tempDir('rt');
        $item = $this->movieItem($dir);
        $writer = new SidecarWriter();

        $writer->write($item, $this->canonicalMovieMetadata(), $dir);

        $nfoPath = $dir . '/Inception (2010).nfo';
        $this->assertFileExists($nfoPath);

        $parsed = (new LocalNfoProvider())->getDetails($nfoPath);

        $this->assertSame('movie', $parsed['type']);
        $this->assertSame('Inception', $parsed['name']);
        $this->assertSame('Inception & Company', $parsed['original_name']);
        $this->assertSame(
            'A thief who steals corporate secrets <through dream-sharing> & more.',
            $parsed['overview'],
        );
        $this->assertSame(2010, $parsed['year']);
        $this->assertSame(8.4, $parsed['rating']);
        $this->assertSame(36900, $parsed['votes']);
        $this->assertSame(148, $parsed['runtime']);
        $this->assertSame('PG-13', $parsed['mpaa']);
        $this->assertSame('Your mind is the scene of the crime.', $parsed['tagline']);
        $this->assertSame(
            ['Action', 'Science Fiction', 'Thriller'],
            array_values($parsed['genres']),
        );
        $this->assertSame(['Warner Bros. Pictures'], $parsed['studios']);
        $this->assertSame(['Christopher Nolan'], $parsed['directors']);
        $this->assertSame('Leonardo DiCaprio', $parsed['actors'][0]['name']);
        $this->assertSame('Cobb', $parsed['actors'][0]['role']);
        $this->assertSame('Marion Cotillard', $parsed['actors'][1]['name']);
        $this->assertSame(['tmdb' => '27205', 'imdb' => 'tt1375666'], $parsed['external_ids']);
    }

    public function test_poster_and_fanart_sidecars_round_trip_through_the_reader_image_discovery(): void
    {
        $dir = $this->tempDir('img');
        $item = $this->movieItem($dir);

        // REAL ArtworkStorage cache layout: {dir}/{itemId}/original.jpg
        $artworkRoot = $this->tempDir('art');
        mkdir($artworkRoot . '/' . $item->id, 0777, true);
        file_put_contents($artworkRoot . '/' . $item->id . '/original.jpg', 'POSTER-BYTES');
        $writer = new SidecarWriter(new ArtworkStorage($artworkRoot));

        // Operator-curated fanart ALREADY in the media directory.
        $meta = $this->canonicalMovieMetadata();
        $meta['backdrop_path'] = $dir . '/backdrop-original.jpg';
        file_put_contents($meta['backdrop_path'], 'FANART-BYTES');

        $writer->write($item, $meta, $dir);

        $this->assertSame('POSTER-BYTES', file_get_contents($dir . '/poster.jpg'));
        $this->assertSame('FANART-BYTES', file_get_contents($dir . '/fanart.jpg'));

        $images = (new LocalNfoProvider())->getImages($dir);
        $posterNames = array_column($images['posters'] ?? [], 'filename');
        $backdropNames = array_column($images['backdrops'] ?? [], 'filename');
        $this->assertContains('poster.jpg', $posterNames);
        $this->assertContains('fanart.jpg', $backdropNames);
    }

    public function test_remote_artwork_paths_are_never_written_and_never_throw(): void
    {
        $dir = $this->tempDir('remote');
        $item = $this->movieItem($dir);

        // TMDB-relative poster_path and no artwork cache → no poster sidecar.
        // backdrop_path is likewise remote → no fanart sidecar. NFO still ships.
        (new SidecarWriter())->write($item, $this->canonicalMovieMetadata(), $dir);

        $this->assertFileDoesNotExist($dir . '/poster.jpg');
        $this->assertFileDoesNotExist($dir . '/fanart.jpg');
        $this->assertFileExists($dir . '/Inception (2010).nfo');
    }

    public function test_metadata_never_reaches_outside_the_media_directory_for_image_bytes(): void
    {
        $dir = $this->tempDir('jail');
        $outside = $this->tempDir('outside');
        file_put_contents($outside . '/secret.jpg', 'ARBITRARY-DISK-BYTES');
        $item = $this->movieItem($dir);

        $meta = $this->canonicalMovieMetadata();
        $meta['poster_path'] = $outside . '/secret.jpg';
        $meta['backdrop_path'] = $outside . '/secret.jpg';

        (new SidecarWriter())->write($item, $meta, $dir);

        // Positive control (S345 rule 3): the SAME writer DOES copy a
        // media-dir-internal path (see test_poster_and_fanart_sidecars_…), so
        // these absences are the jail working, not a dead code path.
        $this->assertFileDoesNotExist($dir . '/poster.jpg');
        $this->assertFileDoesNotExist($dir . '/fanart.jpg');
    }

    public function test_read_only_media_directory_throws_named_exception_with_full_context(): void
    {
        $dir = $this->tempDir('ro');
        $item = $this->movieItem($dir);
        $stale = $dir . '/Inception (2010).nfo';
        file_put_contents($stale, 'PREVIOUS ON-DISK BYTES');

        self::assertTrue(chmod($dir, 0555));
        try {
            (new SidecarWriter())->write($item, ['name' => 'Inception'], $dir);
            $this->fail('a read-only media directory must throw');
        } catch (SidecarNotWritableException $e) {
            $this->assertStringContainsString('item-s88-1', $e->getMessage());
            $this->assertStringContainsString($dir, $e->getMessage());
            $this->assertStringContainsString('not writable', $e->getMessage());
            $this->assertSame($item->id, $e->itemId);
            $this->assertSame($dir, $e->targetDir);
        } finally {
            chmod($dir, 0777);
        }

        // The interface contract: a failed write leaves prior state intact.
        $this->assertSame('PREVIOUS ON-DISK BYTES', file_get_contents($stale));
        $this->assertSame([], glob($dir . '/*.phlix-tmp'));
    }

    public function test_missing_media_directory_throws_named_exception_naming_the_missing_reason(): void
    {
        $dir = sys_get_temp_dir() . '/phlix_s88_gone_' . uniqid('', true);
        $item = new MediaItem('item-x', 'X', 'movie', $dir . '/x.mkv', []);

        try {
            (new SidecarWriter())->write($item, [], $dir);
            $this->fail('a vanished media directory must throw');
        } catch (SidecarNotWritableException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
            $this->assertStringContainsString('item-x', $e->getMessage());
        }
    }

    public function test_worker_turns_the_unwritable_directory_throw_into_a_warning_log_line(): void
    {
        $dir = $this->tempDir('warn');
        $item = $this->movieItem($dir);
        $stale = $dir . '/Inception (2010).nfo';
        file_put_contents($stale, 'PREVIOUS ON-DISK BYTES');

        $queueDir = $this->tempDir('queue');
        $store = new \Phlix\Media\Metadata\Writer\MetadataWriteJobStore($queueDir);

        $registry = new \Phlix\Media\Metadata\Writer\MetadataWriterRegistry();
        $registry->register(new SidecarWriter());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('writer failed for item'),
                $this->callback(function (array $context): bool {
                    return $context['item_id'] === 'item-s88-1'
                        && $context['writer'] === SidecarWriter::class
                        && str_contains((string) $context['error'], 'not writable');
                }),
            );

        $worker = new \Phlix\Media\Metadata\Writer\MetadataWriteWorker(
            $store,
            $registry,
            new SidecarTestRowRepo($this->createMock(Connection::class), [
                'item-s88-1' => [
                    'id' => 'item-s88-1',
                    'name' => 'Inception',
                    'type' => 'movie',
                    'path' => $dir . '/Inception (2010).mkv',
                    'metadata_json' => json_encode(['name' => 'Inception'], JSON_THROW_ON_ERROR),
                ],
            ]),
            $logger,
        );

        $store->enqueue(new \Phlix\Media\Metadata\Writer\MetadataWriteJob('item-s88-1', 'lib-1'));
        self::assertTrue(chmod($dir, 0555));
        try {
            $processed = $worker->runOnce();
        } finally {
            chmod($dir, 0777);
        }

        // The job DRAINS (worker keeps going), the throw becomes the operator
        // status (the logged warning), and the on-disk state is untouched.
        $this->assertSame(1, $processed);
        $this->assertSame('PREVIOUS ON-DISK BYTES', file_get_contents($stale));
    }

    public function test_rewriting_the_same_item_is_byte_identical_and_leaves_no_residue(): void
    {
        $dir = $this->tempDir('idem');
        $item = $this->movieItem($dir);
        $writer = new SidecarWriter();

        $writer->write($item, $this->canonicalMovieMetadata(), $dir);
        $first = (array) scandir($dir);
        $firstNfo = file_get_contents($dir . '/Inception (2010).nfo');

        $writer->write($item, $this->canonicalMovieMetadata(), $dir);
        $secondNfo = file_get_contents($dir . '/Inception (2010).nfo');

        $this->assertSame($firstNfo, $secondNfo, 're-callable: same input must produce identical bytes');
        $this->assertSame($first, (array) scandir($dir), 'no temp/backup residue between runs');
        $this->assertSame(2010, (new LocalNfoProvider())->getDetails($dir . '/Inception (2010).nfo')['year']);
    }

    public function test_episode_nfo_round_trips_as_episodedetails(): void
    {
        $dir = $this->tempDir('ep');
        $path = $dir . '/S01E02.mkv';
        file_put_contents($path, 'x');
        $item = new MediaItem('ep-1', 'The Shoe', 'episode', $path, []);

        (new SidecarWriter())->write($item, [
            'name' => 'The Shoe',
            'overview' => 'An old enemy returns.',
            'season' => 1,
            'episode' => 2,
            'aired' => '2020-01-05',
            'vote_average' => 7.5,
            'director' => 'Vicky Jensen',
        ], $dir);

        $parsed = (new LocalNfoProvider())->getDetails($dir . '/S01E02.nfo');

        $this->assertSame('episode', $parsed['type']);
        $this->assertSame('The Shoe', $parsed['name']);
        $this->assertSame('An old enemy returns.', $parsed['overview']);
        $this->assertSame(1, $parsed['season_number']);
        $this->assertSame(2, $parsed['episode_number']);
        $this->assertSame('2020-01-05', $parsed['aired']);
        $this->assertSame(7.5, $parsed['rating']);
        $this->assertSame('Vicky Jensen', $parsed['director']);
    }

    public function test_track_nfo_round_trips_title_and_artist(): void
    {
        $dir = $this->tempDir('track');
        $path = $dir . '/01 - Ghostbound.mp3';
        file_put_contents($path, 'x');
        $item = new MediaItem('tr-1', 'Ghostbound', 'track', $path, []);

        (new SidecarWriter())->write($item, [
            'name' => 'Ghostbound',
            'artist' => 'FKA twigs',
            'album' => 'MAGDALENE',
        ], $dir);

        // LocalNfoProvider has no <song> parser — the generic .nfo path defaults
        // to movie extraction, which must still recover title/plot cleanly.
        $parsed = (new LocalNfoProvider())->getDetails($dir . '/01 - Ghostbound.nfo');

        $this->assertSame('Ghostbound', $parsed['name']);
        $this->assertArrayNotHasKey('plot', $parsed);
    }

    public function test_xml_control_characters_are_stripped_but_the_file_still_parses(): void
    {
        $dir = $this->tempDir('ctrl');
        $item = $this->movieItem($dir);

        (new SidecarWriter())->write($item, [
            'name' => "Bad\x07Bell and \x00NUL",
            'overview' => "tabs\tand\nnewlines survive",
        ], $dir);

        $parsed = (new LocalNfoProvider())->getDetails($dir . '/Inception (2010).nfo');
        $this->assertSame('BadBell and NUL', $parsed['name']);
        $this->assertStringContainsString('tabs', (string) $parsed['overview']);
    }

    public function test_generator_marker_is_emitted_in_the_nfo_header(): void
    {
        $dir = $this->tempDir('marker');
        $item = $this->movieItem($dir);

        (new SidecarWriter())->write($item, ['name' => 'Inception'], $dir);

        $this->assertStringContainsString(
            SidecarWriter::GENERATOR_MARKER,
            (string) file_get_contents($dir . '/Inception (2010).nfo'),
        );
    }
}

/**
 * ItemRepository double serving fixed rows — same contract as the S87 suite's
 * RowLookupRepo, defined per-file so this suite stays single-file executable
 * (the S87 RecordingWriter precedent; PSR1 MultipleClasses is exempted for
 * tests by phpcs-tests.xml).
 */
final class SidecarTestRowRepo extends ItemRepository
{
    /** @param array<string, array<string, mixed>> $rows */
    public function __construct(Connection $db, private array $rows)
    {
        parent::__construct($db);
    }

    public function findById(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }
}
