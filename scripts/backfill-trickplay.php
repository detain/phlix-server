<?php

declare(strict_types=1);

/*
 * Backfill trickplay sprite sheets for already-indexed media items.
 *
 * Generates a 60-thumb (6x10) sprite sheet + timeline JSON for scrubbing
 * preview in compatible players. Candidates are time-based items
 * (video/movie/episode) that have chapters but lack trickplay paths.
 *
 * Re-running is safe: items that already have trickplay paths no longer
 * match the WHERE clause. Sprite generation is read-only and never
 * modifies the source file.
 *
 * Usage:
 *   php scripts/backfill-trickplay.php                    # all libraries
 *   php scripts/backfill-trickplay.php --library=<id>   # scope to one library
 *   php scripts/backfill-trickplay.php --limit=500       # cap rows this run
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
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

// Create the trickplay output directory within the transcode dir.
$spriteDir = $transcodeDir . '/trickplay';
if (!is_dir($spriteDir)) {
    mkdir($spriteDir, 0755, true);
}

// Candidate rows: time-based items with chapters but no trickplay paths.
$sql = "SELECT id, type, path
        FROM media_items
        WHERE type IN ('video', 'movie', 'episode')
          AND chapters_json IS NOT NULL
          AND chapters_json != '[]'
          AND chapters_json != 'null'
          AND chapters_json != ''
          AND (trickplay_sprite_path IS NULL OR trickplay_timeline_path IS NULL)";

if ($libraryFilter !== null) {
    $sql .= " AND library_id = ?";
}

$sql .= " ORDER BY created_at ASC";

if ($limit !== null && $limit > 0) {
    $sql .= " LIMIT ?";
}

$bindings = [];
if ($libraryFilter !== null) {
    $bindings[] = $libraryFilter;
}
if ($limit !== null && $limit > 0) {
    $bindings[] = $limit;
}

$rows = $db->query($sql, $bindings);
if (!is_array($rows)) {
    $rows = [];
}

echo 'Found ' . count($rows) . " candidate item(s) missing trickplay data.\n";
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

    $itemId = is_string($row['id'] ?? null) ? $row['id'] : '';
    $path = is_string($row['path'] ?? null) ? $row['path'] : '';

    if ($itemId === '' || $path === '') {
        echo "  skip: invalid row (missing id or path)\n";
        $skipped++;
        continue;
    }

    if (!file_exists($path)) {
        echo "  skip: {$path} (file not found)\n";
        $skipped++;
        continue;
    }

    $result = $ffmpeg->generateTrickplaySprites($path, $spriteDir, 60);
    if ($result === null) {
        echo "  FAIL: {$path} (sprite generation failed)\n";
        $failed++;
        continue;
    }

    [$spritePath, $timelinePath] = $result;

    try {
        $repo->updateMarkers($itemId, [
            'trickplay_sprite_path' => $spritePath,
            'trickplay_timeline_path' => $timelinePath,
        ]);
    } catch (\Throwable $e) {
        echo "  FAIL: {$path} (store failed: {$e->getMessage()})\n";
        $failed++;
        continue;
    }

    $updated++;
    echo "  ok:   {$path}\n";
}

echo str_repeat('-', 60) . "\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Failed:  {$failed}\n";
echo "Done.\n";

exit($failed > 0 ? 1 : 0);
