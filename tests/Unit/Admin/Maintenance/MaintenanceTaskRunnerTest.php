<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin\Maintenance;

use InvalidArgumentException;
use Phlix\Admin\Maintenance\MaintenanceTask;
use Phlix\Admin\Maintenance\MaintenanceTaskRunner;
use Phlix\Media\Library\PathDeduper;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Transcoding\TranscodeManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * {@see MaintenanceTaskRunner} — what each admin task actually DOES (S77).
 *
 * The vocabulary/coverage questions live in {@see MaintenanceTaskCoverageTest};
 * this file is about the individual behaviours, and in particular about the two
 * guards that stop a one-click button from destroying data.
 */
final class MaintenanceTaskRunnerTest extends TestCase
{
    /** @var list<array{sql: string, params: array<int, mixed>}> Every statement the runner issued. */
    private array $queries = [];

    /**
     * A `Connection` double that records every statement and answers from a
     * `substring → result` map.
     *
     * @param array<string, mixed> $answers Matched in insertion order; the first
     *        substring found in the SQL wins. ⚠ Keys must be SPECIFIC: the
     *        orphan DELETEs embed `FROM \`media_items\`` inside their
     *        `NOT EXISTS`, so a key that short would answer the DELETE with the
     *        row-count result and every deletion would report zero.
     */
    private function connection(array $answers = []): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (mixed $sql = null, mixed $params = null) use ($answers): mixed {
                $text = is_string($sql) ? $sql : '';
                $this->queries[] = [
                    'sql' => $text,
                    'params' => is_array($params) ? $params : [],
                ];

                foreach ($answers as $needle => $result) {
                    if (str_contains($text, $needle)) {
                        return $result;
                    }
                }

                return [];
            }
        );

        return $db;
    }

    /**
     * Every recorded statement, joined — for asserting that something was or
     * was not issued.
     */
    private function issuedSql(): string
    {
        return implode("\n", array_column($this->queries, 'sql'));
    }

    private function runner(
        Connection $db,
        ?ScanJobRepository $scanJobs = null,
        ?PathDeduper $deduper = null,
        ?TranscodeManager $transcode = null,
    ): MaintenanceTaskRunner {
        return new MaintenanceTaskRunner(
            $db,
            $scanJobs ?? $this->createMock(ScanJobRepository::class),
            $deduper ?? $this->createMock(PathDeduper::class),
            $transcode,
        );
    }

    // -----------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------

    public function test_an_unknown_task_throws_rather_than_silently_doing_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown maintenance task: not-a-task');

        $this->runner($this->connection())->run('not-a-task');
    }

    // -----------------------------------------------------------------
    // reap-scan-jobs — the six-hour FLOOR
    // -----------------------------------------------------------------

    /**
     * THE GUARD. A caller asking for a short age gets the floor instead.
     *
     * `library_scan_jobs` has no heartbeat column, so `started_at` is the only
     * age signal and it is never refreshed while a scan runs.
     * `LibraryScanWorker::start()` records a real production music scan that ran
     * **4 h 09 m** before its first durable write — so honouring a 60-second
     * request would mark that healthy scan `failed` AND make
     * `hasActiveJobForLibrary()` report the library idle, re-opening the door to
     * a second concurrent scan over the same files.
     *
     * The response says the floor was applied rather than pretending it honoured
     * the request.
     */
    public function test_a_short_reap_age_is_raised_to_the_six_hour_floor(): void
    {
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects(self::once())
            ->method('reapStaleJobs')
            ->with(self::anything(), MaintenanceTaskRunner::MIN_SCAN_JOB_AGE_SECONDS)
            ->willReturn(2);

        $result = $this->runner($this->connection(), $scanJobs)
            ->run(MaintenanceTask::REAP_SCAN_JOBS, ['older_than_seconds' => 60]);

        self::assertSame(2, $result['reaped']);
        self::assertSame(MaintenanceTaskRunner::MIN_SCAN_JOB_AGE_SECONDS, $result['older_than_seconds']);
        self::assertSame(60, $result['requested_older_than_seconds']);
        self::assertTrue($result['floor_applied']);
    }

    /**
     * CONTROL for the floor: a LARGER age is honoured verbatim, so the floor is
     * a minimum rather than a hard-coded constant that ignores its input.
     */
    public function test_a_longer_reap_age_is_honoured_verbatim(): void
    {
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects(self::once())
            ->method('reapStaleJobs')
            ->with(self::anything(), 86400)
            ->willReturn(0);

        $result = $this->runner($this->connection(), $scanJobs)
            ->run(MaintenanceTask::REAP_SCAN_JOBS, ['older_than_seconds' => 86400]);

        self::assertSame(86400, $result['older_than_seconds']);
        self::assertFalse($result['floor_applied']);
    }

    /**
     * The age is NEVER null. A null second argument is the boot-catch-up
     * contract of {@see ScanJobRepository::reapStaleJobs()} — it fails EVERY
     * `running` row, healthy or not — and it must never be reachable from an
     * HTTP request.
     */
    public function test_the_reaper_is_never_called_with_the_unbounded_boot_contract(): void
    {
        $seen = null;
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('reapStaleJobs')->willReturnCallback(
            function (string $error, ?int $age = null) use (&$seen): int {
                $seen = $age;

                return 0;
            }
        );

        $this->runner($this->connection(), $scanJobs)->run(MaintenanceTask::REAP_SCAN_JOBS);

        self::assertNotNull($seen, 'A null age reaps every running scan job, including live ones.');
        self::assertGreaterThanOrEqual(MaintenanceTaskRunner::MIN_SCAN_JOB_AGE_SECONDS, $seen);
    }

    // -----------------------------------------------------------------
    // reap-transcode-jobs
    // -----------------------------------------------------------------

    public function test_transcode_reaping_forwards_the_age_and_reports_the_count(): void
    {
        $transcode = $this->createMock(TranscodeManager::class);
        $transcode->expects(self::once())
            ->method('reapStaleRunningJobs')
            ->with(900)
            ->willReturn(3);

        $result = $this->runner($this->connection(), null, null, $transcode)
            ->run(MaintenanceTask::REAP_TRANSCODE_JOBS, ['older_than_seconds' => 900]);

        self::assertSame(['reaped' => 3, 'older_than_seconds' => 900], $result);
    }

    /**
     * A missing TranscodeManager fails THIS task with a message an operator can
     * act on, rather than being silently reported as "reaped 0".
     *
     * This is the PHP-DI optional-parameter trap made visible: the container
     * wires the manager explicitly ({@see MaintenanceContainerWiringTest}), and
     * if it ever stopped, an operator would see this string instead of a button
     * that appears to work and does nothing.
     */
    public function test_transcode_reaping_fails_loudly_when_the_manager_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TranscodeManager is unavailable');

        $this->runner($this->connection(), null, null, null)
            ->run(MaintenanceTask::REAP_TRANSCODE_JOBS);
    }

    // -----------------------------------------------------------------
    // cleanup-orphaned-stats — the empty-parent guard
    // -----------------------------------------------------------------

    /**
     * 🚨 THE FAIL-DANGEROUS CASE. `NOT EXISTS (SELECT 1 FROM media_items …)` is
     * true for EVERY row when `media_items` is empty, so a fresh install, a
     * half-restored backup or an unreadable table would make one click delete
     * the entire playback history.
     *
     * The task refuses, and — asserted separately, because "it threw" is not
     * the same claim — issues NO DELETE at all.
     */
    public function test_cleanup_refuses_and_deletes_nothing_when_media_items_is_empty(): void
    {
        $db = $this->connection([
            'SELECT COUNT(*) AS cnt FROM `media_items`' => [['cnt' => 0]],
            'SELECT COUNT(*) AS cnt FROM `users`' => [['cnt' => 42]],
        ]);

        try {
            $this->runner($db)->run(MaintenanceTask::CLEANUP_ORPHANED_STATS);
            self::fail('An empty media_items table must abort the cleanup.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('media_items', $e->getMessage());
            self::assertStringContainsString('zero rows', $e->getMessage());
        }

        self::assertStringNotContainsString(
            'DELETE',
            $this->issuedSql(),
            'The guard must run BEFORE any DELETE, not merely report afterwards.'
        );
    }

    /**
     * The same guard on the OTHER parent. Asserted separately because a guard
     * that only checked `media_items` would still delete every
     * `stats_user_activity` row.
     */
    public function test_cleanup_refuses_when_users_is_empty(): void
    {
        $db = $this->connection([
            'SELECT COUNT(*) AS cnt FROM `media_items`' => [['cnt' => 7]],
            'SELECT COUNT(*) AS cnt FROM `users`' => [['cnt' => 0]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('`users` reports zero rows');

        $this->runner($db)->run(MaintenanceTask::CLEANUP_ORPHANED_STATS);
    }

    /**
     * THE POSITIVE CONTROL: with both parents populated the cleanup DOES run,
     * so the two refusals above are a property of the empty table and not of
     * the task being permanently broken.
     */
    public function test_cleanup_deletes_orphans_when_the_parents_are_populated(): void
    {
        $db = $this->connection([
            'SELECT COUNT(*) AS cnt FROM `media_items`' => [['cnt' => 100]],
            'SELECT COUNT(*) AS cnt FROM `users`' => [['cnt' => 5]],
            'DELETE FROM `stats_playback_events`' => 4,
            'DELETE FROM `stats_library_changes`' => 1,
            'DELETE FROM `stats_user_activity`' => 2,
        ]);

        $result = $this->runner($db)->run(MaintenanceTask::CLEANUP_ORPHANED_STATS);

        // stats_playback_events is cleaned on BOTH its references, so 4 + 4.
        self::assertSame(11, $result['total']);
        self::assertSame(
            [
                'stats_playback_events.media_item_id' => 4,
                'stats_playback_events.user_id' => 4,
                'stats_library_changes.media_item_id' => 1,
                'stats_user_activity.user_id' => 2,
            ],
            $result['deleted']
        );
        self::assertFalse($result['truncated']);
        self::assertSame(MaintenanceTaskRunner::DEFAULT_ORPHAN_DELETE_LIMIT, $result['limit']);
    }

    /**
     * A NULLABLE reference column is excluded before the NOT EXISTS.
     *
     * `stats_library_changes.media_item_id` is legitimately NULL for a
     * server-wide change; without the `IS NOT NULL` guard every such row would
     * look orphaned and be deleted. Asserted on the emitted SQL per table,
     * because the behavioural difference only shows against a real database.
     */
    public function test_only_the_nullable_reference_carries_an_is_not_null_guard(): void
    {
        $db = $this->connection([
            'SELECT COUNT(*) AS cnt FROM `media_items`' => [['cnt' => 1]],
            'SELECT COUNT(*) AS cnt FROM `users`' => [['cnt' => 1]],
        ]);

        $this->runner($db)->run(MaintenanceTask::CLEANUP_ORPHANED_STATS);

        $deletes = array_values(array_filter(
            array_column($this->queries, 'sql'),
            static fn (string $sql): bool => str_starts_with($sql, 'DELETE')
        ));
        self::assertCount(4, $deletes, 'ANTI-VACUITY: no DELETE statements were issued at all.');

        $byTable = [];
        foreach ($deletes as $sql) {
            $byTable[] = [
                'sql' => $sql,
                'nullGuarded' => str_contains($sql, 'IS NOT NULL'),
            ];
        }

        // Exactly ONE of the four targets is nullable.
        $guarded = array_filter($byTable, static fn (array $d): bool => $d['nullGuarded']);
        self::assertCount(1, $guarded);

        $guardedSql = array_values($guarded)[0]['sql'];
        self::assertStringContainsString('stats_library_changes', $guardedSql);
        self::assertStringContainsString('`media_item_id` IS NOT NULL', $guardedSql);
    }

    /**
     * Hitting the per-table cap is REPORTED, not looped over — one click can
     * never turn into an unbounded delete.
     */
    public function test_hitting_the_limit_reports_truncated_rather_than_looping(): void
    {
        $db = $this->connection([
            'SELECT COUNT(*) AS cnt FROM `media_items`' => [['cnt' => 1]],
            'SELECT COUNT(*) AS cnt FROM `users`' => [['cnt' => 1]],
            'DELETE FROM `stats_playback_events`' => 10,
        ]);

        $result = $this->runner($db)->run(MaintenanceTask::CLEANUP_ORPHANED_STATS, ['limit' => 10]);

        self::assertTrue($result['truncated']);
        self::assertSame(10, $result['limit']);

        $deletes = array_filter(
            array_column($this->queries, 'sql'),
            static fn (string $sql): bool => str_starts_with($sql, 'DELETE')
        );
        self::assertCount(4, $deletes, 'One DELETE per target, never a retry loop.');
    }

    public function test_the_delete_limit_is_clamped_to_the_ceiling(): void
    {
        $db = $this->connection([
            'SELECT COUNT(*) AS cnt FROM `media_items`' => [['cnt' => 1]],
            'SELECT COUNT(*) AS cnt FROM `users`' => [['cnt' => 1]],
        ]);

        $result = $this->runner($db)->run(
            MaintenanceTask::CLEANUP_ORPHANED_STATS,
            ['limit' => 10_000_000]
        );

        self::assertSame(MaintenanceTaskRunner::MAX_ORPHAN_DELETE_LIMIT, $result['limit']);
        self::assertStringContainsString(
            'LIMIT ' . MaintenanceTaskRunner::MAX_ORPHAN_DELETE_LIMIT,
            $this->issuedSql()
        );
    }

    // -----------------------------------------------------------------
    // dedupe-paths
    // -----------------------------------------------------------------

    /**
     * THE DEFAULT IS A DRY RUN. `apply` must be explicitly `true`, so a button
     * that forgets to send it previews instead of deleting media rows.
     */
    public function test_dedupe_defaults_to_a_dry_run_and_never_merges(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('findDuplicateGroups')->willReturn([
            [
                'path' => '/library/Film.mp4',
                'library_id' => 'lib-1',
                'library_name' => 'Movies',
                'items' => [
                    ['id' => 'a', 'name' => 'Film', 'type' => 'movie', 'created_at' => '2026-01-01'],
                    ['id' => 'b', 'name' => 'Film', 'type' => 'movie', 'created_at' => '2026-01-02'],
                ],
            ],
        ]);
        $deduper->method('scoreItem')->willReturn(0);
        $deduper->expects(self::never())->method('deleteItem');
        $deduper->expects(self::never())->method('beginTrans');

        $result = $this->runner($this->connection(), null, $deduper)
            ->run(MaintenanceTask::DEDUPE_PATHS);

        self::assertFalse($result['applied']);
        self::assertSame(1, $result['groups_found']);
        self::assertSame(1, $result['rows_merged'], 'A preview still reports what WOULD be merged.');
    }

    /**
     * CONTROL: `apply: true` really does merge, so the dry-run assertion above
     * is not just "this code path never deletes anything".
     */
    public function test_dedupe_with_apply_true_merges(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('findDuplicateGroups')->willReturn([
            [
                'path' => '/library/Film.mp4',
                'library_id' => 'lib-1',
                'library_name' => 'Movies',
                'items' => [
                    ['id' => 'a', 'name' => 'Film', 'type' => 'movie', 'created_at' => '2026-01-01'],
                    ['id' => 'b', 'name' => 'Film', 'type' => 'movie', 'created_at' => '2026-01-02'],
                ],
            ],
        ]);
        $deduper->method('scoreItem')->willReturnCallback(
            static fn (string $id): int => $id === 'b' ? 10 : 1
        );
        $deduper->expects(self::once())->method('beginTrans');
        $deduper->expects(self::once())->method('repointReferencingTables')->with('a', 'b');
        $deduper->expects(self::once())->method('deleteItem')->with('a');
        $deduper->expects(self::once())->method('commit');

        $result = $this->runner($this->connection(), null, $deduper)
            ->run(MaintenanceTask::DEDUPE_PATHS, ['apply' => true]);

        self::assertTrue($result['applied']);
        self::assertSame(1, $result['rows_merged']);
        self::assertSame(0, $result['groups_failed']);
    }

    /**
     * A TRUTHY-but-not-true `apply` (the string `"true"`, which is what a form
     * post sends) does NOT apply.
     *
     * Strict `=== true` is deliberate on a destructive switch: `"false"` is a
     * truthy string, so a loose check would delete rows for a client that
     * explicitly said not to.
     */
    public function test_a_stringly_typed_apply_does_not_delete(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('findDuplicateGroups')->willReturn([]);
        $deduper->expects(self::never())->method('deleteItem');

        foreach (['true', '1', 1, 'false'] as $value) {
            $result = $this->runner($this->connection(), null, $deduper)
                ->run(MaintenanceTask::DEDUPE_PATHS, ['apply' => $value]);

            self::assertFalse($result['applied'], 'apply must be a real boolean true');
        }
    }

    public function test_the_dedupe_batch_size_is_clamped(): void
    {
        $deduper = $this->createMock(PathDeduper::class);
        $deduper->method('findDuplicateGroups')->willReturn([]);

        $runner = $this->runner($this->connection(), null, $deduper);

        self::assertSame(
            MaintenanceTaskRunner::MAX_DEDUPE_BATCH_SIZE,
            $runner->run(MaintenanceTask::DEDUPE_PATHS, ['batch_size' => 999999])['batch_size']
        );
        self::assertSame(
            1,
            $runner->run(MaintenanceTask::DEDUPE_PATHS, ['batch_size' => -5])['batch_size']
        );
    }

    // -----------------------------------------------------------------
    // storage-snapshot
    // -----------------------------------------------------------------

    /**
     * The snapshot counts items from the database and records a row.
     *
     * ⚠ SKIPPED when a vault root exists. `StorageSnapshotHelper::collectBuckets()`
     * `shell_exec`s `du -sb` over `/vault1` and `/vault2` when they are present,
     * and a unit test must not walk a production media vault. Neither exists on
     * this box or in CI, so the test runs there; the skip is the honest thing to
     * do rather than pretending the coverage is unconditional.
     */
    public function test_the_storage_snapshot_records_database_item_counts(): void
    {
        foreach (['/vault1', '/vault2'] as $vault) {
            if (is_dir($vault)) {
                self::markTestSkipped(
                    "{$vault} exists on this host; running this test would `du -sb` a real media vault."
                );
            }
        }

        $db = $this->connection([
            'GROUP BY type' => [
                ['type' => 'movie', 'item_count' => 12],
                ['type' => 'episode', 'item_count' => 30],
            ],
        ]);

        $result = $this->runner($db)->run(MaintenanceTask::STORAGE_SNAPSHOT);

        self::assertSame(count(\Phlix\Stats\StorageSnapshotHelper::BUCKETS), $result['buckets']);
        self::assertSame(42, $result['total_items']);
        self::assertSame(0, $result['total_bytes']);

        self::assertStringContainsString(
            'INSERT INTO stats_storage',
            $this->issuedSql(),
            'The task must actually WRITE a snapshot, not merely collect one.'
        );
    }
}
