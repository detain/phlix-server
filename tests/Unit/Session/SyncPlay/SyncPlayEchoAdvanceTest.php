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
use Phlix\Session\SyncPlay\Messages;
use Phlix\Server\WebSocket\ConnectionPool;
use Phlix\Server\WebSocket\MessageHandler;
use Phlix\Tests\Unit\Server\WebSocket\TestableSyncPlayManager;
use Phlix\Tests\Unit\Server\WebSocket\TestConnection;

/**
 * Incident playjump — the echo-path rubber-band pin (field fingerprint).
 *
 * Fingerprint of the reported defect (INCIDENT-playjump-era.md): repeated
 * `syncplay_playback_sync` frames carrying a STATIC `position` while playing.
 * Every echo re-fires the client 'play'-arm drift watcher, which seeks back to
 * the raw frozen anchor → rubber-band backward seeks every 2-5s.
 *
 * Cure law: the group position the echo carries ADVANCES while playing, on the
 * uniform Messages::nowMs() clock — pos = anchor + (nowMs − anchorAtMs),
 * clamped to [0, duration] when known; paused/stopped groups echo the raw
 * anchor. The echoes carry no other position authority: the nudge unicast of
 * the same frame family must advance identically, on the same tick.
 *
 * The echo clock is injected through TestableSyncPlayManager's syncNowMs seam,
 * so both echoes share ONE anchor stamp and the inter-echo delta is EXACT —
 * deterministic red before the cure (delta was always 0), deterministic green
 * after, never flaky on real-clock ms ticks.
 *
 * @see GroupStateAnchorAdvanceTest For the pure read-side projection law
 */
final class SyncPlayEchoAdvanceTest extends TestCase
{
    /** Raw anchor the seed play command stamped (ms). */
    private const SEED_ANCHOR_MS = 5_000;

    /** Upper bound on this test's own wall runtime between seed and echo (ms). */
    private const RUNTIME_SLACK_MS = 60_000;

    private TestableSyncPlayManager $manager;

    private TestConnection $hostConn;

    private TestConnection $memberConn;

    /** Anchor wall-time captured right after the seed play command. */
    private int $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = ConnectionPool::getInstance();
        $pool->clear();
        $this->manager = new TestableSyncPlayManager(new MessageHandler($pool));
        $this->seedPlayingRoom();
        $this->t0 = Messages::nowMs();
    }

    protected function tearDown(): void
    {
        ConnectionPool::getInstance()->clear();
        parent::tearDown();
    }

    public function testGroupEchoAdvancesByExactlyTheInjectedClockDeltaWithoutAnyMemberReport(): void
    {
        $first = $this->requestEchoAt($this->t0 + 5_000);
        $second = $this->requestEchoAt($this->t0 + 10_000);

        $this->assertSame(
            5_000,
            $second - $first,
            'two echoes 5s apart with NO member report must differ by exactly 5000ms — '
                . 'the frozen-anchor echo (delta 0) is the rubber-band fingerprint'
        );
    }

    public function testEchoPositionIsStrictlyMonotonicAcrossTheFiveSecondCadence(): void
    {
        $positions = [];
        for ($tick = 1; $tick <= 3; $tick++) {
            $positions[] = $this->requestEchoAt($this->t0 + 5_000 * $tick);
        }

        $this->assertSame(
            [5_000, 5_000],
            [$positions[1] - $positions[0], $positions[2] - $positions[1]],
            'the cadence walk advances by the clock, never repeats (no re-anchoring watcher fires)'
        );
        $this->assertGreaterThanOrEqual(
            self::SEED_ANCHOR_MS + 5_000,
            $positions[0],
            'the first echo already sits ahead of the anchor plus one cadence step'
        );
    }

    public function testPausedGroupEchoesTheRawAnchor(): void
    {
        $this->manager->publicHandleMessage($this->hostConn, [
            'type' => Messages::TYPE_PLAYBACK_PAUSE,
            'position' => 5_500,
        ]);

        $first = $this->requestEchoAt($this->t0 + 100_000);
        $second = $this->requestEchoAt($this->t0 + 200_000);

        $this->assertSame(5_500, $first, 'a paused group has nothing to extrapolate — the echo stays at the raw anchor');
        $this->assertSame(5_500, $second);
    }

    public function testEchoNeverFallsBelowTheAnchorUnderAClockRewind(): void
    {
        // An echo tick behind the anchor stamp must clamp to the raw anchor,
        // never produce a negative-elapsed position below it.
        $echo = $this->requestEchoAt($this->t0 - 60_000);

        $this->assertGreaterThanOrEqual(
            self::SEED_ANCHOR_MS,
            $echo,
            'the echo floor is the raw anchor — a rewound clock clamps elapsed at zero'
        );
    }

    public function testNudgeUnicastCarriesTheSameAdvancedAuthoritativePositionAsTheGroupEcho(): void
    {
        $tick = $this->t0 + 60_000;

        // user-b reports 1000ms while the group advances past it: out of sync,
        // so the S446 nudge unicast fires (same tick drives judge and echo).
        $this->manager->syncNowMsOverride = $tick;
        try {
            $this->manager->publicHandleMessage($this->memberConn, [
                'type' => Messages::TYPE_PLAYBACK_SYNC,
                'position' => 1_000,
            ]);
        } finally {
            $this->manager->syncNowMsOverride = null;
        }

        $nudges = array_values(array_filter(
            $this->memberConn->framesOfType(Messages::TYPE_PLAYBACK_SYNC),
            static fn (array $frame): bool => array_key_exists('nudge', $frame)
        ));
        $echoes = array_values(array_filter(
            $this->memberConn->framesOfType(Messages::TYPE_PLAYBACK_SYNC),
            static fn (array $frame): bool => !array_key_exists('nudge', $frame)
        ));

        $this->assertCount(1, $nudges, 'seed: the drifting reporter is nudged exactly once');
        $this->assertCount(1, $echoes);

        $nudge = $nudges[0];
        $echo = $echoes[0];
        $this->assertIsInt($nudge['position'] ?? null);
        $this->assertIsInt($echo['position'] ?? null);

        $this->assertSame(
            $echo['position'],
            $nudge['position'],
            'one tick, one authoritative position — the nudge frame (same playback_sync family the '
                . 'client watcher re-arms on) must never carry a staler anchor than the group echo'
        );
        $this->assertSame(
            $nudge['position'] - 1_000,
            $nudge['nudge']['drift_ms'],
            'the drift directive and the carried anchor are internally consistent on the same tick'
        );
        $this->assertGreaterThanOrEqual(
            self::SEED_ANCHOR_MS + 60_000,
            (int) $echo['position'],
            'same anchor plus the full 60s jump from the seed tick'
        );
        $this->assertLessThanOrEqual(
            self::SEED_ANCHOR_MS + 60_000 + self::RUNTIME_SLACK_MS,
            (int) $echo['position'],
            'and only that jump — the advance is the clock delta, not invention'
        );
    }

    // ── Harness ───────────────────────────────────────────────────────────

    /**
     * Two-member room, host user-a PLAYING at 5000ms via the REAL host play
     * command, so the anchor is stamped exactly as production stamps it.
     */
    private function seedPlayingRoom(): void
    {
        $this->hostConn = new TestConnection('conn-a');
        $this->hostConn->setAuthenticated(true, 'user-a');
        $this->memberConn = new TestConnection('conn-b');
        $this->memberConn->setAuthenticated(true, 'user-b');

        $pool = ConnectionPool::getInstance();
        $pool->add($this->hostConn);
        $pool->add($this->memberConn);

        $this->manager->publicHandleMessage($this->hostConn, [
            'type' => Messages::TYPE_GROUP_CREATE,
            'group_name' => 'Echo Room',
            'member_name' => 'A',
        ]);
        $createFrames = $this->hostConn->framesOfType(Messages::TYPE_GROUP_STATE);
        $this->assertNotEmpty($createFrames, 'seed: create must answer with group_state');
        /** @var array<string, mixed> $group */
        $group = $createFrames[count($createFrames) - 1]['group'] ?? [];
        $groupId = (string) ($group['group_id'] ?? '');
        $this->assertNotSame('', $groupId, 'seed: group_id must be present');

        $this->manager->publicHandleMessage($this->memberConn, [
            'type' => Messages::TYPE_GROUP_JOIN,
            'group_id' => $groupId,
            'member_name' => 'B',
        ]);

        $this->manager->publicHandleMessage($this->hostConn, [
            'type' => Messages::TYPE_PLAYBACK_PLAY,
            'position' => self::SEED_ANCHOR_MS,
        ]);

        $live = $this->manager->getGroup($groupId);
        $this->assertNotNull($live);
        $this->assertTrue($live->isPlaying(), 'seed: room must be playing at the seed anchor');
        $this->assertSame(self::SEED_ANCHOR_MS, $live->getPlaybackPosition(), 'seed: the RAW anchor is where the host put it');
    }

    /**
     * One positionless playback_sync at the injected tick; returns the
     * authoritative `position` the group echo carried back.
     */
    private function requestEchoAt(int $nowMs): int
    {
        $before = count($this->plainEchoFrames());

        $this->manager->syncNowMsOverride = $nowMs;
        try {
            $this->manager->publicHandleMessage($this->memberConn, ['type' => Messages::TYPE_PLAYBACK_SYNC]);
        } finally {
            $this->manager->syncNowMsOverride = null;
        }

        $echoes = $this->plainEchoFrames();
        $this->assertCount($before + 1, $echoes, 'each report answers with exactly one plain group echo');

        if ($echoes === []) {
            $this->fail('unreachable guard: the assert above already condemned the empty list');
        }

        $position = $echoes[array_key_last($echoes)]['position'] ?? null;
        $this->assertIsInt($position, 'the echo must carry an int ms position');

        return $position;
    }

    /**
     * The playback_sync frames that are unchanged group echoes (no directive).
     *
     * @return list<array<array-key, mixed>>
     */
    private function plainEchoFrames(): array
    {
        return array_values(array_filter(
            $this->memberConn->framesOfType(Messages::TYPE_PLAYBACK_SYNC),
            static fn (array $frame): bool => !array_key_exists('nudge', $frame)
        ));
    }
}
