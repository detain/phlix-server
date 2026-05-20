<?php

declare(strict_types=1);

namespace Phlix\Plugins\Lastfm;

/**
 * Interface for the Last.fm API client.
 *
 * Exists to allow test doubles and alternative implementations
 * without coupling to the concrete {@see LastfmApiClient}.
 *
 * @package Phlix\Plugins\Lastfm
 * @since 0.15.0
 */
interface LastfmApiClientInterface
{
    /**
     * Authenticate with Last.fm using username + password hash.
     *
     * @param string $username      Last.fm username.
     * @param string $passwordHash MD5 hex of the user's password.
     *
     * @return string The session key to store for subsequent calls.
     */
    public function getMobileSession(string $username, string $passwordHash): string;

    /**
     * Validate that a session key is currently valid.
     *
     * @param string $sessionKey The session key to validate.
     *
     * @return bool True when the session key is valid; false otherwise.
     */
    public function validateSession(string $sessionKey): bool;

    /**
     * Submit a scrobble to Last.fm.
     *
     * @param ScrobbleData $data The scrobble data to submit.
     *
     * @return bool True when Last.fm returns a 200 OK status.
     */
    public function scrobble(ScrobbleData $data): bool;

    /**
     * Update the user's "Now Playing" status on Last.fm.
     *
     * @param NowPlayingData $data The Now Playing data to submit.
     *
     * @return bool True when Last.fm returns a 200 OK status.
     */
    public function nowPlaying(NowPlayingData $data): bool;
}
