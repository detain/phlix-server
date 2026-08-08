<?php

/**
 * S56 — rebase one freshly-encoded CMAF fragment onto the VOD timeline.
 *
 * Usage: `php scripts/fmp4-rebase-segment.php <segment> <init> <startSeconds>`
 *
 * ## Why this runs as a separate process
 *
 * On-demand segment encodes are DETACHED: `FfmpegRunner::buildDetachedSegmentCommand()`
 * emits `nohup setsid timeout … sh -c 'ffmpeg && … && mv tmp final'` and the HTTP
 * worker only polls for the published file (`TranscodeManager::produceSegment()`).
 * The rebase has to happen while the segment is still the `.part-<hex>` temp —
 * after it is published, a sibling worker may already be serving the bytes — so it
 * has to be a link in that shell chain rather than something the worker does after
 * the poll. Doing it in the worker would also be blocking file I/O on the resident
 * event loop, which this codebase forbids.
 *
 * The exit code is the contract: non-zero aborts the `&&` chain, so a fragment that
 * could not be rebased is never published and the request simply times out and
 * retries, exactly as it does for a failed encode.
 *
 * The heavy lifting — and the ffmpeg measurements that make this necessary — live in
 * {@see \Phlix\Media\Transcoding\Fmp4SegmentRebaser}.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

use Phlix\Media\Transcoding\Fmp4SegmentRebaser;

require_once __DIR__ . '/../vendor/autoload.php';

$argvList = $_SERVER['argv'] ?? [];
if (!is_array($argvList) || count($argvList) !== 4) {
    fwrite(STDERR, "usage: fmp4-rebase-segment.php <segment> <init> <startSeconds>\n");
    exit(2);
}

[, $segmentPath, $initPath, $rawStart] = array_map('strval', $argvList);

if (!is_numeric($rawStart)) {
    fwrite(STDERR, "fmp4-rebase-segment: startSeconds must be numeric, got '{$rawStart}'\n");
    exit(2);
}

try {
    Fmp4SegmentRebaser::rebase($segmentPath, $initPath, (float) $rawStart);
} catch (\Throwable $e) {
    fwrite(STDERR, 'fmp4-rebase-segment: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
