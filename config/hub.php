<?php

/**
 * Hub subsystem configuration.
 *
 * @package Phlix\Config
 * @since 0.11.0
 */

return [
    'hub_url' => getenv('PHLIX_HUB_URL') ?: null,

    'hub_jwks_url' => getenv('PHLIX_HUB_JWKS_URL') ?: null,

    'heartbeat_interval' => (int)(getenv('PHLIX_HUB_HEARTBEAT_INTERVAL') ?: 60),

    'enrollment_token_ttl' => 7 * 86400,

    'jwks_cache_ttl' => 900,

    'key_path' => __DIR__ . '/hub-server-key.pem',

    'config_dir' => __DIR__,

    'subdomain_auto_claim' => (bool)(getenv('PHLIX_SUBDOMAIN_AUTO_CLAIM') ?: true),

    'tls_enabled' => (bool)(getenv('PHLIX_TLS_ENABLED') ?: true),

    'domain' => getenv('PHLIX_DOMAIN') ?: 'phlix.media',
];
