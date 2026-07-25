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
     * @var list<string>
     */
    private const COUNTER_COLUMNS = [
        'items_found',
        'items_added',
        'items_updated',
        'items_removed',
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
                $assignments[] = $column . ' = ?';
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
     * queue and drains one job at a time, so on a fresh worker start NO job can
     * legitimately be `running` — any such row is orphaned by a restart/crash of
     * the process that owned it and would otherwise sit `running` forever (and
     * keep a UI spinner alive). Call this once at worker startup to reap them.
     *
     * ⚠ **UNSCOPED BY DESIGN, AND ONLY SAFE FROM THAT ONE CALL SITE (S96(c)).** There
     * is no `library_id` filter and no age guard: this fails EVERY `running` row in
     * the table. Calling it from anywhere that is not a single-consumer worker's
     * startup — an HTTP handler, a second concurrently-draining worker process, a
     * `count > 1` fork — fails a job that IS still running, while its scan carries on
     * unaware (nothing re-reads the job row mid-scan). {@see LibraryScanWorker::start()}
     * documents the exact invariant this rests on and why an age guard was rejected
     * rather than added.
     *
     * @param string $error Failure message stored on each reaped row.
     *
     * @return int Number of rows reaped.
     *
     * @since 0.35.0
     */
    public function reapStaleJobs(string $error): int
    {
        $affected = $this->db->query(
            "UPDATE library_scan_jobs SET status = 'failed', error = ?, completed_at = NOW()"
            . " WHERE status = 'running'",
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
