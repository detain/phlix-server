<?php

declare(strict_types=1);

// Fixture config for SettingsRepositoryTest — mirrors a subset of the real
// config/hwaccel.php so default/override resolution can be asserted.
return [
    'enabled' => true,
    'prefer_hardware' => true,
    'probe_timeout' => 30,
];
