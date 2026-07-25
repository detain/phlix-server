<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Dlna\UpnpClassMap;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Media\MediaItemType;
use Phlix\Stats\StorageSnapshotHelper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The DRIFT ALARM for the `media_items.type` vocabulary (S102).
 *
 * ## Why this test is different from the ones it supersedes
 *
 * `StorageSnapshotHelperTest` and `UpnpClassMapTest` already cross-check the PHP
 * copies of this ENUM against EACH OTHER — but each of them also hardcodes the
 * member list a third and fourth time, and NONE of them looks at the database
 * schema. So all the PHP copies could agree perfectly while the actual column
 * disagreed with every one of them. That is not hypothetical: it is exactly the
 * S102 production bug. `stats_playback_events.media_type` sat at migration 019's
 * FOUR members while the PHP side had thirteen, so once S31 began writing the
 * real type, every episode / track / photo play raised MySQL error 1265
 * ("Data truncated for column 'media_type'") under `STRICT_TRANS_TABLES` and threw
 * an uncaught `PDOException` out of the HTTP worker. 235 rows, all `movie`, and
 * every non-movie play lost.
 *
 * This test closes that loop: it PARSES THE MIGRATION SQL and compares the real
 * column ENUMs against {@see MediaItemType::ALL} and against every consumer map.
 * It needs no database, so it runs everywhere the unit suite runs — a widened
 * `media_items.type` that forgets any of its dependants is now a red test rather
 * than a runtime 500 discovered in production.
 *
 * ## What the parser accepts (S102 review r1, HIGH-1)
 *
 * The first version of the parser required bare (un-backticked) identifiers and
 * the literal `COLUMN` keyword, so it was BLIND to
 * ``MODIFY COLUMN `type` …`` (migrations 030, 081, 084) and to `MODIFY type …`
 * (migrations 068, 083, 091) — two styles already in this repo. A widening
 * written in either style left the parser holding the PREVIOUS file's member
 * list, so the comparisons below passed against a stale vocabulary and the alarm
 * failed OPEN. {@see testTheParserSeesEveryDefinitionStyleThisRepoUses} now pins
 * every style: optional backticks on table AND column, `MODIFY` with or without
 * `COLUMN`, `CHANGE [COLUMN] <old> <new>`, `ADD [COLUMN]`, a schema-qualified
 * table, a multi-clause `ALTER` where the `MODIFY` is not the first clause, and a
 * multi-line `ENUM(…)` body.
 *
 * `ADD [COLUMN]` was the second fail-open, found by review r2 (LOW-3): the parser
 * saw only `MODIFY`/`CHANGE`/`CREATE TABLE`, so a `DROP COLUMN` + `ADD COLUMN`
 * re-definition of a tracked column left the whole alarm green, and the one ENUM
 * column of 29 in a fully migrated schema it could not read was `users.status`
 * (`migrations/037_users_status.sql:38`). Both are now covered, the real migration
 * included ({@see testTheParserSeesTheAddColumnStyleInARealMigration}). The
 * remaining, deliberate blind spots are named on
 * {@see \Phlix\Media\MediaItemType}: runtime-assembled `PREPARE` DDL, a bare
 * `DROP COLUMN` with no re-`ADD`, and `migrations/*.php` — the last of which
 * {@see testNoPhpMigrationDefinesAnEnumTheGuardCannotRead} keeps honest.
 *
 * ## Why the parser masks string literals first
 *
 * `migrations/011_music_library.sql:18` builds its `ALTER` inside a
 * `PREPARE`/`EXECUTE` string with DOUBLED quotes (`''movie''`). A regex reading
 * quotes directly parsed that as 24 EMPTY-STRING "members", and the old
 * trustworthiness guard (`assertNotSame([], …)`) could not tell that garbage from
 * a real result. So the parser now replaces every string literal with a
 * placeholder BEFORE looking for clause structure: dynamic SQL contains no
 * `ENUM(` at all once masked, which makes it skipped rather than half-parsed.
 * Pinned by {@see testMigration011sDynamicAlterIsSkippedNotHalfParsed}.
 *
 * @covers \Phlix\Media\MediaItemType
 */
final class MediaItemTypeDriftTest extends TestCase
{
    /**
     * Sentinel byte wrapping a masked string literal's index. Cannot occur in
     * migration SQL, which is plain ASCII text.
     */
    private const LITERAL_MARK = "\x01";

    /**
     * Absolute path to the migrations directory.
     */
    private static function migrationsDir(): string
    {
        return dirname(__DIR__, 3) . '/migrations';
    }

    /**
     * Resolve a column's EFFECTIVE ENUM members by replaying the migration SQL in
     * filename order and keeping the LAST definition found.
     *
     * @param string $table  Table the column belongs to.
     * @param string $column Column name.
     *
     * @return list<string> Members in column order; empty when no definition exists.
     */
    private function enumMembersFromMigrations(string $table, string $column): array
    {
        $files = glob(self::migrationsDir() . '/*.sql');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'No migration SQL found');
        sort($files);

        $members = [];

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }

            foreach ($this->enumDefinitionsIn($sql, $table, $column) as $definition) {
                $members = $definition;
            }
        }

        return $members;
    }

    /**
     * Every PLAUSIBLE ENUM member list defining `$table`.`$column` in one chunk of
     * SQL, in source order.
     *
     * Recognised:
     * - `CREATE TABLE [IF NOT EXISTS] [schema.]<table> ( … <column> ENUM(…) … )`
     * - `ALTER TABLE [schema.]<table> … MODIFY [COLUMN] <column> ENUM(…)`
     * - `ALTER TABLE [schema.]<table> … CHANGE [COLUMN] <old> <column> ENUM(…)`
     * - `ALTER TABLE [schema.]<table> … ADD [COLUMN] <column> ENUM(…)`
     *
     * Identifiers may be backtick-quoted, the table may be schema-qualified, the
     * `ALTER` may carry other clauses before and after the one that matters, and
     * whitespace/newlines/case are all irrelevant. A `CHANGE` that renames the
     * column AWAY is deliberately not a definition of it.
     *
     * `ADD [COLUMN]` is read for two reasons (S102 review r2, LOW-3). It is a real
     * style in this repo — `migrations/037_users_status.sql:38` defines
     * `users.status` that way, and it was the ONE column of the 29 in a fully
     * migrated schema this parser could not see — and, more importantly here, a
     * `DROP COLUMN` + `ADD COLUMN` pair is a way to redefine a tracked ENUM that
     * left the whole alarm GREEN. See {@see testTheParserSeesTheAddColumnStyleInARealMigration}.
     *
     * @return list<list<string>>
     */
    private function enumDefinitionsIn(string $sql, string $table, string $column): array
    {
        $out = [];

        foreach ($this->statementsIn($sql) as $statement) {
            [$masked, $literals] = $this->maskStringLiterals($statement);

            foreach ($this->enumBodiesIn($masked, $table, $column) as $body) {
                $members = $this->membersFrom($body, $literals);
                if ($members !== null) {
                    $out[] = $members;
                }
            }
        }

        return $out;
    }

    /**
     * The LAST definition of `$table`.`$column` in one chunk of SQL, or `[]`.
     *
     * @return list<string>
     */
    private function lastEnumDefinitionIn(string $sql, string $table, string $column): array
    {
        $definitions = $this->enumDefinitionsIn($sql, $table, $column);

        return $definitions === [] ? [] : $definitions[count($definitions) - 1];
    }

    /**
     * Split SQL into the statements the migration runner would execute.
     *
     * Deliberately mirrors `MigrationRunner::splitStatements()`: comments are
     * dropped and `;` only ends a statement outside string literals and
     * backtick-quoted identifiers, so the test sees the same statement boundaries
     * production does.
     *
     * @return list<string>
     */
    private function statementsIn(string $sql): array
    {
        $statements = [];
        $buffer = '';
        // '' (top level), "'"/'"'/'`' (quoted), '--' (line comment), '/*' (block).
        $context = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($context === "'" || $context === '"' || $context === '`') {
                $buffer .= $ch;
                if ($ch === $context && $next === $context) {
                    // Doubled quote — an escaped quote, still inside the literal.
                    $buffer .= $next;
                    $i++;
                } elseif ($ch === '\\' && $context !== '`' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                } elseif ($ch === $context) {
                    $context = '';
                }
                continue;
            }

            if ($context === '--') {
                if ($ch === "\n") {
                    $buffer .= $ch;
                    $context = '';
                }
                continue;
            }

            if ($context === '/*') {
                if ($ch === '*' && $next === '/') {
                    $i++;
                    $context = '';
                }
                continue;
            }

            if ($ch === '-' && $next === '-') {
                $context = '--';
                $i++;
                continue;
            }
            if ($ch === '#') {
                $context = '--';
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $context = '/*';
                $i++;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $context = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ';') {
                $part = trim($buffer);
                if ($part !== '') {
                    $statements[] = $part;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $part = trim($buffer);
        if ($part !== '') {
            $statements[] = $part;
        }

        return $statements;
    }

    /**
     * Replace every string literal with a placeholder so clause structure can be
     * matched without a literal's CONTENTS ever being mistaken for structure.
     *
     * This is what makes `migrations/011`'s prepared-statement `ALTER` invisible:
     * the whole DDL lives inside one literal, so the masked statement contains no
     * `ENUM(` and yields nothing, instead of 24 empty-string members.
     *
     * @return array{0: string, 1: list<string>} Masked SQL, decoded literals by index.
     */
    private function maskStringLiterals(string $statement): array
    {
        $masked = '';
        /** @var list<string> $literals */
        $literals = [];
        $len = strlen($statement);

        for ($i = 0; $i < $len; $i++) {
            $ch = $statement[$i];

            if ($ch !== "'" && $ch !== '"') {
                $masked .= $ch;
                continue;
            }

            $quote = $ch;
            $value = '';
            for ($i++; $i < $len; $i++) {
                $c = $statement[$i];
                if ($c === $quote) {
                    if ($i + 1 < $len && $statement[$i + 1] === $quote) {
                        $value .= $quote;
                        $i++;
                        continue;
                    }
                    break;
                }
                if ($c === '\\' && $i + 1 < $len) {
                    $value .= $statement[$i + 1];
                    $i++;
                    continue;
                }
                $value .= $c;
            }

            $literals[] = $value;
            $masked .= self::LITERAL_MARK . (count($literals) - 1) . self::LITERAL_MARK;
        }

        return [$masked, $literals];
    }

    /**
     * Raw (masked) `ENUM(…)` bodies defining `$table`.`$column` in ONE statement.
     *
     * @return list<string>
     */
    private function enumBodiesIn(string $maskedStatement, string $table, string $column): array
    {
        $identifier = '`?[A-Za-z0-9_$]+`?';
        $tableRef = '(?:' . $identifier . '\s*\.\s*)?`?' . preg_quote($table, '/') . '`?';
        $col = '`?' . preg_quote($column, '/') . '`?';
        $out = [];

        $alter = [];
        if (
            preg_match(
                '/\AALTER\s+(?:ONLINE\s+|IGNORE\s+)*TABLE\s+' . $tableRef . '\b(?<body>.*)\z/is',
                $maskedStatement,
                $alter
            ) === 1
        ) {
            $clauses = [];
            preg_match_all(
                '/\bMODIFY(?:\s+COLUMN)?\s+' . $col . '\s+ENUM\s*\(([^)]*)\)'
                . '|\bCHANGE(?:\s+COLUMN)?\s+' . $identifier . '\s+' . $col . '\s+ENUM\s*\(([^)]*)\)'
                . '|\bADD(?:\s+COLUMN)?\s+' . $col . '\s+ENUM\s*\(([^)]*)\)/is',
                $alter['body'],
                $clauses,
                PREG_SET_ORDER
            );
            foreach ($clauses as $clause) {
                // Exactly one of the three alternatives captured a body.
                $body = '';
                foreach ([1, 2, 3] as $group) {
                    if (($clause[$group] ?? '') !== '') {
                        $body = $clause[$group];
                        break;
                    }
                }
                $out[] = $body;
            }
        }

        $create = [];
        if (
            preg_match(
                '/\ACREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $tableRef
                . '\s*\((?<body>.*)\)/is',
                $maskedStatement,
                $create
            ) === 1
        ) {
            $col_ = [];
            if (
                preg_match(
                    '/(?:^|,)\s*' . $col . '\s+ENUM\s*\(([^)]*)\)/im',
                    $create['body'],
                    $col_
                ) === 1
            ) {
                $out[] = $col_[1];
            }
        }

        return $out;
    }

    /**
     * Decode a masked `ENUM(…)` body into its members, or NULL when the body is
     * not a plain quoted-member list.
     *
     * Returning NULL rather than a partial list is the LOW-8 fix: a body carrying
     * anything other than string literals and separators (an expression, a
     * placeholder-free token, a member that is empty or non-printable) is NOT
     * treated as a definition at all, so garbage can never masquerade as a
     * successfully parsed vocabulary.
     *
     * @param list<string> $literals
     *
     * @return list<string>|null
     */
    private function membersFrom(string $body, array $literals): ?array
    {
        $residue = preg_replace(
            '/' . self::LITERAL_MARK . '\d+' . self::LITERAL_MARK . '|[\s,]+/',
            '',
            $body
        );
        if ($residue !== '') {
            // Something other than quoted members and separators is in there.
            return null;
        }

        $found = [];
        preg_match_all('/' . self::LITERAL_MARK . '(\d+)' . self::LITERAL_MARK . '/', $body, $found);

        $members = [];
        foreach ($found[1] as $index) {
            $value = $literals[(int) $index] ?? null;
            if ($value === null || !$this->isPlausibleMember($value)) {
                return null;
            }
            $members[] = $value;
        }

        return $members === [] ? null : $members;
    }

    /**
     * Could `$value` be a real ENUM member? Rejects the empty string (the exact
     * garbage `migrations/011`'s doubled quotes used to yield), leading
     * whitespace, control bytes and implausibly long values.
     */
    private function isPlausibleMember(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 64
            && preg_match('/\A[\x21-\x7E][\x20-\x7E]*\z/', $value) === 1;
    }

    /**
     * Every `ALTER`/`CREATE` style this repo actually uses must be VISIBLE to the
     * parser. Each case widens `media_items.type` to a member the PHP side does
     * not have, which is exactly the mutation the alarm exists to catch — so if
     * the parser cannot see the style, the alarm fails open.
     *
     * @return array<string, array{0: string}>
     */
    public static function definitionStyleProvider(): array
    {
        return [
            // migration 034 — the only style the original parser handled.
            'MODIFY COLUMN, bare identifiers' => [
                "ALTER TABLE media_items\n    MODIFY COLUMN type ENUM('movie', 'podcast') NOT NULL;",
            ],
            // migrations 030:16, 081:21, 084:39 — was INVISIBLE (review r1 HIGH-1).
            'MODIFY COLUMN, backticked column' => [
                "ALTER TABLE media_items\n    MODIFY COLUMN `type` ENUM('movie', 'podcast') NOT NULL;",
            ],
            // migrations 068:21, 083:25, 091:20 — was INVISIBLE (review r1 HIGH-1).
            'MODIFY without the COLUMN keyword' => [
                "ALTER TABLE media_items MODIFY type ENUM('movie', 'podcast') NOT NULL;",
            ],
            'MODIFY without COLUMN, backticked column' => [
                "ALTER TABLE `media_items` MODIFY `type` ENUM('movie', 'podcast') NOT NULL;",
            ],
            // migration 084:38 backticks the table too.
            'backticked table' => [
                "ALTER TABLE `media_items` MODIFY COLUMN `type` ENUM('movie', 'podcast') NOT NULL;",
            ],
            'schema-qualified table' => [
                "ALTER TABLE `phlix`.`media_items` MODIFY COLUMN `type` ENUM('movie', 'podcast') NOT NULL;",
            ],
            'schema-qualified table, no backticks' => [
                "ALTER TABLE phlix.media_items MODIFY COLUMN type ENUM('movie', 'podcast') NOT NULL;",
            ],
            'CHANGE COLUMN renaming a column TO type' => [
                "ALTER TABLE media_items CHANGE COLUMN item_type type ENUM('movie', 'podcast') NOT NULL;",
            ],
            'CHANGE without the COLUMN keyword' => [
                "ALTER TABLE media_items CHANGE `item_type` `type` ENUM('movie', 'podcast') NOT NULL;",
            ],
            'multi-clause ALTER where MODIFY is not the first clause' => [
                "ALTER TABLE media_items\n"
                . "    ADD COLUMN probe_state VARCHAR(16) NULL,\n"
                . "    MODIFY COLUMN `type` ENUM('movie', 'podcast') NOT NULL,\n"
                . "    ADD INDEX idx_probe_state (probe_state);",
            ],
            // migration 084:39 spreads the member list over many lines.
            'multi-line ENUM body' => [
                "ALTER TABLE `media_items`\n    MODIFY COLUMN `type`\n        ENUM(\n"
                . "            'movie',\n            'podcast'\n        ) NOT NULL;",
            ],
            'lower-case keywords' => [
                "alter table media_items modify column `type` enum('movie', 'podcast') not null;",
            ],
            'CREATE TABLE column line' => [
                "CREATE TABLE media_items (\n    id CHAR(36) NOT NULL,\n"
                . "    type ENUM('movie', 'podcast') NOT NULL,\n    PRIMARY KEY (id)\n) ENGINE=InnoDB;",
            ],
            'CREATE TABLE IF NOT EXISTS, backticked column' => [
                "CREATE TABLE IF NOT EXISTS `media_items` (\n    `id` CHAR(36) NOT NULL,\n"
                . "    `type` ENUM('movie', 'podcast') NOT NULL,\n    PRIMARY KEY (`id`)\n) ENGINE=InnoDB;",
            ],
            // migrations 002:59, 037:38 — was INVISIBLE (review r2 LOW-3).
            'ADD COLUMN, bare identifiers' => [
                "ALTER TABLE media_items\n    ADD COLUMN type ENUM('movie', 'podcast') NOT NULL DEFAULT 'movie';",
            ],
            'ADD without the COLUMN keyword, backticked' => [
                "ALTER TABLE `media_items` ADD `type` ENUM('movie', 'podcast') NOT NULL AFTER `id`;",
            ],
            // The destructive re-definition the ADD blindness let through whole: it
            // erases every row's type, so it is the LAST thing that should be quiet.
            'DROP COLUMN then ADD COLUMN in one ALTER' => [
                "ALTER TABLE media_items\n    DROP COLUMN type,\n"
                . "    ADD COLUMN `type` ENUM('movie', 'podcast') NOT NULL;",
            ],
            'DROP COLUMN then ADD COLUMN in two statements' => [
                "ALTER TABLE media_items DROP COLUMN type;\n"
                . "ALTER TABLE media_items ADD COLUMN type ENUM('movie', 'podcast') NOT NULL;",
            ],
        ];
    }

    /**
     * @dataProvider definitionStyleProvider
     */
    public function testTheParserSeesEveryDefinitionStyleThisRepoUses(string $sql): void
    {
        $this->assertSame(
            ['movie', 'podcast'],
            $this->lastEnumDefinitionIn($sql, 'media_items', 'type'),
            'A widening written in this style must be VISIBLE to the drift alarm. When the parser '
            . 'cannot see a definition it silently keeps the previous migration\'s member list, so '
            . 'every assertion in this class then compares a STALE vocabulary and passes.'
        );
    }

    /**
     * SQL that does NOT define this column must not be mistaken for a definition
     * — the parser failing CLOSED (a confusing false red) is as bad as failing
     * open.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonDefinitionProvider(): array
    {
        return [
            'another table entirely' => [
                "ALTER TABLE libraries MODIFY COLUMN type ENUM('movie', 'podcast') NOT NULL;",
            ],
            'a table whose name merely starts with ours' => [
                "ALTER TABLE media_items_archive MODIFY COLUMN type ENUM('movie', 'podcast') NOT NULL;",
            ],
            'another column on our table' => [
                "ALTER TABLE media_items MODIFY COLUMN sub_type ENUM('movie', 'podcast') NOT NULL;",
            ],
            'a column whose name merely starts with ours' => [
                "ALTER TABLE media_items MODIFY COLUMN `type_legacy` ENUM('movie', 'podcast') NOT NULL;",
            ],
            'CHANGE renaming our column AWAY defines the NEW name, not ours' => [
                "ALTER TABLE media_items CHANGE COLUMN type legacy_type ENUM('movie', 'podcast') NOT NULL;",
            ],
            'a non-ENUM MODIFY' => [
                'ALTER TABLE media_items MODIFY COLUMN type VARCHAR(32) NOT NULL;',
            ],
            'prose in a comment' => [
                "-- ALTER TABLE media_items MODIFY COLUMN type ENUM('movie', 'podcast') NOT NULL;\n"
                . 'ALTER TABLE media_items ADD COLUMN probe_state VARCHAR(16) NULL;',
            ],
        ];
    }

    /**
     * @dataProvider nonDefinitionProvider
     */
    public function testTheParserIgnoresSqlThatDoesNotDefineTheColumn(string $sql): void
    {
        $this->assertSame([], $this->enumDefinitionsIn($sql, 'media_items', 'type'));
    }

    /**
     * LOW-8: dynamic SQL is SKIPPED, not half-parsed. `migrations/011` builds its
     * `ALTER` inside a `PREPARE` string with doubled quotes; read naively that
     * yields 24 empty-string "members", and the old `assertNotSame([], …)` guard
     * could not tell the difference.
     */
    public function testMigration011sDynamicAlterIsSkippedNotHalfParsed(): void
    {
        $sql = file_get_contents(self::migrationsDir() . '/011_music_library.sql');
        $this->assertIsString($sql);

        $this->assertSame(
            [],
            $this->enumDefinitionsIn($sql, 'media_items', 'type'),
            'Migration 011 builds its ALTER inside a PREPARE string with doubled quotes; it must '
            . 'contribute NO definition rather than a list of empty strings.'
        );
    }

    /**
     * S102 review r2 LOW-3 — the `ADD COLUMN … ENUM(…)` style, against a REAL
     * migration rather than a fixture.
     *
     * `users.status` was the one column of the 29 ENUM columns in a fully migrated
     * schema that the parser could not see, because `migrations/037` defines it with
     * `ADD COLUMN`. On its own that is harmless (`ADD` cannot add a member to an
     * existing ENUM), but `DROP COLUMN` + `ADD COLUMN` CAN redefine a tracked
     * column, and that combination left this entire alarm green — measured
     * end to end with a scratch migration widening both tracked columns.
     */
    public function testTheParserSeesTheAddColumnStyleInARealMigration(): void
    {
        $sql = file_get_contents(self::migrationsDir() . '/037_users_status.sql');
        $this->assertIsString($sql);

        $this->assertSame(
            ['pending', 'active', 'disabled'],
            $this->lastEnumDefinitionIn($sql, 'users', 'status'),
            'migrations/037 defines users.status with ADD COLUMN. A style the parser cannot see is '
            . 'a style a widening can hide in.'
        );
    }

    /**
     * S102 review r2 LOW-4 — the guard reads `migrations/*.sql` ONLY, so an ENUM
     * defined in a `.php` migration would be invisible to every assertion here.
     *
     * That is currently safe rather than lucky: `MigrationRunner::discoverMigrationFiles()`
     * also globs `*.sql` only, so a `.php` migration is never applied automatically
     * — the three that exist (`080_backfill_missing_profiles.php`, `cleanup_072.php`,
     * `cleanup_090.php`) are hand-run data/index fixups and contain no DDL. This
     * test makes that assumption self-policing: the day someone writes schema DDL
     * into a `.php` migration, this reddens and points at the parser, instead of the
     * drift alarm quietly comparing a stale vocabulary.
     */
    public function testNoPhpMigrationDefinesAnEnumTheGuardCannotRead(): void
    {
        $files = glob(self::migrationsDir() . '/*.php');
        self::assertIsArray($files);

        $offenders = [];
        foreach ($files as $file) {
            $php = file_get_contents($file);
            if ($php === false) {
                continue;
            }
            if (preg_match('/\bENUM\s*\(/i', $php) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These .php migrations contain an ENUM definition, but this drift guard only parses '
            . 'migrations/*.sql — so the members they declare are invisible to every assertion in '
            . 'this class. Either move the DDL into a .sql migration (which is also the only kind '
            . 'MigrationRunner applies automatically) or teach enumMembersFromMigrations() to read '
            . 'PHP files: ' . implode(', ', $offenders)
        );
    }

    /**
     * LOW-8, generalised: a member list that is not a plain list of quoted,
     * printable, non-empty values is not a definition at all.
     *
     * @return array<string, array{0: string}>
     */
    public static function untrustworthyBodyProvider(): array
    {
        return [
            'doubled quotes inside a prepared statement' => [
                "SET @sql := (SELECT 'ALTER TABLE media_items MODIFY COLUMN type "
                . "ENUM(''movie'', ''podcast'') NOT NULL');",
            ],
            'an empty member' => [
                "ALTER TABLE media_items MODIFY COLUMN type ENUM('movie', '') NOT NULL;",
            ],
            'an expression instead of literals' => [
                'ALTER TABLE media_items MODIFY COLUMN type ENUM(@a, @b) NOT NULL;',
            ],
            'a member carrying a control byte' => [
                "ALTER TABLE media_items MODIFY COLUMN type ENUM('movie', \"pod\tcast\") NOT NULL;",
            ],
        ];
    }

    /**
     * @dataProvider untrustworthyBodyProvider
     */
    public function testAnUntrustworthyMemberListIsRejectedRatherThanReturned(string $sql): void
    {
        $this->assertSame([], $this->enumDefinitionsIn($sql, 'media_items', 'type'));
    }

    /**
     * The parser itself must be trustworthy — if it silently found nothing, or
     * found garbage, every other assertion here would pass vacuously.
     *
     * Asserting a KNOWN member is present (rather than merely `!== []`) is the
     * LOW-8 hardening: `assertNotSame([], …)` was satisfied by 24 empty strings.
     */
    public function testTheMigrationParserActuallyFindsTheKnownColumns(): void
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: int}> $cases */
        $cases = [
            ['media_items', 'type', 'movie', 13],
            ['stats_playback_events', 'media_type', 'episode', 13],
            ['stats_storage', 'media_type', 'book', 5],
        ];

        foreach ($cases as [$table, $column, $known, $atLeast]) {
            $members = $this->enumMembersFromMigrations($table, $column);

            $this->assertContains(
                $known,
                $members,
                sprintf(
                    'Parser did not find member "%s" in %s.%s — the assertions in this class would '
                    . 'be vacuous. Parsed: %s',
                    $known,
                    $table,
                    $column,
                    json_encode($members)
                )
            );
            $this->assertGreaterThanOrEqual(
                $atLeast,
                count($members),
                sprintf('%s.%s parsed to implausibly few members', $table, $column)
            );
            foreach ($members as $member) {
                $this->assertTrue(
                    $this->isPlausibleMember($member),
                    sprintf('%s.%s parsed a non-member value: %s', $table, $column, var_export($member, true))
                );
            }
        }
    }

    /**
     * {@see MediaItemType::ALL} is the source of truth, so it must match the
     * column it claims to describe — members AND order (ENUM ordinals are
     * positional in MySQL).
     */
    public function testMediaItemTypeMatchesTheMediaItemsColumnEnum(): void
    {
        $this->assertSame(
            $this->enumMembersFromMigrations('media_items', 'type'),
            MediaItemType::ALL,
            'MediaItemType::ALL must be the media_items.type column ENUM, verbatim and in order'
        );
    }

    /**
     * THE S102 REGRESSION. `stats_playback_events.media_type` stores the raw
     * `media_items.type` value, so it must accept every member. With migration
     * 019's four-member ENUM this assertion fails on nine of thirteen members —
     * and in production each of those nine was an uncaught `PDOException`.
     */
    public function testStatsPlaybackEventsAcceptsEveryMediaItemType(): void
    {
        $stored = $this->enumMembersFromMigrations('stats_playback_events', 'media_type');

        $this->assertSame(
            MediaItemType::ALL,
            $stored,
            'stats_playback_events.media_type stores the RAW media_items.type value, so its ENUM '
            . 'must be the same members in the same order (migration 094). A missing member is '
            . 'MySQL error 1265 on every play of that type.'
        );

        // Name the specific value that broke production, so the reason this test
        // exists survives any future refactor of the assertion above.
        $this->assertContains(
            'episode',
            $stored,
            'Every episode play writes media_type=episode; without the member the INSERT is error 1265'
        );
    }

    /**
     * `stats_storage.media_type` is deliberately COARSE — it is the one column of
     * the three with a real reader (`DashboardService::getStorageSummary()` groups
     * by it into a fixed five-key shape), so it must stay exactly
     * {@see StorageSnapshotHelper::BUCKETS} and NOT be widened to the 13 types.
     */
    public function testStatsStorageStaysTheCoarseBucketVocabulary(): void
    {
        $this->assertSame(
            $this->enumMembersFromMigrations('stats_storage', 'media_type'),
            StorageSnapshotHelper::BUCKETS,
            'stats_storage.media_type is the coarse bucket column; BUCKETS must mirror it exactly'
        );
    }

    /**
     * Every fold target must be a value the coarse column can store.
     */
    public function testEveryFoldTargetIsAStatsStorageEnumMember(): void
    {
        $storageEnum = $this->enumMembersFromMigrations('stats_storage', 'media_type');

        foreach (StorageSnapshotHelper::TYPE_TO_BUCKET as $type => $bucket) {
            $this->assertContains(
                $bucket,
                $storageEnum,
                sprintf('Type "%s" folds to "%s", which stats_storage.media_type cannot store', $type, $bucket)
            );
        }
    }

    /**
     * The storage fold must be exhaustive over the vocabulary: an unmapped type is
     * now REFUSED by the writer (S102 review r1 MED-3) rather than misfiled, so a
     * missing key means those bytes never reach the dashboard at all.
     */
    public function testStorageFoldCoversEveryType(): void
    {
        $this->assertSame(
            $this->sorted(MediaItemType::ALL),
            $this->sorted(array_keys(StorageSnapshotHelper::TYPE_TO_BUCKET)),
            'StorageSnapshotHelper::TYPE_TO_BUCKET must have one key per MediaItemType::ALL member'
        );
    }

    /**
     * The DLNA class map must be exhaustive too, or the affected items serve as
     * the generic `object.item` fallback.
     */
    public function testUpnpClassMapCoversEveryType(): void
    {
        $this->assertSame(
            $this->sorted(MediaItemType::ALL),
            $this->sorted(array_keys(UpnpClassMap::TYPE_TO_CLASS)),
            'UpnpClassMap::TYPE_TO_CLASS must have one key per MediaItemType::ALL member'
        );
    }

    /**
     * The API shaper's allow-list is now an alias of the source of truth rather
     * than a fifth hand-typed copy; pin that so nobody re-inlines the literal.
     */
    public function testShaperValidTypesIsTheSourceOfTruth(): void
    {
        $valid = (new ReflectionClass(MediaItemShaper::class))->getConstant('VALID_TYPES');

        $this->assertSame(
            MediaItemType::ALL,
            $valid,
            'MediaItemShaper::VALID_TYPES must be MediaItemType::ALL, not a re-typed copy'
        );
    }

    /**
     * `image` has never been a member of `media_items.type` — the scanner uses it
     * as an argument label only, and treating it as a column value is a
     * long-standing source of bugs in this repo. `photo` is the real member.
     */
    public function testImageIsNotAMemberButPhotoIs(): void
    {
        $this->assertFalse(MediaItemType::isValid('image'), '`image` is a scanner label, not a column member');
        $this->assertTrue(MediaItemType::isValid('photo'), '`photo` is the real stills member');
        $this->assertNotContains('image', $this->enumMembersFromMigrations('media_items', 'type'));
    }

    /**
     * `normalize()` is what keeps an unexpected value off the wire: members pass
     * through verbatim, everything else becomes the shared fallback.
     */
    public function testNormalizePassesMembersThroughAndCoercesTheRest(): void
    {
        foreach (MediaItemType::ALL as $type) {
            $this->assertSame($type, MediaItemType::normalize($type));
        }

        $this->assertSame(MediaItemType::FALLBACK, MediaItemType::normalize('image'));
        $this->assertSame(MediaItemType::FALLBACK, MediaItemType::normalize(''));
        $this->assertSame(MediaItemType::FALLBACK, MediaItemType::normalize(null));
        $this->assertSame(MediaItemType::FALLBACK, MediaItemType::normalize(42));
        $this->assertContains(MediaItemType::FALLBACK, MediaItemType::ALL);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
