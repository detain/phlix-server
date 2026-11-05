<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Session\SyncPlay\GroupState;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\SyncPlaySnapshotService;
use Phlix\Tests\Unit\Server\WebSocket\TestableSyncPlayManager;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;

/**
 * SRV-M9 — cleanupStaleGroups teardown integrity (review server__asphp).
 *
 * On the count=1 resident WS worker, the periodic stale sweep is the one
 * group-death path that used to perform exactly two unsets (memberToGroup
 * per member + groups). Two leaks followed, both permanent in a process
 * that is never restarted:
 *
 *  1. connectionToMember entries stranded forever — the later socket close
 *     cannot recover them because leaveGroup early-returns once the member
 *     mapping is gone (the review's :921-927 evidence);
 *  2. the syncplay_snapshots row surviving — REST rails list ghost rooms
 *     indefinitely and the table grows unboundedly.
 *
 * These tests drive the REAL join → sweep path (same harness idiom as
 * OutboundFrameShapeGuardTest) and pin the sibling-path teardown the cure
 * mirrors: unindexGroup (identity-checked reverse-index death, bridge-
 * replace :354/:392) + removeSnapshot (leave-empty :963).
 *
 * Second law pinned here (playback-lag cure C1, INV-B 2026-11-05): the sweep
 * predicate reads lastActivityAt, and a mid-movie watch party's ONLY inbound
 * signal is the 5 s playback_sync position report. Reports must count as
 * group liveness (mirroring the hub relay, which stamps roomActivity on
 * every echo — phlix-hub SyncPlayRelayWorker.php:2247); genuine silence past
 * the timeout must still tear down. Before the cure, recordMemberPosition()
 * was activity-blind and groups died at 60 min of faithful playback.
 */
final class SyncPlayStaleTeardownTest extends TestCase
{
    /** Sentinel proving this file is the code home of the SRV-M9 audit. */
    public const GUARD_TOKEN = 'SRVM9STALETEARDOWNK7T2';

    private TestableSyncPlayManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        $this->manager = new TestableSyncPlayManager(new MessageHandler($pool));
    }

    protected function tearDown(): void
    {
        ConnectionPool::getInstance()->clear();
        parent::tearDown();
    }

    /**
     * Seed two authenticated members in one live group via the real message
     * path (GROUP_CREATE + GROUP_JOIN index both reverse maps).
     *
     * @return array{TestConnection, TestConnection, string}
     */
    private function seedGroup(): array
    {
        $a = new TestConnection('conn-a');
        $a->setAuthenticated(true, 'user-a');
        $b = new TestConnection('conn-b');
        $b->setAuthenticated(true, 'user-b');

        $pool = ConnectionPool::getInstance();
        $pool->add($a);
        $pool->add($b);

        $this->manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'group_name' => 'Stale Group',
            'member_name' => 'A',
        ]);
        $createFrames = $a->framesOfType(Messages::TYPE_GROUP_STATE);
        $this->assertNotEmpty($createFrames, 'seed: create must answer with group_state');
        /** @var array<string, mixed> $group */
        $group = $createFrames[count($createFrames) - 1]['group'] ?? [];
        $groupId = (string) ($group['group_id'] ?? '');
        $this->assertNotSame('', $groupId, 'seed: group_id must be present');

        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'member_name' => 'B',
        ]);

        return [$a, $b, $groupId];
    }

    /**
     * Read one of the manager's private reverse-index arrays — this test
     * exists precisely to pin their teardown and no production accessor
     * exposes them.
     *
     * @return array<array-key, mixed>
     */
    private function readPrivate(string $property): array
    {
        $prop = new \ReflectionProperty(SyncPlayManager::class, $property);
        return (array) $prop->getValue($this->manager);
    }

    public function testStaleSweepKillsConnectionIndexAndDurableRow(): void
    {
        [, , $groupId] = $this->seedGroup();

        // Precondition: both reverse indexes are live — the leak class starts here.
        $this->assertSame(['conn-a', 'conn-b'], array_keys($this->readPrivate('connectionToMember')));
        $this->assertSame(['user-a', 'user-b'], array_keys($this->readPrivate('memberToGroup')));

        // The durable row must die with the in-memory group (leave-empty :963
        // parity). SRV-M9 red-at-parent: the stale path never reached here.
        $service = $this->createMock(SyncPlaySnapshotService::class);
        $service->expects($this->once())->method('removeGroup')->with($groupId);
        $this->manager->setSnapshotService($service);

        // Any negative timeout marks every group stale immediately.
        $this->assertSame(1, $this->manager->cleanupStaleGroups(-1));

        // The stranding conviction: no conn->member entry may outlive the group.
        $this->assertSame([], $this->readPrivate('connectionToMember'));
        $this->assertSame([], $this->readPrivate('memberToGroup'));
        $stats = $this->manager->getStats();
        $this->assertSame(0, $stats['total_groups']);
        $this->assertSame(0, $stats['total_members']);
    }

    public function testStaleSweepNeverClobbersANewerGroupMapping(): void
    {
        [, , $groupId] = $this->seedGroup();

        // Simulate the newer-join race the identity checks exist for: user-b
        // already maps to a different group while the stale group's member
        // list still carries the (phantom) entry.
        $memberToGroup = $this->readPrivate('memberToGroup');
        $memberToGroup['user-b'] = 'group-newer';
        (new \ReflectionProperty(SyncPlayManager::class, 'memberToGroup'))
            ->setValue($this->manager, $memberToGroup);

        $this->assertSame(1, $this->manager->cleanupStaleGroups(-1));

        // The newer mapping survives (the old blunt unset deleted it — the
        // second SRV-M9 conviction, red at parent), user-a's own mapping dies
        // with its group, and both connection entries die under their identity
        // checks.
        $this->assertSame('group-newer', $this->readPrivate('memberToGroup')['user-b'] ?? null);
        $this->assertSame(['user-b'], array_keys($this->readPrivate('memberToGroup')));
        $this->assertSame([], $this->readPrivate('connectionToMember'));
    }

    /**
     * The sweep's subject: the live in-memory GroupState for a seeded id.
     * The tests below backdate its lastActivityAt to model "the last host
     * command was ages ago" while the room is still playing.
     */
    private function groupState(string $groupId): GroupState
    {
        $group = $this->readPrivate('groups')[$groupId] ?? null;
        $this->assertInstanceOf(GroupState::class, $group, 'seed: group must be live before the sweep');

        return $group;
    }

    public function testPositionReportsKeepTheGroupAliveAcrossTheSweep(): void
    {
        [, $b, $groupId] = $this->seedGroup();

        // Model the long movie: the host's last explicit command
        // (play/pause/seek/queue/chat) is older than GROUP_TIMEOUT, so the
        // sweep predicate judges the group stale right now.
        $group = $this->groupState($groupId);
        (new \ReflectionProperty(GroupState::class, 'lastActivityAt'))->setValue($group, time() - 7200);
        $this->assertLessThanOrEqual(time() - 3600, $group->getLastActivityAt());

        // A member is playing faithfully — its playback_sync position
        // report lands inside the window. On the count=1 WS worker this
        // frame is the room's ONLY liveness signal during a film.
        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_PLAYBACK_SYNC,
            'position' => 123456,
        ]);
        $this->assertNotNull(
            $group->getMemberPosition('user-b'),
            'seed: the report must be ingested (via recordMemberPosition) before the sweep judges liveness'
        );

        // C1 law (INV-B CONFIRMED): a group that received a report now is
        // NOT torn down, even though its last host command predates the
        // timeout. Red at parent: recordMemberPosition() stored the
        // position and left lastActivityAt untouched, so the sweep removed
        // the live room mid-film.
        $this->assertSame(0, $this->manager->cleanupStaleGroups(3600));

        // The room survives whole — members, indexes, snapshot all intact.
        $this->assertArrayHasKey($groupId, $this->readPrivate('groups'));
        $this->assertSame(['conn-a', 'conn-b'], array_keys($this->readPrivate('connectionToMember')));
        $this->assertSame(1, $this->manager->getStats()['total_groups']);
    }

    public function testGenuineSilencePastTheTimeoutStillTearsDown(): void
    {
        [, , $groupId] = $this->seedGroup();

        // Same aged host command — but NOBODY reports. An abandoned room
        // must still die on schedule: the C1 liveness stamp rides position
        // ingestion only, it is not a liveness license for silence.
        $group = $this->groupState($groupId);
        (new \ReflectionProperty(GroupState::class, 'lastActivityAt'))->setValue($group, time() - 7200);

        $this->assertSame(1, $this->manager->cleanupStaleGroups(3600));
        $this->assertSame([], $this->readPrivate('groups'));
        $this->assertSame([], $this->readPrivate('memberToGroup'));
    }
}
