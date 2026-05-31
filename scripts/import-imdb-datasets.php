<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Metadata\Imdb\ImdbDatasetImporter;

/**
 * IMDb dataset importer CLI.
 *
 * Bootstraps the DB connection + logger, then downloads IMDb's free daily
 * datasets and UPSERTs them into the `imdb_titles` table (see migration 029 and
 * {@see ImdbDatasetImporter}). Run AFTER `scripts/run-migrations.php`.
 *
 * Usage:
 *   php scripts/import-imdb-datasets.php            # only runs when imdb.enabled
 *   php scripts/import-imdb-datasets.php --force    # run regardless of enabled
 *   php scripts/import-imdb-datasets.php --redownload  # force re-download of gz
 *
 * Guarded by `config/imdb.php` `enabled` (env `IMDB_ENABLED`); pass `--force` to
 * bypass the guard.
 *
 * @since 0.21.0
 */

$options = getopt('', ['force', 'redownload', 'help']);

if (isset($options['help'])) {
    echo "Import IMDb daily datasets into the imdb_titles table.\n\n";
    echo "Usage: php scripts/import-imdb-datasets.php [--force] [--redownload]\n\n";
    echo "  --force       Run even when IMDB_ENABLED is not set.\n";
    echo "  --redownload  Force re-download of the gz datasets.\n";
    echo "  --help        Show this help.\n";
    return;
}

$force = isset($options['force']);
$redownload = isset($options['redownload']);

/** @var array<string, mixed> $config */
$config = require __DIR__ . '/../config/imdb.php';

$enabled = (bool) ($config['enabled'] ?? false);
if (!$enabled && !$force) {
    fwrite(STDERR, "IMDb import is disabled (set IMDB_ENABLED=1 or pass --force). Skipping.\n");
    return;
}

LoggerFactory::init(__DIR__ . '/../config/logger.php');
ConnectionPool::init(__DIR__ . '/../config/database.php');

$db = ConnectionPool::getConnection('mysql');

$importer = new ImdbDatasetImporter($db, $config);

echo "Importing IMDb datasets...\n";
$result = $importer->import($redownload);

echo "Done.\n";
echo "  Titles upserted: " . $result['titles'] . "\n";
echo "  Ratings loaded:  " . $result['ratings'] . "\n";
