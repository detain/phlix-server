<?php

declare(strict_types=1);

/**
 * Last.fm scrobble plugin configuration.
 *
 * Enable by setting 'enabled' to true and providing your Last.fm API
 * credentials and authenticated session key.
 *
 * @since 0.15.0
 */

return [
    /**
     * Whether the Last.fm scrobbler is active.
     * Must be explicitly set to true to enable.
     */
    'enabled' => false,

    /**
     * Last.fm API key.
     * Obtain from https://www.last.fm/api/account/create
     */
    'api_key' => '',

    /**
     * Last.fm API secret.
     * Obtain from https://www.last.fm/api/account/create
     */
    'api_secret' => '',

    /**
     * Last.fm session key.
     *
     * After calling getMobileSession() once with username + password hash,
     * the returned session key is stored here. It does not expire unless
     * the user revokes it in their Last.fm settings.
     *
     * To obtain: call LastfmApiClient::getMobileSession() with your
     * username and md5(password) once, then save the returned key here.
     */
    'session_key' => '',

    /**
     * Last.fm username used for scrobble attribution.
     */
    'username' => '',

    /**
     * Whether to send a Now Playing notification to Last.fm when
     * playback starts (tracks.showUpdateNowPlaying API).
     *
     * Set to false to disable Now Playing and only scrobble on stop.
     */
    'submit_now_playing' => true,

    /**
     * Fraction of track duration that must be played before a scrobble
     * is submitted (0.0 to 1.0).
     *
     * A value of 0.5 means the track must be at least 50% complete
     * before scrobbling. Last.fm's default threshold is 50%.
     *
     * Set to 0.0 to scrobble immediately on stop, or 1.0 to only
     * scrobble when the track is fully finished.
     */
    'scrobble_threshold' => 0.5,
];
