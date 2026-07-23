<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Session;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use Phlix\Session\PlaybackStateDeduper;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S29 real-DB rehearsal of the `playback_state` dedupe-then-constrain migration
 * ({@see PlaybackStateDeduper} + `migrations/cleanup_090.php`).
 *
 * The unit-level surface of the deduper cannot prove the two things the S29
 * acceptance criteria actually care about, because both are properties of MySQL,
 * not of PHP:
 *
 *   1. The BATCHED dedupe is row-count-safe on a *large-ish* table — exactly one
 *      row survives per `(session_id, media_item_id)` group, and it is the
 *      max-`updated_at` (tie-break max-`id`) keeper — with no group emptied and
 *      no keeper deleted. A mocked connection would just replay hand-fed rows and
 *      never exercise the `GROUP BY … HAVING COUNT(*) > 1 … LIMIT` batch scan, the
 *      `ORDER BY updated_at DESC, id DESC` keeper pick, or the atomic DELETE.
 *   2. After {@see PlaybackStateDeduper::addUniqueKey()} adds
 *      `uq_playback_state_session_media (session_id, media_item_id)`, the real
 *      `INSERT … ON DUPLICATE KEY UPDATE` the progress path emits
 *      ({@see \Phlix\Session\PlaybackController::reportProgress()}) finally
 *      UPDATES the existing row instead of inserting a brand-new one — the whole
 *      point of the migration.
 *
 * This mirrors the repo's real-MySQL integration convention
 * ({@see \Phlix\Tests\Integration\Session\ContinueWatchingIntegrationTest},
 * {@see \Phlix\Tests\Integration\Media\PathHashIndexUsageTest}): connect to the
 * configured DB, self-skip when no MySQL is reachable (locally), run for real in
 * CI (the `phlix_test` service has every migration applied before the suite).
 *
 * The plan's S29 cycle note asks the Test stage to "rehearse the dedupe against a
 * seeded LARGE-ish fixture, not just a handful of rows" — so the fixture seeds
 * {@see self::DUP_GROUP_COUNT} duplicate groups (more than the deduper's
 * {@see PlaybackStateDeduper::DEFAULT_BATCH_SIZE}, forcing the batch loop to
 * iterate more than once) plus a tie-break group and a block of untouchable
 * singleton groups — thousands of `playback_state` rows in total.
 *
 * Because `addUniqueKey()` mutates the shared `playback_state` schema (and a
 * lingering unique key would break other integration tests that deliberately
 * seed duplicate rows, e.g. ContinueWatchingIntegrationTest), the key is dropped
 * again in tearDown *only when this test created it* — exactly the create-and-
 * restore discipline {@see PathHashIndexUsageTest} uses for its index.
 *
 * @covers \Phlix\Session\PlaybackStateDeduper
 */
final class PlaybackStateDeduperIntegrationTest extends TestCase
{
    /**
     * Duplicate `(session_id, media_item_id)` groups to seed. Deliberately GREATER
     * than {@see PlaybackStateDeduper::DEFAULT_BATCH_SIZE} (500) so
     * {@see PlaybackStateDeduper::dedupeAll()} MUST drain the table in more than
     * one bounded batch — the batching path the cycle note wants exercised.
     */
    private const DUP_GROUP_COUNT = 650;

    /** Groups seeded with a single row — must be left completely untouched. */
    private const SINGLETON_GROUP_COUNT = 60;

    /** Distinct sessions/media the group grid is built from (27 * 27 = 729 pairs). */
    private const SESSION_COUNT = 27;
    private const MEDIA_COUNT = 27;

    /** Base epoch for seeded `updated_at` values (kept well in the past). */
    private const BASE_TS = 1_609_459_200; // 2021-01-01 00:00:00 UTC

    private ?Connection $db = null;

    private bool $createdUniqueKey = false;

    private string $libraryId = '';
    private string $userId = '';

    /** @var list<string> seeded session ids (FK parents + assertion scope) */
    private array $sessionIds = [];

    /** @var list<string> seeded media_item ids (FK parents) */
    private array $mediaIds = [];

    /**
     * Expected surviving keeper `position_ticks`, keyed by "sessionId|mediaId".
     * Every regular duplicate group records its keeper here so the survivor can
     * be verified cell-for-cell.
     *
     * @var array<string, int>
     */
    private array $keeperPosition = [];

    /** Rows deleted the dedupe MUST report (sum of group_size - 1 over dup groups). */
    private int $expectedDeleted = 0;

    /** Total rows the fixture seeds (for a before/after row-count-safe check). */
    private int $expectedSeededRows = 0;

    /** Total groups after dedupe (dup groups + tie group + singletons). */
    private int $expectedSurvivingGroups = 0;

    // The tie-break group: two rows share the max updated_at, greater id must win.
    private string $tieSessionId = '';
    private string $tieMediaId = '';
    private string $tieKeeperId = '';
    private int $tieKeeperPosition = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(sprintf(
                'No MySQL on %s:%d — skipping playback_state dedupe rehearsal. Runs in CI.',
                $host,
                $port,
            ));
        }

        try {
            ConnectionPool::init(dirname(__DIR__, 3) . '/config/database.php');
            $this->db = ConnectionPool::getConnection('mysql');
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

        $this->assertNotNull($this->db);

        // Guard against a leftover key from a previously-aborted run so the
        // fixture's duplicate rows can be inserted (a live key would 1062).
        $this->dropUniqueKeyIfPresent();

        $this->libraryId = Uuid::v4();
        $this->userId = Uuid::v4();

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();
        parent::tearDown();
    }

    /**
     * The end-to-end rehearsal: seed thousands of rows across hundreds of
     * duplicate groups, run the batched dedupe + add the unique key, and prove
     * (a) row-count-safety and correct keeper selection, (b) the upsert now
     * updates instead of inserting, and (c) full idempotency on replay.
     */
    public function testBatchedDedupeIsRowCountSafeThenUpsertUpdatesInPlace(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $deduper = new PlaybackStateDeduper($db);

        // --- Pre-conditions ------------------------------------------------
        $this->assertFalse(
            $deduper->hasUniqueKey(),
            'the (session_id, media_item_id) unique key must NOT exist before cleanup_090 runs',
        );
        $this->assertSame(
            $this->expectedSeededRows,
            $this->scopedRowCount(),
            'fixture did not seed the expected number of playback_state rows',
        );
        // Every duplicate + tie group is discoverable; singletons are not.
        $this->assertSame(
            self::DUP_GROUP_COUNT + 1,
            $this->scopedDuplicateGroupCount(),
            'expected DUP_GROUP_COUNT duplicate groups plus the tie group before dedupe',
        );

        // --- Batched dedupe (the cleanup_090 step 1) -----------------------
        $result = $deduper->dedupeAll();

        // The whole point of the cycle note: the fixture has more duplicate
        // groups than one batch holds, so the drain loop MUST iterate > once.
        $this->assertGreaterThanOrEqual(
            2,
            $result['iterations'],
            'dedupeAll must drain > DEFAULT_BATCH_SIZE groups in more than one batch',
        );
        $this->assertGreaterThanOrEqual(
            self::DUP_GROUP_COUNT + 1,
            $result['groups'],
            'every seeded duplicate group (incl. the tie group) must be resolved',
        );
        $this->assertGreaterThanOrEqual(
            $this->expectedDeleted,
            $result['deleted'],
            'reported deletions must cover every loser row the fixture seeded',
        );
        $this->assertSame(0, $result['skipped'], 'no group DELETE should have thrown');

        // --- Row-count-safety: exactly one survivor per group --------------
        $survivors = $this->scopedGroupCounts();
        $this->assertCount(
            $this->expectedSurvivingGroups,
            $survivors,
            'no group may be emptied and no group may vanish — group count must be stable',
        );
        foreach ($survivors as $pairKey => $count) {
            $this->assertSame(
                1,
                $count,
                "group {$pairKey} must collapse to EXACTLY one surviving row",
            );
        }
        $this->assertSame(
            $this->expectedSurvivingGroups,
            $this->scopedRowCount(),
            'total surviving rows must equal the group count (one keeper each)',
        );
        $this->assertSame(
            0,
            $this->scopedDuplicateGroupCount(),
            'no duplicate group may remain after dedupeAll',
        );

        // --- Keeper correctness: max updated_at, tie-break max id ----------
        $this->assertKeepersAreCorrect();
        $this->assertTieBreakKeeperSurvived();

        // --- Add the unique key (the cleanup_090 step 2) -------------------
        $this->assertTrue(
            $deduper->addUniqueKey(),
            'addUniqueKey must create the key on first application',
        );
        $this->createdUniqueKey = true;
        $this->assertTrue(
            $deduper->hasUniqueKey(),
            'the unique key must be present after addUniqueKey',
        );

        // --- The payoff: ON DUPLICATE KEY UPDATE now UPDATES, not inserts --
        $this->assertUpsertUpdatesInsteadOfInserting();

        // --- Idempotency: re-running the whole cleanup is a safe no-op -----
        $replay = $deduper->dedupeAll();
        $this->assertSame(0, $replay['groups'], 'no duplicate groups remain on replay');
        $this->assertSame(0, $replay['deleted'], 'replay must delete nothing');
        $this->assertFalse(
            $deduper->addUniqueKey(),
            'addUniqueKey on an already-present key must be a no-op returning false',
        );
        $this->assertTrue($deduper->hasUniqueKey(), 'the key must still be present after replay');
    }

    /**
     * With the key in place, the exact upsert the progress path emits must UPDATE
     * the existing row (row count unchanged, position/updated_at changed) rather
     * than insert a fresh row keyed on a new random UUID.
     */
    private function assertUpsertUpdatesInsteadOfInserting(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // Use the tie-break group's surviving keeper as the target.
        $sessionId = $this->tieSessionId;
        $mediaId = $this->tieMediaId;

        $rowsBefore = $this->scopedRowCount();
        $keeperIdBefore = $this->onlyRowId($sessionId, $mediaId);
        $this->assertNotNull($keeperIdBefore, 'target group must hold exactly one row pre-upsert');

        $newPosition = 4_242_424;
        // The literal upsert from PlaybackController::reportProgress() — a fresh
        // random id in VALUES, conflict target (session_id, media_item_id).
        $db->query(
            "INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                position_ticks = VALUES(position_ticks),
                duration_ticks = VALUES(duration_ticks),
                playback_status = VALUES(playback_status),
                updated_at = NOW()",
            [Uuid::v4(), $sessionId, $mediaId, $newPosition, 999_999, 'playing'],
        );

        $this->assertSame(
            $rowsBefore,
            $this->scopedRowCount(),
            'ON DUPLICATE KEY UPDATE must NOT insert a new row once the unique key exists',
        );
        $this->assertSame(
            1,
            $this->scopedGroupCounts()[$sessionId . '|' . $mediaId] ?? 0,
            'the target group must still hold exactly one row after the upsert',
        );
        $keeperIdAfter = $this->onlyRowId($sessionId, $mediaId);
        $this->assertSame(
            $keeperIdBefore,
            $keeperIdAfter,
            'the upsert must update the EXISTING keeper row, keeping its id (not the new random UUID)',
        );

        $row = $this->rowFor($sessionId, $mediaId);
        $this->assertNotNull($row);
        $this->assertSame(
            $newPosition,
            self::intCell($row['position_ticks'] ?? -1),
            'position_ticks must reflect the upserted value',
        );
        $this->assertSame(
            999_999,
            self::intCell($row['duration_ticks'] ?? -1),
            'duration_ticks must reflect the upserted value',
        );
    }

    /**
     * Verify a representative sample of surviving keepers carry the sentinel
     * `position_ticks` written to the max-`updated_at` row of their group.
     */
    private function assertKeepersAreCorrect(): void
    {
        $pairKeys = array_keys($this->keeperPosition);
        $sampled = [];
        // First 25, last 25, and every 50th in between — bounded but spread.
        foreach ($pairKeys as $i => $key) {
            if ($i < 25 || $i >= count($pairKeys) - 25 || $i % 50 === 0) {
                $sampled[] = $key;
            }
        }

        foreach ($sampled as $pairKey) {
            [$sessionId, $mediaId] = explode('|', $pairKey, 2);
            $row = $this->rowFor($sessionId, $mediaId);
            $this->assertNotNull($row, "keeper row missing for {$pairKey}");
            $this->assertSame(
                $this->keeperPosition[$pairKey],
                self::intCell($row['position_ticks'] ?? -1),
                "surviving row for {$pairKey} is not the max-updated_at keeper",
            );
        }
    }

    /**
     * The tie-break group had two rows sharing the max `updated_at`; the greater
     * `id` must be the survivor.
     */
    private function assertTieBreakKeeperSurvived(): void
    {
        $survivorId = $this->onlyRowId($this->tieSessionId, $this->tieMediaId);
        $this->assertSame(
            $this->tieKeeperId,
            $survivorId,
            'tie-break: the row with the greater id must survive when updated_at ties',
        );
        $row = $this->rowFor($this->tieSessionId, $this->tieMediaId);
        $this->assertNotNull($row);
        $this->assertSame(
            $this->tieKeeperPosition,
            self::intCell($row['position_ticks'] ?? -1),
            'tie-break survivor must carry the greater-id row payload',
        );
    }

    // ---- Seeding -----------------------------------------------------------

    private function seedFixtures(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'movie', '[]')",
            [$this->libraryId, 'S29 Dedupe IT Library'],
        );
        $db->query(
            "INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)",
            [
                $this->userId,
                's29-it-' . substr($this->userId, 0, 8),
                's29-it-' . substr($this->userId, 0, 8) . '@example.test',
                'x',
            ],
        );

        for ($s = 0; $s < self::SESSION_COUNT; $s++) {
            $id = Uuid::v4();
            $this->sessionIds[] = $id;
            $db->query(
                "INSERT INTO sessions (id, user_id, device_id) VALUES (?, ?, ?)",
                [$id, $this->userId, 's29-it-device-' . $s],
            );
        }
        for ($m = 0; $m < self::MEDIA_COUNT; $m++) {
            $id = Uuid::v4();
            $this->mediaIds[] = $id;
            $db->query(
                "INSERT INTO media_items (id, library_id, parent_id, name, type, path, metadata_json)
                 VALUES (?, ?, NULL, ?, 'movie', ?, '{}')",
                [$id, $this->libraryId, 'S29 IT Movie ' . $m, '/s29-it/' . $id . '.mkv'],
            );
        }

        // Build (session, media) pairs from the grid, in a stable order.
        $pairs = [];
        foreach ($this->mediaIds as $mediaId) {
            foreach ($this->sessionIds as $sessionId) {
                $pairs[] = [$sessionId, $mediaId];
            }
        }

        /** @var list<array{string, string, string, int, int, string, string}> $rows */
        $rows = [];
        $pairIndex = 0;

        // (1) Regular duplicate groups: 2..5 rows each, strictly increasing
        // updated_at so the LAST row is the unambiguous keeper.
        for ($g = 0; $g < self::DUP_GROUP_COUNT; $g++) {
            [$sessionId, $mediaId] = $pairs[$pairIndex++];
            $groupSize = 2 + ($g % 4); // 2,3,4,5
            $keeperK = $groupSize - 1;
            $keeperPos = 7_000_000 + $g;
            $this->keeperPosition[$sessionId . '|' . $mediaId] = $keeperPos;

            for ($k = 0; $k < $groupSize; $k++) {
                $isKeeper = ($k === $keeperK);
                $rows[] = [
                    Uuid::v4(),
                    $sessionId,
                    $mediaId,
                    $isKeeper ? $keeperPos : (100 + $k),
                    5_000_000,
                    'playing',
                    $this->ts(($g * 10) + $k), // increasing within + across groups
                ];
            }
            $this->expectedDeleted += $groupSize - 1;
            $this->expectedSeededRows += $groupSize;
        }

        // (2) Tie-break group: two rows share the max updated_at; the greater
        // id must win. A third, older row proves it is not simply "last inserted".
        [$this->tieSessionId, $this->tieMediaId] = $pairs[$pairIndex++];
        $tieMaxTs = $this->ts((self::DUP_GROUP_COUNT * 10) + 500);
        $idA = Uuid::v4();
        $idB = Uuid::v4();
        // Ensure the two ids differ (astronomically unlikely to collide).
        while ($idB === $idA) {
            $idB = Uuid::v4();
        }
        [$greaterId, $lesserId] = strcmp($idA, $idB) > 0 ? [$idA, $idB] : [$idB, $idA];
        $this->tieKeeperId = $greaterId;
        $this->tieKeeperPosition = 8_000_000;
        $tieSession = $this->tieSessionId;
        $tieMedia = $this->tieMediaId;
        // greater-id row (expected keeper) — max ts, sentinel payload
        $rows[] = [$greaterId, $tieSession, $tieMedia, $this->tieKeeperPosition, 5_000_000, 'playing', $tieMaxTs];
        // lesser-id row — same max ts, different payload
        $rows[] = [$lesserId, $tieSession, $tieMedia, 8_000_001, 5_000_000, 'playing', $tieMaxTs];
        // older loser row
        $rows[] = [Uuid::v4(), $tieSession, $tieMedia, 8_000_002, 5_000_000, 'playing', $this->ts(1)];
        $this->expectedDeleted += 2;
        $this->expectedSeededRows += 3;

        // (3) Singleton groups: one row each — must be left untouched entirely.
        for ($n = 0; $n < self::SINGLETON_GROUP_COUNT; $n++) {
            [$sessionId, $mediaId] = $pairs[$pairIndex++];
            $rows[] = [
                Uuid::v4(),
                $sessionId,
                $mediaId,
                9_000_000 + $n,
                5_000_000,
                'paused',
                $this->ts(($n * 3) + 1),
            ];
            $this->expectedSeededRows += 1;
        }

        $this->expectedSurvivingGroups =
            self::DUP_GROUP_COUNT + 1 + self::SINGLETON_GROUP_COUNT;

        $this->insertPlaybackRows($rows);
    }

    /**
     * Bulk-insert playback_state rows in bounded multi-row statements.
     *
     * @param list<array{string, string, string, int, int, string, string}> $rows
     */
    private function insertPlaybackRows(array $rows): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $chunkSize = 100; // 700 bound params per statement — comfortably bounded
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $row) {
                $tuples[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($params, $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6]);
            }
            $db->query(
                'INSERT INTO playback_state
                    (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status, updated_at)
                 VALUES ' . implode(', ', $tuples),
                $params,
            );
        }
    }

    // ---- Scoped assertions helpers ----------------------------------------

    /** Total playback_state rows belonging to this fixture's sessions. */
    private function scopedRowCount(): int
    {
        $rows = $this->scopedQuery(
            'SELECT COUNT(*) AS c FROM playback_state WHERE session_id IN (%s)',
        );

        return isset($rows[0]['c']) ? self::intCell($rows[0]['c']) : -1;
    }

    /** Count of this fixture's (session, media) groups with more than one row. */
    private function scopedDuplicateGroupCount(): int
    {
        $rows = $this->scopedQuery(
            'SELECT COUNT(*) AS c FROM (
                SELECT 1 FROM playback_state
                WHERE session_id IN (%s)
                GROUP BY session_id, media_item_id
                HAVING COUNT(*) > 1
             ) t',
        );

        return isset($rows[0]['c']) ? self::intCell($rows[0]['c']) : -1;
    }

    /**
     * Per-group surviving row counts, keyed "sessionId|mediaId".
     *
     * @return array<string, int>
     */
    private function scopedGroupCounts(): array
    {
        $rows = $this->scopedQuery(
            'SELECT session_id, media_item_id, COUNT(*) AS c FROM playback_state
             WHERE session_id IN (%s)
             GROUP BY session_id, media_item_id',
        );

        $out = [];
        foreach ($rows as $row) {
            $key = self::strCell($row['session_id'] ?? '') . '|' . self::strCell($row['media_item_id'] ?? '');
            $out[$key] = self::intCell($row['c'] ?? 0);
        }

        return $out;
    }

    /**
     * The single surviving row for a group, or null.
     *
     * @return array<array-key, mixed>|null
     */
    private function rowFor(string $sessionId, string $mediaId): ?array
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $rows = $db->query(
            'SELECT id, position_ticks, duration_ticks, updated_at FROM playback_state
             WHERE session_id = ? AND media_item_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1',
            [$sessionId, $mediaId],
        );

        return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    private function onlyRowId(string $sessionId, string $mediaId): ?string
    {
        $row = $this->rowFor($sessionId, $mediaId);
        $id = $row['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Run a query whose single `%s` is expanded to this fixture's session-id
     * placeholder list, bound safely.
     *
     * @return list<array<string, mixed>>
     */
    private function scopedQuery(string $sqlWithPlaceholder): array
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $placeholders = implode(', ', array_fill(0, count($this->sessionIds), '?'));
        $sql = sprintf($sqlWithPlaceholder, $placeholders);
        $rows = $db->query($sql, $this->sessionIds);

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $shaped = [];
            foreach ($row as $key => $value) {
                $shaped[(string) $key] = $value;
            }
            $out[] = $shaped;
        }

        return $out;
    }

    /** Coerce a mixed DB cell to int (0 for non-numeric), mirroring the deduper. */
    private static function intCell(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Coerce a mixed DB cell to string (empty for non-scalars), mirroring the deduper. */
    private static function strCell(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function ts(int $offsetSeconds): string
    {
        return gmdate('Y-m-d H:i:s', self::BASE_TS + $offsetSeconds);
    }

    // ---- Environment / teardown -------------------------------------------

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        // Restore the schema: drop the unique key iff this test added it, so
        // sibling integration tests that seed duplicate playback_state rows keep
        // working against a shared DB.
        if ($this->createdUniqueKey) {
            $this->dropUniqueKeyIfPresent();
        }

        if ($this->sessionIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($this->sessionIds), '?'));
            $db->query(
                "DELETE FROM playback_state WHERE session_id IN ({$placeholders})",
                $this->sessionIds,
            );
            $db->query(
                "DELETE FROM sessions WHERE id IN ({$placeholders})",
                $this->sessionIds,
            );
        }
        if ($this->mediaIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($this->mediaIds), '?'));
            $db->query(
                "DELETE FROM media_items WHERE id IN ({$placeholders})",
                $this->mediaIds,
            );
        }
        if ($this->userId !== '') {
            $db->query('DELETE FROM users WHERE id = ?', [$this->userId]);
        }
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
    }

    private function dropUniqueKeyIfPresent(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }
        try {
            $db->query(
                'ALTER TABLE playback_state DROP INDEX ' . PlaybackStateDeduper::UNIQUE_KEY_NAME,
            );
        } catch (Throwable) {
            // Not present — nothing to drop.
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
