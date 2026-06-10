<?php

declare(strict_types=1);

/*
 * Run a metadata match for one library (or every library) from the CLI — the
 * same {@see \Phlix\Media\Metadata\LibraryMetadataMatcher::matchLibrary()} the
 * admin "Match metadata" button enqueues, but invoked directly so an operator
 * can (re)populate TMDB metadata without the SPA / scan-job worker.
 *
 * Movies are matched via the cross-source TMDB+IMDb resolver; series/season/
 * episode items are matched via the TMDB TV resolver (poster/overview/genres +
 * per-episode title/still). The TMDB API key is read from the admin Settings
 * (Settings → Metadata), falling back to config/tmdb.php / the TMDB_API_KEY env.
 *
 * Usage:
 *   php scripts/match-library-metadata.php --library=<id>   # one library
 *   php scripts/match-library-metadata.php --all            # every library
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Metadata\LibraryMetadataMatcher;

$libraryId = null;
$all = in_array('--all', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--library=')) {
        $libraryId = substr($arg, strlen('--library='));
    }
}

if (!$all && ($libraryId === null || $libraryId === '')) {
    fwrite(STDERR, "Usage: php scripts/match-library-metadata.php --library=<id> | --all\n");
    exit(1);
}

/** @var array<string, mixed> $config */
$config = include $baseDir . '/config/server.php';
$config['db_config_path']     = $baseDir . '/config/database.php';
$config['logger_config_path'] = $baseDir . '/config/logger.php';

// Make config/tmdb.php (and similar) available to the container's provider
// factories the same way start.php's bootstrap does.
$tmdbConfig = @include $baseDir . '/config/tmdb.php';
if (is_array($tmdbConfig)) {
    $config['tmdb'] = $tmdbConfig;
}

LoggerFactory::init($config['logger_config_path']);

$container = ContainerFactory::create($config);
/** @var LibraryMetadataMatcher $matcher */
$matcher = $container->get(LibraryMetadataMatcher::class);

$libraryIds = [];
if ($all) {
    /** @var LibraryManager $libraryManager */
    $libraryManager = $container->get(LibraryManager::class);
    foreach ($libraryManager->getAllLibraries() as $library) {
        $id = is_array($library) ? ($library['id'] ?? null) : null;
        if (is_string($id) && $id !== '') {
            $libraryIds[] = $id;
        }
    }
} else {
    $libraryIds[] = (string) $libraryId;
}

$startedAll = time();
foreach ($libraryIds as $id) {
    echo "[" . date('H:i:s') . "] matching library {$id} ...\n";
    $started = time();
    $result = $matcher->matchLibrary($id);
    $elapsed = time() - $started;
    echo "[" . date('H:i:s') . "] library {$id}: matched {$result['matched']} of {$result['processed']} "
        . "processed in {$elapsed}s\n";
}

echo "Done in " . (time() - $startedAll) . "s.\n";
