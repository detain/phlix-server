<?php

/**
 * Phlix media server component: Database.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 *   - An applied-migrations ledger (`schema_migrations`, SV-4.9) is consulted
 *     and recorded: a file whose name is recorded AND whose checksum still
 *     matches is SKIPPED (not re-executed); an un-recorded file is applied and
 *     then recorded. A recorded file whose checksum has DIVERGED (the `.sql`
 *     was edited since it was applied) is re-applied — matching the project's
 *     "re-run safe" contract — with a WARNING, and its recorded checksum is
 *     refreshed. The historical apply-every-file behaviour therefore only
 *     survives as the ledger's transition/fallback path (see {@see run()}).
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
    /**
     * Name of the applied-migrations ledger table (SV-4.9). Kept in sync with
     * `migrations/076_schema_migrations.sql`. The runner bootstrap-creates it
     * (idempotently) before the first read so the ledger is available even on a
     * fresh database where `076` has not yet been reached in sort order.
     */
    private const LEDGER_TABLE = 'schema_migrations';

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
     *   - `applied`: basenames of every migration file whose statements were
     *     EXECUTED this run (one entry per file, in execution order). A file
     *     skipped because the ledger already records it with a matching
     *     checksum is NOT listed here — see `skipped_count`/`notes`.
     *   - `notes`:   human-readable messages for idempotent errors that were
     *     downgraded (e.g. "duplicate column" on a replay), plus one
     *     "<file> already applied (ledger), skipping" line per ledger-skipped
     *     file.
     *   - `errors`:  human-readable messages for genuine, non-idempotent
     *     statement failures. A non-empty list signals a failure to the
     *     caller, but — exactly like the original script — does NOT abort the
     *     run: every remaining statement and file is still attempted.
     *   - `skipped_count`: how many statements/files were skipped as "already
     *     applied" — both the idempotent-error class (duplicate column /
     *     duplicate key / table-or-index already exists — see
     *     {@see isAlreadyAppliedNote()}) AND files skipped up front because the
     *     ledger already records them with a matching checksum. Callers can
     *     render a single "N skipped (already applied)" summary line instead of
     *     echoing each on every deploy, while still printing any note that
     *     falls outside that class in full.
     *
     * A failure to obtain the connection (provider throwing) or to read the
     * filesystem propagates as an uncaught exception, mirroring the script's
     * fatal-error path.
     *
     * @return array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int}
     */
    public function run(): array
    {
        $applied = [];
        $notes = [];
        $errors = [];
        $skippedCount = 0;

        $files = $this->discoverMigrationFiles();

        $connection = ($this->connectionProvider)();

        // No files → no ledger work and no queries at all (mirrors the empty /
        // absent migrations directory yielding no work). The connection is
        // still resolved above, exactly as the original script obtained `$db`
        // up front.
        if ($files === []) {
            return [
                'applied' => $applied,
                'notes' => $notes,
                'errors' => $errors,
                'skipped_count' => $skippedCount,
            ];
        }

        // SV-4.9 — bootstrap the ledger table before the first read. This is
        // idempotent (`CREATE TABLE IF NOT EXISTS`) and mirrors
        // `migrations/076_schema_migrations.sql`; doing it here guarantees the
        // ledger exists even on a fresh DB where `076` has not yet been reached
        // in sort order.
        $this->ensureLedgerTable($connection);

        // Read the applied-migrations ledger (name => checksum).
        //
        // TRANSITION SAFETY: on a box that already has every migration applied
        // but an EMPTY ledger — the current live state, where `076` created the
        // table yet nothing ever populated it — this SELECT returns no rows, so
        // every file is treated as un-recorded and re-applied. That is safe
        // because each migration's own `IF NOT EXISTS` / the duplicate-error
        // squelch below tolerates the replay, and each file is then RECORDED.
        // On subsequent runs the ledger is populated and unchanged files are
        // skipped without executing. A failure to read the ledger degrades to
        // the same "empty ledger" path (apply-all), never a hard failure.
        $ledger = $this->loadLedger($connection);

        foreach ($files as $file) {
            $name = basename($file);
            $sql = (string) file_get_contents($file);
            $checksum = md5($sql);

            if (isset($ledger[$name])) {
                if ($ledger[$name] === $checksum) {
                    // Recorded and unchanged — skip WITHOUT executing.
                    $skippedCount++;
                    $notes[] = sprintf('%s already applied (ledger), skipping', $name);
                    $this->logger?->info('Migration skipped (ledger)', ['file' => $name]);
                    continue;
                }

                // Recorded but the file content changed since it was applied
                // (a hotfix / rewrite-class edit). Matching the "re-run safe"
                // contract we do NOT hard-fail: log a warning and re-apply, then
                // refresh the recorded checksum after a clean apply below.
                $this->logger?->warning('Migration checksum diverged; re-applying', [
                    'file' => $name,
                    'recorded_checksum' => $ledger[$name],
                    'current_checksum' => $checksum,
                ]);
            }

            $applied[] = $name;
            $this->logger?->info('Running migration', ['file' => $name]);

            $fileHadGenuineError = false;

            foreach (self::splitStatements($sql) as $statement) {
                try {
                    $connection->query($statement);
                } catch (Throwable $e) {
                    if (self::isExpectedIdempotentError($e)) {
                        $notes[] = $e->getMessage();
                        if (self::isAlreadyAppliedNote($e->getMessage())) {
                            $skippedCount++;
                        }
                        $this->logger?->info('Migration note', [
                            'file' => $name,
                            'message' => $e->getMessage(),
                        ]);
                    } else {
                        $fileHadGenuineError = true;
                        $errors[] = $e->getMessage();
                        $this->logger?->error('Migration error', [
                            'file' => $name,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Record (or refresh) the ledger row only after a clean apply.
            // A file that raised a genuine (non-idempotent) error is left
            // UNRECORDED so it is re-attempted next run — honouring the
            // project's "a failed migration is not recorded / re-run safe"
            // contract. Idempotent "already applied" notes are NOT failures, so
            // a legitimately-replayed file is still recorded.
            if (!$fileHadGenuineError) {
                $this->recordMigration($connection, $name, $checksum);
            }
        }

        return [
            'applied' => $applied,
            'notes' => $notes,
            'errors' => $errors,
            'skipped_count' => $skippedCount,
        ];
    }

    /**
     * Bootstrap the ledger table (idempotent). Never aborts a run: if the
     * table cannot be created/verified we fall back to the historical
     * apply-every-file behaviour, which the duplicate-error squelch keeps safe.
     */
    private function ensureLedgerTable(Connection $connection): void
    {
        try {
            $connection->query(
                'CREATE TABLE IF NOT EXISTS ' . self::LEDGER_TABLE . ' ('
                . 'name VARCHAR(255) NOT NULL, '
                . 'applied_at INT UNSIGNED NOT NULL, '
                . 'checksum CHAR(32) NOT NULL, '
                . 'PRIMARY KEY (name)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable $e) {
            $this->logger?->warning('Could not ensure migration ledger table', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Read the applied-migrations ledger as a `name => checksum` map. Any read
     * failure degrades to an empty map (treat every file as un-recorded), never
     * a hard failure — see the transition-safety note in {@see run()}.
     *
     * @return array<string, string>
     */
    private function loadLedger(Connection $connection): array
    {
        try {
            $rows = $connection->query('SELECT name, checksum FROM ' . self::LEDGER_TABLE);
        } catch (Throwable $e) {
            $this->logger?->warning('Could not read migration ledger; treating as empty', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $ledger = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['name'], $row['checksum'])) {
                    $ledger[(string) $row['name']] = (string) $row['checksum'];
                }
            }
        }

        return $ledger;
    }

    /**
     * Record (INSERT ... ON DUPLICATE KEY UPDATE) a cleanly-applied migration
     * in the ledger. Parameterised via `?` placeholders (the safe
     * {@see Connection::query()} idiom — no raw PDO/mysqli). A write failure is
     * logged and swallowed: the migration itself already applied; a missing
     * ledger row only means it is re-applied (safely) next run.
     */
    private function recordMigration(Connection $connection, string $name, string $checksum): void
    {
        try {
            $connection->query(
                'INSERT INTO ' . self::LEDGER_TABLE . ' (name, applied_at, checksum) '
                . 'VALUES (?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE applied_at = VALUES(applied_at), checksum = VALUES(checksum)',
                [$name, time(), $checksum]
            );
        } catch (Throwable $e) {
            $this->logger?->warning('Could not record migration in ledger', [
                'file' => $name,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether a note message belongs to the "already applied" class — the
     * idempotent duplicate/exists errors a replayed migration legitimately
     * raises (duplicate column 1060, duplicate key 1061, table exists 1050,
     * can't-drop 1091, …). Callers use this to collapse such notes into a
     * single "N statements skipped (already applied)" summary line while
     * still printing any other note in full.
     */
    public static function isAlreadyAppliedNote(string $message): bool
    {
        return str_contains($message, 'Duplicate column name')
            || str_contains($message, 'Duplicate key name')
            || str_contains($message, 'check that column/key exists')
            || str_contains($message, 'already exists');
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
     * Split a file into individual executable statements, ignoring any `;`
     * that lives inside a string literal, a backtick-quoted identifier, a
     * line `--`/`#` comment, or a C-style block comment. Comment text and
     * blank fragments are dropped; quoted contents survive verbatim.
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        // Single-pass, quote/comment-aware scanner. Splitting on `;` with a
        // plain regex (even after stripping `--`/block comments) still shreds a
        // statement whose string literal contains a semicolon — e.g. a column
        // `COMMENT 'Hard expiry; the token is invalid once this passes'`, which
        // leaves the DDL truncated mid-string and failing with a 1064 syntax
        // error. This scanner only splits on a `;` outside any string literal
        // (single/double quoted), backtick-quoted identifier, line comment
        // (`-- ...` or `# ...`) or C-style block comment. Comment text is
        // dropped; quoted contents (including embedded `;`) survive verbatim.
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        // Lexical context: '' (top level), "'"/'"'/'`' (quote), '--' (line
        // comment) or '/*' (block comment).
        $context = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            switch ($context) {
                case "'":
                case '"':
                case '`':
                    $buffer .= $ch;
                    if ($ch === $context && $next === $context) {
                        // Doubled quote ('' / "" / ``) — an escaped quote.
                        $buffer .= $next;
                        $i++;
                    } elseif ($ch === '\\' && $context !== '`' && $next !== '') {
                        // Backslash escape inside a string literal.
                        $buffer .= $next;
                        $i++;
                    } elseif ($ch === $context) {
                        $context = '';
                    }
                    break;

                case '--':
                    if ($ch === "\n") {
                        $buffer .= $ch;
                        $context = '';
                    }
                    break;

                case '/*':
                    if ($ch === '*' && $next === '/') {
                        $i++;
                        $context = '';
                    }
                    break;

                default:
                    if ($ch === '-' && $next === '-') {
                        $context = '--';
                        $i++;
                    } elseif ($ch === '#') {
                        // MySQL '#' line comment.
                        $context = '--';
                    } elseif ($ch === '/' && $next === '*') {
                        $context = '/*';
                        $i++;
                    } elseif ($ch === "'" || $ch === '"' || $ch === '`') {
                        $context = $ch;
                        $buffer .= $ch;
                    } elseif ($ch === ';') {
                        $part = trim($buffer);
                        if ($part !== '') {
                            $statements[] = $part;
                        }
                        $buffer = '';
                    } else {
                        $buffer .= $ch;
                    }
                    break;
            }
        }

        $part = trim($buffer);
        if ($part !== '') {
            $statements[] = $part;
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
        return self::isAlreadyAppliedNote($e->getMessage());
    }
}
