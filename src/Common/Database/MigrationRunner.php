<?php

declare(strict_types=1);

namespace Phlix\Common\Database;

use Psr\Log\LoggerInterface;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Applies the project's `migrations/*.sql` files against a MySQL connection.
 *
 * This is a faithful extraction of the apply-all loop that previously lived
 * inline in `scripts/run-migrations.php`. The behaviour is deliberately
 * byte-faithful so that `scripts/run-migrations.php` (still called by
 * `docker/docker-entrypoint.sh` and `scripts/install.sh`) and the new
 * `bin/phlix migrate` command produce identical results:
 *
 *   - All `*.sql` files in the migrations directory are discovered with
 *     `glob()` and `sort()`ed (lexicographic order, same as the script).
 *   - Every file is applied on every run — there is **no** migration-tracking
 *     table. The apply-all-every-time idempotent contract is preserved.
 *   - Each file is split into individual statements (comments stripped) and
 *     every statement is run via {@see Connection::query()}.
 *   - Statement-level exceptions whose message matches a known
 *     "already applied" pattern (duplicate column / duplicate key /
 *     table-or-index already exists) are downgraded to notes rather than
 *     treated as failures (MySQL 8 has no `IF NOT EXISTS` on `ADD COLUMN` /
 *     `ADD INDEX`, so replays legitimately raise these).
 *   - Any other statement-level exception is recorded as an error (the script
 *     printed these as `Warning:`); like the script, recording an error does
 *     not abort the run — remaining statements/files still execute.
 *
 * No I/O happens at construction: the connection is obtained lazily, only when
 * {@see run()} is invoked, via the supplied connection provider. This lets
 * `bin/phlix list` (and command construction in general) work in an
 * environment with no database.
 */
final class MigrationRunner
{
    /** @var callable(): Connection */
    private $connectionProvider;

    private string $migrationsDir;

    private ?LoggerInterface $logger;

    /**
     * @param callable(): Connection $connectionProvider Lazily resolves the
     *        MySQL connection. NOT invoked at construction — only inside
     *        {@see run()} — so no socket is opened until a migration runs.
     * @param string $migrationsDir Absolute path to the directory containing
     *        the `*.sql` migration files.
     * @param LoggerInterface|null $logger Optional logger; applied migrations
     *        and notes are logged at info level, errors at error level.
     */
    public function __construct(
        callable $connectionProvider,
        string $migrationsDir,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionProvider = $connectionProvider;
        $this->migrationsDir = $migrationsDir;
        $this->logger = $logger;
    }

    /**
     * Apply every migration file once, in sorted order.
     *
     * Resolves the connection lazily via the provider, then applies each
     * statement of each `*.sql` file. The return value lets a caller render a
     * human summary and decide on an exit code:
     *
     *   - `applied`: basenames of every migration file that was processed
     *     (one entry per file, in execution order).
     *   - `notes`:   human-readable messages for idempotent errors that were
     *     downgraded (e.g. "duplicate column" on a replay).
     *   - `errors`:  human-readable messages for genuine, non-idempotent
     *     statement failures. A non-empty list signals a failure to the
     *     caller, but — exactly like the original script — does NOT abort the
     *     run: every remaining statement and file is still attempted.
     *
     * A failure to obtain the connection (provider throwing) or to read the
     * filesystem propagates as an uncaught exception, mirroring the script's
     * fatal-error path.
     *
     * @return array{applied: list<string>, notes: list<string>, errors: list<string>}
     */
    public function run(): array
    {
        $applied = [];
        $notes = [];
        $errors = [];

        $files = $this->discoverMigrationFiles();

        $connection = ($this->connectionProvider)();

        foreach ($files as $file) {
            $name = basename($file);
            $applied[] = $name;
            $this->logger?->info('Running migration', ['file' => $name]);

            $sql = (string) file_get_contents($file);

            foreach (self::splitStatements($sql) as $statement) {
                try {
                    $connection->query($statement);
                } catch (Throwable $e) {
                    if (self::isExpectedIdempotentError($e)) {
                        $notes[] = $e->getMessage();
                        $this->logger?->info('Migration note', [
                            'file' => $name,
                            'message' => $e->getMessage(),
                        ]);
                    } else {
                        $errors[] = $e->getMessage();
                        $this->logger?->error('Migration error', [
                            'file' => $name,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return [
            'applied' => $applied,
            'notes' => $notes,
            'errors' => $errors,
        ];
    }

    /**
     * Discover all `*.sql` migration files, sorted lexicographically.
     *
     * Mirrors the script's `glob() + sort()`. `glob()` can return `false`
     * (e.g. an unreadable directory); that is normalised to an empty list so
     * an empty/absent migrations directory yields no work rather than a fatal.
     *
     * @return list<string>
     */
    private function discoverMigrationFiles(): array
    {
        $files = glob($this->migrationsDir . '/*.sql');
        if ($files === false) {
            return [];
        }
        sort($files);

        return $files;
    }

    /**
     * Strip SQL comments and split a file into individual executable
     * statements. Handles:
     *   - line `--` comments (the whole line, including any `;` inside it)
     *   - block `/* ... *\/` comments
     *   - blank lines after comment removal
     *
     * A naive `explode(';', $sql)` shreds files whose comments happen to
     * contain a semicolon, so comments are removed before splitting.
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        // 1. Drop /* ... */ block comments (non-greedy, multi-line aware).
        $stripped = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;

        // 2. Drop line-level `--` comments. Anything from `--` on a line
        //    until end-of-line is comment text. Tolerate both `-- comment`
        //    and `--comment` and `code -- comment` forms.
        $stripped = preg_replace('/--[^\n]*/', '', $stripped) ?? $stripped;

        // 3. Split on `;`. Trim each fragment and drop empty ones.
        $statements = [];
        foreach (explode(';', $stripped) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $statements[] = $part;
            }
        }

        return $statements;
    }

    /**
     * Some `ALTER TABLE ... ADD COLUMN` / `ADD INDEX` statements legitimately
     * fail on re-runs because the column / index already exists. MySQL 8
     * doesn't accept `IF NOT EXISTS` on those clauses (only MariaDB does), so
     * we recognise the matching error text and downgrade those to notes
     * rather than treating them as failures.
     */
    private static function isExpectedIdempotentError(Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'Duplicate column name')
            || str_contains($msg, 'Duplicate key name')
            || str_contains($msg, 'check that column/key exists')
            || str_contains($msg, 'already exists');
    }
}
