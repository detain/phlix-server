<?php

/**
 * Phlix media server test double: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Database\PhlixMySQLConnection;

/**
 * A REAL MySQL connection that also records every statement it forwards.
 *
 * ## Why this exists, and why it is not an in-memory double
 *
 * S148's claim is a COUNT OF STATEMENTS THE SERVER RECEIVED — "a full read of an
 * unchanged library must issue zero stamp `UPDATE`s", "a retagged N-track album must
 * issue one recount, not N".
 *
 * The in-memory doubles CAN count statements — {@see \Phlix\Tests\Unit\Media\Music\SkipSchemaConnection::$statements}
 * and {@see \Phlix\Tests\Unit\Media\Music\MusicSchemaConnection::countStatements()} do
 * exactly that, and the cheap per-push guards in
 * {@see \Phlix\Tests\Unit\Media\Music\MusicScanReparentTest} rely on it. What they
 * cannot do is make the count mean anything about PRODUCTION, because the branch the
 * scanner takes depends on the rows MySQL returns and a fake returns the rows its
 * author modelled. Where the model and the server disagree, the fake's count is a
 * faithful count of the wrong scan.
 *
 * This class subclasses the production connection and delegates every statement to it,
 * so the SQL is parsed, planned and executed by a real server exactly as in production
 * — the recording is a side effect, not a substitute. It also keeps the bound
 * PARAMETERS alongside each statement, which the unit doubles' statement logs do not.
 *
 * ⚠ Stated narrowly, because the neighbouring over-claim was review r1's finding 2 and
 * it must not be re-committed here in a new place. "Which album was recounted" IS
 * answerable on {@see \Phlix\Tests\Unit\Media\Music\MusicLibraryScannerTest}'s
 * `MusicSchemaConnection`, whose `$totalTracksWrites` is keyed by album id. What that
 * array holds is the LAST value written per album, so the question it cannot answer is
 * **HOW MANY TIMES** a given album was recounted — which is exactly S148's claim, and
 * why the per-album counting in this file is done here.
 *
 * ⚠ **This is the direct answer to mutation M10, and M10 is the disagreement above made
 * concrete.** During S145 the mutation that reverted only the widened `SELECT` survived
 * the ENTIRE unit suite, because both in-memory doubles return a stored row wholesale
 * and ignore the statement's column list, so "a column the SELECT does not fetch" is a
 * distinction they cannot express — the modelled scan issued no writes while the real
 * one rewrote all 61,111 rows.
 *
 * @internal
 */
final class RecordingMySqlConnection extends PhlixMySQLConnection
{
    /**
     * Every statement forwarded since the last {@see self::startLog()}, in order.
     *
     * @var list<array{sql: string, params: array<int, mixed>}>
     */
    public array $log = [];

    /** Whether statements are being recorded (off during fixture setup/teardown). */
    public bool $recording = false;

    /**
     * Statements that must fail instead of reaching the server.
     *
     * @var list<array{needle: string, param: string}>
     */
    private array $faults = [];

    /**
     * Make one statement THROW instead of executing — the shape a real SQL error takes
     * (`Connection::execute()` re-throws; it never returns `false`).
     *
     * The match is narrowed by the FIRST BOUND PARAMETER, and that is the whole point:
     * `refreshAlbumTrackTotal()` issues byte-identical SQL for every album and differs
     * only in the id it binds, so "fail the recount of THIS album and no other" is not
     * expressible on SQL text alone.
     *
     * ⚠ It IS expressible on one of the in-memory doubles, and saying otherwise would
     * repeat review r1's finding 2 in a new place:
     * {@see \Phlix\Tests\Unit\Media\Music\MusicLibraryScannerTest}'s
     * `MusicSchemaConnection::faultOnNth($needle, $occurrence, $param)` narrows on a
     * bound parameter through the same mechanism (12 call sites, of which 2 — at
     * `MusicLibraryScannerTest.php:1494` and `:1956` — pass the `$param`). The one
     * this method is for is
     * {@see \Phlix\Tests\Unit\Media\Music\SkipSchemaConnection}, which keeps only the
     * SQL (`$statements`) and has no fault arm at all — and, more to the point, a fault
     * injected here interrupts a scan a REAL server is answering, so what the rest of
     * the `finally` then does is production behaviour rather than modelled behaviour.
     *
     * @param string     $needle     Case-sensitive SQL substring, e.g. `'SET a.total_tracks'`.
     * @param int|string $firstParam Value `$params[0]` must equal, compared as a string.
     * @return void
     */
    public function failOn(string $needle, int|string $firstParam): void
    {
        $this->faults[] = ['needle' => $needle, 'param' => (string) $firstParam];
    }

    /**
     * Disarm every {@see self::failOn()}.
     *
     * @return void
     */
    public function clearFaults(): void
    {
        $this->faults = [];
    }

    /**
     * Mirrors the driver's signature, which is why it is untyped.
     *
     * @param string $query
     * @param array<int, mixed>|null $params
     * @param int $fetchmode
     * @return mixed
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        if ($this->recording) {
            $this->log[] = [
                'sql' => (string) $query,
                // Captured BEFORE the parent runs, because the production connection
                // re-keys positional binds (the workerman/mysql v1.0.9 bindMore() bug)
                // and a test asserting on `$params[0]` must see what the SCANNER passed.
                'params' => is_array($params) ? array_values($params) : [],
            ];
        }

        // AFTER the log append on purpose: the statement WAS issued, and a test that
        // asserts "the failing recount was attempted" has to be able to see it.
        $first = is_array($params) && is_scalar($params[0] ?? null) ? (string) $params[0] : null;
        foreach ($this->faults as $fault) {
            if ($first === $fault['param'] && str_contains((string) $query, $fault['needle'])) {
                throw new \RuntimeException(
                    sprintf('injected failure for %s bound to %s', $fault['needle'], $fault['param']),
                );
            }
        }

        return parent::query($query, $params, $fetchmode);
    }

    /**
     * Starts recording from empty.
     *
     * @return void
     */
    public function startLog(): void
    {
        $this->log = [];
        $this->recording = true;
    }

    /**
     * Stops recording and keeps whatever has been collected.
     *
     * @return void
     */
    public function stopLog(): void
    {
        $this->recording = false;
    }

    /**
     * Every recorded statement whose SQL contains `$needle`.
     *
     * @param string $needle Case-sensitive substring, e.g. `'metadata_json = JSON_SET'`.
     * @return list<array{sql: string, params: array<int, mixed>}>
     */
    public function matching(string $needle): array
    {
        $out = [];
        foreach ($this->log as $entry) {
            if (str_contains($entry['sql'], $needle)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * How many recorded statements contain `$needle`.
     *
     * @param string $needle Case-sensitive substring.
     * @return int
     */
    public function countMatching(string $needle): int
    {
        return count($this->matching($needle));
    }
}
