<?php

declare(strict_types=1);

/*
 * Idempotent backfill: populate metadata_json['source'] (+ the total duration)
 * and media_streams for already-indexed time-based media items that predate the
 * scan-time source probe (Stream Quality/ABR step A1).
 *
 * Why: the scanner now probes each time-based file ONCE and stores a compact
 * technical summary — `metadata_json['source'] = {width, height, video_codec,
 * video_bitrate, pix_fmt, audio_codec, audio_bitrate}` — plus the video +
 * primary audio rows in media_streams, so the ABR ladder can be built without
 * re-probing on every playback start. Items scanned before that (or never
 * transcoded) carry no `source` key. This script finds them, probes each once,
 * and writes the same fields the live scanner does — reusing
 * MediaScanner::backfillItemSourceMetadata() verbatim so the offline and live
 * paths never drift.
 *
 * Candidates are time-based rows (type IN video/movie/episode/audio) whose
 * metadata_json lacks a `source` key. The type filter also excludes the
 * synthetic series/season containers. Re-running is safe: rows populated on a
 * prior run no longer match the WHERE clause, and the scanner method itself
 * skips any row that already has both a duration and a `source` blob WITHOUT
 * probing. Each item is probed under its own guard, so a single probe failure
 * (missing file, unreadable media) is reported as FAILED — a distinct bucket
 * from a plain SKIP — and never aborts the run; the process exits non-zero
 * if any row failed, so automation can detect a partial run and safely
 * re-invoke it.
 *
 * Usage:
 *   php scripts/backfill-source-metadata.php                 # every library
 *   php scripts/backfill-source-metadata.php --library=<id>  # scope to one library
 *   php scripts/backfill-source-metadata.php --limit=500     # cap rows this run
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Transcoding\FfmpegRunner;

$libraryFilter = null;
$limit = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--library=')) {
        $libraryFilter = substr($arg, strlen('--library='));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    }
}

ConnectionPool::init(__DIR__ . '/../config/database.php');
$db = ConnectionPool::getConnection('mysql');
$repo = new ItemRepository($db);

// Build the ffprobe runner from config/ffmpeg.php so the CLI probes with the
// same binaries the live scanner uses. Without a runner nothing can be probed,
// and MediaScanner::backfillItemSourceMetadata() would simply return false.
$ffmpegConfig = @include __DIR__ . '/../config/ffmpeg.php';
if (!is_array($ffmpegConfig)) {
    $ffmpegConfig = [];
}
$ffmpegPath = is_string($ffmpegConfig['ffmpeg_path'] ?? null) ? $ffmpegConfig['ffmpeg_path'] : '/usr/bin/ffmpeg';
$ffprobePath = is_string($ffmpegConfig['ffprobe_path'] ?? null) ? $ffmpegConfig['ffprobe_path'] : '/usr/bin/ffprobe';
$transcodeDir = is_string($ffmpegConfig['transcode_dir'] ?? null)
    ? $ffmpegConfig['transcode_dir']
    : sys_get_temp_dir();

$ffmpeg = new FfmpegRunner($ffmpegPath, $ffprobePath, $transcodeDir);

// The scanner owns the probe -> source -> streams logic; the CLI reuses it via
// backfillItemSourceMetadata() so the offline and live paths never diverge.
$scanner = new MediaScanner($db, $repo, null, null, null, $ffmpeg);

// Candidate rows: time-based items still missing metadata_json['source']. The
// escaped '\$.source' JSON path matches the codebase convention for double
// quoted SQL (see ItemRepository JSON_CONTAINS calls).
$sql = "SELECT id, type, path, metadata_json
        FROM media_items
        WHERE type IN ('video', 'movie', 'episode', 'audio')
          AND (metadata_json IS NULL
               OR JSON_EXTRACT(metadata_json, '\$.source') IS NULL)";
$bindings = [];
if ($libraryFilter !== null) {
    $sql .= " AND library_id = ?";
    $bindings[] = $libraryFilter;
}
$sql .= " ORDER BY created_at ASC";
if ($limit !== null && $limit > 0) {
    $sql .= " LIMIT ?";
    $bindings[] = $limit;
}

$rows = $db->query($sql, $bindings);
if (!is_array($rows)) {
    $rows = [];
}

echo 'Found ' . count($rows) . " candidate item(s) missing source metadata.\n";
if ($libraryFilter !== null) {
    echo "Library filter: {$libraryFilter}\n";
}
if ($limit !== null && $limit > 0) {
    echo "Row limit: {$limit}\n";
}
echo str_repeat('-', 60) . "\n";

$updated = 0;
$skipped = 0;
$failed = 0;
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $path = is_string($row['path'] ?? null) ? $row['path'] : '';

    // One probe per item. The scanner method is already self-guarded and never
    // throws; the extra try/catch here is defence in depth so a single failure
    // can never abort the whole run.
    try {
        $status = $scanner->backfillItemSourceMetadata($row);
    } catch (\Throwable $e) {
        $status = 'failed';
    }

    switch ($status) {
        case 'updated':
            $updated++;
            echo "  ok:   {$path}\n";
            break;
        case 'failed':
            $failed++;
            echo "  FAIL: {$path} (probe/persist failed; reselected on next run)\n";
            break;
        default:
            $skipped++;
            echo "  skip: {$path} (already populated or probe yielded no source)\n";
            break;
    }
}

echo str_repeat('-', 60) . "\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Failed:  {$failed}\n";
echo "Done.\n";

// Non-zero exit when any item hard-failed its probe/persist, so an operator or
// wrapper (systemd, cron, CI) sees the run needs another pass. All items were
// still processed above; a full success or all-skipped run exits 0.
exit($failed > 0 ? 1 : 0);
