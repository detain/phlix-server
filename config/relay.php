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
    //
    // If you set an explicit wss:// URL here (or via PHLIX_RELAY_HUB_WS_URL),
    // ALSO set relay_tls below (PHLIX_RELAY_TLS=1) so the intent is explicit and
    // the cert/verify envs (relay_tls_verify / relay_tls_cafile) apply. The
    // transport keys off the scheme, so a wss:// URL to a TLS hub relay port
    // works either way, but pairing it with PHLIX_RELAY_TLS=1 keeps the config
    // unambiguous and silences the start-time TLS-mismatch heads-up warning.
    'hub_relay_ws_url' => '',

    // Relay tunnel TLS (wss://). INDEPENDENT of the server's HTTP TLS and OFF by
    // default, mirroring the hub's HUB_RELAY_TLS default — the hub relay listener
    // (:8802) is plaintext unless HUB_RELAY_TLS=true is set on the hub. Only the
    // DERIVED relay scheme is affected; an explicit hub_relay_ws_url above wins.
    // Enable here (PHLIX_RELAY_TLS=1) AND on the hub (HUB_RELAY_TLS=true) together.
    'relay_tls' => filter_var(getenv('PHLIX_RELAY_TLS') ?: 'false', FILTER_VALIDATE_BOOLEAN),

    // Verify the hub's presented relay TLS certificate (default: secure/on).
    // Set PHLIX_RELAY_TLS_VERIFY=0 to accept a self-signed hub relay cert
    // (verify_peer=false + allow_self_signed). Production should use a CA-signed
    // cert and leave verification on.
    'relay_tls_verify' => filter_var(getenv('PHLIX_RELAY_TLS_VERIFY') ?: 'true', FILTER_VALIDATE_BOOLEAN),

    // CA bundle used to verify the hub's relay TLS certificate (default = system).
    'relay_tls_cafile' => getenv('PHLIX_RELAY_TLS_CAFILE') ?: '/etc/ssl/certs/ca-certificates.crt',

    // This server's own local HTTP listener that relayed client bytes are piped to.
    'local_http_address' => '127.0.0.1:8096',

    'local_address' => '127.0.0.1:0',

    'tunnel_hostname' => '',

    'reconnect_delay' => 5,

    'ping_interval' => 30,

    'ping_timeout' => 10,
];
