<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Session\SyncPlay;

use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\SyncPlaySnapshotService;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S289 — the REAL-MySQL runtime confirmation of the REST-vs-WS phantom-write split,
 * and the boundary of what S289 fixes.
 *
 * ## The finding this file proves (lane task 1, "confirm by a targeted test")
 *
 * On the live topology there are 14 HTTP workers and 1 WebSocket worker; only the WS
 * worker is given a `SyncPlaySnapshotService` (start.php), so a REST create/join
 * mutates one HTTP worker's per-process `SyncPlayManager` tables and publishes
 * NOTHING. This test reproduces the split faithfully with two `SyncPlayManager`
 * instances that model two worker processes — separate in-memory group tables, but
 * the SAME shared `syncplay_snapshots` store via their snapshot services (both resolve
 * the one `ConnectionPool::getConnection('mysql')` the guard seeds):
 *
 *  - Manager A creates a group → the row IS published to the shared store (a snapshot
 *    read sees it).
 *  - Manager B — a different process that never saw A's memory — tries to join that
 *    group by id → "Group not found". The create is a PHANTOM WRITE with respect to B:
 *    the shared store holds the *snapshot*, but no live worker re-hydrates its
 *    authoritative membership table from it, so B cannot accept the join.
 *
 * That asymmetry — reads converge on the snapshot, mutations do NOT converge on any
 * live worker's membership table — is exactly the SP6 gap. It is pinned here rather
 * than silently half-fixed, because the S415 envelope pin deliberately forbids the
 * HTTP mutation path from touching the DB, and a snapshot the WS worker never
 * re-hydrates would only relocate the phantom.
 *
 * ## The part S289 DOES fix, proved against the same store
 *
 * Once two joins DO reach one authoritative worker (the model), the identity is now the
 * JWT subject on both transports, so the same human joining REST-style then WS-style is
 * EXACTLY ONE member (idempotent re-join) — asserted here on the live snapshot emitted
 * to the shared store. This is the precondition that makes the SP6 bridge, when built,
 * converge on one member instead of forking the identity.
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the suite; locally,
 * with no reachable MySQL, the guard skips — the same venue contract as
 * {@see \Phlix\Tests\Integration\Session\PlaybackFinishIntegrationTest}.
 */
final class SyncPlayIdentitySharedStoreIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    /** Lane marker for S289; kept code-resident (never in markdown) per lane contract. */
    private const LANE_TOKEN = 'S289ONEIDENTITYX3B7';

    private ?Connection $db = null;

    /** @var list<string> created group ids to purge from the shared snapshot table */
    private array $createdGroupIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping SyncPlay shared-store integration test. Runs in CI.');
        $this->assertNotNull($this->db);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            foreach ($this->createdGroupIds as $groupId) {
                $db->query('DELETE FROM syncplay_snapshots WHERE group_id = ?', [$groupId]);
            }
        }
        $this->createdGroupIds = [];
        parent::tearDown();
    }

    /**
     * A's create reaches the shared snapshot store; B (a separate live worker's memory)
     * cannot join it — the REST-vs-WS phantom write, observed against real MySQL.
     */
    public function testRestCreateIsPhantomToAnotherWorkersMembershipTable(): void
    {
        $serviceA = new SyncPlaySnapshotService();
        $serviceB = new SyncPlaySnapshotService();

        $managerA = new SyncPlayManager();
        $managerA->setSnapshotService($serviceA);

        $create = $managerA->createGroup('S289 Phantom', null, 'jwt-alice', 'Alice', 'connA');
        $this->assertTrue($create['success']);
        /** @var array{group: array{group_id: string}} $create */
        $groupId = $create['group']['group_id'];
        $this->createdGroupIds[] = $groupId;

        // The snapshot WAS published — reads converge on the shared store.
        $readBack = $serviceB->getGroupState($groupId);
        $this->assertNotNull($readBack, 'the create must be visible in the shared snapshot [' . self::LANE_TOKEN . ']');
        $this->assertSame(['jwt-alice'], array_keys($readBack['members']));

        // A DIFFERENT process's authoritative membership table never saw the create.
        $managerB = new SyncPlayManager();
        $managerB->setSnapshotService($serviceB);

        $join = $managerB->joinGroup($groupId, 'jwt-bob', 'Bob', null, 'connB');
        $this->assertFalse(
            $join['success'],
            'the phantom-write boundary: a worker that never held the group in memory '
            . 'cannot mutate it — the snapshot is not re-hydrated into any live table (SP6).'
        );
        $this->assertSame('Group not found', $join['error'] ?? null);
    }

    /**
     * Once both joins land on ONE authoritative worker (the model the SP6 bridge will
     * hand them), the unified JWT-subject identity means the same human over both
     * transports is EXACTLY ONE member — and that single member is what reaches the
     * shared snapshot.
     */
    public function testSameIdentityOverBothTransportsPublishesExactlyOneMember(): void
    {
        $manager = new SyncPlayManager();
        $manager->setSnapshotService(new SyncPlaySnapshotService());

        $create = $manager->createGroup('S289 Converge', null, 'jwt-host', 'Host', 'connHost');
        $this->assertTrue($create['success']);
        /** @var array{group: array{group_id: string}} $create */
        $groupId = $create['group']['group_id'];
        $this->createdGroupIds[] = $groupId;

        // REST-style join (no WS socket) of Jane, then WS-style join of the SAME Jane.
        $manager->joinGroup($groupId, 'jwt-jane', 'Jane', null, null);
        $manager->joinGroup($groupId, 'jwt-jane', 'Jane', null, 'conn-jane');

        $published = (new SyncPlaySnapshotService())->getGroupState($groupId);
        $this->assertNotNull($published);
        $this->assertSame(
            2,
            $published['member_count'],
            'host + one Jane — the double join must not fork the identity [' . self::LANE_TOKEN . ']'
        );
        $this->assertSame(['jwt-host', 'jwt-jane'], array_keys($published['members']));
    }
}
