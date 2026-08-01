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
 *   - Statement-level exceptions carrying one of the known "already applied"
 *     MySQL error numbers (duplicate column / duplicate key / duplicate
 *     constraint name / table already exists / can't-drop — see
 *     {@see IDEMPOTENT_ERROR_CODES}) are downgraded to notes rather than
 *     treated as failures (MySQL 8 has no `IF NOT EXISTS` on `ADD COLUMN` /
 *     `ADD INDEX`, so replays legitimately raise these).
 *   - Any other statement-level exception is recorded as an error; like the
 *     script, recording an error does not abort the run — remaining
 *     statements/files still execute. See "Failure semantics" below: that is a
 *     DECISION, and the visibility of such an error is carried by
 *     {@see exitCodeFor()}, not by aborting the run.
 *
 * No I/O happens at construction: the connection is obtained lazily, only when
 * {@see run()} is invoked, via the supplied connection provider. This lets
 * `bin/phlix list` (and command construction in general) work in an
 * environment with no database.
 *
 * ## Failure semantics (S159 — DECIDED, not incidental)
 *
 * Three outcomes exist and must never be collapsed into one another:
 *
 *   (a) **"Already applied" replay** — one of the MySQL error numbers in
 *       {@see IDEMPOTENT_ERROR_CODES} (duplicate column/key/FK/CHECK name,
 *       table exists, can't-drop), raised by re-running a migration that was
 *       already applied. MySQL 8 has no `IF NOT EXISTS` on `ADD COLUMN` /
 *       `ADD INDEX`, so a legitimate replay raises these on nearly every file.
 *       Recorded as a NOTE, counted in `skipped_count`, **is not a failure**,
 *       and the file is still recorded in the ledger. Treating this class as a
 *       failure would redden every replay and the change would be reverted.
 *       ⚠ The set is a CLOSED LIST, not "everything a replay can raise": a
 *       seed `INSERT` replay (1062) and `ADD PRIMARY KEY` (1068) are class (b)
 *       ON PURPOSE — see the constant for why — so a migration author must
 *       write `INSERT IGNORE` / guard the `ADD PRIMARY KEY` rather than rely
 *       on the squelch.
 *   (b) **Genuine statement error** — anything else, decided on the driver's
 *       error number and NOT on a substring of the exception message (which
 *       contains the migration's own SQL — see {@see isAlreadyAppliedNote()}).
 *       Recorded in `errors`, and {@see exitCodeFor()} maps a non-empty
 *       `errors` to exit code 1 so the failure is visible to a shell, to
 *       `set -e`, and to CI.
 *   (c) **A file that failed is left UNRECORDED** in the ledger, so it is
 *       re-attempted on the next run. This is the project's "re-run safe"
 *       contract and is the reason (d) below is safe.
 *
 * **The run is CONTINUE-AND-REPORT, not stop-on-first-error.** This was
 * re-affirmed rather than changed, because:
 *
 *   - migration files are independent units; a failure in `085` does not make
 *     `086` unsafe to apply, and stopping would leave every later file both
 *     un-applied AND un-recorded — turning one bad file into a permanently
 *     stalled schema on every subsequent boot;
 *   - the (c) contract already guarantees a failed file is retried, so
 *     continuing costs nothing in correctness;
 *   - the defect S159 fixes is *visibility*, not execution order. Making the
 *     exit code honest is the minimal change that makes the failure impossible
 *     to miss on every path that has a caller able to check it.
 *
 * Consumers of the exit code:
 *
 *   - `bin/phlix migrate` ({@see \Phlix\Console\Commands\MigrateCommand}) — 0/1.
 *   - `scripts/run-migrations.php` — 0/1 (S159; it previously always exited 0).
 *   - `scripts/install.sh` — runs under `set -euo pipefail` with no `|| true`,
 *     so a failed migration now ABORTS the install/update. Deliberate: that is
 *     an attended, operator-driven path.
 *   - `docker/docker-entrypoint.sh` — deliberately still boots the container on
 *     a migration failure (a crash-looping media server is a worse outcome than
 *     a degraded one), but prints a loud `PHLIX-MIGRATION-FAILURE` banner and
 *     honours `PHLIX_MIGRATIONS_STRICT=1` to abort instead. See that file.
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

    /**
     * Process exit code for a run in which every statement applied, or failed
     * only with an idempotent "already applied" error (class (a) in the class
     * docblock). Matches `Symfony\Component\Console\Command\Command::SUCCESS`.
     */
    public const EXIT_SUCCESS = 0;

    /**
     * Process exit code for a run that recorded at least one genuine,
     * non-idempotent statement error (class (b)). Matches
     * `Symfony\Component\Console\Command\Command::FAILURE`.
     */
    public const EXIT_FAILURE = 1;

    /**
     * MySQL error numbers that a LEGITIMATE REPLAY of an already-applied
     * migration raises — class (a) in the class docblock. Matched on the
     * driver's errno, never on the rendered message (see
     * {@see isAlreadyAppliedNote()} for why the message cannot be trusted).
     *
     *   - `1050` ER_TABLE_EXISTS_ERROR      — `CREATE TABLE` without `IF NOT EXISTS`
     *   - `1060` ER_DUP_FIELDNAME           — `ALTER TABLE … ADD COLUMN`
     *   - `1061` ER_DUP_KEYNAME             — `ALTER TABLE … ADD KEY` / `CREATE INDEX`
     *   - `1091` ER_CANT_DROP_FIELD_OR_KEY  — `DROP COLUMN` / `DROP INDEX` already gone
     *   - `1826` ER_FK_DUP_NAME             — `ADD CONSTRAINT … FOREIGN KEY`
     *   - `3822` ER_CHECK_CONSTRAINT_DUP_NAME — `ADD CONSTRAINT … CHECK`
     *
     * MySQL 8 accepts `IF NOT EXISTS` on none of those clauses (only MariaDB
     * does), which is why a replay legitimately raises them.
     *
     * `1826` and `3822` were added by the S159 review: like `1050` and `1061`
     * they are NAMED-object collisions — MySQL is reporting "an object with
     * this exact name already exists", which is precisely what a second run of
     * the same file produces, and the name in the message lets an operator see
     * which object it was.
     *
     * ## Deliberately NOT idempotent — a migration author must handle these
     *
     *   - `1062` ER_DUP_ENTRY ("Duplicate entry 'x' for key '…'"). This is what
     *     `ALTER TABLE … ADD UNIQUE` raises when the EXISTING DATA violates the
     *     new constraint — the single most valuable failure a migration can
     *     report. Squelching it to make un-guarded seed `INSERT`s replayable
     *     would hide real data corruption. Write `INSERT IGNORE` /
     *     `INSERT … ON DUPLICATE KEY UPDATE` instead.
     *   - `1068` ER_MULTIPLE_PRI_KEY ("Multiple primary key defined"). MySQL
     *     names no object here, so "the same PK is already there" (a replay)
     *     and "a DIFFERENT primary key already exists" (a genuine conflict
     *     whose intended PK was never created) are indistinguishable. Guard
     *     the `ADD PRIMARY KEY` in the migration instead.
     *
     * Other replay shapes outside this set — `1051` unknown table on a bare
     * `DROP TABLE`, `1304`/`1359` duplicate routine/trigger — are likewise
     * class (b). No file in `migrations/` uses them today; a future one should
     * use the `IF EXISTS` / `IF NOT EXISTS` form those statements DO accept.
     *
     * @var list<int>
     */
    private const IDEMPOTENT_ERROR_CODES = [1050, 1060, 1061, 1091, 1826, 3822];

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
     *     downgraded (e.g. "duplicate column" on a replay). A file that the
     *     ledger already records with a matching checksum is skipped SILENTLY
     *     (no per-file note — only `skipped_count` is bumped) so the steady-state
     *     deploy log stays quiet; see `skipped_count`.
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
            $checksum = self::checksum($sql);

            if (isset($ledger[$name])) {
                if ($ledger[$name] === $checksum) {
                    // Recorded and unchanged — skip WITHOUT executing. Only the
                    // `skipped_count` summary is bumped; deliberately NO per-file
                    // note is pushed into `notes[]`. In steady state (every
                    // deploy after the first) the ledger records all ~79 files,
                    // so a per-file "already applied" note would be echoed IN
                    // FULL by both callers (they print any note that is not an
                    // `isAlreadyAppliedNote()` idempotent-class message) — the
                    // exact per-deploy noise the `skipped_count` collapse exists
                    // to prevent. The single "N skipped (already applied)"
                    // summary line the callers render from `skipped_count` is
                    // enough (SV-4.9 review finding 1).
                    $skippedCount++;
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
     * The single source of truth for "did this migration run FAIL?", expressed
     * as a process exit code (S159).
     *
     * Every caller that owns a process exit status — `bin/phlix migrate` and
     * `scripts/run-migrations.php` — routes through this method so the two
     * operator paths can never disagree about what counts as a failure.
     *
     * Only `errors` decides. Specifically:
     *
     *   - `notes` do NOT fail the run, even when there are dozens of them: an
     *     "already applied" duplicate-column/key error is what a legitimate
     *     replay looks like (class (a) in the class docblock), and failing on
     *     it would redden every re-deploy.
     *   - `skipped_count` does NOT fail the run: it counts work that was
     *     correctly not repeated.
     *   - `applied` being empty does NOT fail the run: on a fully-migrated box
     *     the ledger skips every file, which is the healthy steady state.
     *
     * @param array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int} $result
     *        A {@see run()} result.
     *
     * @return int {@see EXIT_SUCCESS} or {@see EXIT_FAILURE}.
     */
    public static function exitCodeFor(array $result): int
    {
        return $result['errors'] === [] ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
    }

    /**
     * The human-readable verdict for a FAILED run, shared by both operator
     * paths (S159 review findings 3 and 7).
     *
     * Finding 3: `scripts/run-migrations.php` suppressed `"Migrations
     * complete."` on failure while `bin/phlix migrate` still printed
     * `"Migrations complete. (1 file(s), 0 note(s), 1 error(s))"` next to its
     * own exit code 1. The exit contract agreed; the sentence a human reads
     * did not. Both now render THIS string instead, so they cannot drift.
     *
     * Finding 7: the previous wording asserted, unconditionally, that "the
     * schema is HALF-MIGRATED". Nothing checked that, and with an unreachable
     * database it is simply false — so the message misdirected the operator at
     * the exact moment they were debugging.
     *
     * ⚠ The obvious guard — "say it only when `applied` is empty" — does NOT
     * work, and measuring beats assuming: `applied` lists files whose
     * statements were ATTEMPTED, and the connection is resolved lazily per
     * statement, so `DB_PORT=33999` against the real migration set produces
     * `229 error(s) in 100 file(s) attempted`, not zero. The empty case is
     * still special-cased (it is unambiguous), but for everything else this
     * method states only what it can verify and then tells the operator how to
     * tell the two situations apart, rather than picking one for them.
     *
     * @param array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int} $result
     *        A {@see run()} result for which {@see exitCodeFor()} returned
     *        {@see EXIT_FAILURE}.
     */
    public static function failureSummary(array $result): string
    {
        $errorCount = count($result['errors']);
        $fileCount = count($result['applied']);

        $summary = 'Migrations FAILED: ' . $errorCount . ' error(s) in '
            . $fileCount . ' file(s) attempted.';

        if ($fileCount === 0) {
            return $summary . ' No migration file was executed at all, so the'
                . ' schema is unchanged — the failure happened before any'
                . ' statement ran (check that the database is reachable and the'
                . ' credentials are correct).';
        }

        return $summary . ' The failing file(s) were NOT recorded in'
            . ' schema_migrations and will be retried on the next run. Whether'
            . ' the schema is now PARTIALLY MIGRATED depends on the errors'
            . ' above: a bad statement leaves the statements before it applied,'
            . ' while an unreachable or misconfigured database changes nothing'
            . ' at all.';
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
     * raises. Callers use this to collapse such notes into a single
     * "N statements skipped (already applied)" summary line while still
     * printing any other note in full; {@see isExpectedIdempotentError()} uses
     * it to decide class (a) vs class (b).
     *
     * ## The decision is made on the DRIVER ERROR CODE, never on the raw message
     *
     * `Workerman\MySQL\Connection::execute()` (and this project's
     * {@see PhlixMySQLConnection}) rethrow as
     * `"SQL:" . $theWholeStatement . " " . $pdoMessage`, so the message a
     * caller sees CONTAINS THE MIGRATION'S OWN SQL. A plain
     * `str_contains($message, 'already exists')` therefore greps the
     * migration text, and a genuine hard failure was silently reclassified as
     * an idempotent replay. Reproduced against MySQL 8.0.46 with a single
     * statement:
     *
     *     ALTER TABLE zz_no_such_table
     *       ADD COLUMN foo INT COMMENT 'reuse the row if it already exists';
     *
     * → `SQL:ALTER TABLE … 'reuse the row if it already exists'
     *    SQLSTATE[42S02]: Base table or view not found: 1146 Table … doesn't exist`
     *
     * — errno 1146, a hard failure, matched `'already exists'` from the
     * COMMENT, was counted as a skip, exited 0 AND (because class (b) never
     * fired) the file was RECORDED in `schema_migrations`, so it was never
     * retried. That defeated classes (b) and (c) at once and was strictly
     * worse than the pre-S159 behaviour. Any of the old phrases surviving in a
     * `COMMENT`, an `ENUM`/`DEFAULT` value, a string literal or a C-style
     * block comment was enough.
     *
     * So: everything before the LAST `SQLSTATE[` — i.e. the whole `SQL:…`
     * prefix — is stripped first ({@see errorSegment()}), the MySQL errno is
     * parsed out of what remains ({@see driverErrorCode()}), and only
     * {@see IDEMPOTENT_ERROR_CODES} counts as class (a).
     *
     * The message-phrase test below survives ONLY as the fallback for a
     * throwable that carries no PDO error segment at all (a mocked connection
     * in a unit test, or one of the two `PDOException`s this project raises
     * for a missing connection/statement). A message that DOES carry the
     * `SQL:` prefix but no parseable error segment is deliberately treated as
     * a genuine error rather than guessed at.
     *
     * @param string $message An exception message, as stored in the `notes` /
     *        `errors` lists returned by {@see run()}.
     */
    public static function isAlreadyAppliedNote(string $message): bool
    {
        $segment = self::errorSegment($message);
        if ($segment === null) {
            return false;
        }

        $code = self::driverErrorCode($segment);
        if ($code !== null) {
            return in_array($code, self::IDEMPOTENT_ERROR_CODES, true);
        }

        return str_contains($segment, 'Duplicate column name')
            || str_contains($segment, 'Duplicate key name')
            || str_contains($segment, 'Duplicate foreign key constraint name')
            || str_contains($segment, 'Duplicate check constraint name')
            || str_contains($segment, 'check that column/key exists')
            || str_contains($segment, 'already exists');
    }

    /**
     * Strip the `"SQL:" . $statement . " "` prefix that
     * `Workerman\MySQL\Connection::execute()` and
     * {@see PhlixMySQLConnection::query()} prepend, leaving only the driver's
     * own error text.
     *
     * The PDO message always begins with `SQLSTATE[`, and the prefix is
     * prepended, so the LAST occurrence is the real error even if the
     * migration's SQL contains a literal `SQLSTATE[` of its own.
     *
     * @return string|null The driver error segment, or `null` when the message
     *         is SQL-prefixed but carries no recognisable error segment — in
     *         which case the caller must NOT squelch it (a message we cannot
     *         separate from its SQL is never classified as idempotent).
     */
    private static function errorSegment(string $message): ?string
    {
        $pos = strrpos($message, 'SQLSTATE[');
        if ($pos !== false) {
            return substr($message, $pos);
        }

        if (str_starts_with($message, 'SQL:')) {
            return null;
        }

        return $message;
    }

    /**
     * Parse the MySQL error number out of a driver error segment.
     *
     * PDO renders `SQLSTATE[<state>]: <condition>: <errno> <text>`; measured
     * shapes (MySQL 8.0.46, through the project's own connection classes):
     *
     *     SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'x' already exists
     *     SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'v'
     *     SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name 'k'
     *     SQLSTATE[HY000]: General error: 1826 Duplicate foreign key constraint name 'fk'
     *
     * ⚠ The errno cannot be taken from the exception object: both rethrow
     * sites build a fresh `PDOException` with `(int) $e->getCode()` — the
     * SQLSTATE cast to int, e.g. `42` for `42S02` — and never copy
     * `errorInfo`, which is `NULL` on the rethrown instance (verified by
     * execution). The rendered segment is the only place the errno survives.
     *
     * @return int|null `null` when the segment carries no errno (e.g.
     *         `SQLSTATE[HY000] [2002] Connection refused`).
     */
    private static function driverErrorCode(string $segment): ?int
    {
        if (preg_match('/^SQLSTATE\[[^\]]*\]:\s*[^:]*:\s*(\d+)\b/', $segment, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Compute the ledger checksum of a migration file's contents.
     *
     * The hash is taken over a NORMALISED form of the file: full-line `--` /
     * `#` comments and trailing whitespace on each line are stripped before
     * hashing. This means a documentation-only edit to a `.sql` — e.g. keeping
     * the rewrite-class header the `076` protocol asks operators to maintain —
     * does NOT flip the checksum and trigger a spurious one-time re-apply on
     * the next boot (SV-4.9 review finding 3).
     *
     * The normalisation is deliberately narrow and CANNOT mask a real SQL
     * change: it only drops lines that are ENTIRELY a comment (after leading
     * whitespace) and per-line trailing whitespace. Any change to an actual SQL
     * token — including an inline `-- ...` comment appended to a real statement
     * line — is preserved in the hash, so a genuine edit still diverges the
     * checksum and re-applies (safely, migrations are re-run-safe).
     */
    private static function checksum(string $sql): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql);
        if ($lines === false) {
            return md5($sql);
        }

        $kept = [];
        foreach ($lines as $line) {
            // Drop full-line `--` / `#` comments (after any leading whitespace).
            if (preg_match('/^\s*(--|#)/', $line) === 1) {
                continue;
            }
            $kept[] = rtrim($line);
        }

        return md5(implode("\n", $kept));
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
     * Some `ALTER TABLE ... ADD COLUMN` / `ADD INDEX` / `ADD CONSTRAINT`
     * statements legitimately fail on re-runs because the column / index /
     * constraint already exists. MySQL 8 doesn't accept `IF NOT EXISTS` on
     * those clauses (only MariaDB does), so we recognise the matching MySQL
     * error number and downgrade those to notes rather than treating them as
     * failures.
     *
     * The errno has to come out of the rendered message: both connection
     * classes rethrow a fresh `PDOException` whose `errorInfo` is `NULL` and
     * whose `getCode()` is the SQLSTATE cast to int. See
     * {@see isAlreadyAppliedNote()} / {@see driverErrorCode()}.
     */
    private static function isExpectedIdempotentError(Throwable $e): bool
    {
        return self::isAlreadyAppliedNote($e->getMessage());
    }
}
