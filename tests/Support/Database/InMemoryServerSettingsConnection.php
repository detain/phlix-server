<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Database;

use PDO;
use Workerman\MySQL\Connection;

/**
 * A stateful `server_settings` table in memory, for tests that need a REAL
 * {@see \Phlix\Admin\SettingsRepository} rather than a mock (S74).
 *
 * ## Why a mock is not enough here
 *
 * The core update check writes its result through `SettingsRepository::set()`
 * and reads it back through `getOverride()`. A `createMock(Connection::class)`
 * returns a canned value for `query()` regardless of what was written, so a
 * test built on one proves the read shape and NOTHING about the round-trip —
 * "the fetched marker actually reaches the status endpoint" is exactly the
 * claim that would go untested.
 *
 * This double models only what `SettingsRepository` issues:
 *
 *  - `SELECT setting_value, value_type FROM server_settings WHERE setting_key = ?`
 *  - `SELECT setting_key, setting_value, value_type FROM server_settings` (all)
 *  - `INSERT ... ON DUPLICATE KEY UPDATE` against the unique `setting_key`
 *
 * WHAT IT IS NOT: not MySQL. No types beyond text storage, no transactions, no
 * locking, no other table. Anything else returns `true`, matching the
 * workerman/mysql write return.
 *
 * Not `final`: an integration test that drives the production DI container needs
 * ONE connection to answer both `server_settings` and the admin gate's `users`
 * lookup, and subclassing this is cleaner than widening it with table knowledge
 * it has no business holding.
 *
 * @package Phlix\Tests\Support\Database
 */
class InMemoryServerSettingsConnection extends Connection
{
    /**
     * setting_key => [setting_value, value_type], both stored as text exactly
     * as the real column does.
     *
     * @var array<string, array{setting_value: string, value_type: string}>
     */
    private array $rows = [];

    /** @var list<string> Every SQL statement seen, in order. */
    private array $statements = [];

    public function __construct()
    {
        // No parent::__construct() — this double never connects.
    }

    /**
     * Seed a row as though a previous check (or an admin) had written it.
     *
     * @param string $key   Dotted setting key.
     * @param string $value Stored text value.
     * @param string $type  One of string|int|bool|float|json.
     *
     * @return void
     */
    public function seed(string $key, string $value, string $type): void
    {
        $this->rows[$key] = ['setting_value' => $value, 'value_type' => $type];
    }

    /**
     * Raw stored text for a key, or null when unset.
     *
     * @param string $key Dotted setting key.
     *
     * @return string|null
     */
    public function storedValue(string $key): ?string
    {
        return $this->rows[$key]['setting_value'] ?? null;
    }

    /**
     * Every statement issued against this connection, in order.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    /**
     * The signature MUST stay untyped: the parent declares
     * `query($query = '', $params = null, $fetchmode = PDO::FETCH_ASSOC)`.
     *
     * @param string                        $query     SQL.
     * @param array<int|string, mixed>|null $params    Positional bindings.
     * @param int                           $fetchmode Ignored.
     *
     * @return mixed Rows for a SELECT, true for a write.
     */
    public function query($query = '', $params = null, $fetchmode = PDO::FETCH_ASSOC)
    {
        $query = trim((string) $query);
        $args  = is_array($params) ? array_values($params) : [];

        $this->statements[] = $query;

        if (str_contains($query, 'INSERT INTO server_settings')) {
            $key   = is_scalar($args[1] ?? null) ? (string) $args[1] : '';
            $value = is_scalar($args[2] ?? null) ? (string) $args[2] : '';
            $type  = is_scalar($args[3] ?? null) ? (string) $args[3] : 'string';

            $this->rows[$key] = ['setting_value' => $value, 'value_type' => $type];

            return true;
        }

        if (str_contains($query, 'FROM server_settings') && str_contains($query, 'setting_key = ?')) {
            $key = is_scalar($args[0] ?? null) ? (string) $args[0] : '';
            if (!isset($this->rows[$key])) {
                return [];
            }

            return [[
                'setting_value' => $this->rows[$key]['setting_value'],
                'value_type'    => $this->rows[$key]['value_type'],
            ]];
        }

        if (str_contains($query, 'FROM server_settings')) {
            $out = [];
            foreach ($this->rows as $key => $row) {
                $out[] = [
                    'setting_key'   => $key,
                    'setting_value' => $row['setting_value'],
                    'value_type'    => $row['value_type'],
                ];
            }

            return $out;
        }

        return true;
    }
}
