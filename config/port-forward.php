<?php

declare(strict_types=1);

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
