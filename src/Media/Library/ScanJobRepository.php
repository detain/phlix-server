<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use InvalidArgumentException;
use Phlix\Common\Uuid;
use Workerman\MySQL\Connection;

/**
 * Persistent store for library scan jobs (Step 1.1a).
 *
 * Records the lifecycle of a library scan — `queued` → `running` →
 * `completed`/`failed` — together with its progress counters. This repository
 * is the data layer the 1.1b async worker writes to (claim a queued job,
 * report progress, mark completed/failed) and that the scan-status /
 * scan-history endpoints read from. There is **no behaviour change** in this
 * step: the `claimNext()`, `updateProgress()`, `markCompleted()` and
 * `markFailed()` methods are consumed by the worker landing in 1.1b, so they
 * are deliberately not yet called anywhere in this PR.
 *
 * The job table doubles as the queue transport (there is no Redis / queue
 * library in the stack), so {@see self::claimNext()} performs an atomic
 * conditional UPDATE to guard against a double-claim.
 *
 * Database access is exclusively through the async
 * {@see \Workerman\MySQL\Connection} client with parameterised queries —
 * never PDO/mysqli, never string-interpolated SQL — per the resident-memory
 * (Workerman) runtime rules. The repository is request/worker-scoped via the
 * container, so the only instance state is the injected connection.
 *
 * @package Phlix\Media\Library
 * @since   1.1a (Scan-job data layer)
 */
class ScanJobRepository
{
    /** @var Connection Async MySQL connection used for all queries. */
    private Connection $db;

    /**
     * Allowed scan-job types, mirroring the `type` column in migration 027.
     *
     * `metadata` reuses the same async job queue + status infrastructure as
     * `scan`/`rescan` so the admin UI's scan-status badge/polling shows progress
     * for a background metadata match unchanged. `metadata_refresh` behaves like
     * `metadata` but forces a re-match of already-matched items (migration 081
     * widens the ENUM to admit it).
     *
     * The four fine-grained maintenance ops — `prune` (drop items whose files are
     * gone), `clear_metadata` (reset items to filesystem basics), `clear_artwork`
     * (delete locally cached artwork), and the destructive `delete_all` (remove
     * every item) — reuse the SAME queue and are admitted by migration 084.
     *
     * The column is an ENUM, so this allowlist is the application-level guard
     * mirroring the accepted set of DB values.
     *
     * @var list<string>
     */
    private const ALLOWED_TYPES = [
        'scan',
        'rescan',
        'metadata',
        'metadata_refresh',
        'prune',
        'clear_metadata',
        'clear_artwork',
        'delete_all',
    ];

    /**
     * Counter columns that {@see self::updateProgress()} and
     * {@see self::markCompleted()} may write, mapped from the public
     * `$counts` array keys. Only these keys are honoured; anything else in a
     * caller-supplied array is ignored so the SQL column set stays fixed.
     *
     * `items_failed` (migration 095, S96(f)) is the count of files a scan could not
     * index. It exists because a partially-failed scan was previously indistinguishable
     * from a clean one from the outside: {@see ScanResult} had no failure field, the
     * job row had no failed-file column, and the scanner's own error log went into the
     * unit's `PrivateTmp`. ⚠ NOTE THE ASYMMETRY WITH `items_updated`, which is the
     * PROCESSED-FILE count (the progress numerator the admin UI divides by
     * `items_found`), NOT {@see ScanResult::$updated} — see
     * {@see LibraryScanWorker::scanProgressSink()}.
     *
     * **THE ONE DEFINITION OF `items_added` (review r2 F5).** It is "`media_items` rows
     * this job created". Mid-scan the live sink writes a LOWER BOUND on that number —
     * for music, new **track `media_items`** rows only, because the artist/album container
     * rows are not in the scanner's own `added` tally — and at completion a `rescan`
     * replaces it with the exact all-types row-count delta. Same quantity, coarser then
     * finer, which is why the final stamp is allowed to raise it and not to lower it (see
     * {@see self::MONOTONIC_FINAL_COLUMNS}). This matters to a reader of
     * {@see self::decodeRow()} because the admin SPA renders the column.
     *
     * ⚠ **"track `media_items` rows", not "`music_tracks` rows" (review r3 finding 11).**
     * The two differ in exactly one shape, and it is a shape that occurs in the field: a
     * `music_tracks` row inserted against a PRE-EXISTING `media_items` row (a partial
     * prior scan left the media item but not the track row) is reported `'updated'` by
     * {@see \Phlix\Media\Music\MusicLibraryScanner}, and is correctly NOT counted here —
     * because this counter's subject is the `media_items` row, and no `media_items` row
     * was created. Since this docblock is the single authority for the definition, the
     * loose wording was the whole ambiguity.
     *
     * @var list<string>
     */
    private const COUNTER_COLUMNS = [
        'items_found',
        'items_added',
        'items_updated',
        'items_removed',
        'items_failed',
    ];

    /**
     * Counter columns {@see self::markCompleted()} may only ever RAISE, never lower.
     *
     * Both are cumulative tallies of work a job did, as opposed to `items_found` (the
     * progress DENOMINATOR) and `items_updated` (the progress NUMERATOR), which are
     * absolute readings the final stamp must be able to correct in either direction.
     *
     * ⚠ **WHY, AND WHY THIS SET SHRANK (review r1 LOW-4 → review r2 F5).** r1 found that
     * a `rescan`'s `items_added` could move BACKWARDS at completion, and r2 objected that
     * clamping it takes the maximum of two incommensurable metrics. The arithmetic
     * settles it, so it is written down rather than argued:
     *
     *   `rescanLibrary()` computes `added = after − survivors` with
     *   `survivors = before − removed`, and `after = before + newRows − removed`. Those
     *   reduce to **`added = newRows`** exactly — every `media_items` row the job
     *   created, containers included. The live sink's music value is `new track
     *   media_items rows`, and a track is only counted `'added'` after its own
     *   `media_items` row is minted,
     *   so `liveAdded ≤ newRows` ALWAYS. The two are therefore not rival metrics: the
     *   live one is a **lower bound** on the final one (see
     *   {@see self::COUNTER_COLUMNS} for the single definition), and `GREATEST` picks the
     *   exact value in every normal case.
     *
     * So the clamp is not a routine max — it is a guard for the one shape where the
     * inequality can invert (a concurrent deleter shrinking `after`, or a future change
     * to either metric), where a visible 12 → 3 retraction is strictly worse than
     * reporting the lower bound. `items_failed` is monotonic by nature within a job.
     *
     * **`items_removed` was REMOVED from this set: its clamp was provably inert.** The
     * only writers are `updateProgress()` in the `prune` and `delete_all` branches, and
     * BOTH leave `$finalCounts` empty, so `markCompleted()` never clamps them; the only
     * branch that puts `items_removed` in `$finalCounts` is `rescan`, whose live sink
     * never writes that key (`scanProgressSink()`'s payload is
     * `items_found`/`items_updated`/`items_added`/`items_failed` only). The prior value
     * at clamp time is therefore always the column default 0, and `GREATEST(0, x) = x`.
     * Keeping it implied a protection that could not fire.
     *
     * **No NULL hazard.** `GREATEST(NULL, 5)` is `NULL` in MySQL, but all five `items_*`
     * columns are `int unsigned NOT NULL DEFAULT 0` (verified against
     * `information_schema` on a real 8.0.46 server) and the bound parameter is always
     * `(int) $finalCounts[$column]`, so neither side can be NULL.
     *
     * Costs nothing: one SQL function inside the UPDATE that was already being issued —
     * no extra statement, no read-modify-write, so no race either.
     *
     * @var list<string>
     */
    private const MONOTONIC_FINAL_COLUMNS = [
        'items_added',
        'items_failed',
    ];

    /** @var int Lower bound for {@see self::getHistoryForLibrary()} `$limit`. */
    private const HISTORY_LIMIT_MIN = 1;

    /** @var int Upper bound for {@see self::getHistoryForLibrary()} `$limit`. */
    private const HISTORY_LIMIT_MAX = 100;

    /**
     * @param Connection $db Workerman MySQL connection.
     *
     * @since 1.1a
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Enqueue a new scan job for a library in the `queued` state.
     *
     * @param string $libraryId Target library UUID.
     * @param string $type      Job type: `scan` (incremental), `rescan`
     *                          (non-destructive rescan + prune-removed),
     *                          `metadata` (background match, skip already-matched),
     *                          `metadata_refresh` (force re-match everything),
     *                          `prune` (drop items whose files are gone),
     *                          `clear_metadata` (reset items to filesystem basics),
     *                          `clear_artwork` (delete locally cached artwork) or
     *                          `delete_all` (destructive: remove every item).
     *
     * @return string The newly generated job UUID.
     *
     * @throws InvalidArgumentException When `$type` is not one of
     *                                  {@see self::ALLOWED_TYPES}.
     *
     * @since 1.1a
     */
    public function enqueue(string $libraryId, string $type): string
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid scan-job type "%s"; expected one of: %s', $type, implode(', ', self::ALLOWED_TYPES)),
            );
        }

        $id = $this->generateUuid();

        $this->db->query(
            'INSERT INTO library_scan_jobs (id, library_id, type, status) VALUES (?, ?, ?, ?)',
            [$id, $libraryId, $type, 'queued'],
        );

        return $id;
    }

    /**
     * Insert a job that is ALREADY `running`, for a scan this process is about to
     * execute itself.
     *
     * ## Why this is not `enqueue()` + `claimNext()` (S150)
     *
     * {@see self::claimNext()} claims the OLDEST `queued` row in the whole table, not
     * a specific one. A CLI scan that enqueued its own job and then called
     * `claimNext()` could therefore claim somebody ELSE's queued job — and would in
     * turn leave its own row `queued` for the worker to pick up and run a SECOND,
     * concurrent scan of the same library. Inserting directly as `running` is the
     * only shape that is correct for a self-executing caller: the row is never
     * `queued`, so {@see self::claimNext()} can never see it.
     *
     * ⚠ **This bypasses the queue on purpose, and it is only correct for a caller
     * that runs the work IN THIS PROCESS.** Do not use it to hand work to the worker
     * — that is {@see self::enqueue()}.
     *
     * ⚠ **A row written here is still subject to {@see self::reapStaleJobs()}**, which
     * the single-consumer worker calls at boot and which fails EVERY `running` row
     * without scoping or an age guard. A `phlix-server` restart during a long CLI
     * scan will therefore mark this row `failed` while the CLI keeps running. Bounding
     * that needs per-job worker ownership, i.e. a schema change deliberately out of
     * S150/S151's scope.
     *
     * ⚠ **The consequence is NOT only a false badge.** A reaped row is terminal, so
     * {@see self::hasActiveJobForLibrary()} then reports the library idle while the CLI
     * scan is still running — and a second `library:scan`, or an admin-UI "Scan" click
     * (which has no active-job check at all), will start a genuinely concurrent scan
     * over the same library. The scan already in flight is not itself lost or stuck:
     * nothing re-reads the job row mid-scan, and the CLI's own `markCompleted()` will
     * overwrite the reaped `failed` with `completed` when it finishes.
     *
     * ## ⚠ `$jobId` exists so a caller can know the id BEFORE the row exists (S151)
     *
     * {@see \Phlix\Console\Commands\LibraryScanCommand} installs its SIGTERM/SIGINT/
     * SIGHUP and shutdown handlers *before* calling this, and those handlers can only
     * fail a row whose id they already hold. If this method minted the id, there would
     * be a window in which the row is committed and externally visible while
     * `$this->jobId` is still `''` — a signal landing there strands the row `running`
     * forever, which is the exact state S150 exists to abolish. Passing the id in
     * removes the window entirely: the id is known before the INSERT is issued, so
     * every committed row has a handler that can name it.
     *
     * @param string      $libraryId Target library UUID.
     * @param string      $type      Job type; must be one of {@see self::ALLOWED_TYPES}.
     * @param string|null $jobId     Pre-minted row id (see above). NULL mints one here.
     *
     * @return string The job UUID (the one passed in, when one was).
     *
     * @throws InvalidArgumentException When `$type` is not one of
     *                                  {@see self::ALLOWED_TYPES}.
     *
     * @since 0.36.0 (S150 — CLI scans visible in the admin UI)
     */
    public function startRunning(string $libraryId, string $type, ?string $jobId = null): string
    {
        $this->assertAllowedType($type);

        $id = ($jobId === null || $jobId === '') ? $this->generateUuid() : $jobId;

        $this->db->query(
            'INSERT INTO library_scan_jobs (id, library_id, type, status, started_at)'
            . ' VALUES (?, ?, ?, ?, NOW())',
            [$id, $libraryId, $type, 'running'],
        );

        return $id;
    }

    /**
     * Insert a `running` job for this library **only if the library has no
     * `queued`/`running` job**, in ONE statement.
     *
     * ## Why this exists — the check-then-insert race (S151, review finding 5)
     *
     * {@see self::hasActiveJobForLibrary()} followed by {@see self::startRunning()} is
     * a TOCTOU: two `php bin/phlix library:scan <id>` invocations started together both
     * read "no active job" and both insert a `running` row, which is precisely the
     * two-scanners-over-one-library race the refusal exists to prevent. This method
     * folds the check into the INSERT so the decision and the write cannot be
     * interleaved.
     *
     * `INSERT ... SELECT ... WHERE NOT EXISTS` takes locking reads on the
     * `idx_lsj_library` range it inspects (migration 027), so a concurrent inserter for
     * the SAME `library_id` either blocks until the winner commits and then sees its
     * row — inserting nothing — or the pair deadlocks. **The deadlock is retried once**
     * (see {@see self::isRetryableLockError()}): after the retry the winner's row is
     * committed, so the loser's `NOT EXISTS` is false and it correctly refuses. Without
     * that retry a deadlock would surface as a thrown INSERT and the caller would
     * degrade to "run untracked", i.e. both scans would run — the hole this closes.
     *
     * ⚠ **`Connection::query()` returns `lastInsertId()` for an INSERT that affected
     * rows, and `null` when it affected none.** `library_scan_jobs.id` is a CHAR(36)
     * UUID with no AUTO_INCREMENT, so a SUCCESSFUL insert here returns the string
     * `'0'` — which is FALSY. Test `!== null`, never truthiness; `if (!$result)` reads
     * a successful insert as a refusal.
     *
     * @param string      $libraryId Target library UUID.
     * @param string      $type      Job type; must be one of {@see self::ALLOWED_TYPES}.
     * @param string|null $jobId     Pre-minted row id ({@see self::startRunning()}).
     *
     * @return string|null The job UUID, or NULL when the library already had a
     *                     `queued`/`running` job and nothing was inserted.
     *
     * @throws InvalidArgumentException When `$type` is not one of
     *                                  {@see self::ALLOWED_TYPES}.
     *
     * @since 0.36.0 (S151 — closes the S150 check-then-insert race)
     */
    public function startRunningIfIdle(string $libraryId, string $type, ?string $jobId = null): ?string
    {
        $this->assertAllowedType($type);

        $id = ($jobId === null || $jobId === '') ? $this->generateUuid() : $jobId;

        $sql = 'INSERT INTO library_scan_jobs (id, library_id, type, status, started_at)'
            . " SELECT ?, ?, ?, 'running', NOW() FROM DUAL WHERE NOT EXISTS ("
            . '    SELECT 1 FROM library_scan_jobs active'
            . "     WHERE active.library_id = ? AND active.status IN ('queued', 'running')"
            . ' )';
        $params = [$id, $libraryId, $type, $libraryId];

        try {
            $result = $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            if (!$this->isRetryableLockError($e)) {
                throw $e;
            }
            // The competing inserter has now committed (or rolled back). Re-running the
            // guarded statement therefore yields the CORRECT answer rather than a
            // thrown error the caller would have to interpret as "unknown".
            $result = $this->db->query($sql, $params);
        }

        return $result === null ? null : $id;
    }

    /**
     * Mint a job id without writing anything.
     *
     * Exists so a caller can install its termination handlers — which must be able to
     * name the row they will fail — BEFORE the row is created. See
     * {@see self::startRunning()} for why that ordering is load-bearing.
     *
     * @return string A fresh UUID, not yet present in `library_scan_jobs`.
     *
     * @since 0.36.0 (S151)
     */
    public function newJobId(): string
    {
        return $this->generateUuid();
    }

    /**
     * Whether ANY `queued`/`running` job exists for this library.
     *
     * ⚠ **This deliberately does NOT ask "is the library's NEWEST job active?"**
     * (review finding 4). `queued_at` is a `TIMESTAMP` with 1-second granularity
     * (migration 027), so two rows written in the same second tie and
     * `ORDER BY queued_at DESC LIMIT 1` picks between them arbitrarily — a `completed`
     * row can beat a `queued` one and the refusal is then silently bypassed. The same
     * shape occurs without any tie whenever a NEWER terminal job exists alongside an
     * older live one (a `metadata` job that completed while a CLI `rescan` from ten
     * minutes earlier is still `running`): "newest" reports idle for a library that is
     * actively being scanned. Existence over the whole set has neither hole.
     *
     * ⚠ It follows that this can disagree with the admin Libraries badge, which DOES
     * show the newest row. That is intended: the badge answers "what happened last",
     * this answers "is it safe to start another scan", and only the second may gate a
     * write. {@see self::startRunningIfIdle()} re-asserts the same predicate inside the
     * INSERT, so this is a cheap early message, not the guarantee.
     *
     * @param string $libraryId Target library UUID.
     *
     * @return bool TRUE when the library has at least one `queued`/`running` job.
     *
     * @since 0.36.0 (S150)
     */
    public function hasActiveJobForLibrary(string $libraryId): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM library_scan_jobs WHERE library_id = ? AND status IN ('queued', 'running') LIMIT 1",
            [$libraryId],
        );

        return is_array($rows) && $rows !== [];
    }

    /**
     * Guard a caller-supplied job type against the ENUM allowlist.
     *
     * @param string $type Candidate job type.
     *
     * @throws InvalidArgumentException When `$type` is not one of
     *                                  {@see self::ALLOWED_TYPES}.
     */
    private function assertAllowedType(string $type): void
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid scan-job type "%s"; expected one of: %s', $type, implode(', ', self::ALLOWED_TYPES)),
            );
        }
    }

    /**
     * Whether a thrown database error is an InnoDB deadlock / lock-wait timeout, i.e.
     * one where re-running the SAME statement is expected to succeed.
     *
     * 1213 `ER_LOCK_DEADLOCK` (SQLSTATE 40001) and 1205 `ER_LOCK_WAIT_TIMEOUT` are the
     * only two errors InnoDB raises that mean "your transaction was rolled back, try
     * again"; every other error is a real fault and must propagate. The match is on the
     * message text as well as the code because the connection wraps PDO and rethrows
     * with the driver code in either position depending on the failure path.
     *
     * @param \Throwable $e The error thrown by the INSERT.
     *
     * @return bool TRUE when one retry is warranted.
     */
    private function isRetryableLockError(\Throwable $e): bool
    {
        $message = $e->getMessage();
        if (str_contains($message, '1213') || str_contains($message, '1205')) {
            return true;
        }

        return stripos($message, 'deadlock') !== false
            || stripos($message, 'lock wait timeout') !== false
            || stripos($message, 'try restarting transaction') !== false;
    }

    /**
     * Atomically claim the oldest `queued` job and move it to `running`.
     *
     * Picks the oldest queued job id (by `queued_at`), then issues a
     * conditional UPDATE that flips it to `running` and stamps `started_at`
     * only while it is still `queued`. The claim is honoured only when the
     * UPDATE actually changed a row, so a second concurrent caller that lost
     * the race observes zero affected rows and is treated as "nothing
     * claimed" (a safety net even though the worker runs `count:1`).
     *
     * @return array<string, mixed>|null The decoded claimed job row, or null
     *                                    when no job was queued / the claim
     *                                    lost the race.
     *
     * @since 1.1a
     */
    public function claimNext(): ?array
    {
        $rows = $this->db->query(
            "SELECT id FROM library_scan_jobs WHERE status = 'queued' ORDER BY queued_at ASC LIMIT 1",
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $candidate = $rows[0];
        if (!is_array($candidate) || !is_string($candidate['id'] ?? null)) {
            return null;
        }
        $jobId = $candidate['id'];

        // Conditional UPDATE: only flip a row that is still `queued`. The
        // Workerman MySQL client returns the affected-row count for an UPDATE
        // (see Connection::query()), so a result < 1 means another caller won
        // the race — treat that as "nothing claimed".
        $affected = $this->db->query(
            "UPDATE library_scan_jobs SET status = 'running', started_at = NOW()"
            . " WHERE id = ? AND status = 'queued'",
            [$jobId],
        );

        if (!is_int($affected) || $affected < 1) {
            return null;
        }

        return $this->findById($jobId);
    }

    /**
     * Update the progress counters (and optional current path) of a job.
     *
     * Only the supplied counter keys (`items_found`, `items_added`,
     * `items_updated`, `items_removed`, `items_failed`) are written; unknown keys
     * are ignored. When neither a recognised counter nor `$currentPath` is supplied
     * the call is a no-op (no SQL is issued).
     *
     * @param string                     $jobId       Job UUID.
     * @param array<string, int|string> $counts      Map of counter column →
     *                                                new value. Values are
     *                                                cast to int.
     * @param string|null                $currentPath Optional progress hint;
     *                                                passing null leaves the
     *                                                column untouched.
     *
     * @since 1.1a
     */
    public function updateProgress(string $jobId, array $counts, ?string $currentPath = null): void
    {
        $assignments = [];
        $params      = [];

        foreach (self::COUNTER_COLUMNS as $column) {
            if (array_key_exists($column, $counts)) {
                $assignments[] = $column . ' = ?';
                $params[]      = (int) $counts[$column];
            }
        }

        if ($currentPath !== null) {
            $assignments[] = 'current_path = ?';
            $params[]      = $currentPath;
        }

        if ($assignments === []) {
            return;
        }

        $params[] = $jobId;

        $this->db->query(
            'UPDATE library_scan_jobs SET ' . implode(', ', $assignments) . ' WHERE id = ?',
            $params,
        );
    }

    /**
     * Mark a job as `completed`, stamping `completed_at` and optionally
     * writing the final counter values.
     *
     * The cumulative counters in {@see self::MONOTONIC_FINAL_COLUMNS} are written as
     * `GREATEST(<column>, ?)` so a completion stamp can only ever RAISE what the live
     * progress sink already observed — see that constant for why (review r1 LOW-4).
     * `items_found`/`items_updated` are written verbatim: they are the progress
     * denominator/numerator, i.e. absolute readings rather than tallies.
     *
     * @param string                     $jobId       Job UUID.
     * @param array<string, int|string>  $finalCounts Optional final counter
     *                                                values; only recognised
     *                                                counter keys are written.
     *
     * @since 1.1a
     */
    public function markCompleted(string $jobId, array $finalCounts = []): void
    {
        $assignments = ["status = 'completed'", 'completed_at = NOW()'];
        $params      = [];

        foreach (self::COUNTER_COLUMNS as $column) {
            if (array_key_exists($column, $finalCounts)) {
                $assignments[] = in_array($column, self::MONOTONIC_FINAL_COLUMNS, true)
                    ? $column . ' = GREATEST(' . $column . ', ?)'
                    : $column . ' = ?';
                $params[]      = (int) $finalCounts[$column];
            }
        }

        $params[] = $jobId;

        $this->db->query(
            'UPDATE library_scan_jobs SET ' . implode(', ', $assignments) . ' WHERE id = ?',
            $params,
        );
    }

    /**
     * Mark a job as `failed`, recording the error message and stamping
     * `completed_at`.
     *
     * ⚠ **NO FINAL COUNTERS, BY NECESSITY — a failed job keeps whatever the live
     * progress sink last wrote (review r1 LOW-7).** The counters are deliberately left
     * alone rather than zeroed: there is no {@see ScanResult} to read at this point (the
     * throw destroyed it), and the reaper path — {@see self::reapStaleJobs()}, which is
     * how a job killed by a RESTART actually ends up `failed` — never runs in the
     * process that owned the scan at all. What survives is therefore the truth as of the
     * last throttled progress write, and how good that is depends on the library type:
     *
     *  - MUSIC: accurate to within `LibraryScanWorker::PROGRESS_WRITE_EVERY` files. This
     *    is the case from the live incident (a 4 h scan killed by a restart reporting
     *    `items_added: 0`) and S96(b) fixed it.
     *  - VIDEO / PHOTO / BOOK / AUDIOBOOK: still `items_added: 0`. Those paths go
     *    through {@see MediaScanner::scan()}, which knows its added count only when a
     *    whole path is finished, so the 3-argument sink streams no counters and only
     *    `markCompleted()` can fill them in — which a killed job never reaches. Closing
     *    that needs a per-file OUTCOME in `MediaScanner`'s `$onFile` contract (it
     *    currently reports the path only); tracked as a follow-up, deliberately not
     *    redesigned here.
     *
     * @param string $jobId Job UUID.
     * @param string $error Failure message stored in the `error` column.
     *
     * @since 1.1a
     */
    public function markFailed(string $jobId, string $error): void
    {
        $this->db->query(
            "UPDATE library_scan_jobs SET status = 'failed', error = ?, completed_at = NOW() WHERE id = ?",
            [$error, $jobId],
        );
    }

    /**
     * Fail every job still marked `running`, stamping the error + `completed_at`.
     *
     * The single `count:1` {@see LibraryScanWorker} is the only consumer of this
     * QUEUE and drains one job at a time. A row it did not claim would otherwise sit
     * `running` forever after a restart/crash of the process that owned it, keeping a
     * UI spinner alive. Call this once at worker startup to reap them.
     *
     * ⚠ **UNSCOPED BY DESIGN. IT IS NO LONGER TRUE THAT EVERY `running` ROW AT WORKER
     * BOOT IS ORPHANED (S150/S151).** This docblock previously said the `count:1`
     * invariant made a live `running` row impossible on a fresh start. S150 falsified
     * that: {@see self::startRunning()} lets `php bin/phlix library:scan` insert a
     * `running` row for a scan it executes IN ITS OWN PROCESS, which the worker never
     * claims and knows nothing about. A `phlix-server` restart during such a scan
     * reaps a row whose scan is still running. Consequences, both real:
     *
     *   * the badge says `failed` while the scan is healthy — and it is then NOT
     *     re-corrected until the CLI finishes and `markCompleted()` overwrites it;
     *   * {@see self::hasActiveJobForLibrary()} reports the library idle, so a second
     *     CLI scan (or an admin-UI "Scan", which has no active-job check at all) can
     *     start a genuinely concurrent scan over the same library.
     *
     * There is no `library_id` filter and no age guard: this fails EVERY `running` row
     * in the table. Calling it from anywhere that is not a single-consumer worker's
     * startup — an HTTP handler, a second concurrently-draining worker process, a
     * `count > 1` fork — fails a job that IS still running, while its scan carries on
     * unaware (nothing re-reads the job row mid-scan). {@see LibraryScanWorker::start()}
     * documents why an age guard was rejected rather than added. Bounding the radius
     * correctly needs per-job worker ownership (an owner id / heartbeat column), which
     * is a schema change outside S150/S151.
     *
     * ## S77 added an OPTIONAL age bound — the default is unchanged
     *
     * The paragraph above is the contract for `$olderThanSeconds === null`, and
     * {@see LibraryScanWorker::start()} still calls it that way: at boot, "reap
     * everything running" is the only thing that closes the spinner-forever
     * hang, because the reaper does not run again and an orphan younger than
     * any threshold would never be reaped at all.
     *
     * The admin `reap-scan-jobs` maintenance task is the opposite situation —
     * it runs on demand, repeatedly, while the server is live — so reaping
     * every `running` row would fail scans that are healthy. It passes an age,
     * which adds `started_at < NOW() - INTERVAL n SECOND`. That bound is
     * WEAKER than per-job ownership would be (there is still no heartbeat
     * column, so a long scan looks old rather than alive), which is why
     * {@see \Phlix\Admin\Maintenance\MaintenanceTaskRunner::MIN_SCAN_JOB_AGE_SECONDS}
     * imposes a six-hour FLOOR on what a caller may ask for, derived from the
     * 4 h 09 m production music scan recorded in `LibraryScanWorker::start()`.
     *
     * A row with a NULL `started_at` is never age-reaped: it was never claimed,
     * so it has no age, and treating "unknown" as "old" is the wrong direction.
     *
     * @param string   $error             Failure message stored on each reaped row.
     * @param int|null $olderThanSeconds  NULL reaps EVERY `running` row (the boot
     *        catch-up). An integer reaps only rows claimed longer ago than that.
     *
     * @return int Number of rows reaped.
     *
     * @since 0.35.0
     */
    public function reapStaleJobs(string $error, ?int $olderThanSeconds = null): int
    {
        $ageClause = '';
        if ($olderThanSeconds !== null) {
            // Interpolated, not bound: `Workerman\MySQL\Connection` binds every
            // value as a string and MySQL will not accept `INTERVAL '600'
            // SECOND`. The value is an already-cast, non-negative `int`, so it
            // cannot carry injection.
            $ageClause = sprintf(
                ' AND started_at IS NOT NULL AND started_at < (NOW() - INTERVAL %d SECOND)',
                max(0, $olderThanSeconds),
            );
        }

        $affected = $this->db->query(
            "UPDATE library_scan_jobs SET status = 'failed', error = ?, completed_at = NOW()"
            . " WHERE status = 'running'" . $ageClause,
            [$error],
        );

        return is_int($affected) ? $affected : 0;
    }

    /**
     * Fetch a single job by id.
     *
     * @param string $jobId Job UUID.
     *
     * @return array<string, mixed>|null The decoded job row, or null when no
     *                                   such job exists.
     *
     * @since 1.1a
     */
    public function findById(string $jobId): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM library_scan_jobs WHERE id = ?',
            [$jobId],
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        return $this->decodeRow($row);
    }

    /**
     * Fetch the most-recent job (any status) for a library.
     *
     * Powers `GET .../scan-status` in 1.1b.
     *
     * @param string $libraryId Library UUID.
     *
     * @return array<string, mixed>|null The decoded latest job row, or null
     *                                   when the library has no jobs.
     *
     * @since 1.1a
     */
    public function getLatestForLibrary(string $libraryId): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM library_scan_jobs WHERE library_id = ? ORDER BY queued_at DESC LIMIT 1',
            [$libraryId],
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        return $this->decodeRow($row);
    }

    /**
     * Fetch the most-recent jobs for a library, newest first.
     *
     * Powers `GET .../scan-history` in 1.1b. `$limit` is clamped to the
     * inclusive range [1, 100] so a caller cannot request an unbounded or
     * nonsensical page size.
     *
     * @param string $libraryId Library UUID.
     * @param int    $limit     Desired row cap; clamped to [1, 100].
     *
     * @return list<array<string, mixed>> Decoded job rows, newest first.
     *
     * @since 1.1a
     */
    public function getHistoryForLibrary(string $libraryId, int $limit = 20): array
    {
        $limit = max(self::HISTORY_LIMIT_MIN, min(self::HISTORY_LIMIT_MAX, $limit));

        $rows = $this->db->query(
            'SELECT * FROM library_scan_jobs WHERE library_id = ? ORDER BY queued_at DESC LIMIT ?',
            [$libraryId, $limit],
        );

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $this->decodeRow($row);
            }
        }

        return $out;
    }

    /**
     * Return statistics about currently-running scan jobs.
     *
     * F6: Back the /admin/health/jobs endpoint alongside
     * {@see \Phlix\Media\Transcoding\TranscodeManager::getTranscodeJobStats()}.
     *
     * @return array{running: int, oldest_age_seconds: int|null, oldest_started_at: string|null}
     *         running: Number of jobs currently in `running` state.
     *         oldest_age_seconds: Seconds since the oldest running job started, or null if none.
     *         oldest_started_at: ISO-8601 timestamp of the oldest running job, or null.
     *
     * @since F6
     */
    public function getRunningJobStats(): array
    {
        $result = $this->db->query(
            "SELECT id, started_at FROM library_scan_jobs WHERE status = 'running' ORDER BY started_at ASC"
        );

        if (!is_array($result) || count($result) === 0) {
            return [
                'running' => 0,
                'oldest_age_seconds' => null,
                'oldest_started_at' => null,
            ];
        }

        $oldestRow = is_array($result[0]) ? $result[0] : [];
        $startedAt = is_string($oldestRow['started_at'] ?? null)
            ? strtotime((string) $oldestRow['started_at'])
            : false;

        return [
            'running' => count($result),
            'oldest_age_seconds' => $startedAt !== false ? time() - $startedAt : null,
            'oldest_started_at' => $startedAt !== false
                ? date('c', $startedAt)
                : null,
        ];
    }

    /**
     * Defensively decode a raw DB row into a typed associative array.
     *
     * Integer counters are cast to int, the `id`/`library_id`/`type`/`status`
     * fields are normalised to strings, and the nullable text/timestamp
     * columns are preserved as a string or null. Mirrors the null-safety of
     * {@see \Phlix\Admin\SettingsRepository::getOverride()}.
     *
     * ⚠ **WHAT THE COUNTERS MEAN, since this array IS the admin API payload**
     * (`GET /api/v1/libraries/{id}/scan-status`, rendered by the SPA — review r2 F5 asked
     * for this to be stated where a consumer reads it):
     *
     *  - `items_found`   files the walk discovered — the progress DENOMINATOR;
     *  - `items_updated` files PROCESSED so far — the progress NUMERATOR, **not**
     *    {@see ScanResult::$updated}. The SPA computes
     *    `items_updated / items_found` as the percentage;
     *  - `items_added`   `media_items` rows this job created. While the job runs this is a
     *    LOWER BOUND (music streams new tracks only, not the artist/album containers); at
     *    completion a `rescan` raises it to the exact all-types delta. It never goes down
     *    ({@see self::MONOTONIC_FINAL_COLUMNS});
     *  - `items_removed` rows pruned because their file is gone from disk;
     *  - `items_failed`  files the scan READ and could not index (errors only — never a
     *    policy skip, and never an unchanged file).
     *
     * @param array<array-key, mixed> $row Raw row as returned by the driver.
     *
     * @return array<string, mixed> The decoded job row.
     */
    private function decodeRow(array $row): array
    {
        return [
            'id'            => is_string($row['id'] ?? null) ? $row['id'] : '',
            'library_id'    => is_string($row['library_id'] ?? null) ? $row['library_id'] : '',
            'type'          => is_string($row['type'] ?? null) ? $row['type'] : 'scan',
            'status'        => is_string($row['status'] ?? null) ? $row['status'] : 'queued',
            'items_found'   => $this->intColumn($row['items_found'] ?? null),
            'items_added'   => $this->intColumn($row['items_added'] ?? null),
            'items_updated' => $this->intColumn($row['items_updated'] ?? null),
            'items_removed' => $this->intColumn($row['items_removed'] ?? null),
            'items_failed'  => $this->intColumn($row['items_failed'] ?? null),
            'current_path'  => $this->nullableString($row['current_path'] ?? null),
            'error'         => $this->nullableString($row['error'] ?? null),
            'queued_at'     => $this->nullableString($row['queued_at'] ?? null),
            'started_at'    => $this->nullableString($row['started_at'] ?? null),
            'completed_at'  => $this->nullableString($row['completed_at'] ?? null),
        ];
    }

    /**
     * Normalise a raw column value to a string, or null when it is SQL NULL.
     *
     * @param mixed $value Raw column value.
     *
     * @return string|null The string value, or null when null/non-scalar.
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Coerce a raw counter column value to a non-negative int, defaulting to
     * 0 for null / non-numeric input (counters are `INT UNSIGNED` columns).
     *
     * @param mixed $value Raw column value.
     *
     * @return int The integer value, or 0 when null/non-numeric.
     */
    private function intColumn(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Generate a UUID v4 string. Mirrors the local `generateUuid()` helper
     * duplicated across the codebase (per the repo's no-UUID-library rule).
     *
     * @return string Formatted UUID string.
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }
}
