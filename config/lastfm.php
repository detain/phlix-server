<?php

declare(strict_types=1);

/**
 * Last.fm scrobble plugin configuration.
 *
 * Defaults pull from environment variables so secrets stay out of the
 * repo. Set `LASTFM_API_KEY` and `LASTFM_SHARED_SECRET` to enable the
 * integration; set `LASTFM_CALLBACK_URL` to the URL the user is
 * redirected back to after approving the request token on Last.fm.
 *
 * @since 0.15.0
 */

return [
    /**
     * Whether the Last.fm scrobbler is active.
     * Set `LASTFM_ENABLED=1` in the environment to enable.
     */
    'enabled' => filter_var(getenv('LASTFM_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),

    /**
     * Last.fm API key. Required.
     * Obtain from https://www.last.fm/api/account/create
     */
    'api_key' => getenv('LASTFM_API_KEY') ?: '',

    /**
     * Shared secret used to sign authenticated calls (`api_sig`).
     * Required. Obtain alongside the API key.
     *
     * Also exposed as `api_secret` for backward compatibility with the
     * legacy `Phlix\Plugins\Lastfm\Plugin` consumer.
     */
    'shared_secret' => getenv('LASTFM_SHARED_SECRET') ?: '',
    'api_secret'    => getenv('LASTFM_SHARED_SECRET') ?: '',

    /**
     * URL the user lands on after authorising the request token on the
     * Last.fm auth page. Last.fm appends `?token=...`; the server then
     * calls `LastfmApi::getSession()` to obtain the long-lived
     * session key.
     */
    'callback_url' => getenv('LASTFM_CALLBACK_URL') ?: '',

    /**
     * Session key for the legacy single-user Plugin. The per-user
     * `LastfmScrobbler` reads its keys from the `lastfm_sessions` table
     * instead, so this can stay empty when using the new flow.
     */
    'session_key' => getenv('LASTFM_SESSION_KEY') ?: '',

    /**
     * Last.fm username used for scrobble attribution (display only).
     */
    'username' => getenv('LASTFM_USERNAME') ?: '',

    /**
     * Whether to send `track.updateNowPlaying` on playback start in
     * addition to the scrobble on stop.
     */
    'submit_now_playing' => filter_var(getenv('LASTFM_SUBMIT_NOW_PLAYING') ?: '1', FILTER_VALIDATE_BOOLEAN),

    /**
     * Legacy fraction-based threshold consumed by the original
     * `Phlix\Plugins\Lastfm\Plugin`. The new
     * `Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler` enforces
     * Last.fm's official rule (>30s duration AND >50% played) regardless
     * of this value.
     */
    'scrobble_threshold' => 0.5,
];
