<?php

/**
 * Relay tunnel configuration.
 *
 * @package Phlix\Config
 * @since 0.12.0
 */

return [
    'enabled' => (bool)(getenv('PHLIX_RELAY_ENABLED') ?: false),

    // Legacy HTTP-route template; retained only to derive the hub host when
    // hub_relay_ws_url is not set. The multiplexed protocol does NOT connect here.
    'hub_wss_url' => getenv('PHLIX_RELAY_HUB_URL') ?: 'wss://hub.example.com/api/v1/servers/{id}/relay',

    // Hub server-tunnel WS endpoint (Phlix\Hub\Relay\RelayWorker, default :8802).
    // The enrollment JWT is sent in the JSON HELLO body, not as an auth header.
    'hub_relay_ws_url' => getenv('PHLIX_RELAY_HUB_WS_URL') ?: '',

    // This server's own local HTTP listener that relayed client bytes are piped to.
    'local_http_address' => getenv('PHLIX_RELAY_LOCAL_HTTP_ADDRESS') ?: '127.0.0.1:8096',

    'local_address' => '127.0.0.1:0',

    'tunnel_hostname' => getenv('PHLIX_RELAY_TUNNEL_HOSTNAME') ?: '',

    'reconnect_delay' => (int)(getenv('PHLIX_RELAY_RECONNECT_DELAY') ?: 5),

    'ping_interval' => (int)(getenv('PHLIX_RELAY_PING_INTERVAL') ?: 30),

    'ping_timeout' => (int)(getenv('PHLIX_RELAY_PING_TIMEOUT') ?: 10),
];
