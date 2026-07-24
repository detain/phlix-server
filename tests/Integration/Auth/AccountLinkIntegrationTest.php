<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use Phlix\Plugins\Ldap\LdapConnection;
use Phlix\Plugins\Ldap\LdapProvider;
use Phlix\Server\Http\Controllers\AccountLinkController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-MySQL proof for S45 (updates.md #37 — OIDC/LDAP account-linking
 * endpoint): {@see \Phlix\Server\Http\Controllers\AccountLinkController} and the
 * conflict-handling it turns on in the `user_identities` table (migration 092).
 *
 * The account-takeover linchpins (verified-only linking, server-side link
 * intent, no-token link mode) are covered by the mock-DB unit test
 * {@see \Phlix\Tests\Unit\Server\Http\Controllers\AccountLinkControllerTest}.
 * What a mock DB CANNOT prove — and what the Reviewer explicitly flagged as
 * "only mock-covered" (finding #4) plus the finding #1 boundary — is how the
 * controller behaves against the REAL InnoDB `UNIQUE (provider,
 * provider_instance, external_id)` index (migration 092):
 *
 *   - the DB-UNIQUE race BACKSTOP: when two users link the SAME external
 *     identity and the pre-check misses the TOCTOU window, `create()` must raise
 *     a GENUINE duplicate-key error (SQLSTATE 23000 / errno 1062) that the
 *     controller catches, re-reads, and re-classifies to 409 — never a 500, and
 *     leaving EXACTLY ONE identity row still owned by the first linker;
 *   - the finding #1 BOUNDARY: a NON-duplicate `create()` failure (an FK
 *     violation — also SQLSTATE 23000 in the flattened driver error) must be
 *     RE-THROWN (a 5xx), NOT mislabeled 409. This is exactly why the controller
 *     classifies by RE-READING the row rather than parsing driver error codes:
 *     dup-key ⇒ row present ⇒ 409; FK/other ⇒ row absent ⇒ re-throw.
 *
 * Mock-DB tests in this repo have repeatedly hidden real SQL / constraint bugs
 * (LiveTv RowQuery/ResultSet, metrics ONLY_FULL_GROUP_BY), so these behaviours
 * need a live DB. Every assertion is a real SELECT read-back, not a return-value.
 *
 * The test exercises the CONTROLLER path (real {@see UserIdentityRepository} on
 * the scratch connection + a real {@see LdapProvider} whose bind is stubbed to
 * return a fixed provider-VERIFIED `ldap.<dn>` — the linked external_id is always
 * the verified value, never request input) so the assertions are faithful to the
 * production conflict mapping. It ALSO asserts the genuine dup-key SQLSTATE at
 * the repository level (scenario 2) to prove the throw the controller relies on
 * is a real DB duplicate-key, not a synthesised error.
 *
 * Scenarios:
 *  1. Cross-user conflict via the DB-UNIQUE backstop (the HEADLINE): A links
 *     `(ldap, '', ldap.<dn>)`; B links the SAME identity through a controller
 *     whose pre-check misses the race window, so `create()` hits the real UNIQUE
 *     index and throws → the controller maps it to 409 (not 500), EXACTLY ONE
 *     row survives, still owned by A, B owns none, and neither users row's
 *     provider/external_id was mutated (real SELECT before/after).
 *  2. The genuine duplicate-key error is a real DB error (repository level):
 *     a second `create()` for the same triple throws with a SQLSTATE
 *     23000 / 1062 / "Duplicate" signature, and one row remains owned by A.
 *  3. Finding #1 boundary: a NON-duplicate `create()` failure (FK violation —
 *     link for a user_id absent from `users`) is RE-THROWN (a 5xx), NOT a 409,
 *     and persists no row.
 *  4. Same-user idempotency: A re-links the same identity → 200 `created:false`,
 *     still exactly one row.
 *  5. Listing: `GET /auth/identities` shaping returns A's identity with the
 *     expected fields and NEVER leaks `provider_data` secrets.
 *
 * The suite self-skips when no MySQL is reachable (same fsockopen guard + env
 * gates as {@see UserIdentitiesMigrationIntegrationTest} /
 * {@see UserRepositoryExternalIdIntegrationTest}). Like the S46 test it BUILDS
 * the schema it needs (`users` = migrations 001+004+009+032+037+040+041+091,
 * `user_identities` = the verbatim migration-092 CREATE) with `IF NOT EXISTS`,
 * so it runs against a bare scratch DB and is a no-op create on a migrated one.
 * It only ever mutates rows it creates (namespaced by a per-run token, removed in
 * tearDown via ON DELETE CASCADE), so it is safe against a shared `phlix_test`.
 *
 * @covers \Phlix\Server\Http\Controllers\AccountLinkController
 * @covers \Phlix\Auth\UserIdentityRepository
 */
final class AccountLinkIntegrationTest extends TestCase
{
    private ?Connection $db = null;

    private string $token = '';

    /** The verbatim `CREATE TABLE ... user_identities` statement from migration 092. */
    private string $createSql = '';

    /** @var list<string> user ids this run created, deleted (CASCADE) in tearDown. */
    private array $userIds = [];

    /** @var list<string> external_id values this run created, for defensive cleanup. */
    private array $externalIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf(
                    'No MySQL on %s:%d — skipping account-link integration test. Runs in CI.',
                    $host,
                    $port,
                ),
            );
        }

        try {
            ConnectionPool::init(dirname(__DIR__, 3) . '/config/database.php');
            $this->db = ConnectionPool::getConnection('mysql');
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

        $this->assertNotNull($this->db);

        // Load the verbatim migration-092 CREATE (its first statement).
        $statements = $this->migration092Statements();
        $this->assertNotEmpty($statements, 'migration 092 must contain at least the CREATE statement');
        $this->createSql = $statements[0];
        $this->assertStringContainsStringIgnoringCase('create table', $this->createSql);

        $this->buildSchema();

        $this->token = substr(Uuid::v4(), 0, 12);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
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
     * Scenario 1 (the HEADLINE): a genuine cross-user DB-UNIQUE conflict is
     * mapped to 409 — never a 500 — with exactly one identity row surviving,
     * owned by the first linker, and `users` never mutated.
     *
     * A (a LOCAL account) links a verified `ldap.<dn>` identity. B (a different
     * LOCAL account) then links the SAME verified identity through a controller
     * whose pre-check MISSES the row (simulating the TOCTOU window between B's
     * check and A's commit), so B's request proceeds into `create()` and hits the
     * real migration-092 UNIQUE index. The genuine duplicate-key throw is caught
     * and re-classified by re-reading: the row belongs to A, not B, so the
     * outcome is 409 `identity_already_linked`.
     */
    public function testCrossUserDbUniqueConflictMapsTo409OneRowOwnedByA(): void
    {
        $dn = 'uid=link-shared-' . $this->token . ',ou=users,dc=example,dc=com';
        $externalId = 'ldap.' . $dn;
        $this->externalIds[] = $externalId;

        $idA = $this->seedLocalUser('A');
        $idB = $this->seedLocalUser('B');

        // Snapshot the (local) users rows BEFORE any linking.
        $beforeA = $this->userProviderCols($idA);
        $beforeB = $this->userProviderCols($idB);

        // --- A links the identity through the controller (real repo). ---
        $controllerA = new AccountLinkController(
            new UserIdentityRepository($this->conn()),
            $this->registryVerifying($dn),
        );
        $respA = $controllerA->linkLdap($this->ldapLinkRequest($idA), []);
        $this->assertSame(200, $respA->statusCode, 'A must successfully link the verified identity');
        /** @var array<string, mixed> $bodyA */
        $bodyA = json_decode($respA->body, true);
        $this->assertTrue($bodyA['created'] ?? null, 'A must have created a new identity row');

        // Exactly one row, owned by A (real SELECT read-back).
        $this->assertSame(1, $this->countIdentities('ldap', '', $externalId));
        $rowA = $this->identityRow('ldap', '', $externalId);
        $this->assertNotNull($rowA);
        $this->assertSame($idA, $this->cell($rowA, 'user_id'));

        // --- B links the SAME identity; force the pre-check to MISS the row so
        // the controller proceeds to create() and the REAL DB UNIQUE index is the
        // conflict authority (the race backstop). ---
        $raceRepo = $this->raceRepositoryMissingFirstPrecheck();
        $controllerB = new AccountLinkController($raceRepo, $this->registryVerifying($dn));

        $respB = $controllerB->linkLdap($this->ldapLinkRequest($idB), []);

        // 409, NOT 500: the genuine duplicate-key throw was caught, re-read, and
        // re-classified to a conflict.
        $this->assertSame(
            409,
            $respB->statusCode,
            'a genuine DB duplicate-key conflict must map to 409, never a 500',
        );
        /** @var array<string, mixed> $bodyB */
        $bodyB = json_decode($respB->body, true);
        $this->assertSame('identity_already_linked', $bodyB['error'] ?? null);

        // The pre-check really was forced to miss, so create() really ran and the
        // DB really rejected it — this is the backstop, not the pre-check path.
        $this->assertSame(
            1,
            $raceRepo->createAttempts(),
            'B must have attempted create() (the pre-check was forced to miss)',
        );

        // EXACTLY ONE identity row survives, still owned by A — no second row, no
        // repoint to B.
        $this->assertSame(
            1,
            $this->countIdentities('ldap', '', $externalId),
            'the duplicate must not persist — exactly one identity row',
        );
        $rowAfter = $this->identityRow('ldap', '', $externalId);
        $this->assertNotNull($rowAfter);
        $this->assertSame($idA, $this->cell($rowAfter, 'user_id'), 'the surviving identity must still belong to A');

        // B owns NO identity rows.
        $this->assertSame(0, count($this->identitiesForUser($idB)), 'B must own no identity rows');

        // users NEVER mutated by linking (real SELECT after == before).
        $this->assertSame($beforeA, $this->userProviderCols($idA), "A's users.provider/external_id must be untouched");
        $this->assertSame($beforeB, $this->userProviderCols($idB), "B's users.provider/external_id must be untouched");
    }

    /**
     * Scenario 2: the throw the controller relies on in scenario 1 is a GENUINE
     * DB duplicate-key error. At the repository level, a second `create()` for
     * the same `(provider, '', external_id)` triple raises an error whose message
     * carries the InnoDB duplicate-key signature (SQLSTATE 23000 / errno 1062 /
     * "Duplicate"), and exactly one row remains owned by the first writer.
     */
    public function testRepositoryCreateRaisesGenuineDuplicateKeyError(): void
    {
        $dn = 'uid=dup-' . $this->token . ',ou=users,dc=example,dc=com';
        $externalId = 'ldap.' . $dn;
        $this->externalIds[] = $externalId;

        $idA = $this->seedLocalUser('A');
        $idB = $this->seedLocalUser('B');

        $repo = new UserIdentityRepository($this->conn());
        $repo->create($idA, 'ldap', '', $externalId, null);

        $caught = null;
        try {
            $repo->create($idB, 'ldap', '', $externalId, null);
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'a duplicate (provider, "", external_id) create() must throw');
        $message = (string) $caught->getMessage();
        $this->assertTrue(
            str_contains($message, '23000')
            || str_contains($message, '1062')
            || stripos($message, 'duplicate') !== false,
            'the throw must carry a genuine DB duplicate-key signature (SQLSTATE 23000 / 1062 / Duplicate), got: ' . $message,
        );

        // Exactly one row, still owned by A.
        $this->assertSame(1, $this->countIdentities('ldap', '', $externalId));
        $row = $this->identityRow('ldap', '', $externalId);
        $this->assertNotNull($row);
        $this->assertSame($idA, $this->cell($row, 'user_id'));
    }

    /**
     * Scenario 3 (finding #1 boundary): a NON-duplicate `create()` failure must
     * NOT be mislabeled 409. Linking for a `user_id` that does not exist in
     * `users` violates the migration-092 FOREIGN KEY (also SQLSTATE 23000 in the
     * flattened driver error), so `create()` throws; the post-throw re-read finds
     * NO row, so the controller RE-THROWS (a 5xx) rather than returning a 409.
     *
     * This proves the controller classifies by RE-READING the row, not by parsing
     * driver error codes — the exact distinction finding #1 turned on, against a
     * real (non-duplicate) constraint failure.
     */
    public function testNonDuplicateCreateFailureIsRethrownNotMappedTo409(): void
    {
        $dn = 'uid=fk-' . $this->token . ',ou=users,dc=example,dc=com';
        $externalId = 'ldap.' . $dn;
        $this->externalIds[] = $externalId;

        // A user id that is NEVER inserted into `users` → the identity FK fails.
        $ghostUserId = Uuid::v4();

        $controller = new AccountLinkController(
            new UserIdentityRepository($this->conn()),
            $this->registryVerifying($dn),
        );

        $request = $this->ldapLinkRequest($ghostUserId);

        $threw = false;
        try {
            $controller->linkLdap($request, []);
        } catch (Throwable $e) {
            $threw = true;
        }

        $this->assertTrue(
            $threw,
            'a non-duplicate (FK) create() failure must be RE-THROWN (a 5xx), not masked as a 409',
        );

        // Nothing persisted (the FK rejected the insert; no row exists for anyone).
        $this->assertSame(
            0,
            $this->countIdentities('ldap', '', $externalId),
            'a rejected FK insert must persist no identity row',
        );
    }

    /**
     * Scenario 4: same-user idempotency. A links the identity, then links the
     * SAME identity again → the pre-check finds A's own row and returns 200
     * `created:false` with no duplicate insert (still exactly one row).
     */
    public function testSameUserRelinkIsIdempotentNoDuplicateRow(): void
    {
        $dn = 'uid=idem-' . $this->token . ',ou=users,dc=example,dc=com';
        $externalId = 'ldap.' . $dn;
        $this->externalIds[] = $externalId;

        $idA = $this->seedLocalUser('A');

        $controller = new AccountLinkController(
            new UserIdentityRepository($this->conn()),
            $this->registryVerifying($dn),
        );

        $first = $controller->linkLdap($this->ldapLinkRequest($idA), []);
        $this->assertSame(200, $first->statusCode);
        /** @var array<string, mixed> $firstBody */
        $firstBody = json_decode($first->body, true);
        $this->assertTrue($firstBody['created'] ?? null, 'first link creates the row');
        $this->assertSame(1, $this->countIdentities('ldap', '', $externalId));

        $second = $controller->linkLdap($this->ldapLinkRequest($idA), []);
        $this->assertSame(200, $second->statusCode, 're-linking the same identity is idempotent success');
        /** @var array<string, mixed> $secondBody */
        $secondBody = json_decode($second->body, true);
        $this->assertTrue($secondBody['success'] ?? null);
        $this->assertFalse($secondBody['created'] ?? null, 'the second link must NOT create a new row');

        $this->assertSame(
            1,
            $this->countIdentities('ldap', '', $externalId),
            'idempotent re-link must not add a second identity row',
        );
        $row = $this->identityRow('ldap', '', $externalId);
        $this->assertNotNull($row);
        $this->assertSame($idA, $this->cell($row, 'user_id'));
    }

    /**
     * Scenario 5: the listing shaping. `GET /auth/identities` returns A's linked
     * identity with the client-facing fields and DELIBERATELY drops
     * `provider_data` — even when the stored row carries a secret.
     */
    public function testListIdentitiesReturnsIdentityWithoutLeakingProviderData(): void
    {
        $secret = 'super-secret-' . $this->token;
        $dn = 'uid=list-' . $this->token . ',ou=users,dc=example,dc=com';
        $externalId = 'ldap.' . $dn;
        $this->externalIds[] = $externalId;

        $idA = $this->seedLocalUser('A');

        // Seed the identity directly with a provider_data secret the client must
        // never see.
        $repo = new UserIdentityRepository($this->conn());
        $repo->create($idA, 'ldap', '', $externalId, ['access_token' => $secret, 'email' => 'a@example.test']);

        $controller = new AccountLinkController($repo, new AuthProviderRegistry());

        $request = new Request();
        $request->userId = $idA;

        $response = $controller->listIdentities($request, []);
        $this->assertSame(200, $response->statusCode);

        // The secret / provider_data must not appear anywhere in the payload.
        $this->assertStringNotContainsString($secret, $response->body);
        $this->assertStringNotContainsString('provider_data', $response->body);
        $this->assertStringNotContainsString('access_token', $response->body);

        /** @var array{identities: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true);
        $this->assertCount(1, $body['identities']);
        $identity = $body['identities'][0];
        $this->assertSame('ldap', $identity['provider']);
        $this->assertSame('', $identity['provider_instance']);
        $this->assertSame($externalId, $identity['external_id']);
        $this->assertArrayHasKey('id', $identity);
        $this->assertArrayHasKey('linked_at', $identity);
        $this->assertArrayNotHasKey('provider_data', $identity);
    }

    // ---- helpers -------------------------------------------------------------

    private function conn(): Connection
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return $db;
    }

    /**
     * A {@see UserIdentityRepository} on the scratch connection whose FIRST
     * `findByProviderExternalId()` (the controller's pre-check) is forced to
     * return null — simulating the TOCTOU window where a second linker checked
     * BEFORE the first linker's row became visible. Every other call (including
     * the controller's post-throw re-read) hits the real DB, and `create()` is
     * inherited unchanged, so it genuinely exercises the migration-092 UNIQUE
     * index as the conflict authority.
     */
    private function raceRepositoryMissingFirstPrecheck(): UserIdentityRepository
    {
        return new class ($this->conn()) extends UserIdentityRepository {
            private int $precheckCalls = 0;

            private int $createCalls = 0;

            public function findByProviderExternalId(
                string $provider,
                ?string $providerInstance,
                string $externalId
            ): ?array {
                $this->precheckCalls++;
                if ($this->precheckCalls === 1) {
                    // Race window: pretend the row is not there yet.
                    return null;
                }

                return parent::findByProviderExternalId($provider, $providerInstance, $externalId);
            }

            public function create(
                string $userId,
                string $provider,
                ?string $providerInstance,
                string $externalId,
                array|string|null $providerData = null
            ): string {
                $this->createCalls++;

                return parent::create($userId, $provider, $providerInstance, $externalId, $providerData);
            }

            public function createAttempts(): int
            {
                return $this->createCalls;
            }
        };
    }

    /**
     * An {@see AuthProviderRegistry} carrying an {@see LdapProvider} whose bind is
     * stubbed to SUCCEED and resolve to the given DN, so the provider-VERIFIED
     * external_id is always `ldap.<dn>` — never anything from the request.
     */
    private function registryVerifying(string $dn): AuthProviderRegistry
    {
        $registry = new AuthProviderRegistry();
        $registry->registerProvider($this->ldapProviderVerifying($dn));

        return $registry;
    }

    private function ldapProviderVerifying(string $dn): LdapProvider
    {
        $connection = $this->createMock(LdapConnection::class);
        $connection->method('authenticate')->willReturn(true);
        $connection->method('findUserDn')->willReturn($dn);
        $connection->method('getUserAttributes')->willReturn([
            'dn' => $dn,
            'uid' => ['linker'],
            'cn' => ['Link Tester'],
            'mail' => ['linker@example.com'],
        ]);
        $connection->method('isAdmin')->willReturn(false);

        $provider = new LdapProvider(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: '(uid={{username}})',
            adminGroup: null,
        );
        $provider->setConnection($connection);

        return $provider;
    }

    /**
     * A POST /auth/identities/link/ldap request for the given (trusted, session)
     * user id. The body carries HOSTILE fields that must be ignored — the linked
     * identity is always the provider-verified value.
     */
    private function ldapLinkRequest(string $userId): Request
    {
        $request = new Request();
        $request->userId = $userId;
        $request->body = [
            'username' => 'linker',
            'password' => 'correct',
            // Hostile fields that must never influence the linked identity.
            'external_id' => 'ldap.attacker',
            'provider' => 'github',
            'user_id' => 'victim',
        ];

        return $request;
    }

    /**
     * Seed a LOCAL `users` row (provider/external_id NULL — the realistic
     * account-linking initiator) and record it for cleanup.
     */
    private function seedLocalUser(string $label): string
    {
        $id = Uuid::v4();
        $suffix = substr($id, 0, 8);
        $this->conn()->query(
            'INSERT INTO users (id, username, email, password_hash, provider, external_id)'
            . ' VALUES (?, ?, ?, ?, NULL, NULL)',
            [
                $id,
                'link_' . $label . '_' . $suffix,
                'link_' . $label . '_' . $suffix . '@example.test',
                'x',
            ],
        );
        $this->userIds[] = $id;

        return $id;
    }

    /**
     * Read a user's provider/external_id columns back (for the untouched-by-link
     * assertion).
     *
     * @return array{provider: ?string, external_id: ?string}
     */
    private function userProviderCols(string $userId): array
    {
        $row = $this->row('SELECT provider, external_id FROM users WHERE id = ?', [$userId]);
        $this->assertNotNull($row);

        return [
            'provider' => $this->cell($row, 'provider'),
            'external_id' => $this->cell($row, 'external_id'),
        ];
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function identitiesForUser(string $userId): array
    {
        $rows = $this->conn()->query('SELECT id FROM user_identities WHERE user_id = ?', [$userId]);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
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
     * @param array<string, mixed> $row
     */
    private function cell(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Build the minimal live schema: `users` (consolidated from migrations
     * 001+004+009+032+037+040+041+091), `user_settings` (001), and
     * `user_identities` via the VERBATIM CREATE from migration 092. All
     * CREATE ... IF NOT EXISTS, so it is a no-op on a fully-migrated schema and
     * self-standing against a bare scratch database.
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
     * Read migration 092 and split it into its individual SQL statements
     * (full-line `--` comments and blank lines stripped, then split on `;`).
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
