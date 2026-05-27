<?php

declare(strict_types=1);

namespace Phlix\Admin;

use Workerman\MySQL\Connection;

/**
 * Server-wide settings store (Step 0.5).
 *
 * Persists admin-editable *overrides* on top of the read-only
 * `config/*.php` files. The runtime contract is:
 *
 *   - **default**  — the value baked into `config/<file>.php` (boot-time).
 *   - **override** — a row in the `server_settings` table written via the
 *     admin settings API.
 *   - **effective** — the override when present, else the default.
 *
 * Keys are *dotted*: the first segment names the config file and the
 * remaining segments walk into the array it returns. For example
 * `hwaccel.enabled` resolves the `'enabled'` key of `config/hwaccel.php`,
 * and `port-forward.port_forwarding.upnp_enabled` walks two levels into
 * `config/port-forward.php`.
 *
 * Storage notes:
 *   - `setting_value` is always stored as text; `value_type`
 *     (string|int|bool|float|json) records how to decode it back into a
 *     PHP value. {@see self::encode()} / {@see self::decode()}.
 *   - Upserts use `INSERT ... ON DUPLICATE KEY UPDATE` against the
 *     `uq_server_settings_key` unique index, mirroring
 *     {@see \Phlix\Auth\UserRepository::updateSettings()}.
 *
 * Database access is exclusively through the async
 * {@see \Workerman\MySQL\Connection} client with parameterised queries —
 * never PDO/mysqli, never string-interpolated SQL — per the resident-memory
 * (Workerman) runtime rules.
 *
 * @package Phlix\Admin
 * @since   0.5 (Server-wide settings store)
 */
class SettingsRepository
{
    /** @var Connection Async MySQL connection used for all queries. */
    private Connection $db;

    /** @var string Absolute or relative directory holding `config/*.php`. */
    private string $configDir;

    /**
     * In-process cache of decoded config files, keyed by file segment.
     * Bounded by the (small, fixed) number of config files referenced by
     * the allow-list, so it is not an unbounded resident-memory leak. It is
     * shared-default config (not request data), so caching it on the
     * instance is safe — the repository is request-scoped via the container.
     *
     * @var array<string, array<array-key, mixed>|null>
     */
    private array $configCache = [];

    /**
     * @param Connection  $db        Workerman MySQL connection.
     * @param string|null $configDir Directory containing `config/*.php`.
     *                               Defaults to `PHLIX_CONFIG_DIR` when the
     *                               constant is defined, else `config`,
     *                               matching {@see \Phlix\Admin\BackupManager}.
     *                               Injectable so tests can point at fixtures.
     *
     * @since 0.5
     */
    public function __construct(Connection $db, ?string $configDir = null)
    {
        $this->db = $db;
        if ($configDir !== null) {
            $this->configDir = $configDir;
        } elseif (defined('PHLIX_CONFIG_DIR')) {
            /** @var string $dir */
            $dir = constant('PHLIX_CONFIG_DIR');
            $this->configDir = $dir;
        } else {
            $this->configDir = 'config';
        }
    }

    /**
     * Read the raw override row for a single key.
     *
     * @param string $key Dotted setting key.
     *
     * @return array{value: mixed, value_type: string}|null Decoded override
     *         and its declared type, or null when no override exists.
     *
     * @since 0.5
     */
    public function getOverride(string $key): ?array
    {
        $rows = $this->db->query(
            'SELECT setting_value, value_type FROM server_settings WHERE setting_key = ?',
            [$key],
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        $type = is_string($row['value_type'] ?? null) ? $row['value_type'] : 'string';
        $raw  = is_string($row['setting_value'] ?? null) ? $row['setting_value'] : '';

        return [
            'value'      => self::decode($raw, $type),
            'value_type' => $type,
        ];
    }

    /**
     * Read every override currently stored, keyed by setting key.
     *
     * @return array<string, mixed> Map of setting_key → decoded override.
     *
     * @since 0.5
     */
    public function getAllOverrides(): array
    {
        $rows = $this->db->query(
            'SELECT setting_key, setting_value, value_type FROM server_settings',
        );

        $out = [];
        if (!is_array($rows)) {
            return $out;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['setting_key'] ?? null)) {
                continue;
            }
            $type = is_string($row['value_type'] ?? null) ? $row['value_type'] : 'string';
            $raw  = is_string($row['setting_value'] ?? null) ? $row['setting_value'] : '';
            $out[$row['setting_key']] = self::decode($raw, $type);
        }

        return $out;
    }

    /**
     * Persist (insert or update) an override.
     *
     * The caller is responsible for validating that `$key` is allowed and
     * that `$value` matches `$valueType` (the admin controller does this
     * against its typed allow-list). This method only serialises and upserts.
     *
     * @param string $key       Dotted setting key (must be unique).
     * @param mixed  $value     PHP value to persist.
     * @param string $valueType One of string|int|bool|float|json.
     *
     * @since 0.5
     */
    public function set(string $key, mixed $value, string $valueType): void
    {
        $id      = $this->generateUuid();
        $encoded = self::encode($value, $valueType);

        // Upsert on the unique `setting_key` index, mirroring
        // UserRepository::updateSettings(): on a new row the inserted values
        // win; on an existing row only the supplied columns are refreshed.
        $sql = 'INSERT INTO server_settings (id, setting_key, setting_value, value_type)'
            . ' VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' setting_value = VALUES(setting_value),'
            . ' value_type = VALUES(value_type)';

        $this->db->query($sql, [$id, $key, $encoded, $valueType]);
    }

    /**
     * Resolve the *default* (config-file) value for a dotted key.
     *
     * @param string $key Dotted setting key.
     *
     * @return mixed The config value, or null when the file/path is absent.
     *
     * @since 0.5
     */
    public function getDefault(string $key): mixed
    {
        $segments = explode('.', $key);
        $file     = array_shift($segments);
        if ($file === null || $file === '') {
            return null;
        }

        $config = $this->loadConfig($file);
        if ($config === null) {
            return null;
        }

        $cursor = $config;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Effective value for a single key: override when present, else default.
     *
     * @param string $key Dotted setting key.
     *
     * @return mixed The effective value.
     *
     * @since 0.5
     */
    public function getEffective(string $key): mixed
    {
        $override = $this->getOverride($key);
        if ($override !== null) {
            return $override['value'];
        }

        return $this->getDefault($key);
    }

    /**
     * Build the effective-value map for a known set of keys, plus the list
     * of keys that are currently overridden.
     *
     * The admin controller passes its typed allow-list here so the response
     * only ever exposes curated keys (never arbitrary config internals).
     *
     * @param list<string> $keys Allow-listed dotted keys.
     *
     * @return array{values: array<string, mixed>, overridden: list<string>}
     *
     * @since 0.5
     */
    public function getEffectiveMany(array $keys): array
    {
        $overrides = $this->getAllOverrides();

        $values     = [];
        $overridden = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $overrides)) {
                $values[$key] = $overrides[$key];
                $overridden[] = $key;
            } else {
                $values[$key] = $this->getDefault($key);
            }
        }

        return ['values' => $values, 'overridden' => $overridden];
    }

    /**
     * Load and cache a single `config/<file>.php`.
     *
     * @param string $file Config file segment (no extension), e.g. `hwaccel`.
     *
     * @return array<array-key, mixed>|null Decoded config, or null when
     *         missing / not an array.
     */
    private function loadConfig(string $file): ?array
    {
        if (array_key_exists($file, $this->configCache)) {
            return $this->configCache[$file];
        }

        // Jail the lookup to the config directory: reject any traversal in
        // the key's first segment so a crafted setting_key cannot include
        // arbitrary PHP files. Only simple file names are permitted.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $file)) {
            return $this->configCache[$file] = null;
        }

        $path = $this->configDir . '/' . $file . '.php';
        if (!is_file($path)) {
            return $this->configCache[$file] = null;
        }

        /** @psalm-suppress UnresolvableInclude $path is a jailed config file resolved at runtime */
        $loaded = @include $path;

        return $this->configCache[$file] = is_array($loaded) ? $loaded : null;
    }

    /**
     * Serialise a PHP value to its text representation for storage.
     *
     * @param mixed  $value     Value to encode.
     * @param string $valueType One of string|int|bool|float|json.
     *
     * @return string Text form suitable for the `setting_value` column.
     */
    private static function encode(mixed $value, string $valueType): string
    {
        return match ($valueType) {
            'bool'  => ($value ? '1' : '0'),
            'int'   => (string) (int) (is_numeric($value) ? $value : 0),
            'float' => (string) (float) (is_numeric($value) ? $value : 0),
            'json'  => (string) json_encode($value),
            default => is_scalar($value) ? (string) $value : (string) json_encode($value),
        };
    }

    /**
     * Decode a stored text value back into a PHP value per its type.
     *
     * @param string $raw       Stored text value.
     * @param string $valueType One of string|int|bool|float|json.
     *
     * @return mixed The decoded PHP value.
     */
    private static function decode(string $raw, string $valueType): mixed
    {
        return match ($valueType) {
            'bool'  => $raw === '1' || strtolower($raw) === 'true',
            'int'   => (int) $raw,
            'float' => (float) $raw,
            'json'  => json_decode($raw, true),
            default => $raw,
        };
    }

    /**
     * Generate a UUID v4 string. Mirrors the local `generateUuid()` helper
     * duplicated across the codebase (per the repo's no-UUID-library rule).
     *
     * @return string Formatted UUID string.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
