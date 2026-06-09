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
 * A candidate is any top-level real-file row (`parent_id IS NULL`, path not a
 * synthetic container) in a series/video library. Its filename is RE-PARSED with
 * {@see EpisodeFilenameParser}, so it catches episodes the original scanner
 * missed (spaced "S01 E02", absolute "Show - 394"/"Show 125", etc.) whose stored
 * metadata has no season/episode — the parsed season/episode are written back
 * into the row's metadata. Rows that do not parse as episodes (real movies or
 * specials misfiled in a series library) are skipped. The movie library is
 * excluded entirely. Already-reparented episodes have a parent and are skipped,
 * so the script is safe to re-run (idempotent).
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
use Phlix\Media\Library\EpisodeFilenameParser;
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

// Candidate rows: every top-level real-file row in a series/video library
// (movies are excluded so the movie library is never touched). The filename is
// re-parsed per row with EpisodeFilenameParser, so this catches episodes the
// original scanner missed (spaced "S01 E02", absolute "Show - 394", etc.) whose
// stored metadata has no season/episode. Non-episodes (real movies/specials in
// a series library) parse to null and are skipped.
$sql = "SELECT mi.id, mi.library_id, mi.name, mi.type, mi.path, mi.metadata_json, l.type AS library_type
        FROM media_items mi
        JOIN libraries l ON l.id = mi.library_id
        WHERE mi.parent_id IS NULL
          AND l.type IN ('video', 'series')
          AND mi.path NOT LIKE 'series:%'
          AND mi.path NOT LIKE 'season:%'";
$bindings = [];
if ($libraryFilter !== null) {
    $sql .= " AND mi.library_id = ?";
    $bindings[] = $libraryFilter;
}

$rows = $db->query($sql, $bindings);
if (!is_array($rows)) {
    $rows = [];
}

echo 'Found ' . count($rows) . " candidate row(s) to examine.\n\n";

/** @var array<string, string> $containerCache synthetic path => container id */
$containerCache = [];
/** @var array<string, int> $perSeries series slug => episode count (reporting) */
$perSeries = [];
$created = ['series' => 0, 'season' => 0];
$reparented = 0;
$skipped = 0;

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

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = is_string($row['id'] ?? null) ? $row['id'] : null;
    $libraryId = is_string($row['library_id'] ?? null) ? $row['library_id'] : null;
    $path = is_string($row['path'] ?? null) ? $row['path'] : null;
    $libraryType = is_string($row['library_type'] ?? null) ? $row['library_type'] : '';
    if ($id === null || $libraryId === null || $path === null) {
        continue;
    }

    // Re-parse the filename; absolute numbering only honoured in series libraries.
    $parsed = EpisodeFilenameParser::parse(basename($path), $libraryType === 'series');
    if ($parsed === null) {
        $skipped++;
        continue;
    }

    $rawMeta = $row['metadata_json'] ?? '{}';
    $metadata = is_string($rawMeta) ? (json_decode($rawMeta, true) ?: []) : (is_array($rawMeta) ? $rawMeta : []);
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $seriesName = $parsed['series'] !== '' ? $parsed['series'] : 'Unknown Series';
    $season = $parsed['season'];

    // Merge parsed episode fields into the row's metadata so the API exposes
    // season_number/episode_number (these rows previously carried none).
    $metadata['name'] = $seriesName;
    $metadata['season'] = $season;
    $metadata['episode'] = $parsed['episode'];
    if ($parsed['episode_title'] !== null) {
        $metadata['episode_title'] = $parsed['episode_title'];
    }

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
        $repo->update($id, ['parent_id' => $seasonId, 'type' => 'episode', 'metadata_json' => $metadata]);
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
echo "Skipped (not recognised as episodes): {$skipped}\n";
echo $apply
    ? "\nDone. Reload the library in the app to see grouped shows.\n"
    : "\nDry-run only. Re-run with --apply to write these changes.\n";
