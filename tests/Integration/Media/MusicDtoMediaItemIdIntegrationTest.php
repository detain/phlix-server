<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof that `media_item_id` survives the DB → DTO hop (S121).
 *
 * The unit twin ({@see \Phlix\Tests\Unit\Media\Music\MusicDtoMediaItemIdTest})
 * pins the coercion by feeding `fromRow()` a hand-built array. That test is only
 * as good as its assumption about what MySQL hands back for the column — and that
 * assumption is exactly what the original bug got wrong: `media_item_id` was
 * treated as an integer when it is a `CHAR(36)` UUID (migration 070). A mock or a
 * literal array cannot catch a wrong column type, which is the same class of miss
 * as the `al.name` / LiveTv `ResultSet` / metrics `ONLY_FULL_GROUP_BY` defects.
 *
 * So this test asserts the two halves against real MySQL:
 *   1. the column really is `char(36)` and `workerman/mysql` really hands it back
 *      as a PHP `string` (so `is_numeric()` can never be the right predicate), and
 *   2. a seeded UUID round-trips out through the DTO-returning reads, including
 *      {@see MusicLibraryService::getAllArtists()} — the LIVE read behind
 *      `GET /api/v1/music/artists`.
 *
 * A NULL `media_item_id` is seeded alongside, because the music scanner writes
 * NULL when its `createMediaItem()` mint fails and backfills the row later. That
 * null must stay null, not become `''`.
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the suite;
 * locally, with no reachable MySQL, it self-skips — the same `isMysqlReachable()`
 * guard {@see MusicTracksQueryIntegrationTest} uses. It does **not** copy that
 * sibling's second guard: see the comment in {@see setUp()} for why a
 * reachable-but-unusable database is raised rather than skipped.
 *
 * **On S120 (assertions swallowed by a `catch`).** This class DOES contain one
 * `try`/`catch` — around `ConnectionPool::init()` + `getConnection()` in
 * {@see setUp()} — so the "no try/catch anywhere" claim first made for this file was
 * wrong. The S120 conclusion is unaffected and was re-audited: **zero assertions
 * execute inside that block**, no assertion in this class runs inside a callback that
 * production invokes under `catch (\Throwable)`/`catch (\RuntimeException)`, and the
 * only closures in the read path under test are `array_map` shapers whose results are
 * asserted outside them. So no `ExpectationFailedException` can be swallowed and no
 * test here is vacuous — proven by mutation, not by inspection: planting the original
 * `is_numeric()` predicate reddens 2 of these 4 tests and planting a `''` fallback
 * reddens a 3rd, each with its named message.
 *
 * @covers \Phlix\Media\Music\MusicArtist
 * @covers \Phlix\Media\Music\MusicAlbum
 * @covers \Phlix\Media\Music\MusicTrack
 */
final class MusicDtoMediaItemIdIntegrationTest extends TestCase
{
    /** Page size used while paging {@see MusicLibraryService::getAllArtists()}. */
    private const PAGE_SIZE = 100;

    /** Hard ceiling on pages read, so a missing fixture row fails as an assertion. */
    private const MAX_PAGES = 200;

    private ?Connection $db = null;

    private string $libraryId = '';

    /**
     * Fixture-local `music_artists.name` prefix. `music_artists.name` is UNIQUE, so
     * every run needs its own namespace; the leading `!` collates ahead of every
     * digit and letter in `utf8mb4_unicode_ci`, so the fixture is normally on page
     * 1 of the unscoped listing. Correctness does not depend on that — the
     * collector pages.
     */
    private string $prefix = '';

    /** @var list<int> Seeded music_artists ids (cascade-deletes albums + tracks). */
    private array $artistIds = [];

    /** @var list<string> Seeded media_items ids. */
    private array $mediaIds = [];

    /** `media_items.id` linked from the seeded artist row. */
    private string $artistMediaItemId = '';

    /** `media_items.id` linked from the seeded album row. */
    private string $albumMediaItemId = '';

    /** `media_items.id` linked from the seeded track row — the playable id. */
    private string $trackMediaItemId = '';

    private int $artistId = 0;

    private int $albumId = 0;

    /** Artist seeded with a NULL `media_item_id` (the failed-mint shape). */
    private int $unmintedArtistId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf('No MySQL on %s:%d — skipping music DTO media_item_id test. Runs in CI.', $host, $port),
            );
        }

        // ⚠ Deliberately NOT `markTestSkipped()` here, which is what the other 22
        // `markTestSkipped`-inside-`catch` integration sites do (S121 review r1
        // finding 3, r2 findings 1/2/6/7, r3 finding 7 — 22 sites across 21 files,
        // brace-matched with token_get_all, not grepped; `PathHashIndexUsageTest` has
        // two. An earlier "23" here was one high, and S126's scope depends on the
        // number). `isMysqlReachable()` above has already proven a listener ACCEPTED a
        // TCP connection on $host:$port, so reaching the catch below does not mean "no
        // MySQL on this box" — it means whatever is on that port would not serve us.
        // Skipping that reports success without ever touching a database, in the one
        // environment (CI) where this test is the only thing proving the column type.
        // Genuine absence is still a skip, handled one block up. The other 22 sites
        // are deliberately untouched; unifying them is step S126.
        //
        // ⚠⚠ The `SELECT 1` is LOAD-BEARING, not a sanity check (r2 finding 1).
        // `config/database.php` resolves `pool_enabled = true` BY DEFAULT, and
        // `PooledMySQLConnection::__construct()` deliberately does NOT call
        // `parent::__construct()` — it opens no socket, leasing one lazily on the
        // first `query()`. So without a real round-trip inside this `try`, nothing
        // here could fail and the named exception below was dead code in exactly the
        // configuration CI and local dev use; only `DB_POOL_ENABLED=0` (which builds
        // a `PhlixMySQLConnection`, whose parent ctor connects eagerly) ever reached
        // it. Measured with a deliberately wrong password on a scratch MySQL:
        //
        //   without the round-trip: pool default → `PDOException … [1045] Access
        //     denied` escaping from seedFixtures(); pool 0 → named RuntimeException.
        //   with the round-trip:    BOTH modes → named RuntimeException.
        //
        // A healthy database is unaffected in either mode — `OK (4 tests, 141
        // assertions)` (117 before the identifier allow-list in `idOf()` added 24; r3
        // finding 2 caught this figure going stale inside the very comment that was
        // rewritten to stop stale claims) — so this cannot redden a healthy CI.
        try {
            ConnectionPool::init(dirname(__DIR__, 3) . '/config/database.php');
            $db = ConnectionPool::getConnection('mysql');
            // Forces the lazy pooled connection to open for real, and binds nothing.
            //
            // `SELECT` is chosen because it RETURNS ROWS, not because `query()`
            // requires it (r3 finding 3 — the older "query() must start with SELECT"
            // note here was wrong, and this same file pushes INSERT/DELETE through
            // `query()` below). What workerman/mysql actually does
            // (`vendor/workerman/mysql/src/Connection.php:1852-1869`): it ALWAYS
            // executes the statement, then switches on the first keyword only to pick
            // a return value — `select`/`show` → `fetchAll()`, `update`/`delete`/
            // `replace` → `rowCount()`, `insert` → `lastInsertId()` **only when
            // `rowCount() > 0`** (`:1861-1864`; a 0-row insert falls through to the
            // `return null` at `:1869`, so an `INSERT IGNORE` that hits a duplicate is
            // indistinguishable from a no-op — r4 finding 3), and **anything else →
            // `null`**. So the real, narrower constraint worth remembering is
            // that a statement outside that keyword set (`SET`, `CREATE`, `TRUNCATE`,
            // `BEGIN`, …) runs but is indistinguishable from a no-op by its return
            // value. A round-trip here would work with any statement; `SELECT 1` also
            // proves a result set came back, and is side-effect-free.
            $db->query('SELECT 1');
            $this->db = $db;
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf(
                    'Something on %s:%d accepted a TCP connection but no query could be run over it (%s). '
                    . 'That is NOT "no MySQL on this box", so it is reported instead of skipped. Most often '
                    . 'it is a DB_* / config problem — wrong credentials, or a database that does not exist '
                    . 'yet: check DB_USER/DB_PASSWORD/DB_DATABASE and run scripts/run-migrations.php. This '
                    . 'guard cannot tell that apart from a transient condition (a non-MySQL listener on the '
                    . 'port, or a connect timeout on a loaded box), so read the driver message above first.',
                    $host,
                    $port,
                    $e->getMessage(),
                ),
                0,
                $e,
            );
        }

        $this->assertNotNull($this->db);

        $this->libraryId = Uuid::v4();
        $this->prefix = '!S121-' . substr(Uuid::v4(), 0, 8) . '-';

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();
        parent::tearDown();
    }

    /**
     * The assumption the unit twin encodes, measured instead of assumed: the column
     * is `char(36)` in all three music tables, and a selected value arrives as a
     * PHP `string` for which `is_numeric()` is false.
     */
    public function testTheColumnIsChar36AndArrivesAsAPhpString(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $types = $db->query(
            'SELECT TABLE_NAME AS t, COLUMN_TYPE AS ct FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'media_item_id'"
            . " AND TABLE_NAME IN ('music_artists','music_albums','music_tracks')",
        );
        $this->assertIsArray($types);

        $seen = [];
        foreach ($types as $row) {
            $this->assertIsArray($row);
            $seen[(string) $row['t']] = strtolower((string) $row['ct']);
        }

        $this->assertSame(
            ['music_albums' => 'char(36)', 'music_artists' => 'char(36)', 'music_tracks' => 'char(36)'],
            $this->sortedByKey($seen),
            'media_item_id must be CHAR(36) on all three music tables (migration 070) — '
            . 'if this ever became an integer column the DTO coercion would have to change with it',
        );

        $rows = $db->query('SELECT media_item_id FROM music_artists WHERE id = ?', [$this->artistId]);
        $this->assertIsArray($rows);
        $this->assertArrayHasKey(0, $rows);
        $row = $rows[0];
        $this->assertIsArray($row);

        $this->assertIsString(
            $row['media_item_id'],
            'workerman/mysql must hand a CHAR(36) column back as a PHP string',
        );
        $this->assertFalse(
            is_numeric($row['media_item_id']),
            'is_numeric() is false for a real UUID — which is precisely why it was the wrong guard',
        );
    }

    /**
     * `getArtist()` / `getAlbum()` are the DTO-returning reads. The UUID must reach
     * `MusicArtist`, `MusicAlbum` AND the embedded `MusicTrack` list.
     */
    public function testTheDtoReadsCarryTheUuidOutOfRealMysql(): void
    {
        $service = $this->service();

        $artist = $service->getArtist($this->artistId);
        $this->assertNotNull($artist, 'the seeded artist must be readable');
        $this->assertSame(
            $this->artistMediaItemId,
            $artist->mediaItemId,
            'MusicArtist::fromRow() dropped the CHAR(36) media_item_id read from real MySQL',
        );

        $album = $service->getAlbum($this->albumId);
        $this->assertNotNull($album, 'the seeded album must be readable');
        $this->assertSame(
            $this->albumMediaItemId,
            $album->album->mediaItemId,
            'MusicAlbum::fromRow() dropped the CHAR(36) media_item_id read from real MySQL',
        );
        $this->assertNotNull($album->artist);
        $this->assertSame(
            $this->artistMediaItemId,
            $album->artist->mediaItemId,
            'the nested MusicArtist dropped its media_item_id',
        );

        $this->assertCount(1, $album->tracks, 'the fixture seeds exactly one track');
        $this->assertSame(
            $this->trackMediaItemId,
            $album->tracks[0]->mediaItemId,
            'MusicTrack::fromRow() dropped the media_item_id that IS the playable track id — '
            . 'this is the defect MusicLibraryService documented and S121 fixed',
        );
    }

    /**
     * The live path. `getAllArtists()` builds a `MusicArtist` on every served
     * `GET /api/v1/music/artists` request, so this is the one that is not a dead DTO.
     */
    public function testGetAllArtistsCarriesTheUuidOnTheLiveReadPath(): void
    {
        $mine = $this->collectFixtureArtists();

        $this->assertArrayHasKey(
            $this->prefix . 'Minted Artist',
            $mine,
            'the seeded artist was not found in any page of getAllArtists()',
        );
        $this->assertSame(
            $this->artistMediaItemId,
            $mine[$this->prefix . 'Minted Artist'],
            'getAllArtists() — the LIVE /api/v1/music/artists read — dropped the media_item_id UUID',
        );
    }

    /**
     * A genuinely NULL `media_item_id` (the scanner's failed-mint / backfill-later
     * shape) must stay `null`. `''` would read as a present-but-unusable id.
     */
    public function testANullMediaItemIdStaysNullAndDoesNotBecomeAnEmptyString(): void
    {
        $unminted = $this->service()->getArtist($this->unmintedArtistId);

        $this->assertNotNull($unminted, 'the unminted artist row must be readable');
        $this->assertNull(
            $unminted->mediaItemId,
            'a NULL media_item_id must arrive as null, not "" — the scanner backfills these rows later',
        );

        $mine = $this->collectFixtureArtists();
        $this->assertArrayHasKey($this->prefix . 'Unminted Artist', $mine);
        $this->assertNull(
            $mine[$this->prefix . 'Unminted Artist'],
            'getAllArtists() must keep an unminted artist null rather than coercing it to ""',
        );
    }

    /**
     * Pages {@see MusicLibraryService::getAllArtists()} until both fixture artists
     * are collected, so the assertions never depend on the unscoped listing's page 1.
     *
     * @return array<string, string|null> name => media_item_id
     */
    private function collectFixtureArtists(): array
    {
        $service = $this->service();
        $found = [];

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $batch = $service->getAllArtists(self::PAGE_SIZE, $page * self::PAGE_SIZE);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $withAlbums) {
                if (str_starts_with($withAlbums->artist->name, $this->prefix)) {
                    $found[$withAlbums->artist->name] = $withAlbums->artist->mediaItemId;
                }
            }
            if (count($found) === 2) {
                break;
            }
        }

        return $found;
    }

    private function service(): MusicLibraryService
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return new MusicLibraryService($db, $this->createMock(MusicLibraryScanner::class));
    }

    /**
     * @param array<string, string> $map
     * @return array<string, string>
     */
    private function sortedByKey(array $map): array
    {
        ksort($map);

        return $map;
    }

    private function seedFixtures(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, 'S121 Music DTO IT Library'],
        );

        // media_item_id is UNIQUE on each music table, so each row needs its own.
        $this->artistMediaItemId = $this->mediaItem('artist-artwork', 'artist');
        $this->albumMediaItemId = $this->mediaItem('album-artwork', 'album');
        $this->trackMediaItemId = $this->mediaItem('the-track', 'track');

        $this->artistId = $this->artist('Minted Artist', $this->artistMediaItemId);
        $this->unmintedArtistId = $this->artist('Unminted Artist', null);

        $db->query(
            'INSERT INTO music_albums (media_item_id, artist_id, title, year, total_tracks)'
            . ' VALUES (?, ?, ?, ?, ?)',
            [$this->albumMediaItemId, $this->artistId, 'S121 Album', 1994, 1],
        );
        $this->albumId = $this->idOf('music_albums', 'media_item_id', $this->albumMediaItemId);

        $db->query(
            'INSERT INTO music_tracks (media_item_id, album_id, artist_id, title, track_number, disc_number,'
            . ' duration_secs) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->trackMediaItemId, $this->albumId, $this->artistId, 'S121 Track', 1, 1, 302],
        );
    }

    /**
     * `media_items.type` is a 13-member ENUM; `artist`, `album` and `track` are all
     * members (migration 001 + 034) and `photo`/`image` are irrelevant here.
     */
    private function mediaItem(string $name, string $type): string
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $id = Uuid::v4();
        $db->query(
            'INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, ?, ?)',
            [$id, $this->libraryId, $this->prefix . $name, $type, '/s121-music-dto/' . $id . '.flac'],
        );
        $this->mediaIds[] = $id;

        return $id;
    }

    private function artist(string $name, ?string $mediaItemId): int
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            'INSERT INTO music_artists (media_item_id, name) VALUES (?, ?)',
            [$mediaItemId, $this->prefix . $name],
        );
        $id = $this->idOf('music_artists', 'name', $this->prefix . $name);
        $this->artistIds[] = $id;

        return $id;
    }

    /**
     * Resolves an AUTO_INCREMENT id by unique lookup. `LAST_INSERT_ID()` is not
     * safe here: the pooled connection may hand a different socket to the
     * follow-up query.
     *
     * `$table`/`$column` are SQL **identifiers**, which cannot be bound as
     * parameters, so they are interpolated — and therefore allow-listed below
     * (S121 review r2 finding 10). Every current caller passes a hardcoded literal
     * and the *value* is always bound, so this is inert today; the allow-list exists
     * so it stays inert if this helper is ever copied or handed external input.
     *
     * The allow-lists are **exactly** the two call sites and nothing more (r3 finding
     * 6 — they previously also admitted `music_tracks`/`title`, which nobody passes;
     * and because `phpstan.neon.dist` is `paths: [src]`, the `@param` unions below are
     * never checked at level 9, so the runtime allow-list is the only enforcement —
     * step **S128**). A new caller must widen both deliberately.
     *
     * @param 'music_albums'|'music_artists' $table
     * @param 'media_item_id'|'name'         $column
     */
    private function idOf(string $table, string $column, string $value): int
    {
        $this->assertContains(
            $table,
            ['music_artists', 'music_albums'],
            'idOf() interpolates $table into SQL, so it accepts only the two tables its callers pass',
        );
        $this->assertContains(
            $column,
            ['media_item_id', 'name'],
            'idOf() interpolates $column into SQL, so it accepts only the two columns its callers pass',
        );

        $db = $this->db;
        $this->assertNotNull($db);

        $rows = $db->query(sprintf('SELECT id FROM %s WHERE %s = ? LIMIT 1', $table, $column), [$value]);
        $this->assertIsArray($rows);
        $this->assertArrayHasKey(0, $rows);
        $row = $rows[0];
        $this->assertIsArray($row);
        $this->assertArrayHasKey('id', $row);

        return (int) $row['id'];
    }

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        // music_albums/music_tracks cascade off music_artists.
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

    private function isMysqlReachable(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }
}
