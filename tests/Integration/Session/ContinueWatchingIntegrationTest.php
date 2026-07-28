<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Session;

use Phlix\Common\Uuid;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof that {@see PlaybackController::getContinueWatching()} shapes the
 * continue-watching rail correctly when driven against live MySQL.
 *
 * The unit tests {@see \Phlix\Tests\Unit\Session\PlaybackControllerTest} mock the
 * connection and hand the mapper hand-built rows — so they validate the PHP
 * shaping but NOT the two-level `LEFT JOIN` (season parent + series grandparent),
 * the `ROW_NUMBER() OVER (PARTITION BY …)` dedup, or the `mi.parent_id` /
 * `metadata_json` columns the SQL actually projects. WS-A of plan_posters.md
 * explicitly wanted a real-DB test here because mock-DB suites have hidden real
 * query bugs before (e.g. the metrics ONLY_FULL_GROUP_BY 1055 that only surfaced
 * against MySQL — see {@see \Phlix\Tests\Integration\Stats\MetricsReadQueriesTest}).
 *
 * It seeds a full series → season → episode hierarchy (episode poster = its TMDB
 * still) plus a standalone movie, records playback progress for the episode (via
 * TWO playback_state rows — a newer and an older one, under TWO DIFFERENT
 * SESSIONS of the same user — so the `ROW_NUMBER()`
 * dedup has real duplicates to collapse) and the movie, then asserts the shaped
 * output: exactly ONE row survives for the episode and it is the NEWER playback
 * (proving the `PARTITION BY ps.media_item_id ORDER BY ps.updated_at DESC, ps.id
 * DESC` dedup), the episode row surfaces the SERIES
 *
 * ⚠ S156 — WHY THE TWO DUPLICATE ROWS LIVE IN DIFFERENT SESSIONS. Migration
 * `097_playback_state_unique_key.sql` puts
 * `UNIQUE KEY uq_playback_state_session_media (session_id, media_item_id)` into
 * the migration chain, so two rows sharing ONE session and one media item are
 * now a 1062 — the fixture could no longer be seeded, and the whole file would
 * have died in `setUp()`. Splitting the pair across two sessions of the same
 * user keeps the duplicate REAL rather than synthetic: `getContinueWatching()`
 * partitions by `ps.media_item_id` alone and scopes by `s_session.user_id`, so
 * one user watching the same episode on two devices is exactly the duplicate the
 * `ROW_NUMBER()` dedup still has to collapse after 097. The unique key cannot
 * subsume it — it constrains a single session only.
 *
 * poster at the TOP LEVEL (the /app MediaCard bug), a positive top-level runtime,
 * id == media item id, a real parent_id, and the retained playback fields; the
 * movie keeps its own poster. CI applies all migrations to the `phlix_test` MySQL
 * service before the suite; locally, with no reachable MySQL, it self-skips —
 * the same guard {@see \Phlix\Tests\Integration\Stats\MetricsReadQueriesTest} uses.
 *
 * @covers \Phlix\Session\PlaybackController
 */
final class ContinueWatchingIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $libraryId = '';
    private string $userId = '';
    private string $sessionId = '';
    /**
     * A SECOND session for the same user. The older/stale episode playback row
     * lives here so the (session_id, media_item_id) unique key added by migration
     * 097 is not violated while the ROW_NUMBER dedup still has two rows to
     * collapse. See the class docblock.
     */
    private string $staleSessionId = '';
    private string $seriesId = '';
    private string $seasonId = '';
    private string $episodeId = '';
    private string $movieId = '';
    private string $olderEpisodePlaybackId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping continue-watching integration test. Runs in CI.');

        $this->assertNotNull($this->db);

        $this->libraryId = Uuid::v4();
        $this->userId = Uuid::v4();
        $this->sessionId = Uuid::v4();
        $this->staleSessionId = Uuid::v4();
        $this->seriesId = Uuid::v4();
        $this->seasonId = Uuid::v4();
        $this->episodeId = Uuid::v4();
        $this->movieId = Uuid::v4();
        $this->olderEpisodePlaybackId = Uuid::v4();

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();
        parent::tearDown();
    }

    /**
     * The episode CW row must carry the SERIES poster at the top level (the SPA
     * MediaCard reads top-level `poster_url`), a positive top-level `runtime`,
     * id == media item id, a real parent_id, and the retained playback fields.
     * The two seeded episode playback_state rows must be deduped to exactly one
     * (the newer), and the movie must keep its own poster.
     */
    public function testEpisodeRowSurfacesSeriesPosterAndRuntimeAgainstRealDb(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $controller = new PlaybackController($db, $this->createMock(SessionManager::class));

        $result = $controller->getContinueWatching($this->userId, 10);

        $episode = $this->rowFor($result, $this->episodeId);
        $movie = $this->rowFor($result, $this->movieId);
        $this->assertNotNull($episode, 'episode continue-watching row missing');
        $this->assertNotNull($movie, 'movie continue-watching row missing');

        // The episode was seeded with TWO playback_state rows; ROW_NUMBER dedup
        // (PARTITION BY media_item_id) must collapse them to EXACTLY ONE.
        $episodeRows = array_values(array_filter(
            $result,
            fn (array $row): bool => ($row['media_item_id'] ?? null) === $this->episodeId,
        ));
        $this->assertCount(
            1,
            $episodeRows,
            'ROW_NUMBER dedup must collapse the two seeded episode playback_state rows to one',
        );

        // THE /app bug: top-level poster_url must be the resolved SERIES poster,
        // not the episode's stored TMDB still.
        $this->assertSame('/series/poster.jpg', $episode['poster_url'] ?? null);
        $episodeMeta = $episode['metadata'] ?? null;
        $this->assertIsArray($episodeMeta);
        $this->assertSame('/series/poster.jpg', $episodeMeta['poster_url'] ?? null);
        // The nested metadata.poster_url must be re-minted to match the top-level
        // (shaped) value — the console reads the NESTED field and 401s on a stale
        // scan-time signature; here it must equal the fresh top-level poster_url.
        $this->assertSame($episode['poster_url'] ?? null, $episodeMeta['poster_url'] ?? null);

        // Top-level runtime (minutes) drives the SPA progress bar; must be > 0.
        $episodeRuntime = $episode['runtime'] ?? null;
        $this->assertIsInt($episodeRuntime);
        $this->assertGreaterThan(0, $episodeRuntime);
        $this->assertSame(42, $episodeRuntime);

        // id == media item id (not playback_state id); parent_id resolved from the
        // now-selected mi.parent_id column.
        $this->assertSame($this->episodeId, $episode['id'] ?? null);
        $this->assertSame($this->episodeId, $episode['media_item_id'] ?? null);
        $this->assertSame($this->seasonId, $episode['parent_id'] ?? null);

        // Retained playback fields (raw ticks for useResumeSync). position_ticks ==
        // 1000 (the NEWER row) — not 500 (the older, collapsed row) — proves the
        // dedup kept the most recent playback_state per media item.
        $this->assertSame(1000, $episode['position_ticks'] ?? null);
        $this->assertSame(100000, $episode['duration_ticks'] ?? null);

        // Movie keeps its own poster — series/season resolution never touches it.
        $this->assertSame('/movies/big.jpg', $movie['poster_url'] ?? null);
        $this->assertSame($this->movieId, $movie['id'] ?? null);
        $this->assertSame(120, $movie['runtime'] ?? null);
    }

    private function seedFixtures(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'series', '[]')",
            [$this->libraryId, 'CW IT Library'],
        );
        $db->query(
            "INSERT INTO users (id, username, email, password_hash)
             VALUES (?, ?, ?, ?)",
            [
                $this->userId,
                'cw-it-' . substr($this->userId, 0, 8),
                'cw-it-' . substr($this->userId, 0, 8) . '@example.test',
                'x',
            ],
        );

        $this->insertMediaItem($this->seriesId, null, 'series', 'CW IT Series', [
            'poster_url' => '/series/poster.jpg',
        ]);
        $this->insertMediaItem($this->seasonId, $this->seriesId, 'season', 'Season 1', [
            'poster_url' => '/season/poster.jpg',
            'season' => 1,
        ]);
        // Episode's stored poster IS its TMDB still — the wrong image for the rail.
        $this->insertMediaItem($this->episodeId, $this->seasonId, 'episode', 'S01E01', [
            'poster_url' => '/stills/ep01.jpg',
            'still_url' => '/stills/ep01.jpg',
            'season' => 1,
            'episode' => 1,
            'runtime' => 42,
        ]);
        $this->insertMediaItem($this->movieId, null, 'movie', 'Big Movie', [
            'poster_url' => '/movies/big.jpg',
            'runtime' => 120,
        ]);

        $db->query(
            "INSERT INTO sessions (id, user_id, device_id) VALUES (?, ?, ?)",
            [$this->sessionId, $this->userId, 'cw-it-device'],
        );
        // A SECOND device/session for the SAME user — the shape the unique key
        // added by migration 097 deliberately does not (and must not) prevent.
        $db->query(
            "INSERT INTO sessions (id, user_id, device_id) VALUES (?, ?, ?)",
            [$this->staleSessionId, $this->userId, 'cw-it-device-2'],
        );

        // In-progress playback (< 95%) for both the episode and the movie.
        // The episode gets TWO playback_state rows so the read query's
        // ROW_NUMBER() OVER (PARTITION BY ps.media_item_id ORDER BY
        // ps.updated_at DESC, ps.id DESC) dedup is genuinely exercised — the
        // NEWER row (position_ticks = 1000, default updated_at = NOW()) must
        // win and the OLDER row (position_ticks = 500, an explicitly stale
        // updated_at, on the OTHER session) must be collapsed away.
        $this->insertPlayback($this->episodeId, 1000, 100000);
        $this->insertPlayback(
            $this->episodeId,
            500,
            100000,
            $this->olderEpisodePlaybackId,
            '2020-01-01 00:00:00',
            $this->staleSessionId,
        );
        $this->insertPlayback($this->movieId, 5000, 100000);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function insertMediaItem(string $id, ?string $parentId, string $type, string $name, array $metadata): void
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $db->query(
            "INSERT INTO media_items (id, library_id, parent_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $this->libraryId,
                $parentId,
                $name,
                $type,
                '/cw-it/' . $id . '.mkv',
                (string) json_encode($metadata),
            ],
        );
    }

    private function insertPlayback(
        string $mediaItemId,
        int $position,
        int $duration,
        ?string $id = null,
        ?string $updatedAt = null,
        ?string $sessionId = null,
    ): void {
        $db = $this->db;
        $this->assertNotNull($db);
        $id ??= Uuid::v4();
        // `$sessionId` exists so a second row for the SAME media item can be
        // seeded under a different session of the same user — the only duplicate
        // shape that survives migration 097's
        // `uq_playback_state_session_media (session_id, media_item_id)`.
        $sessionId ??= $this->sessionId;
        if ($updatedAt === null) {
            $db->query(
                "INSERT INTO playback_state
                    (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
                 VALUES (?, ?, ?, ?, ?, 'playing')",
                [$id, $sessionId, $mediaItemId, $position, $duration],
            );

            return;
        }
        // Explicit updated_at lets a fixture seed a deliberately STALE row so the
        // ROW_NUMBER dedup's `ORDER BY updated_at DESC, id DESC` has a real ordering
        // to resolve.
        $db->query(
            "INSERT INTO playback_state
                (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status, updated_at)
             VALUES (?, ?, ?, ?, ?, 'playing', ?)",
            [$id, $sessionId, $mediaItemId, $position, $duration, $updatedAt],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function rowFor(array $rows, string $mediaItemId): ?array
    {
        foreach ($rows as $row) {
            if (($row['media_item_id'] ?? null) === $mediaItemId) {
                return $row;
            }
        }
        return null;
    }

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }
        // Child-first (FKs cascade, but be explicit and id-scoped so a shared test
        // DB is left untouched apart from these rows).
        $db->query('DELETE FROM playback_state WHERE session_id = ?', [$this->sessionId]);
        if ($this->staleSessionId !== '') {
            $db->query('DELETE FROM playback_state WHERE session_id = ?', [$this->staleSessionId]);
        }
        // Belt-and-braces id-scoped removal of the second (older) episode row.
        if ($this->olderEpisodePlaybackId !== '') {
            $db->query('DELETE FROM playback_state WHERE id = ?', [$this->olderEpisodePlaybackId]);
        }
        $db->query('DELETE FROM sessions WHERE id = ?', [$this->sessionId]);
        if ($this->staleSessionId !== '') {
            $db->query('DELETE FROM sessions WHERE id = ?', [$this->staleSessionId]);
        }
        foreach ([$this->episodeId, $this->seasonId, $this->seriesId, $this->movieId] as $id) {
            if ($id !== '') {
                $db->query('DELETE FROM media_items WHERE id = ?', [$id]);
            }
        }
        if ($this->userId !== '') {
            $db->query('DELETE FROM users WHERE id = ?', [$this->userId]);
        }
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
    }
}
