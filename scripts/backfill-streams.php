<?php

declare(strict_types=1);

/*
 * Idempotent backfill: populate media_streams rows for already-indexed time-based
 * media items that predate the scan-time stream extraction, or whose streams were
 * never properly stored (e.g. rows with NULL stream_index values).
 *
 * The script probes each candidate file with ffprobe, extracts all video and audio
 * streams, and inserts them into media_streams. It uses
 * MediaScanner::backfillItemSourceMetadata() internally so the offline and live
 * scan paths never drift.
 *
 * Candidates are time-based items (type IN video/movie/episode/audio) that either:
 * - Have no media_streams rows at all, OR
 * - Have media_streams rows with NULL stream_index (incomplete data)
 *
 * Re-running is safe: once media_streams is populated the WHERE clause excludes
 * the item, and the probe is read-only.
 *
 * Usage:
 *   php scripts/backfill-streams.php                    # all libraries
 *   php scripts/backfill-streams.php --library=<id>   # scope to one library
 *   php scripts/backfill-streams.php --limit=500      # cap rows this run
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
// same binaries the live scanner uses.
$ffmpegConfig = @include __DIR__ . '/../config/ffmpeg.php';
if (!is_array($ffmpegConfig)) {
    $ffmpegConfig = [];
}
$ffmpegPath = is_string($ffmpegConfig['ffmpeg_path'] ?? null) ? $ffmpegConfig['ffmpeg_path'] : '/usr/bin/ffmpeg';
$probePath = is_string($ffmpegConfig['probe_path'] ?? null) ? $ffmpegConfig['probe_path'] : '/usr/bin/ffprobe';
$transcodeDir = is_string($ffmpegConfig['transcode_dir'] ?? null)
    ? $ffmpegConfig['transcode_dir']
    : sys_get_temp_dir();

$ffmpeg = new FfmpegRunner($ffmpegPath, $probePath, $transcodeDir);

// The scanner owns the probe -> source -> streams logic; the CLI reuses it via
// backfillItemSourceMetadata() so the offline and live paths never diverge.
$scanner = new MediaScanner($db, $repo, null, null, null, $ffmpeg);

// Candidate rows: time-based items missing media_streams data.
// Two patterns:
//  1. No streams at all: subquery returns 0
//  2. Incomplete streams: stream_index IS NULL
$sql = "SELECT m.id, m.type, m.path, m.metadata_json
        FROM media_items m
        WHERE m.type IN ('video', 'movie', 'episode', 'audio')
          AND (
              -- Pattern 1: no streams exist for this item
              (SELECT COUNT(*) FROM media_streams s WHERE s.media_item_id = m.id) = 0
              --
              -- Pattern 2: streams exist but have NULL stream_index (incomplete)
              OR EXISTS (
                  SELECT 1 FROM media_streams s2
                  WHERE s2.media_item_id = m.id AND s2.stream_index IS NULL LIMIT 1
              )
          )";

$bindings = [];
if ($libraryFilter !== null) {
    $sql .= " AND m.library_id = ?";
    $bindings[] = $libraryFilter;
}
$sql .= " ORDER BY m.created_at ASC";
if ($limit !== null && $limit > 0) {
    $sql .= " LIMIT ?";
    $bindings[] = $limit;
}

$rows = $db->query($sql, $bindings);
if (!is_array($rows)) {
    $rows = [];
}

echo 'Found ' . count($rows) . " candidate item(s) missing stream data.\n";
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

    // Guard: file must exist on disk
    if ($path === '' || !file_exists($path)) {
        echo "  skip: " . ($path !== '' ? $path : '(empty path)') . " (file not found)\n";
        $skipped++;
        continue;
    }

    $failReason = null;
    try {
        $status = $scanner->backfillItemSourceMetadata($row);
    } catch (\Throwable $e) {
        $status = 'failed';
        $failReason = $e->getMessage();
    }

    switch ($status) {
        case 'updated':
            $updated++;
            echo "  ok:   {$path}\n";
            break;
        case 'failed':
            $failed++;
            $reason = $failReason ?? 'probe/persist returned failed status';
            echo "  FAIL: {$path} (probe/persist failed: {$reason})\n";
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

// Non-zero exit when any item hard-failed so automation can detect a partial run.
exit($failed > 0 ? 1 : 0);
