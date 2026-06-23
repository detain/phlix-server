<?php

/**
 * Relay tunnel configuration.
 *
 * For servers co-located with the hub, use 127.0.0.1 to avoid hairpin NAT issues.
 * For remote servers, use the hub's public hostname with wss:// protocol.
 *
 * @package Phlix\Config
 * @since 0.12.0
 */

return [
    'enabled' => true,

    // Legacy HTTP-route template; retained only to derive the hub host when
    // hub_relay_ws_url is not set. The multiplexed protocol does NOT connect here.
    'hub_wss_url' => 'wss://hub.example.com/api/v1/servers/{id}/relay',

    // Hub server-tunnel WS endpoint (Phlix\Hub\Relay\RelayWorker, default :8802).
    // The enrollment JWT is sent in the JSON HELLO body, not as an auth header.
    // Use ws://127.0.0.1:8802 if hub and server are co-located (avoids hairpin NAT).
    // Use wss://hub.phlix.interserver.net:8802 for remote servers.
    'hub_relay_ws_url' => 'ws://127.0.0.1:8802',

    // This server's own local HTTP listener that relayed client bytes are piped to.
    'local_http_address' => '127.0.0.1:8096',

    'local_address' => '127.0.0.1:0',

    'tunnel_hostname' => '',

    'reconnect_delay' => 5,

    'ping_interval' => 30,

    'ping_timeout' => 10,
];
