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

use Phlix\Common\Env\EnvConfig;

return [
    /**
     * Whether the Last.fm scrobbler is active.
     * Set `LASTFM_ENABLED=1` in the environment to enable.
     */
    // E-04 grammar (Wave-S1 residual sweep): EnvConfig::bool — identical answers
    // to the old filter_var(`?:`) line for every real spelling; junk now
    // announces itself and padded spellings take the estate trim law.
    'enabled' => EnvConfig::bool('LASTFM_ENABLED', false),

    /**
     * Last.fm API key. Required.
     * Obtain from https://www.last.fm/api/account/create
     */
    'api_key' => EnvConfig::text('LASTFM_API_KEY', ''),

    /**
     * Shared secret used to sign authenticated calls (`api_sig`).
     * Required. Obtain alongside the API key.
     *
     * Also exposed as `api_secret` for backward compatibility with older
     * config readers (the legacy `Phlix\Plugins\Lastfm\Plugin` consumer that
     * once read this key has been removed).
     */
    'shared_secret' => EnvConfig::text('LASTFM_SHARED_SECRET', ''),
    'api_secret'    => EnvConfig::text('LASTFM_SHARED_SECRET', ''),

    /**
     * URL the user lands on after authorising the request token on the
     * Last.fm auth page. Last.fm appends `?token=...`; the server then
     * calls `LastfmApi::getSession()` to obtain the long-lived
     * session key.
     */
    'callback_url' => EnvConfig::text('LASTFM_CALLBACK_URL', ''),

    /**
     * Session key for the removed legacy single-user Plugin. The per-user
     * `LastfmScrobbler` reads its keys from the `lastfm_sessions` table
     * instead, so this can stay empty when using the new flow.
     */
    'session_key' => EnvConfig::text('LASTFM_SESSION_KEY', ''),

    /**
     * Last.fm username used for scrobble attribution (display only).
     */
    'username' => EnvConfig::text('LASTFM_USERNAME', ''),

    /**
     * Whether to send `track.updateNowPlaying` on playback start in
     * addition to the scrobble on stop.
     */
    // E-04 grammar (Wave-S1 residual sweep): opt-out shape. Real-word spellings
    // ('0'/'1'/'true'/'false'/'on'/'off', padded) answer identically to the old
    // `?: '1'` line; JUNK ('banana') and whitespace used to be silently OFF on
    // a knob whose comment promises it defaults ON — under the estate boolOptOut
    // law (same convergence SRV-ENVBOOL-r1/r2 ratified for PHLIX_RELAY_TLS_VERIFY)
    // junk now answers the documented default ON and announces itself. Disclosed
    // deliberately: an operator typing junk into an opt-out can no longer
    // accidentally turn the feature OFF.
    'submit_now_playing' => EnvConfig::boolOptOut('LASTFM_SUBMIT_NOW_PLAYING'),

    /**
     * Legacy fraction-based threshold consumed by the original (now removed)
     * `Phlix\Plugins\Lastfm\Plugin`. The current
     * `Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler` (provided by the
     * installed Last.fm plugin) enforces Last.fm's official rule (>30s
     * duration AND >50% played) regardless of this value.
     */
    'scrobble_threshold' => 0.5,
];
