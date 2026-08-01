<?php

/**
 * Apply `migrations/*.sql` and EXIT NON-ZERO IF ANY OF THEM FAILED.
 *
 * ## S159 — the exit code is the whole point
 *
 * Until S159 this script printed `"  Warning: <error>"` for every genuine
 * migration failure, then printed `"Migrations complete."` and fell off the end
 * of the file — i.e. it always exited 0. Combined with the `|| true` that
 * `docker/docker-entrypoint.sh` wrapped it in, a container could boot, report
 * success and then serve traffic against a HALF-MIGRATED schema, and CI's
 * "Apply database migrations" step could never go red. `bin/phlix migrate` was
 * the only path in the project that returned 1.
 *
 * It now exits with {@see MigrationRunner::exitCodeFor()}:
 *
 *   - `0` — every statement applied, or failed only with an idempotent
 *     "already applied" error (duplicate column / duplicate key / table or
 *     index exists). A replay on a fully-migrated database is a SUCCESS and
 *     must stay one; see the MigrationRunner class docblock, class (a).
 *   - `1` — at least one genuine, non-idempotent statement error was recorded.
 *
 * The run itself is unchanged: it is still CONTINUE-AND-REPORT, not
 * stop-on-first-error, and a file that failed is still left out of the
 * `schema_migrations` ledger so the next run retries it. Only the reporting of
 * the outcome changed. The reasoning is recorded on {@see MigrationRunner}.
 *
 * ## Callers, and what a non-zero exit does to each
 *
 *   - `scripts/install.sh` (`set -euo pipefail`, no `|| true`) — aborts the
 *     install/update. Deliberate: an attended, operator-driven path.
 *   - `docker/docker-entrypoint.sh` — prints a loud banner and still boots,
 *     unless `PHLIX_MIGRATIONS_STRICT=1`. Decided in that file.
 *   - `.github/workflows/phpunit.yml` / `syncplay-e2e.yml` — the step now fails,
 *     which is the CI half of S159.
 *
 * ## PHLIX_MIGRATIONS_DIR
 *
 * The migrations directory defaults to `<repo>/migrations` and can be pointed
 * elsewhere with `PHLIX_MIGRATIONS_DIR`. That override exists so the exit-code
 * contract above can be PROVEN in both directions by a test that plants a
 * deliberately failing `.sql` in a scratch directory
 * (`tests/Integration/Common/Database/MigrationFailureVisibilityTest.php`)
 * without touching the repo's real migrations.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Database\MigrationRunner;

// Apply migrations via the shared MigrationRunner service so this script,
// `bin/phlix migrate`, and any other caller stay byte-faithful — including the
// definition of "failed", which both now take from
// MigrationRunner::exitCodeFor().

$configPath = __DIR__ . '/../config/database.php';
ConnectionPool::init($configPath);

$migrationsDirEnv = getenv('PHLIX_MIGRATIONS_DIR');
$migrationsDir = (is_string($migrationsDirEnv) && $migrationsDirEnv !== '')
    ? $migrationsDirEnv
    : __DIR__ . '/../migrations';

$runner = new MigrationRunner(
    static fn() => ConnectionPool::getConnection('mysql'),
    $migrationsDir
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
    // Kept as `Warning:` for log-grep compatibility with every deploy log
    // written before S159; the FAILED summary below is the new, unambiguous
    // signal, and the exit code is the machine-readable one.
    echo "  Warning: " . $error . "\n";
}

$exitCode = MigrationRunner::exitCodeFor($result);

if ($exitCode === MigrationRunner::EXIT_SUCCESS) {
    echo "Migrations complete.\n";
} else {
    // stderr, not stdout: an installer or entrypoint that only surfaces stderr
    // still shows this, and it cannot be lost in a page of "Running migration:"
    // lines.
    fwrite(
        STDERR,
        "Migrations FAILED: " . count($result['errors']) . " error(s) in "
        . count($result['applied']) . " file(s) attempted. The schema is "
        . "HALF-MIGRATED — the failing file(s) were NOT recorded in "
        . "schema_migrations and will be retried on the next run.\n"
    );
}

exit($exitCode);
