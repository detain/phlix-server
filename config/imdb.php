<?php

/**
 * IMDb dataset / data-layer configuration.
 *
 * Drives {@see \Phlix\Media\Metadata\Imdb\ImdbDatasetImporter} (which downloads
 * IMDb's free daily TSV datasets and UPSERTs them into the `imdb_titles` table)
 * and {@see \Phlix\Media\Metadata\Imdb\ImdbLookup} (which queries that table).
 *
 * Set `IMDB_ENABLED=1` (or `true`) in the environment to allow the importer CLI
 * (`scripts/import-imdb-datasets.php`) to run without `--force`. When disabled,
 * the local `imdb_titles` table simply stays empty and lookups return null.
 *
 * IMDb publishes the datasets under the non-commercial terms documented at
 * https://www.imdb.com/interfaces/.
 *
 * @since 0.21.0
 */

declare(strict_types=1);

$enabledRaw = getenv('IMDB_ENABLED');
$enabled = in_array(
    strtolower((string) ($enabledRaw === false ? '' : $enabledRaw)),
    ['1', 'true', 'yes', 'on'],
    true
);

$cacheDir = getenv('IMDB_CACHE_DIR');
if ($cacheDir === false || $cacheDir === '') {
    $cacheDir = dirname(__DIR__) . '/.cache/imdb';
}

return [
    /** Whether the importer CLI is allowed to run without `--force`. */
    'enabled' => $enabled,

    /** IMDb title.basics dataset (gzipped TSV). */
    'basics_url' => 'https://datasets.imdbws.com/title.basics.tsv.gz',

    /** IMDb title.ratings dataset (gzipped TSV). */
    'ratings_url' => 'https://datasets.imdbws.com/title.ratings.tsv.gz',

    /** Directory the gzipped datasets are downloaded to / read from. */
    'cache_dir' => rtrim($cacheDir, '/'),

    /** IMDb `titleType` values kept during import (movies only). */
    'title_types' => ['movie', 'tvMovie'],
];
