<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

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

// Idempotent "already applied" notes (duplicate column/key, table exists, …)
// are collapsed into ONE summary line — replaying every migration on every
// deploy legitimately raises dozens of them, and echoing each one reads like
// the deploy is broken. Any note outside that class is still printed in full.
foreach ($result['notes'] as $note) {
    if (!MigrationRunner::isAlreadyAppliedNote($note)) {
        echo "  note: " . $note . "\n";
    }
}

if ($result['skipped_count'] > 0) {
    echo "  " . $result['skipped_count'] . " statement(s) skipped (already applied)\n";
}

foreach ($result['errors'] as $error) {
    echo "  Warning: " . $error . "\n";
}

echo "Migrations complete.\n";
