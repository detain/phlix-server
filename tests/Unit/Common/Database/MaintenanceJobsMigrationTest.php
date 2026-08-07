<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Admin\Maintenance\MaintenanceJobRepository;
use Phlix\Common\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * S77 — static guard over `migrations/098_maintenance_jobs.sql`.
 *
 * ## Why a static test, and what it is actually protecting
 *
 * Two things, neither of which any other gate sees:
 *
 *  1. **The runner's split.** `MigrationRunner` strips comments and then splits
 *     on `;`, so a semicolon inside a `COMMENT '…'` string literal shreds the
 *     DDL into fragments that each fail on their own. Migration 034 carries a
 *     warning about exactly this, and 098 has SIX `COMMENT` strings. Nothing
 *     else in the suite would notice — the file is only executed against a real
 *     MySQL, which most CI jobs do not have.
 *  2. **The columns the repository reads.** {@see MaintenanceJobRepository}
 *     `SELECT *`s and then reads named keys, so a column that exists in the
 *     code and not in the DDL is a silent empty string at runtime rather than
 *     an error.
 *
 * The real-schema counterpart would be an integration test against a live
 * MySQL; this is the half that runs everywhere.
 */
final class MaintenanceJobsMigrationTest extends TestCase
{
    private const MIGRATION = '098_maintenance_jobs.sql';

    private static function path(): string
    {
        return dirname(__DIR__, 4) . '/migrations/' . self::MIGRATION;
    }

    private static function sql(): string
    {
        $sql = file_get_contents(self::path());
        self::assertIsString($sql, self::MIGRATION . ' must be readable');

        return $sql;
    }

    /**
     * The statements the RUNNER would execute, produced by the runner's own
     * splitter rather than by a re-implementation of it.
     *
     * @return list<string>
     */
    private static function statements(): array
    {
        $split = new ReflectionMethod(MigrationRunner::class, 'splitStatements');
        $split->setAccessible(true);

        /** @var list<string> $statements */
        $statements = $split->invoke(null, self::sql());

        return $statements;
    }

    public function test_the_migration_file_exists_and_is_next_in_the_chain(): void
    {
        self::assertFileExists(self::path());

        $files = glob(dirname(__DIR__, 4) . '/migrations/*.sql');
        self::assertIsArray($files);
        sort($files);

        $names = array_map('basename', $files);
        self::assertContains(self::MIGRATION, $names);

        // No other file may claim number 098, or the runner's ordering is
        // ambiguous and one of them silently never runs.
        $ninetyEights = array_values(array_filter(
            $names,
            static fn (string $n): bool => str_starts_with($n, '098_')
        ));
        self::assertSame([self::MIGRATION], $ninetyEights);
    }

    /**
     * 🚨 THE SPLIT. The runner must see exactly ONE statement.
     *
     * A `;` inside any of the six `COMMENT '…'` strings would shred the CREATE
     * TABLE into fragments. Asserted through `MigrationRunner::splitStatements()`
     * itself, so a change to the splitter is covered as well as a change to the
     * SQL.
     */
    public function test_the_runner_sees_exactly_one_statement(): void
    {
        $statements = self::statements();

        self::assertCount(
            1,
            $statements,
            'A semicolon inside a COMMENT string literal splits this DDL into fragments that each '
            . "fail on their own. Statements the runner produced:\n" . implode("\n---\n", $statements)
        );
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `maintenance_jobs`', trim($statements[0]));
    }

    /**
     * Idempotent, per the repo convention: the runner can replay the file.
     */
    public function test_the_create_is_idempotent(): void
    {
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', self::sql());
    }

    /**
     * Every column {@see MaintenanceJobRepository} reads is DEFINED.
     *
     * The repository `SELECT *`s and then reads named keys with `?? null`
     * fallbacks, so a missing column is an empty string at runtime, never an
     * error. Listed explicitly rather than derived from the DDL — a list
     * derived from its own subject cannot detect that subject changing.
     */
    public function test_every_column_the_repository_reads_is_defined(): void
    {
        $statement = self::statements()[0];

        foreach (
            ['id', 'task', 'status', 'params_json', 'result_json', 'error',
                'requested_by', 'queued_at', 'started_at', 'completed_at'] as $column
        ) {
            self::assertStringContainsString(
                '`' . $column . '`',
                $statement,
                "MaintenanceJobRepository reads `{$column}`, but the DDL does not define it."
            );
        }
    }

    /**
     * The status vocabulary matches the one the code writes.
     *
     * `markCompleted()`/`markFailed()`/`claimNext()` write these four literals;
     * under `STRICT_TRANS_TABLES` a value the ENUM does not admit is MySQL error
     * 1265 — the same defect `stats_playback_events.media_type` shipped.
     */
    public function test_the_status_enum_admits_every_value_the_code_writes(): void
    {
        $statement = self::statements()[0];

        self::assertStringContainsString(
            "ENUM('queued', 'running', 'completed', 'failed')",
            $statement
        );
    }

    /**
     * `task` is a VARCHAR, not an ENUM — deliberately.
     *
     * The vocabulary lives in `MaintenanceTask::ALL` so adding a task needs no
     * migration. Pinned because the consequence is load-bearing elsewhere: it
     * is precisely why {@see \Phlix\Admin\Maintenance\MaintenanceQueueWorker}
     * has to validate the name itself before running anything.
     */
    public function test_the_task_column_is_a_varchar_so_the_vocabulary_can_live_in_php(): void
    {
        $statement = self::statements()[0];

        self::assertMatchesRegularExpression('/`task`\s+VARCHAR\(\d+\)\s+NOT NULL/i', $statement);
        self::assertDoesNotMatchRegularExpression('/`task`\s+ENUM/i', $statement);
    }

    /**
     * The queue's two hot lookups are indexed: `claimNext()` orders `queued`
     * rows by `queued_at`, and `findPending()` filters by `task` + status.
     */
    public function test_the_queue_lookups_are_indexed(): void
    {
        $statement = self::statements()[0];

        self::assertStringContainsString('INDEX `idx_mj_status_queued` (`status`, `queued_at`)', $statement);
        self::assertStringContainsString('INDEX `idx_mj_task_status` (`task`, `status`)', $statement);
    }
}
