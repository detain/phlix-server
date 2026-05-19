<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\TestCase;
use Phlex\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlex\Plugins\Scrobbler\Lastfm\LastfmScrobbler;
use Phlex\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlex\Shared\Events\Playback\PlaybackStarted;
use Phlex\Shared\Events\Playback\PlaybackStopped;

/**
 * Unit tests for {@see LastfmScrobbler}.
 *
 * @covers \Phlex\Plugins\Scrobbler\Lastfm\LastfmScrobbler
 *
 * @package Phlex\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmScrobblerTest extends TestCase
{
    /** @var LastfmApi&\PHPUnit\Framework\MockObject\MockObject */
    private LastfmApi $api;
    /** @var LastfmSessionRepository&\PHPUnit\Framework\MockObject\MockObject */
    private LastfmSessionRepository $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->createMock(LastfmApi::class);
        $this->sessions = $this->createMock(LastfmSessionRepository::class);
    }

    public function testMeetsScrobbleRulesRejectsShortTracks(): void
    {
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);
        // 25-second track played 20 seconds — duration <= 30s gate fails first.
        $this->assertFalse($scrobbler->meetsScrobbleRules(25, 20));
        // Edge: exactly 30 also rejected (rule is "longer than 30").
        $this->assertFalse($scrobbler->meetsScrobbleRules(30, 16));
    }

    public function testMeetsScrobbleRulesRejectsLessThanHalfPlayed(): void
    {
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);
        // 120s track, played 30s = 25% — should reject.
        $this->assertFalse($scrobbler->meetsScrobbleRules(120, 30));
        // Exactly 50% — also rejected (rule is "more than 50%").
        $this->assertFalse($scrobbler->meetsScrobbleRules(120, 60));
    }

    public function testMeetsScrobbleRulesAcceptsWhenBothGatesPass(): void
    {
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);
        // 200s track, played 101s = 50.5% — should accept (>30s AND >50%).
        $this->assertTrue($scrobbler->meetsScrobbleRules(200, 101));
    }

    public function testMeetsScrobbleRulesRejectsUnknownDuration(): void
    {
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);
        $this->assertFalse($scrobbler->meetsScrobbleRules(null, 9999));
    }

    public function testOnPlaybackStartedFiresUpdateNowPlaying(): void
    {
        $this->sessions->method('findByUserId')->with('u1')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::once())
            ->method('updateNowPlaying')
            ->with('SK', 'Song', 'Artist', 'Album');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => 'Album', 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(
            new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0)
        );
    }

    public function testOnPlaybackStartedSkipsWhenNoSession(): void
    {
        $this->sessions->method('findByUserId')->willReturn(null);
        $this->api->expects(self::never())->method('updateNowPlaying');
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', 0));
    }

    public function testOnPlaybackStoppedScrobblesWhenRulesPass(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);

        $playedSeconds = 101; // > 50% of 200s
        $this->api->expects(self::once())
            ->method('scrobble')
            ->with('SK', 'Song', 'Artist', null, self::isType('integer'));

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $event = new PlaybackStopped('s', 'u1', 'm', 'd', $playedSeconds * 10_000_000, true);
        $scrobbler->onPlaybackStopped($event);
    }

    public function testOnPlaybackStoppedSkipsWhenDurationUnknown(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => null,
        ]);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', 1_000_000_000, false));
    }

    public function testOnPlaybackStoppedSkipsWhenUnderThreshold(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::never())->method('scrobble');

        // 200s track, played 30s = 15% — under 50% gate.
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', 30 * 10_000_000, false));
    }

    public function testOnPlaybackStoppedSkipsWhenNoSession(): void
    {
        $this->sessions->method('findByUserId')->willReturn(null);
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', 200 * 10_000_000, true));
    }

    /**
     * @param callable(string): ?array{title: string, artist: string, album: ?string, duration_seconds: ?int} $resolveTrack
     */
    private function buildScrobbler(callable $resolveTrack): LastfmScrobbler
    {
        return new LastfmScrobbler($this->api, $this->sessions, $resolveTrack);
    }
}
