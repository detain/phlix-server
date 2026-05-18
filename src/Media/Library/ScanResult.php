<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

/**
 * Result of a library scan operation.
 *
 * @property int $scanned Total number of files scanned
 * @property int $added Number of new items added
 * @property int $updated Number of existing items updated
 * @property int $durationMs Duration of the scan in milliseconds
 */
final class ScanResult
{
    public int $scanned = 0;
    public int $added = 0;
    public int $updated = 0;
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
            'duration_ms' => $this->durationMs,
        ];
    }
}
