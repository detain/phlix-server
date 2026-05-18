<?php

declare(strict_types=1);

namespace Phlex\Plugins\Lastfm;

/**
 * Thrown when the Last.fm API returns a non-OK status for a scrobble
 * or Now Playing submission.
 *
 * @package Phlex\Plugins\Lastfm
 * @since 0.15.0
 */
final class LastfmScrobbleFailedException extends \RuntimeException
{
    /**
     * @param string $artist    Artist name for the failed scrobble.
     * @param string $track     Track title for the failed scrobble.
     * @param string $apiCode   The Last.fm API error code string.
     */
    public function __construct(
        public readonly string $artist,
        public readonly string $track,
        public readonly string $apiCode,
    ) {
        parent::__construct(sprintf(
            'Last.fm scrobble failed for "%s" - "%s": %s',
            $artist,
            $track,
            $apiCode,
        ));
    }
}
