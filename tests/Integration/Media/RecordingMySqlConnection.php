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
 * issue one recount, not N". Neither claim is about a return value, so neither can be
 * proven by a fake that answers queries from a PHP array: such a fake proves what the
 * fake's author decided the scanner would see, not what MySQL was asked to do.
 *
 * This class subclasses the production connection and delegates every statement to it,
 * so the SQL is parsed, planned and executed by a real server exactly as in production
 * — the recording is a side effect, not a substitute.
 *
 * ⚠ **This is the direct answer to mutation M10.** During S145 the mutation that
 * reverted only the widened `SELECT` survived the ENTIRE unit suite, because both
 * in-memory doubles return a stored row wholesale and ignore the statement's column
 * list, so "a column the SELECT does not fetch" is a distinction they cannot express.
 * Anything asserted about the scanner's write volume has to be asserted here.
 *
 * @internal
 */
final class RecordingMySqlConnection extends PhlixMySQLConnection
{
    /**
     * Every statement forwarded since the last {@see self::clearLog()}, in order.
     *
     * @var list<array{sql: string, params: array<int, mixed>}>
     */
    public array $log = [];

    /** Whether statements are being recorded (off during fixture setup/teardown). */
    public bool $recording = false;

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
