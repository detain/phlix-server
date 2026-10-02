<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Casting;

use Phlix\Casting\CastingSessionStore;
use Phlix\Casting\CastingSessionStoreInterface;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Common\Uuid;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Device-M1 — real-MySQL proof of the casting-session register.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * The properties below belong to the live server, not to the doubled sibling
 * (`tests/Unit/Casting/CastingSessionStoreTest.php`): the `CHAR(36)`/`JSON`
 * round-trip through `utf8mb4_unicode_ci`, the `NOW()` column defaults paired
 * with the `DATE_SUB(NOW(), INTERVAL n SECOND)` sweep horizon, the
 * `uk_type_device` unique register that turns two concurrent same-device
 * starts into exactly one surviving row (the 1062 the store's replace-race
 * retry rides), and cross-connection visibility — the literal fleet posture of
 * fourteen HTTP workers sharing one table.
 *
 * SAFETY: self-heals the table with the verbatim migration-111
 * `CREATE TABLE IF NOT EXISTS`, rows are namespaced by freshly minted UUIDs
 * this file created, and tearDown deletes exactly those session ids.
 *
 * ## 2026-10-02 ship-review correction note (forward-only)
 *
 * The ship review of e1f1fa05 caught this header PHANTOM-CLAIMING its own
 * evidence: every test in this file up to that date ran on ONE shared
 * pool connection, so "cross-connection visibility — the literal fleet
 * posture of fourteen workers" was prose about the venue, not a proven
 * property of a test. {@see testCrossConnectionFleetVisibilitySecondSocketSeesFirstsWrites}
 * now exercises exactly that posture with two independent MySQL connections
 * (the pool front for the writer, a hand-built {@see PhlixMySQLConnection}
 * for the reader, the house idiom of
 * `tests/Integration/Stats/StatsStorageUniqueKeyUpsertGuardTest.php`), so the
 * claim above is true as of this commit rather than aspirational.
 */
final class CastingSessionStoreRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    /** Verbatim from migrations/111_casting_sessions.sql — do not "improve". */
    private const SCHEMA_SQL = 'CREATE TABLE IF NOT EXISTS casting_sessions ('
        . ' session_id CHAR(36) NOT NULL,'
        . ' type VARCHAR(16) NOT NULL,'
        . ' device_id VARCHAR(191) NOT NULL,'
        . ' user_id CHAR(36) NOT NULL,'
        . ' state JSON NOT NULL,'
        . ' created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (session_id),'
        . ' UNIQUE KEY uk_type_device (type, device_id),'
        . ' KEY ix_casting_sessions_last_seen_at (last_seen_at),'
        . ' KEY ix_casting_sessions_user_id (user_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private ?Connection $db = null;

    /** @var list<string> session ids this test minted — the exact tearDown scope. */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the Device-M1 casting-session store proof. Runs in CI.');

        $this->db->query(self::SCHEMA_SQL);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            foreach ($this->sessionIds as $id) {
                $db->query('DELETE FROM casting_sessions WHERE session_id = ?', [$id]);
            }
        }
        $this->sessionIds = [];
        // The second socket of the fleet-posture proof needs no explicit drop:
        // the vendor Connection has no close API (StatsStorageUniqueKeyUpsertGuardTest
        // precedent) and the test-local reference rides into the destructor.
        $this->db = null;
    }

    private function store(
        int $staleHorizonSeconds = CastingSessionStore::DEFAULT_STALE_HORIZON_SECONDS,
    ): CastingSessionStore {
        self::assertNotNull($this->db);

        return new CastingSessionStore($this->db, 1, $staleHorizonSeconds);
    }

    private function mint(): string
    {
        $id = Uuid::v4();
        $this->sessionIds[] = $id;

        return $id;
    }

    private function deviceKey(string $label): string
    {
        return "device-m1-it-{$label}-" . bin2hex(random_bytes(6));
    }

    /**
     * Recursively key-sort an array for order-insensitive comparison.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        ksort($value);
        foreach ($value as &$inner) {
            if (is_array($inner)) {
                $inner = self::canonicalize($inner);
            }
        }

        return $value;
    }

    public function testRoundTripInsertFindTouchDelete(): void
    {
        $store = $this->store();
        $sessionId = $this->mint();
        $deviceId = $this->deviceKey('roundtrip');

        self::assertTrue($store->insert($sessionId, 'playto', $deviceId, 'user-a', [
            'av_transport_url' => 'http://192.168.253.9:49152/AVTransport.xml',
            'friendly_name' => 'Integration TV',
            'nested' => ['list' => [1, 2, 3]],
        ]));

        $record = $store->find('playto', $deviceId);
        self::assertNotNull($record);
        self::assertSame($sessionId, $record->sessionId);
        self::assertSame('user-a', $record->userId);
        // MySQL's binary JSON stores object members ordered (by key length,
        // then bytes) — content round-trips, insertion order does not, and no
        // re-attach consumer reads positionally, so compare canonically.
        self::assertSame(
            self::canonicalize([
                'av_transport_url' => 'http://192.168.253.9:49152/AVTransport.xml',
                'friendly_name' => 'Integration TV',
                'nested' => ['list' => [1, 2, 3]],
            ]),
            self::canonicalize($record->state),
            'the JSON column round-trips the re-attach payload key-for-key',
        );
        self::assertNotSame('', $record->createdAt);
        self::assertNotSame('', $record->lastSeenAt);

        self::assertTrue($store->touch($sessionId));

        $store->delete($sessionId);
        self::assertNull($store->find('playto', $deviceId));
    }

    public function testTouchUnknownSessionAnswersFalse(): void
    {
        self::assertFalse($this->store()->touch(Uuid::v4()));
    }

    public function testUniqueRegisterCollapsesSameDeviceStartsToOneRow(): void
    {
        $store = $this->store();
        $first = $this->mint();
        $second = $this->mint();
        $deviceId = $this->deviceKey('unique');

        self::assertTrue($store->insert($first, 'roku', $deviceId, 'kid-a', ['media_url' => 'http://a']));

        // A second start on the same device WITHOUT the manager's deleteByDevice
        // is exactly the replace race: 1062 inside, one retry through the
        // scoped delete, later writer wins — the last-writer-wins REPLACE law.
        self::assertTrue($store->insert($second, 'roku', $deviceId, 'kid-b', ['media_url' => 'http://b']));

        $record = $store->find('roku', $deviceId);
        self::assertNotNull($record);
        self::assertSame($second, $record->sessionId);
        self::assertSame('kid-b', $record->userId);

        // The loser's worker finds its row gone on the next touch — the eviction
        // signal the poll loops depend on.
        self::assertFalse($store->touch($first));
    }

    public function testDeleteByDeviceIsScopedToTypeAndDevice(): void
    {
        $store = $this->store();
        $deviceId = $this->deviceKey('scoped');
        $otherDeviceId = $this->deviceKey('scoped-other');
        $castRow = $this->mint();
        $playtoRow = $this->mint();
        $otherRow = $this->mint();

        self::assertTrue($store->insert($castRow, 'cast', $deviceId, 'user-a', []));
        self::assertTrue($store->insert($playtoRow, 'playto', $deviceId, 'user-a', []));
        self::assertTrue($store->insert($otherRow, 'cast', $otherDeviceId, 'user-a', []));

        $store->deleteByDevice('cast', $deviceId);

        self::assertNull($store->find('cast', $deviceId));
        self::assertNotNull($store->find('playto', $deviceId), 'the same device under another class is untouched');
        self::assertNotNull($store->find('cast', $otherDeviceId), 'other devices are untouched');

        $store->deleteByDevice('playto', $deviceId);
    }

    public function testLazySweepCollectsRowsBeyondTheHorizon(): void
    {
        $store = $this->store(60);
        $oldSession = $this->mint();
        $liveSession = $this->mint();
        $oldDevice = $this->deviceKey('stale');
        $liveDevice = $this->deviceKey('live');

        self::assertTrue($store->insert($oldSession, 'airplay', $oldDevice, 'user-a', []));
        self::assertTrue($store->insert($liveSession, 'airplay', $liveDevice, 'user-a', []));

        // Age exactly one row beyond the horizon with the DB clock (the same
        // clock the sweep's DATE_SUB reads — no PHP/MySQL skew games).
        self::assertNotNull($this->db);
        $this->db->query(
            'UPDATE casting_sessions SET last_seen_at = DATE_SUB(NOW(), INTERVAL 120 SECOND) WHERE session_id = ?',
            [$oldSession],
        );

        // Consulting ANY device triggers the bounded sweep.
        self::assertNull($store->find('airplay', $this->deviceKey('trigger-only-not-inserted')));

        self::assertNull($store->find('airplay', $oldDevice), 'the stale row was swept');
        self::assertNotNull($store->find('airplay', $liveDevice), 'the live row survived the sweep');
    }

    public function testFindByUserReturnsOnlyThatUsersRows(): void
    {
        $store = $this->store();
        $mine = $this->mint();
        $theirs = $this->mint();
        $myDevice = $this->deviceKey('user-a');
        $theirDevice = $this->deviceKey('user-b');
        $userId = Uuid::v4();
        $otherUserId = Uuid::v4();

        self::assertTrue($store->insert($mine, 'cast', $myDevice, $userId, []));
        self::assertTrue($store->insert($theirs, 'cast', $theirDevice, $otherUserId, []));

        $rows = $store->findByUser($userId);

        self::assertCount(1, $rows);
        self::assertSame($mine, $rows[0]->sessionId);
        self::assertSame(CastingSessionStoreInterface::TYPE_CAST, $rows[0]->type);

        $store->delete($theirs);
    }

    public function testNowDefaultsStampBothTimestampColumns(): void
    {
        $store = $this->store();
        $sessionId = $this->mint();
        $deviceId = $this->deviceKey('defaults');

        self::assertTrue($store->insert($sessionId, 'playto', $deviceId, 'user-a', []));

        self::assertNotNull($this->db);
        $rows = $this->db->query(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM casting_sessions WHERE session_id = ?',
            [$sessionId],
        );
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertLessThanOrEqual(5, (int) $rows[0]['age'], 'NOW() defaults stamp the row at write time');

        $store->delete($sessionId);
    }

    /**
     * The fleet posture itself: TWO independent MySQL connections over the one
     * register — writer on the shared pool front (connection 1, the socket
     * every other test in this file used), reader on a hand-built second
     * socket (connection 2, the house idiom of
     * `tests/Integration/Stats/StatsStorageUniqueKeyUpsertGuardTest.php`).
     *
     * Each leg proves the store's contract from the OTHER socket's seat:
     *  1. a row committed through connection 1 is fully readable — hydrated
     *     record, JSON state and all — through connection 2;
     *  2. `touch()` from connection 2 lands a real UPDATE on a row this socket
     *     did not create;
     *  3. the same-second zero-changed path disambiguates across connections:
     *     a FRESH store (empty throttle memo) re-stamping a row already stamped
     *     in the current DB second gets rowCount 0, and the bounded existence
     *     read must answer "alive" from connection 2's view of connection 1's
     *     row. (Both legs of that branch return true for a live row; the age
     *     probe pins the precondition, and leg 4 pins the dead-row leg of the
     *     same disambiguation deterministically.)
     *  4. an eviction through connection 1 is a death signal through
     *     connection 2 — rowCount 0 AND an empty existence read, so the new
     *     store on the reader socket answers false: the exact "your session is
     *     gone, evict yourself" answer one worker's poll loop needs when
     *     another worker's stop deleted the row.
     */
    public function testCrossConnectionFleetVisibilitySecondSocketSeesFirstsWrites(): void
    {
        $writerDb = $this->db;
        self::assertNotNull($writerDb);
        $readerDb = $this->openSecondConnection();

        // Verbatim idempotent DDL again on the reader socket: proves the table
        // exists to THIS connection and keeps the test runnable in isolation.
        $readerDb->query(self::SCHEMA_SQL);

        $writer = new CastingSessionStore($writerDb, 1);
        $reader = new CastingSessionStore($readerDb, 1);

        $sessionId = $this->mint();
        $deviceId = $this->deviceKey('crossconn');
        $state = ['media_url' => 'http://fleet-proof/stream', 'friendly_name' => 'Cross TV'];

        // Leg 1 — writer socket INSERT, reader socket full read path.
        self::assertTrue($writer->insert($sessionId, 'playto', $deviceId, 'user-cross', $state));

        $record = $reader->find('playto', $deviceId);
        self::assertNotNull($record, 'a row committed on connection 1 must be visible on an independent connection 2');
        self::assertSame($sessionId, $record->sessionId);
        self::assertSame('user-cross', $record->userId);
        self::assertSame(
            self::canonicalize($state),
            self::canonicalize($record->state),
            'the JSON payload crosses the connection boundary key-for-key',
        );

        // Leg 2 — a real UPDATE from socket 2 against socket 1's row.
        self::assertTrue($reader->touch($sessionId), 'touch from the reader socket must succeed on the writer row');

        // Leg 3 — precondition probe then the same-second re-stamp through a
        // fresh store (its throttle memo is empty, so the UPDATE really runs).
        $age = $readerDb->query(
            'SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS age FROM casting_sessions WHERE session_id = ?',
            [$sessionId],
        );
        self::assertIsArray($age);
        self::assertCount(1, $age, 'connection 2 reads its own just-stamped row');
        self::assertLessThanOrEqual(
            1,
            (int) $age[0]['age'],
            'leg 2 stamped within the current or immediately previous DB second',
        );

        self::assertTrue(
            (new CastingSessionStore($readerDb, 1))->touch($sessionId),
            'a same-second re-stamp across connections is not a death signal — the SELECT-1 '
            . 'existence read must answer from connection 2\'s view of the row connection 1 created',
        );

        // Leg 4 — eviction through socket 1 is death for socket 2 (deterministic
        // negative leg of the same zero-affected disambiguation).
        $writer->delete($sessionId);

        self::assertFalse(
            (new CastingSessionStore($readerDb, 1))->touch($sessionId),
            'after connection 1 deletes the row, connection 2 must report the eviction',
        );
        self::assertNull($reader->find('playto', $deviceId), 'the device key is free on the reader socket too');
    }

    /**
     * Open a second, fully independent connection to the same configured DB.
     *
     * The {@see IntegrationDbGuard} call in setUp has already separated
     * "absent" (skip) from "unusable" (red) for this venue, so the direct
     * construction below cannot resurrect the S126 defect; the `SELECT 1`
     * round-trip proves this socket is genuinely live on its own.
     */
    private function openSecondConnection(): PhlixMySQLConnection
    {
        $reader = new PhlixMySQLConnection(
            IntegrationDbGuard::host(),
            IntegrationDbGuard::port(),
            (string) (getenv('DB_USER') ?: 'root'),
            (string) (getenv('DB_PASSWORD') ?: ''),
            (string) (getenv('DB_DATABASE') ?: 'phlix_test'),
        );
        $reader->query('SELECT 1');

        return $reader;
    }
}
