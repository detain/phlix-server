<?php

/**
 * path_hash finalizer: merge duplicate paths, then add the unique index.
 *
 * ⚠ SINCE MIGRATION 096, THIS SCRIPT IS NO LONGER REQUIRED ON A CLEAN INSTALL.
 * `migrations/096_path_hash_unique_index.sql` adds the
 * `UNIQUE INDEX idx_media_items_library_path_hash (library_id, path_hash)` as
 * part of the ordinary migration chain, idempotently and only when the table is
 * free of duplicates. Before 096, migration 072 deferred the index to this
 * script and 087 DROPped it, so a database built by `run-migrations.php` alone
 * carried NO path-dedupe constraint at all unless an operator remembered to run
 * this file by hand (S152).
 *
 * WHAT THIS SCRIPT STILL OWNS: de-duplication — the one part a `.sql` migration
 * cannot do. Merging a duplicate group means choosing a keeper by how much user
 * data it carries ({@see PathDeduper::scoreItem()}) and repointing twenty
 * referencing tables collision-safely; that logic lives once, in PHP, shared
 * with the `media:dedupe-paths` CLI command. Re-implementing it in SQL would
 * create a second source of truth for the keeper rule.
 *
 * So run this ONLY when you have duplicates to merge. Migration 096 tells you
 * so explicitly — on a dirty table it refuses to add the index and fails with
 *
 *     Unknown column 'media_items duplicate paths: run php
 *     migrations/cleanup_072.php' in 'field list'
 *
 * After this script has merged them, the next `run-migrations.php` adds the
 * index (096 is left unrecorded until it succeeds, so it retries by itself).
 *
 *     php migrations/cleanup_072.php
 *
 * The scope comes from {@see PathDeduper::DEDUPED_TYPES}, so the script always
 * follows whatever the current code and the generated column agree on. RE-RUN
 * IT after any future migration that widens `path_hash`'s scope: such a
 * migration must drop the unique index before rewriting the column (see 087),
 * which can hand a hash to rows that previously hashed to NULL and so expose
 * duplicates that were invisible under the old scope.
 *
 * The two steps:
 *
 *   1. Merge every duplicate (library_id, path) group using {@see PathDeduper}
 *      — the SAME scoring, repoint and delete logic the `media:dedupe-paths`
 *      CLI command uses, so there is a single source of truth. The keeper is the
 *      row with the most user data (lowest id breaks ties); every referencing
 *      row is repointed onto it collision-safely, then the losers are deleted.
 *   2. Add the `UNIQUE INDEX (library_id, path_hash)` if it is not already
 *      there. Retained deliberately: it makes the script complete on its own for
 *      an operator part-way through an upgrade, or on a DB whose chain has not
 *      reached 096 yet. On a DB where 096 already ran, this step reports
 *      "already present" and does nothing.
 *
 * Safe to re-run: step 1 is a no-op once no duplicates remain, and step 2 treats
 * an already-present index as success.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\PathDeduper;

echo "=== Migration 072: duplicate-path cleanup + unique index ===\n\n";

ConnectionPool::init(__DIR__ . '/../config/database.php');
$db = ConnectionPool::getConnection('mysql');
$deduper = new PathDeduper($db);

$maxIterations = 1000; // safety backstop against a pathological non-converging loop
$iterations = 0;
$totalGroups = 0;
$totalDeleted = 0;

do {
    $groups = $deduper->findDuplicateGroups();
    if ($groups === []) {
        break;
    }

    $iterations++;
    $deletedThisIteration = 0;

    foreach ($groups as $group) {
        $totalGroups++;

        // Score each item; keep the highest score, lowest id on a tie.
        $scored = [];
        foreach ($group['items'] as $item) {
            $scored[] = ['item' => $item, 'score' => $deduper->scoreItem($item['id'])];
        }
        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strcmp($a['item']['id'], $b['item']['id']);
        });

        $keeper = $scored[0];
        $losers = array_slice($scored, 1);

        echo sprintf(
            "[%s] %s\n  KEEP:  %s (score=%d)\n",
            $group['library_name'],
            shortenPath($group['path']),
            $keeper['item']['id'],
            $keeper['score']
        );

        try {
            $deduper->beginTrans();
            foreach ($losers as $s) {
                $deduper->repointReferencingTables($s['item']['id'], $keeper['item']['id']);
                $deduper->deleteItem($s['item']['id']);
                echo sprintf("  DELETE: %s (score=%d)\n", $s['item']['id'], $s['score']);
                $deletedThisIteration++;
                $totalDeleted++;
            }
            $deduper->commit();
        } catch (\Throwable $e) {
            $deduper->rollback();
            echo sprintf("  SKIP (rolled back): %s\n", $e->getMessage());
        }
    }

    echo sprintf(
        "Iteration %d: deleted %d row(s) from %d group(s).\n\n",
        $iterations,
        $deletedThisIteration,
        count($groups)
    );

    // No progress despite duplicates remaining → stop rather than spin forever.
    if ($deletedThisIteration === 0) {
        echo "No further progress possible; stopping.\n";
        break;
    }
} while ($iterations < $maxIterations);

echo "=== Cleanup complete ===\n";
echo sprintf("Groups processed: %d\n", $totalGroups);
echo sprintf("Rows deleted:     %d\n\n", $totalDeleted);

echo "Adding unique index idx_media_items_library_path_hash (library_id, path_hash)...\n";
try {
    $db->query(
        'ALTER TABLE media_items
            ADD UNIQUE INDEX idx_media_items_library_path_hash (library_id, path_hash)'
    );
    echo "Unique index created.\n";
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Duplicate key name')) {
        echo "Unique index already present — nothing to do.\n";
    } elseif (str_contains($msg, 'Duplicate entry')) {
        echo "FAILED: duplicate paths still remain (a type outside the deduper's scope?).\n";
        echo "        {$msg}\n";
        exit(1);
    } else {
        echo "FAILED: {$msg}\n";
        exit(1);
    }
}

/**
 * Shorten a path for display.
 */
function shortenPath(string $path): string
{
    return strlen($path) > 60 ? substr($path, 0, 57) . '...' : $path;
}
