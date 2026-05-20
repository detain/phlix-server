<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\Dto;

/**
 * Static helpers for safely narrowing mixed values from JSON-decoded
 * provider responses into concrete scalar/array types.
 *
 * The HTTP metadata providers (TMDB, TVDB, Fanart.tv, Last.fm, etc.) all
 * return `array<string, mixed>` after `json_decode(..., true)`, but
 * downstream formatting code needs concrete scalar types. Rather than
 * repeating `is_string/is_int/is_array` guards inline at every call site,
 * this helper centralises the narrowing.
 *
 * @author Phlix Development Team
 * @since Wave 5b
 */
final class MetadataValue
{
    private function __construct()
    {
    }

    /**
     * Narrow a mixed value to a string, falling back to a default.
     */
    public static function asString(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $default;
    }

    /**
     * Narrow a mixed value to a nullable string.
     */
    public static function asNullableString(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Narrow a mixed value to an int, falling back to a default.
     */
    public static function asInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * Narrow a mixed value to a nullable int.
     */
    public static function asNullableInt(mixed $value): ?int
    {
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
     * Narrow a mixed value to a float, falling back to a default.
     */
    public static function asFloat(mixed $value, float $default = 0.0): float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return $default;
    }

    /**
     * Narrow a mixed value to a nullable float.
     */
    public static function asNullableFloat(mixed $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return null;
    }

    /**
     * Narrow a mixed value to a string-keyed associative array.
     * Non-array inputs and non-string keys are discarded.
     *
     * @return array<string, mixed>
     */
    public static function asAssoc(mixed $value): array
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
     * Narrow a mixed value to a list of string-keyed associative arrays.
     * Non-array entries are dropped.
     *
     * @return list<array<string, mixed>>
     */
    public static function asAssocList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $out[] = self::asAssoc($entry);
            }
        }
        return $out;
    }

    /**
     * Narrow a mixed value to a list of mixed entries (preserving order).
     * Non-array inputs return an empty list.
     *
     * @return list<mixed>
     */
    public static function asList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values($value);
    }
}
