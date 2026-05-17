<?php

/**
 * Relay tunnel configuration.
 *
 * @package Phlex\Config
 * @since 0.12.0
 */

return [
    'enabled' => (bool)(getenv('PHLEX_RELAY_ENABLED') ?: false),

    'hub_wss_url' => getenv('PHLEX_RELAY_HUB_URL') ?: 'wss://hub.example.com/api/v1/servers/{id}/relay',

    'local_address' => '127.0.0.1:0',

    'tunnel_hostname' => getenv('PHLEX_RELAY_TUNNEL_HOSTNAME') ?: '',

    'reconnect_delay' => (int)(getenv('PHLEX_RELAY_RECONNECT_DELAY') ?: 5),

    'ping_interval' => (int)(getenv('PHLEX_RELAY_PING_INTERVAL') ?: 30),

    'ping_timeout' => (int)(getenv('PHLEX_RELAY_PING_TIMEOUT') ?: 10),
];
