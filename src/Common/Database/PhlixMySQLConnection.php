<?php

declare(strict_types=1);

namespace Phlix\Common\Database;

use Workerman\MySQL\Connection;

/**
 * Workerman MySQL Connection with one bug-fix applied.
 *
 * `workerman/mysql` v1.0.9 (the latest tagged release as of writing)
 * has a `bindMore()` implementation that calls `array_keys($parray)`
 * and feeds the raw integer keys (0, 1, 2, …) straight into PDO::bindParam.
 * PHP 8.x's PDO is strict: param-index zero throws
 * "PDOStatement::bindParam(): Argument #1 ($param) must be greater than
 *  or equal to 1".
 *
 * Phlix's queries use the natural `$db->query($sql, [$a, $b])` pattern
 * (positional arrays), which exercises that buggy path on every call.
 * Rather than re-key every call site to either named placeholders or
 * `[1 => $a, 2 => $b]`, we just normalise here once.
 *
 * Associative arrays (string keys, e.g. `[':id' => $id]`) pass through
 * untouched.
 */
final class PhlixMySQLConnection extends Connection
{
    /**
     * @param array<int|string, mixed>|string $parray
     */
    public function bindMore($parray): void
    {
        if (is_array($parray) && $parray !== [] && array_is_list($parray)) {
            // re-key [0=>'a', 1=>'b'] → [1=>'a', 2=>'b']
            $parray = array_combine(range(1, count($parray)), $parray);
        }
        parent::bindMore($parray);
    }
}
