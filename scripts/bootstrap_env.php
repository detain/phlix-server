<?php

/**
 * CLI env bootstrap.
 *
 * The systemd services receive DB_PASSWORD (and friends) via the unit's
 * `EnvironmentFile=/etc/phlix/env`. A manually-run `php scripts/foo.php` does
 * NOT get that, so `getenv('DB_PASSWORD')` is empty and the DB connect fails
 * with "Access denied for user 'phlix'@'localhost' (using password: NO)".
 *
 * Requiring this file (after the Composer autoloader) loads the env file into
 * the process — but ONLY when `DB_PASSWORD` isn't already set, and it NEVER
 * overrides a variable that's already present. So scripts "just work" whether
 * run by systemd (env already provided → no-op) or by hand. Override the file
 * path with the `PHLIX_ENV_FILE` environment variable (default `/etc/phlix/env`).
 */

declare(strict_types=1);

if (!function_exists('phlix_parse_env_file')) {
    /**
     * Parse a `KEY=VALUE` env file (systemd EnvironmentFile-style: `#` comments,
     * blank lines, optional surrounding single/double quotes around the value).
     * Pure — no side effects, no I/O beyond reading the given file.
     *
     * @return array<string, string> Parsed key→value pairs (empty if unreadable).
     */
    function phlix_parse_env_file(string $file): array
    {
        $out = [];
        if (!is_file($file) || !is_readable($file)) {
            return $out;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $out;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $len = strlen($value);
            if (
                $len >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[$len - 1] === $value[0]
            ) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}

if (!function_exists('phlix_bootstrap_cli_env')) {
    /**
     * Load the env file into the process when DB_PASSWORD isn't already set.
     * Never clobbers an already-present variable. Returns the keys it set.
     *
     * @return list<string>
     */
    function phlix_bootstrap_cli_env(?string $file = null): array
    {
        $pw = getenv('DB_PASSWORD');
        if (is_string($pw) && $pw !== '') {
            return [];
        }
        if ($file === null) {
            $envFile = getenv('PHLIX_ENV_FILE');
            $file = (is_string($envFile) && $envFile !== '') ? $envFile : '/etc/phlix/env';
        }
        $applied = [];
        foreach (phlix_parse_env_file($file) as $key => $value) {
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                $applied[] = $key;
            }
        }
        return $applied;
    }
}

phlix_bootstrap_cli_env();
