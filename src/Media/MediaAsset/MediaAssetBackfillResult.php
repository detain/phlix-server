<?php

/**
 * Phlix media server component: Media Asset.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\MediaAsset;

/**
 * Outcome of one {@see MediaAssetBackfill::reenqueueLibrary()} pass.
 *
 * Every counter is a DISJOINT bucket and they add up:
 * `scanned = enqueued + alreadyComplete + alreadyQueued + missingFile + ineligible`.
 * That identity is asserted in the tests, because a backfill whose buckets do not
 * account for every row it walked is a backfill that silently dropped items — the
 * exact failure mode S284 exists to correct in the first place.
 *
 * @since 0.36.0 (S284)
 */
final class MediaAssetBackfillResult
{
    /**
     * @param int $scanned         `media_items` rows walked in this library.
     * @param int $enqueued        Jobs written to the media-asset queue.
     * @param int $alreadyComplete Skipped: sprite + BIF already on disk.
     * @param int $alreadyQueued   Skipped: a job file for that item already existed.
     * @param int $missingFile     Skipped: the source file is gone from disk.
     * @param int $ineligible      Skipped: no path, or an unsupported container.
     */
    public function __construct(
        public readonly int $scanned = 0,
        public readonly int $enqueued = 0,
        public readonly int $alreadyComplete = 0,
        public readonly int $alreadyQueued = 0,
        public readonly int $missingFile = 0,
        public readonly int $ineligible = 0,
    ) {
    }

    /**
     * Serialise for logs / the API response.
     *
     * @return array{scanned: int, enqueued: int, already_complete: int,
     *               already_queued: int, missing_file: int, ineligible: int}
     */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'enqueued' => $this->enqueued,
            'already_complete' => $this->alreadyComplete,
            'already_queued' => $this->alreadyQueued,
            'missing_file' => $this->missingFile,
            'ineligible' => $this->ineligible,
        ];
    }
}
