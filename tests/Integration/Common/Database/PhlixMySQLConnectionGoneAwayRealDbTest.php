<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Common\Database;

use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Tests\Support\Database\UsesScratchDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * TXMUTEX-A r2 (reviewer MINOR-1, design §5-T1): the A1 gone-away arm proven
 * against a REAL mysqld — the 2006/2013 arrives through the live driver, the
 * transaction is genuinely open on the server side, and the session is torn
 * down by an out-of-band KILL, exactly the production loss mode.
 *
 * The hermetic unit pins (PhlixMySQLConnectionTest, `goneAwayHandle`) fabricate
 * the errorInfo; this file proves the same refusal through bytes the server
 * actually sent:
 *  1. the in-transaction statement is NOT retried — the throw carries the
 *     "(connection lost inside a transaction; not retried)" suffix and the
 *     poison flag is set (the parent code reconnects and lands the INSERT
 *     autocommit: the exact silent-loss failure mode §1.1 documents);
 *  2. commitTrans() on the poisoned connection refuses with the hub-verbatim
 *     wording and consumes the poison;
 *  3. the killed session's uncommitted work is gone (server-side rollback) and
 *     the retried statement never leaked a row either way;
 *  4. recovery: beginTrans clears the poison, a fresh transaction commits, and
 *     the new session's write is visible — the connection self-heals after the
 *     refusal, it is not quarantined.
 *
 * @see \Phlix\Tests\Integration\Admin\AdminSettingsAuthLockB2Test for the
 *      two-live-connections venue idiom this file clones.
 */
final class PhlixMySQLConnectionGoneAwayRealDbTest extends TestCase
{
    use UsesScratchDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->openScratchDatabase(
            'Runs against a real MySQL server (the TXMUTEX-A r2 KILL venue).',
            'txmgr'
        );
        $this->db->query('CREATE TABLE gone_t (v VARCHAR(64) NOT NULL) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        $this->cleanupScratchDatabase();
        parent::tearDown();
    }

    public function testAGoneAwayInsideAnOpenTransactionIsNotRetriedByTheLiveDriver(): void
    {
        $serverThread = (int)$this->db->row('SELECT CONNECTION_ID() AS id')['id'];

        self::assertTrue($this->db->beginTrans(), 'a real server transaction must open');
        $this->db->query("INSERT INTO gone_t VALUES ('pre-kill')");

        $killer = $this->newScratchConnection();
        $killer->query('KILL ' . $serverThread);

        try {
            $this->db->query("INSERT INTO gone_t VALUES ('post-kill')");
            self::fail(
                'the live driver must refuse the in-transaction statement — '
                . 'without the arm the statement is retried onto a fresh autocommit session'
            );
        } catch (\PDOException $caught) {
            self::assertStringEndsWith(
                '(connection lost inside a transaction; not retried)',
                $caught->getMessage(),
                'the refusal must carry the hub-verbatim in-transaction wording on real driver bytes'
            );
        }

        self::assertTrue($this->poisoned(), 'the lost connection must poison the lease');

        try {
            $this->db->commitTrans();
            self::fail('committing a poisoned transaction must be refused');
        } catch (\PDOException $refusal) {
            self::assertSame(
                'Transaction was lost when the connection dropped; refusing to commit.',
                $refusal->getMessage(),
                'the stale-commit block must carry the hub-verbatim refusal'
            );
        }

        self::assertFalse($this->poisoned(), 'the refusal consumes the poison one-shot');

        $probe = $this->newScratchConnection();
        self::assertSame(
            [],
            (array)$probe->query('SELECT v FROM gone_t'),
            'nothing may survive: the KILL rolled the session transaction back and the '
            . 'refused statement was never retried'
        );

        self::assertTrue($this->db->beginTrans(), 'a fresh begin must self-heal and clear the poison');
        $this->db->query("INSERT INTO gone_t VALUES ('recovered')");
        self::assertTrue($this->db->commitTrans(), 'the recovered session must commit normally');
        self::assertFalse($this->poisoned());

        $probe2 = $this->newScratchConnection();
        self::assertSame(
            [['v' => 'recovered']],
            (array)$probe2->query('SELECT v FROM gone_t'),
            'the healed write lands alone — the pre-kill and post-kill attempts left nothing behind'
        );
    }

    private function poisoned(): bool
    {
        // TXMUTEX-B retrofit: the mark lives in the shared machine's ledger
        // now (the retired `txPoisoned` bool is gone from the class).
        $state = (new ReflectionProperty(PhlixMySQLConnection::class, 'txState'))->getValue($this->db);

        return $state instanceof \Phlix\Shared\Database\MysqlTxLockState && $state->isPoisoned();
    }
}
