<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Plugins;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use Phlix\Plugins\OAuth2\DbOAuth2StateStore;
use Phlix\Plugins\OAuth2\Pkce;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * REAL-MySQL proof for S48's {@see DbOAuth2StateStore} — the browser-bound OAuth2
 * state store that replaces `$_SESSION` (which is NOT request-scoped under
 * Workerman; see CLAUDE.md "Workerman Session Model").
 *
 * The unit test for this class drives a mocked `Workerman\MySQL\Connection`, which
 * means the three things that can actually break in production are unexercised:
 *
 *   - the `beginTrans()` / `SELECT … FOR UPDATE` / `DELETE` / `commitTrans()`
 *     sequence that makes {@see DbOAuth2StateStore::consume()} genuinely one-shot
 *     (a mock cannot fail to lock a row, so the replay guarantee is asserted
 *     against nothing);
 *   - the BOUND `LIMIT ?` in the expiry sweep — a real workerman/mysql trap in
 *     this repo (`query()` with positional placeholders in a `LIMIT` clause) that
 *     a stubbed `query()` always "passes";
 *   - `FROM_UNIXTIME(?)` vs the `expires_at TIMESTAMP` column and `expires_at >
 *     NOW()` comparisons — i.e. whether a stored TTL is really honoured.
 *
 * Covered here against a live schema: PKCE verifier round-trip, one-shot consume
 * (a second consume MUST fail), per-provider scoping on the
 * `uk_provider_state` unique key, context payload fidelity (including the
 * `callback_url` S48 binds into it and non-ASCII), TTL expiry, and the sweep.
 *
 * Self-skips when no MySQL is reachable; runs for real in CI (`phpunit.yml`
 * provisions `mysql:8.0` and applies every migration first). Only rows tagged
 * with this run's provider token are written, and they are deleted in tearDown.
 *
 * @covers \Phlix\Plugins\OAuth2\DbOAuth2StateStore
 */
final class DbOAuth2StateStoreRealDbIntegrationTest extends TestCase
{
    private ?Connection $db = null;

    /** Per-run `oauth_state_store.provider` tag, so rows can never collide. */
    private string $provider = '';

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf('No MySQL on %s:%d — skipping S48 OAuth2 state-store real-DB test. Runs in CI.', $host, $port),
            );
        }

        try {
            ConnectionPool::init(dirname(__DIR__, 3) . '/config/database.php');
            $this->db = ConnectionPool::getConnection('mysql');
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

        $this->assertNotNull($this->db);
        // `provider` is VARCHAR(50); keep the tag comfortably inside it.
        $this->provider = 'itest-gh-' . substr(Uuid::v4(), 0, 8);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null && $this->provider !== '') {
            $db->query('DELETE FROM oauth_state_store WHERE provider = ?', [$this->provider]);
        }
        parent::tearDown();
    }

    /**
     * The happy path end-to-end: a PKCE verifier plus the server-side context S48
     * really stores (`callback_url` + the correlation fingerprint) survives the DB
     * round-trip, and the replayed verifier still recomputes the SAME S256
     * challenge that was sent to the provider — which is the whole point of
     * persisting it.
     */
    public function testPkceVerifierAndContextRoundTripThroughRealMysql(): void
    {
        $store = new DbOAuth2StateStore($this->conn(), $this->provider);

        $verifier = Pkce::generateCodeVerifier();
        $challenge = Pkce::computeCodeChallenge($verifier);
        $state = bin2hex(random_bytes(16));
        $context = [
            'callback_url' => 'https://intertainer.phlix.interserver.net/auth/github/callback',
            'correlation' => hash('sha256', 'secret-cookie-value'),
            'link_user_id' => 'user-42',
            'display_name' => 'Ünicode 日本語 🎬',
            'nested' => ['a' => ['b' => true]],
        ];

        $store->put($state, $verifier, $context);
        $this->assertSame(1, $this->rowCount($state), 'the state row must really be in the table');

        $entry = $store->consume($state);

        $this->assertIsArray($entry);
        $this->assertSame($verifier, $entry['code_verifier'] ?? null);
        $this->assertSame(
            $challenge,
            Pkce::computeCodeChallenge((string) ($entry['code_verifier'] ?? '')),
            'the replayed verifier must still match the challenge sent at authorize time',
        );
        $this->assertSame(
            'https://intertainer.phlix.interserver.net/auth/github/callback',
            $entry['context']['callback_url'] ?? null,
            'the authorize-time callback_url must survive for the token exchange',
        );
        $this->assertSame('Ünicode 日本語 🎬', $entry['context']['display_name'] ?? null);
        $this->assertSame(['a' => ['b' => true]], $entry['context']['nested'] ?? null);
    }

    /**
     * ONE-SHOT semantics against a real transaction: the row is gone after the
     * first consume, and a second consume of the same state returns null. This is
     * the anti-replay guarantee the whole callback leg rests on — a stolen
     * `code`+`state` pair must be usable at most once.
     */
    public function testConsumeIsOneShotAndDeletesTheRow(): void
    {
        $store = new DbOAuth2StateStore($this->conn(), $this->provider);
        $state = bin2hex(random_bytes(16));
        $store->put($state, 'verifier-one-shot');

        $first = $store->consume($state);
        $this->assertIsArray($first, 'the first consume must succeed');
        $this->assertSame('verifier-one-shot', $first['code_verifier'] ?? null);

        $this->assertSame(0, $this->rowCount($state), 'consume must DELETE the row inside its transaction');
        $this->assertNull($store->consume($state), 'a second consume of the same state must FAIL (replay)');
        $this->assertNull($store->consume($state), 'and keep failing');
    }

    /**
     * An unknown state is simply null — no exception, no 500 on the callback leg.
     */
    public function testConsumingAnUnknownStateReturnsNull(): void
    {
        $store = new DbOAuth2StateStore($this->conn(), $this->provider);

        $this->assertNull($store->consume('never-issued-' . bin2hex(random_bytes(8))));
    }

    /**
     * The `provider` column really scopes the store: the SAME state value may
     * exist for two provider families (that is what `uk_provider_state` allows),
     * and consuming it as one provider must NOT consume the other's row. Without
     * this, adding GitHub alongside OIDC would let either flow burn the other's
     * state.
     */
    public function testStateIsScopedToItsProviderFamily(): void
    {
        $other = $this->provider . '-b';
        $github = new DbOAuth2StateStore($this->conn(), $this->provider);
        $oidcish = new DbOAuth2StateStore($this->conn(), $other);
        $state = bin2hex(random_bytes(16));

        try {
            $github->put($state, 'verifier-github');
            $oidcish->put($state, 'verifier-other');

            $consumed = $github->consume($state);
            $this->assertSame('verifier-github', $consumed['code_verifier'] ?? null);

            // The other family's row is untouched and still consumable.
            $still = $oidcish->consume($state);
            $this->assertSame(
                'verifier-other',
                $still['code_verifier'] ?? null,
                'consuming one provider family must not burn another family\'s identical state value',
            );
        } finally {
            $this->conn()->query('DELETE FROM oauth_state_store WHERE provider = ?', [$other]);
        }
    }

    /**
     * TTL is enforced by the DB, not by PHP: a row whose `expires_at` has passed is
     * NOT consumable (`expires_at > NOW()` in the SELECT), even though it is still
     * physically present at that moment.
     */
    public function testAnExpiredStateIsNotConsumable(): void
    {
        // TTL of 1s, then push expires_at into the past directly so the test does
        // not sleep (no blocking sleep in a resident-memory codebase's suite).
        $store = new DbOAuth2StateStore($this->conn(), $this->provider, 1);
        $state = bin2hex(random_bytes(16));
        $store->put($state, 'verifier-expired');
        $this->conn()->query(
            'UPDATE oauth_state_store SET expires_at = FROM_UNIXTIME(?) WHERE provider = ? AND state_value = ?',
            [time() - 3600, $this->provider, $state],
        );

        $this->assertSame(1, $this->rowCount($state), 'precondition: the row is still physically present');
        $this->assertNull($store->consume($state), 'an expired state must not be consumable');
    }

    /**
     * The SWEEP path. `consume()` runs `DELETE FROM oauth_state_store WHERE
     * expires_at <= NOW() LIMIT ?` with a BOUND limit — exactly the shape that has
     * 500'd under workerman/mysql in this repo before. Proven here by seeding an
     * expired row, consuming an unrelated state, and asserting the expired row is
     * gone (and that a live row is NOT).
     */
    public function testConsumeSweepsExpiredRowsWithABoundLimit(): void
    {
        $store = new DbOAuth2StateStore($this->conn(), $this->provider);

        $stale = bin2hex(random_bytes(16));
        $live = bin2hex(random_bytes(16));
        $trigger = bin2hex(random_bytes(16));
        $store->put($stale, 'verifier-stale');
        $store->put($live, 'verifier-live');
        $store->put($trigger, 'verifier-trigger');
        $this->conn()->query(
            'UPDATE oauth_state_store SET expires_at = FROM_UNIXTIME(?) WHERE provider = ? AND state_value = ?',
            [time() - 7200, $this->provider, $stale],
        );

        // Any consume triggers cleanupExpiredEntries().
        $this->assertIsArray($store->consume($trigger));

        $this->assertSame(0, $this->rowCount($stale), 'the expired row must have been swept');
        $this->assertSame(1, $this->rowCount($live), 'a live row must survive the sweep');
    }

    /**
     * `put()` with no context stores only the verifier — `consume()` must then omit
     * the `context` key entirely rather than returning a null/empty one, because
     * the callback controllers branch on `isset($entry['context'])`.
     */
    public function testPutWithoutContextOmitsTheContextKey(): void
    {
        $store = new DbOAuth2StateStore($this->conn(), $this->provider);
        $state = bin2hex(random_bytes(16));

        $store->put($state, 'verifier-bare');
        $entry = $store->consume($state);

        $this->assertIsArray($entry);
        $this->assertSame('verifier-bare', $entry['code_verifier'] ?? null);
        $this->assertArrayNotHasKey('context', $entry);
    }

    /**
     * Re-using a state value within one provider family hits the
     * `uk_provider_state` UNIQUE key. The store must not silently swallow it into a
     * state that cannot be consumed — it either throws or leaves exactly one usable
     * row. Asserted as "at most one row, and it is consumable", which is the
     * property the flow actually needs.
     */
    public function testDuplicateStateValueDoesNotProduceAnUnusableRow(): void
    {
        $store = new DbOAuth2StateStore($this->conn(), $this->provider);
        $state = bin2hex(random_bytes(16));
        $store->put($state, 'verifier-first');

        try {
            $store->put($state, 'verifier-second');
        } catch (Throwable) {
            // A unique-key violation surfacing as an exception is acceptable: the
            // authorize leg generates a fresh 16-byte state per request, so this is
            // an impossible-in-practice collision, and failing loudly beats a
            // silently unusable row.
        }

        $this->assertLessThanOrEqual(1, $this->rowCount($state), 'the unique key must prevent a duplicate row');
        $entry = $store->consume($state);
        $this->assertIsArray($entry, 'whatever survived must still be consumable');
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    private function conn(): Connection
    {
        $db = $this->db;
        if ($db === null) {
            $this->fail('No database connection');
        }

        return $db;
    }

    private function rowCount(string $state): int
    {
        $rows = $this->conn()->query(
            'SELECT COUNT(*) AS c FROM oauth_state_store WHERE provider = ? AND state_value = ?',
            [$this->provider, $state],
        );

        return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? (int) ($rows[0]['c'] ?? 0) : 0;
    }

    private function isMysqlReachable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
