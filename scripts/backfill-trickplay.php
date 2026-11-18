<?php

declare(strict_types=1);

/*
 * Backfill trickplay artefacts (sprite sheet, timeline JSON and Roku BIF) for
 * already-indexed media items.
 *
 * Candidates are time-based items (video/movie/episode) that have chapters.
 * The database path columns are not used to decide anything: an item can have
 * a sprite and still lack its BIF, and only the disk can say which artefacts
 * exist. An item whose three artefacts are all on disk is skipped with no
 * decode (its database paths are filled in if they are missing). Anything else
 * is generated in ONE ffmpeg pass that produces the sprite, timeline and BIF.
 *
 * Re-running is safe: complete items are skipped, and a half-finished item is
 * regenerated rather than trusted. Sprite generation is read-only and never
 * modifies the source file.
 *
 * Usage:
 *   php scripts/backfill-trickplay.php                    # all libraries
 *   php scripts/backfill-trickplay.php --library=<id>   # scope to one library
 *   php scripts/backfill-trickplay.php --limit=500       # cap items generated this run
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Streaming\Trickplay\TrickplayArtefacts;
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

// Create the base trickplay output directory within the transcode dir.
// Individual item subdirectories are created per-item below.
$spriteBaseDir = $transcodeDir . '/trickplay';
if (!is_dir($spriteBaseDir)) {
    mkdir($spriteBaseDir, 0755, true);
}

// Candidate rows: time-based items with chapters. The trickplay path columns are
// deliberately NOT filtered on here (see the header): the on-disk check below is
// the only thing that decides whether an item is done.
$sql = "SELECT id, type, path, trickplay_sprite_path, trickplay_timeline_path
        FROM media_items
        WHERE type IN ('video', 'movie', 'episode')
          AND chapters_json IS NOT NULL
          AND chapters_json != '[]'
          AND chapters_json != 'null'
          AND chapters_json != ''";

$bindings = [];
if ($libraryFilter !== null) {
    $sql .= " AND library_id = ?";
    $bindings[] = $libraryFilter;
}

$sql .= " ORDER BY created_at ASC";

$rows = $db->query($sql, $bindings);
if (!is_array($rows)) {
    $rows = [];
}

echo 'Found ' . count($rows) . " candidate item(s) with chapters.\n";
if ($libraryFilter !== null) {
    echo "Library filter: {$libraryFilter}\n";
}
if ($limit !== null && $limit > 0) {
    echo "Generation limit: {$limit} item(s)\n";
}
echo str_repeat('-', 60) . "\n";

$updated = 0;
$skipped = 0;
$failed = 0;
$attempted = 0;

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

    $itemDir = $spriteBaseDir . '/' . $itemId;
    $spritePath = $itemDir . '/' . FfmpegRunner::SPRITE_FILENAME;
    $timelinePath = $itemDir . '/' . FfmpegRunner::TIMELINE_FILENAME;

    // Already complete on disk: never decode it again. Only restore the database
    // paths when the row has lost them.
    if (TrickplayArtefacts::isComplete($itemDir)) {
        $rowHasPaths = is_string($row['trickplay_sprite_path'] ?? null)
            && is_string($row['trickplay_timeline_path'] ?? null);
        if (!$rowHasPaths) {
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
            echo "  paths: {$path} (artefacts on disk, database paths restored)\n";
        } else {
            echo "  skip: {$path} (artefacts already on disk)\n";
        }
        $skipped++;
        continue;
    }

    // The limit counts items that are actually generated, so skipped items never
    // use it up.
    if ($limit !== null && $limit > 0 && $attempted >= $limit) {
        echo "  stop: generation limit reached\n";
        break;
    }
    $attempted++;

    $probe = $ffmpeg->probe($path);
    $rawDuration = $probe['format']['duration'] ?? null;
    $duration = is_numeric($rawDuration) ? (float) $rawDuration : 0.0;

    $result = $ffmpeg->generateTrickplayArtefacts(
        $path,
        $itemDir,
        TrickplayArtefacts::SPRITE_COUNT,
        TrickplayArtefacts::bifFrameCount($duration),
        TrickplayArtefacts::BIF_FRAME_WIDTH,
        ['item_id' => $itemId]
    );
    if ($result === null || $result['sprite'] === null || $result['timeline'] === null) {
        echo "  FAIL: {$path} (trickplay generation failed)\n";
        $failed++;
        continue;
    }

    $frames = $result['bif_frames'];

    try {
        $repo->updateMarkers($itemId, [
            'trickplay_sprite_path' => $result['sprite'],
            'trickplay_timeline_path' => $result['timeline'],
        ]);
    } catch (\Throwable $e) {
        foreach ($frames as $frame) {
            @unlink($frame);
        }
        echo "  FAIL: {$path} (store failed: {$e->getMessage()})\n";
        $failed++;
        continue;
    }

    // Best-effort, like the job: a BIF failure must not undo the sprite.
    $bifWritten = false;
    try {
        $bifWritten = TrickplayArtefacts::writeBif($frames, $result['bif_interval_ms'], $itemDir);
    } catch (\Throwable $e) {
        echo "  warn: {$path} (BIF not written: {$e->getMessage()})\n";
    } finally {
        foreach ($frames as $frame) {
            @unlink($frame);
        }
    }

    $updated++;
    echo '  ok:   ' . $path . ($bifWritten ? '' : ' (no BIF)') . "\n";
}

echo str_repeat('-', 60) . "\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Failed:  {$failed}\n";
echo "Done.\n";

exit($failed > 0 ? 1 : 0);
