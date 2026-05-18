<?php

declare(strict_types=1);

namespace Phlex\Playlists;

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
     * @param mixed $itemValue The value to check
     * @param array<mixed> $ruleValues Array of allowed values
     * @return bool True if item value is in the array
     *
     * @since 0.14.0
     */
    public static function in(mixed $itemValue, array $ruleValues): bool
    {
        return in_array($itemValue, $ruleValues, false);
    }

    /**
     * Set exclusion - item value must NOT be in the excluded values array.
     *
     * @param mixed $itemValue The value to check
     * @param array<mixed> $ruleValues Array of excluded values
     * @return bool True if item value is NOT in the array
     *
     * @since 0.14.0
     */
    public static function notIn(mixed $itemValue, array $ruleValues): bool
    {
        return !in_array($itemValue, $ruleValues, false);
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
