<?php

/**
 * playback_state finalizer: merge duplicate rows, then add the unique key.
 *
 * WHAT THIS SCRIPT OWNS, as of S156: DE-DUPLICATION, and only de-duplication.
 *
 * `migrations/097_playback_state_unique_key.sql` now adds
 * `uq_playback_state_session_media (session_id, media_item_id)` as part of the
 * migration chain, so a database built by `scripts/run-migrations.php` alone
 * finally has the constraint. Before 097 the key existed ONLY because somebody
 * ran this script by hand — migration 090 carries no executable statement at
 * all, and nothing in `run-migrations.php` / `bin/phlix migrate` /
 * `scripts/install.sh` / `docker/docker-entrypoint.sh` calls this file.
 *
 * You only need to run this on a database that still holds duplicate rows, and
 * migration 097 will tell you so by name: it fails, deliberately, with
 *
 *     Unknown column 'playback_state duplicates: run php
 *     migrations/cleanup_090.php' in 'field list'
 *
 * and alters nothing until you have. Merging is not something 097 can safely do
 * itself: choosing which duplicate survives decides where the user resumes, and
 * that rule lives once, in {@see \Phlix\Session\PlaybackStateDeduper}, rather
 * than being re-implemented as a second source of truth in SQL.
 *
 * The `addUniqueKey()` step below stays — idempotent — so this script remains
 * usable stand-alone by an operator mid-upgrade who has not yet run 097.
 *
 * Run this ONCE after migration 090 (which documents the
 * `(session_id, media_item_id)` unique key but deliberately does NOT add it —
 * an inline `ADD UNIQUE KEY` fails with error 1062 on any DB that still holds
 * duplicate rows, which is every production DB affected by the bug) has been
 * applied:
 *
 *     php migrations/cleanup_090.php
 *
 * It performs the one-time work the auto-run `.sql` migration cannot safely do:
 *
 *   1. Merge every duplicate `(session_id, media_item_id)` group using
 *      {@see \Phlix\Session\PlaybackStateDeduper} — keep the row with the
 *      greatest `updated_at` (ties broken by the greatest `id`), delete the
 *      rest — in bounded batches so a large, bloated `playback_state` table is
 *      drained without one table-wide DELETE that would lock it / blow memory.
 *   2. Add the `UNIQUE KEY uq_playback_state_session_media (session_id,
 *      media_item_id)` that makes `PlaybackController::reportProgress()` /
 *      `StreamManager::persistStreamState()` update-not-insert on every tick.
 *
 * Safe to re-run: step 1 is a no-op once no duplicates remain, and step 2 treats
 * an already-present key as success. This mirrors `migrations/cleanup_072.php`
 * (the `media_items` path_hash finalizer) — the same dedupe-then-constrain
 * pattern, one table simpler (playback_state is a leaf, so losers are just
 * deleted, with no reference repointing).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Session\PlaybackStateDeduper;

echo "=== Migration 090: playback_state duplicate cleanup + unique key ===\n\n";

ConnectionPool::init(__DIR__ . '/../config/database.php');
$db = ConnectionPool::getConnection('mysql');
$deduper = new PlaybackStateDeduper($db);

echo "Merging duplicate (session_id, media_item_id) groups...\n";
$result = $deduper->dedupeAll();
echo sprintf(
    "  groups resolved: %d\n  rows deleted:    %d\n  batches:         %d\n",
    $result['groups'],
    $result['deleted'],
    $result['iterations']
);
if ($result['skipped'] > 0) {
    echo sprintf("  groups skipped:  %d (delete threw — see logs; re-run to retry)\n", $result['skipped']);
}
echo "\n";

echo 'Adding unique key ' . PlaybackStateDeduper::UNIQUE_KEY_NAME . " (session_id, media_item_id)...\n";
try {
    $created = $deduper->addUniqueKey();
    echo $created
        ? "Unique key created.\n"
        : "Unique key already present — nothing to do.\n";
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Duplicate entry')) {
        echo "FAILED: duplicate (session_id, media_item_id) rows still remain — re-run this script.\n";
        echo "        (a concurrent progress tick may have inserted one mid-run.)\n";
        echo "        {$msg}\n";
        exit(1);
    }
    echo "FAILED: {$msg}\n";
    exit(1);
}

echo "\n=== Cleanup complete ===\n";
echo sprintf("Groups processed: %d\n", $result['groups']);
echo sprintf("Rows deleted:     %d\n", $result['deleted']);
