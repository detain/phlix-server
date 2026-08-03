<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Session;

use Phlix\Common\Uuid;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S156 — real-schema proof that a database built by the migration chain ALONE
 * carries `UNIQUE KEY uq_playback_state_session_media (session_id,
 * media_item_id)`.
 *
 * CI builds its schema with `php scripts/run-migrations.php` and NOTHING else —
 * it never runs `migrations/cleanup_090.php`. That is exactly the environment in
 * which the key was missing before migration 097: migration 090 carries no
 * executable statement and deferred the key to that manual finalizer, so the
 * constraint simply did not exist on any install nobody had hand-finalized.
 * This test therefore asserts against the schema the chain actually produces.
 *
 * It deliberately does NOT create the key itself (unlike
 * {@see PlaybackStateDeduperIntegrationTest}, which drops and restores it to
 * rehearse the finalizer). If the key is absent here, the migration chain is
 * broken — that is the finding, not an environment gap. The only skip is
 * "no MySQL".
 *
 */
final class PlaybackStateUniqueKeyPresentTest extends TestCase
{
    use RequiresRealDatabase;

    private const KEY_NAME = 'uq_playback_state_session_media';

    private ?Connection $db = null;

    private string $libraryId = '';
    private string $userId = '';
    private string $sessionId = '';
    private string $mediaItemId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the playback_state unique-key schema check. Runs in CI.');
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            try {
                // Child-first; playback_state also cascades from both parents.
                if ($this->sessionId !== '') {
                    $db->query('DELETE FROM playback_state WHERE session_id = ?', [$this->sessionId]);
                    $db->query('DELETE FROM sessions WHERE id = ?', [$this->sessionId]);
                }
                if ($this->mediaItemId !== '') {
                    $db->query('DELETE FROM media_items WHERE id = ?', [$this->mediaItemId]);
                }
                if ($this->userId !== '') {
                    $db->query('DELETE FROM users WHERE id = ?', [$this->userId]);
                }
                if ($this->libraryId !== '') {
                    $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
                }
            } catch (Throwable) {
                // Best effort; leftover fixture rows are inert and id-scoped.
            }
        }

        $this->db = null;
        $this->libraryId = '';
        $this->userId = '';
        $this->sessionId = '';
        $this->mediaItemId = '';

        parent::tearDown();
    }

    /**
     * The key exists, is UNIQUE, and covers exactly `(session_id,
     * media_item_id)` in that order.
     *
     * All three properties are load-bearing: a non-unique index constrains
     * nothing, and any other column set is not the conflict target the
     * `ON DUPLICATE KEY UPDATE` in the progress path needs in order to fire.
     */
    public function testMigrationChainLeavesTheUniqueKeyInPlace(): void
    {
        $rows = $this->db()->query(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            ['playback_state', self::KEY_NAME],
        );

        $this->assertIsArray($rows);
        $this->assertCount(
            2,
            $rows,
            self::KEY_NAME . ' must exist on playback_state with exactly 2 columns after '
            . '`php scripts/run-migrations.php` alone. Absent means migration 097 did not run, or a later '
            . 'migration dropped it again — progress reporting silently reverts to INSERTing a new row per '
            . 'tick, and finished episodes never leave Continue Watching.',
        );

        $this->assertSame('0', (string) $rows[0]['NON_UNIQUE'], 'the key must be UNIQUE, not a plain index');
        $this->assertSame('session_id', (string) $rows[0]['COLUMN_NAME']);
        $this->assertSame('media_item_id', (string) $rows[1]['COLUMN_NAME']);
    }

    /**
     * The functional payoff, end to end: the exact upsert the progress path
     * emits must UPDATE the existing row rather than insert a fresh one keyed on
     * a new random UUID.
     *
     * This is the user-visible half of the defect. Without the key the second
     * call adds a row, the stale `position_ticks` survives, and
     * `getContinueWatching()` keeps surfacing a finished episode.
     */
    public function testTheProgressUpsertUpdatesInPlaceInsteadOfInsertingASecondRow(): void
    {
        $db = $this->db();
        $this->seedFixture();

        $firstRowId = Uuid::v4();
        $this->reportProgress($firstRowId, 1_000);

        $this->assertSame(1, $this->rowCount(), 'the first progress report must create exactly one row');

        // A second tick for the SAME (session_id, media_item_id) — a fresh id,
        // exactly as PlaybackController::reportProgress() generates per call.
        $this->reportProgress(Uuid::v4(), 2_000);

        $this->assertSame(
            1,
            $this->rowCount(),
            'the second progress report INSERTed a new row instead of updating — the '
            . 'ON DUPLICATE KEY UPDATE conflict target is missing, which is the whole S156 defect',
        );

        $rows = $db->query(
            'SELECT id, position_ticks FROM playback_state WHERE session_id = ? AND media_item_id = ?',
            [$this->sessionId, $this->mediaItemId],
        );
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);

        $this->assertSame(
            2_000,
            (int) $rows[0]['position_ticks'],
            'the surviving row must carry the NEWLY reported position',
        );
        $this->assertSame(
            $firstRowId,
            (string) $rows[0]['id'],
            'the ORIGINAL row must have been updated in place, keeping its id',
        );
    }

    /**
     * The constraint scope: it forbids a duplicate within ONE session, but must
     * NOT stop the same user watching the same item in a DIFFERENT session.
     *
     * That distinction is why the `ROW_NUMBER() OVER (PARTITION BY
     * ps.media_item_id ...)` dedup in `getContinueWatching()` is still required
     * after 097 — one user on two devices is a legitimate duplicate at the
     * media-item level that this key cannot subsume.
     */
    public function testASecondSessionForTheSameItemIsStillAllowed(): void
    {
        $db = $this->db();
        $this->seedFixture();

        $this->reportProgress(Uuid::v4(), 1_000);

        $secondSessionId = Uuid::v4();
        $db->query(
            'INSERT INTO sessions (id, user_id, device_id) VALUES (?, ?, ?)',
            [$secondSessionId, $this->userId, 's156-device-2'],
        );

        $threw = false;
        try {
            $db->query(
                "INSERT INTO playback_state
                    (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
                 VALUES (?, ?, ?, ?, ?, 'playing')",
                [Uuid::v4(), $secondSessionId, $this->mediaItemId, 500, 100_000],
            );
        } catch (Throwable) {
            $threw = true;
        }

        // Scoped cleanup for the extra session before any assertion can fail.
        $db->query('DELETE FROM playback_state WHERE session_id = ?', [$secondSessionId]);
        $db->query('DELETE FROM sessions WHERE id = ?', [$secondSessionId]);

        $this->assertFalse(
            $threw,
            'the unique key must constrain (session_id, media_item_id), NOT media_item_id alone — '
            . 'one user watching the same item on two devices is legitimate',
        );
    }

    /** Seed the minimal library → user → session → media_item chain. */
    private function seedFixture(): void
    {
        $db = $this->db();

        $this->libraryId = Uuid::v4();
        $this->userId = Uuid::v4();
        $this->sessionId = Uuid::v4();
        $this->mediaItemId = Uuid::v4();

        $db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'S156 unique-key probe', 'movie', json_encode(['/tmp/phlix-s156'])],
        );
        $db->query(
            'INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)',
            [$this->userId, 's156-' . substr($this->userId, 0, 8), 's156-' . $this->userId . '@example.test', 'x'],
        );
        $db->query(
            'INSERT INTO sessions (id, user_id, device_id) VALUES (?, ?, ?)',
            [$this->sessionId, $this->userId, 's156-device-1'],
        );
        $db->query(
            'INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, ?, ?)',
            [$this->mediaItemId, $this->libraryId, 'S156 probe movie', 'movie', '/tmp/phlix-s156/probe.mkv'],
        );
    }

    /**
     * The literal upsert `PlaybackController::reportProgress()` emits — a fresh
     * random UUID every call, relying on the unique key to turn the second and
     * later calls into UPDATEs.
     */
    private function reportProgress(string $rowId, int $position): void
    {
        $this->db()->query(
            "INSERT INTO playback_state
                (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                position_ticks = VALUES(position_ticks),
                duration_ticks = VALUES(duration_ticks),
                playback_status = VALUES(playback_status),
                updated_at = NOW()",
            [$rowId, $this->sessionId, $this->mediaItemId, $position, 100_000, 'playing'],
        );
    }

    private function rowCount(): int
    {
        $rows = $this->db()->query(
            'SELECT COUNT(*) AS c FROM playback_state WHERE session_id = ? AND media_item_id = ?',
            [$this->sessionId, $this->mediaItemId],
        );
        $this->assertIsArray($rows);

        return isset($rows[0]['c']) && is_scalar($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
    }

    /** The connection, guaranteed non-null (setUp skips the test otherwise). */
    private function db(): Connection
    {
        $this->assertInstanceOf(Connection::class, $this->db);

        return $this->db;
    }
}
