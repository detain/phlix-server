<?php

declare(strict_types=1);

/*
 * One-off backfill: reorganise already-scanned episode rows into a
 * series → season → episode hierarchy IN PLACE, without deleting anything.
 *
 * Why: before the scanner learned to build the tree (phlix-server #244), every
 * episode of a show was persisted as its own top-level row (`type='movie'`, or
 * `type='series'` in a series library) with `parent_id = NULL` — so a show like
 * "24" rendered as dozens of separate "series" entries. This script finds those
 * rows and slots each under a find-or-created series + season parent, preserving
 * every row's existing `metadata_json` (TMDB/IMDb matches etc.) — unlike a full
 * rescan, which purges and re-creates.
 *
 * A candidate is any top-level row (`parent_id IS NULL`) in a video-content
 * library (video/series/movie) whose metadata carries BOTH a season and an
 * episode number, and whose path is a real file (not an existing synthetic
 * container). Real series/season containers carry no episode number and are
 * skipped; already-reparented episodes have a parent and are skipped — so the
 * script is safe to re-run (idempotent).
 *
 * Container paths come from {@see SeriesContainerNaming}, the SAME scheme the
 * live scanner uses, so a later scan resolves to these rows rather than making
 * duplicates.
 *
 * Usage:
 *   php scripts/backfill-series-hierarchy.php                 # dry-run (default)
 *   php scripts/backfill-series-hierarchy.php --apply         # write changes
 *   php scripts/backfill-series-hierarchy.php --library=<id>  # scope to one library
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\SeriesContainerNaming;

$apply = in_array('--apply', $argv, true);
$libraryFilter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--library=')) {
        $libraryFilter = substr($arg, strlen('--library='));
    }
}

ConnectionPool::init(__DIR__ . '/../config/database.php');
$db = ConnectionPool::getConnection('mysql');
$repo = new ItemRepository($db);

echo $apply ? "MODE: APPLY (writing changes)\n" : "MODE: DRY-RUN (no changes; pass --apply to write)\n";
if ($libraryFilter !== null) {
    echo "Library filter: {$libraryFilter}\n";
}
echo str_repeat('-', 60) . "\n";

// Candidate episodes: top-level rows in a video-content library carrying both a
// season and an episode number, excluding synthetic containers.
$sql = "SELECT mi.id, mi.library_id, mi.name, mi.type, mi.path, mi.metadata_json
        FROM media_items mi
        JOIN libraries l ON l.id = mi.library_id
        WHERE mi.parent_id IS NULL
          AND l.type IN ('video', 'series', 'movie')
          AND mi.path NOT LIKE 'series:%'
          AND mi.path NOT LIKE 'season:%'
          AND JSON_EXTRACT(mi.metadata_json, '$.season') IS NOT NULL
          AND JSON_EXTRACT(mi.metadata_json, '$.episode') IS NOT NULL";
$bindings = [];
if ($libraryFilter !== null) {
    $sql .= " AND mi.library_id = ?";
    $bindings[] = $libraryFilter;
}

$rows = $db->query($sql, $bindings);
if (!is_array($rows)) {
    $rows = [];
}

echo 'Found ' . count($rows) . " candidate episode row(s).\n\n";

/** @var array<string, string> $containerCache synthetic path => container id */
$containerCache = [];
/** @var array<string, int> $perSeries series slug => episode count (reporting) */
$perSeries = [];
$created = ['series' => 0, 'season' => 0];
$reparented = 0;

/**
 * Find or create a synthetic container row, mirroring MediaScanner.
 *
 * @param array<string, mixed> $metadata
 */
$findOrCreate = static function (
    string $libraryId,
    string $type,
    string $name,
    string $path,
    ?string $parentId,
    array $metadata
) use (
    $repo,
    &$containerCache,
    &$created,
    $apply
): string {
    if (isset($containerCache[$path])) {
        return $containerCache[$path];
    }
    $existing = $repo->findByPath($path);
    if (is_array($existing) && isset($existing['id']) && is_string($existing['id'])) {
        return $containerCache[$path] = $existing['id'];
    }
    $created[$type] = ($created[$type] ?? 0) + 1;
    if (!$apply) {
        // Synthesise a stable pseudo-id for dry-run so dependent rows line up.
        return $containerCache[$path] = 'DRYRUN-' . $type . '-' . md5($path);
    }
    $id = $repo->create([
        'library_id' => $libraryId,
        'parent_id' => $parentId,
        'name' => $name,
        'type' => $type,
        'path' => $path,
        'metadata_json' => $metadata,
    ]);
    return $containerCache[$path] = $id;
};

/** Light series-title cleanup matching the scanner ("24." / "24 -" => "24"). */
$cleanTitle = static function (string $raw): string {
    $t = (string) preg_replace('/[._]+/', ' ', $raw);
    $t = (string) preg_replace('/\s+/', ' ', $t);
    $t = trim($t, " -._\t\n");
    return $t !== '' ? $t : trim($raw);
};

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = is_string($row['id'] ?? null) ? $row['id'] : null;
    $libraryId = is_string($row['library_id'] ?? null) ? $row['library_id'] : null;
    if ($id === null || $libraryId === null) {
        continue;
    }
    $rawMeta = $row['metadata_json'] ?? '{}';
    $metadata = is_string($rawMeta) ? (json_decode($rawMeta, true) ?: []) : (is_array($rawMeta) ? $rawMeta : []);

    $season = isset($metadata['season']) && is_numeric($metadata['season']) ? (int) $metadata['season'] : 0;
    $seriesName = is_string($metadata['name'] ?? null) && $metadata['name'] !== ''
        ? $cleanTitle($metadata['name'])
        : 'Unknown Series';

    $seriesId = $findOrCreate(
        $libraryId,
        'series',
        $seriesName,
        SeriesContainerNaming::seriesPath($libraryId, $seriesName),
        null,
        ['name' => $seriesName]
    );
    $seasonLabel = SeriesContainerNaming::seasonLabel($season);
    $seasonId = $findOrCreate(
        $libraryId,
        'season',
        $seasonLabel,
        SeriesContainerNaming::seasonPath($libraryId, $seriesName, $season),
        $seriesId,
        ['name' => $seasonLabel, 'season' => $season]
    );

    if ($apply) {
        $repo->update($id, ['parent_id' => $seasonId, 'type' => 'episode']);
    }
    $reparented++;
    $key = SeriesContainerNaming::slug($seriesName);
    $perSeries[$key] = ($perSeries[$key] ?? 0) + 1;
}

echo "Per-series episode counts:\n";
ksort($perSeries);
foreach ($perSeries as $slug => $count) {
    echo "  - {$slug}: {$count} episode(s)\n";
}
echo str_repeat('-', 60) . "\n";
echo "Series containers " . ($apply ? 'created' : 'to create') . ": {$created['series']}\n";
echo "Season containers " . ($apply ? 'created' : 'to create') . ": {$created['season']}\n";
echo "Episodes " . ($apply ? 'reparented' : 'to reparent') . ": {$reparented}\n";
echo $apply
    ? "\nDone. Reload the library in the app to see grouped shows.\n"
    : "\nDry-run only. Re-run with --apply to write these changes.\n";
