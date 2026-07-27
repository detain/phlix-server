<?php

/**
 * Phlix media server test double: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Workerman\MySQL\Connection;

/**
 * Builds the identity-map result set INSIDE `query()`, so nothing outside the map it
 * feeds ever holds one of the path strings.
 *
 * ⚠ **THAT IS THE ENTIRE POINT OF THIS CLASS, AND IT IS WHY
 * {@see MusicScanSkipIndexTest::testMemoryPerEntryIsBounded()} CANNOT USE
 * {@see SkipIndexConnection} (review r1 B3).** A double that takes its rows through the
 * constructor forces the TEST to allocate every path first, so the map's keys are shared
 * by refcount with an array the test still holds and are invisible to a
 * `memory_get_usage()` delta. That is how the class docblock came to quote 90.9
 * bytes/entry for something that really costs 186.9. Here the rows are created and
 * returned in one expression, the caller's `$rows` local dies with `load()`, and the map
 * ends up the sole owner of every key — the production shape.
 *
 * @internal
 */
final class GeneratedIdentityConnection extends Connection
{
    /**
     * @param int $rows     How many rows to synthesise.
     * @param int $pathLen  Exact key length in bytes. Fixed rather than "whatever
     *        `sprintf` produced" so the allocator's size class is deterministic and the
     *        measurement reproduces to the byte across runs.
     */
    public function __construct(private readonly int $rows, private readonly int $pathLen = 56)
    {
    }

    /**
     * @param string $query
     * @param array<int, mixed>|null $params
     * @param int $fetchmode
     * @return list<array<string, string>>
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        unset($query, $params, $fetchmode);

        $out = [];
        for ($i = 0; $i < $this->rows; $i++) {
            // Production-shaped: /vault1/music/<artist>/<album>/NN - <title>.mp3
            $path = sprintf('/vault1/music/Artist %04d/Album %03d/%02d - T.mp3', $i, $i % 40, $i % 20);
            $out[] = [
                'path' => substr(str_pad($path, $this->pathLen, 'x'), 0, $this->pathLen),
                'file_mtime' => (string) (1_700_000_000 + $i),
                'file_size' => (string) (4_000_000 + $i),
            ];
        }

        return $out;
    }
}
