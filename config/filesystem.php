<?php

declare(strict_types=1);

// Filesystem roots the admin path-picker may browse (Step 0.6). The
// `GET /api/v1/admin/fs/browse` endpoint jails directory listing to these
// roots; only directories that resolve (via realpath) under one of them are
// listable. Keep this list conservative — it is the security boundary for the
// browse endpoint. (Env override is intentionally omitted to keep the boundary
// explicit and auditable.)
return [
    'browse_roots' => ['/home', '/mnt', '/media', '/data'],
];
