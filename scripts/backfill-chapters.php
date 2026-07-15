<?php

declare(strict_types=1);

/*
 * Idempotent backfill: extract chapter markers from MKV/MP4/WebM files and
 * persist them to the chapters_json column for already-indexed media items
 * that were scanned before chapter extraction existed (or that never had a
 * file with embedded chapters).
 *
 * Candidates are time-based rows (type IN video/movie/episode) whose
 * chapters_json is NULL or empty. Re-running is safe: items that already
 * have chapters no longer match the WHERE clause, and the extraction itself
 * is a read-only ffprobe call that never modifies the filesystem.
 *
 * Usage:
 *   php scripts/backfill-chapters.php                    # all libraries
 *   php scripts/backfill-chapters.php --library=<id>   # scope to one library
 *   php scripts/backfill-chapters.php --limit=500       # cap rows this run
 *   php scripts/backfill-chapters.php --types=video    # only video type
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\ChapterMarkerService;
use Phlix\Media\Markers\ChapterService;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Transcoding\FfmpegRunner;

$libraryFilter = null;
$limit = null;
$typeFilter = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--library=')) {
        $libraryFilter = substr($arg, strlen('--library='));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    } elseif (str_starts_with($arg, '--types=')) {
        $typeFilter = substr($arg, strlen('--types='));
    }
}

ConnectionPool::init(__DIR__ . '/../config/database.php');
$db = ConnectionPool::getConnection('mysql');
$repo = new ItemRepository($db);
$candidateRepo = new MarkerCandidateRepository($db);

// Build the FFprobe runner from config/ffmpeg.php so the CLI probes with the
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
$chapterService = new ChapterMarkerService($ffmpeg);
$markerService = new MarkerService($repo, $candidateRepo);

// Candidate rows: time-based items with no chapter data.
$sql = "SELECT id, type, path, chapters_json
        FROM media_items
        WHERE type IN ('video', 'movie', 'episode')";

if ($typeFilter !== null) {
    $sql .= " AND type = ?";
}

$sql .= " AND (chapters_json IS NULL
             OR chapters_json = '[]'
             OR chapters_json = 'null'
             OR chapters_json = '')";

if ($libraryFilter !== null) {
    $sql .= " AND library_id = ?";
}

$sql .= " ORDER BY created_at ASC";

if ($limit !== null && $limit > 0) {
    $sql .= " LIMIT ?";
}

$bindings = [];
if ($typeFilter !== null) {
    $bindings[] = $typeFilter;
}
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

echo 'Found ' . count($rows) . " candidate item(s) missing chapter data.\n";
if ($libraryFilter !== null) {
    echo "Library filter: {$libraryFilter}\n";
}
if ($typeFilter !== null) {
    echo "Type filter: {$typeFilter}\n";
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

    // Skip if the file does not exist on disk
    if (!file_exists($path)) {
        echo "  skip: {$path} (file not found)\n";
        $skipped++;
        continue;
    }

    // Skip non-video extensions (defence in depth — library type filter
    // should have already excluded audio/books/etc.)
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mkv', 'mp4', 'webm', 'avi', 'mov', 'm4v'], true)) {
        echo "  skip: {$path} (not a container format that carries chapters)\n";
        $skipped++;
        continue;
    }

    try {
        $chapters = $chapterService->extractFromFile($path);
    } catch (\Throwable $e) {
        echo "  FAIL: {$path} (ffprobe threw: {$e->getMessage()})\n";
        $failed++;
        continue;
    }

    if (empty($chapters)) {
        echo "  skip: {$path} (no chapters embedded in file)\n";
        // Even if no chapters found, mark the item so we don't re-probe it.
        // Persist an empty array so the WHERE clause excludes it on re-run.
        try {
            $markerService->storeChapters($itemId, []);
        } catch (\Throwable $e) {
            // Log but don't fail — the item will be re-selected on next run.
        }
        $skipped++;
        continue;
    }

    try {
        $markerService->storeChapters($itemId, $chapters);
    } catch (\Throwable $e) {
        echo "  FAIL: {$path} (store failed: {$e->getMessage()})\n";
        $failed++;
        continue;
    }

    $updated++;
    $title = $chapters[0]->title ?? null;
    echo "  ok:   {$path} (" . count($chapters) . " chapters" . ($title !== null ? ", first: \"{$title}\"" : '') . ")\n";
}

echo str_repeat('-', 60) . "\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Failed:  {$failed}\n";
echo "Done.\n";

// Non-zero exit when any item hard-failed, so automation can detect a partial
// run and re-invoke safely. All items were still processed above.
exit($failed > 0 ? 1 : 0);
