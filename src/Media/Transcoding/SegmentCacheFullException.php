<?php

/**
 * Phlix media server component: Transcoding.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

/**
 * Thrown when the segment cache filesystem has insufficient free space to
 * encode a new segment.
 *
 * A full tmpfs segment directory causes ENOSPC errors on write, which FFmpeg
 * surfaces as opaque encode failures that cascade into silent 404s at the player
 * layer. This exception is thrown proactively (before attempting the encode)
 * when disk_free_space() falls below the configurable minimum threshold, so the
 * HLS controller can fast-fail with HTTP 503 and trigger an opportunistic
 * {@see TranscodeManager::sweepSegmentCache()} to reclaim space before the
 * client retries.
 *
 * This is a transient, retryable condition — the sweep may free enough space
 * for a subsequent retry to succeed.
 *
 * @see TranscodeManager::ensureSegment() Where the disk-space guard is enforced.
 * @see TranscodeManager::sweepSegmentCache() The opportunistic cleanup triggered on ENOSPC.
 */
class SegmentCacheFullException extends \RuntimeException
{
}
