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
 * Returns a value the real client hands back for an unrecognised leading keyword: `null`.
 *
 * @internal
 */
final class NonListConnection extends Connection
{
    public function __construct()
    {
    }

    /**
     * @param string $query
     * @param array<int, mixed>|null $params
     * @param int $fetchmode
     * @return null
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        unset($query, $params, $fetchmode);

        return null;
    }
}
