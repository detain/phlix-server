<?php

/**
 * Phlix media server component: Util.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common\Util;

use Phlix\Shared\Database\RowMap as SharedRowMap;

/**
 * Helpers for safely narrowing untyped result-set rows into
 * `array<string, mixed>` row maps, and — since E-09 — for coercing the
 * VALUES of a row map into typed DTO fields.
 *
 * V059 row 5: this class is now a THIN DELEGATE onto the canonical home,
 * `Phlix\Shared\Database\RowMap` (the shared copy was lifted verbatim from
 * this file's E-09 substrate and differentially proven vectors=1023,
 * deltas=0 — see the v0.59 bank and the shared RowMap docblock). The full
 * value-coercion convention table (STRICT / NUMERIC / SCALAR-CAST /
 * NULLABLE SCALAR STRING / TRUTHY CAST / NON-EMPTY STRICT STRING / MYSQL
 * DATETIME), the explicit-default mandate, and every no-harmonization
 * clause now live in ONE place — the shared class docblock — instead of
 * two drifting copies.
 *
 * The public surface here stays byte-compatible: 67 src/ files import it,
 * and tests/Unit/Common/Util/RowMapAccessorsTest.php (behavior + the
 * signature-shape pins) is the acceptance baseline and runs UNMODIFIED.
 * Per-method summaries below name the family; the shared docblock owns the
 * full law prose for each.
 *
 * @since Wave 5a (post-O.7)
 */
final class RowMap
{
    private function __construct()
    {
    }

    /**
     * Narrow a value into a string-keyed associative array; non-array
     * inputs and non-string keys are discarded.
     *
     * @param mixed $value Anything; typically a row from `$db->query()`.
     * @return array<string, mixed>
     *
     * @see SharedRowMap::fromMixed()
     */
    public static function fromMixed(mixed $value): array
    {
        return SharedRowMap::fromMixed($value);
    }

    /**
     * Narrow a Workerman result-set into a list of string-keyed row maps;
     * non-array entries are dropped.
     *
     * @param mixed $rows Anything; typically the return of `$db->query()`.
     * @return list<array<string, mixed>>
     *
     * @see SharedRowMap::listFromMixed()
     */
    public static function listFromMixed(mixed $rows): array
    {
        return SharedRowMap::listFromMixed($rows);
    }

    /**
     * STRICT string column: only an actual `string` value passes; anything
     * else yields the explicit default.
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::str()
     *
     * @since Wave 5 E-09
     */
    public static function str(array $row, string $col, string $default): string
    {
        return SharedRowMap::str($row, $col, $default);
    }

    /**
     * STRICT nullable string column: only an actual `string` value passes;
     * everything else hydrates to null.
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::nullableStr()
     *
     * @since Wave 5 E-09
     */
    public static function nullableStr(array $row, string $col): ?string
    {
        return SharedRowMap::nullableStr($row, $col);
    }

    /**
     * STRICT int column: only an actual `int` value passes — a numeric
     * string takes the default (the legacy Webhook-record discipline).
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::int()
     *
     * @since Wave 5 E-09
     */
    public static function int(array $row, string $col, int $default): int
    {
        return SharedRowMap::int($row, $col, $default);
    }

    /**
     * STRICT nullable int column: only an actual `int` value passes;
     * everything else (including numeric strings) hydrates to null.
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::nullableInt()
     *
     * @since Wave 5 E-09
     */
    public static function nullableInt(array $row, string $col): ?int
    {
        return SharedRowMap::nullableInt($row, $col);
    }

    /**
     * Numeric-coercive int column: `is_numeric` gate then `(int)` cast.
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::intFromNumeric()
     *
     * @since Wave 5 E-09
     */
    public static function intFromNumeric(array $row, string $col, int $default): int
    {
        return SharedRowMap::intFromNumeric($row, $col, $default);
    }

    /**
     * Numeric-coercive nullable int column.
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::nullableIntFromNumeric()
     *
     * @since Wave 5 E-09
     */
    public static function nullableIntFromNumeric(array $row, string $col): ?int
    {
        return SharedRowMap::nullableIntFromNumeric($row, $col);
    }

    /**
     * Numeric-coercive float column.
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::floatFromNumeric()
     *
     * @since Wave 5 E-09
     */
    public static function floatFromNumeric(array $row, string $col, float $default): float
    {
        return SharedRowMap::floatFromNumeric($row, $col, $default);
    }

    /**
     * Scalar-cast string column: scalars become their `(string)` form;
     * arrays, objects and resources take the explicit default.
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::stringFromScalar()
     *
     * @since Wave 5 E-09
     */
    public static function stringFromScalar(array $row, string $col, string $default): string
    {
        return SharedRowMap::stringFromScalar($row, $col, $default);
    }

    /**
     * Scalar-cast nullable string column — the legacy
     * `WatchHistoryRow::nullableString()` contract, where a non-scalar
     * value becomes `''` rather than null.
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::nullableStringFromScalar()
     *
     * @since Wave 5 E-09
     */
    public static function nullableStringFromScalar(array $row, string $col): ?string
    {
        return SharedRowMap::nullableStringFromScalar($row, $col);
    }

    /**
     * Nullable scalar-cast string column — the `ScanJobRepository::nullableString()`
     * vocabulary: a NON-SCALAR value hydrates to null here (NOT to `''` as
     * in {@see self::nullableStringFromScalar()} — the two spellings
     * coexist as pinned legacy vocabularies and are never silently
     * harmonized between).
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::nullableScalarStr()
     *
     * @since E-09 cohort 4 (ScanJobRepository extraction)
     */
    public static function nullableScalarStr(array $row, string $col): ?string
    {
        return SharedRowMap::nullableScalarStr($row, $col);
    }

    /**
     * Truthy-cast bool column: the raw `(bool)` cast with the explicit
     * default reserved for the missing/NULL case — beware `"0"` is false
     * while `"false"` is TRUE; that is why the mode is named.
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::boolFromCast()
     *
     * @since Wave 5 E-09
     */
    public static function boolFromCast(array $row, string $col, bool $defaultWhenNull): bool
    {
        return SharedRowMap::boolFromCast($row, $col, $defaultWhenNull);
    }

    /**
     * Non-empty strict string column: an actual non-`''` `string` passes;
     * everything else hydrates to null — the music-DTO S121 law (null is
     * the DETECTABLE "no id" value; `''` is not — the full reasoning lives
     * in the shared docblock).
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::nullableNonEmptyStr()
     *
     * @since E-09 cohort 2 (music DTO extraction; S121/S127 law)
     */
    public static function nullableNonEmptyStr(array $row, string $col): ?string
    {
        return SharedRowMap::nullableNonEmptyStr($row, $col);
    }

    /**
     * Parse a `datetime` column value into a `\DateTime` or null — the
     * S143 anti-fabrication law (degenerate strings hydrate NULL; a mutable
     * `\DateTime` returns by identity; the full law prose is shared-owned).
     *
     * @see SharedRowMap::parseMysqlDateTime()
     *
     * @since E-09 cohort 2 (music DTO extraction; S143 law)
     */
    public static function parseMysqlDateTime(mixed $value): ?\DateTime
    {
        return SharedRowMap::parseMysqlDateTime($value);
    }

    /**
     * Nullable `datetime` column: {@see self::parseMysqlDateTime()} over
     * the named key.
     *
     * @param array<string, mixed> $row
     *
     * @see SharedRowMap::nullableDateTime()
     *
     * @since E-09 cohort 2 (music DTO extraction; S143 law)
     */
    public static function nullableDateTime(array $row, string $col): ?\DateTime
    {
        return SharedRowMap::nullableDateTime($row, $col);
    }
}
