<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\WatchHistory;
use Phlix\Common\Uuid;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof that {@see WatchHistory::getNextUp()} resolves the correct
 * next-unwatched episode per started series against live MySQL (S36 ·
 * updates.md #43).
 *
 * Mock-DB tests have repeatedly hidden real query bugs in this repo (LiveTv
 * RowQuery/ResultSet, metrics ONLY_FULL_GROUP_BY / GROUP BY alias) — the
 * getNextUp aggregation leans on two window-function ({@see WatchHistory} Query
 * A/B) queries plus the `episode → season → series` `parent_id` chain and the
 * `playback_state`-derived watch state, none of which a hand-fed mock exercises.
 * The pure ordering/selection logic is unit-tested separately in
 * {@see \Phlix\Tests\Unit\Media\Library\NextUpSelectorTest}.
 *
 * The fixture seeds a multi-series watch history covering every S36 edge case:
 *  - Series A (Binge): S1 eps 1-3 watched, ep4 fresh          → next = ep4
 *  - Series B (SeasonBoundary): S1 e1-e2 watched, S2 fresh     → next = S2E1
 *  - Series C (SingleSeason): S1 e1 watched, e2 fresh          → next = e2
 *  - Series D (Finale): S1 e1-e2 both watched                  → excluded (no next)
 *  - Series E (Specials): S0 special watched, S1 e1-e2 fresh   → next = S1E1
 *  - Series F (MissingNum): S1 e1-e2 watched, two number-less  → next = "Alpha"
 * plus a stand-alone movie (must never appear). Recency (explicit playback
 * `updated_at`) orders the started series A > B > C > E > F > D so the returned
 * order is asserted too.
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the suite;
 * locally, with no reachable MySQL, it self-skips — the same guard
 * {@see \Phlix\Tests\Integration\Session\ContinueWatchingIntegrationTest} uses.
 *
 * @covers \Phlix\Auth\WatchHistory
 */
final class NextUpIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $libraryId = '';
    private string $userId = '';
    private string $profileId = '';
    private string $sessionId = '';
    private string $movieId = '';

    /** @var list<string> Every seeded media_items id, in creation order (parents first). */
    private array $mediaIds = [];

    /** @var array<string, string> Human label → media_items id, for assertions. */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping Next-Up integration test. Runs in CI.');

        $this->assertNotNull($this->db);

        $this->libraryId = Uuid::v4();
        $this->userId = Uuid::v4();
        $this->profileId = Uuid::v4();
        $this->sessionId = Uuid::v4();
        $this->movieId = Uuid::v4();

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();
        parent::tearDown();
    }

    /**
     * getNextUp must return one next-episode pick per started series, correctly
     * ordered by recency, with the right episode resolved for every S36 edge
     * case, and must exclude finished series + never surface a Special.
     */
    public function testNextUpResolvesCorrectPicksAgainstRealDb(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $watchHistory = new WatchHistory($db);
        $result = $watchHistory->getNextUp($this->profileId, 20);

        // Series order by recency (A newest ... F oldest; D excluded).
        $seriesOrder = array_map(
            static fn (array $row): mixed => $row['series_id'] ?? null,
            $result,
        );
        $this->assertSame(
            [
                $this->ids['seriesA'],
                $this->ids['seriesB'],
                $this->ids['seriesC'],
                $this->ids['seriesE'],
                $this->ids['seriesF'],
            ],
            $seriesOrder,
            'Next-Up series must be ordered by most-recent playback recency, with the finished series excluded',
        );

        // Binge skip-ahead: watched eps 1-3 → next is ep4 (not ep2).
        $a = $this->pick($result, $this->ids['seriesA']);
        $this->assertSame($this->ids['A_ep4'], $a['media_item_id'] ?? null);
        $this->assertSame($this->ids['A_ep4'], $a['id'] ?? null);
        $this->assertSame(1, $a['season_number'] ?? null);
        $this->assertSame(4, $a['episode_number'] ?? null);

        // Last episode of a season → first episode of the next numbered season.
        $b = $this->pick($result, $this->ids['seriesB']);
        $this->assertSame($this->ids['B_s2e1'], $b['media_item_id'] ?? null);
        $this->assertSame(2, $b['season_number'] ?? null);
        $this->assertSame(1, $b['episode_number'] ?? null);

        // Single-season series.
        $c = $this->pick($result, $this->ids['seriesC']);
        $this->assertSame($this->ids['C_e2'], $c['media_item_id'] ?? null);

        // Specials excluded: the watched Special is the most-recent touch, but the
        // pick is the first NUMBERED episode, never the Special.
        $e = $this->pick($result, $this->ids['seriesE']);
        $this->assertSame($this->ids['E_s1e1'], $e['media_item_id'] ?? null);
        $this->assertSame(1, $e['season_number'] ?? null);

        // Missing episode_number sorts last: eps 1-2 watched → next is the first
        // number-less episode, ordered by title ("Alpha" before "Bravo").
        $f = $this->pick($result, $this->ids['seriesF']);
        $this->assertSame($this->ids['F_alpha'], $f['media_item_id'] ?? null);
        $this->assertArrayHasKey('episode_number', $f);
        $this->assertNull($f['episode_number']);

        // Finished series (finale watched) contributes nothing.
        $this->assertNull(
            $this->pick($result, $this->ids['seriesD']),
            'A series whose finale is already watched must be excluded from Next Up',
        );

        // A Special must never be returned as a "next" pick.
        foreach ($result as $row) {
            $this->assertNotSame(0, $row['season_number'] ?? null, 'Specials (season 0) must never be a Next-Up pick');
            // Every pick is a fresh episode → no resume position, CW-shaped keys present.
            $this->assertSame(0, $row['position_ticks'] ?? null);
            $this->assertSame(0, $row['duration_ticks'] ?? null);
            $this->assertArrayHasKey('media_item_id', $row);
            $this->assertArrayHasKey('series_id', $row);
            $this->assertArrayHasKey('series_name', $row);
            // The stand-alone movie must never surface here.
            $this->assertNotSame($this->movieId, $row['media_item_id'] ?? null);
        }

        // Episode cards resolve the SERIES poster (the episode's stored poster is a
        // TMDB still) — mirrors the Continue-Watching rail.
        $this->assertSame('/series/A.jpg', $a['poster_url'] ?? null);
        $this->assertSame('/series/B.jpg', $b['poster_url'] ?? null);
    }

    public function testLimitIsRespectedAndClamped(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $watchHistory = new WatchHistory($db);

        // Only the two most-recent started series (A, B).
        $limited = $watchHistory->getNextUp($this->profileId, 2);
        $this->assertCount(2, $limited);
        $this->assertSame($this->ids['seriesA'], $limited[0]['series_id'] ?? null);
        $this->assertSame($this->ids['seriesB'], $limited[1]['series_id'] ?? null);

        // A non-positive limit clamps to >= 1 (never an unbounded / invalid query).
        $clamped = $watchHistory->getNextUp($this->profileId, 0);
        $this->assertCount(1, $clamped);
    }

    private function seedFixtures(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'series', '[]')",
            [$this->libraryId, 'NextUp IT Library'],
        );
        $db->query(
            "INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)",
            [
                $this->userId,
                'nu-it-' . substr($this->userId, 0, 8),
                'nu-it-' . substr($this->userId, 0, 8) . '@example.test',
                'x',
            ],
        );
        // Active profile — getNextUp resolves profileId → userId via user_profiles.
        $db->query(
            "INSERT INTO user_profiles (id, user_id, name, is_active) VALUES (?, ?, ?, TRUE)",
            [$this->profileId, $this->userId, 'NextUp IT Profile'],
        );
        $db->query(
            "INSERT INTO sessions (id, user_id, device_id) VALUES (?, ?, ?)",
            [$this->sessionId, $this->userId, 'nu-it-device'],
        );

        // Stand-alone movie — must never appear in Next Up.
        $this->movieId = $this->media(null, 'movie', 'Big Movie', ['poster_url' => '/movies/big.jpg']);
        $this->insertPlayback($this->movieId, 5000, 100000, 'playing', '2026-02-01 00:00:00');

        // ---- Series A (Binge): eps 1-3 watched, ep4 fresh → next ep4 ----
        $seriesA = $this->series('seriesA', 'A', '/series/A.jpg');
        $seasonA = $this->season($seriesA, 1);
        $this->ids['A_ep1'] = $this->episode($seasonA, 1, 1);
        $this->ids['A_ep2'] = $this->episode($seasonA, 1, 2);
        $this->ids['A_ep3'] = $this->episode($seasonA, 1, 3);
        $this->ids['A_ep4'] = $this->episode($seasonA, 1, 4);
        $this->watched($this->ids['A_ep1'], '2026-01-06 01:00:00');
        // ep2 watched via the >= 95% rule (not the stop-at-0 signal).
        $this->insertPlayback($this->ids['A_ep2'], 96000, 100000, 'playing', '2026-01-06 02:00:00');
        $this->watched($this->ids['A_ep3'], '2026-01-06 03:00:00'); // most-recent for A

        // ---- Series B (SeasonBoundary): S1 watched, S2 fresh → next S2E1 ----
        $seriesB = $this->series('seriesB', 'B', '/series/B.jpg');
        $seasonB1 = $this->season($seriesB, 1);
        $seasonB2 = $this->season($seriesB, 2);
        $this->ids['B_s1e1'] = $this->episode($seasonB1, 1, 1);
        $this->ids['B_s1e2'] = $this->episode($seasonB1, 1, 2);
        $this->ids['B_s2e1'] = $this->episode($seasonB2, 2, 1);
        $this->ids['B_s2e2'] = $this->episode($seasonB2, 2, 2);
        $this->watched($this->ids['B_s1e1'], '2026-01-05 01:00:00');
        $this->watched($this->ids['B_s1e2'], '2026-01-05 02:00:00'); // most-recent for B

        // ---- Series C (SingleSeason): e1 watched, e2 fresh → next e2 ----
        $seriesC = $this->series('seriesC', 'C', '/series/C.jpg');
        $seasonC = $this->season($seriesC, 1);
        $this->ids['C_e1'] = $this->episode($seasonC, 1, 1);
        $this->ids['C_e2'] = $this->episode($seasonC, 1, 2);
        $this->watched($this->ids['C_e1'], '2026-01-04 01:00:00'); // most-recent for C

        // ---- Series D (Finale): e1-e2 both watched → excluded ----
        $seriesD = $this->series('seriesD', 'D', '/series/D.jpg');
        $seasonD = $this->season($seriesD, 1);
        $this->ids['D_e1'] = $this->episode($seasonD, 1, 1);
        $this->ids['D_e2'] = $this->episode($seasonD, 1, 2);
        $this->watched($this->ids['D_e1'], '2026-01-01 01:00:00');
        $this->watched($this->ids['D_e2'], '2026-01-01 02:00:00'); // oldest; finale watched

        // ---- Series E (Specials): S0 special watched, S1 fresh → next S1E1 ----
        $seriesE = $this->series('seriesE', 'E', '/series/E.jpg');
        $seasonE0 = $this->season($seriesE, 0);
        $seasonE1 = $this->season($seriesE, 1);
        $this->ids['E_special'] = $this->episode($seasonE0, 0, 1);
        $this->ids['E_s1e1'] = $this->episode($seasonE1, 1, 1);
        $this->ids['E_s1e2'] = $this->episode($seasonE1, 1, 2);
        $this->watched($this->ids['E_special'], '2026-01-03 01:00:00'); // most-recent for E

        // ---- Series F (MissingNum): e1-e2 watched, two number-less fresh ----
        $seriesF = $this->series('seriesF', 'F', '/series/F.jpg');
        $seasonF = $this->season($seriesF, 1);
        $this->ids['F_e1'] = $this->episode($seasonF, 1, 1);
        $this->ids['F_e2'] = $this->episode($seasonF, 1, 2);
        // Number-less episodes (missing `episode` in metadata) sort last, by title.
        $this->ids['F_bravo'] = $this->episodeNamed($seasonF, 1, null, 'Bravo');
        $this->ids['F_alpha'] = $this->episodeNamed($seasonF, 1, null, 'Alpha');
        $this->watched($this->ids['F_e1'], '2026-01-02 01:00:00');
        $this->watched($this->ids['F_e2'], '2026-01-02 02:00:00'); // most-recent for F
    }

    /**
     * Insert a series row + record its id under $label.
     */
    private function series(string $label, string $name, string $poster): string
    {
        $id = $this->media(null, 'series', 'NU Series ' . $name, ['poster_url' => $poster]);
        $this->ids[$label] = $id;
        return $id;
    }

    private function season(string $seriesId, int $number): string
    {
        return $this->media($seriesId, 'season', 'Season ' . $number, [
            'poster_url' => '/season/' . $number . '.jpg',
            'season' => $number,
        ]);
    }

    private function episode(string $seasonId, int $season, int $episode): string
    {
        return $this->media($seasonId, 'episode', sprintf('S%02dE%02d', $season, $episode), [
            'poster_url' => '/stills/' . Uuid::v4() . '.jpg',
            'still_url' => '/stills/ep.jpg',
            'season' => $season,
            'episode' => $episode,
            'runtime' => 42,
        ]);
    }

    /**
     * An episode with a title but NO episode number in metadata (sorts last).
     */
    private function episodeNamed(string $seasonId, int $season, ?int $episode, string $title): string
    {
        $meta = [
            'poster_url' => '/stills/' . Uuid::v4() . '.jpg',
            'season' => $season,
            'episode_title' => $title,
            'runtime' => 42,
        ];
        if ($episode !== null) {
            $meta['episode'] = $episode;
        }
        return $this->media($seasonId, 'episode', $title, $meta);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function media(?string $parentId, string $type, string $name, array $metadata): string
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $id = Uuid::v4();
        $db->query(
            "INSERT INTO media_items (id, library_id, parent_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $this->libraryId,
                $parentId,
                $name,
                $type,
                '/nu-it/' . $id . '.mkv',
                (string) json_encode($metadata),
            ],
        );
        $this->mediaIds[] = $id;
        return $id;
    }

    /**
     * Seed a "watched" playback_state row (the S30 finish signal: stopped at 0).
     */
    private function watched(string $mediaItemId, string $updatedAt): void
    {
        $this->insertPlayback($mediaItemId, 0, 100000, 'stopped', $updatedAt);
    }

    private function insertPlayback(
        string $mediaItemId,
        int $position,
        int $duration,
        string $status,
        string $updatedAt,
    ): void {
        $db = $this->db;
        $this->assertNotNull($db);
        $db->query(
            "INSERT INTO playback_state
                (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [Uuid::v4(), $this->sessionId, $mediaItemId, $position, $duration, $status, $updatedAt],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function pick(array $rows, string $seriesId): ?array
    {
        foreach ($rows as $row) {
            if (($row['series_id'] ?? null) === $seriesId) {
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
        $db->query('DELETE FROM playback_state WHERE session_id = ?', [$this->sessionId]);
        $db->query('DELETE FROM sessions WHERE id = ?', [$this->sessionId]);
        // media_items self-reference via parent_id → delete children before parents.
        foreach (array_reverse($this->mediaIds) as $id) {
            $db->query('DELETE FROM media_items WHERE id = ?', [$id]);
        }
        if ($this->profileId !== '') {
            $db->query('DELETE FROM user_profiles WHERE id = ?', [$this->profileId]);
        }
        if ($this->userId !== '') {
            $db->query('DELETE FROM users WHERE id = ?', [$this->userId]);
        }
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
    }
}
