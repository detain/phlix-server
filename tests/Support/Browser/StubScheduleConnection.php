<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Browser;

use PDO;
use Workerman\MySQL\Connection;

/**
 * S295 — the one `access_schedules` row an in-block schedule proof needs,
 * WITHOUT PHPUnit.
 *
 * Every other schedule fixture in this repo stubs the database with
 * `$this->createMock(Workerman\MySQL\Connection::class)`. That is unavailable here
 * on purpose: {@see \Phlix\Tests\Unit\Server\Workerman\AccessScheduleHeadNoBodyWireTest}
 * serves its bytes from a SEPARATE OS PROCESS (`s295-head-body-server.php`), so the
 * real {@see \Phlix\Server\Http\Middleware\AccessScheduleMiddleware} answers a real
 * socket. A PHPUnit mock cannot cross a `proc_open()`, so the row travels as JSON
 * and this class replays it.
 *
 * It answers exactly the query the schedule check asks on the dispatch path —
 * `… access_schedules WHERE profile_id = ? AND is_active = TRUE` — matched the same
 * way the mocks do (anything containing `access_schedules` returns the single row).
 * Anything else answers `[]`, which is what an absent row looks like — a read this
 * fixture does not model must degrade to "not found", never to a stale row from
 * another query's shape.
 *
 * ⚠ `parent::__construct()` is deliberately NOT called: it takes live MySQL
 * credentials and this stub must open no socket. Every inherited query builder
 * method is therefore unusable, which is correct — the only entry point the
 * schedule check uses is {@see query()}.
 */
final class StubScheduleConnection extends Connection
{
    /**
     * @param array<string, mixed> $row The `access_schedules` row, exactly as a live
     *                                  `SELECT *` would hydrate it.
     */
    public function __construct(private readonly array $row)
    {
        // No parent::__construct() — see the class docblock.
    }

    /**
     * Load the row from the JSON file the test wrote for the server process.
     *
     * @throws \RuntimeException when the file is absent or not a JSON object — a
     *                           silently-empty fixture would make every request
     *                           pass the schedule check and read as "the middleware
     *                           passed", which is the wrong diagnosis.
     */
    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("schedule-row fixture {$path} does not exist");
        }
        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || $decoded === []) {
            throw new \RuntimeException("schedule-row fixture {$path} is not a non-empty JSON object");
        }

        /** @var array<string, mixed> $row */
        $row = $decoded;

        return new self($row);
    }

    /**
     * @param string             $query
     * @param array<mixed>|null  $params
     * @param int                $fetchmode
     *
     * @return list<array<string, mixed>>
     */
    public function query($query = '', $params = null, $fetchmode = PDO::FETCH_ASSOC): array
    {
        return str_contains($query, 'access_schedules') ? [$this->row] : [];
    }
}
