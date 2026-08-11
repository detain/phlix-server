<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Browser;

use PDO;
use Workerman\MySQL\Connection;

/**
 * S315 — the one `transcode_jobs` row a job fixture needs, WITHOUT PHPUnit.
 *
 * Every other fMP4 fixture in this repo stubs the database with
 * `$this->createMock(Workerman\MySQL\Connection::class)`. That is unavailable here
 * on purpose: {@see \Phlix\Tests\E2E\Media\Transcoding\Fmp4HlsThroughControllerE2ETest}
 * serves its bytes from a SEPARATE OS PROCESS (`hls-controller-server.php`), so the
 * real {@see \Phlix\Server\Http\Controllers\HlsController} answers a real socket. A
 * PHPUnit mock cannot cross a `proc_open()`, so the row travels as JSON and this
 * class replays it.
 *
 * It answers exactly the two queries {@see \Phlix\Media\Transcoding\TranscodeManager}
 * asks on the on-demand serve path, matched the same way the mocks do:
 *
 *   - `… transcode_jobs WHERE id = ?` → the single row;
 *   - anything containing `COUNT(*)` → `[['c' => 0]]`.
 *
 * Anything else answers `[]`, which is what an absent row looks like — a read this
 * fixture does not model must degrade to "not found", never to a stale row from
 * another query's shape.
 *
 * ⚠ `parent::__construct()` is deliberately NOT called: it takes live MySQL
 * credentials and this stub must open no socket. Every inherited query builder
 * method is therefore unusable, which is correct — the only entry point the
 * transcoder uses is {@see query()}.
 */
final class StubJobRowConnection extends Connection
{
    /**
     * @param array<string, mixed> $row The `transcode_jobs` row, exactly as a live
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
     *                           silently-empty fixture would make every request 404
     *                           and read as "the controller refused", which is the
     *                           wrong diagnosis.
     */
    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("job-row fixture {$path} does not exist");
        }
        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || $decoded === []) {
            throw new \RuntimeException("job-row fixture {$path} is not a non-empty JSON object");
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
        if (str_contains($query, 'COUNT(*)')) {
            return [['c' => 0]];
        }

        return str_contains($query, 'transcode_jobs WHERE id = ?') ? [$this->row] : [];
    }
}
