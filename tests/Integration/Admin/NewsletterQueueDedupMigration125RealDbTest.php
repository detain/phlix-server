<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Admin;

use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Major-1 from the 2026-11-04 campaign-diff review — real-MySQL proof of
 * migration 125's dedup SURVIVOR RULE, not just its duplicate-rejection.
 *
 * ## Why this suite exists (the blind spot the review named)
 *
 * The pre-existing 1062 tests exercise duplicate REJECTION (INSERT IGNORE
 * after the UNIQUE is live). None of them seeds mixed-status duplicates and
 * asks WHICH row the in-migration DELETE spares. Keep-earliest did the
 * damage: `NewsletterSender::processQueue()` selects
 * `WHERE status = 'pending' AND attempts < ?`, so a `failed` row is TERMINAL
 * and a later `pending` row behind it is the ONLY retry vector for that
 * (user, week) — deleting the earliest-failed-then-pending pair's pending leg
 * silently unmailed the user. A later `sent` row behind an earliest `failed`
 * row proves delivery happened; deleting it made the queue stats claim the
 * user was never mailed. The dedup therefore orders survivors by status
 * precedence (sent > pending > failed) BEFORE created_at and id.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * The survivor is chosen by a JOIN-formed multi-table self-delete using
 * FIELD() rank arithmetic; the schema steps are the INFORMATION_SCHEMA +
 * PREPARE replay guards of 107/121/123/124. A mocked Connection models none
 * of it. Every proof runs in a throwaway `phlix_srpcure_*` DATABASE against
 * a fixture newsletter_queue carrying exactly the 020 columns — never the
 * shared tables. tearDown drops the scratch DB.
 */
final class NewsletterQueueDedupMigration125RealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const MIGRATION_125 = '125_newsletter_queue_user_week_unique.sql';

    /** The table verbatim from migration 020 (pre-125 shape: NON-UNIQUE idx_user_week). */
    private const FIXTURE_DDL =
        "CREATE TABLE newsletter_queue ("
        . " id CHAR(36) NOT NULL PRIMARY KEY,"
        . " user_id CHAR(36) NOT NULL,"
        . " week_start DATE NOT NULL,"
        . " status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',"
        . " attempts INT UNSIGNED NOT NULL DEFAULT 0,"
        . " last_attempt_at DATETIME NULL,"
        . " sent_at DATETIME NULL,"
        . " error_message TEXT NULL,"
        . " created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
        . " INDEX idx_user_week (user_id, week_start),"
        . " INDEX idx_status (status),"
        . " INDEX idx_created_at (created_at)"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    /** @var list<string> */
    private array $fixDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireHealthyDatabase('skipping the migration-125 survivor-precedence proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_srpcure_' . $rand;

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        $this->db->query(self::FIXTURE_DDL);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixDirs as $fixDir) {
            if (is_dir($fixDir)) {
                foreach ((array) glob($fixDir . '/*') as $file) {
                    if (is_string($file)) {
                        unlink($file);
                    }
                }
                rmdir($fixDir);
            }
        }
        $this->fixDirs = [];

        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->db = null;
        $this->admin = null;
        $this->scratchDb = '';

        parent::tearDown();
    }

    public function testPendingRetryRowSurvivesBehindAnEarlierFailedRow(): void
    {
        $db = $this->requireDb();

        // The terminal-failed + later-pending pair: the pending row is the
        // ONLY retry vector (processQueue never touches 'failed'), so it must
        // be the survivor even though it was queued second.
        $this->seedRow('f1', 'u-retry', '2026-10-05', 'failed', 3, '2026-10-05 08:00:00');
        $this->seedRow('p1', 'u-retry', '2026-10-05', 'pending', 0, '2026-10-05 09:00:00');

        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_125)['errors']);

        $this->assertSame(
            [$this->rowTuple('p1', 'pending', 0)],
            $this->queueRows($db),
            'a failed row is terminal — splicing out the pending retry would silently un-mail the user'
        );
    }

    public function testSentRowSurvivesBehindAnEarlierFailedRow(): void
    {
        $db = $this->requireDb();

        // A 'sent' row is PROOF of delivery; keeping the earliest-'failed'
        // neighbour instead deleted the proof and made queue stats claim the
        // user was never mailed.
        $this->seedRow('f2', 'u-sent', '2026-10-05', 'failed', 3, '2026-10-05 08:00:00');
        $this->seedRow('s1', 'u-sent', '2026-10-05', 'sent', 1, '2026-10-05 09:00:00');

        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_125)['errors']);

        $this->assertSame(
            [$this->rowTuple('s1', 'sent', 1)],
            $this->queueRows($db),
            'the row that proves delivery must outlive a failed neighbour'
        );
    }

    public function testSentOutranksPendingRegardlessOfQueueOrder(): void
    {
        $db = $this->requireDb();

        $this->seedRow('p2', 'u-sent-late', '2026-10-05', 'pending', 0, '2026-10-05 08:00:00');
        $this->seedRow('s2', 'u-sent-late', '2026-10-05', 'sent', 1, '2026-10-05 09:00:00');

        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_125)['errors']);

        $this->assertSame(
            [$this->rowTuple('s2', 'sent', 1)],
            $this->queueRows($db),
            'sent > pending: the delivered row is the honest survivor even when queued second'
        );
    }

    public function testThreeStatusGroupKeepsExactlyTheSentRow(): void
    {
        $db = $this->requireDb();

        // failed earliest by time, pending middle, sent last — AND the sent
        // row carrying the lex-greatest tiebreak pieces: precedence must
        // trump created_at AND id here, or the whole rule is just keep-earliest.
        $this->seedRow('f3', 'u-triple', '2026-10-05', 'failed', 3, '2026-10-05 07:00:00');
        $this->seedRow('p3', 'u-triple', '2026-10-05', 'pending', 1, '2026-10-05 08:00:00');
        $this->seedRow('s3', 'u-triple', '2026-10-05', 'sent', 2, '2026-10-05 09:00:00');

        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_125)['errors']);

        $this->assertSame(
            [$this->rowTuple('s3', 'sent', 2)],
            $this->queueRows($db),
            'one survivor per group, and it is the sent row'
        );
    }

    public function testWithinStatusTheEarliestRowStillWins(): void
    {
        $db = $this->requireDb();

        // Control arm (green before and after the cure): same status,
        // different created_at — the created_at order must be honoured, and
        // must beat the id order (the later-queued row has the lex-smaller id).
        $this->seedRow('p4', 'u-earliest', '2026-10-05', 'pending', 0, '2026-10-05 08:00:00');
        $this->seedRow('a1', 'u-earliest', '2026-10-05', 'pending', 1, '2026-10-05 09:00:00');

        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_125)['errors']);

        $this->assertSame(
            [$this->rowTuple('p4', 'pending', 0)],
            $this->queueRows($db),
            'inside one status the earliest created_at is the survivor'
        );
    }

    public function testIdBreaksCreatedAtTiesInsideOneStatus(): void
    {
        $db = $this->requireDb();

        // Control arm: two INSERTs inside one timestamp — the CHAR(36) id
        // tiebreak keeps the survivor deterministic.
        $this->seedRow('t1', 'u-tie', '2026-10-05', 'pending', 0, '2026-10-05 08:00:00');
        $this->seedRow('t2', 'u-tie', '2026-10-05', 'pending', 0, '2026-10-05 08:00:00');

        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_125)['errors']);

        $this->assertSame(
            [$this->rowTuple('t1', 'pending', 0)],
            $this->queueRows($db),
            'equal status and equal created_at: the lex-smallest id survives'
        );
    }

    public function testCureLandsTheSchemaRailAndIsRecorded(): void
    {
        $db = $this->requireDb();

        $this->seedRow('m1', 'u-rail', '2026-10-05', 'failed', 3, '2026-10-05 08:00:00');
        $this->seedRow('m2', 'u-rail', '2026-10-05', 'pending', 0, '2026-10-05 09:00:00');

        $result = $this->runMigrationFile(self::MIGRATION_125);
        $this->assertSame([], $result['errors'], 'migration 125 must apply cleanly');

        $indexCols = $db->query(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$this->scratchDb, 'newsletter_queue', 'uk_newsletter_user_week']
        );
        $names = array_map(static fn (array $r): string => (string) $r['COLUMN_NAME'], (array) $indexCols);
        $this->assertSame(
            ['user_id', 'week_start'],
            $names,
            'the UNIQUE gate must cover exactly (user_id, week_start)'
        );

        $oldIndex = $db->query(
            'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->scratchDb, 'newsletter_queue', 'idx_user_week']
        );
        $this->assertSame([], (array) $oldIndex, 'the retired non-UNIQUE pair index must be gone');

        $ledger = $db->query(
            'SELECT name FROM schema_migrations WHERE name LIKE ?',
            ['125_newsletter_queue_user_week_unique%']
        );
        $this->assertCount(1, (array) $ledger, 'the ledger must record THIS file (S114 pattern)');
    }

    public function testReplayIsANoOpOnAlreadyMigratedDatabases(): void
    {
        $db = $this->requireDb();

        $this->seedRow('r1', 'u-replay', '2026-10-05', 'failed', 3, '2026-10-05 08:00:00');
        $this->seedRow('r2', 'u-replay', '2026-10-05', 'sent', 1, '2026-10-05 09:00:00');
        $this->assertSame([], $this->runMigrationFile(self::MIGRATION_125)['errors']);
        $afterFirst = $this->queueRows($db);

        // Second run of the same single-file dir: the runner ledger skips it.
        $second = $this->runMigrationFile(self::MIGRATION_125);
        $this->assertSame([], $second['errors']);
        $this->assertSame([], $second['applied']);
        $this->assertSame(1, $second['skipped_count']);

        // And the amended file's OWN behaviour on an already-migrated DB:
        // strip the ledger row and replay — the survivor DELETE can find no
        // duplicate (the UNIQUE forbids one), and both guarded schema steps
        // short-circuit to SELECT 1. This is the proof that shipping the
        // amended checksum re-harmlessly on every DB that ran the old file.
        $db->query('DELETE FROM schema_migrations WHERE name LIKE ?', ['125_newsletter_queue_user_week_unique%']);
        $third = $this->runMigrationFile(self::MIGRATION_125);
        $this->assertSame([], $third['errors'], 'ledger-stripped replay must no-op via the guards');
        $this->assertSame($afterFirst, $this->queueRows($db), 'replay must not touch the survivor');

        $defs = $db->query(
            'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->scratchDb, 'newsletter_queue', 'uk_newsletter_user_week']
        );
        $this->assertCount(1, (array) $defs, 'replay must not duplicate the UNIQUE definition');
    }

    /**
     * Insert one queue row with an explicit created_at (the timestamp column
     * accepts it) and a deterministic CHAR(36) id built from $key.
     */
    private function seedRow(
        string $key,
        string $userId,
        string $weekStart,
        string $status,
        int $attempts,
        string $createdAt
    ): void {
        $db = $this->requireDb();
        $db->query(
            'INSERT INTO newsletter_queue (id, user_id, week_start, status, attempts, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?)',
            [self::rowId($key), $userId, $weekStart, $status, $attempts, $createdAt]
        );
    }

    /** @return list<array{row_id: string, status: string, attempts: int}> */
    private function queueRows(Connection $db): array
    {
        $rows = (array) $db->query(
            'SELECT id, status, attempts FROM newsletter_queue ORDER BY id'
        );

        return array_map(
            static fn (array $r): array => self::survivorTuple($r),
            $rows
        );
    }

    /**
     * @param array<string, mixed> $r one raw queue row
     *
     * @return array{row_id: string, status: string, attempts: int}
     */
    private static function survivorTuple(array $r): array
    {
        return self::rowTuple(
            (string) $r['id'],
            (string) $r['status'],
            (int) $r['attempts']
        );
    }

    /** @return array{row_id: string, status: string, attempts: int} */
    private static function rowTuple(string $key, string $status, int $attempts): array
    {
        return ['row_id' => self::rowId($key), 'status' => $status, 'attempts' => $attempts];
    }

    /** Pad a short fixture key into the CHAR(36) id space, order-preserving. */
    private static function rowId(string $key): string
    {
        return str_pad($key, 36, '0', STR_PAD_LEFT);
    }

    private function runMigrationFile(string $basename): array
    {
        $rand = bin2hex(random_bytes(4));
        $fixDir = sys_get_temp_dir() . '/phlix-srv-cure-fix-' . $basename . '-' . $rand;
        mkdir($fixDir, 0o755, true);
        $this->fixDirs[] = $fixDir;

        copy($this->migrationPath($basename), $fixDir . '/' . $basename);

        $db = $this->requireDb();
        $runner = new MigrationRunner(static fn (): Connection => $db, $fixDir);

        return $runner->run();
    }

    private function migrationPath(string $basename): string
    {
        return dirname(__DIR__, 3) . '/migrations/' . $basename;
    }

    private function requireDb(): Connection
    {
        $this->assertNotNull($this->db, 'scratch connection missing (setUp did not complete)');

        return $this->db;
    }
}
