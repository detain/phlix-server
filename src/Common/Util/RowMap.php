<?php

declare(strict_types=1);

namespace Phlix\Common\Util;

/**
 * Helpers for safely narrowing untyped result-set rows into
 * `array<string, mixed>` row maps.
 *
 * The Workerman MySQL driver returns `array<int, array>` from
 * `$db->query(...)` where each row is an `array<mixed, mixed>` as far
 * as PHPStan is concerned. Most of our domain code consumes rows as
 * `array<string, mixed>` row maps (the `Foo::fromRow()` pattern), so
 * this helper centralizes the key-narrowing loop that would otherwise
 * be copy-pasted across every repository.
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
}
