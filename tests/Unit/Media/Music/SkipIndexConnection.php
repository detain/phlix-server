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
 * Returns a fixed row set for the identity-map SELECT and records every statement.
 *
 * Its own file rather than co-located with {@see MusicScanSkipIndexTest}: PSR-1
 * "each class must be in a file by itself" (review r1 non-blocking 8), and the
 * dominant precedent in this suite — `FakeMetadataSource`, `CountingOAuth2StateStore`,
 * `TestConnection`, `SpyRatingService` and ~20 others all live alone.
 *
 * @internal
 */
final class SkipIndexConnection extends Connection
{
    /** @var list<string> Every statement, in order. */
    public array $statements = [];

    /**
     * @param list<array<string, mixed>> $rows Well-formed rows.
     * @param list<mixed> $extraRows Rows that are NOT arrays, so the loader's own
     *        `is_array($row)` guard is exercised — the real client can return a mixed
     *        list shape and a scalar row must be dropped, not crash the walk.
     */
    public function __construct(private readonly array $rows, private readonly array $extraRows = [])
    {
    }

    /**
     * @param string $query
     * @param array<int, mixed>|null $params
     * @param int $fetchmode
     * @return list<array<string, mixed>>
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        unset($params, $fetchmode);
        $this->statements[] = (string) $query;

        return array_merge($this->rows, $this->extraRows);
    }
}
