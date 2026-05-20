<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Dto;

use Workerman\MySQL\Connection;

/**
 * Narrowing helpers around {@see Connection::query()} results.
 *
 * {@see Connection::query()} is typed `mixed`; the LiveTv module treats
 * the returned object as a row cursor with a `num_rows` property and a
 * `fetch()` method. These helpers centralise the `instanceof
 * \Phlix\LiveTv\Dto\ResultSet` narrowing so PHPStan can verify the
 * accesses without inline casts or ignore-pragma directives.
 *
 * Test mocks `extends ResultSet`; production callers that hand us a
 * Workerman result that does not match the expected shape simply yield
 * no rows.
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
     * @param mixed $result Whatever {@see Connection::query()} returned.
     * @return array<int, array<string, mixed>>
     */
    public static function rows(mixed $result): array
    {
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
     * Fetch a single row from a query result, or null when the cursor is
     * empty.
     *
     * @param mixed $result Whatever {@see Connection::query()} returned.
     * @return array<string, mixed>|null
     */
    public static function firstRow(mixed $result): ?array
    {
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
        if (!$result instanceof ResultSet) {
            return false;
        }

        return $result->num_rows > 0;
    }
}
