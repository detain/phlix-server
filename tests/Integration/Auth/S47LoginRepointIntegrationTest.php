<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Common\Uuid;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-MySQL proof for the S47 (updates.md #54) LOGIN-READ REPOINT in
 * {@see UserRepository::findOrCreateByExternalId()}.
 *
 * S47 makes external-identity login resolve the owning user via the
 * `user_identities` join table (migration 092) FIRST, with a `users`-table
 * fallback, so an identity LINKED to an existing account (S45) becomes usable
 * for login instead of wrongly creating a duplicate account. Because this is the
 * riskiest change in the step, it gets REAL-MySQL coverage of the three
 * behaviours the plan calls out — mock-DB tests have repeatedly hidden real
 * SQL/schema bugs in this repo:
 *
 *   (i)   a PRE-EXISTING external user (backfilled identity, like S46) still logs
 *         in — resolves to the same account, no duplicate;
 *   (ii)  an identity LINKED via S45 to an existing account (a user_identities
 *         row with NO matching users.provider/external_id row) resolves that SAME
 *         account on login — NOT a new user (the headline repoint);
 *   (iii) a BRAND-NEW external login still creates + dual-writes, and a second
 *         login resolves the same account.
 *
 * The suite self-skips when no MySQL is reachable (same guard as the sibling
 * integration tests) and builds a self-standing scratch schema (users +
 * user_settings + the VERBATIM migration-092 `user_identities` CREATE) with
 * `IF NOT EXISTS`, so it is a no-op on a fully-migrated DB and safe on a bare
 * scratch database. It only mutates rows it created (namespaced by a per-run
 * token) and cleans them up in tearDown (users delete CASCADEs identities).
 *
 * @covers \Phlix\Auth\UserRepository
 */
final class S47LoginRepointIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $token = '';

    /** @var list<string> user ids this run created, deleted (CASCADE) in tearDown. */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S47 login-repoint integration test. Runs in CI.');

        $this->assertNotNull($this->db);

        $this->buildSchema();
        $this->token = substr(Uuid::v4(), 0, 12);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            foreach ($this->userIds as $id) {
                // ON DELETE CASCADE removes user_settings + user_identities.
                $db->query('DELETE FROM users WHERE id = ?', [$id]);
            }
        }
        $this->userIds = [];
        parent::tearDown();
    }

    /**
     * (i) A pre-existing external user whose identity row was backfilled (S46)
     * still logs in — findOrCreateByExternalId resolves to the SAME account and
     * creates no duplicate.
     */
    public function testPreExistingBackfilledExternalUserResolves(): void
    {
        $repo = new UserRepository($this->conn());
        $ext = 'oidc.pre-' . $this->token;

        // Seed a users row (as an external login would have left it) AND its
        // backfilled user_identities row (as migration 092 would have created).
        $userId = $this->seedExternalUser('oidc', $ext);
        (new UserIdentityRepository($this->conn()))->create($userId, 'oidc', '', $ext, null);

        $resolved = $repo->findOrCreateByExternalId('oidc', $ext, 'pre@example.test', 'Pre');

        $this->assertSame($userId, $resolved, 'a backfilled pre-existing external user must resolve to its account');
        $this->assertSame(1, $this->countUsers('oidc', $ext), 'no duplicate users row may be created');
    }

    /**
     * (ii) THE HEADLINE: an identity LINKED via S45 to an existing local account
     * (a user_identities row that has NO matching users.provider/external_id row,
     * because linking never mutates `users`) resolves that SAME account on login —
     * NOT a brand-new user. Pre-S47 this would have created a duplicate.
     */
    public function testS45LinkedIdentityResolvesExistingAccountNotADuplicate(): void
    {
        $repo = new UserRepository($this->conn());
        $ext = 'oidc.linked-' . $this->token;

        // A LOCAL account (provider/external_id NULL — a password user).
        $localId = $this->seedLocalUser();
        // S45 link: a user_identities row pointing at that local account, with NO
        // users.provider/external_id set.
        (new UserIdentityRepository($this->conn()))->create($localId, 'oidc', '', $ext, ['email' => 'me@example.test']);

        $beforeCount = $this->totalUsers();

        $resolved = $repo->findOrCreateByExternalId('oidc', $ext, 'me@example.test', 'Me');

        $this->assertSame($localId, $resolved, 'a linked identity must resolve its OWNER, never a new account');
        $this->assertSame($beforeCount, $this->totalUsers(), 'no new users row may be created for a linked identity');
        // The linking user still has NO provider/external_id on its users row.
        $this->assertNull($this->readColumn($localId, 'provider'));
        $this->assertNull($this->readColumn($localId, 'external_id'));
    }

    /**
     * (iii) A brand-new external login still creates + dual-writes (users +
     * user_identities), and a SECOND login resolves the same account (now via the
     * identity row).
     */
    public function testBrandNewExternalLoginCreatesThenResolves(): void
    {
        $repo = new UserRepository($this->conn());
        $ext = 'oidc.new-' . $this->token;

        $created = $repo->findOrCreateByExternalId('oidc', $ext, 'new@example.test', 'New');
        $this->assertNotSame('', $created);
        $this->userIds[] = $created;

        // Dual-write: both the users row and the user_identities row exist.
        $this->assertSame('oidc', $this->readColumn($created, 'provider'));
        $this->assertSame($ext, $this->readColumn($created, 'external_id'));
        $this->assertSame(1, $this->countIdentities('oidc', '', $ext), 'a user_identities row must be dual-written');

        // A second login resolves the SAME account (via the identity-first read).
        $again = $repo->findOrCreateByExternalId('oidc', $ext, 'new@example.test', 'New');
        $this->assertSame($created, $again);
        $this->assertSame(1, $this->countUsers('oidc', $ext), 'the second login must not create a duplicate');
    }

    /**
     * (iv) USERS-FALLBACK resolution: a `users` row that exists but has NO
     * corresponding `user_identities` row (an un-backfilled / legacy row — as if
     * migration 092's backfill somehow missed it) still resolves via the
     * belt-and-suspenders `users`-table fallback. The identity-first read MISSES
     * (no identity row), so this proves the fallback keeps that user's login
     * working and never creates a duplicate — the "no existing user loses login"
     * guarantee for a row the backfill didn't cover.
     */
    public function testUnbackfilledUsersRowResolvesViaUsersFallback(): void
    {
        $repo = new UserRepository($this->conn());
        $ext = 'oidc.fallback-' . $this->token;

        // A pre-existing external user on the AUTHORITATIVE users columns, with NO
        // user_identities row at all.
        $userId = $this->seedExternalUser('oidc', $ext);
        $this->assertSame(
            0,
            $this->countIdentities('oidc', '', $ext),
            'precondition: no identity row exists — the identity-first read must miss',
        );

        $beforeCount = $this->totalUsers();
        $resolved = $repo->findOrCreateByExternalId('oidc', $ext, 'fallback@example.test', 'Fallback');

        $this->assertSame(
            $userId,
            $resolved,
            'an un-backfilled users row must resolve via the users fallback, never create a duplicate',
        );
        $this->assertSame(
            $beforeCount,
            $this->totalUsers(),
            'no new users row may be created when the fallback resolves',
        );
        $this->assertSame(
            1,
            $this->countUsers('oidc', $ext),
            'exactly one users row for the (provider, external_id) key',
        );
    }

    /**
     * (v) LEGACY `provider='external'` prefix resolution (the trickiest legacy
     * case): a users row saved by the PRE-S44 login path as provider='external'
     * with external_id='oidc.<sub>'. Migration 092's backfill DERIVES
     * provider='oidc' (from the `oidc.` prefix) for its user_identities row. An
     * OIDC login for that external_id must resolve the SAME legacy user via that
     * backfill-derived identity — NOT create a duplicate.
     *
     * This is the case the users-fallback ALONE cannot handle: the fallback is
     * scoped `provider='oidc'` and would MISS the users row whose provider is
     * still literally 'external'. Only the identity-first read resolves it, so
     * this exercises the "no existing user loses login" guarantee end-to-end for
     * the migrated legacy case.
     */
    public function testLegacyExternalProviderResolvesViaBackfilledIdentity(): void
    {
        $repo = new UserRepository($this->conn());
        $ext = 'oidc.legacy-Z-' . $this->token;

        // Legacy pre-S44 row: provider literally 'external', oidc.-prefixed id.
        $legacyId = $this->seedExternalUser('external', $ext);

        // Run migration 092's ACTUAL backfill → derives an (oidc, '', ext)
        // identity row for the legacy user (real deploy behaviour).
        $this->runBackfill();
        $this->assertSame(
            1,
            $this->countIdentities('oidc', '', $ext),
            'backfill must derive one oidc identity for the legacy external row',
        );

        // Sanity: the users-fallback ALONE would miss this — no users row has
        // provider='oidc' for this external_id (it is still 'external'), so ONLY
        // the identity-first read can resolve it.
        $this->assertNull(
            $repo->findByExternalId('oidc', $ext),
            'precondition: no oidc users row exists — only the identity-first read can resolve this',
        );

        $beforeCount = $this->totalUsers();
        $resolved = $repo->findOrCreateByExternalId('oidc', $ext, 'legacy@example.test', 'Legacy');

        $this->assertSame(
            $legacyId,
            $resolved,
            'an OIDC login must resolve the legacy external user via the backfilled identity, never a duplicate',
        );
        $this->assertSame(
            $beforeCount,
            $this->totalUsers(),
            'no new users row may be created for a legacy user resolved via backfill',
        );
        // The legacy users row is untouched — the login read repoint never mutates it.
        $this->assertSame('external', $this->readColumn($legacyId, 'provider'));
        $this->assertSame(
            1,
            $this->countIdentities('oidc', '', $ext),
            'resolution via the identity-first read must not write a second identity row',
        );
    }

    // ---- helpers -------------------------------------------------------------

    private function conn(): Connection
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return $db;
    }

    private function seedExternalUser(string $provider, string $externalId): string
    {
        $id = Uuid::v4();
        $suffix = substr($id, 0, 8);
        $this->conn()->query(
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
        $this->userIds[] = $id;

        return $id;
    }

    private function seedLocalUser(): string
    {
        $id = Uuid::v4();
        $suffix = substr($id, 0, 8);
        $this->conn()->query(
            'INSERT INTO users (id, username, email, password_hash, provider, external_id)'
            . ' VALUES (?, ?, ?, ?, NULL, NULL)',
            [
                $id,
                'local_' . $suffix,
                'local_' . $suffix . '@example.test',
                password_hash('secret', PASSWORD_ARGON2ID),
            ],
        );
        $this->userIds[] = $id;

        return $id;
    }

    private function readColumn(string $userId, string $column): ?string
    {
        $rows = $this->conn()->query("SELECT `$column` FROM users WHERE id = ?", [$userId]);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }
        $value = $rows[0][$column] ?? null;

        return is_string($value) ? $value : null;
    }

    private function countUsers(string $provider, string $externalId): int
    {
        $rows = $this->conn()->query(
            'SELECT id FROM users WHERE provider = ? AND external_id = ?',
            [$provider, $externalId],
        );

        return is_array($rows) ? count($rows) : 0;
    }

    private function totalUsers(): int
    {
        $rows = $this->conn()->query('SELECT COUNT(*) AS c FROM users', []);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return 0;
        }
        $c = $rows[0]['c'] ?? 0;

        return is_numeric($c) ? (int) $c : 0;
    }

    private function countIdentities(string $provider, string $instance, string $externalId): int
    {
        $rows = $this->conn()->query(
            'SELECT id FROM user_identities WHERE provider = ? AND provider_instance = ? AND external_id = ?',
            [$provider, $instance, $externalId],
        );

        return is_array($rows) ? count($rows) : 0;
    }

    /**
     * Build the minimal live schema: `users` + `user_settings` + the VERBATIM
     * migration-092 `user_identities` CREATE. All `IF NOT EXISTS`, so a no-op on a
     * fully-migrated schema and self-standing on a bare scratch DB.
     */
    private function buildSchema(): void
    {
        $db = $this->conn();

        $db->query(
            'CREATE TABLE IF NOT EXISTS users (
                id CHAR(36) PRIMARY KEY,
                username VARCHAR(255) NOT NULL UNIQUE,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NULL,
                display_name VARCHAR(255),
                is_admin TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM(\'pending\', \'active\', \'disabled\') NOT NULL DEFAULT \'active\',
                provider VARCHAR(64) NULL,
                external_id VARCHAR(255) NULL,
                provider_data JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL,
                UNIQUE INDEX idx_external (provider, external_id),
                INDEX idx_provider (provider),
                INDEX idx_username (username),
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->query(
            'CREATE TABLE IF NOT EXISTS user_settings (
                user_id CHAR(36) PRIMARY KEY,
                max_streams INT DEFAULT 3,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->query($this->migration092Statement('create table'));
    }

    /**
     * Run migration 092's ACTUAL `INSERT IGNORE ... SELECT ... FROM users`
     * backfill statement (loaded verbatim from the file) against the seeded rows,
     * so a legacy external user gets its provider-derived identity row exactly as
     * a real deploy would (scenario v).
     */
    private function runBackfill(): void
    {
        $this->conn()->query($this->migration092Statement('insert ignore'));
    }

    /**
     * The VERBATIM statement from migration 092 whose text contains `$needle`
     * (case-insensitive) — e.g. the `CREATE TABLE ... user_identities` DDL or the
     * `INSERT IGNORE` backfill — so both the scratch schema and the backfill match
     * production exactly. The file has no `;` inside strings/comments (Reviewer
     * verified), so a full-line-comment strip + split on `;` is safe.
     */
    private function migration092Statement(string $needle): string
    {
        $path = dirname(__DIR__, 3) . '/migrations/092_user_identities.sql';
        $sql = (string) file_get_contents($path);

        $lines = preg_split('/\R/', $sql) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $kept[] = $line;
        }

        $body = implode("\n", $kept);
        $parts = array_filter(
            array_map('trim', explode(';', $body)),
            static fn (string $s): bool => $s !== '',
        );

        foreach ($parts as $part) {
            if (stripos($part, $needle) !== false) {
                return $part;
            }
        }

        return '';
    }
}
