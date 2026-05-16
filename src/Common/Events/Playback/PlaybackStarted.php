<?php

declare(strict_types=1);

namespace Phlex\Common\Events\Playback;

use Phlex\Common\Events\AbstractEvent;

/**
 * Fired when a user begins playback of a media item.
 *
 * Fired by: `\Phlex\Session\PlaybackController::reportProgress()` the
 * first time progress is recorded for a `(sessionId, mediaItemId)` pair.
 * Typical listener: scrobble plugins (Trakt, Last.fm), analytics
 * collectors, presence integrations (Discord rich presence), watch-history
 * recorders, parental-control auditors.
 *
 * Manifest alias (Phase A.4 loader): `phlex.playback.started`.
 *
 * @package Phlex\Common\Events\Playback
 * @since 0.10.0
 */
final class PlaybackStarted extends AbstractEvent
{
    /**
     * @param string $sessionId     UUID of the playback session.
     * @param string $userId        UUID of the user driving the session.
     * @param string $mediaItemId   UUID of the media item being played.
     * @param string $deviceId      Stable identifier of the client device.
     * @param int    $positionTicks Initial playback position, in 100-ns
     *                              "ticks" (Plex/Jellyfin convention).
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $userId,
        public readonly string $mediaItemId,
        public readonly string $deviceId,
        public readonly int $positionTicks,
    ) {
        parent::__construct();
    }
}
