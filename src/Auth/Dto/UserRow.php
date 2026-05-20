<?php

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
}
