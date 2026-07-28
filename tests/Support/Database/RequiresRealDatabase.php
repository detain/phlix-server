<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Database;

use Workerman\MySQL\Connection;

/**
 * S126 — what a test that needs a real MySQL should `use`.
 *
 * This is deliberately a trait rather than a base class: the tests that need it
 * already extend `PHPUnit\Framework\TestCase` directly and several of them are
 * in `tests/Unit/`, so a base class would force an unrelated parent change on
 * every one of them. A `use RequiresRealDatabase;` line sits at the top of the
 * class where the next person writing an integration test will see it — which is
 * the point, because the defect this replaces spread by copy-paste from
 * neighbouring files.
 *
 * ⚠ Do NOT re-add a private `isMysqlReachable()` / `fsockopen()` probe to a test.
 * A port probe on its own cannot tell "no MySQL here" (skip) from "wrong
 * credentials" (a real failure), and in the default pooled configuration nothing
 * after it can fail either — see {@see IntegrationDbGuard} for the mechanism and
 * `tests/Unit/Support/IntegrationDbGuardAdoptionTest.php` for the check that
 * enforces this.
 *
 * ⚠ Do NOT wrap these calls in a `try`/`catch` that ends in `markTestSkipped()`.
 * `requireRealDatabase()` already skips on genuine absence all by itself; the
 * only thing it *throws* is {@see IntegrationDbUnusableException}, raised
 * precisely so that a reachable-but-unusable database reddens the run.
 * Converting that back into a skip restores the S126 defect exactly, which is
 * why the adoption test flags the shape.
 */
trait RequiresRealDatabase
{
    /**
     * Skip when MySQL is absent, fail loudly when it is present but unusable,
     * otherwise hand back `ConnectionPool`'s shared connection.
     *
     * @param string      $skipReason Trailing part of the skip message, appended to
     *                                `No MySQL on {host}:{port} — `.
     * @param string|null $host       Override the probe host. Defaults to the host
     *                                `config/database.php` resolved, i.e. the same
     *                                address the connection is opened to. ⚠ Overriding
     *                                lets the probe and the connection disagree — see
     *                                {@see IntegrationDbGuard}'s class docblock.
     * @param int|null    $port       Override the probe port (same caveat).
     */
    protected function requireRealDatabase(
        string $skipReason,
        ?string $host = null,
        ?int $port = null
    ): Connection {
        return IntegrationDbGuard::connection($skipReason, $host, $port);
    }

    /**
     * The same gate for a test that opens its own connection and does not want
     * `ConnectionPool`'s shared instance handed back.
     */
    protected function requireHealthyDatabase(
        string $skipReason,
        ?string $host = null,
        ?int $port = null
    ): void {
        IntegrationDbGuard::requireHealthyDatabase($skipReason, $host, $port);
    }
}
