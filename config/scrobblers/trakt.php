<?php

declare(strict_types=1);

/**
 * Trakt.tv scrobbler plugin configuration.
 *
 * Users must register a Trakt application at https://trakt.tv/apps
 * to obtain client_id and client_secret.
 *
 * @since 0.14.0
 */

return [
    /**
     * Trakt application client ID.
     *
     * Get yours at: https://trakt.tv/apps
     */
    'client_id' => '',

    /**
     * Trakt application client secret.
     *
     * Get yours at: https://trakt.tv/apps
     */
    'client_secret' => '',

    /**
     * OAuth2 redirect URI.
     *
     * Must match exactly what is registered in your Trakt app.
     */
    'redirect_uri' => 'https://your-server.com/api/v1/oauth/trakt/callback',

    /**
     * Sync interval for Trakt → Phlix history sync (in minutes).
     *
     * Default: 30 minutes
     * Min: 5 minutes, Max: 1440 minutes (24 hours)
     */
    'sync_interval' => 30,
];
