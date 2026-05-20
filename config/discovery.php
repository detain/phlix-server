<?php

return [
    'ssdp' => [
        'enabled' => true,
        'announce_interval_secs' => 600,  // SSDP NOTIFY interval (10 minutes)
        'discovery_timeout_secs' => 5,
    ],
    'mdns' => [
        'enabled' => true,
        'discovery_timeout_secs' => 5,
    ],
    'discovery_port' => 8200,  // Phlix server port for discovery responses
];
