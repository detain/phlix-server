<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Stats;

use Phlix\Admin\DashboardService;
use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Streaming\StreamManager;
use Phlix\Session\SessionManager;
use Phlix\Stats\StatsCollector;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S114 — real-MySQL proof that migration 105 makes a duplicate `stats_storage`
 * snapshot STRUCTURALLY impossible without destroying a single accumulated byte,
 * and that the write path's upsert ACCUMULATES.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * Every clause below is a property of a live server: NULL-distinctness inside a
 * UNIQUE index (the reason the bare index is INERT on the old nullable column),
 * the 1138 refusal of `MODIFY … NOT NULL` over existing NULLs under
 * STRICT_TRANS_TABLES, the 1048 refusal of an explicit NULL INSERT afterwards,
 * the 1062 collision the deterministic column turns a duplicate into, the
 * multi-table UPDATE/DELETE JOIN over a grouped derived table, and the runner's
 * ledger + checksum over THIS file. A mocked `Connection` accepts everything
 * and models none of it.
 *
 * ## Proof branches (S345 rule 1 — every write/merge path and its input class)
 *
 *   | 105-entry state                        | acted on by      | outcome |
 *   |----------------------------------------|------------------|---------|
 *   | nullable, no unique index (canonical)  | steps 1,1b,1c,2  | merged, deterministic, key live |
 *   | nullable, inert same-shape index       | steps 0,1,1b,1c,2 | inert key dropped, rebuilt meaningful |
 *   | NOT NULL, no index (half-applied)      | steps 1b,2       | merged, key added |
 *   | NOT NULL, contractual key (complete)   | nothing — probes say "done" | silent no-op replay |
 *   | same KEY NAME, wrong shape (imposter)  | step 2 only  | atomic DROP+ADD replacement |
 *
 *   | 105-exit write path                            | row effect |
 *   |------------------------------------------------|------------|
 *   | fresh (stamp,bucket,library) triple            | INSERT, new id |
 *   | repeat triple, any caller                      | UPDATE survivor: sums ride along, id kept |
 *   | repeat triple onto a NULL-valued survivor (all-NULL legacy group, kept NULL by the merge)
 *   |                                                | COALESCE rides the values in — never `NULL + x` |
 *   | explicit NULL library bind (post-105 caller)   | 1048 — the collector binds '' instead |
 *
 * SAFETY: all destructive proofs run in a throwaway `phlix_s114_*` DATABASE
 * built from the REAL 019+086 files (S79 pattern), never against the shared
 * `phlix_test` tables — except the final chain-level shape check, which is
 * read-only.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
final class StatsStorageUniqueKeyUpsertGuardTest extends TestCase
{
    use RequiresRealDatabase;

    /** Lane survival token (S114). Code-resident by design; never prose. */
    public const string SURVIVAL_TOKEN = 'S114STATSUPSERTX3F6';

    private const MIGRATION_105 = '105_stats_storage_unique_key_accumulation.sql';

    private const MIGRATION_019 = '019_stats_schema.sql';

    private const MIGRATION_086 = '086_stats_storage_book_bucket.sql';

    /** Ledger checksums of the two schema neighbours — R4: they must NEVER move. */
    private const EXPECTED_019_CHECKSUM = 'e7032697acbfc7b31f5f45b5ec16581a';

    private const EXPECTED_086_CHECKSUM = '4ad8d1c323159c2206b34e62320f7189';

    private const KEY_NAME = 'uq_stats_storage_recorded_media_library';

    /** The shared stamp of the fixture's duplicate generations. */
    private const T1 = '2032-04-05 06:07:08';

    private const T2 = '2032-04-05 06:07:09';

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    private string $preDir = '';

    private string $fixDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // S126 gate: skip on genuine absence, LOUD on reachable-but-unusable.
        $this->requireHealthyDatabase('skipping the S114 stats_storage unique-key proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_s114_' . $rand;

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        $this->preDir = sys_get_temp_dir() . '/phlix-s114-pre-' . $rand;
        $this->fixDir = sys_get_temp_dir() . '/phlix-s114-fix-' . $rand;
        mkdir($this->preDir, 0o755, true);
        mkdir($this->fixDir, 0o755, true);
        copy($this->migrationPath(self::MIGRATION_019), $this->preDir . '/' . self::MIGRATION_019);
        copy($this->migrationPath(self::MIGRATION_086), $this->preDir . '/' . self::MIGRATION_086);
        copy($this->migrationPath(self::MIGRATION_105), $this->fixDir . '/' . self::MIGRATION_105);
    }

    protected function tearDown(): void
    {
        foreach ([$this->preDir, $this->fixDir] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                foreach ((array) glob($dir . '/*') as $file) {
                    if (is_string($file)) {
                        unlink($file);
                    }
                }
                rmdir($dir);
            }
        }

        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->db = null;
        $this->admin = null;
        $this->scratchDb = '';
        $this->preDir = '';
        $this->fixDir = '';

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Tree-level proofs (no schema mutation).
    // -----------------------------------------------------------------------

    public function testMigrationFileCarriesTheSurvivalToken(): void
    {
        $this->assertStringContainsString(
            self::SURVIVAL_TOKEN,
            $this->migrationSql(self::MIGRATION_105),
            'migration 105 must carry the token so a tokenized-corpus search proves the fix '
            . 'shipped in the tree the migration runs from.'
        );
    }

    /**
     * R4 FORWARD-ONLY pins, computed with the RUNNER's own checksum over the
     * shipped files — an in-ALTER definition edit to 019/086 would break every
     * deployed install's ledger and is what this catches.
     */
    public function testNeighbourChecksumsAreUnmoved(): void
    {
        foreach (
            [
                self::MIGRATION_019 => self::EXPECTED_019_CHECKSUM,
                self::MIGRATION_086 => self::EXPECTED_086_CHECKSUM,
            ] as $file => $expected
        ) {
            $raw = $this->migrationSql($file);
            $stripped = $this->runnerChecksum($raw);

            $this->assertSame(
                $expected,
                $stripped,
                $file . ' was edited: its comment-stripped checksum no longer matches the ledger '
                . 'value every deployed install holds.'
            );

            // NEGATIVE CONTROL (S345 rule 3): the proof only means something
            // because the ledger stores the STRIPPED hash. If either file ever
            // loses its full-line comments, the two collapse and this reddens.
            $this->assertNotSame(
                md5($raw),
                $stripped,
                $file . ' carries no strippable full-line comments — the strip-based ledger '
                . 'proof would have become vacuous.'
            );
        }
    }

    // -----------------------------------------------------------------------
    // Scratch-database proofs against the REAL migration files.
    // -----------------------------------------------------------------------

    /**
     * Acceptance criteria 1 + 3: the column is deterministic and the UNIQUE key
     * exists with exactly the contractual shape.
     */
    public function testTheColumnIsDeterministicAndTheKeyExistsWithTheContractualShape(): void
    {
        $this->applyPreSchema();
        $this->seedCanonicalFixture();
        $this->applyFix();

        $column = $this->rows(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stats_storage'
                AND COLUMN_NAME = 'library_id'"
        );
        $this->assertCount(1, $column);
        $this->assertSame('char(36)', (string) $column[0]['COLUMN_TYPE']);
        $this->assertSame('NO', (string) $column[0]['IS_NULLABLE']);
        $this->assertSame('', (string) $column[0]['COLUMN_DEFAULT']);
        $this->assertSame('utf8mb4', (string) $column[0]['CHARACTER_SET_NAME']);
        $this->assertSame('utf8mb4_unicode_ci', (string) $column[0]['COLLATION_NAME']);

        $index = $this->rows(
            "SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stats_storage'
                AND INDEX_NAME = '" . self::KEY_NAME . "'
              ORDER BY SEQ_IN_INDEX"
        );
        $this->assertCount(
            3,
            $index,
            self::KEY_NAME . ' must cover exactly (recorded_at, media_type, library_id) in that order.'
        );
        $this->assertSame(
            ['recorded_at', 'media_type', 'library_id'],
            array_map(static fn(array $r): string => (string) $r['COLUMN_NAME'], $index)
        );
        foreach ($index as $row) {
            $this->assertSame('0', (string) $row['NON_UNIQUE'], 'the key must be UNIQUE, not a plain index');
        }
    }

    /**
     * Acceptance criterion 4: pre-existing duplicates MERGE, preserving
     * ACCUMULATED totals — never first-write-wins, never a failed migration.
     */
    public function testPreExistingDuplicatesMergePreservingAccumulatedTotals(): void
    {
        $this->applyPreSchema();
        $this->seedCanonicalFixture();

        $before = $this->grandTotals();
        $this->assertSame(
            [6, 25, 25_000, 250],
            $before,
            'POSITIVE CONTROL: the fixture must be in the pre-105 table before the merge proves anything.'
        );

        $result = $this->applyFix();
        $this->assertSame([], $result['errors'], 'migration 105 must apply over duplicates, not fail on them');
        $this->assertSame(MigrationRunner::EXIT_SUCCESS, MigrationRunner::exitCodeFor($result));

        $after = $this->grandTotals();
        $this->assertSame(4, $after[0], '6 rows in, four UNIQUE triples out');
        $this->assertSame(
            array_slice($before, 1),
            array_slice($after, 1),
            'items/bytes/cache are conserved: SUM(SUM(group)) = SUM(table), byte-exact'
        );

        // Per-group survivors: MIN(id) keeps its identity and carries the SUM.
        $survivorRows = $this->rows(
            'SELECT id, recorded_at, library_id, media_type, item_count, total_bytes,
                    transcode_cache_bytes FROM stats_storage ORDER BY id'
        );
        $rows = [];
        foreach ($survivorRows as $row) {
            $rows[(string) $row['id']] = $row;
        }

        $this->assertSame(
            ['a1', 'a3', 'b1', 'c1'],
            array_keys($rows),
            'survivors are MIN(id) per group; the losers (a2, b2) are gone and no new id was minted'
        );
        $this->assertSame(['3', '3000', '30'], [
            (string) $rows['a1']['item_count'],
            (string) $rows['a1']['total_bytes'],
            (string) $rows['a1']['transcode_cache_bytes'],
        ], 'the NULL-movie pair merges onto a1 with SUMmed values');
        $this->assertSame(['11', '11000', '110'], [
            (string) $rows['b1']['item_count'],
            (string) $rows['b1']['total_bytes'],
            (string) $rows['b1']['transcode_cache_bytes'],
        ], 'the NULL-vs-\'\' collision created by step 1 merges onto b1 with SUMmed values');
        $this->assertSame('4', (string) $rows['a3']['item_count'], 'a distinct library is never merged away');
        $this->assertSame('', (string) $rows['c1']['library_id'], 'a lone NULL collapses to the sentinel');
    }

    /**
     * The R3 invariant: for this write history, the S102 SUM reader computed X
     * pre-105 and computes the SAME X post-105, from fewer rows.
     */
    public function testGetStorageSummaryIsByteIdenticalAcrossTheMigration(): void
    {
        $this->applyPreSchema();
        $this->seedCanonicalFixture();

        $before = $this->dashboardSummary();

        $this->assertSame(
            ['7000', '11000', '7000'],
            [
                (string) $before['movie_bytes'],
                (string) $before['series_bytes'],
                (string) $before['music_bytes'],
            ],
            'POSITIVE CONTROL: the fixture must roll up as designed BEFORE the migration.'
        );

        $this->applyFix();

        $this->assertSame(
            $before,
            $this->dashboardSummary(),
            'the surviving merged rows must reproduce the reader output of the N-row world EXACTLY — '
            . 'the totals ride through the merge unchanged (R3), which first-write-wins would violate'
        );
    }

    /**
     * The 🔴 correction, both halves, measured on THIS build:
     *   * PRE-105 + blind UNIQUE add = INERT (the index accepts duplicate
     *     NULL-library tuples — "shipping only the migration changes nothing");
     *   * POST-105 the same scenarios are structurally impossible: explicit NULL
     *     INSERT fails 1048, a repeated sentinel triple fails 1062 — and 105
     *     CONVERGES from exactly such an inert-index box.
     */
    public function testTheInertNullScenarioIsRealBeforeAndImpossibleAfter(): void
    {
        $this->applyPreSchema();
        $this->seedCanonicalFixture();

        // The blind index today's spec would have added — succeeds WITH duplicates…
        $this->db()->query(
            'ALTER TABLE stats_storage ADD UNIQUE INDEX ' . self::KEY_NAME . ' (recorded_at, media_type, library_id)'
        );
        $inert = $this->scalar(
            "SELECT COUNT(*) AS c FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stats_storage'
                AND INDEX_NAME = '" . self::KEY_NAME . "'"
        );
        $this->assertSame(3, $inert, 'POSITIVE CONTROL: the blind index must be live on the dirty table.');

        // …and then rejects NOTHING for NULL-library rows: three identical tuples in.
        $accepted = 0;
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->db()->query(
                    'INSERT INTO stats_storage VALUES (?, ?, NULL, ?, 1, 100, 1)',
                    ['i' . $i . 'inert', self::T1, 'movie']
                );
                $accepted++;
            } catch (Throwable) {
                // counted below
            }
        }
        $this->assertSame(
            3,
            $accepted,
            'the measured inertness: NULLs are DISTINCT in a UNIQUE index, so the old index shape '
            . 'cannot stop a duplicate snapshot run — this is why 105 exists'
        );

        // 105 applied to THIS box (nullable + inert index) must converge: the inert constraint is
        // dropped before the normalization UPDATE could hit 1062 on it, and the three
        // accepted-anyway duplicates merge into the group's MIN(id) survivor — `a1` — with
        // every byte preserved (25+3 items, 25,000+300 bytes, 250+3 cache across 4 survivors).
        $result = $this->applyFix();
        $this->assertSame([], $result['errors'], '105 must handle an inert-index install');
        $this->assertSame(
            [4, 28, 25_300, 253],
            $this->grandTotals(),
            'the inert duplicates must be MERGED into the survivor, not lost and not kept'
        );
        $survivor = $this->rows("SELECT library_id, item_count FROM stats_storage WHERE id = 'a1'");
        $this->assertCount(1, $survivor);
        $this->assertSame('', (string) $survivor[0]['library_id']);
        $this->assertSame('6', (string) $survivor[0]['item_count']);

        // POST-105: explicit NULL INSERT — the exact old seed shape — fails 1048 (measured
        // doctrine: strict mode rejects an explicit NULL even with a DEFAULT '').
        $code = $this->insertExpectingFailure(
            "INSERT INTO stats_storage VALUES ('after1','2032-05-01 00:00:00',NULL,'photo',1,100,1)"
        );
        $this->assertStringContainsString('1048', $code, 'the NULL seed scenario must now be REJECTED');

        // POST-105: the repeated sentinel triple fails 1062 — the duplicate a normal
        // (non-upsert) writer used to get away with.
        $this->db()->query(
            "INSERT INTO stats_storage VALUES ('after2','2032-05-01 00:00:01','','photo',1,100,1)"
        );
        $code = $this->insertExpectingFailure(
            "INSERT INTO stats_storage VALUES ('after3','2032-05-01 00:00:01','','photo',1,100,1)"
        );
        $this->assertStringContainsString('1062', $code, 'the duplicate-generation scenario must now be REJECTED');

        $this->assertSame(
            [],
            $this->rows("SELECT id FROM stats_storage WHERE id IN ('after1','after3')"),
            'the rejected statements must have written nothing at all'
        );
    }

    /**
     * The destructive-clobber scenario, proven inert in the OTHER direction too:
     * two collectors ("concurrent requests") writing the SAME
     * (stamp, bucket, library) through the REAL write path yield exactly ONE row
     * whose values are the SUM of both — never the first row's, never the
     * second's, never two rows, and the survivor keeps its original id.
     */
    public function testConcurrentDoubleWriteThroughTheCollectorProducesOneSummedRow(): void
    {
        $this->applyPreSchema();
        $this->applyFix();

        $db = $this->db();

        for ($attempt = 1; $attempt <= 25; $attempt++) {
            $db->query("DELETE FROM stats_storage WHERE library_id = '' AND media_type = 'movie'");

            // Two FRESH instances — the daemon timer and the FPM bootstrap each construct their own
            // (never $container->get(): the r3 singleton trap, R6).
            (new StatsCollector($db))->recordStorageSnapshots(['movie' => ['count' => 3, 'bytes' => 4_000]]);
            $second = new StatsCollector($db);
            $second->recordStorageSnapshots(['movie' => ['count' => 4, 'bytes' => 5_000]]);

            // The stamp is PHP's time() bound as FROM_UNIXTIME — a second is a coarse window;
            // both collectors stamping the same integer is what makes the tuples collide.
            $rows = $this->rows(
                "SELECT id, item_count, total_bytes FROM stats_storage
                  WHERE library_id = '' AND media_type = 'movie'"
            );
            if (count($rows) === 1) {
                $this->assertSame('7', (string) $rows[0]['item_count'], 'the upsert must SUM item_count');
                $this->assertSame('9000', (string) $rows[0]['total_bytes'], 'the upsert must SUM total_bytes');
                return;
            }

            // Two rows: the wall second crossed between the two stamps. Precondition churn on a
            // slow box, not a verdict — clear and retry. 25 attempts span >> 25 s of crossings.
            $this->assertSame(
                2,
                count($rows),
                'the same stamp must fold to one row; three or more means the write path stopped upserting'
            );
            usleep(120_000);
        }

        $this->fail(
            'precondition never met in 25 attempts: two back-to-back snapshot writes must share a '
            . 'wall-clock second at least once — a box that never repeats a second is not a test venue'
        );
    }

    /**
     * The NULL-fold branch of the write path (review r1 finding 1): a survivor of
     * an all-NULL legacy group keeps NULL numerics (faithful to the pre-105 SUM
     * reader), and bare `NULL + VALUES(col)` is NULL — which would silently ERASE
     * the incoming snapshot. The COALESCE accumulation lands the values instead,
     * through the REAL collector, byte-identical to what the pre-105 SUM reader
     * computed for the same history (the NULL row added nothing, the new row added
     * its values).
     */
    public function testFoldOntoANullSurvivorLandsEveryIncomingByte(): void
    {
        $this->applyPreSchema();
        $this->applyFix();

        $db = $this->db();

        for ($attempt = 1; $attempt <= 25; $attempt++) {
            $db->query("DELETE FROM stats_storage WHERE library_id = '' AND media_type = 'movie'");

            // The survivor shape 105's merge legitimately produces for an all-NULL
            // legacy group — and stamp it on the CURRENT second so the collector's
            // fold targets it.
            $stamp = time();
            $db->query(
                'INSERT INTO stats_storage (id, recorded_at, library_id, media_type,
                                            item_count, total_bytes, transcode_cache_bytes)
                 VALUES (?, FROM_UNIXTIME(?), ?, ?, NULL, NULL, NULL)',
                ['nullsurvivor', $stamp, '', 'movie']
            );

            (new StatsCollector($db))->recordStorageSnapshots(['movie' => ['count' => 3, 'bytes' => 4_000]]);

            $rows = $this->rows(
                "SELECT id, item_count, total_bytes, transcode_cache_bytes FROM stats_storage
                  WHERE library_id = '' AND media_type = 'movie'"
            );
            if (count($rows) === 1) {
                $this->assertSame('nullsurvivor', (string) $rows[0]['id'], 'the fold must land on the survivor');
                $this->assertSame(
                    ['3', '4000', '0'],
                    [
                        (string) $rows[0]['item_count'],
                        (string) $rows[0]['total_bytes'],
                        (string) $rows[0]['transcode_cache_bytes'],
                    ],
                    'the incoming values must ride in despite the survivor holding NULL — bare addition '
                    . 'would have left NULL here, silently erasing the snapshot'
                );

                return;
            }

            // Two rows: the wall second crossed between the seed stamp and the
            // collector stamp — precondition churn, re-seed and retry.
            $this->assertSame(2, count($rows), 'a fold either lands or misses the second; three rows '
                . 'means the write path stopped upserting');
            usleep(120_000);
        }

        $this->fail('precondition never met in 25 attempts: seed and collector stamp must share a '
            . 'wall-clock second at least once');
    }

    /**
     * The TOCTOU merge-window input class (review r2 finding 1): if the old
     * write path slips NULL-keyed duplicates in between STEP 1's clobber and
     * STEP 1b's merge, GROUP BY would fold them into ONE group but the DELETE's
     * equality JOIN can never remove a NULL loser — an unguarded merge would
     * INFLATE the survivor (double-count on retry, breaking R3 byte-exactness).
     * STEP 1b therefore excludes NULL keys; this test runs the migration's OWN
     * two merge statements (extracted from the shipped file with the runner's
     * own splitStatements, not a hand copy) against the exact mid-race state:
     * a sentinel duplicate pair that MUST merge and a NULL pair that MUST be
     * left untouched for the MODIFY to reject.
     */
    public function testTheMergeStatementsLeaveRacedNullGroupsUntouched(): void
    {
        $this->applyPreSchema();

        $db = $this->db();
        foreach (
            [
                ['s1', '', 'movie', 1, 100, 1],
                ['s2', '', 'movie', 2, 200, 2],
                ['r1', null, 'movie', 10, 1_000, 10],
                ['r2', null, 'movie', 20, 2_000, 20],
            ] as [$id, $libraryId, $type, $items, $bytes, $cache]
        ) {
            $db->query(
                'INSERT INTO stats_storage (id, recorded_at, library_id, media_type, item_count,
                                            total_bytes, transcode_cache_bytes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, self::T1, $libraryId, $type, $items, $bytes, $cache]
            );
        }

        $split = new ReflectionMethod(MigrationRunner::class, 'splitStatements');
        $split->setAccessible(true);
        /** @var list<string> $statements */
        $statements = $split->invoke(null, $this->migrationSql(self::MIGRATION_105));

        $merge = array_values(array_filter(
            $statements,
            static fn(string $s): bool => str_contains($s, 'phlix_duplicate_groups')
                || str_contains($s, 'phlix_duplicate_survivors')
        ));
        $this->assertCount(
            2,
            $merge,
            'POSITIVE CONTROL: exactly the merge UPDATE and merge DELETE must carry those derived-table '
            . 'aliases — if the migration reorganizes and this grabs nothing, the proof below is vacuous'
        );
        foreach ($merge as $statement) {
            $this->assertStringContainsString('library_id IS NOT NULL', $statement);
        }

        $merge[0] = rtrim($merge[0], " \t\n\r\0\x0B;");
        $db->query($merge[0]);
        $merge[1] = rtrim($merge[1], " \t\n\r\0\x0B;");
        $db->query($merge[1]);

        $survivor = $this->rows("SELECT item_count, total_bytes FROM stats_storage WHERE id = 's1'");
        $this->assertCount(1, $survivor, 'the sentinel pair must merge');
        $this->assertSame(
            ['3', '300'],
            [(string) $survivor[0]['item_count'], (string) $survivor[0]['total_bytes']],
            'the sentinel merge must be unaffected by the NULL guard'
        );
        $this->assertSame([], $this->rows("SELECT id FROM stats_storage WHERE id = 's2'"));

        $raced = $this->rows("SELECT id, item_count FROM stats_storage WHERE id IN ('r1','r2') ORDER BY id");
        $this->assertCount(
            2,
            $raced,
            'the NULL pair must survive UNTOUCHED — folding without deleting would double-count on retry'
        );
        $this->assertSame(
            ['10', '20'],
            [(string) $raced[0]['item_count'], (string) $raced[1]['item_count']],
            'no survivor may carry an inflated SUM of rows that still exist'
        );
    }

    /**
     * Ledger-bypassing replay (raw `mysql < file` class): with the 105 ledger row
     * deleted, a second full pass is silent, error-free, and changes nothing.
     */
    public function testReplayWithALostLedgerRowIsASilentNoOp(): void
    {
        $this->applyPreSchema();
        $this->seedCanonicalFixture();
        $this->applyFix();

        $rowsBefore = $this->rows('SELECT * FROM stats_storage ORDER BY id');
        $indexBefore = $this->rows(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'stats_storage\' ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );

        $this->db()->query('DELETE FROM schema_migrations WHERE name = ?', [self::MIGRATION_105]);
        $replay = $this->applyFix();

        $this->assertSame([], $replay['errors'], 'a replay must raise no genuine error');
        $this->assertSame(MigrationRunner::EXIT_SUCCESS, MigrationRunner::exitCodeFor($replay));
        $this->assertSame($rowsBefore, $this->rows('SELECT * FROM stats_storage ORDER BY id'));
        $this->assertSame($indexBefore, $this->rows(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'stats_storage\' ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        ));
    }

    /**
     * The SV-4.9 ledger: a clean apply records 105 with the file's own checksum,
     * so the NEXT deploy skips it without executing (the reason 105 is a no-op
     * for a narrower purpose than "every deploy").
     */
    public function testTheLedgerRecordsTheAppliedMigration(): void
    {
        $this->applyPreSchema();
        $this->seedCanonicalFixture();
        $this->applyFix();

        $ledger = $this->rows(
            'SELECT checksum FROM schema_migrations WHERE name = ?',
            [self::MIGRATION_105]
        );
        $this->assertCount(1, $ledger, 'a successful apply must record the ledger row');
        $this->assertSame(
            $this->runnerChecksum($this->migrationSql(self::MIGRATION_105)),
            (string) $ledger[0]['checksum'],
            'the ledger checksum must equal the RUNNER checksum of the shipped file'
        );
    }

    /**
     * R5 empiricism pin: `MODIFY … NOT NULL` over live NULLs fails 1138 on this
     * build (strict mode) — the measurement that forced 105's
     * collapse→merge→MODIFY order instead of the naive single MODIFY. If a future
     * server makes that MODIFY silently convert, THIS reddens and 105 may be
     * simplified — deliberately, not by assumption.
     */
    public function testModifyNotNullOverLiveNullsReallyDoesFailThirteenThirtyEight(): void
    {
        $this->applyPreSchema();
        $this->seedCanonicalFixture();

        $code = $this->insertExpectingFailure(
            "ALTER TABLE stats_storage MODIFY COLUMN library_id CHAR(36) NOT NULL DEFAULT ''"
        );
        $this->assertStringContainsString(
            '1138',
            $code,
            'if MODIFY converts NULLs silently here, 105 step 1 still holds — but this file should be '
            . 're-read and simplified then, not left to rot on a stale premise'
        );
    }

    // -----------------------------------------------------------------------
    // Chain-level proof on the real (CI-built) schema. Read-only.
    // -----------------------------------------------------------------------

    /**
     * The shipped chain, `php scripts/run-migrations.php` alone, must land
     * `phlix_test` on the deterministic column and the contractual key — same
     * doctrine as PlaybackStateUniqueKeyPresentTest: an absent key here is a
     * broken chain, not an environment gap.
     */
    public function testTheRealSchemaBuiltByTheChainCarriesColumnAndKey(): void
    {
        $db = $this->requireRealDatabase('skipping the live-schema chain check. Runs in CI.');

        $column = $db->query(
            "SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stats_storage'
                AND COLUMN_NAME = 'library_id'"
        );
        $this->assertIsArray($column);
        $this->assertCount(1, $column);
        $this->assertSame('NO', (string) $column[0]['IS_NULLABLE']);
        $this->assertSame('', (string) $column[0]['COLUMN_DEFAULT']);

        $index = $db->query(
            "SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stats_storage'
                AND INDEX_NAME = '" . self::KEY_NAME . "' ORDER BY SEQ_IN_INDEX"
        );
        $this->assertIsArray($index);
        $this->assertCount(3, $index);
        $this->assertSame(
            ['recorded_at', 'media_type', 'library_id'],
            array_map(static fn(array $r): string => (string) $r['COLUMN_NAME'], $index)
        );
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    private function db(): Connection
    {
        $db = $this->db;
        $this->assertNotNull($db, 'the scratch connection must be open');

        return $db;
    }

    private function migrationPath(string $basename): string
    {
        return dirname(__DIR__, 3) . '/migrations/' . $basename;
    }

    private function migrationSql(string $basename): string
    {
        $sql = file_get_contents($this->migrationPath($basename));
        $this->assertIsString($sql, $basename . ' must be readable');

        return $sql;
    }

    private function runnerChecksum(string $sql): string
    {
        $checksum = new ReflectionMethod(MigrationRunner::class, 'checksum');
        $checksum->setAccessible(true);

        return (string) $checksum->invoke(null, $sql);
    }

    /** @return array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int} */
    private function runDir(string $dir): array
    {
        $db = $this->db();
        $runner = new MigrationRunner(static fn(): Connection => $db, $dir);

        /** @var array{applied: list<string>, notes: list<string>, errors: list<string>, skipped_count: int} $result */
        $result = $runner->run();

        return $result;
    }

    /** Pass 1: build the pre-105 schema from the REAL 019+086 files (S345 rule 2). */
    private function applyPreSchema(): void
    {
        $result = $this->runDir($this->preDir);
        $this->assertSame([], $result['errors'], '019+086 must build the pre-105 schema cleanly');
    }

    /** Pass 2: apply the REAL 105 file through the REAL runner. */
    private function applyFix(): array
    {
        return $this->runDir($this->fixDir);
    }

    /**
     * The six-row canonical fixture: a NULL pair (merge group 1), a distinct
     * library that must NEVER be merged away, the NULL-vs-'' collision pair
     * (merge group 2), and a lone NULL row (collapse only).
     */
    private function seedCanonicalFixture(): void
    {
        $db = $this->db();
        $db->query(
            'INSERT INTO stats_storage (id, recorded_at, library_id, media_type, item_count, total_bytes,
                                        transcode_cache_bytes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'a1', self::T1, null, 'movie', 1, 1_000, 10,
            ]
        );
        foreach (
            [
                ['a2', self::T1, null, 'movie', 2, 2_000, 20],
                ['a3', self::T1, 'lib-1', 'movie', 4, 4_000, 40],
                ['b1', self::T1, null, 'series', 5, 5_000, 50],
                ['b2', self::T1, '', 'series', 6, 6_000, 60],
                ['c1', self::T2, null, 'music', 7, 7_000, 70],
            ] as $row
        ) {
            $db->query(
                'INSERT INTO stats_storage (id, recorded_at, library_id, media_type, item_count, total_bytes,
                                            transcode_cache_bytes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                $row
            );
        }
    }

    /** @return array<int, int> [rows, items, bytes, cache] over the WHOLE table */
    private function grandTotals(): array
    {
        $rows = $this->rows(
            'SELECT COUNT(*) AS n, COALESCE(SUM(item_count),0) AS i, COALESCE(SUM(total_bytes),0) AS b,
                    COALESCE(SUM(transcode_cache_bytes),0) AS c
               FROM stats_storage'
        );
        $this->assertCount(1, $rows);

        return [
            (int) $rows[0]['n'],
            (int) $rows[0]['i'],
            (int) $rows[0]['b'],
            (int) $rows[0]['c'],
        ];
    }

    /**
     * The real `DashboardService::getStorageSummary()` over the scratch
     * connection — the production SQL verbatim; only collaborators the storage
     * card never touches are mocked.
     *
     * @return array<string, mixed>
     */
    private function dashboardSummary(): array
    {
        $db = $this->db();

        $dashboard = new DashboardService(
            new StatsCollector($db),
            $this->createMock(SessionManager::class),
            $this->createMock(StreamManager::class),
            $this->createMock(ItemRepository::class),
            $db
        );

        return $dashboard->getStorageSummary();
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $result = $this->db()->query($sql, $params);
        $this->assertIsArray($result, 'expected a row set from: ' . $sql);

        return $result;
    }

    private function scalar(string $sql): int
    {
        $rows = $this->rows($sql);
        $this->assertCount(1, $rows);
        $first = $rows[0];

        return (int) reset($first);
    }

    /** Runs a statement expected to FAIL and returns the errno as text. */
    private function insertExpectingFailure(string $sql): string
    {
        try {
            $this->db()->query($sql);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        $this->fail('statement unexpectedly SUCCEEDED, expected rejection: ' . $sql);
    }
}
