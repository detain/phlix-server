<?php

declare(strict_types=1);

// Fixture config for SettingsRepositoryTest — exercises a nested dotted key
// (`port-forward.port_forwarding.upnp_enabled`).
return [
    'port_forwarding' => [
        'upnp_enabled' => true,
        'port' => 32400,
    ],
];
