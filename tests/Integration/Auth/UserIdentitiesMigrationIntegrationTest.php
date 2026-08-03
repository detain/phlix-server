<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Common\Uuid;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-MySQL proof for S46 (updates.md — OAuth plugins `user_identities`
 * migration): {@see \Phlix\Auth\UserIdentityRepository}, the migration-092
 * backfill (`migrations/092_user_identities.sql`), and the
 * {@see UserRepository::findOrCreateByExternalId()} DUAL-WRITE.
 *
 * Mock-DB unit tests stub `query()`, so they cannot see the three
 * data-integrity-critical behaviours this table's design turns on (the Reviewer
 * called this out explicitly): (a) the backfill's CASE-based provider derivation
 * for legacy `provider='external'` rows, (b) whether the
 * `UNIQUE (provider, provider_instance, external_id)` index ACTUALLY enforces
 * for the default-instance rows the migration creates — the S46 fix-r1 changed
 * `provider_instance` from a NULLable column (which InnoDB treats as DISTINCT, so
 * the UNIQUE never fired) to `NOT NULL DEFAULT ''`, and (c) the dual-write
 * transaction that keeps `users` and `user_identities` in lock-step. This suite
 * exercises all three against a live MySQL 8.0 schema built from the real
 * migration files (users = 001+004+009+032+037+040+041+091; user_identities =
 * the verbatim CREATE from migration 092) and re-runs migration 092's actual
 * `INSERT IGNORE` backfill statement AFTER seeding, so the backfill is proven
 * against real rows rather than an empty table.
 *
 * Scenarios (each a real SELECT read-back, not a return-value assertion):
 *  1. Backfill derives the real provider from the legacy `oidc.`/`ldap.`
 *     external_id prefix and writes the '' default-instance sentinel.
 *  2. Backfill collapses a legacy/post-S44 TWIN pair (two `users` rows,
 *     different `provider`, SAME derived identity key) to EXACTLY ONE identity
 *     row via INSERT IGNORE — the migration must not fail on the UNIQUE.
 *  3. The UNIQUE index now genuinely rejects a second default-instance
 *     (`provider_instance = ''`) identity, while a DISTINCT non-empty instance
 *     (S47) with the same provider+external_id is still allowed.
 *  4. Login is preserved: `users` is authoritative and untouched by 092, so
 *     existing external users still resolve via `findByExternalId`.
 *  5. First external login dual-writes BOTH a `users` row and a matching
 *     default-instance `user_identities` row in one transaction; and, under the
 *     S47 login-read repoint, a new external login for an external_id already
 *     owned via a `user_identities` row RESOLVES that owner instead of creating a
 *     duplicate (the former deterministic dual-write conflict is now superseded).
 *
 * The suite self-skips when no MySQL is reachable, and FAILS when one is reachable
 * but unusable — the shared S126 gate in {@see RequiresRealDatabase}, the same one
 * {@see NextUpIntegrationTest} / {@see UserRepositoryExternalIdIntegrationTest} use,
 * reading the `DB_HOST`/`DB_PORT` env. Unlike those two it BUILDS the schema
 * it needs (`CREATE TABLE IF NOT EXISTS`), so it runs against a bare scratch DB;
 * on a fully-migrated shared schema the IF-NOT-EXISTS creates are no-ops. It only
 * ever mutates rows it creates (namespaced by a per-run token, cleaned up in
 * tearDown via ON DELETE CASCADE), so it is safe against a shared `phlix_test`.
 *
 */
final class UserIdentitiesMigrationIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $token = '';

    /** The verbatim `CREATE TABLE ... user_identities` statement from migration 092. */
    private string $createSql = '';

    /** The verbatim `INSERT IGNORE ... SELECT ... FROM users` backfill from migration 092. */
    private string $backfillSql = '';

    /** @var list<string> user ids this run created, deleted (CASCADE) in tearDown. */
    private array $userIds = [];

    /** @var list<string> external_id values this run created, for defensive cleanup. */
    private array $externalIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping user_identities migration integration test. Runs in CI.');

        $this->assertNotNull($this->db);

        // Split the REAL migration 092 file into its two statements (the Reviewer
        // confirmed it splits to exactly 2 — CREATE + backfill). We run the
        // CREATE now (idempotent) and re-run the backfill AFTER seeding in each
        // scenario, so the backfill is exercised against real, seeded rows.
        $statements = $this->migration092Statements();
        $this->assertCount(2, $statements, 'migration 092 must split into exactly 2 SQL statements');
        $this->createSql = $statements[0];
        $this->backfillSql = $statements[1];
        $this->assertStringContainsStringIgnoringCase('create table', $this->createSql);
        $this->assertStringContainsStringIgnoringCase('insert ignore', $this->backfillSql);

        $this->buildSchema();

        $this->token = substr(Uuid::v4(), 0, 12);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            // Identity rows first (defensive — CASCADE already covers the common
            // case where the owning user still exists).
            foreach ($this->externalIds as $ext) {
                $db->query('DELETE FROM user_identities WHERE external_id = ?', [$ext]);
            }
            // Deleting the user CASCADE-removes its user_settings + user_identities.
            foreach ($this->userIds as $id) {
                $db->query('DELETE FROM users WHERE id = ?', [$id]);
            }
        }
        $this->userIds = [];
        $this->externalIds = [];
        parent::tearDown();
    }

    /**
     * Scenario 1: the backfill recovers the REAL provider from the legacy
     * `oidc.`/`ldap.` external_id prefix (bug #4 hard-coded `users.provider =
     * 'external'` before S44) and stores the '' default-instance sentinel.
     */
    public function testBackfillDerivesRealProviderAndDefaultInstanceSentinel(): void
    {
        $extA = 'oidc.sub-A-' . $this->token;      // post-S44 row, provider already 'oidc'
        $extB = 'oidc.legacy-B-' . $this->token;   // legacy 'external' row, oidc. prefix
        $extC = 'ldap.legacy-C-' . $this->token;   // legacy 'external' row, ldap. prefix

        $idA = $this->seedUser('oidc', $extA);
        $idB = $this->seedUser('external', $extB);
        $idC = $this->seedUser('external', $extC);

        $this->runBackfill();

        // A: post-S44 provider passes through the CASE unchanged.
        $rowA = $this->identityForUser($idA);
        $this->assertNotNull($rowA, 'backfill must create an identity row for user A');
        $this->assertSame('oidc', $this->cell($rowA, 'provider'));
        $this->assertSame(
            '',
            $this->cell($rowA, 'provider_instance'),
            'default instance must be the "" sentinel, never NULL',
        );
        $this->assertSame($extA, $this->cell($rowA, 'external_id'));

        // B: legacy 'external' + 'oidc.' prefix -> derived provider 'oidc'.
        $rowB = $this->identityForUser($idB);
        $this->assertNotNull($rowB);
        $this->assertSame(
            'oidc',
            $this->cell($rowB, 'provider'),
            "legacy 'external' row with an oidc. prefix must derive provider 'oidc'",
        );
        $this->assertSame('', $this->cell($rowB, 'provider_instance'));
        $this->assertSame($extB, $this->cell($rowB, 'external_id'));

        // C: legacy 'external' + 'ldap.' prefix -> derived provider 'ldap'.
        $rowC = $this->identityForUser($idC);
        $this->assertNotNull($rowC);
        $this->assertSame(
            'ldap',
            $this->cell($rowC, 'provider'),
            "legacy 'external' row with an ldap. prefix must derive provider 'ldap'",
        );
        $this->assertSame('', $this->cell($rowC, 'provider_instance'));
        $this->assertSame($extC, $this->cell($rowC, 'external_id'));
    }

    /**
     * Scenario 2 (the fix-r1 guard): a legacy `provider='external'` row and its
     * post-S44 `provider='oidc'` twin (SAME external_id — allowed by
     * `users.UNIQUE(provider, external_id)` because provider differs) both derive
     * to `(oidc, '', <external_id>)`. The `INSERT IGNORE` backfill must SUCCEED
     * (a plain INSERT...SELECT would fail the whole migration on the UNIQUE) and
     * leave EXACTLY ONE identity row.
     */
    public function testBackfillCollapsesLegacyTwinsToASingleIdentityRow(): void
    {
        $ext = 'oidc.twin-D-' . $this->token;

        $legacyId = $this->seedUser('external', $ext); // derives to oidc
        $postS44Id = $this->seedUser('oidc', $ext);    // already oidc

        // Both users legitimately coexist in `users` (UNIQUE differs on provider).
        $this->assertNotSame($legacyId, $postS44Id);

        $threw = false;
        try {
            $this->runBackfill();
        } catch (Throwable $e) {
            $threw = true;
        }
        $this->assertFalse(
            $threw,
            'INSERT IGNORE backfill must NOT fail when two users derive to the same identity key',
        );

        // Exactly one identity row for the collapsed key — not two, not zero.
        $this->assertSame(
            1,
            $this->countIdentities('oidc', '', $ext),
            'INSERT IGNORE must collapse the twin pair to a single (oidc, "", external_id) row',
        );

        // The surviving row belongs to one of the two twin users.
        $row = $this->identityRow('oidc', '', $ext);
        $this->assertNotNull($row);
        $this->assertContains(
            $this->cell($row, 'user_id'),
            [$legacyId, $postS44Id],
            'the surviving identity must be owned by one of the twin users',
        );
    }

    /**
     * Scenario 3 (the HIGH fix — the definitive check): after backfill, a SECOND
     * default-instance identity for the same `(provider, '', external_id)` is
     * REJECTED by the UNIQUE index (both via UserIdentityRepository::create and a
     * raw INSERT), while a DISTINCT non-empty `provider_instance` (S47
     * multi-instance) with the same provider+external_id is ALLOWED.
     *
     * This scenario is red against the pre-fix DDL (NULLable provider_instance +
     * NULL backfill): InnoDB treats a multi-column UNIQUE as DISTINCT whenever an
     * indexed column is NULL, so the duplicate would be accepted. The '' sentinel
     * makes the comparison ('' = '') real, so the DB genuinely enforces.
     */
    public function testUniqueEnforcesDefaultInstanceAndPermitsDistinctInstance(): void
    {
        $ext = 'oidc.sub-A-' . $this->token;
        $idA = $this->seedUser('oidc', $ext);

        $this->runBackfill();
        $this->assertSame(
            1,
            $this->countIdentities('oidc', '', $ext),
            'backfill seeds exactly one default-instance identity',
        );

        $repo = new UserIdentityRepository($this->conn());

        // (a) Duplicate default-instance via the repository -> rejected.
        $threwRepo = false;
        try {
            $repo->create($idA, 'oidc', '', $ext, null);
        } catch (Throwable $e) {
            $threwRepo = true;
        }
        $this->assertTrue(
            $threwRepo,
            'a duplicate default-instance identity via create() must violate the UNIQUE index',
        );

        // (b) Duplicate default-instance via a raw INSERT -> rejected.
        $threwRaw = false;
        try {
            $this->conn()->query(
                'INSERT INTO user_identities (id, user_id, provider, provider_instance, external_id)'
                . ' VALUES (?, ?, ?, ?, ?)',
                [Uuid::v4(), $idA, 'oidc', '', $ext],
            );
        } catch (Throwable $e) {
            $threwRaw = true;
        }
        $this->assertTrue(
            $threwRaw,
            'a raw duplicate (provider, "", external_id) INSERT must violate the UNIQUE index',
        );

        // Still exactly one default-instance row (neither duplicate persisted).
        $this->assertSame(
            1,
            $this->countIdentities('oidc', '', $ext),
            'no duplicate default-instance identity may persist',
        );

        // (c) A DISTINCT non-empty instance (S47) with the SAME provider+external_id is allowed.
        $newId = $repo->create($idA, 'oidc', 'okta', $ext, null);
        $this->assertNotSame('', $newId);
        $this->assertSame(
            1,
            $this->countIdentities('oidc', 'okta', $ext),
            'a distinct non-empty provider_instance must be permitted (S47 multi-instance)',
        );

        // Two identities now share (provider, external_id) but differ on instance.
        $this->assertSame(
            2,
            $this->countIdentitiesByProviderExternal('oidc', $ext),
            'default-instance + a named instance coexist for the same provider+external_id',
        );
    }

    /**
     * Scenario 4 (the AC — login is preserved): migration 092 does NOT touch
     * `users`, so `users.provider`/`users.external_id` stay authoritative and
     * every external user who could log in before still resolves after.
     */
    public function testExternalLoginLookupStillResolvesAfterMigration(): void
    {
        $extA = 'oidc.sub-A-' . $this->token;
        $extB = 'oidc.legacy-B-' . $this->token;

        $idA = $this->seedUser('oidc', $extA);
        $idB = $this->seedUser('external', $extB);

        $this->runBackfill();

        $repo = new UserRepository($this->conn());

        // Post-S44 oidc user still resolves by its real provider.
        $rowA = $repo->findByExternalId('oidc', $extA);
        $this->assertIsArray($rowA);
        $this->assertSame($idA, $rowA['id'] ?? null);
        $this->assertSame('oidc', $rowA['provider'] ?? null);

        // Legacy 'external' user still resolves under its ORIGINAL provider value
        // — the login read path reads `users`, which the migration left untouched
        // (even though the identity row derives provider 'oidc').
        $rowB = $repo->findByExternalId('external', $extB);
        $this->assertIsArray($rowB);
        $this->assertSame($idB, $rowB['id'] ?? null);
        $this->assertSame('external', $rowB['provider'] ?? null, 'users.provider must be untouched by migration 092');
    }

    /**
     * Scenario 5: first external login dual-writes BOTH stores. A real SELECT of
     * `users`, `user_settings` AND `user_identities` proves all three rows exist
     * for the one user id, with the identity carrying the '' default-instance
     * sentinel.
     */
    public function testDualWriteCreatesUsersAndIdentityRows(): void
    {
        $ext = 'oidc.new-E-' . $this->token;
        $email = 'oidc-new-e-' . $this->token . '@example.test';

        $repo = new UserRepository($this->conn());
        $userId = $repo->findOrCreateByExternalId('oidc', $ext, $email, 'New E');
        $this->assertNotSame('', $userId);
        $this->userIds[] = $userId;
        $this->externalIds[] = $ext;

        // users row (real read-back).
        $userRow = $this->row('SELECT id, provider, external_id FROM users WHERE id = ?', [$userId]);
        $this->assertNotNull($userRow);
        $this->assertSame('oidc', $this->cell($userRow, 'provider'));
        $this->assertSame($ext, $this->cell($userRow, 'external_id'));

        // user_settings row (proves the dual-write's settings insert committed).
        $settingsRow = $this->row('SELECT user_id FROM user_settings WHERE user_id = ?', [$userId]);
        $this->assertNotNull($settingsRow, 'dual-write must create the user_settings row');

        // user_identities row for the SAME user id, default-instance sentinel.
        $identityRow = $this->identityForUser($userId);
        $this->assertNotNull($identityRow, 'dual-write must create the user_identities row');
        $this->assertSame('oidc', $this->cell($identityRow, 'provider'));
        $this->assertSame('', $this->cell($identityRow, 'provider_instance'));
        $this->assertSame($ext, $this->cell($identityRow, 'external_id'));

        // Idempotent: a second call returns the same id and adds no rows.
        $again = $repo->findOrCreateByExternalId('oidc', $ext, $email, 'New E');
        $this->assertSame($userId, $again);
        $this->assertSame(
            1,
            $this->countIdentities('oidc', '', $ext),
            'the find-branch must not write a second identity row',
        );
    }

    /**
     * Scenario 5 (S47 supersedes the former deterministic dual-write conflict):
     * a NEW 'oidc' login for an external_id already OWNED — via a
     * `user_identities` row — by a legacy `provider='external'` user X must
     * RESOLVE X, never create a duplicate.
     *
     * Before S47, {@see UserRepository::findOrCreateByExternalId()} ignored
     * `user_identities` on read, so this login entered the create branch and its
     * identity INSERT collided with X's row, rolling the whole transaction back
     * (this scenario used to assert that throw). S47's login-read REPOINT reads
     * `user_identities` FIRST, so the same login now resolves X (its legitimate
     * owner) and returns early — NO create branch, NO conflict, NO duplicate
     * 'oidc' users row. That is the headline S47 guarantee (an existing identity
     * is never turned into a duplicate account), proven here against real MySQL.
     *
     * The dual-write transaction wrapping is unchanged; its rollback atomicity is
     * now reachable only via a genuine concurrent race (two first-logins for the
     * SAME brand-new external_id), which is out of scope for a single-threaded
     * integration test.
     */
    public function testS47RepointResolvesExistingIdentityOwnerInsteadOfConflicting(): void
    {
        $ext = 'oidc.atomic-F-' . $this->token;

        // Legacy user X (provider 'external') + its already-present derived identity.
        $xId = $this->seedUser('external', $ext);
        $this->conn()->query(
            'INSERT INTO user_identities (id, user_id, provider, provider_instance, external_id)'
            . ' VALUES (?, ?, ?, ?, ?)',
            [Uuid::v4(), $xId, 'oidc', '', $ext],
        );
        $this->assertSame(1, $this->countIdentities('oidc', '', $ext));

        $repo = new UserRepository($this->conn());

        // S47 identity-first read resolves X — no throw, no create branch.
        $resolved = $repo->findOrCreateByExternalId('oidc', $ext, null, 'Atomic F');
        $this->assertSame($xId, $resolved, 'S47 must resolve the existing identity owner, never create a duplicate');

        // No duplicate: still no users row with provider='oidc' for this ext.
        $dup = $this->row('SELECT id FROM users WHERE provider = ? AND external_id = ?', ['oidc', $ext]);
        $this->assertNull($dup, 'the repoint must not create a duplicate oidc users row');

        // X and its single identity row are intact.
        $xRow = $this->row('SELECT id FROM users WHERE id = ?', [$xId]);
        $this->assertNotNull($xRow, 'the pre-existing legacy user must be untouched');
        $this->assertSame(1, $this->countIdentities('oidc', '', $ext), 'only the pre-existing identity row remains');
    }

    // ---- helpers -------------------------------------------------------------

    private function conn(): Connection
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return $db;
    }

    /**
     * Seed a `users` row directly (raw SQL) and record it for cleanup.
     */
    private function seedUser(string $provider, string $externalId): string
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
        if (!in_array($externalId, $this->externalIds, true)) {
            $this->externalIds[] = $externalId;
        }

        return $id;
    }

    /**
     * Run migration 092's ACTUAL `INSERT IGNORE ... SELECT ... FROM users`
     * backfill statement (loaded verbatim from the file) against the seeded rows.
     */
    private function runBackfill(): void
    {
        $this->conn()->query($this->backfillSql);
    }

    /**
     * The single identity row for a user id (each seeded user has at most one
     * default-instance identity from the backfill).
     *
     * @return array<string, mixed>|null
     */
    private function identityForUser(string $userId): ?array
    {
        return $this->row(
            'SELECT id, user_id, provider, provider_instance, external_id FROM user_identities WHERE user_id = ?',
            [$userId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function identityRow(string $provider, string $instance, string $externalId): ?array
    {
        return $this->row(
            'SELECT id, user_id, provider, provider_instance, external_id FROM user_identities
             WHERE provider = ? AND provider_instance = ? AND external_id = ?',
            [$provider, $instance, $externalId],
        );
    }

    private function countIdentities(string $provider, string $instance, string $externalId): int
    {
        $rows = $this->conn()->query(
            'SELECT id FROM user_identities WHERE provider = ? AND provider_instance = ? AND external_id = ?',
            [$provider, $instance, $externalId],
        );

        return is_array($rows) ? count($rows) : 0;
    }

    private function countIdentitiesByProviderExternal(string $provider, string $externalId): int
    {
        $rows = $this->conn()->query(
            'SELECT id FROM user_identities WHERE provider = ? AND external_id = ?',
            [$provider, $externalId],
        );

        return is_array($rows) ? count($rows) : 0;
    }

    /**
     * Run a single-row SELECT and return the first row (or null).
     *
     * @param list<mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $params): ?array
    {
        $rows = $this->conn()->query($sql, $params);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $rows[0];

        return $row;
    }

    /**
     * Extract a scalar cell as a string (or null). Column names are hard-coded
     * test literals, never request input.
     *
     * @param array<string, mixed> $row
     */
    private function cell(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Build the minimal live schema this test needs: `users` (consolidated from
     * migrations 001+004+009+032+037+040+041+091), `user_settings` (001), and
     * `user_identities` via the VERBATIM CREATE statement from migration 092.
     * All CREATE ... IF NOT EXISTS, so it is a no-op on a fully-migrated schema
     * and self-standing against a bare scratch database.
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
                avatar_url VARCHAR(500) NULL DEFAULT NULL,
                is_admin TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM(\'pending\', \'active\', \'disabled\') NOT NULL DEFAULT \'active\',
                must_change_password TINYINT(1) NOT NULL DEFAULT 0,
                password_reset_token VARCHAR(255) NULL DEFAULT NULL,
                password_reset_expires_at INT UNSIGNED NULL DEFAULT NULL,
                logout_all_devices_at INT UNSIGNED NULL DEFAULT NULL,
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
                max_bitrate INT DEFAULT 100000000,
                preferred_audio_language VARCHAR(10) DEFAULT \'en\',
                preferred_subtitle_language VARCHAR(10) DEFAULT \'en\',
                subtitle_mode ENUM(\'always\', \'only_foreign\', \'none\') DEFAULT \'only_foreign\',
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        // The real migration 092 CREATE (already `IF NOT EXISTS`).
        $db->query($this->createSql);
    }

    /**
     * Read migration 092 and split it into its individual SQL statements.
     * Full-line `--` comments and blank lines are stripped, then the remainder is
     * split on `;` (the file has no `;` inside strings/comments — Reviewer
     * verified, and this yields exactly [CREATE, INSERT IGNORE]).
     *
     * @return list<string>
     */
    private function migration092Statements(): array
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

        return array_values($parts);
    }
}
