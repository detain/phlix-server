<?php

declare(strict_types=1);

/**
 * Trakt.tv scrobbler plugin configuration.
 *
 * These are the *operator's* Trakt application credentials — every Phlix
 * install must register its own application at https://trakt.tv/apps to obtain
 * a client_id and client_secret (Phlix cannot ship a shared one because each
 * Trakt app is bound to its owner and redirect URI). Per-user OAuth tokens are
 * stored separately (see {@see \Phlix\Plugins\Scrobbler\Trakt\TraktSettings}),
 * NOT here.
 *
 * Defaults pull from environment variables so secrets stay out of the repo —
 * mirroring config/lastfm.php. Set them in the service environment (systemd
 * unit / .env) and restart the server, or configure them in the admin
 * Settings page (stored in server_settings, which takes precedence).
 *
 * @since 0.14.0
 */

return [
    /**
     * Trakt application client ID. Required.
     * Set TRAKT_CLIENT_ID. Get yours at: https://trakt.tv/apps
     */
    'client_id' => getenv('TRAKT_CLIENT_ID') ?: '',

    /**
     * Trakt application client secret. Required.
     * Set TRAKT_CLIENT_SECRET. Get yours at: https://trakt.tv/apps
     */
    'client_secret' => getenv('TRAKT_CLIENT_SECRET') ?: '',

    /**
     * OAuth2 redirect URI. Must match exactly what is registered in your
     * Trakt app. Set TRAKT_REDIRECT_URI.
     */
    'redirect_uri' => getenv('TRAKT_REDIRECT_URI')
        ?: 'https://your-server.com/api/v1/oauth/trakt/callback',

    /**
     * Sync interval for Trakt → Phlix history sync (in minutes).
     * Set TRAKT_SYNC_INTERVAL. Default 30; min 5, max 1440.
     */
    'sync_interval' => (int) (getenv('TRAKT_SYNC_INTERVAL') ?: '30'),
];
