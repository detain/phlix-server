<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\UserRepository;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-MySQL proof for the S44 (updates.md #37) FOUNDATIONAL provider-column
 * change in {@see UserRepository::findOrCreateByExternalId()} /
 * {@see UserRepository::findByExternalId()}.
 *
 * S44 made `findOrCreateByExternalId()` take the real `$provider` (first arg),
 * scope the existence SELECT by BOTH `(provider, external_id)` (previously
 * unscoped + a hard-coded `provider='external'`), and INSERT the real provider.
 * S46's `user_identities` backfill and S47's multi-instance logic build on this
 * column being correct, so it needs REAL-MySQL confidence, not just the mock-DB
 * unit test in {@see \Phlix\Tests\Unit\Auth\UserRepositoryExternalIdTest}
 * (mock-DB tests have repeatedly hidden real SQL/WHERE-clause + schema-constraint
 * bugs in this repo — LiveTv RowQuery/ResultSet, metrics ONLY_FULL_GROUP_BY).
 *
 * This test exercises three surfaces against the live `users` table:
 *  1. provider-scoped SELECT isolation (the exact collision the pre-S44 unscoped
 *     SELECT would have gotten wrong) — GREEN proves the S44 fix works.
 *  2. the DB-level `UNIQUE(provider, external_id)` index (migration 009) agrees
 *     with the app-level scoping.
 *  3. the create-branch: a first-time external login persists the REAL provider
 *     (never the old literal 'external'), is idempotent, and keeps two providers
 *     that mint the same opaque id as DISTINCT local users.
 *
 * The suite self-skips when no migrated MySQL is reachable (same guard as
 * {@see NextUpIntegrationTest}); it requires the `users` table with the
 * migration-009 `provider`/`external_id` columns. It only ever mutates rows it
 * created (namespaced by a per-run token) and cleans them up in tearDown, so it
 * is safe against a shared CI `phlix_test` schema.
 *
 * @covers \Phlix\Auth\UserRepository
 */
final class UserRepositoryExternalIdIntegrationTest extends TestCase
{
    private ?Connection $db = null;

    private string $token = '';

    /** @var list<string> external_id values this run created/seeded, for cleanup. */
    private array $externalIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf('No MySQL on %s:%d — skipping external-id integration test. Runs in CI.', $host, $port),
            );
        }

        try {
            ConnectionPool::init(dirname(__DIR__, 3) . '/config/database.php');
            $this->db = ConnectionPool::getConnection('mysql');
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

        $this->assertNotNull($this->db);

        if (!$this->usersProviderSchemaPresent()) {
            $this->markTestSkipped(
                'users.provider/external_id schema (migrations 001 + 009) not present on the reachable DB — skipping.',
            );
        }

        $this->token = substr(Uuid::v4(), 0, 12);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            foreach ($this->externalIds as $ext) {
                // ON DELETE CASCADE removes the user_settings row too.
                $db->query('DELETE FROM users WHERE external_id = ?', [$ext]);
            }
        }
        $this->externalIds = [];
        parent::tearDown();
    }

    /**
     * Scenario 3 (isolated from the create-branch by seeding via raw SQL with a
     * non-null password_hash): two providers that mint the SAME opaque
     * external_id must resolve to their OWN distinct local users. This is the
     * exact collision the pre-S44 unscoped `WHERE external_id = ?` SELECT would
     * have gotten wrong.
     */
    public function testProviderScopedSelectIsolatesProvidersSharingAnExternalId(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $ext = 's44it-shared-' . $this->token;
        $oidcId = $this->seedExternalUser('oidc', $ext);
        $ldapId = $this->seedExternalUser('ldap', $ext);
        $this->assertNotSame($oidcId, $ldapId);

        $repo = new UserRepository($db);

        // findByExternalId resolves each provider to its OWN row (real read-back).
        $oidcRow = $repo->findByExternalId('oidc', $ext);
        $this->assertIsArray($oidcRow);
        $this->assertSame($oidcId, $oidcRow['id']);
        $this->assertSame('oidc', $oidcRow['provider']);

        $ldapRow = $repo->findByExternalId('ldap', $ext);
        $this->assertIsArray($ldapRow);
        $this->assertSame($ldapId, $ldapRow['id']);
        $this->assertSame('ldap', $ldapRow['provider']);

        $this->assertNotSame($oidcRow['id'], $ldapRow['id'], 'cross-provider rows must be distinct');

        // find-branch of findOrCreateByExternalId returns the EXISTING id for the
        // matching provider (no duplicate insert), scoped by (provider, external_id).
        $this->assertSame($oidcId, $repo->findOrCreateByExternalId('oidc', $ext, 'x@example.test', 'X'));
        $this->assertSame($ldapId, $repo->findOrCreateByExternalId('ldap', $ext, 'y@example.test', 'Y'));

        // Exactly one row per (provider, external_id) — no cross-match, no dup.
        $this->assertSame(1, $this->countRows('oidc', $ext));
        $this->assertSame(1, $this->countRows('ldap', $ext));
    }

    /**
     * The DB-level UNIQUE(provider, external_id) index (migration 009) enforces
     * uniqueness in agreement with the app-level scoping: a raw duplicate insert
     * of the same (provider, external_id) pair must fail.
     */
    public function testUniqueProviderExternalIdConstraintIsEnforced(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $ext = 's44it-uniq-' . $this->token;
        $this->seedExternalUser('oidc', $ext);

        $threw = false;
        try {
            $this->seedExternalUser('oidc', $ext); // same (provider, external_id)
        } catch (Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'a duplicate (provider, external_id) insert must violate the UNIQUE index');
        $this->assertSame(1, $this->countRows('oidc', $ext), 'the duplicate must not have persisted');
    }

    /**
     * The create-branch — the first-time external-login path S44 wires live.
     * Scenario 1: a new external identity persists the REAL provider ('oidc'),
     * never the old literal 'external'. Scenario 2: a second call is idempotent
     * (same id, still one row). Scenario 3: the same external_id under a
     * DIFFERENT provider ('ldap') creates a DISTINCT user, and each resolves to
     * its own row.
     */
    public function testFindOrCreatePersistsRealProviderIdempotentAndCrossProviderDistinct(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $repo = new UserRepository($db);
        $ext = 's44it-create-' . $this->token;
        $oidcEmail = 's44it-oidc-' . $this->token . '@example.test';
        $ldapEmail = 's44it-ldap-' . $this->token . '@example.test';
        $this->externalIds[] = $ext;

        // Scenario 1: create writes the REAL provider (read back with a real SELECT).
        $oidcId = $repo->findOrCreateByExternalId('oidc', $ext, $oidcEmail, 'OIDC User');
        $this->assertNotSame('', $oidcId);
        $this->assertSame(
            'oidc',
            $this->readColumn($oidcId, 'provider'),
            'persisted provider must be the real "oidc", NOT "external"',
        );
        $this->assertSame(1, $this->countRows('oidc', $ext));

        // Scenario 2: idempotent — same id, still exactly one row.
        $again = $repo->findOrCreateByExternalId('oidc', $ext, $oidcEmail, 'OIDC User');
        $this->assertSame($oidcId, $again);
        $this->assertSame(1, $this->countRows('oidc', $ext));

        // Scenario 3: same external_id, DIFFERENT provider → DISTINCT user.
        $ldapId = $repo->findOrCreateByExternalId('ldap', $ext, $ldapEmail, 'LDAP User');
        $this->assertNotSame(
            $oidcId,
            $ldapId,
            'a different provider with the same external_id must be a distinct local user',
        );
        $this->assertSame('ldap', $this->readColumn($ldapId, 'provider'));

        // Both coexist and each findByExternalId resolves to its own row.
        $this->assertSame($oidcId, ($repo->findByExternalId('oidc', $ext) ?? [])['id'] ?? null);
        $this->assertSame($ldapId, ($repo->findByExternalId('ldap', $ext) ?? [])['id'] ?? null);
        $this->assertSame(1, $this->countRows('oidc', $ext));
        $this->assertSame(1, $this->countRows('ldap', $ext));
    }

    /**
     * Scenario 4 (commit a3dd3cd2): the email-less username-fallback collision.
     *
     * `users.username` is NOT NULL + UNIQUE. The pre-a3dd3cd2 fallback
     * `'user_' . substr($externalId, 0, 16)` collides whenever two email-less
     * external identities share the first 16 chars of their external_id — the
     * realistic LDAP case where external_id is `ldap.` + a DN. Two such users
     * must now create as DISTINCT rows with DISTINCT usernames (the collision-
     * safe `'user_' . substr(sha256(provider\0externalId), 0, 24)` fallback),
     * proven by real SELECT read-backs of the persisted `username` column.
     */
    public function testEmaillessExternalUsersSharingExternalIdPrefixGetDistinctUsernames(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $repo = new UserRepository($db);

        // Both DNs share the first 16 chars ('ldap.uid=john.sm'), differ after,
        // and carry NO email — so the username MUST come from the fallback path.
        $ext1 = 'ldap.uid=john.smith,ou=people,' . $this->token;
        $ext2 = 'ldap.uid=john.smart,ou=people,' . $this->token;
        $this->assertSame(
            substr($ext1, 0, 16),
            substr($ext2, 0, 16),
            'precondition: the two external_ids must collide on the old 16-char username fallback',
        );
        $this->externalIds[] = $ext1;
        $this->externalIds[] = $ext2;

        $id1 = $repo->findOrCreateByExternalId('ldap', $ext1, null, null);
        $id2 = $repo->findOrCreateByExternalId('ldap', $ext2, null, null);

        // Distinct users, both persisted.
        $this->assertNotSame($id1, $id2, 'two distinct email-less identities must be two distinct users');
        $this->assertSame(1, $this->countRows('ldap', $ext1));
        $this->assertSame(1, $this->countRows('ldap', $ext2));

        // Distinct, non-empty usernames read back from the DB.
        $u1 = $this->readColumn($id1, 'username');
        $u2 = $this->readColumn($id2, 'username');
        $this->assertNotSame('', (string) $u1);
        $this->assertNotSame('', (string) $u2);
        $this->assertNotSame($u1, $u2, 'the two email-less users must get DISTINCT usernames (no UNIQUE collision)');

        // Email-less → the emails are also distinct per-identity placeholders.
        $this->assertNotSame(
            $this->readColumn($id1, 'email'),
            $this->readColumn($id2, 'email'),
            'email-less users must get distinct placeholder emails',
        );
    }

    /**
     * Seed a users row directly (raw SQL, non-null password_hash) so the
     * provider-scoping SELECT can be tested independently of the create-branch.
     */
    private function seedExternalUser(string $provider, string $externalId): string
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $id = Uuid::v4();
        $suffix = substr($id, 0, 8);
        $db->query(
            'INSERT INTO users (id, username, email, password_hash, provider, external_id) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $id,
                $provider . '_' . $suffix,
                $provider . '_' . $suffix . '@example.test',
                'x',
                $provider,
                $externalId,
            ],
        );
        if (!in_array($externalId, $this->externalIds, true)) {
            $this->externalIds[] = $externalId;
        }

        return $id;
    }

    /**
     * Read a single column value for a user id back from the DB (real SELECT).
     * The column name is a hard-coded test literal, never request input.
     */
    private function readColumn(string $userId, string $column): ?string
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $rows = $db->query("SELECT `$column` FROM users WHERE id = ?", [$userId]);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }
        $value = $rows[0][$column] ?? null;

        return is_string($value) ? $value : null;
    }

    private function countRows(string $provider, string $externalId): int
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $rows = $db->query(
            'SELECT id FROM users WHERE provider = ? AND external_id = ?',
            [$provider, $externalId],
        );

        return is_array($rows) ? count($rows) : 0;
    }

    private function usersProviderSchemaPresent(): bool
    {
        $db = $this->db;
        if ($db === null) {
            return false;
        }
        try {
            $cols = $db->query("SHOW COLUMNS FROM users LIKE 'provider'");
        } catch (Throwable $e) {
            return false;
        }

        return is_array($cols) && isset($cols[0]);
    }

    private function isMysqlReachable(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }
}
