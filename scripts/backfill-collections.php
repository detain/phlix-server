#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * P4-S3 backfill: sync TMDB box-set collection membership for all movies
 * that have a tmdb_id in their metadata but no entry in media_collection_members.
 *
 * Usage:
 *   php scripts/backfill-collections.php                 # dry-run
 *   php scripts/backfill-collections.php --dry-run        # explicit dry-run
 *   php scripts/backfill-collections.php --execute       # real write
 *   php scripts/backfill-collections.php --limit=500     # cap rows this run
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\CollectionService;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\TmdbProvider;

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

// Get TMDB API key from config/environment
$tmdbApiKey = '';
$tmdbConfigFile = __DIR__ . '/../config/tmdb.php';
if (file_exists($tmdbConfigFile)) {
    $tmdbConfig = require $tmdbConfigFile;
    if (is_array($tmdbConfig) && isset($tmdbConfig['api_key']) && is_string($tmdbConfig['api_key'])) {
        $tmdbApiKey = $tmdbConfig['api_key'];
    }
}
if ($tmdbApiKey === '') {
    $envKey = getenv('TMDB_API_KEY');
    if ($envKey !== false && $envKey !== '') {
        $tmdbApiKey = (string) $envKey;
    }
}

if ($tmdbApiKey === '') {
    echo "ERROR: TMDB API key not found. Set it in config/tmdb.php or TMDB_API_KEY env var.\n";
    exit(1);
}

$tmdbProvider = new TmdbProvider($tmdbApiKey);
$collectionService = new CollectionService($db, $itemRepository, $tmdbProvider);

// Find movies that have a tmdb_id but are not in media_collection_members.
// This query uses a LEFT JOIN to find items without a collection membership.
$batchSize = 100;
$processed = 0;
$skipped = 0;
$failed = 0;
$offset = 0;

echo "Scanning for movies with TMDB IDs but no collection membership...\n";

while (true) {
    $rows = $db->query(
        "SELECT mi.id, mi.metadata_json
         FROM media_items mi
         LEFT JOIN media_collection_members mcm ON mcm.media_item_id = mi.id
         WHERE mi.type IN ('movie')
           AND mcm.media_item_id IS NULL
           AND JSON_TYPE(JSON_EXTRACT(mi.metadata_json, '\$.tmdb_id')) = 'INT'
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

        // Check if item actually has a tmdb_id (the query already checks this,
        // but we double-check for safety)
        $metadata = $row['metadata_json'] ?? null;
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $tmdbId = isset($metadata['tmdb_id']) && is_numeric($metadata['tmdb_id'])
            ? (int) $metadata['tmdb_id']
            : null;

        if ($tmdbId === null) {
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "[DRY-RUN] Would sync collection for item {$itemId} (TMDB ID: {$tmdbId})\n";
        } else {
            try {
                $collectionService->syncCollectionForMovie((int) $itemId, $tmdbApiKey);
                echo "Synced collection for item {$itemId} (TMDB ID: {$tmdbId})\n";
            } catch (\Throwable $e) {
                echo "FAILED for item {$itemId}: {$e->getMessage()}\n";
                $failed++;
            }
        }

        $processed++;

        if ($processed % 50 === 0) {
            echo "Progress: {$processed} items processed, {$failed} failed\n";
        }

        if ($limit !== null && $processed >= $limit) {
            echo "Reached limit of {$limit} items\n";
            break 2;
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
