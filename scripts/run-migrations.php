<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Database\MigrationRunner;

// Apply migrations via the shared MigrationRunner service so this script,
// `bin/phlix migrate`, and any other caller stay byte-faithful. The runner
// performs the same apply-all loop (split statements, run each, downgrade
// idempotent dup-column/dup-key errors to notes) with NO migration-tracking
// table — preserving the apply-all-every-time contract that
// docker/docker-entrypoint.sh and scripts/install.sh depend on.

$configPath = __DIR__ . '/../config/database.php';
ConnectionPool::init($configPath);

$runner = new MigrationRunner(
    static fn() => ConnectionPool::getConnection('mysql'),
    __DIR__ . '/../migrations'
);

$result = $runner->run();

foreach ($result['applied'] as $file) {
    echo "Running migration: " . $file . "\n";
}

foreach ($result['notes'] as $note) {
    echo "  note: " . $note . "\n";
}

foreach ($result['errors'] as $error) {
    echo "  Warning: " . $error . "\n";
}

echo "Migrations complete.\n";
