<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Common\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * DBA remediation (R19 family) — static guards over migrations 113–120 plus
 * the R19-09 collation policy gate for every future migration.
 *
 * ## Why static tests (MaintenanceJobsMigrationTest precedent)
 *
 * These files only ever EXECUTE against a real MySQL, which most CI jobs do
 * not have. What CI can do is pin, through the runner's OWN splitter, that the
 * statements are the exact shapes the remediation depends on:
 *
 *  1. **One clause per statement.** The replay-safety of every ADD INDEX here
 *     rides on MySQL raising error 1061 (duplicate key name) per statement,
 *     which {@see MigrationRunner::isAlreadyApplied()} downgrades to a note.
 *     Multi-clause ALTERs would be all-or-nothing: one already-present clause
 *     would mask the other never having applied. Same math for the 1091
 *     (check-NA / can't drop) replay of the 119 drops.
 *  2. **The exact index names.** Later cleanup lanes (and the real-DB test)
 *     reference these names; a silent rename here would orphan that work.
 *  3. **The collation policy.** Any migration numbered 113 or later that
 *     creates a table must say its COLLATE explicitly — the drift that
 *     migration 120 had to convert away is exactly what happens when a CREATE
 *     TABLE relies on the server default. This is the CI assertion R19-09
 *     asked for; it is vacuously green today (113–120 create no tables) and
 *     guards tomorrow.
 */
final class DbRemediationMigrationsTest extends TestCase
{
    /**
     * The collation policy starts with the remediation set itself — every
     * migration number >= this floor must pin COLLATE on its CREATE TABLEs.
     */
    private const COLLATE_POLICY_FLOOR = 113;

    /**
     * Files this lane owns, keyed by the number prefix the runner orders on.
     *
     * @var array<int, string>
     */
    private const FILES = [
        113 => '113_sessions_last_activity_index.sql',
        114 => '114_playback_state_media_updated_index.sql',
        115 => '115_watch_history_profile_time_indexes.sql',
        116 => '116_active_streams_session_index.sql',
        117 => '117_stats_read_and_retention_indexes.sql',
        118 => '118_media_items_library_created_index.sql',
        119 => '119_drop_redundant_dead_indexes.sql',
        120 => '120_collation_unicode_ci_conversion.sql',
    ];

    /**
     * The exact statements the runner must extract from each file, in order.
     *
     * @var array<int, list<string>>
     */
    private const EXPECTED_STATEMENTS = [
        113 => [
            'ALTER TABLE `sessions` ADD INDEX `idx_sessions_last_activity` (`last_activity`)',
        ],
        114 => [
            'ALTER TABLE `playback_state` ADD INDEX `idx_playback_state_media_updated` (`media_item_id`, `updated_at`)',
        ],
        115 => [
            'ALTER TABLE `watch_history` ADD INDEX `idx_watch_history_profile_last` (`profile_id`, `last_watched_at`)',
            'ALTER TABLE `watch_history` ADD INDEX `idx_watch_history_profile_completed` (`profile_id`, `completed_at`)',
        ],
        116 => [
            'ALTER TABLE `active_streams` ADD INDEX `idx_active_streams_session` (`session_id`)',
        ],
        117 => [
            'ALTER TABLE `stats_library_changes` ADD INDEX `idx_stats_library_changes_changed_at` (`changed_at`)',
            'ALTER TABLE `stats_user_activity` ADD INDEX `idx_stats_user_activity_occurred_at` (`occurred_at`)',
            'ALTER TABLE `stats_user_activity` ADD INDEX `idx_stats_user_activity_type_occurred` (`activity_type`, `occurred_at`)',
            'ALTER TABLE `stats_user_activity` ADD INDEX `idx_stats_user_activity_user_id` (`user_id`)',
        ],
        118 => [
            'ALTER TABLE `media_items` ADD INDEX `idx_media_items_library_created` (`library_id`, `created_at`)',
        ],
        119 => [
            'ALTER TABLE `users` DROP INDEX `idx_username`',
            'ALTER TABLE `users` DROP INDEX `idx_email`',
            'ALTER TABLE `item_similar` DROP INDEX `idx_score`',
        ],
        120 => [
            'ALTER TABLE `audiobook_progress` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'ALTER TABLE `network_health_log` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'ALTER TABLE `access_schedules` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'ALTER TABLE `profile_access_schedule` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'ALTER TABLE `profile_tags` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'ALTER TABLE `user_recommendations` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'ALTER TABLE `profile_stream_limits` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'ALTER TABLE `active_streams` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'ALTER TABLE `book_progress` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'ALTER TABLE `schema_migrations` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        ],
    ];

    private static function migrationsDir(): string
    {
        return dirname(__DIR__, 4) . '/migrations';
    }

    /**
     * @return list<string> basenames of every .sql in the chain, sorted
     */
    private static function allMigrationNames(): array
    {
        $files = glob(self::migrationsDir() . '/*.sql');
        self::assertIsArray($files);
        $names = array_map('basename', $files);
        sort($names);

        return $names;
    }

    /**
     * Leading numeric prefix of a migration filename ('104b_…' → 104).
     */
    private static function numberOf(string $basename): int
    {
        return (int) $basename;
    }

    /**
     * The statements the RUNNER would execute, produced by the runner's own
     * splitter, not a re-implementation of it.
     *
     * @return list<string>
     */
    private static function statementsIn(string $basename): array
    {
        $sql = file_get_contents(self::migrationsDir() . '/' . $basename);
        self::assertIsString($sql, $basename . ' must be readable');

        $split = new ReflectionMethod(MigrationRunner::class, 'splitStatements');
        $split->setAccessible(true);

        /** @var list<string> $statements */
        $statements = $split->invoke(null, $sql);

        return $statements;
    }

    public function test_each_remediation_migration_exists_and_is_the_only_claimant_of_its_number(): void
    {
        $names = self::allMigrationNames();

        foreach (self::FILES as $number => $file) {
            self::assertContains($file, $names, "migration {$file} is missing");

            $claimants = array_values(array_filter(
                $names,
                static fn (string $n): bool => self::numberOf($n) === $number
                    && preg_match('/^0*' . $number . '[^0-9]/', $n) === 1
            ));
            self::assertSame(
                [$file],
                $claimants,
                "exactly one file may claim migration number {$number} or the runner's order is ambiguous"
            );
        }
    }

    /**
     * 🚨 THE SPLIT + THE SHAPE. Each file must produce exactly its canonical
     * statement list — one clause per ALTER, index names verbatim.
     */
    public function test_the_runner_extracts_the_exact_canonical_statements_from_every_remediation_migration(): void
    {
        foreach (self::FILES as $number => $file) {
            self::assertSame(
                self::EXPECTED_STATEMENTS[$number],
                self::statementsIn($file),
                "{$file} drifted from the pinned remediation shape"
            );
        }
    }

    /**
     * Defence-in-depth on the single-clause law: even if someone edits both the
     * file and the pin above, no index statement may stack two ADD/DROP INDEX
     * clauses — replay squelch (1061/1091 via isAlreadyApplied) is only
     * faithful when each statement carries one clause.
     */
    public function test_no_index_statement_stacks_multiple_clauses(): void
    {
        foreach (self::FILES as $number => $file) {
            if ($number === 120) {
                continue; // CONVERT statements carry their own single clause.
            }
            foreach (self::statementsIn($file) as $statement) {
                $adds = substr_count($statement, 'ADD INDEX');
                $drops = substr_count($statement, 'DROP INDEX');
                self::assertLessThanOrEqual(
                    1,
                    $adds + $drops,
                    "multi-clause ALTER defeats the 1061/1091 replay squelch in {$file}: {$statement}"
                );
            }
        }
    }

    /**
     * R19-15 safety: 119 drops only the three redundant twins. The UNIQUE
     * indexes that actually enforce users.username / users.email are named
     * after the columns themselves (inline UNIQUE in 001) — dropping those
     * would remove the constraint, not the redundancy.
     */
    public function test_the_dead_index_drops_never_touch_the_uniqueness_constraints(): void
    {
        foreach (self::statementsIn(self::FILES[119]) as $statement) {
            self::assertStringNotContainsString('DROP INDEX `username`', $statement);
            self::assertStringNotContainsString('DROP INDEX `email`', $statement);
            self::assertStringNotContainsString('PRIMARY', $statement);
        }
    }

    /**
     * R19-09 policy gate: every migration numbered >= 113 that CREATEs a table
     * must pin its COLLATE in the same statement. Silence on the COLLATE line
     * is how ten tables inherited utf8mb4_0900_ai_ci and needed migration 120
     * to convert them back; no future migration may repeat that.
     */
    public function test_new_migrations_never_create_a_table_without_an_explicit_collate(): void
    {
        $offenders = [];

        foreach (self::allMigrationNames() as $name) {
            if (self::numberOf($name) < self::COLLATE_POLICY_FLOOR) {
                continue;
            }
            foreach (self::statementsIn($name) as $statement) {
                if (stripos($statement, 'CREATE TABLE') === false) {
                    continue;
                }
                if (stripos($statement, 'COLLATE') === false) {
                    $offenders[] = $name;
                    continue;
                }
                // The estate's only sanctioned collation; 'COLLATE=utf8mb4_0900_ai_ci'
                // would satisfy a bare-presence check while re-introducing drift.
                if (stripos($statement, 'utf8mb4_unicode_ci') === false) {
                    $offenders[] = $name . ' (COLLATE present but not utf8mb4_unicode_ci)';
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * The ten drifted tables named in 120's header are exactly the set the
     * conversion executes — no table silently leaves (or joins) the fold-back.
     */
    public function test_the_collation_conversion_covers_exactly_the_ten_drifted_tables(): void
    {
        $drifted = [
            'access_schedules',
            'active_streams',
            'audiobook_progress',
            'book_progress',
            'network_health_log',
            'profile_access_schedule',
            'profile_stream_limits',
            'profile_tags',
            'schema_migrations',
            'user_recommendations',
        ];

        $converted = [];
        foreach (self::statementsIn(self::FILES[120]) as $statement) {
            self::assertSame(1, preg_match('/^ALTER TABLE `([^`]+)` CONVERT TO CHARACTER SET/', $statement, $m));
            $converted[] = $m[1];
        }

        sort($converted);
        self::assertSame($drifted, $converted);
    }
}
