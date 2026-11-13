<?php

declare(strict_types=1);

/*
 * WARNING (exposure): with `auto` and `upnp_enabled` true (the defaults), the
 * server asks the LAN router via NAT-PMP/UPnP to publish `port` to the
 * internet. To opt out set PHLIX_PORT_FORWARD_AUTO=false and
 * PHLIX_UPNP_ENABLED=false, and delete any existing mapping on the router.
 * The defaults are owner-gated and intentionally unchanged here.
 */
return [
    'port_forwarding' => [
        'auto' => (bool) ($_ENV['PHLIX_PORT_FORWARD_AUTO'] ?? true),
        'port' => (int) ($_ENV['PHLIX_EXTERNAL_PORT'] ?? 32400),
        'external_http_port' => (int) ($_ENV['PHLIX_EXTERNAL_HTTP_PORT'] ?? 8080),
        'external_https_port' => (int) ($_ENV['PHLIX_EXTERNAL_HTTPS_PORT'] ?? 8443),
        'stun_server' => $_ENV['PHLIX_STUN_SERVER'] ?? 'stun.l.google.com',
        'stun_port' => (int) ($_ENV['PHLIX_STUN_PORT'] ?? 19302),
        'upnp_enabled' => (bool) ($_ENV['PHLIX_UPNP_ENABLED'] ?? true),
    ],
];
