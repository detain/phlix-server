<?php

declare(strict_types=1);

/*
 * Idempotent backfill: populate the materialized `sort_title` and
 * `content_rating` columns (migration 050) for media_items rows that predate
 * them (Stream Quality/ABR performance step S7).
 *
 * Why: listings now ORDER BY the indexed `sort_title` column instead of the
 * per-row SortTitle::sqlExpression() CASE, and rating filters/sorts read the
 * indexed `content_rating` column instead of a JSON extraction. Migration 050
 * backfills both columns inline on deploy, but this CLI exists to (re)run the
 * backfill on demand, scope it to one library, cap a batch, or self-heal a row
 * the inline UPDATE skipped — using the SAME PHP derivation the live write path
 * uses (SortTitle::from() and ItemRepository::extractContentRating()), so the
 * offline and live values never drift.
 *
 * Genres are NOT this script's concern: this script only derives sort_title/
 * content_rating (via SortTitle::from()/ItemRepository::extractContentRating()).
 * The `media_item_genres` join table (migration 051, which replaced migration
 * 050's multi-valued genre index after it reproduced real InnoDB purge-thread
 * errors under sustained churn) has its own idempotent SQL backfill inline in
 * that migration file — it does not need a PHP CLI equivalent, since
 * ItemRepository::syncGenreRows() keeps it in sync on every subsequent
 * create()/update() going forward.
 *
 * Candidates are rows still missing a materialized value: `sort_title IS NULL`,
 * or `content_rating IS NULL` while metadata_json actually carries a rating (so a
 * genuinely un-rated row is never re-selected every run). Re-running is safe: a
 * row already carrying the derived value is left untouched and counted as a skip.
 * A single row whose derivation/persist throws is reported FAILED (a distinct
 * bucket) and never aborts the run; the process exits non-zero if any row failed
 * so automation can detect a partial run and safely re-invoke it.
 *
 * Usage:
 *   php scripts/backfill-sort-metadata.php                 # every library
 *   php scripts/backfill-sort-metadata.php --library=<id>  # scope to one library
 *   php scripts/backfill-sort-metadata.php --limit=500     # cap rows this run
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\SortTitle;

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

// Candidate rows: a NULL sort_title, or a NULL content_rating while the blob
// still carries a rating. The escaped '\$.rating' JSON path matches the
// codebase convention for double-quoted SQL (see ItemRepository JSON calls).
$sql = "SELECT id, name, metadata_json, sort_title, content_rating
        FROM media_items
        WHERE sort_title IS NULL
           OR (content_rating IS NULL AND JSON_EXTRACT(metadata_json, '\$.rating') IS NOT NULL)";
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

echo 'Found ' . count($rows) . " candidate item(s) missing sort_title/content_rating.\n";
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
    $id = is_string($row['id'] ?? null) ? $row['id'] : null;
    if ($id === null) {
        $failed++;
        continue;
    }
    $name = is_string($row['name'] ?? null) ? $row['name'] : '';

    // Derive from the SAME helpers the live write path uses so the offline and
    // live columns can never drift.
    $sortTitle = SortTitle::from($name);
    $contentRating = ItemRepository::extractContentRating($row['metadata_json'] ?? null);

    $currentSort = is_string($row['sort_title'] ?? null) ? $row['sort_title'] : null;
    $currentRating = is_string($row['content_rating'] ?? null) ? $row['content_rating'] : null;

    // Nothing to change (row already consistent) — count as a skip.
    if ($currentSort === $sortTitle && $currentRating === $contentRating) {
        $skipped++;
        continue;
    }

    try {
        $db->query(
            "UPDATE media_items SET sort_title = ?, content_rating = ? WHERE id = ?",
            [$sortTitle, $contentRating, $id]
        );
        $updated++;
        echo "  ok:   {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "  FAIL: {$name} ({$e->getMessage()}; reselected on next run)\n";
    }
}

echo str_repeat('-', 60) . "\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Failed:  {$failed}\n";
echo "Done.\n";

// Non-zero exit when any row hard-failed, so an operator or wrapper (systemd,
// cron, CI) sees the run needs another pass. A full success or all-skipped run
// exits 0.
exit($failed > 0 ? 1 : 0);
