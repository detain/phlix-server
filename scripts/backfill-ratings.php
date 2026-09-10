#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * P1-S1 backfill: populate metadata_ratings for media items that predate the
 * rating capture feature (or were never refreshed through MetadataManager).
 *
 * Finds media items that have a TMDB ID (stored in metadata_json.external_ids.tmdb)
 * but lack a corresponding 'tmdb' source entry in metadata_ratings, then
 * fetches the current vote_average and vote_count from TMDB and upserts them.
 *
 * The aggregate (RatingType::Average) is recomputed for every affected item so
 * the weighted average stays current even when backfilling historical data.
 *
 * Usage:
 *   php scripts/backfill-ratings.php                 # dry-run
 *   php scripts/backfill-ratings.php --dry-run      # explicit dry-run
 *   php scripts/backfill-ratings.php --execute       # real write
 *   php scripts/backfill-ratings.php --limit=500     # cap rows this run
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Metadata\TmdbApiKeyResolver;
use Phlix\Media\Metadata\RatingService;
use Phlix\Media\Metadata\RatingSource;
use Phlix\Media\Metadata\RatingType;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Media\Library\ItemRepository;

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

// Effective TMDB API key: the admin-managed `server_settings` override wins,
// then config/tmdb.php, then TMDB_API_KEY. Reading config/env only (as this
// did) reports "key not found" on a server whose key is set from the admin UI.
$tmdbApiKey = TmdbApiKeyResolver::resolve(
    new SettingsRepository($db, __DIR__ . '/../config'),
    __DIR__ . '/../config/tmdb.php'
);

if ($tmdbApiKey === '') {
    fwrite(STDERR, "ERROR: TMDB API key not found in the admin settings, config/tmdb.php or TMDB_API_KEY env\n");
    exit(1);
}

// Build the services
$tmdb = new TmdbProvider($tmdbApiKey);
$ratingService = new RatingService($db);
$itemRepository = new ItemRepository($db, null);

// Build the candidate query: items with a TMDB external ID but no corresponding
// metadata_ratings.tmdb entry. We use a LEFT JOIN to find items missing the rating.
//
// The `{keyset}`/`{limit_clause}` placeholders are replaced per batch by the loop
// below — the pre-batching code interpolated a LIMIT into $query but then executed a
// DIFFERENT inline SQL string in the loop, so `--limit` was parsed, documented in the
// usage block and silently never applied (PHPStan's `arguments.count` error on the old
// 4-parameter query() call is what surfaced the whole dead construction).
//
// Pagination is KEYSET on the primary key (`m.id > ?` with `ORDER BY m.id`), NOT
// OFFSET: under --execute every successful upsert REMOVES its row from the
// `r.id IS NULL` candidate set, so an advancing OFFSET walks past the candidates that
// shifted down into the window it just skipped — roughly one silently unprocessed row
// per fixed row. Ordered keyset pages are immune whether the set shrinks or not, and
// the dry-run (set never changes) gets the same stable order.
$query = "
    SELECT m.id, m.metadata_json
    FROM media_items m
    LEFT JOIN metadata_ratings r
        ON r.media_item_id = m.id
        AND r.source = 'tmdb'
        AND r.rating_type = 'user'
    WHERE r.id IS NULL
      AND JSON_EXTRACT(m.metadata_json, '$.external_ids.tmdb') IS NOT NULL
      {keyset}
    ORDER BY m.id
    {limit_clause}
";

// Fetch candidates in batches to avoid blowing up memory on large libraries.
$batchSize = 100;
$processed = 0;
$updated = 0;
$failed = 0;

echo "Scanning for items missing TMDB ratings...\n";

// $scanned counts ROWS SEEN, so `--limit` caps the run exactly as its usage line
// advertises. $lastId is the keyset cursor — the id of the last FETCHED row.
$scanned = 0;
$lastId = null;

while (true) {
    $batch = $limit === null ? $batchSize : min($batchSize, $limit - $scanned);
    if ($batch < 1) {
        break;
    }

    $batchQuery = str_replace(
        ['{keyset}', '{limit_clause}'],
        [$lastId === null ? '' : 'AND m.id > ?', "LIMIT {$batch}"],
        $query,
    );

    // Connection::query() takes (sql, params, fetchmode) — the old call passed
    // __LINE__ as $fetchmode and __FILE__ as a fourth argument (a userland
    // over-supply PHP tolerates at the signature but PDO then received 112-ish
    // as the fetch mode). Level 9 read the signature and refused.
    $rows = $db->query($batchQuery, $lastId === null ? [] : [$lastId]);
    if (!is_array($rows)) {
        $rows = [];
    }

    if ($rows === []) {
        break;
    }

    // Advance the cursor from the last FETCHED row — media_items.id is the NOT NULL
    // primary key, so a row whose id is not a non-empty string means the result shape
    // broke and paging further would be a guess: halt loudly, never loop blind.
    $tailRow = end($rows);
    $tailId = is_array($tailRow) ? ($tailRow['id'] ?? null) : null;
    if (!is_string($tailId) || $tailId === '') {
        fwrite(STDERR, "ERROR: candidate row without a string media_items.id — cannot advance the keyset cursor.\n");
        exit(1);
    }
    $lastId = $tailId;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $itemId = $row['id'] ?? null;
        $metadataJson = $row['metadata_json'] ?? null;
        if (!is_string($itemId) || !is_string($metadataJson)) {
            continue;
        }

        $metadata = json_decode($metadataJson, true);

        if (!is_array($metadata)) {
            continue;
        }

        $externalIds = $metadata['external_ids'] ?? null;
        if (!is_array($externalIds)) {
            continue;
        }

        $tmdbId = $externalIds['tmdb'] ?? null;
        if (!is_string($tmdbId) || $tmdbId === '') {
            continue;
        }

        // Fetch TMDB details to get vote_average and vote_count.
        // No is_array() guard here on purpose: TmdbProvider::getDetails() declares
        // `: array`, PHP enforces native return types at runtime, so the old guard
        // could never fire (PHPStan L9: function.alreadyNarrowedType). A missing
        // vote_average is the real failure mode and is handled below.
        $details = $tmdb->getDetails($tmdbId);

        $score = $details['vote_average'] ?? null;
        $votes = $details['vote_count'] ?? null;

        if ($score === null || !is_numeric($score)) {
            echo "SKIP item {$itemId}: no vote_average in TMDB response\n";
            $processed++;
            continue;
        }

        $score = (float) $score;
        $votes = is_numeric($votes) ? (int) $votes : null;

        // Each row increments EXACTLY ONE outcome counter: a failed upsert that
        // also fell through to $updated would overstate the summary line.
        if ($dryRun) {
            echo "[DRY-RUN] Would upsert rating for item {$itemId}: tmdb/user {$score}";
            if ($votes !== null) {
                echo " ({$votes} votes)";
            }
            echo "\n";
            $updated++;
        } else {
            try {
                $ratingService->upsert(
                    $itemId,
                    RatingSource::Tmdb,
                    RatingType::User,
                    $score,
                    $votes,
                );
                $ratingService->aggregate($itemId);
                echo "Upserted rating for item {$itemId}: tmdb/user {$score}";
                if ($votes !== null) {
                    echo " ({$votes} votes)";
                }
                echo "\n";
                $updated++;
            } catch (\Throwable $e) {
                echo "FAILED to upsert rating for item {$itemId}: {$e->getMessage()}\n";
                $failed++;
            }
        }

        $processed++;

        if ($processed % 100 === 0) {
            echo "Progress: {$processed} items checked, {$updated} ratings, {$failed} failed\n";
        }
    }

    // Fewer rows than the batch asked for = candidate set exhausted (keyset page ran dry).
    $scanned += count($rows);
    if (count($rows) < $batch) {
        break;
    }
}

echo "\nDone. Processed: {$processed}, Updated: {$updated}, Failed: {$failed}\n";

if ($dryRun) {
    echo "Re-run with --execute to write changes.\n";
}

exit($failed > 0 ? 1 : 0);
