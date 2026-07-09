#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * P4-S1 backfill: populate item_similar for all media items that have complete
 * metadata (genres, actors, directors, rating, year).
 *
 * Usage:
 *   php scripts/backfill-similar.php                 # dry-run
 *   php scripts/backfill-similar.php --dry-run        # explicit dry-run
 *   php scripts/backfill-similar.php --execute       # real write
 *   php scripts/backfill-similar.php --limit=500     # cap rows this run
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\SimilarityService;

$dryRun = true;
$limit = null;

foreach ($argv as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') {
        $dryRun = true;
    } elseif ($arg === '--execute') {
        $dryRun = false;
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    }
}

if ($dryRun) {
    echo "=== DRY RUN (no changes will be written) ===\n";
}

ConnectionPool::init(__DIR__ . '/../config/database.php');
$db = ConnectionPool::getConnection('mysql');

$itemRepository = new ItemRepository($db, null);
$similarityService = new SimilarityService($db, $itemRepository);

// Find items with complete metadata (genres, actors, directors, rating, year).
$limitClause = $limit !== null ? 'LIMIT ' . (int) $limit : '';
$query = "
    SELECT id
    FROM media_items
    WHERE JSON_LENGTH(metadata_json, '\$.genres') > 0
      AND JSON_LENGTH(metadata_json, '\$.actors') > 0
      AND JSON_LENGTH(metadata_json, '\$.directors') > 0
      AND JSON_TYPE(JSON_EXTRACT(metadata_json, '\$.rating')) = 'DOUBLE'
      AND JSON_TYPE(JSON_EXTRACT(metadata_json, '\$.year')) = 'INT'
    {$limitClause}
";

// Fetch candidates in batches.
$batchSize = 100;
$processed = 0;
$skipped = 0;
$failed = 0;
$offset = 0;

echo "Scanning for items with complete metadata...\n";

while (true) {
    $rows = $db->query(
        "SELECT id
         FROM media_items
         WHERE JSON_LENGTH(metadata_json, '\$.genres') > 0
           AND JSON_LENGTH(metadata_json, '\$.actors') > 0
           AND JSON_LENGTH(metadata_json, '\$.directors') > 0
           AND JSON_TYPE(JSON_EXTRACT(metadata_json, '\$.rating')) = 'DOUBLE'
           AND JSON_TYPE(JSON_EXTRACT(metadata_json, '\$.year')) = 'INT'
         LIMIT {$batchSize} OFFSET {$offset}",
        []
    );

    if (!is_array($rows) || $rows === []) {
        break;
    }

    /** @var array<int, array<string, mixed>> $rows */
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $itemId = is_string($row['id'] ?? null) ? $row['id'] : '';
        if ($itemId === '') {
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "[DRY-RUN] Would compute similarity for item {$itemId}\n";
        } else {
            try {
                $similarityService->computeSimilarForItem($itemId);
                echo "Computed similarity for item {$itemId}\n";
            } catch (\Throwable $e) {
                echo "FAILED for item {$itemId}: {$e->getMessage()}\n";
                $failed++;
            }
        }

        $processed++;

        if ($processed % 50 === 0) {
            echo "Progress: {$processed} items processed, {$failed} failed\n";
        }
    }

    if (count($rows) < $batchSize) {
        break;
    }

    $offset += $batchSize;
}

echo "\nDone. Processed: {$processed}, Skipped: {$skipped}, Failed: {$failed}\n";

if ($dryRun) {
    echo "Re-run with --execute to write changes.\n";
}

exit($failed > 0 ? 1 : 0);
