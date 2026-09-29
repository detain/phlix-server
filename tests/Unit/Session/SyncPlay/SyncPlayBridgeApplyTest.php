<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\GroupState;
use Phlix\Session\SyncPlay\SyncPlayBridge;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;

/**
 * S445 — the WS-side APPLY semantics of the write-through bridge, on two real
 * managers with a shared serialized mirror handed across (the transport leg
 * is SyncPlayBridgeTest; the served-state leg is the Integration suite).
 *
 * Contract under test: frames are idempotent (stamp-gated), adopt absent
 * groups wholesale, and MERGE into live groups — REST owns membership and
 * host, the live worker keeps its playback facets and its own connection ids.
 */
final class SyncPlayBridgeApplyTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides serialized-state key overrides
     * @return array<string, mixed>
     */
    private function serializedGroup(string $id, string $name = 'Room', array $overrides = []): array
    {
        $group = GroupState::deserialize(array_merge([
            'id' => $id,
            'name' => $name,
            'members' => ['u1' => ['name' => 'One', 'connection_id' => null, 'joined_at' => 1, 'is_active' => true]],
            'host_id' => 'u1',
        ], $overrides));

        return $group->serialize();
    }

    /**
     * @param array<string, mixed> $serialized
     */
    private function upsertFrame(array $serialized, int $stamp): array
    {
        return SyncPlayBridge::frame(SyncPlayBridge::OP_GROUP_UPSERT, ['group' => $serialized], $stamp);
    }

    private function deleteFrame(string $groupId, int $stamp): array
    {
        return SyncPlayBridge::frame(SyncPlayBridge::OP_GROUP_DELETE, ['group_id' => $groupId], $stamp);
    }

    public function testAnAbsentGroupIsAdoptedWholesaleAndIndexed(): void
    {
        $ws = new SyncPlayManager();

        $applied = $ws->applyBridgeFrame($this->upsertFrame($this->serializedGroup('sp_adopt', 'Adopted'), 100));

        $this->assertTrue($applied);
        $state = $ws->getGroupState('sp_adopt');
        $this->assertNotNull($state, 'adopted group must be live on the receiving worker');
        $this->assertSame('Adopted', $state['group_name']);
        $this->assertSame(['u1'], array_keys($state['members']));
        $this->assertSame('sp_adopt', $ws->getMemberGroup('u1'));
        $this->assertContains('sp_adopt', array_column($ws->listGroups(), 'id'));
    }

    public function testReDeliveryOfTheSameStampIsAnIdempotentSkip(): void
    {
        $ws = new SyncPlayManager();
        $frame = $this->upsertFrame($this->serializedGroup('sp_dup'), 500);

        $this->assertTrue($ws->applyBridgeFrame($frame));
        $this->assertTrue($ws->applyBridgeFrame($frame));
        $this->assertSame(1, $ws->getStats()['total_groups']);
    }

    public function testLateDeliveryIsGatedByTheStamp(): void
    {
        $ws = new SyncPlayManager();
        $newer = $this->serializedGroup('sp_gate', 'Newer', [
            'members' => [
                'u1' => ['name' => 'One', 'connection_id' => null, 'joined_at' => 1, 'is_active' => true],
                'u2' => ['name' => 'Two', 'connection_id' => null, 'joined_at' => 2, 'is_active' => true],
            ],
        ]);
        $older = $this->serializedGroup('sp_gate', 'Older');

        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($newer, 2000)));
        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($older, 1000)), 'stale frames report applied-or-skipped');

        $state = $ws->getGroupState('sp_gate');
        $this->assertNotNull($state);
        $this->assertSame(['u1', 'u2'], array_keys($state['members']), 'the stale frame must not resurrect the old membership');
    }

    public function testMergePreservesWsOwnedFacetsAndLiveConnections(): void
    {
        $ws = new SyncPlayManager();
        // The live worker: owns the group with a real WS connection + playback state.
        $create = $ws->createGroup('Live Room', null, 'u1', 'One', 'conn-live-u1');
        $this->assertTrue($create['success']);
        /** @var array{group: array{group_id: string}} $create */
        $groupId = $create['group']['group_id'];
        $ws->getGroup($groupId)?->setCurrentMedia('media-9', 100);
        $ws->getGroup($groupId)?->setPlaybackPosition(4321);

        // REST publishes from its mirror: same membership set PLUS a second
        // member, but the mirror's copy carries stale/absent connection ids
        // and no media (the mirror predates the WS-side playback write).
        $merged = $this->serializedGroup($groupId, 'Live Room', [
            'members' => [
                'u1' => ['name' => 'One Renamed', 'connection_id' => null, 'joined_at' => 1, 'is_active' => true],
                'u2' => ['name' => 'Two', 'connection_id' => null, 'joined_at' => 2, 'is_active' => true],
            ],
            'current_media_id' => null,
            'playback_position' => 0,
        ]);

        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($merged, 7777)));

        $state = $ws->getGroupState($groupId);
        $this->assertNotNull($state);
        $this->assertSame(['u1', 'u2'], array_keys($state['members']), 'REST owns the membership set');
        $this->assertSame('One Renamed', $state['members']['u1']['name']);
        $this->assertSame('media-9', $state['current_media_id'], 'WS owns playback — the mirror must not blank it');
        $this->assertSame(4321, $state['playback_position']);
        $this->assertSame('conn-live-u1', $ws->getGroup($groupId)?->getMember('u1')['connection_id'] ?? null, 'live conn wins over the mirror null');
        $this->assertSame($groupId, $ws->getMemberGroup('u2'), 'the new member is indexed for broadcasts');
    }

    public function testMergeRemovesMembersThatRestDropped(): void
    {
        $ws = new SyncPlayManager();
        $create = $ws->createGroup('Shrink', null, 'u1', 'One', 'conn-1');
        /** @var array{group: array{group_id: string}} $create */
        $groupId = $create['group']['group_id'];
        $ws->joinGroup($groupId, 'u2', 'Two', null, 'conn-2');

        $withoutU2 = $this->serializedGroup($groupId, 'Shrink'); // members: u1 only
        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($withoutU2, 3000)));

        $state = $ws->getGroupState($groupId);
        $this->assertNotNull($state);
        $this->assertSame(['u1'], array_keys($state['members']));
        $this->assertNull($ws->getMemberGroup('u2'));
    }

    public function testDeleteTombstonesAndBlocksResurrectionByAStaleUpsert(): void
    {
        $ws = new SyncPlayManager();
        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($this->serializedGroup('sp_dead'), 1000)));

        $this->assertTrue($ws->applyBridgeFrame($this->deleteFrame('sp_dead', 2000)));
        $this->assertNull($ws->getGroupState('sp_dead'));
        $this->assertNull($ws->getMemberGroup('u1'));

        // Late-arriving pre-delete upsert (lower stamp): must NOT resurrect.
        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($this->serializedGroup('sp_dead'), 1500)));
        $this->assertNull($ws->getGroupState('sp_dead'), 'a stale upsert must not resurrect a tombstoned group');

        // A genuinely newer upsert (post-recreate) must be adopted again.
        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($this->serializedGroup('sp_dead'), 2500)));
        $this->assertNotNull($ws->getGroupState('sp_dead'));
    }

    public function testTombstonesArePrunedByTheStaleGroupSweep(): void
    {
        $ws = new SyncPlayManager();
        // Epoch-old stamp: tombstone with nothing live behind it.
        $this->assertTrue($ws->applyBridgeFrame($this->deleteFrame('sp_ancient', 1000)));
        $this->assertNull($ws->getGroupState('sp_ancient'));

        // Still gated immediately after (tombstone present): stale upsert skipped.
        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($this->serializedGroup('sp_ancient'), 999)));
        $this->assertNull($ws->getGroupState('sp_ancient'));

        // Prune the epoch-old tombstone…
        $ws->cleanupStaleGroups(1);

        // …the map is bounded; the same ancient frame is "older than any live
        // truth" and re-applying is harmless (documented posture), while the
        // stamp gate stays intact for anything recent.
        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($this->serializedGroup('sp_ancient'), 998)));
        $this->assertNotNull($ws->getGroupState('sp_ancient'));
    }

    public function testTheMaxGroupsCapAlsoGatesBridgeAdoptionOfNewGroups(): void
    {
        $ws = new SyncPlayManager();
        for ($i = 0; $i < 100; $i++) {
            $created = $ws->createGroup('Cap ' . $i, null, 'cap-user-' . $i, 'Cap');
            $this->assertTrue($created['success']);
        }

        $refused = $ws->applyBridgeFrame($this->upsertFrame($this->serializedGroup('sp_over_cap'), 100));
        $this->assertFalse($refused, 'the bridge must not be a back door around MAX_GROUPS');
        $this->assertNull($ws->getGroupState('sp_over_cap'));

        // Merging INTO an already-live group is still allowed at the cap.
        $existingId = $ws->getMemberGroup('cap-user-0');
        $this->assertNotNull($existingId);
        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($this->serializedGroup($existingId, 'Cap 0'), 200)));
    }

    public function testMalformedFramesAreRefusedNotHalfApplied(): void
    {
        $ws = new SyncPlayManager();

        $this->assertFalse($ws->applyBridgeFrame(['op' => SyncPlayBridge::OP_GROUP_UPSERT, 'issued_at_ms' => 'not-an-int']));
        $this->assertFalse($ws->applyBridgeFrame(['op' => 'bogus', 'issued_at_ms' => 1]));
        $this->assertFalse($ws->applyBridgeFrame([
            'op' => SyncPlayBridge::OP_GROUP_UPSERT,
            'issued_at_ms' => 1,
            'group' => ['id' => 'sp_bad'], // deserialize throws on missing name -> false
        ]));
        $this->assertNull($ws->getGroupState('sp_bad'));
    }

    public function testAdoptGroupFromSerializedInstallsTheRestHydrateBaseWithoutTouchingStamps(): void
    {
        $rest = new SyncPlayManager();

        $this->assertTrue($rest->adoptGroupFromSerialized($this->serializedGroup('sp_hydrate', 'Mirror')));
        $state = $rest->getGroupState('sp_hydrate');
        $this->assertNotNull($state);
        $this->assertSame(['u1'], array_keys($state['members']));
        $this->assertSame('sp_hydrate', $rest->getMemberGroup('u1'));

        // Hydration is not bridge traffic: a later frame stamped anything still applies.
        $this->assertTrue($rest->applyBridgeFrame($this->upsertFrame($this->serializedGroup('sp_hydrate', 'Mirror'), 1)));
    }

    // =================================================================
    // MED-1(2) — a merge may NOT silently evict a member this worker still
    // holds a LIVE socket for; MED-1(3) — a blob cannot install a ghost host.
    // =================================================================

    protected function tearDown(): void
    {
        ConnectionPool::getInstance()->clear();
    }

    public function testMergeOmittingALiveConnectedMemberKeepsThemAndLogs(): void
    {
        $pool = ConnectionPool::getInstance();
        $live = new TestConnection('sock-live');
        $pool->add($live);

        $ws = new SyncPlayManager();
        $created = $ws->createGroup('Live Room', null, 'u1', 'One', 'sock-live');
        $groupId = $created['group']['group_id'];
        $this->assertIsString($groupId);

        // Forged-style blob: membership rewritten to a stranger, u1 omitted.
        $blob = GroupState::deserialize([
            'id' => $groupId,
            'name' => 'Hijacked',
            'members' => ['u9' => ['name' => 'Nine', 'connection_id' => null, 'joined_at' => 1, 'is_active' => true]],
            'host_id' => 'u9',
        ])->serialize();

        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($blob, 900)));

        $state = $ws->getGroupState($groupId);
        $this->assertNotNull($state);
        $this->assertArrayHasKey('u1', $state['members'], 'a live-socketed member must never be silently evicted');
        $this->assertArrayHasKey('u9', $state['members'], 'the REST-owned additions still merge');
        $this->assertSame('u9', $state['host_id'], 'host facet stays REST-owned (u9 is a member, so it is legal)');
    }

    public function testMergeStillEvictsMembersWithoutALiveSocketOnThisWorker(): void
    {
        $ws = new SyncPlayManager();
        $created = $ws->createGroup('Phantom Room', null, 'u1', 'One', 'sock-ghost');
        $groupId = $created['group']['group_id'];
        $this->assertIsString($groupId);

        // 'sock-ghost' is deliberately NOT registered in the ConnectionPool —
        // parity with today's behavior for phantom/idle entries: REST owns them.
        $blob = GroupState::deserialize([
            'id' => $groupId,
            'name' => 'Cleared',
            'members' => [],
            'host_id' => null,
        ])->serialize();

        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($blob, 900)));

        $state = $ws->getGroupState($groupId);
        $this->assertNotNull($state);
        $this->assertSame([], $state['members'], 'non-live members remain fully REST-evictable');
        $this->assertNull($ws->getMemberGroup('u1'));
    }

    public function testAdoptedBlobClaimingAGhostHostIsCorrectedByElection(): void
    {
        $ws = new SyncPlayManager();

        // Raw (not helper-built) blob: helpers round-trip through deserialize(),
        // which now repairs host∈members itself — so forge the shape directly.
        $forged = [
            'id' => 'sp_ghost_host',
            'name' => 'Claimed',
            'password_hash' => null,
            'members' => [
                'u2' => ['name' => 'Two', 'connection_id' => null, 'joined_at' => 5, 'is_active' => true],
                'u1' => ['name' => 'One', 'connection_id' => null, 'joined_at' => 2, 'is_active' => true],
            ],
            'host_id' => 'attacker-never-a-member',
        ];

        $this->assertTrue($ws->applyBridgeFrame($this->upsertFrame($forged, 100)));

        $state = $ws->getGroupState('sp_ghost_host');
        $this->assertNotNull($state);
        $this->assertSame('u1', $state['host_id'], 'a ghost host claim collapses to the oldest-member election');
    }
}
