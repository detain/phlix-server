<?php

/**
 * Music providers configuration.
 *
 * This file configures the MusicBrainz and AudioDB metadata providers
 * used for music library metadata fetching.
 *
 * @author Phlex Development Team
 * @since 0.13.0
 */

declare(strict_types=1);

return [
    'musicbrainz' => [
        'enabled' => true,
        'rate_limit' => 1.0,        // seconds between requests (MusicBrainz requirement)
        'user_agent' => 'Phlex/1.0 (https://phlex.media)',
        'use_fallback' => true,       // fall back to AudioDB if MusicBrainz fails
    ],
    'audiodb' => [
        'enabled' => true,
        'api_key' => '',             // user supplies their own key
        'rate_limit' => 0.5,
    ],
];
