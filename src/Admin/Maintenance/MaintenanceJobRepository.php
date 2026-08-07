<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Admin\Maintenance;

use Phlix\Common\Uuid;
use Workerman\MySQL\Connection;

/**
 * Persistent store for the `maintenance_jobs` queue (S77, migration 098).
 *
 * Deliberately shaped like {@see \Phlix\Media\Library\ScanJobRepository}: the
 * same `queued → running → completed|failed` vocabulary and the same atomic
 * conditional-UPDATE claim, because the table doubles as the queue transport
 * (there is no Redis or queue library in this stack).
 *
 * Database access is exclusively through the async
 * {@see Connection} client with parameterised queries — never PDO/mysqli,
 * never interpolated SQL — per the resident-memory runtime rules. The only
 * instance state is the injected connection, so nothing accumulates across
 * requests in a long-lived worker.
 *
 * @package Phlix\Admin\Maintenance
 * @since 1.9
 */
class MaintenanceJobRepository
{
    /** How many rows {@see self::recent()} returns when the caller gives no limit. */
    public const DEFAULT_RECENT_LIMIT = 20;

    /** Hard ceiling on {@see self::recent()}'s limit, so a client cannot ask for the whole table. */
    public const MAX_RECENT_LIMIT = 200;

    /**
     * @param Connection $db Async MySQL connection used for every query here.
     */
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Enqueue a task, UNLESS an identical one is already pending.
     *
     * ## Why the "already pending" check exists
     *
     * Both queued tasks are expensive and idempotent-but-wasteful: a second
     * `storage-snapshot` re-runs `du -sb` over every vault root for a result
     * that would be identical, and a second `dedupe-paths` re-scans the whole
     * `media_items` table. The admin Tasks page is a row of buttons, so a
     * double click is the expected input, not an edge case.
     *
     * ## The residual race, stated rather than papered over
     *
     * The check is a SELECT followed by an INSERT, not an atomic upsert. Two
     * requests interleaving between the two statements can both insert. MySQL
     * offers no partial unique index (`UNIQUE (task) WHERE status IN
     * ('queued','running')` is not expressible), so closing it properly needs
     * either a sentinel column or an advisory lock. The consequence of losing
     * the race is one redundant run of an idempotent task — strictly less bad
     * than the machinery required to prevent it — so it is accepted and
     * documented. {@see MaintenanceQueueWorker} runs one job at a time, so the
     * two never execute concurrently.
     *
     * @param string               $task        A member of {@see MaintenanceTask::ALL}.
     * @param array<string, mixed> $params      Task parameters, stored as JSON.
     * @param string|null          $requestedBy `users.id` of the admin, if known.
     *
     * @return array{job: array<string, mixed>, created: bool} The pending job
     *         row, and whether THIS call created it.
     */
    public function enqueue(string $task, array $params = [], ?string $requestedBy = null): array
    {
        $pending = $this->findPending($task);
        if ($pending !== null) {
            return ['job' => $pending, 'created' => false];
        }

        $id = Uuid::v4();
        $paramsJson = $params === [] ? null : json_encode($params);

        $this->db->query(
            'INSERT INTO maintenance_jobs (id, task, status, params_json, requested_by)'
            . ' VALUES (?, ?, ?, ?, ?)',
            [$id, $task, 'queued', is_string($paramsJson) ? $paramsJson : null, $requestedBy],
        );

        $row = $this->findById($id);

        // A findById() miss here would mean the INSERT did not land. Rather than
        // return null and force every caller to handle a state that should be
        // impossible, synthesise the row we just wrote — the caller needs an id
        // to poll with more than it needs a perfect echo of the columns.
        return [
            'job' => $row ?? [
                'id' => $id,
                'task' => $task,
                'status' => 'queued',
                'params' => $params,
                'result' => null,
                'error' => null,
                'requested_by' => $requestedBy,
                'queued_at' => null,
                'started_at' => null,
                'completed_at' => null,
            ],
            'created' => true,
        ];
    }

    /**
     * The oldest `queued` or `running` job for `$task`, if any.
     *
     * @return array<string, mixed>|null
     */
    public function findPending(string $task): ?array
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            "SELECT * FROM maintenance_jobs WHERE task = ? AND status IN ('queued', 'running')"
            . ' ORDER BY queued_at ASC LIMIT 1',
            [$task],
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $row = $rows[0];

        return is_array($row) ? $this->decodeRow($row) : null;
    }

    /**
     * Atomically claim the oldest `queued` job.
     *
     * The conditional UPDATE is what makes a second drainer safe: the Workerman
     * MySQL client returns the affected-row count for an UPDATE, so a result
     * below 1 means another caller won the race and this call claimed nothing.
     * Mirrors {@see \Phlix\Media\Library\ScanJobRepository::claimNext()}.
     *
     * @return array<string, mixed>|null The claimed job, now `running`.
     */
    public function claimNext(): ?array
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            "SELECT id FROM maintenance_jobs WHERE status = 'queued' ORDER BY queued_at ASC LIMIT 1",
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $candidate = $rows[0];
        if (!is_array($candidate) || !is_string($candidate['id'] ?? null)) {
            return null;
        }
        $jobId = $candidate['id'];

        /** @var mixed $affected */
        $affected = $this->db->query(
            "UPDATE maintenance_jobs SET status = 'running', started_at = NOW()"
            . " WHERE id = ? AND status = 'queued'",
            [$jobId],
        );

        if (!is_int($affected) || $affected < 1) {
            return null;
        }

        return $this->findById($jobId);
    }

    /**
     * Mark a job finished successfully, recording what it did.
     *
     * @param string               $jobId  Job UUID.
     * @param array<string, mixed> $result Summary written to `result_json`.
     */
    public function markCompleted(string $jobId, array $result = []): void
    {
        $json = $result === [] ? null : json_encode($result);

        $this->db->query(
            "UPDATE maintenance_jobs SET status = 'completed', result_json = ?, completed_at = NOW()"
            . " WHERE id = ? AND status = 'running'",
            [is_string($json) ? $json : null, $jobId],
        );
    }

    /**
     * Mark a job failed.
     *
     * The message is truncated CHARACTER-wise. A byte-wise cut can split a
     * multi-byte sequence, and MySQL rejects the resulting invalid UTF-8 with
     * error 1366 — the same landmine `TranscodeManager::readFailureReason()`
     * documents for `transcode_jobs.error`.
     */
    public function markFailed(string $jobId, string $error): void
    {
        $this->db->query(
            "UPDATE maintenance_jobs SET status = 'failed', error = ?, completed_at = NOW()"
            . ' WHERE id = ?',
            [mb_substr($error, 0, 2000), $jobId],
        );
    }

    /**
     * Fetch one job.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $jobId): ?array
    {
        /** @var mixed $rows */
        $rows = $this->db->query('SELECT * FROM maintenance_jobs WHERE id = ?', [$jobId]);

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $row = $rows[0];

        return is_array($row) ? $this->decodeRow($row) : null;
    }

    /**
     * The most recently queued jobs, newest first.
     *
     * @param int         $limit Clamped to `[1, MAX_RECENT_LIMIT]`.
     * @param string|null $task  Restrict to one task, or null for all.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = self::DEFAULT_RECENT_LIMIT, ?string $task = null): array
    {
        $limit = max(1, min(self::MAX_RECENT_LIMIT, $limit));

        // The limit is an already-clamped integer interpolated into the SQL
        // rather than bound: `Workerman\MySQL\Connection` binds a bound value as
        // a string, and MySQL rejects `LIMIT '20'`. It cannot carry injection —
        // `max()`/`min()` over an `int` parameter leaves an int.
        $sql = 'SELECT * FROM maintenance_jobs';
        $params = [];
        if ($task !== null) {
            $sql .= ' WHERE task = ?';
            $params[] = $task;
        }
        $sql .= ' ORDER BY queued_at DESC LIMIT ' . $limit;

        /** @var mixed $rows */
        $rows = $this->db->query($sql, $params);
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
     * Fail every `running` row — the boot catch-up.
     *
     * A `running` row whose worker died is never claimed again (only `queued`
     * rows are), so without this it blocks {@see self::enqueue()}'s
     * already-pending check forever and the admin's button silently stops
     * working. Called once from {@see MaintenanceQueueWorker::start()}.
     *
     * @return int Rows reaped.
     */
    public function reapRunning(string $error): int
    {
        /** @var mixed $affected */
        $affected = $this->db->query(
            "UPDATE maintenance_jobs SET status = 'failed', error = ?, completed_at = NOW()"
            . " WHERE status = 'running'",
            [mb_substr($error, 0, 2000)],
        );

        return is_int($affected) ? $affected : 0;
    }

    /**
     * Decode a raw row into the shape every consumer (and the JSON API) uses.
     *
     * `params_json`/`result_json` become arrays; a malformed value degrades to
     * `[]`/`null` rather than propagating a string where an object is expected.
     *
     * @param array<array-key, mixed> $row A raw result row from the client.
     *
     * @return array<string, mixed>
     */
    private function decodeRow(array $row): array
    {
        $params = $this->decodeJsonObject($row['params_json'] ?? null);
        $result = $this->decodeJsonObject($row['result_json'] ?? null);

        return [
            'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
            'task' => is_string($row['task'] ?? null) ? $row['task'] : '',
            'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
            'params' => $params ?? [],
            'result' => $result,
            'error' => is_string($row['error'] ?? null) ? $row['error'] : null,
            'requested_by' => is_string($row['requested_by'] ?? null) ? $row['requested_by'] : null,
            'queued_at' => is_string($row['queued_at'] ?? null) ? $row['queued_at'] : null,
            'started_at' => is_string($row['started_at'] ?? null) ? $row['started_at'] : null,
            'completed_at' => is_string($row['completed_at'] ?? null) ? $row['completed_at'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }
}
