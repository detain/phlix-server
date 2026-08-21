<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Common\Database\MigrationRunner;
use Phlix\Media\Library\ScanJobRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * S341 — cross-repo guard: `ScanJobRepository::ALLOWED_TYPES` must equal the
 * `library_scan_jobs.type` ENUM as the migration chain actually declares it.
 *
 * ## Why a static test, and what it is actually protecting
 *
 * The `library_scan_jobs.type` ENUM is declared in migration 027 and widened by
 * 030, 081, 084 and 101. The application-level allowlist
 * `ScanJobRepository::ALLOWED_TYPES` mirrors it, and phlix-ui's `SCAN_JOB_TYPES`
 * array mirrors BOTH. Migration 101 (S284) appended `media_assets` and its ship
 * note claimed "zero client change": the admin percentage needed none, but the
 * TYPE LABEL did — the admin UI rendered the raw ENUM string instead of a label,
 * silently.
 *
 * Two hand-written lists (the repository's allowlist and the UI's array) cannot
 * guard each other, so this test pins the SERVER side of the boundary to the
 * migrations: when the ENUM grows a member and nowhere else updates, this test
 * goes red and its failure message names phlix-ui's `SCAN_JOB_TYPES` as the
 * mirror to update.
 *
 * ## Why parse migration 101 specifically
 *
 * MySQL stores an ENUM by index and every widening re-declares the full set, so
 * the LAST migration to declare the type ENUM is the complete current set.
 * Migration 101 is that file today; {@see self::test_101_is_the_latest_migration_to_declare_the_type_enum()}
 * fails loudly the day a later migration widens the ENUM again, forcing this
 * test to parse the new latest declarer instead of silently comparing
 * `ALLOWED_TYPES` against a stale 9-member list.
 *
 * ## Why through MigrationRunner::splitStatements
 *
 * The runner strips comments and splits on `;` with a quote-aware scanner, so a
 * semicolon inside the `COMMENT '…'` string literal would shred the ALTER into
 * fragments that each fail on their own — and nothing else in the suite would
 * notice, because the file is only executed against a real MySQL, which most CI
 * jobs do not have. Splitting through the runner's own method covers a change
 * to the splitter as well as a change to the SQL.
 */
final class ScanJobsEnumMigrationTest extends TestCase
{
    /** The migration this test parses — must stay the LATEST declarer of the ENUM. */
    private const MIGRATION = '101_library_scan_jobs_media_assets_type.sql';

    /** The phlix-ui mirror, named in failure messages. */
    private const PHLIX_UI_MIRROR = 'phlix-ui `src/api/admin/libraries.ts` `SCAN_JOB_TYPES`';

    private static function migrationsDir(): string
    {
        return dirname(__DIR__, 4) . '/migrations';
    }

    private static function path(): string
    {
        return self::migrationsDir() . '/' . self::MIGRATION;
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

    /**
     * Every migration whose EXECUTABLE SQL (full-line comments stripped) declares
     * the `library_scan_jobs.type` ENUM via `ALTER TABLE ... MODIFY COLUMN`, in
     * runner order.
     *
     * @return list<string>
     */
    private static function enumDeclarers(): array
    {
        $files = glob(self::migrationsDir() . '/*.sql');
        self::assertIsArray($files, 'migrations/*.sql must be readable');
        sort($files);

        $declarers = [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw, basename($file) . ' must be readable');
            // Full-line comments stripped: a migration header may *discuss* the
            // ENUM (101's does, at length) without declaring it.
            $executable = preg_replace('/^\s*(--|#).*$/m', '', $raw) ?? $raw;
            if (
                preg_match(
                    '/ALTER\s+TABLE\s+`library_scan_jobs`\s+MODIFY\s+COLUMN\s+`type`\s+ENUM\(/i',
                    $executable,
                ) === 1
            ) {
                $declarers[] = basename($file);
            }
        }

        return $declarers;
    }

    /**
     * The `library_scan_jobs.type` ENUM members, in the column's own ordinal
     * order, as migration 101 declares them.
     *
     * @return list<string>
     */
    private static function enumMembers(): array
    {
        $statement = self::statements()[0];

        $matched = preg_match('/`type`\s+ENUM\s*\((.*?)\)/s', $statement, $m);
        self::assertSame(
            1,
            $matched,
            'Migration ' . self::MIGRATION . ' must declare the `type` ENUM; '
            . "statement produced by the runner:\n" . $statement
        );

        preg_match_all("/'([^']*)'/", $m[1], $members);

        self::assertGreaterThan(
            0,
            count($members[1]),
            'The ENUM declaration in ' . self::MIGRATION . ' parsed to ZERO members — '
            . 'a "nothing matched" parse cannot prove an equality'
        );

        return $members[1];
    }

    /**
     * Migration 101 is the LAST migration in the chain that declares the type
     * ENUM. This whole test parses 101, so the day a later migration widens the
     * ENUM again, this must fail loudly instead of comparing `ALLOWED_TYPES`
     * against a stale 9-member list while the DB has already grown.
     */
    public function test_101_is_the_latest_migration_to_declare_the_type_enum(): void
    {
        $declarers = self::enumDeclarers();

        $latest = $declarers[count($declarers) - 1] ?? null;

        self::assertSame(
            self::MIGRATION,
            $latest,
            'The latest migration declaring the `library_scan_jobs.type` ENUM is '
            . ($latest ?? '<none>') . ' — expected ' . self::MIGRATION . '. '
            . 'Declarers in runner order: ' . implode(', ', $declarers) . '. '
            . 'When the ENUM is widened, parse the NEW latest declarer in this test, '
            . 'then append the new member to ' . self::PHLIX_UI_MIRROR . '.'
        );
    }

    /**
     * 🚨 THE SPLIT. The runner must see exactly ONE statement.
     *
     * A `;` inside the `COMMENT '…'` string literal would shred the ALTER into
     * fragments. Asserted through `MigrationRunner::splitStatements()` itself, so
     * a change to the splitter is covered as well as a change to the SQL.
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
        self::assertStringStartsWith('ALTER TABLE `library_scan_jobs`', trim($statements[0]));
    }

    /**
     * THE CROSS-REPO GUARD. `ScanJobRepository::ALLOWED_TYPES` must equal the
     * ENUM the migration chain actually declares — in value AND in order, because
     * MySQL stores ENUMs by index and an insertion in the middle re-numbers
     * every stored row.
     *
     * The failure message names phlix-ui's `SCAN_JOB_TYPES` as the mirror to
     * update: the step exists precisely because the UI array drifted (8 members
     * while the DB had 9) and NO server-side gate noticed.
     */
    public function test_allowed_types_equals_the_enum_parsed_from_the_migration(): void
    {
        $enum = self::enumMembers();
        $allowed = (new ReflectionClassConstant(ScanJobRepository::class, 'ALLOWED_TYPES'))->getValue();
        self::assertIsArray($allowed, 'ScanJobRepository::ALLOWED_TYPES must be an array');

        self::assertSame(
            $enum,
            $allowed,
            'ScanJobRepository::ALLOWED_TYPES (' . count($allowed) . ' members) does not equal the '
            . count($enum) . '-member `library_scan_jobs.type` ENUM parsed from migrations/'
            . self::MIGRATION . '. If the ENUM gained a member, append it here AND to the mirror '
            . self::PHLIX_UI_MIRROR . ' — two hand-written lists cannot guard each other. Do NOT '
            . 'reorder existing members: MySQL stores ENUMs by index.'
        );
    }
}
