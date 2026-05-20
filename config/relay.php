<?php

/**
 * Relay tunnel configuration.
 *
 * @package Phlix\Config
 * @since 0.12.0
 */

return [
    'enabled' => (bool)(getenv('PHLIX_RELAY_ENABLED') ?: false),

    'hub_wss_url' => getenv('PHLIX_RELAY_HUB_URL') ?: 'wss://hub.example.com/api/v1/servers/{id}/relay',

    'local_address' => '127.0.0.1:0',

    'tunnel_hostname' => getenv('PHLIX_RELAY_TUNNEL_HOSTNAME') ?: '',

    'reconnect_delay' => (int)(getenv('PHLIX_RELAY_RECONNECT_DELAY') ?: 5),

    'ping_interval' => (int)(getenv('PHLIX_RELAY_PING_INTERVAL') ?: 30),

    'ping_timeout' => (int)(getenv('PHLIX_RELAY_PING_TIMEOUT') ?: 10),
];
