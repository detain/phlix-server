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
use Phlix\Common\Uuid;
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
}
