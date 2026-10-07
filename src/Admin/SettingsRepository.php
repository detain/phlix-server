<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Admin;

use Phlix\Common\Uuid;
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
 * Keys are *dotted*: a leading run of segments names the config **file** and
 * the remaining segments walk into the array it returns. For example
 * `hwaccel.enabled` resolves the `'enabled'` key of `config/hwaccel.php`,
 * and `port-forward.port_forwarding.upnp_enabled` walks two levels into
 * `config/port-forward.php`.
 *
 * The file part may span **subdirectories**: `scrobblers.trakt.client_id`
 * resolves `config/scrobblers/trakt.php`'s `'client_id'`. The longest matching
 * file path wins, so `config/foo/bar.php` is preferred over `config/foo.php`
 * for the key `foo.bar.baz`. This exists because `config/scrobblers/trakt.php`
 * is a real, shipped config file that was unreachable under the original
 * "first segment is the whole filename" rule — any key pointing at it resolved
 * to a `null` default. The change is purely additive (a multi-segment file path
 * previously never resolved at all), so no existing key's resolution changes.
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
     * Start a transaction on this repository's connection.
     *
     * B2 (auth-lockout in-transaction re-validation): the admin settings PUT
     * wraps its guard-check-and-persist in one transaction so the lock, the
     * re-read, and the writes all live on the SAME connection the repository
     * writes through — a lock taken on a second connection would be theater.
     * Failures propagate (the vendor connection throws on a failed BEGIN);
     * the vendor's bool return is ignored, matching the house transaction
     * idiom in {@see \Phlix\Collections\CollectionItemRepository::applyMemberDiff()}.
     *
     * @since 1.4.0 (B2)
     */
    public function beginTransaction(): void
    {
        $this->db->beginTrans();
    }

    /**
     * Commit the open transaction on this repository's connection.
     *
     * @since 1.4.0 (B2)
     */
    public function commitTransaction(): void
    {
        $this->db->commitTrans();
    }

    /**
     * Roll the open transaction back on this repository's connection.
     *
     * @since 1.4.0 (B2)
     */
    public function rollbackTransaction(): void
    {
        $this->db->rollBackTrans();
    }

    /**
     * Take `SELECT ... FOR UPDATE` row locks on the given setting keys.
     *
     * B2 serialization protocol for the auth-method lock-out guard. What IS
     * and ISN'T locked, honestly:
     *
     *  - **Present keys** — every row whose `setting_key` already exists gets
     *    an exclusive (X) row lock on the `uq_server_settings_key` unique
     *    index, held until COMMIT/ROLLBACK. A concurrent writer running the
     *    same protocol on an overlapping key set blocks here, then re-reads
     *    the *locked* (current-read) state, so its guard validates against the
     *    other writer's committed values, never a pre-write snapshot.
     *  - **Absent keys** — under the default REPEATABLE READ isolation the
     *    locking read also takes next-key (gap) locks over the index range of
     *    each missing key, which blocks a concurrent INSERT of that same key.
     *    Two writers inserting two *different* absent keys can in theory
     *    deadlock on each other's gaps; InnoDB resolves this by aborting one
     *    transaction (error 1213), which surfaces here as a throw, rolls the
     *    whole guarded write back, and answers 500 — a SAFE abort: the
     *    all-off state is never reached, only the doomed write is lost.
     *  - **Not locked** — writers that bypass this protocol entirely (raw
     *    SQL, restored backups). The provider-API disable surface is NOT in
     *    that set any more: since the provider-parity close it runs the same
     *    BEGIN → FOR UPDATE (same five canonical keys, same order) → locked
     *    re-read → persist → COMMIT protocol through
     *    {@see \Phlix\Auth\AuthMethodPolicy::guardAndPersistThrough()}, so
     *    admin∥provider writers fully serialize on the universal lock set.
     *    The read-path password fallback in
     *    {@see \Phlix\Auth\AuthMethodPolicy} remains the last-resort
     *    backstop for the raw-SQL/restore class.
     *
     * Must be called inside an open {@see beginTransaction()} window: in
     * autocommit mode InnoDB releases each lock the instant the statement
     * finishes, making the call meaningless.
     *
     * @param list<string> $keys Dotted setting keys to lock.
     *
     * @throws \InvalidArgumentException When `$keys` is empty — an empty
     *         `IN ()` is illegal SQL and an empty lock set would silently
     *         skip the serialization the caller believes it acquired.
     *
     * @since 1.4.0 (B2)
     */
    public function lockSettingRows(array $keys): void
    {
        if ($keys === []) {
            throw new \InvalidArgumentException('lockSettingRows() requires at least one setting key.');
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = 'SELECT setting_key, setting_value FROM server_settings'
            . " WHERE setting_key IN ({$placeholders}) FOR UPDATE";

        $this->db->query($sql, array_values($keys));
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
        return $this->resolveDefault($key)['value'];
    }

    /**
     * Does a dotted key address a config path that actually EXISTS?
     *
     * Distinguishes "the config declares this key and its value happens to be
     * null/empty" (true — e.g. `transcoding.preferred_accelerator`, whose `''`
     * means "auto-detect") from "no config file or path of that name exists"
     * (false), which {@see getDefault()} cannot express because a declared-null
     * default and a missing path both return `null`.
     *
     * That distinction is what makes it possible to assert that every schema
     * key is backed by a real default; see
     * {@see \Phlix\Tests\Unit\Admin\SettingsDefaultResolvabilityTest}.
     *
     * @param string $key Dotted setting key.
     *
     * @return bool True when the config path resolves (whatever its value).
     *
     * @since 1.3.0
     */
    public function hasDefault(string $key): bool
    {
        return $this->resolveDefault($key)['found'];
    }

    /**
     * Resolve a dotted key against the config files, reporting BOTH whether
     * the path exists and the value found there.
     *
     * @param string $key Dotted setting key.
     *
     * @return array{found: bool, value: mixed}
     */
    private function resolveDefault(string $key): array
    {
        $segments = explode('.', $key);
        if (count($segments) < 2) {
            // A bare file name addresses no value inside it.
            return ['found' => false, 'value' => null];
        }

        // Longest-file-path-first: try `config/a/b/c.php`, then `config/a/b.php`,
        // then `config/a.php`, so a nested config file (config/scrobblers/trakt.php)
        // is preferred over a same-named flat one. At least one segment must
        // remain to address a value inside the file.
        for ($fileSegmentCount = count($segments) - 1; $fileSegmentCount >= 1; $fileSegmentCount--) {
            $filePath = array_slice($segments, 0, $fileSegmentCount);
            $config   = $this->loadConfig($filePath);
            if ($config === null) {
                continue;
            }

            $cursor  = $config;
            $missing = false;
            foreach (array_slice($segments, $fileSegmentCount) as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    $missing = true;
                    break;
                }
                $cursor = $cursor[$segment];
            }

            if (!$missing) {
                return ['found' => true, 'value' => $cursor];
            }
        }

        return ['found' => false, 'value' => null];
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
     * Load and cache a single `config/<a>/<b>/....php`.
     *
     * @param list<string> $fileSegments Path segments of the config file, no
     *                                   extension, e.g. `['hwaccel']` or
     *                                   `['scrobblers', 'trakt']`.
     *
     * @return array<array-key, mixed>|null Decoded config, or null when
     *         missing / not an array.
     */
    private function loadConfig(array $fileSegments): ?array
    {
        $file = implode('/', $fileSegments);

        if (array_key_exists($file, $this->configCache)) {
            return $this->configCache[$file];
        }

        // Jail the lookup to the config directory: EVERY segment must be a
        // simple name, so a crafted setting_key can contain no `..`, no `/`
        // and no absolute path, and therefore cannot include arbitrary PHP
        // files from outside `config/`.
        foreach ($fileSegments as $segment) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                return $this->configCache[$file] = null;
            }
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
        return Uuid::v4();
    }
}
