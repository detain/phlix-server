<?php

/**
 * Hub subsystem configuration.
 *
 * @package Phlex\Config
 * @since 0.11.0
 */

return [
    'hub_url' => getenv('PHLEX_HUB_URL') ?: null,

    'heartbeat_interval' => (int)(getenv('PHLEX_HUB_HEARTBEAT_INTERVAL') ?: 60),

    'enrollment_token_ttl' => 7 * 86400,

    'jwks_cache_ttl' => 900,

    'key_path' => __DIR__ . '/hub-server-key.pem',

    'config_dir' => __DIR__,
];
