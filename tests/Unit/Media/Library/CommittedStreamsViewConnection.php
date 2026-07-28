<?php

/**
 * Phlix media server tests: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PDO;
use Workerman\MySQL\Connection;

/**
 * A SEPARATE logical connection onto {@see TransactionalStreamsConnection}'s
 * table, standing in for another Workerman worker (or another pooled coroutine)
 * reading `media_streams` while a replacement is in flight.
 *
 * Like any other MySQL connection it can see COMMITTED rows only, which is the
 * whole point: the torn read this fix exists to prevent
 * ({@see \Phlix\Media\Library\ItemRepository::replaceStreams()}) is by definition
 * an observation made from a DIFFERENT connection, so a double that applies
 * uncommitted writes to one shared view can never detect it. Build a real
 * {@see \Phlix\Media\Library\ItemRepository} on this so the actual
 * `getItemStreams()` read path is exercised, not a hand-rolled read.
 *
 * READ-ONLY: it understands the one SELECT the repository issues for stream rows
 * and answers everything else with an empty result. It records nothing into the
 * writer's op log, so a test may read through it from inside
 * {@see TransactionalStreamsConnection::$onOp} without perturbing what it is
 * observing.
 *
 * Obtain one via {@see TransactionalStreamsConnection::independentReader()}.
 */
final class CommittedStreamsViewConnection extends Connection
{
    /** The writer whose COMMITTED state this connection reads. */
    private TransactionalStreamsConnection $writer;

    /**
     * @param TransactionalStreamsConnection $writer Connection holding the table.
     *
     * @psalm-suppress MissingParentConstructorCall Intentional: no socket.
     */
    public function __construct(TransactionalStreamsConnection $writer)
    {
        // No parent::__construct() — this double never connects.
        $this->writer = $writer;
    }

    /**
     * @param string                        $query
     * @param array<int|string, mixed>|null $params
     * @param int                           $fetchmode
     * @return mixed
     */
    public function query($query = '', $params = null, $fetchmode = PDO::FETCH_ASSOC)
    {
        $sql = trim((string) $query);
        $args = is_array($params) ? array_values($params) : [];

        if (str_starts_with($sql, 'SELECT * FROM media_streams')) {
            return $this->writer->committedRowsFor(
                isset($args[0]) && is_string($args[0]) ? $args[0] : ''
            );
        }

        return [];
    }
}
