<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin\Maintenance;

use Phlix\Admin\Maintenance\MaintenanceJobRepository;
use Phlix\Admin\Maintenance\MaintenanceTask;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * {@see MaintenanceJobRepository} — the `maintenance_jobs` queue (S77).
 */
final class MaintenanceJobRepositoryTest extends TestCase
{
    /** @var list<array{sql: string, params: array<int, mixed>}> */
    private array $queries = [];

    /**
     * @param callable(string, array<int, mixed>): mixed $answer
     */
    private function connection(callable $answer): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (mixed $sql = null, mixed $params = null) use ($answer): mixed {
                $text = is_string($sql) ? $sql : '';
                $bound = is_array($params) ? $params : [];
                $this->queries[] = ['sql' => $text, 'params' => $bound];

                return $answer($text, $bound);
            }
        );

        return $db;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id, string $status, string $task): array
    {
        return [
            'id' => $id,
            'task' => $task,
            'status' => $status,
            'params_json' => '{"apply":true}',
            'result_json' => null,
            'error' => null,
            'requested_by' => 'admin-1',
            'queued_at' => '2026-08-07 10:00:00',
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    // -----------------------------------------------------------------
    // enqueue
    // -----------------------------------------------------------------

    public function test_enqueue_inserts_and_returns_the_created_job(): void
    {
        $inserted = false;
        $db = $this->connection(function (string $sql) use (&$inserted): mixed {
            if (str_starts_with($sql, 'INSERT INTO maintenance_jobs')) {
                $inserted = true;

                return 1;
            }
            if (str_contains($sql, "status IN ('queued', 'running')")) {
                return [];
            }
            if (str_contains($sql, 'WHERE id = ?')) {
                return [$this->row('job-1', 'queued', MaintenanceTask::DEDUPE_PATHS)];
            }

            return [];
        });

        $out = (new MaintenanceJobRepository($db))
            ->enqueue(MaintenanceTask::DEDUPE_PATHS, ['apply' => true], 'admin-1');

        self::assertTrue($inserted);
        self::assertTrue($out['created']);
        self::assertSame('job-1', $out['job']['id']);
        self::assertSame(['apply' => true], $out['job']['params']);
        self::assertSame('admin-1', $out['job']['requested_by']);
    }

    /**
     * A second click while the same task is pending returns the EXISTING job and
     * issues NO INSERT.
     *
     * The Tasks page is a row of buttons, so a double click is the expected
     * input; a second `storage-snapshot` would re-run `du -sb` over every vault
     * root for an identical answer.
     */
    public function test_enqueue_is_idempotent_while_the_same_task_is_pending(): void
    {
        $db = $this->connection(function (string $sql): mixed {
            if (str_contains($sql, "status IN ('queued', 'running')")) {
                return [$this->row('existing-1', 'running', MaintenanceTask::STORAGE_SNAPSHOT)];
            }

            return [];
        });

        $out = (new MaintenanceJobRepository($db))->enqueue(MaintenanceTask::STORAGE_SNAPSHOT);

        self::assertFalse($out['created']);
        self::assertSame('existing-1', $out['job']['id']);

        foreach ($this->queries as $q) {
            self::assertStringNotContainsString(
                'INSERT INTO maintenance_jobs',
                $q['sql'],
                'A pending job must suppress the INSERT, not merely be reported afterwards.'
            );
        }
    }

    /**
     * CONTROL for the idempotence check: the pending lookup is scoped to the
     * SAME task, so a pending `dedupe-paths` does not block a `storage-snapshot`.
     */
    public function test_a_pending_job_for_a_different_task_does_not_block(): void
    {
        $pendingFor = null;
        $db = $this->connection(function (string $sql, array $params) use (&$pendingFor): mixed {
            if (str_contains($sql, "status IN ('queued', 'running')")) {
                $pendingFor = $params[0] ?? null;

                return [];
            }
            if (str_contains($sql, 'WHERE id = ?')) {
                return [$this->row('new-1', 'queued', MaintenanceTask::STORAGE_SNAPSHOT)];
            }

            return 1;
        });

        $out = (new MaintenanceJobRepository($db))->enqueue(MaintenanceTask::STORAGE_SNAPSHOT);

        self::assertSame(MaintenanceTask::STORAGE_SNAPSHOT, $pendingFor);
        self::assertTrue($out['created']);
    }

    /**
     * An INSERT that lands but whose read-back misses still yields a job with an
     * id, so the caller always has something to poll with.
     */
    public function test_enqueue_synthesises_a_job_when_the_read_back_misses(): void
    {
        $db = $this->connection(static fn (string $sql): mixed => str_starts_with($sql, 'INSERT') ? 1 : []);

        $out = (new MaintenanceJobRepository($db))->enqueue(MaintenanceTask::STORAGE_SNAPSHOT, ['a' => 1]);

        self::assertTrue($out['created']);
        self::assertNotSame('', $out['job']['id']);
        self::assertSame('queued', $out['job']['status']);
        self::assertSame(['a' => 1], $out['job']['params']);
    }

    // -----------------------------------------------------------------
    // claimNext
    // -----------------------------------------------------------------

    public function test_claim_next_flips_the_row_to_running_and_returns_it(): void
    {
        $db = $this->connection(function (string $sql): mixed {
            if (str_contains($sql, "WHERE status = 'queued' ORDER BY")) {
                return [['id' => 'job-9']];
            }
            if (str_starts_with($sql, 'UPDATE maintenance_jobs')) {
                return 1;
            }

            return [$this->row('job-9', 'running', MaintenanceTask::STORAGE_SNAPSHOT)];
        });

        $job = (new MaintenanceJobRepository($db))->claimNext();

        self::assertIsArray($job);
        self::assertSame('job-9', $job['id']);
        self::assertSame('running', $job['status']);
    }

    /**
     * THE RACE. When the conditional UPDATE affects zero rows another drainer
     * won, and this call must claim NOTHING — returning the row anyway would
     * run the same expensive job twice.
     */
    public function test_claim_next_returns_null_when_another_drainer_won_the_race(): void
    {
        $db = $this->connection(function (string $sql): mixed {
            if (str_contains($sql, "WHERE status = 'queued' ORDER BY")) {
                return [['id' => 'job-9']];
            }
            if (str_starts_with($sql, 'UPDATE maintenance_jobs')) {
                return 0;
            }

            self::fail('findById must not be reached after a lost claim.');
        });

        self::assertNull((new MaintenanceJobRepository($db))->claimNext());
    }

    public function test_claim_next_returns_null_on_an_empty_queue(): void
    {
        $db = $this->connection(static fn (): array => []);

        self::assertNull((new MaintenanceJobRepository($db))->claimNext());
    }

    /**
     * The claim UPDATE re-checks `status = 'queued'`. Without that condition the
     * atomicity is gone and two drainers both "claim" the same job.
     */
    public function test_the_claim_update_is_conditional_on_the_row_still_being_queued(): void
    {
        $db = $this->connection(function (string $sql): mixed {
            if (str_contains($sql, "WHERE status = 'queued' ORDER BY")) {
                return [['id' => 'job-9']];
            }

            return str_starts_with($sql, 'UPDATE') ? 0 : [];
        });

        (new MaintenanceJobRepository($db))->claimNext();

        $updates = array_values(array_filter(
            array_column($this->queries, 'sql'),
            static fn (string $sql): bool => str_starts_with($sql, 'UPDATE maintenance_jobs')
        ));
        self::assertCount(1, $updates);
        self::assertStringContainsString("WHERE id = ? AND status = 'queued'", $updates[0]);
    }

    // -----------------------------------------------------------------
    // Completion / failure
    // -----------------------------------------------------------------

    public function test_mark_completed_stores_the_result_as_json(): void
    {
        $db = $this->connection(static fn (): int => 1);

        (new MaintenanceJobRepository($db))->markCompleted('job-1', ['reaped' => 4]);

        self::assertCount(1, $this->queries);
        self::assertStringContainsString("status = 'completed'", $this->queries[0]['sql']);
        self::assertSame('{"reaped":4}', $this->queries[0]['params'][0]);
    }

    /**
     * The failure message is truncated CHARACTER-wise.
     *
     * A byte-wise cut can split a multi-byte sequence and MySQL rejects the
     * resulting invalid UTF-8 with error 1366 — the landmine
     * `TranscodeManager::readFailureReason()` documents for the same column
     * shape. Exercised with a multi-byte payload, because an ASCII one cannot
     * tell `substr` from `mb_substr`.
     */
    public function test_mark_failed_truncates_character_wise_so_utf8_stays_valid(): void
    {
        $db = $this->connection(static fn (): int => 1);

        (new MaintenanceJobRepository($db))->markFailed('job-1', str_repeat('é', 4000));

        $stored = $this->queries[0]['params'][0];
        self::assertIsString($stored);
        self::assertSame(2000, mb_strlen($stored));
        self::assertTrue(mb_check_encoding($stored, 'UTF-8'), 'A byte-wise cut would split a codepoint.');
    }

    /**
     * The boot reap exists so a job whose process died stops blocking every
     * future enqueue of that task — `claimNext()` only ever takes `queued` rows,
     * so a `running` orphan is never picked up again.
     */
    public function test_reap_running_fails_every_running_row_and_reports_the_count(): void
    {
        $db = $this->connection(static fn (): int => 3);

        self::assertSame(3, (new MaintenanceJobRepository($db))->reapRunning('restart'));
        self::assertStringContainsString("WHERE status = 'running'", $this->queries[0]['sql']);
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    public function test_recent_clamps_the_limit_and_can_filter_by_task(): void
    {
        $db = $this->connection(fn (): array => [$this->row('j', 'completed', MaintenanceTask::DEDUPE_PATHS)]);
        $repo = new MaintenanceJobRepository($db);

        $repo->recent(999999);
        self::assertStringContainsString('LIMIT ' . MaintenanceJobRepository::MAX_RECENT_LIMIT, $this->queries[0]['sql']);
        self::assertStringNotContainsString('WHERE task', $this->queries[0]['sql']);

        $repo->recent(-4, MaintenanceTask::DEDUPE_PATHS);
        self::assertStringContainsString('LIMIT 1', $this->queries[1]['sql']);
        self::assertStringContainsString('WHERE task = ?', $this->queries[1]['sql']);
        self::assertSame([MaintenanceTask::DEDUPE_PATHS], $this->queries[1]['params']);
    }

    /**
     * A malformed `params_json` / `result_json` degrades to `[]` / `null`
     * instead of handing a raw string to a consumer (or to the JSON API) that
     * expects an object.
     */
    public function test_malformed_json_columns_degrade_rather_than_leak_a_string(): void
    {
        $row = $this->row('job-1', 'failed', MaintenanceTask::DEDUPE_PATHS);
        $row['params_json'] = 'not json at all';
        $row['result_json'] = '"a bare string"';

        $db = $this->connection(static fn (): array => [$row]);

        $job = (new MaintenanceJobRepository($db))->findById('job-1');

        self::assertIsArray($job);
        self::assertSame([], $job['params']);
        self::assertNull($job['result']);
    }

    public function test_find_by_id_returns_null_for_an_unknown_job(): void
    {
        $db = $this->connection(static fn (): array => []);

        self::assertNull((new MaintenanceJobRepository($db))->findById('nope'));
    }

    /**
     * The decoded row has exactly the keys the JSON API promises S78.
     */
    public function test_the_decoded_row_shape_is_the_wire_contract(): void
    {
        $db = $this->connection(fn (): array => [$this->row('job-1', 'queued', MaintenanceTask::STORAGE_SNAPSHOT)]);

        $job = (new MaintenanceJobRepository($db))->findById('job-1');

        self::assertIsArray($job);
        self::assertSame(
            ['id', 'task', 'status', 'params', 'result', 'error', 'requested_by',
                'queued_at', 'started_at', 'completed_at'],
            array_keys($job)
        );
    }
}
