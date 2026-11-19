<?php

/**
 * Phlix media server component: Util.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common\Util;

/**
 * Helpers for safely narrowing untyped result-set rows into
 * `array<string, mixed>` row maps, and — since E-09 — for coercing the
 * VALUES of a row map into typed DTO fields.
 *
 * The Workerman MySQL driver returns `array<int, array>` from
 * `$db->query(...)` where each row is an `array<mixed, mixed>` as far
 * as PHPStan is concerned. Most of our domain code consumes rows as
 * `array<string, mixed>` row maps (the `Foo::fromRow()` pattern), so
 * this helper centralizes the key-narrowing loop that would otherwise
 * be copy-pasted across every repository.
 *
 * **Value-coercion convention table (E-09).** The estate previously
 * hand-rolled ~300 `is_string($row[...])`-style narrowings inside 17
 * `fromRow()` DTOs, and the copies DISAGREED per column type (the same
 * MySQL INT column was strict `is_int` in the Webhook records but
 * numeric-coercive in `Collection` — and a numeric string yields `0`
 * under one and `7` under the other). This class makes those semantics
 * NAMED and the per-column default an EXPLICIT argument at every call
 * site, so each DTO's legacy behavior is pinned auditable instead of
 * silently harmonized. Harmonizing any output bytes is a separate,
 * adjudicated wire-behavior change — deliberately NOT what the accessors
 * do. The families:
 *
 * - STRICT (`str`/`nullableStr`/`int`/`nullableInt`): the PHP type must
 *   already match; anything else (including a numeric string) takes the
 *   default. Matches `(bool)`-free `is_string`/`is_int` ternaries.
 * - NUMERIC (`intFromNumeric`/`nullableIntFromNumeric`/
 *   `floatFromNumeric`): `is_numeric` gate, then `(int)`/`(float)` cast.
 *   For drivers/modes that deliver INT/DECIMAL columns as strings.
 * - SCALAR-CAST (`stringFromScalar`/`nullableStringFromScalar`): any
 *   string/int/float/bool value casts to string; arrays/objects take
 *   the default (or `''` under the nullable form, mirroring the legacy
 *   `WatchHistoryRow::asString()` contract).
 * - TRUTHY CAST (`boolFromCast`): the raw `(bool)` cast with an explicit
 *   default for the missing/NULL case — beware: `(bool)"0"` is false but
 *   `(bool)"false"` is true, which is exactly why the cast mode is named
 *   separately from a strict `is_bool` reader (not yet minted; add one
 *   when a cohort needs it rather than stretching this one).
 *
 * @since Wave 5a (post-O.7)
 */
final class RowMap
{
    private function __construct()
    {
    }

    /**
     * Narrow a value into a string-keyed associative array. Non-array
     * inputs and non-string keys are discarded.
     *
     * @param mixed $value Anything; typically a row from `$db->query()`.
     * @return array<string, mixed>
     */
    public static function fromMixed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $out[$key] = $entry;
            }
        }
        return $out;
    }

    /**
     * Narrow a Workerman result-set (`array<int, array>`-shaped) into
     * a list of string-keyed row maps. Non-array entries are dropped.
     *
     * @param mixed $rows Anything; typically the return of `$db->query()`.
     * @return list<array<string, mixed>>
     */
    public static function listFromMixed(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::fromMixed($row);
            }
        }
        return $out;
    }

    /**
     * STRICT string column: only an actual `string` value passes; a
     * missing key, NULL, or any other type yields the explicit default.
     *
     * @param array<string, mixed> $row
     * @since Wave 5 E-09
     */
    public static function str(array $row, string $col, string $default): string
    {
        $value = $row[$col] ?? null;
        return is_string($value) ? $value : $default;
    }

    /**
     * STRICT nullable string column: only an actual `string` value
     * passes; everything else hydrates to null.
     *
     * @param array<string, mixed> $row
     * @since Wave 5 E-09
     */
    public static function nullableStr(array $row, string $col): ?string
    {
        $value = $row[$col] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * STRICT int column: only an actual `int` value passes — a numeric
     * string takes the default (the legacy Webhook-record discipline).
     *
     * @param array<string, mixed> $row
     * @since Wave 5 E-09
     */
    public static function int(array $row, string $col, int $default): int
    {
        $value = $row[$col] ?? null;
        return is_int($value) ? $value : $default;
    }

    /**
     * STRICT nullable int column: only an actual `int` value passes;
     * everything else (including numeric strings) hydrates to null.
     *
     * @param array<string, mixed> $row
     * @since Wave 5 E-09
     */
    public static function nullableInt(array $row, string $col): ?int
    {
        $value = $row[$col] ?? null;
        return is_int($value) ? $value : null;
    }

    /**
     * Numeric-coercive int column: `is_numeric` gate then `(int)` cast
     * (`"7"` → 7, `"7.9"` → 7); non-numeric values take the explicit
     * default. For INT columns the driver may deliver as strings.
     *
     * @param array<string, mixed> $row
     * @since Wave 5 E-09
     */
    public static function intFromNumeric(array $row, string $col, int $default): int
    {
        $value = $row[$col] ?? null;
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Numeric-coercive nullable int column: `is_numeric` gate then
     * `(int)` cast; anything else hydrates to null.
     *
     * @param array<string, mixed> $row
     * @since Wave 5 E-09
     */
    public static function nullableIntFromNumeric(array $row, string $col): ?int
    {
        $value = $row[$col] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Numeric-coercive float column: `is_numeric` gate then `(float)`
     * cast; non-numeric values take the explicit default.
     *
     * @param array<string, mixed> $row
     * @since Wave 5 E-09
     */
    public static function floatFromNumeric(array $row, string $col, float $default): float
    {
        $value = $row[$col] ?? null;
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Scalar-cast string column: string/int/float/bool values become
     * their `(string)` form (bool true → `"1"`, float 1.25 → `"1.25"`);
     * arrays, objects and resources take the explicit default.
     *
     * @param array<string, mixed> $row
     * @since Wave 5 E-09
     */
    public static function stringFromScalar(array $row, string $col, string $default): string
    {
        $value = $row[$col] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return $default;
    }

    /**
     * Scalar-cast nullable string column: an actual SQL NULL (or a
     * missing key) hydrates to null; every other value follows
     * {@see self::stringFromScalar()} with an empty-string default —
     * the legacy `WatchHistoryRow::nullableString()` contract, where a
     * non-scalar value becomes `''` rather than null.
     *
     * @param array<string, mixed> $row
     * @since Wave 5 E-09
     */
    public static function nullableStringFromScalar(array $row, string $col): ?string
    {
        $value = $row[$col] ?? null;
        if ($value === null) {
            return null;
        }
        return self::stringFromScalar($row, $col, '');
    }

    /**
     * Truthy-cast bool column: the raw `(bool)` cast of whatever is
     * present, with the explicit default reserved for the missing/NULL
     * case. Reproduced byte-for-byte from the legacy
     * `(bool) ($row['is_active'] ?? true)` sites. Note the cast's known
     * grammar: `"0"` and `"0.0"`-as-zero-like strings are false, but
     * `"false"` and `" "` are TRUE — which is precisely why this mode is
     * a named accessor rather than a bare cast at each call site.
     *
     * @param array<string, mixed> $row
     * @since Wave 5 E-09
     */
    public static function boolFromCast(array $row, string $col, bool $defaultWhenNull): bool
    {
        $value = $row[$col] ?? null;
        return $value === null ? $defaultWhenNull : (bool) $value;
    }
}
