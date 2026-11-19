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
 * - NULLABLE SCALAR STRING (`nullableScalarStr`): SQL NULL or a missing
 *   key → null; a NON-SCALAR value → null as well (unlike the nullable
 *   SCALAR-CAST form, where a non-scalar becomes `''`); scalars cast to
 *   their `(string)` form — the legacy `ScanJobRepository::nullableString()`
 *   vocabulary.
 * - TRUTHY CAST (`boolFromCast`): the raw `(bool)` cast with an explicit
 *   default for the missing/NULL case — beware: `(bool)"0"` is false but
 *   `(bool)"false"` is true, which is exactly why the cast mode is named
 *   separately from a strict `is_bool` reader (not yet minted; add one
 *   when a cohort needs it rather than stretching this one).
 * - NON-EMPTY STRICT STRING (`nullableNonEmptyStr`): a real `string` that
 *   is not `''`; everything else NULL, whitespace passed through UNTRIMMED
 *   (the music-DTO S121 law, extracted byte-identically).
 * - MYSQL DATETIME (`parseMysqlDateTime` value form, `nullableDateTime`
 *   row form): the S143 anti-fabrication law — degenerate strings,
 *   non-strings and parser rejections hydrate NULL; `\DateTimeInterface`
 *   converts preserving instant/microseconds/timezone; a mutable
 *   `\DateTime` returns by identity.
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
     * Nullable scalar-cast string column: an actual SQL NULL (or a
     * missing key) hydrates to null and a NON-SCALAR value (array,
     * object, resource) hydrates to null as well; every scalar casts to
     * its `(string)` form (`true` → `"1"`, `false` → `""`, `7.9` →
     * `"7.9"`). Lifted byte-identically from the legacy
     * `ScanJobRepository::nullableString()` helper (E-09 cohort 4).
     *
     * This is NOT {@see self::nullableStringFromScalar()}, which shares
     * the helper's NAME but not its vocabulary: there a non-scalar
     * collapses to `''` (the `WatchHistoryRow` contract); here it
     * collapses to null. The two spellings coexist as pinned legacy
     * vocabularies — a migration cohort picks per column by provenance,
     * and outputs are never silently harmonized between them.
     *
     * @param array<string, mixed> $row
     * @since E-09 cohort 4 (ScanJobRepository extraction)
     */
    public static function nullableScalarStr(array $row, string $col): ?string
    {
        $value = $row[$col] ?? null;
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
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

    /**
     * Non-empty strict string column: an actual `string` value passes only
     * when it is not the empty string; a missing key, SQL NULL, `''` or any
     * non-string value hydrates to null. Extracted byte-identically from the
     * three byte-identical `mediaItemIdFromRow()` helpers of the music DTOs
     * (E-09 cohort 2; S121/S127 law).
     *
     * This family replaced `is_numeric($row['media_item_id']) ? (int)… :
     * null`, which is false for every UUID — the field was silently ALWAYS
     * null (or 0) (S121). Confirmed against real MySQL: `media_item_id` is
     * `char(36)` (migration 070) and `workerman/mysql` hands it back as a
     * PHP `string`, while the sibling `id` columns really are `int unsigned`
     * — which is why they keep their numeric guards and this one must not.
     *
     * `null` is a LEGITIMATE value here, not merely a parse failure: the
     * column is NULLable and the music scanner writes NULL when its
     * `createMediaItem()` mint fails (backfilled on a later pass; migration
     * 065). An absent key, a SQL NULL and `''` all collapse to `null` —
     * never to `''`. The reason is semantic, not static-typing: at PHPStan
     * level 9 a `string|null` flows silently through interpolation, `'…' .
     * $id` and `sprintf('%s', $id)` alike and `null` interpolates to exactly
     * the empty segment `''` would; but `''` is a NON-NULL string, so it
     * SURVIVES the `!== null` / `?? …` / `is_string()` tests callers actually
     * write and propagates as though it were an id, whereas `null` is
     * filtered by those same tests. Choosing `null` makes "no id"
     * detectable; `''` would make it undetectable.
     *
     * A `CHAR(36)` column can hold any 1–36-character string (`'0'`, a
     * truncated UUID) and this accepts all of them — format validation is
     * deliberately NOT the reader's job (the FK to `media_items` is). MySQL
     * strips a `CHAR` value's TRAILING pad spaces on read, so an all-space
     * value arrives as `''` and is rejected here.
     *
     * 🟡 Residual, disclosed rather than closed (S121 review r8 INFO-2):
     * whitespace is NOT trimmed — `' '`, `"\0"` pass through unchanged and a
     * padded `' <uuid> '` keeps its padding byte-for-byte. Adding `trim()`
     * here would be a behaviour change and is deliberately out of law.
     *
     * ⚠ The music-family sweep is SIX sites, not three — one grep is a false
     * all-clear: {@see \Phlix\Media\Music\MusicLibraryScanner} still holds
     * three inline local-variable copies pinned by the S127-era
     * PhpToken guard, while the DTO sites are guarded through `fromRow()`.
     * Read BOTH guards, not either alone.
     *
     * @param array<string, mixed> $row
     * @since E-09 cohort 2 (music DTO extraction; S121/S127 law)
     */
    public static function nullableNonEmptyStr(array $row, string $col): ?string
    {
        $value = $row[$col] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Parses a `datetime` column value into a `\DateTime`, or `null` when
     * the value carries no usable timestamp. Extracted byte-identically from
     * the three byte-identical `parseDateTime()` helpers of the music DTOs
     * (E-09 cohort 2; S143 law — whose docblock commanded: change all three
     * together, or extract. It is now extracted).
     *
     * 🔴 Before S143 this FABRICATED timestamps. `new \DateTime('')` does
     * not throw: PHP's parser reads an empty or whitespace-only string as
     * "now", so a `''` column yielded exactly the current second, and
     * `'0000-00-00 00:00:00'` — MySQL's zero date, which a `NOT NULL
     * DATETIME` yields under a non-strict SQL mode — produced
     * `-0001-11-30`. A fabricated timestamp looks plausible forever and
     * silently corrupts every sort/filter/report keyed on it; a `null` is
     * visibly absent. Both degenerate strings therefore return `null`.
     *
     * The type guard is `\DateTimeInterface`, not `\DateTime` (S143's second
     * bug): `\DateTimeImmutable` does NOT extend `\DateTime` — both
     * implement the interface — so an immutable is converted with
     * `\DateTime::createFromInterface()`, preserving the instant, the
     * microseconds and the timezone, while a mutable `\DateTime` is handed
     * back BY IDENTITY and must stay that way (pinned by the committed
     * guards). A non-string, non-interface value (an int `created_at` is
     * NOT a unix timestamp) and a parser rejection both become `null`.
     *
     * `trim()` decides EMPTYNESS only; any non-blank value is handed to the
     * parser byte-for-byte as-is — `' 2019-03-04 '` still parses exactly as
     * it always did. Widening what parses is not this law's business.
     *
     * @since E-09 cohort 2 (music DTO extraction; S143 law)
     */
    public static function parseMysqlDateTime(mixed $value): ?\DateTime
    {
        if ($value instanceof \DateTime) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTime::createFromInterface($value);
        }
        if (!is_string($value)) {
            return null;
        }

        // `''` and `'   '` BOTH parse as "now", and `'0000-00-00…'` parses as year
        // -1. None of the three is a timestamp the row carried, so none may be
        // handed back as one.
        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '0000-00-00')) {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Nullable `datetime` column: {@see self::parseMysqlDateTime()} over the
     * named key. Equivalent to the legacy
     * `isset($row[$col]) ? self::parseDateTime($row[$col]) : null` sites:
     * the parser maps a NULL value to `null` anyway, so the `isset()` leg
     * only duplicated the same collapse for the missing-key case.
     *
     * @param array<string, mixed> $row
     * @since E-09 cohort 2 (music DTO extraction; S143 law)
     */
    public static function nullableDateTime(array $row, string $col): ?\DateTime
    {
        return self::parseMysqlDateTime($row[$col] ?? null);
    }
}
