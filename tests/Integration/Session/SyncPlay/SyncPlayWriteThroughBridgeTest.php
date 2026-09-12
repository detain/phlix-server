<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Controllers\SyncPlayController;
use Phlix\Server\Http\Request;
use Phlix\Server\WebSocket\ConnectionInterface;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Session\SyncPlay\SyncPlayBridge;
use Phlix\Session\SyncPlay\SyncPlayBridgeListener;
use Phlix\Session\SyncPlay\SyncPlayBridgePublisher;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\SyncPlaySnapshotService;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * S445 — the write-through bridge, END-TO-END ON SERVED STATE.
 *
 * Venue (this suite's honest maximum — stated loudly): two REAL manager
 * instances modeling the two REAL process kinds (14×HTTP, 1×WS), real MySQL
 * for the REST-owned snapshot store, and a REAL filesystem unix socket as the
 * transport between them — the frame leaves the publisher over real socket
 * syscalls and is parsed by the listener from the kernel buffer, exactly as
 * between two live daemons; only the process boundary is folded. The true
 * two-process leg (a booted Workerman master with its forked WS listener) is
 * covered by scripts/syncplay-bridge-smoke.php, whose transcript travels with
 * the lane; wherever its venue is unavailable the check is recorded
 * relied-on-CI.
 *
 * AC1: a room CREATED AND MUTATED through the REST path is reflected in the WS
 * worker's SERVED surfaces — the authenticated `syncplay_group_list` frame and
 * the WS join gate on that worker's live membership table — WITHOUT any
 * restart of that worker (the very same manager object answers before and
 * after).
 *
 * AC2 is carried by the explicitly named reddening test below.
 *
 * The WS-side manager mirrors start.php §4a exactly: it WRITES snapshots after
 * its own mutations (publishSnapshot) but is never given a hydrate path — it
 * never reads the store; every REST fact it holds arrived through the bridge.
 */
final class SyncPlayWriteThroughBridgeTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $socketPath = '';

    private ?SyncPlayBridgeListener $listener = null;

    /** @var list<string> group ids created in the shared store, purged on teardown */
    private array $createdGroupIds = [];

    /** @var list<array<string, mixed>> every frame the listener handed to the applier */
    private array $bridgeFrameLog = [];

    /** @var array<string, list<array<string, mixed>>> frames captured per mock connection id */
    private array $sentByConnection = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping SyncPlay write-through bridge integration test. Runs in CI.');
        $this->assertNotNull($this->db);

        $this->socketPath = sys_get_temp_dir() . '/s445-ac-' . bin2hex(random_bytes(6)) . '.sock';
        $this->listener = new SyncPlayBridgeListener($this->socketPath);
        $this->listener->listen();
        $this->bridgeFrameLog = [];
        $this->sentByConnection = [];
        $this->createdGroupIds = [];
    }

    protected function tearDown(): void
    {
        $this->listener?->close();
        $this->listener = null;
        if ($this->socketPath !== '' && file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }

        $db = $this->db;
        if ($db !== null) {
            foreach ($this->createdGroupIds as $groupId) {
                $db->query('DELETE FROM syncplay_snapshots WHERE group_id = ?', [$groupId]);
            }
        }
        $this->createdGroupIds = [];
        $this->bridgeFrameLog = [];
        $this->sentByConnection = [];

        parent::tearDown();
    }

    /**
     * AC1 — REST create/join/leave, each write-through, are all observed on
     * the WS worker's SERVED state with no restart of that worker.
     */
    public function testRestMutationsAreReflectedInTheWsWorkersServedStateWithoutRestart(): void
    {
        // Two worker-shaped managers. The WS one is wired exactly like
        // start.php §4a: snapshot service set (it WRITES its own mutations),
        // no hydrate/bridge reads from the DB (its REST facts arrive only as
        // frames).
        $httpManager = new SyncPlayManager();
        $wsManager = new SyncPlayManager();
        $wsManager->setSnapshotService(new SyncPlaySnapshotService());
        $this->armWsApplier($wsManager);

        $controller = new SyncPlayController(
            $httpManager,
            new SyncPlaySnapshotService(),
            new SyncPlayBridgePublisher($this->socketPath, 250)
        );

        // Baseline served list — this SAME manager object answers at every
        // step below: the no-restart condition is structural to this venue.
        $this->assertSame([], $this->wsServedGroupList($wsManager), 'precondition: the WS worker starts with an empty room table');

        // 1) REST creates the room.
        $create = $this->decode($controller->createGroup(
            $this->httpRequest('jwt-alice', ['name' => 'Bridge AC Room', 'memberName' => 'Alice']),
            []
        )->body);
        $this->assertTrue($create['success']);
        /** @var array{group: array{group_id: string}} $create */
        $groupId = $create['group']['group_id'];
        $this->createdGroupIds[] = $groupId;

        $this->assertSame([$groupId], $this->drainBridge(), 'the create must publish exactly one bridge frame');
        $listed = $this->wsServedGroupList($wsManager);
        $this->assertSame([$groupId], array_column($listed, 'id'), 'the served room table must show the REST-created room');
        $this->assertSame(1, $listed[0]['member_count']);

        // 2) SERVED MUTATION SURFACE: a WS client joins that REST-created room
        //    through the WS worker's live gate — the very operation the
        //    phantom-write era answered with "Group not found".
        $joinOverWs = $wsManager->joinGroup($groupId, 'jwt-bob', 'Bob', null, 'conn-bob-live');
        $this->assertTrue(
            $joinOverWs['success'],
            'the WS worker must accept joins into REST-created rooms (its live table saw the frame)'
        );
        $this->assertSame($groupId, $wsManager->getMemberGroup('jwt-bob'));
        $this->assertSame(
            'conn-bob-live',
            $this->wsLiveConnectionFor($wsManager, $groupId, 'jwt-bob'),
            'the live WS connection is registered in the served table before the REST round-trip'
        );

        // 3) REST removes Bob — he exists only in the shared mirror (the WS
        //    worker published him there) and the live table; the rail
        //    hydrates, mutates, persists, publishes.
        $leave = $this->decode($controller->leaveGroup(
            $this->httpRequest('jwt-bob', []),
            ['id' => $groupId]
        )->body);
        $this->assertTrue($leave['success'], 'the REST leave of a mirror-only member must succeed after S445');

        $this->assertSame([$groupId], $this->drainBridge(), 'the leave must publish exactly one bridge frame');
        $listed = $this->wsServedGroupList($wsManager);
        $this->assertSame([$groupId], array_column($listed, 'id'), 'the room is still served (Alice remains)');
        $this->assertSame(1, $listed[0]['member_count'], 'served membership must reflect the REST-side leave');
        $this->assertNull($this->wsLiveConnectionFor($wsManager, $groupId, 'jwt-bob'), 'the departed member\'s live connection mapping is gone');
        $this->assertNotContains('jwt-bob', array_keys($wsManager->getGroupState($groupId)['members'] ?? []));

        // 4) Alice leaves over REST too — the group empties; the delete frame
        //    tombstones it live.
        $this->decode($controller->leaveGroup(
            $this->httpRequest('jwt-alice', []),
            ['id' => $groupId]
        )->body);
        $this->assertSame([$groupId], $this->drainBridge(), 'the emptying must publish exactly one delete frame');
        $this->assertSame([], $this->wsServedGroupList($wsManager), 'the served room table must be empty again — no restart happened');
    }

    /**
     * AC2 (the named reddening test) — mutations that write REST-only must
     * NOT reach the WS worker's served state.
     *
     * This test is the bridge's tripwire in both directions:
     *  - the production rail (persist THEN publish) must land on the served
     *    surface — if any future change re-introduces REST-only writes into
     *    the rail ("wrote REST-only"), this leg goes RED;
     *  - a planted REST-only mutation (the pre-S445 rail shape: a bare
     *    manager call that touches only the HTTP process's table — the exact
     *    invariant {@see \Phlix\Tests\Integration\Session\SyncPlay\SyncPlayIdentitySharedStoreIntegrationTest}
     *    pins one layer below) must stay INVISIBLE to the same served
     *    assertion, with an anti-vacuity proof that the planted mutation
     *    genuinely succeeded locally. Without the bridge, "invisible" would
     *    prove nothing.
     */
    public function test_a_rest_only_mutation_reddens_the_served_state_assertion(): void
    {
        $httpManager = new SyncPlayManager();
        $wsManager = new SyncPlayManager();
        $this->armWsApplier($wsManager);

        // LEG A (the reddener): production write-through rail — visible.
        $controller = new SyncPlayController(
            $httpManager,
            new SyncPlaySnapshotService(),
            new SyncPlayBridgePublisher($this->socketPath, 250)
        );
        $viaRail = $this->decode($controller->createGroup(
            $this->httpRequest('jwt-rail', ['name' => 'Write-Through Room', 'memberName' => 'Rail']),
            []
        )->body);
        $this->assertTrue($viaRail['success']);
        /** @var array{group: array{group_id: string}} $viaRail */
        $railGroupId = $viaRail['group']['group_id'];
        $this->createdGroupIds[] = $railGroupId;

        $this->drainBridge();
        $this->assertContains(
            $railGroupId,
            array_column($this->wsServedGroupList($wsManager), 'id'),
            'a write-through REST mutation MUST be served by the WS worker — if the rail '
            . 'ever regresses to REST-only writes (no persist+publish), THIS ASSERTION REDDENS.'
        );

        // LEG B: the planted REST-only mutation — a bare per-process manager
        // write, no snapshot persist, no bridge publish (the pre-S445 shape).
        $planted = $httpManager->createGroup('REST-Only Phantom', null, 'jwt-phantom', 'Phantom');
        $this->assertTrue($planted['success'], 'anti-vacuity: the planted write must genuinely succeed locally');
        /** @var array{group: array{group_id: string}} $planted */
        $phantomGroupId = $planted['group']['group_id'];

        $this->assertSame([], $this->drainBridge(), 'a REST-only write must emit no bridge frame at all');
        $this->assertNotContains(
            $phantomGroupId,
            array_column($this->wsServedGroupList($wsManager), 'id'),
            'a mutation that wrote REST-only must stay invisible to the served state'
        );
        $this->assertNull(
            $wsManager->getGroupState($phantomGroupId),
            'and must not have leaked into the live membership table by any other route'
        );
    }

    // -----------------------------------------------------------------
    // Venue plumbing
    // -----------------------------------------------------------------

    /**
     * Route the bridge into the WS worker's live tables, logging every frame
     * the listener hands over (the drainBridge() accounting surface).
     */
    private function armWsApplier(SyncPlayManager $wsManager): void
    {
        $this->assertNotNull($this->listener);
        $this->listener->setApplier(function (array $frame) use ($wsManager): void {
            $this->bridgeFrameLog[] = $frame;
            $wsManager->applyBridgeFrame($frame);
        });
    }

    /**
     * Pump the listener until the frame log goes quiet (bounded), then return
     * the group ids the frames published since the last drain addressed, in
     * arrival order.
     *
     * @return list<string>
     */
    private function drainBridge(int $maxMs = 3000): array
    {
        $this->assertNotNull($this->listener);
        $start = count($this->bridgeFrameLog);
        $seen = $start;
        $quietMs = 0;
        $waited = 0;

        while ($waited < $maxMs) {
            $this->listener->poll();
            $now = count($this->bridgeFrameLog);
            if ($now !== $seen) {
                $seen = $now;
                $quietMs = 0;
            } else {
                $quietMs += 10;
                if ($quietMs >= 30 && $seen > $start) {
                    break;
                }
            }
            usleep(10_000);
            $waited += 10;
        }

        $ids = [];
        foreach (array_slice($this->bridgeFrameLog, $start) as $frame) {
            $ids[] = $frame['op'] === SyncPlayBridge::OP_GROUP_UPSERT
                ? (string) $frame['group']['id']
                : (string) $frame['group_id'];
        }

        return $ids;
    }

    /**
     * The WS worker's SERVED room table: the group-list frame an attached,
     * authenticated client would receive RIGHT NOW (production handleMessage
     * routing; the mock connection captures what the wire would carry).
     *
     * @return list<array<string, mixed>>
     */
    private function wsServedGroupList(SyncPlayManager $wsManager): array
    {
        $connection = $this->mockConnection('ws-served-probe', 'jwt-probe');

        $handleMessage = new ReflectionMethod(SyncPlayManager::class, 'handleMessage');
        $handleMessage->setAccessible(true);
        $handleMessage->invoke($wsManager, $connection, ['type' => Messages::TYPE_GROUP_LIST]);

        // NEWEST captured frame wins: the probe's send log accumulates across
        // every served-state read in the test.
        $captured = array_reverse($this->sentByConnection['ws-served-probe'] ?? []);
        foreach ($captured as $frame) {
            if (($frame['type'] ?? null) === Messages::TYPE_GROUP_LIST) {
                /** @var list<array<string, mixed>> $groups */
                $groups = $frame['groups'] ?? [];

                return $groups;
            }
        }

        $this->fail('the WS worker served no group_list frame');
    }

    /**
     * A group member's live connection id as the WS worker's own table holds
     * it (used to prove the merge kept the LIVE socket, not the mirror).
     */
    private function wsLiveConnectionFor(SyncPlayManager $wsManager, string $groupId, string $memberId): ?string
    {
        $member = $wsManager->getGroup($groupId)?->getMember($memberId);

        $connectionId = $member['connection_id'] ?? null;

        return is_string($connectionId) ? $connectionId : null;
    }

    private function mockConnection(string $id, ?string $userId): ConnectionInterface
    {
        $mock = $this->createMock(ConnectionInterface::class);
        $mock->method('getId')->willReturn($id);
        $mock->method('getUserId')->willReturn($userId);
        $mock->method('isAuthenticated')->willReturn($userId !== null);
        $mock->method('getSessionId')->willReturn(null);
        $mock->method('send')->willReturnCallback(function ($data) use ($id): bool {
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            $this->sentByConnection[$id][] = is_array($data) ? $data : ['raw' => $data];

            return true;
        });

        return $mock;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function httpRequest(string $userId, array $body): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/syncplay/groups';
        $request->userId = $userId;
        $request->body = $body;

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
