<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

/**
 * Photo scan result data transfer object.
 *
 * @since 0.16.0
 */
class PhotoScanResult
{
    /** @var string Scan status (completed/failed) */
    public string $status = 'completed';

    /** @var int Number of items added during scan */
    public int $itemsAdded = 0;

    /** @var int Number of items updated during scan */
    public int $itemsUpdated = 0;

    /** @var int Scan duration in milliseconds */
    public int $durationMs = 0;

    /** @var string|null Error message if scan failed */
    public ?string $errorMessage = null;
}
