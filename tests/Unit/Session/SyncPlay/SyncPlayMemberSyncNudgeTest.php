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
use Phlix\Session\SyncPlay\Messages;
use Phlix\Session\SyncPlay\SyncPlayBridge;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Tests\Unit\Server\WebSocket\TestableSyncPlayManager;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;

/**
 * S446 — the member sync policy, end to end on the live ingest path.
 *
 * Decided reaction (owner ruling 2026-09-12): an out-of-sync member's own
 * playback_sync report is STORED, JUDGED by the predicate S291 removed
 * (revived here with the storage that makes it live), and answered with a
 * rate-limited NUDGE on the playback_sync family — soft drift/rate keys,
 * NEVER a seek. These tests consciously retire S291's dead-code guard:
 * the both-directions pair below is its replacement, and the guard's
 * own file carries the retirement note in GroupStateTest.
 *
 * Outbound conformance rides OutboundFrameShapeGuardTest (must stay green);
 * the pure predicate/cooldown/storage units are GroupStateTest.
 */
final class SyncPlayMemberSyncNudgeTest extends TestCase
{
    /**
     * Sentinel proving this file is the test code home of the S446 policy;
     * the predicate-side twin is GroupState::SYNC_NUDGE_POLICY_ID.
     */
    public const LANE_TOKEN = 'S446NUDGEPOIX9Q4';

    /** Anything below this is a seconds-based timestamp, not milliseconds. */
    private const MIN_MILLIS = 1_000_000_000_000;

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

    public function testNudgePolicySentinelIsCodeResident(): void
    {
        $this->assertSame(self::LANE_TOKEN, GroupState::SYNC_NUDGE_POLICY_ID);
    }

    /**
     * AC (guard-retirement direction 1): an out-of-sync report yields EXACTLY
     * ONE bounded nudge — on the playback_sync family, aimed at the reporter,
     * carrying soft drift/rate guidance keys, and no seek anywhere.
     */
    public function testOutOfSyncReportYieldsExactlyOneBoundedNudge(): void
    {
        [$a, $b] = $this->seedPlayingRoom();

        // user-b reports 1000ms while the group is authoritative at 5000ms:
        // drift 4000ms > POSITION_TOLERANCE 2000ms.
        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_PLAYBACK_SYNC,
            'position' => 1000,
        ]);

        $nudges = $this->nudgeFrames($b);
        $this->assertCount(1, $nudges, 'the out-of-sync reporter gets exactly one nudge');
        $this->assertSame([], $this->nudgeFrames($a), 'the in-sync host gets no directive');

        $nudge = $nudges[0];
        $this->assertSame('syncplay_playback_sync', $nudge['type']);
        $this->assertSame('user-b', $nudge['member_id'] ?? null, 'the nudge is aimed at the reporter');
        $this->assertSame(5000, $nudge['position'] ?? null, 'and carries the authoritative group position');

        /** @var array<string, mixed> $directive */
        $directive = $nudge['nudge'];
        $this->assertSame(4000, $directive['drift_ms']);
        $this->assertSame('behind', $directive['direction']);
        $this->assertSame(1 + GroupState::NUDGE_RATE_STEP, $directive['suggested_rate']);
        $this->assertSame(GroupState::POSITION_TOLERANCE, $directive['tolerance_ms']);
        $this->assertSame(GroupState::NUDGE_COOLDOWN_MS, $directive['cooldown_ms']);

        // The NUDGE is guidance, never a hostile seek: no seek frame was produced.
        $this->assertSame(
            [],
            $this->framesOfType($b, Messages::TYPE_PLAYBACK_SEEK),
            'S446 policy: out-of-sync answers with a nudge, never a seek'
        );

        // Storage AC: the report was kept, in ms, against the reporter's identity.
        $group = $this->manager->getGroup($this->groupIdOf($b));
        $this->assertNotNull($group);
        $stored = $group->getMemberPosition('user-b');
        $this->assertNotNull($stored, 'position stored per member');
        $this->assertSame(1000, $stored['position']);
    }

    /**
     * AC (guard-retirement direction 2): an in-sync report yields NO nudge —
     * the reporter receives only the unchanged group state echo.
     */
    public function testInSyncReportYieldsNoNudge(): void
    {
        [, $b] = $this->seedPlayingRoom();

        // user-b reports 5500ms against an authoritative 5000ms: 500ms inside tolerance.
        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_PLAYBACK_SYNC,
            'position' => 5500,
        ]);

        $this->assertSame([], $this->nudgeFrames($b), 'an in-sync member must never be nudged');

        $echoes = $this->framesOfType($b, Messages::TYPE_PLAYBACK_SYNC);
        $this->assertCount(1, $echoes, 'the unchanged state echo still arrives, exactly once');
        $this->assertArrayNotHasKey('nudge', $echoes[0]);
    }

    /**
     * The rate bound in force: three consecutive out-of-sync reports (well
     * inside one cooldown window) yield THREE echoes but exactly ONE nudge.
     * Idempotent per tick: one ingest evaluates one decision, one send.
     */
    public function testNudgeIsRateLimitedToOneDirectivePerCooldownWindow(): void
    {
        [, $b] = $this->seedPlayingRoom();

        for ($report = 0; $report < 3; $report++) {
            $this->manager->publicHandleMessage($b, [
                'type' => Messages::TYPE_PLAYBACK_SYNC,
                'position' => 1000 + $report,
            ]);
        }

        $this->assertCount(
            3,
            $this->plainEchoFrames($b),
            'every report keeps its unchanged group echo (nudge frames are not echoes)'
        );
        $this->assertCount(1, $this->nudgeFrames($b), 'the nudge channel is cooldown-bounded per member');
        $this->assertCount(
            4,
            $this->framesOfType($b, Messages::TYPE_PLAYBACK_SYNC),
            '3 echoes + 1 nudge share the playback_sync family — the nudge is additive, never a replacement'
        );
    }

    /**
     * A report without a usable position stores nothing and can provoke
     * nothing — legacy position-less playback_sync requests (WsAuthentication,
     * E2E, FrameShapeGuard) keep their exact pre-S446 shape.
     */
    public function testPositionlessReportIsInertAndStoresNothing(): void
    {
        [, $b] = $this->seedPlayingRoom();

        $this->manager->publicHandleMessage($b, ['type' => Messages::TYPE_PLAYBACK_SYNC]);
        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_PLAYBACK_SYNC,
            'position' => 'not-a-number',
        ]);

        $this->assertSame([], $this->nudgeFrames($b));
        $group = $this->manager->getGroup($this->groupIdOf($b));
        $this->assertNotNull($group);
        $this->assertNull($group->getMemberPosition('user-b'));
    }

    /**
     * S417 outbound rule for the new frame: factory-shaped {type,
     * protocol_version: 1, ms timestamp}, flat, additive `nudge` key only —
     * a tolerant decoder that requires nothing but `type` still consumes it.
     */
    public function testNudgeFrameConformsToTheMessagesFactoryEnvelope(): void
    {
        [, $b] = $this->seedPlayingRoom();

        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_PLAYBACK_SYNC,
            'position' => 0,
        ]);

        $nudges = $this->nudgeFrames($b);
        $this->assertCount(1, $nudges);
        $frame = $nudges[0];

        $this->assertSame(Messages::PROTOCOL_VERSION, $frame['protocol_version'] ?? null);
        $this->assertIsInt($frame['timestamp'] ?? null);
        $this->assertGreaterThanOrEqual(self::MIN_MILLIS, (int) $frame['timestamp']);
        $this->assertArrayNotHasKey('data', $frame, 'frame must stay flat canonical');
        $this->assertArrayNotHasKey('to_position', $frame, 'a nudge never smuggles a seek target');
    }

    /**
     * Bridge-interaction rule (S446 half of the S445 contract): per-member
     * positions are WS-worker-local live state. A mirror upsert replaces the
     * REST-owned membership/host facets and must neither fabricate nor clobber
     * stored positions; only wholesale replacement (adopt of an unseen group,
     * bridge delete) resets them, together with the live object itself.
     */
    public function testBridgeUpsertMergesFacetsWithoutTouchingLivePositions(): void
    {
        $manager = new SyncPlayManager();
        $created = $manager->createGroup('Bridge Room', null, 'u1', 'One', 'conn-u1');
        $this->assertTrue($created['success']);
        /** @var array{group: array{group_id: string}} $created */
        $groupId = $created['group']['group_id'];

        $group = $manager->getGroup($groupId);
        $this->assertNotNull($group);
        $this->assertTrue($group->recordMemberPosition('u1', 42000, 5000));

        // A REST-side rename mirror arrives (bigger stamp, merge path).
        $mirror = GroupState::deserialize([
            'id' => $groupId,
            'name' => 'Bridge Room',
            'members' => [
                'u1' => ['name' => 'Renamed', 'connection_id' => null, 'joined_at' => 1, 'is_active' => true],
            ],
            'host_id' => 'u1',
        ])->serialize();
        $this->assertArrayNotHasKey('member_positions', $mirror, 'the mirror cannot even express positions');

        $this->assertTrue($manager->applyBridgeFrame(
            SyncPlayBridge::frame(SyncPlayBridge::OP_GROUP_UPSERT, ['group' => $mirror], 7000)
        ));

        $live = $manager->getGroup($groupId);
        $this->assertNotNull($live);
        $this->assertSame(
            ['position' => 42000, 'at_ms' => 5000],
            $live->getMemberPosition('u1'),
            'the merge preserved the worker-local live position untouched'
        );
        $state = $manager->getGroupState($groupId);
        $this->assertIsArray($state);

        // The delete op is the only mirror path that erases, and only by
        // replacing the whole group object.
        $this->assertTrue($manager->applyBridgeFrame(
            SyncPlayBridge::frame(SyncPlayBridge::OP_GROUP_DELETE, ['group_id' => $groupId], 8000)
        ));
        $this->assertNull($manager->getGroup($groupId));
    }

    // ── Harness ───────────────────────────────────────────────────────────

    /**
     * Two-member room, host user-a PLAYING at 5000ms (via the real host play
     * command so the group truth is set exactly as production sets it).
     *
     * @return array{0: TestConnection, 1: TestConnection}
     */
    private function seedPlayingRoom(): array
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
            'group_name' => 'Nudge Room',
            'member_name' => 'A',
        ]);
        $createFrames = $a->framesOfType(Messages::TYPE_GROUP_STATE);
        $this->assertNotEmpty($createFrames, 'seed: create must answer with group_state');
        /** @var array<string, mixed> $group */
        $group = $createFrames[count($createFrames) - 1]['group'] ?? [];
        /** @var string $groupId */
        $groupId = (string) ($group['group_id'] ?? '');
        $this->assertNotSame('', $groupId, 'seed: group_id must be present');

        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'member_name' => 'B',
        ]);

        $this->manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_PLAYBACK_PLAY,
            'position' => 5000,
        ]);

        $live = $this->manager->getGroup($groupId);
        $this->assertNotNull($live);
        $this->assertTrue($live->isPlaying(), 'seed: room must be playing at 5000ms');
        $this->assertSame(5000, $live->getPlaybackPosition());

        return [$a, $b];
    }

    private function groupIdOf(TestConnection $connection): ?string
    {
        $userId = $connection->getUserId();
        $this->assertIsString($userId);

        return $this->manager->getMemberGroup($userId);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function framesOfType(TestConnection $connection, string $type): array
    {
        return $connection->framesOfType($type);
    }

    /**
     * The playback_sync frames that carry the S446 soft directive.
     *
     * @return list<array<array-key, mixed>>
     */
    private function nudgeFrames(TestConnection $connection): array
    {
        return array_values(array_filter(
            $connection->framesOfType(Messages::TYPE_PLAYBACK_SYNC),
            static fn (array $frame): bool => array_key_exists('nudge', $frame)
        ));
    }

    /**
     * The unchanged group-state echoes: playback_sync frames WITHOUT a directive.
     *
     * @return list<array<array-key, mixed>>
     */
    private function plainEchoFrames(TestConnection $connection): array
    {
        return array_values(array_filter(
            $connection->framesOfType(Messages::TYPE_PLAYBACK_SYNC),
            static fn (array $frame): bool => !array_key_exists('nudge', $frame)
        ));
    }
}
