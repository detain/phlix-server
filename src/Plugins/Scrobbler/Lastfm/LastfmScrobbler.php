<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PSR-14 listener that fans Phlix playback events out to Last.fm.
 *
 * On `phlix.playback.started` the listener calls
 * {@see LastfmApi::updateNowPlaying()} so the user's Last.fm profile shows
 * what they are listening to in real time.
 *
 * On `phlix.playback.stopped` the listener applies the official Last.fm
 * scrobble rules:
 *
 *  - the track must be longer than 30 seconds, AND
 *  - the user must have listened to more than 50 % of it.
 *
 * Only when both gates pass is {@see LastfmApi::scrobble()} invoked.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
class LastfmScrobbler
{
    /** Minimum track duration (seconds) before a scrobble is eligible. */
    public const MIN_DURATION_SECONDS = 30;

    /** Minimum fraction of the track that must be played. */
    public const MIN_PLAYED_FRACTION = 0.5;

    /** Conversion factor: 100-ns ticks → seconds. */
    private const TICKS_PER_SECOND = 10_000_000;

    private LoggerInterface $logger;

    /**
     * Track metadata resolver — the host wires this with an
     * `ItemRepository`-backed callable that takes a media-item UUID and
     * returns the lookup data the scrobbler needs.
     *
     * @var (callable(string $mediaItemId): ?array{
     *     title: string, artist: string, album: ?string, duration_seconds: ?int
     * })
     */
    private $resolveTrack;

    /**
     * @param LastfmApi               $api          Last.fm HTTP client.
     * @param LastfmSessionRepository $sessions     Per-user session-key store.
     * @param callable(string): ?array{
     *     title: string, artist: string, album: ?string, duration_seconds: ?int
     * } $resolveTrack Resolver that maps a `mediaItemId` to track metadata.
     * @param LoggerInterface|null    $logger       Optional PSR-3 logger.
     */
    public function __construct(
        private readonly LastfmApi $api,
        private readonly LastfmSessionRepository $sessions,
        callable $resolveTrack,
        ?LoggerInterface $logger = null,
    ) {
        $this->resolveTrack = $resolveTrack;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Handle `phlix.playback.started` — sends Now Playing if the user has
     * a Last.fm session.
     */
    public function onPlaybackStarted(PlaybackStarted $event): void
    {
        $session = $this->sessions->findByUserId($event->userId);
        if ($session === null) {
            return;
        }
        $track = ($this->resolveTrack)($event->mediaItemId);
        if ($track === null) {
            return;
        }
        $this->api->updateNowPlaying(
            $session['session_key'],
            $track['title'],
            $track['artist'],
            $track['album'],
        );
    }

    /**
     * Handle `phlix.playback.stopped` — scrobbles when Last.fm's official
     * `>30s AND >50%` rule is satisfied.
     *
     * The rule is enforced here (the underlying {@see LastfmApi::scrobble()}
     * does no gating of its own).
     */
    public function onPlaybackStopped(PlaybackStopped $event): void
    {
        $session = $this->sessions->findByUserId($event->userId);
        if ($session === null) {
            return;
        }

        $track = ($this->resolveTrack)($event->mediaItemId);
        if ($track === null) {
            return;
        }

        $duration = $track['duration_seconds'];
        $playedSeconds = (int) ($event->finalPositionTicks / self::TICKS_PER_SECOND);

        if (!$this->meetsScrobbleRules($duration, $playedSeconds)) {
            $this->logger->debug('Last.fm scrobble skipped: rule not satisfied', [
                'media_item_id'        => $event->mediaItemId,
                'duration_seconds'     => $duration,
                'played_seconds'       => $playedSeconds,
                'min_duration_seconds' => self::MIN_DURATION_SECONDS,
                'min_played_fraction'  => self::MIN_PLAYED_FRACTION,
            ]);
            return;
        }

        $this->api->scrobble(
            $session['session_key'],
            $track['title'],
            $track['artist'],
            $track['album'],
            time() - $playedSeconds,
        );
    }

    /**
     * Apply Last.fm's official rule: the track must be longer than 30
     * seconds AND the user must have listened to more than 50 % of it.
     *
     * Returns false when the track duration is unknown — we are
     * deliberately conservative because mis-counted scrobbles are worse
     * than missing ones.
     *
     * @param int|null $durationSeconds Track duration in seconds, or null when unknown.
     * @param int      $playedSeconds   Seconds of audio actually played.
     */
    public function meetsScrobbleRules(?int $durationSeconds, int $playedSeconds): bool
    {
        if ($durationSeconds === null || $durationSeconds <= self::MIN_DURATION_SECONDS) {
            return false;
        }
        $fractionPlayed = $playedSeconds / $durationSeconds;
        return $fractionPlayed > self::MIN_PLAYED_FRACTION;
    }
}
