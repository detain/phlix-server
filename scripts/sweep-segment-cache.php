#!/usr/bin/env php
<?php

/**
 * Segment-cache sweep CLI.
 *
 * Walks the on-demand segment cache and reclaims job directories (idle past the
 * age TTL first, then least-recently-used until back under the size budget). It is
 * the same walk as TranscodeManager::sweepSegmentCacheNow(), run as its own process
 * so the Workerman event loop never stalls on a full-cache stat walk. The worker
 * launches it detached (`nice -n 19`, output to /dev/null, not waited on) from the
 * reaper timer and from the throttled 503 path.
 *
 * Concurrency: a non-blocking exclusive flock on `<segment-dir>/.sweep.lock`. If
 * another sweep already holds the lock, this run exits 0 at once, so concurrent
 * launches from several workers coalesce into one walk.
 *
 * Budgets: a non-positive or non-numeric budget means "use the default", the same rule the
 * TranscodeManager constructor applies (see TranscodeManager::effectiveCacheBudgets()).
 *
 * Usage: php scripts/sweep-segment-cache.php <segment-dir> <max-bytes> <max-age-seconds>
 *
 * Exits 0 on success, when coalesced into a running sweep, or when the directory holds
 * nothing to reclaim; 1 on bad arguments (wrong count, missing segment dir) or an
 * unopenable lock file.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Transcoding\TranscodeManager;

// Bootstrap the logger the same way the other scripts do (run-library-scan-worker.php,
// import-imdb-datasets.php). Without init() the reclaim line is written with an empty
// config, and under the detached launch stdout is /dev/null, so the line would be lost.
LoggerFactory::init(dirname(__DIR__) . '/config/logger.php');

$argvList = $argv ?? [];
if (count($argvList) !== 4) {
    fwrite(STDERR, "usage: sweep-segment-cache.php <segment-dir> <max-bytes> <max-age-seconds>\n");
    exit(1);
}

$segmentDir = rtrim((string) $argvList[1], '/');
[$maxBytes, $maxAgeSeconds] = TranscodeManager::effectiveCacheBudgets(
    (int) $argvList[2],
    (int) $argvList[3],
);

if ($segmentDir === '' || !is_dir($segmentDir)) {
    fwrite(STDERR, "sweep-segment-cache: invalid segment dir\n");
    exit(1);
}

$lockPath = $segmentDir . '/' . TranscodeManager::SEGMENT_SWEEP_LOCK_FILE;
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "sweep-segment-cache: cannot open lock {$lockPath}\n");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Another sweep is already walking this cache; that run covers this request.
    fclose($lock);
    exit(0);
}

try {
    TranscodeManager::sweepCacheTree(
        $segmentDir,
        $maxBytes,
        $maxAgeSeconds,
        LoggerFactory::get(LogChannels::MEDIA),
    );
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit(0);
