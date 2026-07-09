<?php

declare(strict_types=1);

/*
 * Backfill chapter thumbnails for already-stored media markers.
 *
 * For each marker in media_markers that lacks a thumbnail_path, extracts
 * a single JPEG frame at the marker's start_time_ms and stores the path
 * in thumbnail_path. Re-running is safe: markers that already have a
 * thumbnail_path no longer match the WHERE clause.
 *
 * Usage:
 *   php scripts/backfill-chapter-thumbs.php                    # all markers
 *   php scripts/backfill-chapter-thumbs.php --limit=500        # cap rows this run
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Transcoding\FfmpegRunner;

$limit = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    }
}

ConnectionPool::init(__DIR__ . '/../config/database.php');
$db = ConnectionPool::getConnection('mysql');
$repo = new ItemRepository($db);

// Build the FFmpeg runner from config/ffmpeg.php so the CLI uses the
// same binaries the live scanner uses.
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

// Candidate markers: those without a thumbnail_path.
$sql = "SELECT m.id, m.media_item_id, m.start_time_ms, m.marker_type, m.label,
               i.path AS video_path
        FROM media_markers m
        JOIN media_items i ON m.media_item_id = i.id
        WHERE m.thumbnail_path IS NULL
           OR m.thumbnail_path = ''";

if ($limit !== null && $limit > 0) {
    $sql .= " LIMIT ?";
}

$bindings = [];
if ($limit !== null && $limit > 0) {
    $bindings[] = $limit;
}

$rows = $db->query($sql, $bindings);
if (!is_array($rows)) {
    $rows = [];
}

echo 'Found ' . count($rows) . " marker(s) missing thumbnail data.\n";
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

    $markerId = is_int($row['id'] ?? null) ? $row['id'] : 0;
    $mediaItemId = is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '';
    $startTimeMs = is_int($row['start_time_ms'] ?? null) ? $row['start_time_ms'] : 0;
    $markerType = is_string($row['marker_type'] ?? null) ? $row['marker_type'] : '';
    $label = is_string($row['label'] ?? null) ? $row['label'] : '';
    $videoPath = is_string($row['video_path'] ?? null) ? $row['video_path'] : '';

    if ($markerId === 0 || $mediaItemId === '' || $videoPath === '') {
        echo "  skip: invalid row (missing id, media_item_id, or path)\n";
        $skipped++;
        continue;
    }

    if (!file_exists($videoPath)) {
        echo "  skip: {$videoPath} (file not found)\n";
        $skipped++;
        continue;
    }

    // Create thumbnail directory for this media item
    $thumbDir = $transcodeDir . '/chapters/' . $mediaItemId;
    if (!is_dir($thumbDir)) {
        if (!mkdir($thumbDir, 0755, true) && !is_dir($thumbDir)) {
            echo "  FAIL: marker {$markerId} (could not create directory {$thumbDir})\n";
            $failed++;
            continue;
        }
    }

    // Use marker ID as filename to avoid collisions and enable easy lookup
    $thumbPath = $thumbDir . '/' . $markerId . '.jpg';

    // Extract frame at start_time_ms (convert to seconds for FFmpeg)
    $startSeconds = (int) ($startTimeMs / 1000);
    $success = $ffmpeg->generateThumbnail($videoPath, $thumbPath, $startSeconds);

    if (!$success) {
        echo "  FAIL: marker {$markerId} at {$videoPath} (thumbnail extraction failed)\n";
        $failed++;
        continue;
    }

    if (!is_file($thumbPath)) {
        echo "  FAIL: marker {$markerId} at {$videoPath} (thumbnail file not found after extraction)\n";
        $failed++;
        continue;
    }

    // Update marker with thumbnail path
    try {
        $db->query(
            'UPDATE media_markers SET thumbnail_path = ? WHERE id = ?',
            [$thumbPath, $markerId]
        );
    } catch (\Throwable $e) {
        echo "  FAIL: marker {$markerId} (update failed: {$e->getMessage()})\n";
        $failed++;
        // Clean up the generated thumbnail since we couldn't store the path
        @unlink($thumbPath);
        continue;
    }

    $updated++;
    echo "  ok:   marker {$markerId} ({$markerType}: {$label}) -> {$thumbPath}\n";
}

echo str_repeat('-', 60) . "\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Failed:  {$failed}\n";
echo "Done.\n";

exit($failed > 0 ? 1 : 0);
