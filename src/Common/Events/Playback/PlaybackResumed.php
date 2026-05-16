<?php

declare(strict_types=1);

namespace Phlex\Common\Events\Playback;

use Phlex\Common\Events\AbstractEvent;

/**
 * Fired when a previously paused playback session resumes playing.
 *
 * Fired by: `\Phlex\Session\PlaybackController::reportProgress()` when
 * the reported `isPaused` flag flips from true to false for an existing
 * session.
 * Typical listener: scrobble plugins (re-start the scrobble timer), now-
 * playing dashboard updaters, smart-bulb / "movie mode" integrations.
 *
 * Manifest alias (Phase A.4 loader): `phlex.playback.resumed`.
 *
 * @package Phlex\Common\Events\Playback
 * @since 0.10.0
 */
final class PlaybackResumed extends AbstractEvent
{
    /**
     * @param string $sessionId     UUID of the playback session.
     * @param string $userId        UUID of the user driving the session.
     * @param string $mediaItemId   UUID of the media item being played.
     * @param string $deviceId      Stable identifier of the client device.
     * @param int    $positionTicks Position when resumed, in 100-ns ticks.
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
