<?php

declare(strict_types=1);

namespace Phlix\Plugins\Lastfm;

/**
 * Immutable value object carrying the data required for a Last.fm scrobble
 * submission to the `track.scrobble` API endpoint.
 *
 * @package Phlix\Plugins\Lastfm
 * @since 0.15.0
 */
final readonly class ScrobbleData
{
    /**
     * @param string      $artist_name    Artist name (required).
     * @param string      $track_title    Track title (required).
     * @param int         $timestamp_unix UNIX timestamp when track started
     *                                   playing (required).
     * @param string|null $album_name    Album name (optional).
     * @param int|null    $track_number  Track number on album (optional).
     * @param int|null    $duration_secs Track duration in seconds (optional;
     *                                    improves scrobble accuracy).
     * @param string|null $mbid          MusicBrainz recording ID (optional).
     */
    public function __construct(
        public string $artist_name,
        public string $track_title,
        public int $timestamp_unix,
        public ?string $album_name = null,
        public ?int $track_number = null,
        public ?int $duration_secs = null,
        public ?string $mbid = null,
    ) {
    }
}
