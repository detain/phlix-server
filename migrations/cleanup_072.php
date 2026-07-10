<?php

/**
 * Migration 072 finalizer: merge duplicate paths, then add the unique index.
 *
 * Run this ONCE after migration 072 (which adds the `path_hash` generated
 * column) has been applied:
 *
 *     php migrations/cleanup_072.php
 *
 * It performs the one-time work the auto-run `.sql` migration deliberately does
 * NOT do, because it can fail on a DB that still holds duplicates:
 *
 *   1. Merge every duplicate (library_id, path) group using {@see PathDeduper}
 *      — the SAME scoring, repoint and delete logic the `media:dedupe-paths`
 *      CLI command uses, so there is a single source of truth. The keeper is the
 *      row with the most user data (lowest id breaks ties); every referencing
 *      row is repointed onto it collision-safely, then the losers are deleted.
 *   2. Add the `UNIQUE INDEX (library_id, path_hash)` that makes a future
 *      double-insert impossible.
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
