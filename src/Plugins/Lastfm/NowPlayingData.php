<?php

declare(strict_types=1);

namespace Phlex\Plugins\Lastfm;

/**
 * Immutable value object carrying the data required for a Last.fm
 * "Now Playing" notification via the `track.updateNowPlaying` API endpoint.
 *
 * Sending Now Playing does not scrobble — it only updates the user's
 * profile to show what they are currently listening to.
 *
 * @package Phlex\Plugins\Lastfm
 * @since 0.15.0
 */
final readonly class NowPlayingData
{
    /**
     * @param string      $artist_name    Artist name (required).
     * @param string      $track_title    Track title (required).
     * @param string|null $album_name    Album name (optional).
     * @param int|null    $duration_secs Track duration in seconds (optional).
     * @param string|null $mbid          MusicBrainz recording ID (optional).
     */
    public function __construct(
        public string $artist_name,
        public string $track_title,
        public ?string $album_name = null,
        public ?int $duration_secs = null,
        public ?string $mbid = null,
    ) {
    }
}
