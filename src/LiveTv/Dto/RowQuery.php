<?php

/**
 * Phlix media server component: Dto.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Dto;

use Workerman\MySQL\Connection;

/**
 * Narrowing helpers around {@see Connection::query()} results.
 *
 * {@see Connection::query()} is typed `mixed`. In production a SELECT returns a
 * **plain `array<int, array<string, mixed>>`** (Workerman's
 * `Connection::query()` → `PDOStatement::fetchAll()`), while the LiveTv unit
 * tests historically hand these helpers a cursor object (`num_rows` + `fetch()`)
 * modelled by the abstract {@see ResultSet}. These helpers accept BOTH shapes so
 * the LiveTv module reads real rows against a live database (the prod plain-array
 * shape) AND keeps the existing `ResultSet` mock path working.
 *
 * Centralising the shape handling here lets PHPStan verify the row accesses
 * without inline casts or ignore-pragma directives.
 *
 * Any other value (`null`, scalar, INSERT/UPDATE affected-row int, …) simply
 * yields no rows.
 *
 * @since Wave 5a (post-O.7)
 */
final class RowQuery
{
    private function __construct()
    {
    }

    /**
     * Collect every row from a query result into a typed array.
     *
     * Accepts the production plain-array shape (`array<int, array<string,
     * mixed>>`) and the {@see ResultSet} cursor mock shape.
     *
     * @param mixed $result Whatever {@see Connection::query()} returned.
     * @return array<int, array<string, mixed>>
     */
    public static function rows(mixed $result): array
    {
        // Production shape: a plain list of associative row arrays.
        if (is_array($result)) {
            return self::rowsFromArray($result);
        }

        // Test-mock / cursor shape.
        if (!$result instanceof ResultSet) {
            return [];
        }

        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Fetch a single row from a query result, or null when there are no rows.
     *
     * @param mixed $result Whatever {@see Connection::query()} returned.
     * @return array<string, mixed>|null
     */
    public static function firstRow(mixed $result): ?array
    {
        // Production shape: first element of the plain row list.
        if (is_array($result)) {
            $first = $result[0] ?? null;
            if (is_array($first)) {
                /** @var array<string, mixed> $first */
                return $first;
            }
            return null;
        }

        // Test-mock / cursor shape.
        if (!$result instanceof ResultSet) {
            return null;
        }

        if ($result->num_rows === 0) {
            return null;
        }

        $row = $result->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * True when the result reports at least one row.
     */
    public static function hasRows(mixed $result): bool
    {
        // Production shape: a non-empty plain list means rows are present.
        if (is_array($result)) {
            return $result !== [];
        }

        // Test-mock / cursor shape.
        if (!$result instanceof ResultSet) {
            return false;
        }

        return $result->num_rows > 0;
    }

    /**
     * Normalise a plain array result into a list of associative row arrays.
     *
     * @param array<array-key, mixed> $result
     * @return array<int, array<string, mixed>>
     */
    private static function rowsFromArray(array $result): array
    {
        $rows = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
