<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\GroupState;
use Phlix\Session\SyncPlay\Messages;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Tests\Unit\Server\WebSocket\TestableSyncPlayManager;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;

/**
 * INCIDENT playjump — the rubber-band cure, server half (owner directive 2026-11-02).
 *
 * Root cause (INCIDENT-playjump-fieldtest.md): while state=playing the playback_sync
 * echo carried the RAW, never-advancing group anchor as `position` (the anchor moves
 * only on explicit host play/pause/seek), so every ~5s echo dragged drift-correcting
 * clients BACK to the stale anchor — "periodically jump back a few seconds".
 *
 * Canonical cure semantics (the hub twin adopts these verbatim): while playing, the
 * echo position is ANCHOR-ADVANCED —
 *
 *     pos = anchor + floor((nowMs − anchorUpdatedAtMs) / 1000) · 1000 · rate
 *
 * clamped to [0, duration] when a duration is known; paused/stopped keep the raw
 * anchor; seek/play/pause keep refreshing anchor + timestamp exactly as before.
 * The advance is quantized to whole seconds: the symptom operates at the
 * POSITION_TOLERANCE (2000ms) scale, sub-second advance precision buys nothing,
 * and the quantization keeps every pre-existing sub-second echo/nudge pin
 * byte-identical (raw-ms advance would move `position === 5000` assertions by
 * test-run jitter — a flake class, refused).
 *
 * Disclosed semantic split: the `position` VALUE of BOTH playback_sync emitters
 * (group echo + S446 nudge frame) advances, while the S446 sync PREDICATE and
 * `drift_ms` continue to judge against the RAW anchor (S291-verbatim pins stay
 * untouched; the nudge guidance keys have zero estate consumers per the field
 * test). The ui seeks on any playback_sync frame, so curing only the plain echo
 * would leave the nudge frame rubber-banding once per cooldown window.
 *
 * `rate` is a formula parameter only: no server state carries a playback rate
 * today and the report payload has no rate key (payload keys are unchanged by
 * this cure), so echo call sites advance at 1.0.
 *
 * Mutation teeth: strip the extrapolation at the echo sites and the manager
 * tests go red (echo == raw); strip it inside GroupState and the pure-method
 * tests go red; invert the [0, duration] clamp, the clamp pins go red; ignore
 * the injected rate, the rate pins go red.
 */
final class SyncPlayAnchorAdvanceEchoTest extends TestCase
{
    private TestableSyncPlayManager $manager;

    /** @var string */
    private string $groupId = '';

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

    // ── Manager-level echo semantics (the symptom path) ──────────────────

    /**
     * THE incident test: two consecutive member reports while playing produce
     * STRICTLY ADVANCING echo positions while the stored raw anchor never
     * moves. Pre-cure this is `5000, 5000` (the rubber-band); the expected
     * advancing value is NOT the raw value.
     */
    public function testConsecutiveEchoesAdvanceWhileRawAnchorStaysFrozen(): void
    {
        [, $b] = $this->seedPlayingRoom();

        // Anchor stays 5000; its stamp is back-dated 30.4s (floor = 30s with a
        // >500ms safety margin against run-time drift).
        $this->backdateAnchor(5000, 30_400);
        $this->memberReport($b, 5000);
        $first = $this->latestEcho($b);
        $this->assertSame(
            35000,
            $first['position'] ?? null,
            'echo while playing must carry anchor + whole-second advance, not the raw anchor'
        );

        // Same raw anchor, stamp pushed to 61.3s ago (floor = 61s) → echo advances further.
        $this->backdateAnchor(5000, 61_300);
        $this->memberReport($b, 5000);
        $second = $this->latestEcho($b);
        $this->assertSame(66000, $second['position'] ?? null, 'second echo advances with wall clock');
        $this->assertGreaterThan(
            (int) $first['position'],
            (int) $second['position'],
            'consecutive echoes advance across reports (Expected advancing ≠ raw)'
        );

        $group = $this->manager->getGroup($this->groupId);
        $this->assertNotNull($group);
        $this->assertSame(5000, $group->getPlaybackPosition(), 'the raw anchor itself is untouched');
    }

    /**
     * A paused room keeps the raw anchor verbatim in the echo, no matter how
     * stale the anchor stamp is.
     */
    public function testPausedEchoStaysRawAnchor(): void
    {
        [, $b] = $this->seedPlayingRoom();

        $group = $this->manager->getGroup($this->groupId);
        $this->assertNotNull($group);
        $group->updatePlayback(GroupState::STATE_PAUSED, 42_000, Messages::nowMs() - 100_700);

        $this->memberReport($b, 42_000);
        $echo = $this->latestEcho($b);
        $this->assertSame(42_000, $echo['position'] ?? null, 'paused → raw anchor unchanged');
        $this->assertFalse($echo['is_playing'] ?? true, 'is_playing keeps reflecting the state');
    }

    /**
     * Stopped rooms likewise answer with the raw anchor.
     */
    public function testStoppedEchoStaysRawAnchor(): void
    {
        [, $b] = $this->seedPlayingRoom();

        $group = $this->manager->getGroup($this->groupId);
        $this->assertNotNull($group);
        $group->updatePlayback(GroupState::STATE_STOPPED, 42_000, Messages::nowMs() - 100_700);

        $this->memberReport($b, 0);
        $echo = $this->latestEcho($b);
        $this->assertSame(42_000, $echo['position'] ?? null, 'stopped → raw anchor unchanged');
    }

    /**
     * The advance is clamped at the known duration — the echo never promises a
     * position past the end of the media.
     */
    public function testEchoClampsToDurationAtTheEndOfMedia(): void
    {
        [, $b] = $this->seedPlayingRoom();

        $group = $this->manager->getGroup($this->groupId);
        $this->assertNotNull($group);
        // Media of 60s; anchor 55s in with a 30.4s-stale stamp → raw advance 85000 → clamp 60000.
        $group->setCurrentMedia('media_1', 60_000);
        $group->updatePlayback(GroupState::STATE_PLAYING, 55_000, Messages::nowMs() - 30_400);

        $this->memberReport($b, 55_000);
        $echo = $this->latestEcho($b);
        $this->assertSame(60_000, $echo['position'] ?? null, 'advance clamps at [0, duration] when known');
    }

    /**
     * The S446 nudge frame rides the SAME playback_sync family and the ui
     * consumes its `position` identically — so it must carry the SAME advanced
     * value as the group echo of the same tick, while its drift_judgement keys
     * stay verbatim-raw (disclosed split; S291 semantics preserved).
     */
    public function testNudgeFramePositionMatchesTheAdvancedEchoOfItsTick(): void
    {
        [, $b] = $this->seedPlayingRoom();

        // Back-date 30.4s: expected position 35000. A 1000ms report is out of sync
        // against the RAW 5000 anchor by exactly 4000ms (verbatim S446 maths).
        $this->backdateAnchor(5000, 30_400);
        $this->memberReport($b, 1000);

        $echo = $this->latestEcho($b);
        $nudges = array_values(array_filter(
            $b->framesOfType(Messages::TYPE_PLAYBACK_SYNC),
            static fn (array $frame): bool => array_key_exists('nudge', $frame)
        ));
        $this->assertCount(1, $nudges, 'the raw-anchor predicate verdict is unchanged by the cure');
        $nudge = $nudges[0];

        $this->assertSame(
            $echo['position'] ?? null,
            $nudge['position'] ?? null,
            'both playback_sync emitters of one tick carry the SAME anchor-advanced position'
        );
        $this->assertSame(35000, $nudge['position'] ?? null);

        /** @var array<string, mixed> $directive */
        $directive = $nudge['nudge'];
        $this->assertSame(4000, $directive['drift_ms'], 'drift_ms keeps the verbatim raw-anchor maths');
        $this->assertSame('behind', $directive['direction']);
    }

    // ── Pure GroupState semantics (canonical law the hub mirrors) ────────

    /**
     * The canonical formula, pure and clock-injected: anchor + elapsed-seconds × rate.
     */
    public function testExpectedPositionIsTheCanonicalAdvanceFormula(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->setCurrentMedia('media_1', 0);
        $group->updatePlayback(GroupState::STATE_PLAYING, 10_000, 1_700_000_000_000);

        // 10s of wall clock at rate 1 → +10_000ms.
        $this->assertSame(20_000, $group->expectedPlaybackPosition(1_700_000_010_400));
        // The same tick at rate 2 doubles the advance; rate 0.5 halves it.
        $this->assertSame(30_000, $group->expectedPlaybackPosition(1_700_000_010_400, 2.0));
        $this->assertSame(15_000, $group->expectedPlaybackPosition(1_700_000_010_400, 0.5));
        // Sub-second elapsed advances nothing (whole-second quantization).
        $this->assertSame(10_000, $group->expectedPlaybackPosition(1_700_000_000_400));
        // And the stored anchor is never mutated by reading the expectation.
        $this->assertSame(10_000, $group->getPlaybackPosition(), 'the expectation is pure');
    }

    /**
     * Clamp + rewound-clock law: [0, duration] when duration known, never
     * behind the anchor when `now` predates the stamp (NTP step back cannot
     * make the echo reverse).
     */
    public function testExpectedPositionClampsAndGuardsTheClock(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->setCurrentMedia('media_1', 60_000);
        $group->updatePlayback(GroupState::STATE_PLAYING, 55_000, 1_700_000_000_000);

        $this->assertSame(60_000, $group->expectedPlaybackPosition(1_700_000_030_400), 'clamp at duration');
        $this->assertSame(60_000, $group->expectedPlaybackPosition(1_700_000_030_400, 2.0), 'clamp at rate 2');
        $this->assertSame(
            55_000,
            $group->expectedPlaybackPosition(1_699_999_990_000),
            'a clock behind the anchor stamp must not rewind the anchor'
        );
    }

    /**
     * Non-playing states answer the raw anchor verbatim through the pure method.
     */
    public function testExpectedPositionIsRawWhenNotPlaying(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->setCurrentMedia('media_1', 60_000);
        $group->updatePlayback(GroupState::STATE_PAUSED, 30_000, 1_700_000_000_000);
        $this->assertSame(30_000, $group->expectedPlaybackPosition(1_700_000_060_400));

        $group->updatePlayback(GroupState::STATE_STOPPED, 31_000, 1_700_000_000_000);
        $this->assertSame(31_000, $group->expectedPlaybackPosition(1_700_000_060_400));

        // BUFFERING is not PLAYING either — extrapolation is reserved for real playback.
        $group->updatePlayback(GroupState::STATE_BUFFERING, 32_000, 1_700_000_000_000);
        $this->assertSame(32_000, $group->expectedPlaybackPosition(1_700_000_060_400));
    }

    /**
     * Fail-fast on nonsense rates: negative would rewind, non-finite would
     * explode the position. Both refuse loudly instead.
     */
    public function testNonsenseRateFailsFast(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->setCurrentMedia('media_1', 60_000);
        $group->updatePlayback(GroupState::STATE_PLAYING, 10_000, 1_700_000_000_000);

        $this->expectException(\InvalidArgumentException::class);
        $group->expectedPlaybackPosition(1_700_000_010_400, -1.0);
    }

    /**
     * The anchor stamp is a facet OF THE ANCHOR (not live-only member state):
     * it rides serialize()/deserialize() so a bridge adopt or worker restart
     * keeps advancing the echo instead of re-freezing it at restore time.
     */
    public function testAnchorStampSurvivesSerializeRoundTrip(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->setCurrentMedia('media_1', 0);
        $group->updatePlayback(GroupState::STATE_PLAYING, 5_000, 1_700_000_000_000);

        $serialized = $group->serialize();
        $this->assertArrayHasKey('playback_position_at_ms', $serialized);
        $this->assertSame(1_700_000_000_000, $serialized['playback_position_at_ms']);

        $restored = GroupState::deserialize($serialized);
        $this->assertSame(35_000, $restored->expectedPlaybackPosition(1_700_000_030_400));
        $this->assertSame(5_000, $restored->getPlaybackPosition(), 'restore keeps the raw anchor raw');
    }

    /**
     * Pre-cure blobs (and hand-built mirror frames) carry no stamp: restore
     * self-heals the baseline to the restore moment — the group behaves like
     * it was just re-anchored, never like it anchored at epoch 0.
     */
    public function testLegacyBlobWithoutAnchorStampSelfHealsToRestoreTime(): void
    {
        $restored = GroupState::deserialize([
            'id' => 'group_123',
            'name' => 'Test Group',
            'playback_state' => GroupState::STATE_PLAYING,
            'playback_position' => 5_000,
        ]);

        $this->assertSame(
            5_000,
            $restored->expectedPlaybackPosition(Messages::nowMs()),
            'the restored baseline is the restore instant, not the epoch'
        );
        $this->assertSame(
            66_000,
            $restored->expectedPlaybackPosition(Messages::nowMs() + 61_300),
            'advance continues from the restore instant'
        );
    }

    /**
     * Explicit same-instant anchoring (a fresh play command lands anchor+stamp
     * together): the echo of the very next tick is the raw value.
     */
    public function testFreshAnchorEchoesRawUntilAWholeSecondPasses(): void
    {
        $group = new GroupState('group_123', 'Test Group');
        $group->setCurrentMedia('media_1', 0);
        $anchorAt = 1_700_000_000_000;
        $group->updatePlayback(GroupState::STATE_PLAYING, 10_000, $anchorAt);

        $this->assertSame(10_000, $group->expectedPlaybackPosition($anchorAt));
        $this->assertSame(10_000, $group->expectedPlaybackPosition($anchorAt + 999));
        $this->assertSame(11_000, $group->expectedPlaybackPosition($anchorAt + 1_000));
    }

    // ── Harness ───────────────────────────────────────────────────────────

    /**
     * Two-member room, host user-a PLAYING at 5000ms via the real host play
     * command (production sets group truth exactly this way), no media
     * duration (0 = unknown → no clamp).
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
            'group_name' => 'Anchor Room',
            'member_name' => 'A',
        ]);
        $createFrames = $a->framesOfType(Messages::TYPE_GROUP_STATE);
        $this->assertNotEmpty($createFrames, 'seed: create must answer with group_state');
        /** @var array<string, mixed> $group */
        $group = $createFrames[count($createFrames) - 1]['group'] ?? [];
        $this->groupId = (string) ($group['group_id'] ?? '');
        $this->assertNotSame('', $this->groupId, 'seed: group_id must be present');

        $this->manager->publicHandleMessage($b, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $this->groupId,
            'member_name' => 'B',
        ]);

        $this->manager->publicHandleMessage($a, [
            'type' => Messages::TYPE_PLAYBACK_PLAY,
            'position' => 5000,
        ]);

        $live = $this->manager->getGroup($this->groupId);
        $this->assertNotNull($live);
        $this->assertTrue($live->isPlaying(), 'seed: room must be playing at 5000ms');
        $this->assertSame(5000, $live->getPlaybackPosition());

        return [$a, $b];
    }

    /**
     * Injected-clock seam: re-stamp the raw anchor `msAgo` milliseconds in the
     * past WITHOUT moving it (production stamps this via the real play/seek
     * mutations; the back-dated stamp is the deterministic fake clock).
     */
    private function backdateAnchor(int $positionMs, int $msAgo): void
    {
        $group = $this->manager->getGroup($this->groupId);
        $this->assertNotNull($group);
        $group->updatePlayback(GroupState::STATE_PLAYING, $positionMs, Messages::nowMs() - $msAgo);
    }

    private function memberReport(TestConnection $connection, int $positionMs): void
    {
        $this->manager->publicHandleMessage($connection, [
            'type' => Messages::TYPE_PLAYBACK_SYNC,
            'position' => $positionMs,
        ]);
    }

    /**
     * The most recent plain group echo (a playback_sync frame without the
     * S446 directive key) received by the connection.
     *
     * @return array<array-key, mixed>
     */
    private function latestEcho(TestConnection $connection): array
    {
        $echoes = array_values(array_filter(
            $connection->framesOfType(Messages::TYPE_PLAYBACK_SYNC),
            static fn (array $frame): bool => !array_key_exists('nudge', $frame)
        ));
        $this->assertNotEmpty($echoes, 'the reporting member must receive its group echo');

        return $echoes[count($echoes) - 1];
    }
}
