<?php

declare(strict_types=1);

// Filesystem roots the admin path-picker may browse (Step 0.6). The
// `GET /api/v1/admin/fs/browse` endpoint jails directory listing to these
// roots; only directories that resolve (via realpath) under one of them are
// listable. This is the security boundary for the (admin-only) browse endpoint
// — keep it conservative.
//
// Self-hosted installs mount media in arbitrary places (e.g. /vault1, /srv,
// /tank), so the list is operator-overridable via PHLIX_BROWSE_ROOTS — a
// comma-separated absolute-path list that REPLACES the defaults. Example:
//   PHLIX_BROWSE_ROOTS=/home,/mnt,/media,/vault1,/vault2
// When unset, the conservative defaults apply.
$defaultRoots = ['/home', '/mnt', '/media', '/data'];
$envRoots = getenv('PHLIX_BROWSE_ROOTS');
$roots = is_string($envRoots) && trim($envRoots) !== ''
    ? array_values(array_filter(
        array_map('trim', explode(',', $envRoots)),
        static fn (string $p): bool => $p !== '',
    ))
    : $defaultRoots;

return [
    'browse_roots' => $roots,
];
