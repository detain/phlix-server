<?php

declare(strict_types=1);

/*
 * Backfill trickplay artefacts (sprite sheet, timeline JSON and Roku BIF) for
 * already-indexed media items.
 *
 * Candidates are time-based items (video/movie/episode) that have chapters.
 * The database path columns are not used to decide anything: an item can have
 * a sprite and still lack its BIF, and only the disk can say which artefacts
 * exist. An item whose three artefacts are all on disk, from one generation, is
 * skipped with no decode (its database paths are filled in if they are missing).
 * Anything else is generated in ONE ffmpeg pass that produces the sprite, timeline
 * and BIF.
 *
 * Re-running is safe: complete items are skipped, and a half-finished item is
 * regenerated rather than trusted. Sprite generation is read-only and never
 * modifies the source file.
 *
 * Each item is generated under its .trickplay.lock (non-blocking), so the in-app
 * media-asset worker and this script never write the same item at once. The lock
 * is released on every path, including when generation or the logger throws.
 *
 * An item whose generation failed, or threw, gets generation-failed.json in its
 * directory and is skipped by later runs. When only the BIF could not be written, the
 * sprite and timeline stay published and bif-failed.json is recorded, and that item is
 * skipped by later runs too. Transient failures are included in these markers: a full disk
 * or an I/O error during a BIF write is recorded the same way as a deterministic failure, so
 * the item stays skipped until it is retried. --retry-failed is the way to retry them: it
 * ignores the markers for that run, items are regenerated, and their markers are cleared on
 * success. Deleting a marker by hand also makes its item eligible again. A skipped item does
 * not count toward --limit, so a deterministic failure cannot starve the items after it.
 *
 * Usage:
 *   php scripts/backfill-trickplay.php                    # all libraries
 *   php scripts/backfill-trickplay.php --library=<id>   # scope to one library
 *   php scripts/backfill-trickplay.php --limit=500       # cap items generated this run
 *   php scripts/backfill-trickplay.php --retry-failed    # also regenerate items skipped by a marker
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Streaming\Trickplay\TrickplayArtefacts;
use Phlix\Media\Transcoding\FfmpegRunner;

$libraryFilter = null;
$limit = null;
$retryFailed = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--library=')) {
        $libraryFilter = substr($arg, strlen('--library='));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    } elseif ($arg === '--retry-failed') {
        $retryFailed = true;
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
        TrickplayArtefacts::clearFailedMarker($itemDir);
        TrickplayArtefacts::clearBifFailedMarker($itemDir);
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

    // An item whose earlier generation failed, or whose BIF failed, is not decoded again on
    // every run. The marker records why; deleting it, or running with --retry-failed, makes
    // the item eligible again. Skipping here, before the limit check and before $attempted,
    // keeps a deterministic failure from using up --limit ahead of the items after it.
    $skipMarker = TrickplayArtefacts::backfillSkipMarker($itemDir, !$retryFailed);
    if ($skipMarker !== null) {
        echo "  skip: {$path} (an earlier trickplay generation failed; delete "
            . $itemDir . '/' . $skipMarker . " to retry, or run with --retry-failed)\n";
        $skipped++;
        continue;
    }

    // The limit counts items that are actually generated, so skipped items never
    // use it up.
    if ($limit !== null && $limit > 0 && $attempted >= $limit) {
        echo "  stop: generation limit reached\n";
        break;
    }

    // One pass per item at a time: the in-app media-asset worker writes the same
    // directory. A held lock means the other pass is producing this item, so skip
    // it without waiting.
    $lock = TrickplayArtefacts::tryLock($itemDir);
    if ($lock === null) {
        echo "  skip: {$path} (another trickplay pass holds this item)\n";
        $skipped++;
        continue;
    }
    $attempted++;

    // Generation runs under the lock. A throw is captured here and handled after the
    // lock is released, so the logger (which can also throw) never runs while the
    // item is held and never ends the run.
    $thrown = null;
    $result = null;
    try {
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
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    if ($thrown !== null) {
        // Count, record the marker, and release the lock, then log. The marker makes a
        // deterministic throw skip the item on later runs, so it cannot starve the items
        // after it. The logger may throw; the counter, marker and lock must already be
        // settled when it does.
        $failed++;
        try {
            TrickplayArtefacts::markFailed($itemDir, 'trickplay generation threw: ' . $thrown->getMessage());
        } catch (\Throwable $markError) {
            echo "  warn: could not record failure marker for {$path} ({$markError->getMessage()})\n";
        }
        TrickplayArtefacts::unlock($lock);
        echo "  FAIL: {$path} (trickplay generation threw: {$thrown->getMessage()})\n";
        try {
            LoggerFactory::get(LogChannels::TRANSCODING)->error('Trickplay backfill item threw; continuing', [
                'item_id' => $itemId,
                'video' => $path,
                'exception' => $thrown->getMessage(),
            ]);
        } catch (\Throwable $logError) {
            echo "  warn: logger failed for {$path} ({$logError->getMessage()})\n";
        }
        continue;
    }

    // Everything from here runs under the lock. The finally releases it on every path
    // below, including an unexpected error, before the next item starts.
    try {
        if ($result === null || $result['sprite'] === null || $result['timeline'] === null) {
            echo "  FAIL: {$path} (trickplay generation failed; marked, see the ffmpeg log)\n";
            TrickplayArtefacts::markFailed($itemDir, 'trickplay pass produced no sprite or timeline');
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

        // The sprite and timeline are published, so an earlier generation failure no longer
        // applies. Clearing it here means a --retry-failed run leaves no stale marker behind.
        TrickplayArtefacts::clearFailedMarker($itemDir);

        // Best-effort, like the job: a BIF failure must not undo the sprite.
        $bifWritten = false;
        try {
            $bifWritten = TrickplayArtefacts::writeBif(
                $frames,
                $result['bif_interval_ms'],
                $itemDir,
                $result['generation']
            );
        } catch (\Throwable $e) {
            echo "  warn: {$path} (BIF not written: {$e->getMessage()})\n";
        } finally {
            foreach ($frames as $frame) {
                @unlink($frame);
            }
        }

        if ($bifWritten) {
            TrickplayArtefacts::clearBifFailedMarker($itemDir);
        } else {
            // The sprite and timeline stay published. Only the BIF failure is recorded, under
            // its own marker. That marker skips the item on later runs, so a deterministic BIF
            // failure is not retried on every run. Delete the marker to try the BIF again.
            TrickplayArtefacts::markBifFailed($itemDir, 'BIF not written; sprite and timeline are published');
        }
        $updated++;
        echo '  ok:   ' . $path . ($bifWritten ? '' : ' (no BIF)') . "\n";
    } finally {
        TrickplayArtefacts::unlock($lock);
    }
}

echo str_repeat('-', 60) . "\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Failed:  {$failed}\n";
echo "Done.\n";

exit($failed > 0 ? 1 : 0);
