<?php

declare(strict_types=1);

namespace Phlex\Common\Events\Playback;

use Phlex\Common\Events\AbstractEvent;

/**
 * Fired when an active playback session transitions to the paused state.
 *
 * Fired by: `\Phlex\Session\PlaybackController::reportProgress()` when
 * the reported `isPaused` flag flips from false to true for an existing
 * session.
 * Typical listener: scrobble plugins (mark "stopped" mid-stream so they
 * can resume), now-playing dashboard updaters, away-from-keyboard
 * presence integrations.
 *
 * Manifest alias (Phase A.4 loader): `phlex.playback.paused`.
 *
 * @package Phlex\Common\Events\Playback
 * @since 0.10.0
 */
final class PlaybackPaused extends AbstractEvent
{
    /**
     * @param string $sessionId     UUID of the playback session.
     * @param string $userId        UUID of the user driving the session.
     * @param string $mediaItemId   UUID of the media item being played.
     * @param string $deviceId      Stable identifier of the client device.
     * @param int    $positionTicks Position when paused, in 100-ns ticks.
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
