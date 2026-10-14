<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Database;

use Phlix\Auth\WatchHistory;
use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Config\EffectiveConfig;
use Phlix\Media\RecommendationService;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Stats\StatsCollector;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * DBA remediation (R19-02/03/05/06/07/09/10/12/14/15) — real-MySQL proof over
 * migrations 113–120 and the rewritten query paths.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * Index existence, index column ORDER, TABLE_COLLATION after CONVERT, the
 * 1061/1091 replay squelch through the production runner, INSERT ... ON
 * DUPLICATE KEY UPDATE hitting the real UNIQUE idx_profile_media, the
 * ROW_NUMBER() batched similarity CTE, DATE_SUB(NOW(), INTERVAL ? SECOND)
 * idle sweeps that must NOT move when the PHP clock's timezone moves, and the
 * half-open CURDATE() range — every one of these is a live-server property a
 * mocked Connection accepts-and-models-none-of.
 *
 * SAFETY: everything runs in a throwaway `phlix_s113_*` DATABASE built by
 * applying the REAL full chain (001 → 120) through the production
 * MigrationRunner — the same thing the CI phpunit job does before PHPUnit —
 * never against the shared `phlix_test` tables. The scratch DB is built ONCE
 * per class (the chain is identical for every test; DDL replay is the only
 * mutation and it is proven no-op here) and dropped in tearDownAfterClass.
 * Row-level seeds are namespaced by a per-test unique run id, so the random
 * test order cannot cross-contaminate any assertion.
 */
final class DbRemediationRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    /** The ten indexes migrations 113–118 must produce, table => name => ordered columns. */
    private const NEW_INDEXES = [
        'sessions' => ['idx_sessions_last_activity' => 'last_activity'],
        'playback_state' => ['idx_playback_state_media_updated' => 'media_item_id,updated_at'],
        'watch_history' => [
            'idx_watch_history_profile_last' => 'profile_id,last_watched_at',
            'idx_watch_history_profile_completed' => 'profile_id,completed_at',
        ],
        'active_streams' => ['idx_active_streams_session' => 'session_id'],
        'stats_library_changes' => ['idx_stats_library_changes_changed_at' => 'changed_at'],
        'stats_user_activity' => [
            'idx_stats_user_activity_occurred_at' => 'occurred_at',
            'idx_stats_user_activity_type_occurred' => 'activity_type,occurred_at',
            'idx_stats_user_activity_user_id' => 'user_id',
        ],
        'media_items' => ['idx_media_items_library_created' => 'library_id,created_at'],
    ];

    /** The ten tables migration 120 folds back to the estate collation. */
    private const DRIFTED_TABLES = [
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

    private static ?Connection $db = null;

    private static ?Connection $admin = null;

    private static string $scratchDb = '';

    private string $run = '';

    /** @var list<string> */
    private array $configDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireHealthyDatabase('skipping the DB-remediation real-schema proof. Runs in CI.');

        if (self::$db === null) {
            self::buildScratchSchema();
        }

        $this->run = 'r' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach ($this->configDirs as $dir) {
            foreach ((array) glob($dir . '/*') as $file) {
                if (is_string($file)) {
                    unlink($file);
                }
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->configDirs = [];

        EffectiveConfig::reset();

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin !== null && self::$scratchDb !== '') {
            self::$admin->query('DROP DATABASE IF EXISTS `' . self::$scratchDb . '`');
        }

        self::$db = null;
        self::$admin = null;
        self::$scratchDb = '';

        parent::tearDownAfterClass();
    }

    // -----------------------------------------------------------------
    // Structural proofs (migrations 113–120 against the real schema)
    // -----------------------------------------------------------------

    /**
     * R19-03/05/06/07/10/14: every new index exists exactly once, on the right
     * table, with the right columns in the right ORDER (composite reads depend
     * on column order; SHOW INDEX seq_in_index is the only authority).
     */
    public function testEveryRemediationIndexExistsWithExactColumnOrder(): void
    {
        $db = self::db();

        $found = [];
        foreach (self::NEW_INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                $rows = $db->query(
                    'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
                    . ' ORDER BY SEQ_IN_INDEX',
                    [$table, $name]
                );
                $this->assertIsArray($rows);
                if ($rows === []) {
                    continue;
                }
                $found[$name] = implode(',', array_map(
                    static fn (array $r): string => (string) $r['COLUMN_NAME'],
                    $rows
                ));
            }
        }

        $expected = [];
        foreach (self::NEW_INDEXES as $indexes) {
            foreach ($indexes as $name => $columns) {
                $expected[$name] = $columns;
            }
        }

        $this->assertSame(array_keys($expected), array_keys($found), 'all ten indexes, found once each');
        $this->assertSame($expected, $found, 'column composition/order drifted');
    }

    /**
     * R19-09: the ten drifted tables are utf8mb4_unicode_ci now — MySQL 8's
     * utf8mb4_0900_ai_ci default is exactly what JOINs against the unicode_ci
     * estate schema choke on.
     */
    public function testTheTenDriftedTablesConvergedToUnicodeCi(): void
    {
        $db = self::db();

        $placeholders = implode(',', array_fill(0, count(self::DRIFTED_TABLES), '?'));
        $rows = $db->query(
            'SELECT TABLE_NAME, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
            self::DRIFTED_TABLES
        );
        $this->assertIsArray($rows);
        $this->assertCount(count(self::DRIFTED_TABLES), $rows, 'every drifted table still exists after CONVERT');

        $byTable = [];
        foreach ($rows as $row) {
            $byTable[(string) $row['TABLE_NAME']] = (string) $row['TABLE_COLLATION'];
        }
        foreach (self::DRIFTED_TABLES as $table) {
            $this->assertSame(
                'utf8mb4_unicode_ci',
                $byTable[$table],
                "{$table} did not converge to the estate collation"
            );
        }
    }

    /**
     * R19-15: the three redundant twins are gone and the constraints they
     * duplicated SURVIVE — the inline UNIQUEs named `username`/`email` are what
     * actually enforces users uniqueness, and dropping one of THOSE by an
     * over-broad migration would be a data-integrity incident, not cleanup.
     */
    public function testDeadIndexDropsRemovedOnlyTheRedundantTwins(): void
    {
        $db = self::db();

        // Scoped per-table: 052 also carries an index literally named
        // idx_score on ANOTHER table — 119 only ever touched item_similar's.
        $gone = $db->query(
            'SELECT TABLE_NAME, INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND (('
            . "TABLE_NAME = 'users' AND INDEX_NAME IN ('idx_username', 'idx_email')) OR ("
            . "TABLE_NAME = 'item_similar' AND INDEX_NAME = 'idx_score'))"
        );
        $this->assertIsArray($gone);
        $this->assertSame([], $gone, '119 must drop users.idx_username, users.idx_email and item_similar.idx_score');

        $uniques = $db->query(
            "SELECT INDEX_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME IN ('username', 'email')"
        );
        $this->assertIsArray($uniques);
        $this->assertCount(2, $uniques, 'the inline UNIQUE constraints (same-named indexes) must survive');
        foreach ($uniques as $row) {
            $this->assertSame(0, (int) $row['NON_UNIQUE'], $row['INDEX_NAME'] . ' must stay UNIQUE');
        }

        $similar = $db->query(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_similar'"
            . ' GROUP BY INDEX_NAME'
        );
        $this->assertIsArray($similar);
        $names = array_map(static fn (array $r): string => (string) $r['INDEX_NAME'], $similar);
        sort($names);
        $this->assertSame(['PRIMARY', 'idx_similar_item_id'], $names);
    }

    /**
     * Replay safety under the PRODUCTION runner: delete 113–120's ledger rows
     * so the runner re-executes their bodies against the already-migrated
     * schema. The ten ADD INDEX statements raise 1061, the three DROP INDEX
     * statements raise 1091 — every one is downgraded to a note by
     * MigrationRunner::isAlreadyApplied(), the ten CONVERTs are legal content
     * no-ops, and the schema is bit-for-bit the same afterwards.
     */
    public function testLedgerForcedReplayOfMigrations113To120IsErrorFreeAndStructurallyStable(): void
    {
        $db = self::db();

        $db->query(
            "DELETE FROM schema_migrations WHERE name LIKE '113_%' OR name LIKE '114_%' OR name LIKE '115_%'"
            . " OR name LIKE '116_%' OR name LIKE '117_%' OR name LIKE '118_%' OR name LIKE '119_%'"
            . " OR name LIKE '120_%'"
        );

        $runner = new MigrationRunner(
            static fn (): Connection => self::db(),
            dirname(__DIR__, 3) . '/migrations'
        );
        $result = $runner->run();

        $this->assertSame(
            [],
            $result['errors'],
            'replay must be error-free (1061/1091 squelch + CONVERT no-op): ' . implode('; ', $result['errors'])
        );

        // Exactly one ledger row per remediation file again (re-recorded, not duplicated).
        foreach (['113', '114', '115', '116', '117', '118', '119', '120'] as $number) {
            $ledger = $db->query('SELECT name FROM schema_migrations WHERE name LIKE ?', [$number . '_%']);
            $this->assertIsArray($ledger);
            $this->assertCount(1, $ledger, "migration {$number} must be re-recorded exactly once");
        }

        // Structural stability: the ten remediation indexes did not double or
        // vanish, and the dropped twins stayed dropped.
        $count = $db->query(
            'SELECT COUNT(DISTINCT INDEX_NAME) AS n FROM INFORMATION_SCHEMA.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME IN ('
            . "'idx_sessions_last_activity','idx_playback_state_media_updated',"
            . "'idx_watch_history_profile_last','idx_watch_history_profile_completed',"
            . "'idx_active_streams_session','idx_stats_library_changes_changed_at',"
            . "'idx_stats_user_activity_occurred_at','idx_stats_user_activity_type_occurred',"
            . "'idx_stats_user_activity_user_id','idx_media_items_library_created')"
        );
        $this->assertIsArray($count);
        $this->assertSame(10, (int) $count[0]['n']);

        $collation = $db->query(
            "SELECT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'active_streams'"
        );
        $this->assertIsArray($collation);
        $this->assertSame('utf8mb4_unicode_ci', (string) $collation[0]['TABLE_COLLATION']);
    }

    // -----------------------------------------------------------------
    // Behavioural proofs (the rewritten query paths on the real schema)
    // -----------------------------------------------------------------

    /**
     * R19-02: the single atomic upsert. Two sequential updateProgress() calls
     * for the same (profile, item) collapse to ONE row; the completion stamp
     * written when the >=90% threshold is crossed SURVIVES a later sub-
     * threshold progress report (COALESCE arm), exactly like the old UPDATE
     * branch — and a virgin profile+item still INSERTs cleanly. Constructing
     * the service WITH a real RecommendationService also executes the batched
     * CTE on the real schema at the newly-completed hook.
     */
    public function testWatchHistoryUpsertIsAtomicSingleRowAndKeepsCompletedAt(): void
    {
        $db = self::db();
        $user = $this->uid('user');
        $profile = $this->uid('prof');
        $library = $this->uid('lib');
        $itemA = $this->uid('itemA');
        $itemB = $this->uid('itemB');

        $this->seedUser($user);
        $this->seedProfile($profile, $user);
        $this->seedLibrary($library);
        $this->seedMedia($itemA, $library, 'Alpha');
        $this->seedMedia($itemB, $library, 'Beta');

        $history = new WatchHistory($db, new RecommendationService($db));

        // 95% of 1000 ticks -> crosses COMPLETED_THRESHOLD (90.0).
        $entry = $history->updateProgress($profile, $itemA, 950, 1000);
        $this->assertSame('completed', $entry['playback_status'] ?? null);

        // 45% afterwards -> status/progress move, completed_at must NOT.
        $history->updateProgress($profile, $itemA, 450, 1000, 'paused');

        $rows = $db->query(
            'SELECT playback_status, progress_percent, completed_at, last_watched_at'
            . ' FROM watch_history WHERE profile_id = ? AND media_item_id = ?',
            [$profile, $itemA]
        );
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows, 'the ODKU must collapse repeat reports to a single row');
        $this->assertSame('paused', (string) $rows[0]['playback_status']);
        $this->assertSame('45.00', (string) $rows[0]['progress_percent']);
        $this->assertNotNullableDate($rows[0]['completed_at'], 'completed_at must survive a later partial report');
        $this->assertNotNull($rows[0]['last_watched_at']);

        // Virgin (profile, item) -> plain INSERT arm of the same statement.
        $history->updateProgress($profile, $itemB, 100, 1000, 'playing');
        $rows = $db->query(
            'SELECT playback_status, completed_at FROM watch_history WHERE profile_id = ? AND media_item_id = ?',
            [$profile, $itemB]
        );
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('playing', (string) $rows[0]['playback_status']);
        $this->assertNull($rows[0]['completed_at'], 'a sub-threshold virgin row must stay uncompleted');
    }

    /**
     * R19-06: getTodayWatchTime's index-friendly half-open range must agree
     * with the calendar day the DATABASE sees: today 00:00:00 (lower bound,
     * inclusive) and NOW() count, yesterday 23:59:59 does not, and non-
     * completed rows never count regardless of timestamp.
     */
    public function testTodayWatchTimeRangeRespectsDayBoundariesOnTheDatabaseClock(): void
    {
        $db = self::db();
        $user = $this->uid('user');
        $profile = $this->uid('prof');
        $library = $this->uid('lib');

        $this->seedUser($user);
        $this->seedProfile($profile, $user);
        $this->seedLibrary($library);

        $hour = 3600 * WatchHistory::TICKS_PER_SECOND;
        $minute = 60 * WatchHistory::TICKS_PER_SECOND;

        $this->seedCompletedWatch($this->uid('w1'), $profile, $library, 'CURDATE()', $hour);
        $this->seedCompletedWatch($this->uid('w2'), $profile, $library, 'NOW()', $minute);
        $this->seedCompletedWatch(
            $this->uid('w3'),
            $profile,
            $library,
            'DATE_SUB(CURDATE(), INTERVAL 1 SECOND)',
            $hour
        );
        // Completed yesterday at noon, and a TODAY row still in progress: both excluded.
        $this->seedCompletedWatch($this->uid('w4'), $profile, $library, 'DATE_SUB(CURDATE(), INTERVAL 12 HOUR)', $hour);
        $this->seedWatchRow($this->uid('w5'), $profile, $library, 'playing', 'NOW()', $hour);

        $seconds = (new WatchHistory($db))->getTodayWatchTime($profile);

        $this->assertSame(
            3660,
            $seconds,
            'only the two TODAY completed rows (60min + 1min) count; the boundary and status filters hold'
        );
    }

    /**
     * R19-03: the idle sweep is immune to host-vs-MySQL timezone skew. With
     * PHP's clock pinned to UTC+14 (a 14h-ahead wall against the UTC server),
     * the OLD `date('Y-m-d H:i:s', time() - 86400)` cutoff would have deleted
     * an 8-hour-idle session out from under a live user — the nightly mass-
     * logout shape. The DATE_SUB(NOW(), ...) form must, in the SAME shifted-
     * PHP-clock process, delete only the truly-stale row and keep the 8-hour
     * row, and getOnlineUsers must agree with the DB clock too.
     */
    public function testSessionIdleSweepRunsOnDatabaseClockUnderAShiftedPhpTimezone(): void
    {
        $db = self::db();
        $staleUser = $this->uid('stale');
        $edgeUser = $this->uid('edge');
        $liveUser = $this->uid('live');

        $this->seedUser($staleUser);
        $this->seedUser($edgeUser);
        $this->seedUser($liveUser);

        $this->seedSession($this->uid('s-stale'), $staleUser, 'DATE_SUB(NOW(), INTERVAL 100000 SECOND)');
        $this->seedSession($this->uid('s-edge'), $edgeUser, 'DATE_SUB(NOW(), INTERVAL 28800 SECOND)');
        $this->seedSession($this->uid('s-live'), $liveUser, 'NOW()');

        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14, no DST — pure +14h wall skew.
        try {
            $manager = new SessionManager($db);

            $deleted = $manager->cleanupStaleSessions(86400);
            $this->assertGreaterThanOrEqual(1, $deleted);

            $remaining = $db->query(
                'SELECT id FROM sessions WHERE id IN (?, ?)',
                [$this->uid('s-stale'), $this->uid('s-edge')]
            );
            $this->assertIsArray($remaining);
            $ids = array_map(static fn (array $r): string => (string) $r['id'], $remaining);
            $this->assertNotContains($this->uid('s-stale'), $ids, '27.8h-idle session must be swept');
            $this->assertContains(
                $this->uid('s-edge'),
                $ids,
                'an 8h-idle session must survive a 24h cutoff even when PHP thinks it is 14h later'
            );

            $online = $manager->getOnlineUsers();
            $this->assertContains($liveUser, $online);
            $this->assertNotContains($edgeUser, $online, '8h-idle is not online under the 300s DB-clock window');
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /**
     * R19-05: the narrow pre-limit continue-watching rewrite returns one entry
     * per media item (newest playback row wins across sessions), excludes
     * sub-threshold statuses/positions, and orders by recency — byte-for-byte
     * the semantics of the old wide query.
     */
    public function testContinueWatchingDedupesToNewestPerItemAndRespectsFilters(): void
    {
        $db = self::db();
        $user = $this->uid('user');
        $library = $this->uid('lib');
        $this->seedUser($user);
        $this->seedLibrary($library);

        $items = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $tag) {
            $items[$tag] = $this->uid('item' . $tag);
            $this->seedMedia($items[$tag], $library, 'Item ' . $tag);
        }

        $sessionOne = $this->uid('sess1');
        $sessionTwo = $this->uid('sess2');
        $this->seedSession($sessionOne, $user, 'NOW()');
        $this->seedSession($sessionTwo, $user, 'NOW()');

        // Item A played in BOTH sessions: the newer row (pos 400) must win.
        $this->seedPlayback($this->uid('p1'), $sessionOne, $items['A'], 100, 1000, 'playing', "'2026-01-01 00:00:00'");
        $this->seedPlayback($this->uid('p2'), $sessionTwo, $items['A'], 400, 1000, 'paused', "'2026-01-02 00:00:00'");
        // Item B: single newest row overall. C: 96% done (finished-ish) -> excluded.
        $this->seedPlayback($this->uid('p3'), $sessionTwo, $items['B'], 200, 1000, 'playing', "'2026-01-03 00:00:00'");
        $this->seedPlayback($this->uid('p4'), $sessionOne, $items['C'], 960, 1000, 'playing', "'2026-01-04 00:00:00'");
        // D: stopped. E: position 0. Both excluded by the status/position gates.
        $this->seedPlayback($this->uid('p5'), $sessionTwo, $items['D'], 100, 1000, 'stopped', "'2026-01-05 00:00:00'");
        $this->seedPlayback($this->uid('p6'), $sessionOne, $items['E'], 0, 1000, 'playing', "'2026-01-06 00:00:00'");

        $controller = new PlaybackController($db, new SessionManager($db));
        $entries = $controller->getContinueWatching($user, 10);

        $this->assertCount(2, $entries, 'dedupe to one row per item; C/D/E filtered out');
        $this->assertSame($items['B'], $entries[0]['media_item_id'], 'recency order: B is the newest row');
        $this->assertSame(200, $entries[0]['position_ticks']);
        $this->assertSame($items['A'], $entries[1]['media_item_id']);
        $this->assertSame(400, $entries[1]['position_ticks'], 'the newest session row must win for item A');
        $this->assertSame($items['A'], $entries[1]['id'], 'the shaped id is the MEDIA item id, not the state row id');
    }

    /**
     * R19-10: the retention sweeper deletes expired rows on BOTH stats tables
     * via the real cutoff arithmetic, keeps everything inside the window, and
     * honors the config knob (30 days here — a 10-day-old row must survive it
     * while a 100-day-old row must not).
     */
    public function testStatsRetentionSweepEndToEndHonorsConfiguredWindow(): void
    {
        $db = self::db();

        $dir = sys_get_temp_dir() . '/phlix-s113-stats-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        $this->configDirs[] = $dir;
        file_put_contents($dir . '/stats.php', '<?php return ' . var_export([
            'enabled' => true,
            'retention_days' => 30,
        ], true) . ';');
        $fakeSettings = $this->createMock(Connection::class);
        $fakeSettings->method('query')->willReturnCallback(
            /** @return list<array<string,mixed>> */
            static function (): array {
                return [];
            }
        );
        EffectiveConfig::bootstrap($fakeSettings, null, $dir);

        $this->assertSame(30, StatsCollector::retentionDays());

        $old = $this->uid('old');
        $fresh = $this->uid('fresh');
        // changed_at/occurred_at take server-clock SQL expressions — constant
        // literals from this test, inlined like the sibling seeds; the runner
        // connection binds values, never SQL fragments.
        $db->query(
            "INSERT INTO stats_library_changes (id, change_type, changed_at) VALUES ('{$old}', 'item_added',"
            . " DATE_SUB(NOW(), INTERVAL 100 DAY))"
        );
        $db->query(
            "INSERT INTO stats_library_changes (id, change_type, changed_at) VALUES ('{$fresh}', 'item_removed',"
            . " DATE_SUB(NOW(), INTERVAL 10 DAY))"
        );
        $db->query(
            "INSERT INTO stats_user_activity (id, user_id, activity_type, occurred_at) VALUES ('{$old}-a',"
            . " '" . $this->uid('who') . "', 'login', DATE_SUB(NOW(), INTERVAL 100 DAY))"
        );
        $db->query(
            "INSERT INTO stats_user_activity (id, user_id, activity_type, occurred_at) VALUES ('{$fresh}-a',"
            . " '" . $this->uid('who') . "', 'login', DATE_SUB(NOW(), INTERVAL 10 DAY))"
        );

        $deleted = (new StatsCollector($db))->sweepRetention();

        $this->assertGreaterThanOrEqual(2, $deleted, 'one expired row per stats table must go');

        $left = $db->query('SELECT id FROM stats_library_changes WHERE id IN (?, ?)', [$old, $fresh]);
        $this->assertIsArray($left);
        $this->assertSame([$fresh], array_map(static fn (array $r): string => (string) $r['id'], $left));

        $leftA = $db->query(
            'SELECT id FROM stats_user_activity WHERE id IN (?, ?)',
            [$old . '-a', $fresh . '-a']
        );
        $this->assertIsArray($leftA);
        $this->assertSame([$fresh . '-a'], array_map(static fn (array $r): string => (string) $r['id'], $leftA));
    }

    /**
     * R19-12: the batched ROW_NUMBER() similarity CTE executes on the real
     * schema and preserves the per-seed top-20 cap, the INNER JOIN drop of
     * dangling targets, the watched-item exclusion, and score-desc ordering —
     * all in ONE read instead of one round trip per seed.
     */
    public function testBecauseYouWatchedBatchCteCapsPerSeedAndExcludesWatchedAndMissingTargets(): void
    {
        $db = self::db();
        $user = $this->uid('user');
        $profile = $this->uid('prof');
        $library = $this->uid('lib');

        $this->seedUser($user);
        $this->seedProfile($profile, $user);
        $this->seedLibrary($library);

        $seed1 = $this->uid('seed1');
        $seed2 = $this->uid('seed2');
        $this->seedMedia($seed1, $library, 'Seed One');
        $this->seedMedia($seed2, $library, 'Seed Two');

        $targets = [];
        for ($i = 1; $i <= 25; $i++) {
            $targets[$i] = $this->uid('t' . $i);
            $this->seedMedia($targets[$i], $library, 'Target ' . $i);
        }
        $a1 = $this->uid('a1');
        $a2 = $this->uid('a2');
        $this->seedMedia($a1, $library, 'Alpha');
        $this->seedMedia($a2, $library, 'Beta');
        $ghost = $this->uid('ghost'); // deliberately NO media_items row

        foreach (['seed1', 'seed2', 't5'] as $watched) {
            $id = ['seed1' => $seed1, 'seed2' => $seed2, 't5' => $targets[5]][$watched];
            $db->query(
                'INSERT INTO watch_history (id, profile_id, media_item_id, playback_status, completed_at)'
                . " VALUES (?, ?, ?, 'completed', NOW())",
                [$this->uid('w-' . $watched), $profile, $id]
            );
        }

        for ($i = 1; $i <= 25; $i++) {
            $score = sprintf('%.3f', 0.999 - 0.001 * $i);
            $db->query(
                'INSERT INTO item_similar (media_item_id, similar_item_id, score) VALUES (?, ?, ?)',
                [$seed1, $targets[$i], $score]
            );
        }
        $db->query(
            'INSERT INTO item_similar (media_item_id, similar_item_id, score) VALUES (?, ?, ?)',
            [$seed1, $ghost, '0.999']
        );
        $db->query(
            'INSERT INTO item_similar (media_item_id, similar_item_id, score) VALUES (?, ?, ?)',
            [$seed2, $a1, '0.900']
        );
        $db->query(
            'INSERT INTO item_similar (media_item_id, similar_item_id, score) VALUES (?, ?, ?)',
            [$seed2, $a2, '0.800']
        );

        (new RecommendationService($db))->computeBecauseYouWatched($user);

        $rows = $db->query(
            'SELECT media_item_id, score FROM user_recommendations WHERE user_id = ? ORDER BY score DESC',
            [$user]
        );
        $this->assertIsArray($rows);

        $recs = array_map(static fn (array $r): string => (string) $r['media_item_id'], $rows);

        // The INNER JOIN sits INSIDE the window's FROM, so the dangling ghost
        // is dropped BEFORE ranking (like getSimilar's post-JOIN LIMIT): the
        // seed1 slice is t1..t20, minus watched t5 = 19; seed2 adds a1+a2 = 21.
        $expectedSet = [$a1, $a2];
        for ($i = 1; $i <= 20; $i++) {
            if ($i === 5) {
                continue;
            }
            $expectedSet[] = $targets[$i];
        }
        sort($expectedSet);
        sort($recs);
        $this->assertCount(21, $rows, '19 from seed1 (joined top-20 minus watched t5) + a1 + a2');
        $this->assertSame($expectedSet, array_values($recs));
        $this->assertSame($targets[1], (string) $rows[0]['media_item_id'], 'score-desc ordering: t1 (0.998) leads');
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    private static function db(): Connection
    {
        $db = self::$db;
        assert($db instanceof Connection);

        return $db;
    }

    private static function buildScratchSchema(): void
    {
        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        self::$scratchDb = 'phlix_s113_' . bin2hex(random_bytes(6));

        self::$admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        self::$admin->query(
            'CREATE DATABASE `' . self::$scratchDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        self::$db = new PhlixMySQLConnection($host, $port, $user, $password, self::$scratchDb);

        $runner = new MigrationRunner(
            static fn (): Connection => self::db(),
            dirname(__DIR__, 3) . '/migrations'
        );
        $result = $runner->run();

        if ($result['errors'] !== []) {
            self::$db = null;
            self::$admin->query('DROP DATABASE IF EXISTS `' . self::$scratchDb . '`');
            self::$admin = null;
            self::$scratchDb = '';

            self::fail('full migration chain 001->120 must apply cleanly: ' . implode('; ', $result['errors']));
        }
    }

    private function uid(string $tag): string
    {
        return $this->run . '-' . $tag;
    }

    private function seedUser(string $id): void
    {
        self::db()->query(
            'INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)',
            [$id, $id . '-name', $id . '@example.test', 'x']
        );
    }

    private function seedProfile(string $id, string $userId): void
    {
        self::db()->query(
            'INSERT INTO user_profiles (id, user_id, name) VALUES (?, ?, ?)',
            [$id, $userId, 'P-' . substr($id, -4)]
        );
    }

    private function seedLibrary(string $id): void
    {
        self::db()->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'movie', '[]')",
            [$id, 'L-' . substr($id, -4)]
        );
    }

    private function seedMedia(string $id, string $libraryId, string $name): void
    {
        self::db()->query(
            "INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, 'movie', ?)",
            [$id, $libraryId, $name, '/media/' . $id . '.mkv']
        );
    }

    /**
     * @param string $activityExpr a server-clock SQL expression ('NOW()',
     *                             'DATE_SUB(NOW(), INTERVAL ...)') — inlined as
     *                             a constant literal, never user data.
     */
    private function seedSession(string $id, string $userId, string $activityExpr): void
    {
        self::db()->query(
            'INSERT INTO sessions (id, user_id, device_id, last_activity) VALUES (?, ?, ?, ' . $activityExpr . ')',
            [$id, $userId, $id . '-dev']
        );
    }

    private function seedPlayback(
        string $id,
        string $sessionId,
        string $mediaItemId,
        int $positionTicks,
        int $durationTicks,
        string $status,
        string $updatedAtLiteral
    ): void {
        self::db()->query(
            'INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks,'
            . " playback_status, updated_at) VALUES (?, ?, ?, ?, ?, '" . $status . "', " . $updatedAtLiteral . ')',
            [$id, $sessionId, $mediaItemId, $positionTicks, $durationTicks]
        );
    }

    /**
     * @param string $lastWatchedExpr server-clock SQL expression for last_watched_at.
     */
    private function seedCompletedWatch(
        string $id,
        string $profileId,
        string $libraryId,
        string $lastWatchedExpr,
        int $durationTicks
    ): void {
        $media = $id . '-media';
        $this->seedMedia($media, $libraryId, 'Watched ' . $id);
        $this->seedWatchRow($id, $profileId, $libraryId, 'completed', $lastWatchedExpr, $durationTicks, $media);
    }

    private function seedWatchRow(
        string $id,
        string $profileId,
        string $libraryId,
        string $status,
        string $lastWatchedExpr,
        int $durationTicks,
        ?string $mediaItemId = null
    ): void {
        if ($mediaItemId === null) {
            $mediaItemId = $id . '-media';
            $this->seedMedia($mediaItemId, $libraryId, 'Watched ' . $id);
        }
        self::db()->query(
            'INSERT INTO watch_history (id, profile_id, media_item_id, position_ticks, duration_ticks,'
            . " playback_status, progress_percent, last_watched_at, completed_at) VALUES (?, ?, ?, ?, ?, ?, 100.00,"
            . ' ' . $lastWatchedExpr . ','
            . ($status === 'completed' ? ' NOW()' : ' NULL') . ')',
            [$id, $profileId, $mediaItemId, $durationTicks, $durationTicks, $status]
        );
    }

    private function assertNotNullableDate(mixed $value, string $message): void
    {
        $this->assertIsString($value, $message);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value, $message);
    }
}
