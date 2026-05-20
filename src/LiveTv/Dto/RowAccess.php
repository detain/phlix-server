<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Dto;

/**
 * Static helpers for safely reading typed values out of an `array<string,
 * mixed>` row returned by the database layer.
 *
 * The LiveTv code receives rows from
 * {@see \Workerman\MySQL\Connection::query()} as `array<string, mixed>`,
 * which makes direct casts (e.g. `(int) $row['start_time']`) trip
 * phpstan level 9 (`cast.int`). These helpers do the runtime narrowing
 * once so call sites stay readable and type-checked.
 *
 * @since Wave 5a (post-O.7)
 */
final class RowAccess
{
    private function __construct()
    {
    }

    /**
     * Coerce a row column to a string. Returns the default when the column
     * is missing, null, or a non-stringable value.
     *
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $key, string $default = ''): string
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $default;
    }

    /**
     * Coerce a row column to a nullable string. Returns null when the
     * column is missing or null.
     *
     * @param array<string, mixed> $row
     */
    public static function stringOrNull(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Coerce a row column to an int. Numeric strings and floats are
     * accepted; non-numeric values fall back to the default.
     *
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key, int $default = 0): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return $default;
    }

    /**
     * Coerce a row column to a nullable int. Returns null when missing or
     * null; numeric strings and floats are accepted.
     *
     * @param array<string, mixed> $row
     */
    public static function intOrNull(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Coerce a row column to a boolean. Treats 0/"0"/"" as false,
     * any other non-null scalar as true (per typical TINYINT(1) semantics).
     *
     * @param array<string, mixed> $row
     */
    public static function bool(array $row, string $key, bool $default = false): bool
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }
        return $default;
    }
}
