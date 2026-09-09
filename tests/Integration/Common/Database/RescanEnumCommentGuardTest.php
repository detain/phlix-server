<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Common\Database;

use Phlix\Common\Database\MigrationRunner;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * S154 — real-MySQL proof that migration 104 corrects the false
 * "rescan=purge+rescan" claim baked into the live `library_scan_jobs.type` column
 * COMMENT while moving NOTHING ELSE: the nine ENUM members and their ordinals,
 * NOT NULL, DEFAULT 'scan', the utf8mb4_unicode_ci collation and every other
 * column are byte-identical before and after; the migration replays idempotently;
 * and migration 084's own ledger checksum is untouched.
 *
 * Why real MySQL is the only venue (S345 rule 2 — the real artefact, not an idea
 * of it): an ENUM re-declaration, its stored ordinals, column collation
 * inheritance and the runner's checksum normalisation are observable only against
 * a live server. An in-memory double cannot express any of them.
 *
 * The FALSE start state is established by re-applying migration 101 — the last
 * declarer before 104 and the file whose comment ships today — so this proves 104
 * against the real artefact and stays correct whether or not CI's chain already
 * ran 104.
 *
 * SAFETY: every scenario mutates the shared `library_scan_jobs.type` column and
 * restores the ambient comment captured in setUp. The mutation is COMMENT-ONLY (the
 * ENUM set is identical across 101/104), so no stored row's value or ordinal can
 * change. The suite runs serially.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
final class RescanEnumCommentGuardTest extends TestCase
{
    use RequiresRealDatabase;

    /** Survival token — code-resident; asserted present in migration 104 too. */
    public const SURVIVAL_TOKEN = 'S154ENUMCOMMENTX9D2';

    private const MIGRATION_104 = '104_library_scan_jobs_rescan_comment_correction.sql';

    private const MIGRATION_101 = '101_library_scan_jobs_media_assets_type.sql';

    private const MIGRATION_084 = '084_library_scan_jobs_maintenance_types.sql';

    /** 084's recorded checksum — must stay this (its COMMENT is executable SQL). */
    private const EXPECTED_084_CHECKSUM = 'c948944dfc1bbb4e82b0675efdb341e2';

    /** The exact falsehood S154 removes from the live schema. */
    private const FALSE_PHRASE = 'rescan=purge+rescan';

    /**
     * The nine ENUM members + ordinals the live column must keep byte-identical
     * across 101 -> 104 (084's eight, plus 101's media_assets).
     */
    private const ENUM_MEMBERS = [
        'scan',
        'rescan',
        'metadata',
        'metadata_refresh',
        'prune',
        'clear_metadata',
        'clear_artwork',
        'delete_all',
        'media_assets',
    ];

    private ?Connection $db = null;

    /** The column's ambient comment as found, restored verbatim in tearDown. */
    private ?string $ambientComment = null;

    private function db(): Connection
    {
        if ($this->db === null) {
            $this->db = $this->requireRealDatabase(
                'the library_scan_jobs ENUM-comment proof runs only against a live MySQL.'
            );
        }

        return $this->db;
    }

    protected function setUp(): void
    {
        $rows = $this->db()->query(
            "SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'library_scan_jobs'
                AND COLUMN_NAME = 'type'"
        );
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows, 'library_scan_jobs.type must exist (migration chain applied)');
        $this->ambientComment = (string) $rows[0]['COLUMN_COMMENT'];
    }

    protected function tearDown(): void
    {
        // Restore the ambient comment so the shared table is exactly as found.
        if ($this->db !== null && $this->ambientComment !== null) {
            $type = $this->columnState()['type']['COLUMN_TYPE'];
            $this->db->query($this->setTypeCommentSql((string) $type, $this->ambientComment));
        }
    }

    // ------------------------------------------------------------------
    // Migration-file proofs (no schema mutation).
    // ------------------------------------------------------------------

    public function test_migration_104_carries_the_survival_token(): void
    {
        $this->assertStringContainsString(
            self::SURVIVAL_TOKEN,
            $this->migrationSql(self::MIGRATION_104),
            'migration 104 must carry the survival token so a tokenized-corpus search '
            . 'proves the fix shipped in the tree the migration runs from.'
        );
    }

    public function test_migration_104_is_one_statement_with_a_semicolon_free_comment(): void
    {
        $statements = $this->runnerStatements($this->migrationSql(self::MIGRATION_104));
        $this->assertCount(
            1,
            $statements,
            'the runner must see exactly one ALTER; a `;` inside the COMMENT would shred it.'
        );
        $this->assertStringStartsWith('ALTER TABLE `library_scan_jobs`', trim($statements[0]));
    }

    public function test_migration_104_declares_the_expected_nine_enum_members_in_order(): void
    {
        $statements = $this->runnerStatements($this->migrationSql(self::MIGRATION_104));
        $members = $this->enumMembersFromStatement($statements[0]);
        $this->assertSame(
            self::ENUM_MEMBERS,
            $members,
            '104 must restate the FULL live ENUM — 084 eight members plus 101 media_assets — '
            . 'in ordinal order; a shorter list would silently DROP a member.'
        );
    }

    public function test_migration_084_checksum_is_unchanged(): void
    {
        $raw = $this->migrationSql(self::MIGRATION_084);
        $stripped = $this->runnerChecksum($raw);

        // The ledger stores the COMMENT-STRIPPED hash; 084 must still produce the
        // exact value every deployed install already holds.
        $this->assertSame(
            self::EXPECTED_084_CHECKSUM,
            $stripped,
            'migration 084 was edited: its comment-stripped checksum no longer matches the '
            . 'ledger value every install holds, which would force a re-apply everywhere.'
        );

        // NEGATIVE CONTROL (S345 rule 3): the whole proof hinges on the ledger
        // checksum being the STRIPPED hash, not the raw file md5. If 084 ever lost
        // its full-line comments the two would collapse to equal and the assertion
        // above would become vacuous — this guard reddens exactly then.
        $this->assertNotSame(
            md5($raw),
            $stripped,
            'the stripped checksum unexpectedly equals the raw md5 — 084 appears to carry no '
            . 'full-line comments, so the strip-based ledger proof no longer means anything.'
        );

        // If this database holds the ledger row for 084, it must equal the recomputed value.
        $ledgerTable = $this->db()->query("SHOW TABLES LIKE 'schema_migrations'");
        $this->assertIsArray($ledgerTable);
        if ($ledgerTable !== []) {
            $row = $this->db()->query(
                'SELECT checksum FROM schema_migrations WHERE name = ?',
                [self::MIGRATION_084]
            );
            $this->assertIsArray($row);
            if ($row !== []) {
                $this->assertSame(
                    self::EXPECTED_084_CHECKSUM,
                    (string) $row[0]['checksum'],
                    '084 ledger row diverged from the recomputed checksum.'
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Live-schema proofs against real MySQL.
    // ------------------------------------------------------------------

    public function test_104_moves_only_the_comment_and_is_idempotent(): void
    {
        // Deterministic FALSE start: re-apply 101, the file whose comment ships today.
        $this->runMigration(self::MIGRATION_101);
        $before = $this->columnState();
        $this->assertStringContainsString(
            self::FALSE_PHRASE,
            (string) $before['type']['COLUMN_COMMENT'],
            'POSITIVE CONTROL: after applying 101 the live comment must still carry the '
            . 'falsehood, otherwise "the comment moved" proves nothing.'
        );

        // Apply the fix.
        $this->runMigration(self::MIGRATION_104);
        $after = $this->columnState();

        // 1. Every attribute of `type` except COLUMN_COMMENT is byte-identical.
        foreach (
            [
                'ORDINAL_POSITION',
                'COLUMN_TYPE',
                'IS_NULLABLE',
                'COLUMN_DEFAULT',
                'COLLATION_NAME',
                'CHARACTER_SET_NAME',
                'COLUMN_KEY',
                'EXTRA',
            ] as $field
        ) {
            $this->assertSame(
                $before['type'][$field],
                $after['type'][$field],
                "migration 104 must not change the type column's $field."
            );
        }

        // 2. ENUM members + ordinals byte-identical (COLUMN_TYPE encodes ordinal order).
        $this->assertSame(
            $before['type']['COLUMN_TYPE'],
            $after['type']['COLUMN_TYPE'],
            'the nine ENUM members and their ordinals must be byte-identical before and after.'
        );

        // 3. Only the COMMENT moved — and moved to the exact text 104 declares.
        $this->assertNotSame(
            $before['type']['COLUMN_COMMENT'],
            $after['type']['COLUMN_COMMENT'],
            'the type column COMMENT was expected to change.'
        );
        $this->assertStringNotContainsString(
            self::FALSE_PHRASE,
            (string) $after['type']['COLUMN_COMMENT'],
            'the corrected comment must not restate the purge+rescan falsehood.'
        );
        $statements = $this->runnerStatements($this->migrationSql(self::MIGRATION_104));
        $expected = $this->commentFromStatement($statements[0]);
        $this->assertSame(
            $expected,
            $after['type']['COLUMN_COMMENT'],
            'the live comment must equal exactly what migration 104 declares.'
        );

        // 4. Every OTHER column on the table is byte-for-byte unchanged.
        foreach ($before as $name => $row) {
            if ($name === 'type') {
                continue;
            }
            $this->assertSame($row, $after[$name], "column $name must be untouched by 104.");
        }

        // 5. Idempotent replay: a second run changes nothing further.
        $this->runMigration(self::MIGRATION_104);
        $after2 = $this->columnState();
        $this->assertSame($after, $after2, 're-running migration 104 must be a total no-op.');
    }

    public function test_corrected_comment_describes_rescan_truthfully(): void
    {
        $this->runMigration(self::MIGRATION_104);
        $comment = (string) $this->columnState()['type']['COLUMN_COMMENT'];
        $this->assertMatchesRegularExpression(
            '/rescan=[^,]*(re-read|re-?reads|reads? every file)[^,]*(prune|remov)[^,]*(gone|missing|no longer)/i',
            $comment,
            'the rescan clause must describe re-reading files and pruning only gone items.'
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function migrationsDir(): string
    {
        return dirname(__DIR__, 4) . '/migrations';
    }

    private function migrationSql(string $basename): string
    {
        $sql = file_get_contents($this->migrationsDir() . '/' . $basename);
        $this->assertIsString($sql, $basename . ' must be readable');

        return $sql;
    }

    /** @return list<string> the statements the RUNNER would execute. */
    private function runnerStatements(string $sql): array
    {
        $split = new ReflectionMethod(MigrationRunner::class, 'splitStatements');
        $split->setAccessible(true);
        /** @var list<string> $statements */
        $statements = $split->invoke(null, $sql);

        return $statements;
    }

    private function runnerChecksum(string $sql): string
    {
        $checksum = new ReflectionMethod(MigrationRunner::class, 'checksum');
        $checksum->setAccessible(true);

        return (string) $checksum->invoke(null, $sql);
    }

    private function runMigration(string $basename): void
    {
        foreach ($this->runnerStatements($this->migrationSql($basename)) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->db()->query($statement);
            }
        }
    }

    /** @return array<string,array<string,mixed>> keyed by COLUMN_NAME. */
    private function columnState(): array
    {
        $rows = $this->db()->query(
            "SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                    COLLATION_NAME, CHARACTER_SET_NAME, COLUMN_KEY, EXTRA, COLUMN_COMMENT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'library_scan_jobs'
              ORDER BY ORDINAL_POSITION"
        );
        $this->assertIsArray($rows);
        $state = [];
        foreach ($rows as $row) {
            $state[(string) $row['COLUMN_NAME']] = $row;
        }

        return $state;
    }

    /** @return list<string> ENUM members in declaration (ordinal) order. */
    private function enumMembersFromStatement(string $statement): array
    {
        $matched = preg_match('/`type`\s+ENUM\s*\((.*?)\)/is', $statement, $m);
        $this->assertSame(1, $matched, '104 must declare the `type` ENUM');
        preg_match_all("/'([^']*)'/", $m[1], $members);

        return $members[1];
    }

    private function commentFromStatement(string $statement): string
    {
        $matched = preg_match("/COMMENT\s+'((?:[^']|'')*)'/is", $statement, $m);
        $this->assertSame(1, $matched, '104 must declare a COMMENT string literal');

        return str_replace("''", "'", $m[1]);
    }

    private function setTypeCommentSql(string $columnType, string $comment): string
    {
        $escaped = str_replace("'", "''", $comment);

        return "ALTER TABLE `library_scan_jobs` MODIFY COLUMN `type` " . $columnType
            . " NOT NULL DEFAULT 'scan' COMMENT '" . $escaped . "'";
    }
}
