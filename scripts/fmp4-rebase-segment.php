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
 * @license   MPL-2.0
 */

declare(strict_types=1);

use Phlix\Media\Transcoding\Fmp4SegmentRebaser;

// Load ONLY the rebaser file, not vendor/autoload.php. This runs once per segment
// inside the encode's `&&` chain, and composer's autoload (about 3 MB of classmap and
// static maps) cost roughly 125 ms per launch on the dev host, against about 15 ms for
// a bare `php -n`. Fmp4SegmentRebaser is self-contained: its only external name is the
// global \RuntimeException (checked; FfmpegRunner appears only in a docblock). Keep it
// that way, or switch back to the autoloader.
require_once __DIR__ . '/../src/Media/Transcoding/Fmp4SegmentRebaser.php';

$argvList = $_SERVER['argv'] ?? [];
if (!is_array($argvList) || count($argvList) !== 4) {
    fwrite(STDERR, "usage: fmp4-rebase-segment.php <segment> <init> <startSeconds>\n");
    exit(2);
}

[, $segmentPath, $initPath, $rawStart] = $argvList;

// Parse at the boundary, trust internally: $_SERVER carries mixed, so each
// operand is asserted to string before use instead of blindly strval-cast.
if (!is_string($segmentPath) || !is_string($initPath) || !is_string($rawStart)) {
    fwrite(STDERR, "usage: fmp4-rebase-segment.php <segment> <init> <startSeconds>\n");
    exit(2);
}

// Exit 2 (bad input) is decided here, before any file is read, for: a non-numeric value, a
// non-finite value such as "1e999" (is_numeric() accepts it, and it casts to INF), a negative
// value, and a finite value above 2^32-1 seconds (about 136 years).
//
// The upper bound is a sanity cap, not the real limit. The rebaser writes the start into a
// 32-bit version-0 tfdt/sidx field, so the largest start that fits is 0xFFFFFFFF / timescale
// seconds. That is timescale-dependent: about 47,721 s at 90000, 349,525 s at 12288, and
// 4,294,967 s at 1000. Those starts are refused by the rebaser (RangeException), which this
// script maps to exit 2 below. 2^32-1 is that field's ceiling at timescale 1, so no start above
// it fits any version-0 box at any timescale.
$maxStartSeconds = 4294967295.0;
$startSeconds = is_numeric($rawStart) ? (float) $rawStart : NAN;
if (
    !is_numeric($rawStart)
    || !is_finite($startSeconds)
    || $startSeconds < 0.0
    || $startSeconds > $maxStartSeconds
) {
    fwrite(
        STDERR,
        "fmp4-rebase-segment: startSeconds must be a finite number from 0 to {$maxStartSeconds}, got '{$rawStart}'\n"
    );
    exit(2);
}

try {
    Fmp4SegmentRebaser::rebase($segmentPath, $initPath, $startSeconds);
} catch (\RangeException $e) {
    // The start is valid but cannot be represented in this fragment's box version. Nothing was
    // written. Exit 2 keeps it in the same class as bad input: the caller must not retry with the
    // same start.
    fwrite(STDERR, 'fmp4-rebase-segment: start not representable: ' . $e->getMessage() . "\n");
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, 'fmp4-rebase-segment: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
