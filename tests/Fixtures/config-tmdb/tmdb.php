<?php

declare(strict_types=1);

/*
 * Fixture standing in for config/tmdb.php.
 *
 * Deliberately a literal (not getenv('TMDB_API_KEY')) so a test can tell a
 * config-file resolution apart from an environment resolution, and from the
 * `server_settings` override that must outrank both.
 */

return [
    'api_key' => 'config-file-key',
];
