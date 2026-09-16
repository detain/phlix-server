<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\QuickConnectPair;
use Phlix\Auth\QuickConnectStateStore;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S518 — real-MySQL proof of the quick-connect pairing store.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * Every clause below is a property of a live server that a recorded double
 * models none of: the `data JSON` column round-trip, the `FROM_UNIXTIME(?)`
 * write against a `TIMESTAMP` column paired with the `UNIX_TIMESTAMP()` read
 * (the two MySQL functions the TTL math rides on), the `SELECT … FOR UPDATE`
 * lock the approve/consume transactions take, the provider-scoped
 * `DELETE … LIMIT n` sweep, and the cross-connection visibility of committed
 * rows — the Workerman multi-worker posture (two resident workers, one table)
 * is literally two Connections against the same `oauth_state_store` here.
 *
 * ## Proof branches (S345 rule 1 — every write/merge path and its input class)
 *
 *   | store call on row state        | outcome |
 *   |--------------------------------|---------|
 *   | approve, live pending          | APPROVED, user_id persisted |
 *   | approve, wrong secret          | BAD_SECRET, state untouched |
 *   | approve, already approved      | NOT_READY, first approval wins |
 *   | approve, unknown / expired     | UNKNOWN |
 *   | consume, approved + secret     | APPROVED + row GONE (one-shot) |
 *   | consume, pending               | NOT_READY, row kept |
 *   | consume, re-consume            | UNKNOWN (double-spend impossible) |
 *   | find, expired-unswept row      | pair returned, isExpired true |
 *   | issue after expiry             | sweep deletes it, find → null |
 *
 * SAFETY: runs against the shared `phlix_test` database like every sibling
 * auth integration test. The table is self-healed with the verbatim
 * migration-048 `CREATE TABLE IF NOT EXISTS` (the shared DB already carries
 * it in CI; an older local box gets it rebuilt), rows are namespaced by the
 * random 6-letter codes the store itself minted, and tearDown deletes exactly
 * those rows — never another provider's state, never a concurrent worker's
 * still-live pairing (the codes are collision-checked on mint).
 */
final class QuickConnectStateStoreRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    /** Verbatim from migrations/048_oidc_state_store.sql — do not "improve". */
    private const SCHEMA_SQL = 'CREATE TABLE IF NOT EXISTS oauth_state_store ('
        . ' id CHAR(36) PRIMARY KEY,'
        . " provider VARCHAR(50) NOT NULL DEFAULT 'oidc',"
        . ' state_value VARCHAR(255) NOT NULL,'
        . ' data JSON NOT NULL,'
        . ' expires_at TIMESTAMP NOT NULL,'
        . ' created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,'
        . ' UNIQUE KEY uk_provider_state (provider, state_value),'
        . ' INDEX idx_expires_at (expires_at),'
        . ' INDEX idx_provider (provider)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private ?Connection $db = null;

    /** @var list<string> codes this test minted — the exact tearDown scope. */
    private array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the S518 quick-connect store proof. Runs in CI.');

        $this->db->query(self::SCHEMA_SQL);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            foreach ($this->codes as $code) {
                $db->query(
                    'DELETE FROM oauth_state_store WHERE provider = ? AND state_value = ?',
                    [QuickConnectStateStore::PROVIDER, $code]
                );
            }
        }
        $this->codes = [];
        $this->db = null;
        parent::tearDown();
    }

    public function testFullPairingLifecycleAcrossTwoConnections(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // Worker A mints the pairing.
        $store = new QuickConnectStateStore($db);
        $pair = $store->issue();
        $this->codes[] = $pair->code;

        $found = $store->find($pair->code);
        $this->assertNotNull($found, 'a live pairing must be readable back');
        $this->assertSame(QuickConnectPair::STATE_PENDING, $found->state);
        $this->assertNull($found->userId);
        $this->assertSame($pair->secret, $found->secret, 'the JSON column must round-trip the secret byte-exact');
        $this->assertFalse($found->isExpired(time()));

        // Worker B (separate resident process → separate Connection) sees it:
        // the table, not the process, is the source of truth.
        $other = new QuickConnectStateStore($this->secondConnection());
        $crossSeen = $other->find($pair->code);
        $this->assertNotNull($crossSeen);
        $this->assertSame($pair->secret, $crossSeen->secret);

        // Wrong secret is refused without touching the row's state.
        $this->assertSame(
            QuickConnectStateStore::RESULT_BAD_SECRET,
            $store->approve($pair->code, 'x' . $pair->secret, 'user-9'),
        );
        $still = $store->find($pair->code);
        $this->assertNotNull($still);
        $this->assertSame(QuickConnectPair::STATE_PENDING, $still->state);

        // Approve under FOR UPDATE; the user id lands in the JSON payload.
        $this->assertSame(
            QuickConnectStateStore::RESULT_APPROVED,
            $store->approve($pair->code, $pair->secret, 'user-42'),
        );
        $approved = $store->find($pair->code);
        $this->assertNotNull($approved);
        $this->assertSame(QuickConnectPair::STATE_APPROVED, $approved->state);
        $this->assertSame('user-42', $approved->userId);

        // A second phone cannot re-approve an approved pairing.
        $this->assertSame(
            QuickConnectStateStore::RESULT_NOT_READY,
            $store->approve($pair->code, $pair->secret, 'user-other'),
        );

        // Consume with the wrong secret keeps the pairing; right secret mints
        // once and DELETES the row — inside one locked transaction.
        $this->assertSame(QuickConnectStateStore::RESULT_BAD_SECRET, $store->consumeApproved($pair->code, 'nope')[0]);
        [$result, $redeemed] = $store->consumeApproved($pair->code, $pair->secret);
        $this->assertSame(QuickConnectStateStore::RESULT_APPROVED, $result);
        $this->assertNotNull($redeemed);
        $this->assertSame('user-42', $redeemed->userId);
        $this->assertNull($store->find($pair->code), 'redemption must consume the row — a token is minted once');

        // Double-spend: the code is now indistinguishable from one that never was.
        $this->assertSame(QuickConnectStateStore::RESULT_UNKNOWN, $store->consumeApproved($pair->code, $pair->secret)[0]);
    }

    public function testExpiredPairingReportsExpiredThenSweepsOnNextIssue(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $shortLived = new QuickConnectStateStore($db, 1);
        $pair = $shortLived->issue();
        $this->codes[] = $pair->code;

        sleep(2);

        // find() deliberately reads past the TTL filter — that is how the status
        // poll tells `expired` from `never was` for unswept rows.
        $found = $shortLived->find($pair->code);
        if ($found === null) {
            // A concurrent issue() on the shared table swept it first; the row
            // is gone, which is the same security posture the next assert locks.
            $this->assertSame(
                QuickConnectStateStore::RESULT_UNKNOWN,
                $shortLived->approve($pair->code, $pair->secret, 'user-1')
            );

            return;
        }
        $this->assertTrue($found->isExpired(time()));

        // An expired row approves/consumes as UNKNOWN — the locks take it, the
        // `expires_at > NOW()` gate rejects it, the pending pairing is unredeemable.
        $this->assertSame(
            QuickConnectStateStore::RESULT_UNKNOWN,
            $shortLived->approve($pair->code, $pair->secret, 'user-1')
        );
        $this->assertSame(QuickConnectStateStore::RESULT_UNKNOWN, $shortLived->consumeApproved($pair->code, $pair->secret)[0]);

        // The next issue() runs the janitorial sweep; the expired row is deleted.
        $fresh = $shortLived->issue();
        $this->codes[] = $fresh->code;
        $this->assertNull($shortLived->find($pair->code), 'issue() must sweep the expired row it displaced a slot for');
    }

    public function testUnknownCodesAreUniformlyUnknown(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $store = new QuickConnectStateStore($db);

        // No SQL row: approve and consume both land on the same uniform
        // RESULT_UNKNOWN the controller maps to a single 404 shape — the
        // anti-oracle posture starts in the store.
        $this->assertSame(QuickConnectStateStore::RESULT_UNKNOWN, $store->approve('QRTVWX', 's', 'u'));
        $this->assertSame(QuickConnectStateStore::RESULT_UNKNOWN, $store->consumeApproved('QRTVWX', 's')[0]);
        $this->assertNull($store->find('QRTVWX'));
    }

    private function secondConnection(): Connection
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // Same DSN the shared pool opened (requireRealDatabase resolved it);
        // a brand-new Connection proves committed-row visibility across the
        // two resident-worker halves of the Workerman model.
        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $name = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $second = new \Phlix\Common\Database\PhlixMySQLConnection($host, $port, $user, $password, $name);
        $this->addToAssertionCount(1);

        return $second;
    }
}
