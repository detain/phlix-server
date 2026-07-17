<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

/**
 * Result of a library scan operation.
 *
 * @property int $scanned Total number of files scanned
 * @property int $added Number of new items added
 * @property int $updated Number of existing items updated
 * @property int $removed Number of items pruned (source file gone from disk)
 * @property int $durationMs Duration of the scan in milliseconds
 */
final class ScanResult
{
    public int $scanned = 0;
    public int $added = 0;
    public int $updated = 0;

    /**
     * Number of items pruned during a non-destructive rescan because their
     * source file no longer exists on disk (including empty series/season
     * containers left behind by that pruning). Zero for a plain scan.
     */
    public int $removed = 0;
    public int $durationMs = 0;

    /**
     * Gets a summary array of the scan result.
     *
     * @return array<string, int> Summary array
     */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'added' => $this->added,
            'updated' => $this->updated,
            'removed' => $this->removed,
            'duration_ms' => $this->durationMs,
        ];
    }
}
