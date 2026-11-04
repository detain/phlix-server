<?php

/**
 * Phlix media server component: Dto.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Auth\Dto;

use Phlix\Common\Util\RowMap;

/**
 * Helpers for narrowing untyped DB rows from the `users` and
 * `user_settings` tables into typed string-keyed maps.
 *
 * Wrapping every `$db->query(...)` access through these static helpers
 * lets PHPStan see a known shape at the call site rather than `mixed`
 * coming back from `Workerman\MySQL\Connection::query()`.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Static narrowing helpers for user-table rows.
 * @since Wave 5b-J
 */
final class UserRow
{
    /**
     * The users-table columns that carry credential material and therefore
     * must never cross a wire or terminal boundary inside a row handed to a
     * caller. {@see self::forWire()} is the sole consumer; the docblock there
     * carries the full rationale.
     */
    private const SECRET_COLUMNS = ['password_hash', 'password_reset_token', 'provider_data'];

    private function __construct()
    {
    }

    /**
     * Narrow the first row of a result set into a string-keyed map.
     *
     * @return array<string, mixed>|null Null when result is empty / non-array.
     */
    public static function firstFromMixed(mixed $rows): ?array
    {
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }
        return RowMap::fromMixed($rows[0]);
    }

    /**
     * Read a string column from a (possibly null) row. Returns null when
     * absent or non-stringable.
     *
     * @param array<string, mixed>|null $row
     */
    public static function string(?array $row, string $key): ?string
    {
        if ($row === null || !array_key_exists($key, $row)) {
            return null;
        }
        $value = $row[$key];
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Drop every credential-bearing column from a raw users row so the result
     * can be serialized to a boundary (an HTTP response, a CLI JSON stream).
     *
     * This is the estate's ONE definition of the users-table secret set
     * (SRV-M12, review server__dba): the admin user-list leaked precisely
     * because redaction lived as a per-caller convention (`AuthManager::getUser()`
     * unsetting one column, `UserListCommand` keeping its own list). Every
     * wire-boundary read enumerated by the mechanical `->getUser(` call-site
     * census in `tests/Unit/Auth/UserRowWireRedactionTest.php` and by its
     * pinned admin-surface consumers passes through here — SRV-m43 closed
     * the fourth consumer family over which the earlier unconditional
     * "every read" wording claimed enforcement the tests did not yet have.
     * The same test pins set equality of that census and that no second
     * definition of the set appears anywhere in src/.
     *
     * The set:
     * - `password_hash` — the Argon2id password credential itself.
     * - `password_reset_token` — the Argon2id hash of a live one-time reset
     *   selector (migration 041); disclosure enables offline cracking and,
     *   paired with any reset surface, replay.
     * - `provider_data` — the external-provider payload JSON (migration 009),
     *   which carries provider access/refresh tokens and private claims.
     *
     * Account-state columns (`status`, `must_change_password`,
     * `password_reset_expires_at`, `tokens_not_valid_after`, …) are NOT in the
     * set: they carry no secret entropy — a reset expiry is inert without its
     * token — and the admin surfaces documented in README and the console
     * client read them.
     *
     * Pure: returns a new array; never mutates the input. Authentication paths
     * (`UserRepository::findById()` and friends) deliberately keep the RAW
     * row — `password_verify()` needs `password_hash` in hand; redacting those
     * would break login.
     *
     * @param array<string, mixed> $row Raw users-table row.
     *
     * @return array<string, mixed> The same row minus {@see self::SECRET_COLUMNS};
     *         every other key (and the relative order of what remains) is
     *         untouched.
     *
     * @since SRV-M12 (2026-11-04, review server__dba)
     */
    public static function forWire(array $row): array
    {
        foreach (self::SECRET_COLUMNS as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    /**
     * Read an int column from a row, defaulting when absent / non-numeric.
     *
     * @param array<string, mixed>|null $row
     */
    public static function int(?array $row, string $key, int $default = 0): int
    {
        if ($row === null || !array_key_exists($key, $row)) {
            return $default;
        }
        $value = $row[$key];
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Read an unsigned-int column that may be NULL.
     *
     * @param array<string, mixed>|null $row
     * @return int|null Null when the value is absent, null, or non-numeric.
     */
    public static function intOrNull(?array $row, string $key): ?int
    {
        if ($row === null || !array_key_exists($key, $row)) {
            return null;
        }
        $value = $row[$key];
        if ($value === null) {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }
}
