<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Common\Database;

use Phlix\Common\Uuid;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S155 — real-MySQL proof that migration 103 collapses every duplicate UNIQUE
 * index on `music_artists`/`music_albums`/`music_tracks`.`media_item_id` to
 * exactly ONE named constraint, survives replay, and never unbacks the
 * `fk_*_media_item` foreign keys while doing it.
 *
 * THE MEASURED DEFECT (S155). Migration 070 declares its fixed columns with a
 * column-level inline `UNIQUE` inside `MODIFY COLUMN` (070:28,35,42). MySQL
 * auto-names such an index (`media_item_id`, `media_item_id_2`, …), so no
 * replay can collide with the previous copy's name, no 1061 is raised, and
 * `MigrationRunner`'s idempotent squelch never fires — every re-application
 * mints ONE MORE unique index over the SAME column. Production measured 24 per
 * table on 2026-07-27; a chain-built dev DB lands on two even on its FIRST
 * pass (065's own inline UNIQUE + 070's re-mint) — both reproduced on MySQL
 * 8.0.46 before this fix existed.
 *
 * Migration 103 keeps the contractual name AND the shape: the survivor is the
 * named `uq_music_<x>_media_item`, every other unique over exactly
 * {media_item_id} is dropped in one FK-safe multi-drop ALTER, and the file
 * re-runs clean forever (070 itself is untouched — its checksum is in every
 * install's ledger, so 103 also makes the chain SELF-HEALING: on an
 * empty-ledger transition 070 re-mints BEFORE 103 collapses again).
 *
 * This file pins, against a live server — the only place index maintenance and
 * FK re-association are observable (an in-memory double cannot express either):
 *   * duplicate injection collapses to exactly one named constraint (per table);
 *   * replay is a silent no-op (no third index, rows untouched);
 *   * the self-healing pass (a fresh 070-style re-mint on top of the cleaned
 *     state) collapses again;
 *   * after collapse the FK STILL rejects a violating INSERT (1452) and the
 *     surviving UNIQUE still rejects a duplicate media_item_id (1062);
 *   * a wrong-shape imposter squatting the contractual name is REPLACED, never
 *     passed vacuously (the S161 shape-awareness standard applied to S155's
 *     tables);
 *   * a dirty install (duplicates, no constraint) refuses loudly with the
 *     remedy in the error text and alters nothing;
 *   * indexes over a DIFFERENT column set are never in the drop set.
 *
 * SAFETY: every scenario mutates indexes on the shared `phlix_test` tables and
 * restores the canonical post-103 shape in tearDown — its own fixture rows
 * are deleted FIRST so the restore's re-add can never trip over leftovers.
 * The suite runs serially; {@see
 * \Phlix\Tests\Integration\Common\Database\UniqueIndexShapeGuardTest}
 * establishes the drop-and-restore precedent this follows.
 */
final class MusicMediaItemUniqueIndexGuardTest extends TestCase
{
    use RequiresRealDatabase;

    /** Survival token — also asserted to be present in migration 103 below. */
    public const SURVIVAL_TOKEN = 'S155MIGX4R9';

    private const MIGRATION = '103_music_media_item_unique_collapse.sql';

    /** @var list<string> the three tables the chain mints duplicates on */
    private const TABLES = ['music_artists', 'music_albums', 'music_tracks'];

    private const COLUMN_SET = 'media_item_id';

    /** Names this test injects; never collide with auto-named `media_item_id*`. */
    private const DUP_PREFIX = 'zz_s155_dup_';
    private const BACKING = 'zz_s155_backing';
    private const OTHER_SET = 'zz_s155_other_set';

    private ?Connection $db = null;

    /** @var list<string> artist NAMES (uk_name keeps them unique per scenario) */
    private array $artistNames = [];

    /** @var list<string> track/album titles, likewise scenario-unique */
    private array $trackTitles = [];

    /** @var list<string> */
    private array $albumTitles = [];

    /** @var list<string> */
    private array $mediaItemIds = [];

    /** @var list<string> `table.index_name` pairs this test created. */
    private array $looseIndexes = [];

    private string $libraryId = '';

    /** Whether any scenario mutated this test's tables' indexes. */
    private bool $mutated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the S155 index-collapse proofs. Runs in CI.');
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            // Own rows first — the canonical restore re-runs 103, and a
            // leftover duplicate group from a mid-scenario failure would make
            // even that refusal-and-restore path leave the table unconstrained.
            try {
                foreach ($this->trackTitles as $title) {
                    $db->query('DELETE FROM music_tracks WHERE title = ?', [$title]);
                }
                foreach ($this->albumTitles as $title) {
                    $db->query('DELETE FROM music_albums WHERE title = ?', [$title]);
                }
                foreach ($this->artistNames as $name) {
                    $db->query('DELETE FROM music_artists WHERE name = ?', [$name]);
                }
                foreach ($this->mediaItemIds as $id) {
                    $db->query('DELETE FROM media_items WHERE id = ?', [$id]);
                }
                if ($this->libraryId !== '') {
                    $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
                }
            } catch (Throwable) {
                // Best effort below.
            }

            if ($this->mutated) {
                $this->restoreCanonicalState();
            }
        }

        $this->db = null;
        $this->artistNames = [];
        $this->trackTitles = [];
        $this->albumTitles = [];
        $this->mediaItemIds = [];
        $this->looseIndexes = [];
        $this->libraryId = '';
        $this->mutated = false;

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Collapse + replay (the S155 AC core)
    // ------------------------------------------------------------------

    /**
     * Inject three extra single-column UNIQUE indexes per table — the exact
     * shape three more replays of 070 would mint — run 103, and require
     * exactly one UNIQUE over {media_item_id} per table afterwards: the
     * contractual NAMED one. Row counts must not move: this migration is
     * index maintenance, never a data edit.
     */
    public function testCollapsesInjectedDuplicatesToTheOneNamedConstraint(): void
    {
        $countsBefore = $this->rowCounts();

        foreach (self::TABLES as $table) {
            $this->injectDuplicates($table, 3);
        }
        $this->mutated = true;

        foreach (self::TABLES as $table) {
            $this->assertGreaterThan(
                1,
                $this->countUniqueIndexesCovering($table, self::COLUMN_SET),
                $table . ': the injected duplicates did not take — the scenario is vacuous',
            );
        }

        $this->runMigration(self::MIGRATION);

        foreach (self::TABLES as $table) {
            $this->assertSame(
                1,
                $this->countUniqueIndexesCovering($table, self::COLUMN_SET),
                $table . ': after 103 there must be exactly one UNIQUE index over '
                . self::COLUMN_SET . ' — duplicates surviving is the S155 defect itself',
            );
            $this->assertSame(
                '0',
                $this->nonUniqueOf($table, $this->contractualName($table)),
                $table . ': the single survivor is not the contractual named constraint',
            );
        }

        $this->assertSame($countsBefore, $this->rowCounts(), '103 must not touch a single row');
    }

    /**
     * A replayed 103 is exactly empty: no new index, no dropped index, no
     * row moved. (The literal AC wording — "re-running the migration CHAIN
     * twice from scratch produces no new duplicates" — is proven by this
     * per-file property plus the twice-replayed fresh-database chain runs
     * recorded for this step; together they cover both the file replay and
     * the 070-before-103 ordering, pinned separately below.)
     */
    public function testReplayAfterCollapseIsASilentNoOp(): void
    {
        $this->runMigration(self::MIGRATION);
        $counts = $this->rowCounts();
        $shapes = $this->indexNameSets();

        $this->runMigration(self::MIGRATION);

        $this->assertSame($counts, $this->rowCounts(), 'a replayed 103 moved rows');
        $this->assertSame(
            $shapes,
            $this->indexNameSets(),
            'a replayed 103 changed the index name set — replay must be exactly empty',
        );
    }

    /**
     * SELF-HEALING: 070 stays byte-identical (its checksum is in every
     * install's ledger), so on an empty-ledger transition it re-mints one
     * auto-named copy BEFORE 103 re-runs in the same pass. A fresh re-mint
     * planted on top of the clean state must therefore collapse again,
     * leaving the single named survivor.
     */
    public function testFreshRemintOnTopOfCleanStateCollapsesAgain(): void
    {
        $this->runMigration(self::MIGRATION);

        foreach (self::TABLES as $table) {
            $this->injectDuplicates($table, 1);
        }
        $this->mutated = true;

        $this->runMigration(self::MIGRATION);

        foreach (self::TABLES as $table) {
            $this->assertSame(
                1,
                $this->countUniqueIndexesCovering($table, self::COLUMN_SET),
                $table . ': 103 is not self-healing — a re-minted duplicate survived the next pass',
            );
        }
    }

    // ------------------------------------------------------------------
    // Constraints still bite AFTER the collapse (real violating INSERTs)
    // ------------------------------------------------------------------

    /**
     * The acceptance criteria demand FK enforcement be PROVEN, not assumed:
     * an INSERT whose media_item_id references nothing must fail with 1452
     * naming fk_artists_media_item — after 103 has dropped every extra index
     * and the FK has re-associated with the surviving named one.
     */
    public function testForeignKeyStillRejectsViolatingInsertAfterCollapse(): void
    {
        $this->runMigration(self::MIGRATION);

        $this->seedLibraryFixture();
        $name = 's155-fk-probe-' . substr(Uuid::v4(), 0, 8);
        $this->artistNames[] = $name;

        $threw = false;
        $message = '';
        try {
            $this->db()->query(
                'INSERT INTO music_artists (media_item_id, name) VALUES (?, ?)',
                ['00000000-dead-beef-0000-000000000000', $name],
            );
        } catch (Throwable $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        $this->assertTrue(
            $threw,
            'fk_artists_media_item must STILL reject an orphan media_item_id after the collapse',
        );
        $this->assertStringContainsString('1452', $message, 'the violating INSERT must fail with errno 1452');
        $this->assertStringContainsString(
            'fk_artists_media_item',
            $message,
            'the refusal must name the FK this migration re-backed',
        );
    }

    /**
     * The surviving UNIQUE is the constraint, not schema decoration: two rows
     * carrying the SAME (real, FK-satisfying) media_item_id must collide — on
     * the NULLable artists column AND on the NOT NULL tracks column, the two
     * shapes 070 touched.
     */
    public function testSurvivingUniqueRejectsDuplicateMediaItemAfterCollapse(): void
    {
        $this->runMigration(self::MIGRATION);

        $this->seedLibraryFixture();
        $mediaA = $this->seedMediaItem('/tmp/phlix-s155/unique-a.mkv');
        $mediaB = $this->seedMediaItem('/tmp/phlix-s155/unique-b.mkv');

        $artistName = $this->seedArtist($mediaA);

        $dupName = $artistName . '-dup';
        $this->artistNames[] = $dupName;
        $threw = false;
        try {
            $this->db()->query(
                'INSERT INTO music_artists (media_item_id, name) VALUES (?, ?)',
                [$mediaA, $dupName],
            );
        } catch (Throwable) {
            $threw = true;
        }
        $this->assertTrue(
            $threw,
            'a duplicate media_item_id on music_artists slipped past the surviving UNIQUE',
        );

        // The NOT NULL shape: music_tracks needs an album + artist parent chain.
        $albumTitle = $this->seedAlbum($artistName);
        $trackTitle = 's155-dup-track-' . substr($mediaB, 0, 8);
        $this->seedTrack($mediaB, $albumTitle, $artistName, $trackTitle);

        $threw = false;
        try {
            $this->seedTrack($mediaB, $albumTitle, $artistName, $trackTitle . '-x');
        } catch (Throwable) {
            $threw = true;
        }
        $this->assertTrue(
            $threw,
            'a duplicate media_item_id on music_tracks slipped past the surviving UNIQUE',
        );
    }

    // ------------------------------------------------------------------
    // Shape awareness (the S161 standard applied to S155's tables)
    // ------------------------------------------------------------------

    /**
     * A NON-UNIQUE index carrying the exact contractual name must not let 103
     * pass vacuously: the imposter is replaced in one atomic ALTER and the
     * shape check below re-asserts NON_UNIQUE = 0 under that name. The FK
     * stays backed throughout by a non-unique backing index created first —
     * that ordering IS the FK-landmine discipline this migration promises.
     */
    public function testImpostorUnderContractualNameIsReplacedNotSkipped(): void
    {
        $this->runMigration(self::MIGRATION);

        $table = 'music_artists';
        $name = $this->contractualName($table);

        $this->createIndex($table, self::BACKING, false);
        $this->dropUniquesCovering($table);
        $this->createIndex($table, $name, false);
        $this->assertSame('1', $this->nonUniqueOf($table, $name));

        $this->runMigration(self::MIGRATION);

        $this->assertSame(
            '0',
            $this->nonUniqueOf($table, $name),
            '103 no-ops on a same-named NON-UNIQUE imposter — the exact S156 review finding-1 '
            . 'blindness transplanted onto S155 tables',
        );
        $this->assertSame(1, $this->countUniqueIndexesCovering($table, self::COLUMN_SET));
    }

    /**
     * An install can only be duplicate-dirty while NO shape-adequate UNIQUE
     * exists. That state must refuse loudly — an error carrying the remedy —
     * and alter NOTHING (the FK keeps its backing, the duplicates survive for
     * the operator to merge deliberately). Mirrors the 096/097 guard idiom.
     */
    public function testDirtyInstallWithoutConstraintRefusesWithTheRemedyError(): void
    {
        $table = 'music_artists';
        $this->seedLibraryFixture();
        $media = $this->seedMediaItem('/tmp/phlix-s155/dirty.mkv');

        // Reach the dirty state FK-safely: back the FK with a non-unique
        // index, drop every UNIQUE over the column, then insert the duplicate
        // group the constraint would normally forbid.
        $this->createIndex($table, self::BACKING, false);
        $this->dropUniquesCovering($table);

        $first = 's155-dirty-' . substr(Uuid::v4(), 0, 8);
        $second = $first . '-b';
        $this->artistNames[] = $first;
        $this->artistNames[] = $second;
        $this->db()->query(
            'INSERT INTO music_artists (media_item_id, name) VALUES (?, ?)',
            [$media, $first],
        );
        $this->db()->query(
            'INSERT INTO music_artists (media_item_id, name) VALUES (?, ?)',
            [$media, $second],
        );

        $error = '';
        try {
            $this->runMigration(self::MIGRATION);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertNotSame('', $error, 'a dirty table must make 103 fail loudly, not pass vacuously');
        $this->assertStringContainsString(
            'dedupe first',
            $error,
            'the refusal must carry the remedy in its text, the 096/097 way',
        );
        $this->assertSame(
            0,
            $this->countUniqueIndexesCovering($table, self::COLUMN_SET),
            'the refusal must alter nothing: no constraint may be minted over dirty data',
        );
    }

    /**
     * 103's drop set is defined by the column SET {media_item_id} — an index
     * over a DIFFERENT set that merely CONTAINS the column (multi-column
     * constraints are the FK landmine zone) must survive untouched.
     */
    public function testIndexesOverOtherColumnSetsSurviveCollapse(): void
    {
        $table = 'music_artists';
        $this->createIndex($table, self::OTHER_SET, true, '(name, media_item_id)');

        $this->runMigration(self::MIGRATION);

        $rows = $this->indexRows($table, self::OTHER_SET);
        $this->assertNotSame(
            [],
            $rows,
            '103 dropped an index whose column SET differs — its predicate is not scoped to '
            . '{' . self::COLUMN_SET . '} alone',
        );
    }

    // ------------------------------------------------------------------
    // Token survival
    // ------------------------------------------------------------------

    public function testMigration103CarriesTheSurvivalToken(): void
    {
        $sql = file_get_contents(self::migrationsDir() . '/' . self::MIGRATION);
        $this->assertIsString($sql);
        $this->assertStringContainsString(
            self::SURVIVAL_TOKEN,
            $sql,
            'the 103 header must keep carrying ' . self::SURVIVAL_TOKEN . ' — it is what proves '
            . 'the music-table collapse fix survived into the tree the migration ships from',
        );
    }

    // ------------------------------------------------------------------
    // Migration execution + schema helpers
    // ------------------------------------------------------------------

    private static function migrationsDir(): string
    {
        return dirname(__DIR__, 4) . '/migrations';
    }

    /**
     * Execute a migration the way MigrationRunner does: strip full-line
     * comments, run the remaining statements in order on THIS connection (the
     * pool hands back a cached singleton, so the @phlix_* / @sql session
     * variables and the reused `stmt` name behave exactly as under the
     * runner). No statement in 103 contains a `;` inside a string literal, so
     * the plain split below reproduces the runner's quote-aware splitter for
     * this file — and the file itself is under the ledger's checksum
     * discipline, not this test's.
     */
    private function runMigration(string $basename): void
    {
        $sql = file_get_contents(self::migrationsDir() . '/' . $basename);
        $this->assertIsString($sql);

        $executable = preg_replace('/^\s*(--|#).*$/m', '', $sql) ?? $sql;

        foreach (explode(';', $executable) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->db()->query($statement);
            }
        }
    }

    private function contractualName(string $table): string
    {
        return 'uq_' . $table . '_media_item';
    }

    /** @return array<int, array<string, mixed>> STATISTICS rows for one index. */
    private function indexRows(string $table, string $name): array
    {
        $rows = $this->db()->query(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            [$table, $name],
        );
        $this->assertIsArray($rows);

        return $rows;
    }

    private function nonUniqueOf(string $table, string $name): string
    {
        $rows = $this->indexRows($table, $name);
        $this->assertNotSame([], $rows, $name . ' is absent from ' . $table);

        return (string) $rows[0]['NON_UNIQUE'];
    }

    /** How many UNIQUE indexes cover exactly the alphabetical column set. */
    private function countUniqueIndexesCovering(string $table, string $set): int
    {
        $rows = $this->db()->query(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
              GROUP BY INDEX_NAME
              HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = ?',
            [$table, $set],
        );
        $this->assertIsArray($rows);

        return count($rows);
    }

    /**
     * Plant `$n` extra single-column UNIQUE indexes over media_item_id — the
     * exact residue shape of `$n` additional replays of 070's inline-UNIQUE
     * mint.
     */
    private function injectDuplicates(string $table, int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->createIndex($table, self::DUP_PREFIX . $table . '_' . $i, true);
        }
    }

    /**
     * Create a named scenario index, dropping any SAME-NAME leftover first —
     * a scenario that died before its tearDown (a mutation run, a fatal) must
     * never poison the next one with a 1061 on its own fixture name.
     */
    private function createIndex(string $table, string $name, bool $unique, string $columns = '(media_item_id)'): void
    {
        // Mark mutation BEFORE the CREATE: a leftover drop here already
        // changed the schema, and a CREATE that itself fails (e.g. 1553) must
        // still route through the restore, not strand the table mid-shape.
        $this->mutated = true;
        $leftover = $this->db()->query(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $name],
        );
        $this->assertIsArray($leftover);
        if ($leftover !== []) {
            $this->db()->query('ALTER TABLE ' . $table . ' DROP INDEX `' . $name . '`');
        }

        $this->db()->query(
            ($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . $name . ' ON ' . $table
            . ' ' . $columns
        );
        $this->looseIndexes[] = $table . '.' . $name;
    }

    /**
     * Drop every UNIQUE index over exactly {media_item_id}. Only called in
     * scenarios that have JUST created a non-unique FK backing, so a missing
     * backing never has to be forgiven — a 1553 here would fail the scenario
     * visibly, which is honest.
     */
    private function dropUniquesCovering(string $table): void
    {
        $rows = $this->db()->query(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
              GROUP BY INDEX_NAME
              HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = ?',
            [$table, self::COLUMN_SET],
        );
        $this->assertIsArray($rows);

        foreach ($rows as $row) {
            $name = (string) ($row['INDEX_NAME'] ?? '');
            if ($name !== '') {
                $this->db()->query('ALTER TABLE ' . $table . ' DROP INDEX `' . $name . '`');
            }
        }
    }

    /**
     * Re-establish the post-103 canonical state on all three tables. Two drop
     * passes with the migration between them, because of the FK: after the
     * dirty scenario the non-unique `BACKING` is the ONLY thing supporting
     * fk_artists_media_item, so dropping it before the canonical UNIQUE is
     * re-minted would fail 1553 and strand it behind. Pass 1 clears what it
     * can (duplicates, other-set indexes, a contractual-name imposter — the
     * FK survives it while any backing remains); the migration re-derives the
     * named survivor; pass 2 removes the now-redundant backing (1091s from
     * pass-1 successes are swallowed — the collapse may have taken names
     * first). The contractual name is never touched in pass 2: by then it is
     * the canonical survivor, not the imposter pass 1 removed.
     */
    private function restoreCanonicalState(): void
    {
        try {
            foreach ($this->looseIndexes as $entry) {
                [$entryTable, $entryName] = explode('.', $entry, 2);
                $this->tryDropIndex($entryTable, $entryName);
            }
            $this->runMigration(self::MIGRATION);
            foreach ($this->looseIndexes as $entry) {
                [$entryTable, $entryName] = explode('.', $entry, 2);
                if ($entryName === $this->contractualName($entryTable)) {
                    continue;
                }
                $this->tryDropIndex($entryTable, $entryName);
            }
        } catch (Throwable) {
            // Restore is best effort; a leftover broken shape fails the NEXT
            // migration-chain health assertion in CI, not this tearDown blind.
        }
    }

    private function tryDropIndex(string $table, string $name): void
    {
        try {
            $this->db()->query('ALTER TABLE ' . $table . ' DROP INDEX `' . $name . '`');
        } catch (Throwable) {
            // 1553 (still FK-backed — pass 2 will get it once the migration
            // has re-backed) or 1091 (already collapsed away) — both honest.
        }
    }

    /** @return array<string, int> row counts of the three tables 103 targets */
    private function rowCounts(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $rows = $this->db()->query('SELECT COUNT(*) AS c FROM ' . $table);
            $this->assertIsArray($rows);
            $out[$table] = (int) ($rows[0]['c'] ?? -1);
        }

        return $out;
    }

    /** @return array<string, list<string>> sorted index names per targeted table */
    private function indexNameSets(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $rows = $this->db()->query(
                'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table],
            );
            $this->assertIsArray($rows);
            $names = array_map(static fn (array $r): string => (string) $r['INDEX_NAME'], $rows);
            sort($names);
            $out[$table] = $names;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Fixtures (names/ids tracked for tearDown; every seed tracks BEFORE the
    // INSERT so a scenario that fails mid-write still cleans up after itself)
    // ------------------------------------------------------------------

    private function seedLibraryFixture(): void
    {
        if ($this->libraryId !== '') {
            return;
        }

        $this->libraryId = Uuid::v4();
        $this->db()->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'S155 collapse probe', 'movie', json_encode(['/tmp/phlix-s155'])],
        );
    }

    private function seedMediaItem(string $path): string
    {
        $id = Uuid::v4();
        $this->mediaItemIds[] = $id;
        $this->db()->query(
            'INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, ?, ?)',
            [$id, $this->libraryId, basename($path), 'movie', $path],
        );

        return $id;
    }

    /** @return string the inserted artist's (unique) name */
    private function seedArtist(?string $mediaItemId): string
    {
        $name = 's155-artist-' . substr(Uuid::v4(), 0, 12);
        $this->artistNames[] = $name;
        $this->db()->query(
            'INSERT INTO music_artists (media_item_id, name) VALUES (?, ?)',
            [$mediaItemId, $name],
        );

        return $name;
    }

    /** @return string the inserted album's (scenario-unique) title */
    private function seedAlbum(string $artistName): string
    {
        $title = 's155-album-' . substr($artistName, -8);
        $this->albumTitles[] = $title;
        $this->db()->query(
            'INSERT INTO music_albums (artist_id, title) VALUES (?, ?)',
            [$this->artistNumericId($artistName), $title],
        );

        return $title;
    }

    private function seedTrack(string $mediaItemId, string $albumTitle, string $artistName, string $title): void
    {
        $this->trackTitles[] = $title;
        $this->db()->query(
            'INSERT INTO music_tracks (media_item_id, album_id, artist_id, title) VALUES (?, ?, ?, ?)',
            [$mediaItemId, $this->albumNumericId($albumTitle), $this->artistNumericId($artistName), $title],
        );
    }

    /** music_artists.id is AUTO_INCREMENT; resolve the parent by its unique name. */
    private function artistNumericId(string $name): int
    {
        $rows = $this->db()->query('SELECT id FROM music_artists WHERE name = ?', [$name]);
        $this->assertIsArray($rows);
        $this->assertNotSame([], $rows, 'fixture artist row vanished mid-scenario');

        return (int) $rows[0]['id'];
    }

    private function albumNumericId(string $title): int
    {
        $rows = $this->db()->query('SELECT id FROM music_albums WHERE title = ?', [$title]);
        $this->assertIsArray($rows);
        $this->assertNotSame([], $rows, 'fixture album row vanished mid-scenario');

        return (int) $rows[0]['id'];
    }

    private function db(): Connection
    {
        $this->assertInstanceOf(Connection::class, $this->db);

        return $this->db;
    }
}
