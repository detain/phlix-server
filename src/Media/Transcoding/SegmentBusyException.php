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
 * Thrown when an on-demand HLS segment cannot be encoded right now because the
 * server is already running its ceiling of concurrent segment encodes.
 *
 * This is a transient, retryable condition — NOT a failure. The HLS controller
 * translates it into an HTTP 503 with a short `Retry-After`, so the player backs
 * off briefly and re-requests rather than blocking a worker (or timing out on the
 * client) while the box is saturated. Fast-failing over-capacity requests keeps
 * the CPU free for the encodes already in flight, so they finish quickly instead
 * of every encode slowing past the client's fragment first-byte timeout.
 *
 * @see TranscodeManager::ensureSegment() Where the concurrency ceiling is enforced.
 */
class SegmentBusyException extends \RuntimeException
{
}
