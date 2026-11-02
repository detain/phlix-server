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

/**
 * Incident playjump — the group anchor's read-side projection, unit law.
 *
 * While a group is PLAYING, the authoritative group position advances from the
 * anchor at 1x:  pos = anchor + (nowMs − anchorAtMs), clamped to
 * [0, duration] when the duration is known. Paused/stopped/buffering groups
 * answer the raw anchor. The clock is uniform Messages::nowMs() milliseconds
 * (NOT time() seconds — the R-SYNCPLAY-05 two-scale class stays out of the
 * advance math). The stamp is LIVE-ONLY state: never serialized, never
 * mirrored, never restorable from a blob — a restore re-stamps at ingest so a
 * restored playing group continues from its anchor without leaping.
 *
 * @see GroupState::getAdvancedPlaybackPosition() For the projection under test
 * @see SyncPlayEchoAdvanceTest                  For the echo-path incident pin
 */
final class GroupStateAnchorAdvanceTest extends TestCase
{
    /** Synthetic anchor-stamp epoch — the units never touch the real clock. */
    private const STAMP_MS = 1_000_000_000_000;

    public function testPlayingAnchorAdvancesAtOneTimesElapsedTime(): void
    {
        $group = $this->playingGroup(anchorMs: 10_000, durationMs: 600_000);

        $this->assertSame(10_000, $group->getAdvancedPlaybackPosition(self::STAMP_MS), 'zero elapsed → the raw anchor');
        $this->assertSame(15_000, $group->getAdvancedPlaybackPosition(self::STAMP_MS + 5_000), '5s of playing time adds 5000ms');
        $this->assertSame(30_000, $group->getAdvancedPlaybackPosition(self::STAMP_MS + 20_000));
    }

    public function testRewoundClockNeverPullsTheEchoBackward(): void
    {
        $group = $this->playingGroup(anchorMs: 10_000, durationMs: 600_000);

        $this->assertSame(
            10_000,
            $group->getAdvancedPlaybackPosition(self::STAMP_MS - 50_000),
            'elapsed clamps at zero: a clock behind the stamp answers the raw anchor, never less'
        );
    }

    public function testAdvanceClampsToDurationWhenKnown(): void
    {
        $group = $this->playingGroup(anchorMs: 55_000, durationMs: 60_000);

        $this->assertSame(60_000, $group->getAdvancedPlaybackPosition(self::STAMP_MS + 10_000), 'a known duration is the ceiling');
        $this->assertSame(60_000, $group->getAdvancedPlaybackPosition(self::STAMP_MS + 600_000), 'and holds no matter how far the clock runs');
    }

    public function testUnknownDurationNeverClamps(): void
    {
        $group = $this->playingGroup(anchorMs: 10_000, durationMs: 0);

        $this->assertSame(20_000, $group->getAdvancedPlaybackPosition(self::STAMP_MS + 10_000));
    }

    public function testNonPlayingStatesEchoTheRawAnchor(): void
    {
        $group = $this->playingGroup(anchorMs: 10_000, durationMs: 600_000);

        foreach ([GroupState::STATE_PAUSED, GroupState::STATE_BUFFERING, GroupState::STATE_STOPPED] as $state) {
            $group->updatePlayback($state, 42_000, self::STAMP_MS);
            $this->assertSame(
                42_000,
                $group->getAdvancedPlaybackPosition(self::STAMP_MS + 90_000),
                "a $state group has nothing to extrapolate — the echo stays at the raw anchor"
            );
        }
    }

    public function testSeekRestampsTheAnchorAndTheAdvanceContinuesFromTheTarget(): void
    {
        $group = $this->playingGroup(anchorMs: 10_000, durationMs: 600_000);

        $group->setPlaybackPosition(30_000, self::STAMP_MS + 10_000);

        $this->assertSame(
            35_000,
            $group->getAdvancedPlaybackPosition(self::STAMP_MS + 15_000),
            'the seek target becomes the new anchor at the new stamp'
        );
    }

    public function testPlayCommandRestampsTheAnchor(): void
    {
        $group = new GroupState('group_123', 'Anchor Test');
        $group->setCurrentMedia('media_1', 600_000);
        $group->updatePlayback(GroupState::STATE_PLAYING, 25_000, self::STAMP_MS + 100_000);

        $this->assertSame(
            28_000,
            $group->getAdvancedPlaybackPosition(self::STAMP_MS + 103_000),
            'the play command re-anchors where the host says playback is'
        );
    }

    public function testAnchorStampRidesTheMirrorButNeverTheBroadcastShape(): void
    {
        $group = $this->playingGroup(anchorMs: 5_000, durationMs: 0);

        $serialized = $group->serialize();
        $this->assertSame(
            self::STAMP_MS,
            $serialized['playback_position_at_ms'],
            'the persisted mirror carries the anchor stamp — handoff continuation depends on it'
        );
        $this->assertArrayNotHasKey(
            'playback_position_at_ms',
            $group->getState(),
            'the public broadcast shape stays exactly the 12 keys it always was'
        );

        // A blob predating the field self-heals to restore-time-now: a tick
        // from the synthetic past clamps to zero elapsed and answers the raw
        // anchor — a legacy (or forged: negative, non-int) blob can never
        // inject a forward leap.
        $legacy = $serialized;
        unset($legacy['playback_position_at_ms']);
        $restored = GroupState::deserialize($legacy);
        $this->assertSame(
            5_000,
            $restored->getAdvancedPlaybackPosition(1_000_000),
            'self-healed legacy blob answers the raw anchor, never a leap'
        );

        $malformed = $serialized;
        $malformed['playback_position_at_ms'] = -1;
        $this->assertSame(
            5_000,
            GroupState::deserialize($malformed)->getAdvancedPlaybackPosition(1_000_000),
            'a negative stamp is as untrusted as a missing one'
        );
    }

    public function testRestoredPlayingGroupKeepsAdvancingAcrossAWorkerHandoff(): void
    {
        $group = $this->playingGroup(anchorMs: 5_000, durationMs: 0);
        $restored = GroupState::deserialize($group->serialize());

        // Handoff semantics: before the worker went away, the echo at
        // STAMP+90s answered 95_000. If the restore instant discards the
        // anchor's stamp, the first post-handoff echo answers 5_000 — the
        // incident's backward rubber-band delivered ONCE PER DEPLOY instead
        // of once per 5s. One-off is not a cure: the stamp must survive the
        // round trip for the cure to be real.
        $this->assertSame(
            95_000,
            $restored->getAdvancedPlaybackPosition(self::STAMP_MS + 90_000),
            'a restored playing group continues advancing from the ORIGINAL anchor stamp'
        );
    }

    public function testAdvanceIsAReadOnlyProjectionTheRawAnchorStaysFrozen(): void
    {
        $group = $this->playingGroup(anchorMs: 10_000, durationMs: 600_000);

        $this->assertSame(30_000, $group->getAdvancedPlaybackPosition(self::STAMP_MS + 20_000));
        $this->assertSame(
            10_000,
            $group->getPlaybackPosition(),
            'the advance is a read-side projection — the stored anchor is untouched, so '
                . 'serialize()/getState()/REST snapshot semantics keep riding the raw anchor'
        );
        $this->assertSame(
            30_000,
            $group->getAdvancedPlaybackPosition(self::STAMP_MS + 20_000),
            'and repeating the read on the same tick repeats the same projection (pure)'
        );
    }

    public function testSyncPredicateJudgesAgainstTheAdvancedAnchorWhenGivenTheClock(): void
    {
        $group = new GroupState('group_123', 'Anchor Test');
        $group->addMember('member_1', ['name' => 'One']);
        $group->addMember('member_2', ['name' => 'Two']);
        $group->setCurrentMedia('media_1', 600_000);
        $group->updatePlayback(GroupState::STATE_PLAYING, 10_000, self::STAMP_MS);

        $tick = self::STAMP_MS + 60_000;

        // A member faithfully tracking the ADVANCING anchor (60s of playing
        // time on) is in sync when judged on the same tick — the cure must
        // never storm nudges at members who are actually in sync.
        $this->assertTrue($group->recordMemberPosition('member_1', 70_000, $tick));
        $this->assertTrue($group->isMemberInSync('member_1', $tick), 'in sync with the advancing anchor → never nudged');

        // A member stuck at the raw anchor is 60s behind the advancing group —
        // the drift truth the nudge channel must see on the same tick.
        $this->assertTrue($group->recordMemberPosition('member_2', 10_000, $tick));
        $this->assertFalse($group->isMemberInSync('member_2', $tick), 'anchored to the frozen position → genuinely behind');

        // S291 verbatim default: without a clock the window stays on the raw anchor.
        $this->assertTrue($group->isInSync(10_000 + GroupState::POSITION_TOLERANCE));
        $this->assertFalse($group->isInSync(10_000 + GroupState::POSITION_TOLERANCE + 1));
        // With the clock, the window moves with the anchor.
        $this->assertTrue($group->isInSync(70_000 + GroupState::POSITION_TOLERANCE, $tick));
        $this->assertFalse($group->isInSync(70_000 + GroupState::POSITION_TOLERANCE + 1, $tick));
    }

    /**
     * @return GroupState A playing group anchored at $anchorMs stamped $STAMP_MS.
     */
    private function playingGroup(int $anchorMs, int $durationMs): GroupState
    {
        $group = new GroupState('group_123', 'Anchor Test');
        $group->setCurrentMedia('media_1', $durationMs);
        $group->updatePlayback(GroupState::STATE_PLAYING, $anchorMs, self::STAMP_MS);

        return $group;
    }
}
