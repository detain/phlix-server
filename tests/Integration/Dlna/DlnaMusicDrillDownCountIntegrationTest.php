<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Dlna;

use Phlix\Common\Uuid;
use Phlix\Dlna\LibraryBridge;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S147 fix round — real-MySQL proof for the two COUNT queries the music
 * drill-down advertises as `TotalMatches`.
 *
 * ## Why this file exists
 *
 * The S147 review found {@see MusicLibraryService::countAlbumMediaItemsForArtist()}
 * and {@see MusicLibraryService::countTrackMediaItemsForAlbum()} to be **surviving
 * mutants**: the shipped SQL is correct, but nothing held it there. Both methods
 * appear in the unit suite only through `createMock(MusicLibraryService::class)`,
 * so no test asserted their SQL and no test ever executed them. Measured on the
 * branch before this file existed:
 *
 *   - dropping `AND al.media_item_id IS NOT NULL` from the album count left the
 *     ENTIRE 7,702-test suite green against real MySQL;
 *   - rewriting the track count's `WHERE` as `al.media_item_id = ? OR 1 = 1` —
 *     i.e. counting every track in the library — left all 703 Music/Dlna tests
 *     green.
 *
 * ## Why the first of those matters more than tidiness
 *
 * It is **the S97 defect one level down.** `getAlbumMediaItemIdsForArtist()`
 * filters `al.media_item_id IS NOT NULL` because an album whose `media_items`
 * mint failed has no row to browse to (schema-permitted: `music_albums.media_item_id`
 * is `NULL`-able in migration 065). Without the same predicate in the COUNT, an
 * artist drill-down advertises MORE albums than the container can ever deliver and
 * a renderer paging to the end gets an empty final page — the exact "advertised
 * count and deliverable list disagree" shape S147 exists to close, re-created on
 * the one branch S147 shipped no real-DB test for.
 *
 * ## Why it has to be a real database
 *
 * The in-memory doubles page with `array_slice()` over a PHP array. They cannot
 * express a `JOIN`, a `NULL` column or a `WHERE` predicate at all, so they would
 * pass against either mutant. The same lesson as S145: a double proves the
 * arithmetic, only MySQL proves the SQL.
 *
 * Self-skips with no reachable MySQL, like every other test under
 * `tests/Integration/` (CI provisions one and applies the migrations first).
 *
 */
final class DlnaMusicDrillDownCountIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    /** Albums seeded under the fixture artist that DO have a `media_items` row. */
    private const MINTED_ALBUMS = 4;

    /** Albums seeded under the fixture artist whose mint "failed" (NULL id). */
    private const UNMINTED_ALBUMS = 1;

    /** Albums seeded under a SECOND artist, so the count must be artist-scoped. */
    private const OTHER_ARTIST_ALBUMS = 3;

    /** Tracks on the album under test. */
    private const TRACKS_ON_ALBUM = 7;

    /** Tracks seeded on the fixture's OTHER albums, so a library-wide count differs. */
    private const TRACKS_ELSEWHERE = 11;

    private ?Connection $db = null;

    private string $libraryId = '';

    private string $prefix = '';

    /** @var list<int> Seeded `music_artists` ids — cascade-delete albums + tracks. */
    private array $artistIds = [];

    /** @var list<string> Seeded `media_items` ids. */
    private array $mediaIds = [];

    /** The artist under test, by `media_items` UUID. */
    private string $artistMediaItemId = '';

    /** A second artist, by `media_items` UUID. */
    private string $otherArtistMediaItemId = '';

    /** The album under test, by `media_items` UUID. */
    private string $albumMediaItemId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the S147 music drill-down COUNT proof. Runs in CI.');

        $this->libraryId = Uuid::v4();
        $this->prefix = '!S147-' . substr(Uuid::v4(), 0, 8) . '-';

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();
        parent::tearDown();
    }

    /**
     * 🔴 MUTANT A: drop `AND al.media_item_id IS NOT NULL` from
     * {@see MusicLibraryService::countAlbumMediaItemsForArtist()} and this goes
     * RED — the artist would advertise 5 albums against a listing that can only
     * ever return 4.
     *
     * Asserted as a THREE-way identity (count === listing size === the number
     * actually minted), because that is the invariant the DLNA drill-down needs:
     * a `TotalMatches` a renderer cannot walk to is the S97 defect.
     */
    public function testTheAlbumCountSharesTheListingPredicateExactly(): void
    {
        $service = $this->service();

        $listed = $service->getAlbumMediaItemIdsForArtist($this->artistMediaItemId);
        $counted = $service->countAlbumMediaItemsForArtist($this->artistMediaItemId);

        $this->assertSame(
            self::MINTED_ALBUMS,
            count($listed),
            'the listing must skip the album whose media_items mint failed — there is no row to browse to',
        );
        $this->assertSame(
            self::MINTED_ALBUMS,
            $counted,
            sprintf(
                'the COUNT must carry `al.media_item_id IS NOT NULL` too. Without it this returns %d — '
                . 'an artist advertising more albums than its container can deliver, which is the S97 '
                . 'defect one level down.',
                self::MINTED_ALBUMS + self::UNMINTED_ALBUMS,
            ),
        );
        $this->assertSame(
            count($listed),
            $counted,
            'advertised count and deliverable list must agree — the S147 acceptance criterion',
        );
    }

    /**
     * The album COUNT is scoped to ITS artist: dropping `ar.media_item_id = ?`
     * (or joining the wrong way) makes this RED, because the fixture deliberately
     * seeds a second artist with a different number of albums.
     */
    public function testTheAlbumCountIsScopedToTheArtistItWasAskedAbout(): void
    {
        $service = $this->service();

        $this->assertSame(
            self::MINTED_ALBUMS,
            $service->countAlbumMediaItemsForArtist($this->artistMediaItemId),
        );
        $this->assertSame(
            self::OTHER_ARTIST_ALBUMS,
            $service->countAlbumMediaItemsForArtist($this->otherArtistMediaItemId),
            'a second artist in the same library must count only ITS OWN albums',
        );
        $this->assertSame(
            0,
            $service->countAlbumMediaItemsForArtist(Uuid::v4()),
            'an artist that does not exist has no albums, not all of them',
        );
    }

    /**
     * 🔴 MUTANT B: rewrite {@see MusicLibraryService::countTrackMediaItemsForAlbum()}'s
     * `WHERE` as `al.media_item_id = ? OR 1 = 1` — counting every track in the
     * library — and this goes RED. The fixture seeds strictly more tracks
     * elsewhere than on the album under test, so a library-wide count cannot
     * coincide with the right answer.
     */
    public function testTheTrackCountIsScopedToTheAlbumItWasAskedAbout(): void
    {
        $service = $this->service();

        $listed = $service->getTrackMediaItemIdsForAlbum($this->albumMediaItemId);
        $counted = $service->countTrackMediaItemsForAlbum($this->albumMediaItemId);

        $this->assertSame(self::TRACKS_ON_ALBUM, count($listed), 'the listing returns the album\'s tracks');
        $this->assertSame(
            self::TRACKS_ON_ALBUM,
            $counted,
            sprintf(
                'the COUNT must be scoped to this album. A predicate that leaks (or is dropped) counts '
                . 'the fixture\'s other %d tracks too, and the album advertises a total it cannot deliver.',
                self::TRACKS_ELSEWHERE,
            ),
        );
        $this->assertSame(count($listed), $counted, 'advertised count and deliverable list must agree');
        $this->assertSame(
            0,
            $service->countTrackMediaItemsForAlbum(Uuid::v4()),
            'an album that does not exist has no tracks, not the whole library\'s',
        );
    }

    /**
     * The same walk-with-`OFFSET` proof the video root got, on both music
     * drill-downs: the pages must tile the container exactly — the concatenation
     * equals the unpaged listing, nothing dropped, nothing repeated, and the walk
     * ends exactly at the advertised COUNT.
     *
     * The unit doubles cannot prove this half: they model `LIMIT`/`OFFSET` with
     * `array_slice()`, which is a total order by construction, so they pass even
     * against an `ORDER BY` with no PRIMARY KEY tiebreak at all.
     */
    public function testBothMusicDrillDownsPageExactlyToTheirAdvertisedCount(): void
    {
        $service = $this->service();

        $albums = $service->getAlbumMediaItemIdsForArtist($this->artistMediaItemId);
        $walkedAlbums = [];
        for ($offset = 0; $offset < $service->countAlbumMediaItemsForArtist($this->artistMediaItemId); $offset += 2) {
            foreach ($service->getAlbumMediaItemIdsForArtist($this->artistMediaItemId, 2, $offset) as $id) {
                $walkedAlbums[] = $id;
            }
        }

        $this->assertSame($albums, $walkedAlbums, 'the paged album walk must equal the unpaged listing exactly');
        $this->assertSame(
            count($walkedAlbums),
            count(array_unique($walkedAlbums)),
            'no album may appear on two pages',
        );

        $tracks = $service->getTrackMediaItemIdsForAlbum($this->albumMediaItemId);
        $walkedTracks = [];
        for ($offset = 0; $offset < $service->countTrackMediaItemsForAlbum($this->albumMediaItemId); $offset += 3) {
            foreach ($service->getTrackMediaItemIdsForAlbum($this->albumMediaItemId, 3, $offset) as $id) {
                $walkedTracks[] = $id;
            }
        }

        $this->assertSame($tracks, $walkedTracks, 'the paged track walk must equal the unpaged listing exactly');
        $this->assertSame(
            count($walkedTracks),
            count(array_unique($walkedTracks)),
            'no track may appear on two pages',
        );
    }

    /**
     * End to end through the DLNA bridge against real MySQL: what the drill-down
     * ADVERTISES equals what it DELIVERS, for both music container types.
     *
     * A service-level assertion alone would pass even if the bridge advertised
     * something else, so the acceptance criterion is asserted where a renderer
     * would actually read it.
     */
    public function testTheDlnaDrillDownAdvertisesWhatItCanDeliver(): void
    {
        $bridge = new LibraryBridge(
            new ItemRepository($this->db()),
            $this->createMock(HlsStreamer::class),
            null,
            $this->service(),
        );

        $albums = $bridge->getContainerChildren($this->artistMediaItemId, 'artist');
        $this->assertSame(
            self::MINTED_ALBUMS,
            $bridge->getContainerChildCount($this->artistMediaItemId, 'artist'),
            'the artist container advertises only the albums it can browse to',
        );
        $this->assertCount(self::MINTED_ALBUMS, $albums, 'and delivers exactly that many');

        $tracks = $bridge->getContainerChildren($this->albumMediaItemId, 'album');
        $this->assertSame(
            self::TRACKS_ON_ALBUM,
            $bridge->getContainerChildCount($this->albumMediaItemId, 'album'),
            'the album container advertises its own track count',
        );
        $this->assertCount(self::TRACKS_ON_ALBUM, $tracks, 'and delivers exactly that many');
    }

    private function service(): MusicLibraryService
    {
        return new MusicLibraryService($this->db(), $this->createMock(MusicLibraryScanner::class));
    }

    private function seedFixtures(): void
    {
        $db = $this->db();

        $db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, 'S147 Music Drill-Down Lib'],
        );

        $this->artistMediaItemId = $this->mediaItem('artist-under-test', 'artist');
        $artistId = $this->artist('Artist Under Test', $this->artistMediaItemId);

        $this->otherArtistMediaItemId = $this->mediaItem('other-artist', 'artist');
        $otherArtistId = $this->artist('Other Artist', $this->otherArtistMediaItemId);

        // The album under test, plus three more minted albums for the same artist.
        $this->albumMediaItemId = $this->mediaItem('album-under-test', 'album');
        $albumId = $this->album($artistId, 'Album Under Test', $this->albumMediaItemId, 1994);

        $mintedElsewhere = [];
        for ($i = 1; $i < self::MINTED_ALBUMS; $i++) {
            $mintedElsewhere[] = $this->album(
                $artistId,
                sprintf('Minted Album %d', $i),
                $this->mediaItem(sprintf('minted-album-%d', $i), 'album'),
                2000 + $i,
            );
        }

        // ⚠ The whole point of the fixture: an album whose `media_items` mint
        // failed. Schema-permitted (migration 065 leaves the column NULL-able),
        // and invisible to the LISTING — so it must be invisible to the COUNT.
        for ($i = 0; $i < self::UNMINTED_ALBUMS; $i++) {
            $mintedElsewhere[] = $this->album($artistId, sprintf('Unminted Album %d', $i), null, 2010 + $i);
        }

        for ($i = 0; $i < self::OTHER_ARTIST_ALBUMS; $i++) {
            $this->album(
                $otherArtistId,
                sprintf('Other Album %d', $i),
                $this->mediaItem(sprintf('other-album-%d', $i), 'album'),
                1980 + $i,
            );
        }

        for ($i = 0; $i < self::TRACKS_ON_ALBUM; $i++) {
            $this->track($albumId, $artistId, sprintf('Track %d', $i), $i + 1);
        }

        // Tracks that are NOT on the album under test, spread over its siblings —
        // strictly more of them than there are on it, so a count that forgets its
        // `WHERE` cannot accidentally agree.
        for ($i = 0; $i < self::TRACKS_ELSEWHERE; $i++) {
            $this->track(
                $mintedElsewhere[$i % count($mintedElsewhere)],
                $artistId,
                sprintf('Elsewhere Track %d', $i),
                $i + 1,
            );
        }
    }

    /**
     * `media_items.type` is a 13-member ENUM; `artist`, `album` and `track` are
     * all members (migrations 001 + 034).
     */
    private function mediaItem(string $name, string $type): string
    {
        $id = Uuid::v4();
        $this->db()->query(
            'INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, ?, ?)',
            [$id, $this->libraryId, $this->prefix . $name, $type, '/s147-music-drilldown/' . $id . '.flac'],
        );
        $this->mediaIds[] = $id;

        return $id;
    }

    private function artist(string $name, string $mediaItemId): int
    {
        $db = $this->db();
        $db->query(
            'INSERT INTO music_artists (media_item_id, name) VALUES (?, ?)',
            [$mediaItemId, $this->prefix . $name],
        );
        $id = $this->idOf('music_artists', 'media_item_id', $mediaItemId);
        $this->artistIds[] = $id;

        return $id;
    }

    private function album(int $artistId, string $title, ?string $mediaItemId, int $year): int
    {
        $db = $this->db();
        $title = $this->prefix . $title;

        $db->query(
            'INSERT INTO music_albums (media_item_id, artist_id, title, year, total_tracks) VALUES (?, ?, ?, ?, 0)',
            [$mediaItemId, $artistId, $title, $year],
        );

        return $mediaItemId === null
            ? $this->idOf('music_albums', 'title', $title)
            : $this->idOf('music_albums', 'media_item_id', $mediaItemId);
    }

    private function track(int $albumId, int $artistId, string $title, int $trackNumber): void
    {
        $this->db()->query(
            'INSERT INTO music_tracks (media_item_id, album_id, artist_id, title, track_number, disc_number,'
            . ' duration_secs) VALUES (?, ?, ?, ?, ?, 1, 180)',
            [
                $this->mediaItem(strtolower(str_replace(' ', '-', $title)), 'track'),
                $albumId,
                $artistId,
                $this->prefix . $title,
                $trackNumber,
            ],
        );
    }

    /**
     * @param 'music_artists'|'music_albums' $table
     * @param 'media_item_id'|'title' $column
     */
    private function idOf(string $table, string $column, string $value): int
    {
        // Both identifiers come from the closed literal sets in the signature —
        // no caller-supplied identifier is ever interpolated here.
        $rows = $this->db()->query(
            sprintf('SELECT id FROM %s WHERE %s = ?', $table, $column),
            [$value],
        );

        $this->assertIsArray($rows);
        $this->assertArrayHasKey(0, $rows, sprintf('the seeded %s row must exist', $table));
        $row = $rows[0];
        $this->assertIsArray($row);

        return (int) $row['id'];
    }

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        // music_albums / music_tracks cascade off music_artists.
        foreach ($this->artistIds as $artistId) {
            $db->query('DELETE FROM music_artists WHERE id = ?', [$artistId]);
        }
        foreach ($this->mediaIds as $id) {
            $db->query('DELETE FROM media_items WHERE id = ?', [$id]);
        }
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
    }

    private function db(): Connection
    {
        if ($this->db === null) {
            $this->fail('No database connection');
        }

        return $this->db;
    }
}
