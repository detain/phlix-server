<?php

/**
 * Phlix media server component: Playlists.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Playlists;

/**
 * Static operator methods for evaluating rule comparisons.
 *
 * Each method takes the item's field value and the rule's expected value(s),
 * returning a boolean indicating whether the rule matches.
 *
 * @since 0.14.0
 */
final class RuleOperators
{
    /**
     * Equality comparison - case-sensitive exact match.
     *
     * @since 0.14.0
     */
    public static function equals(mixed $itemValue, mixed $ruleValue): bool
    {
        return $itemValue === $ruleValue;
    }

    /**
     * Inequality comparison - case-sensitive exact mismatch.
     *
     * @since 0.14.0
     */
    public static function notEquals(mixed $itemValue, mixed $ruleValue): bool
    {
        return $itemValue !== $ruleValue;
    }

    /**
     * Substring match - case-sensitive contains.
     *
     * @since 0.14.0
     */
    public static function contains(string $itemValue, string $ruleValue): bool
    {
        return str_contains($itemValue, $ruleValue);
    }

    /**
     * Inverse substring match - does not contain.
     *
     * @since 0.14.0
     */
    public static function notContains(string $itemValue, string $ruleValue): bool
    {
        return !str_contains($itemValue, $ruleValue);
    }

    /**
     * Greater than numeric comparison.
     *
     * @since 0.14.0
     */
    public static function greaterThan(int|float $itemValue, int|float $ruleValue): bool
    {
        return $itemValue > $ruleValue;
    }

    /**
     * Less than numeric comparison.
     *
     * @since 0.14.0
     */
    public static function lessThan(int|float $itemValue, int|float $ruleValue): bool
    {
        return $itemValue < $ruleValue;
    }

    /**
     * Greater-than-or-equal numeric comparison (L4).
     *
     * Exact boundary: `gte` matches when the item value equals or exceeds the
     * rule value — no epsilon dead zone (the engine previously faked gte with
     * `gt(x, rule - 0.001) || strictEquals`, which matched values strictly
     * BELOW the rule inside a ±0.001 band).
     *
     * @since 0.14.0
     */
    public static function greaterThanOrEqual(int|float $itemValue, int|float $ruleValue): bool
    {
        return $itemValue >= $ruleValue;
    }

    /**
     * Less-than-or-equal numeric comparison (L4). Exact boundary, no epsilon.
     *
     * @since 0.14.0
     */
    public static function lessThanOrEqual(int|float $itemValue, int|float $ruleValue): bool
    {
        return $itemValue <= $ruleValue;
    }

    /**
     * Range inclusion check - value must be between lo and hi (inclusive).
     *
     * @since 0.14.0
     */
    public static function between(int|float $itemValue, int|float $lo, int|float $hi): bool
    {
        return $itemValue >= $lo && $itemValue <= $hi;
    }

    /**
     * Set membership - item value must be in the allowed values array.
     *
     * L4: replaces the old loose `in_array(..., false)`, whose type juggling
     * produced magic hits (`0` matching `'abc'`, `''` matching `0`, `true`
     * matching `1`). Membership is now strict identity, widened ONLY for the
     * two JSON-decode reality the DSL actually carries: a numeric string and
     * its numeric equivalent ('2010' vs 2010) match as numbers, booleans never
     * mix with numbers, and strings only match strings.
     *
     * @param mixed $itemValue The value to check
     * @param array<mixed> $ruleValues Array of allowed values
     * @return bool True if item value is in the array
     *
     * @since 0.14.0
     */
    public static function in(mixed $itemValue, array $ruleValues): bool
    {
        foreach ($ruleValues as $ruleValue) {
            if (self::matches($itemValue, $ruleValue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set exclusion - item value must NOT be in the excluded values array.
     *
     * L4: complement of {@see self::in()} under the same type-aware equality.
     *
     * @param mixed $itemValue The value to check
     * @param array<mixed> $ruleValues Array of excluded values
     * @return bool True if item value is NOT in the array
     *
     * @since 0.14.0
     */
    public static function notIn(mixed $itemValue, array $ruleValues): bool
    {
        return !self::in($itemValue, $ruleValues);
    }

    /**
     * The type-aware membership comparison used by in/notIn (L4).
     */
    private static function matches(mixed $itemValue, mixed $ruleValue): bool
    {
        if ($itemValue === $ruleValue) {
            return true;
        }

        $left = self::numericValue($itemValue);
        $right = self::numericValue($ruleValue);

        return $left !== null && $right !== null && $left === $right;
    }

    /**
     * The float value of an int/float/numeric-string, or null for anything
     * else — notably booleans and non-numeric strings never quantify.
     */
    private static function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float)$value;
        }

        return null;
    }

    /**
     * Prefix match - string starts with the given prefix.
     *
     * @since 0.14.0
     */
    public static function startsWith(string $itemValue, string $ruleValue): bool
    {
        return str_starts_with($itemValue, $ruleValue);
    }

    /**
     * Suffix match - string ends with the given suffix.
     *
     * @since 0.14.0
     */
    public static function endsWith(string $itemValue, string $ruleValue): bool
    {
        return str_ends_with($itemValue, $ruleValue);
    }
}
