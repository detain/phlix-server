<?php

/**
 * Relay tunnel configuration.
 *
 * The hub_relay_ws_url is normally derived from the enrollment's hub_base_url
 * at start time via RelayConfig::withAutoEnable(). Set it here only for
 * unusual deployment scenarios that cannot be handled by enrollment.
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
    // When empty, the URL is derived from the enrollment's hub_base_url via
    // RelayConfig::withAutoEnable() at RelayConsumer start time.
    'hub_relay_ws_url' => '',

    // This server's own local HTTP listener that relayed client bytes are piped to.
    'local_http_address' => '127.0.0.1:8096',

    'local_address' => '127.0.0.1:0',

    'tunnel_hostname' => '',

    'reconnect_delay' => 5,

    'ping_interval' => 30,

    'ping_timeout' => 10,
];
