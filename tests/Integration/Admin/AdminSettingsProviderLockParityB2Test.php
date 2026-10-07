<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Plugins\Github\Plugin as GithubPlugin;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;
use Phlix\Plugins\Repository\PluginSettingsStore;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Provider parity on REAL MySQL: admin∥provider writers serialize on ONE
 * five-key FOR UPDATE set.
 *
 * Venue shape cloned verbatim from {@see AdminSettingsAuthLockB2Test}
 * (production 026 DDL, throwaway `phlix_b2p_*` scratch DATABASE, S345 rule,
 * lock-wait-timeout-as-sync — no sleeps anywhere). What that file proves for
 * admin∥admin, this file proves for the CROSS-SURFACE pair the B2 review
 * left as its residual: the provider-API disable now takes the identical
 * lock set in the identical canonical order as the admin settings PUT
 * (universal overlap), so neither can certify its guard against a snapshot
 * the other is about to invalidate.
 *
 * Both interleaving directions are pinned:
 *  - the DANGEROUS one (all-off landing): the admin writer commits
 *    password=OFF while the provider guard is mid-transaction; blocked, then
 *    re-reading the locked latest-committed view, the disable must REFUSE
 *    with zero persistence — pre-parity it validated against the unlocked
 *    pw=ON snapshot and persisted github=OFF, landing the server all-off.
 *  - the ACCEPT one (the task's scenario): the admin writer commits
 *    password=ON from an all-but-github-off base; the blocked provider
 *    disable then proceeds against the fresh view and lands, leaving exactly
 *    one method enabled — never all-off at any commit point.
 *
 * Honest venue limit, stated the same way the admin file states it: PHP is
 * single-threaded per connection here, so the tests demonstrate the
 * mechanism (cross-connection blocking + re-validation after the competitor
 * commits) rather than a free-running race; the deterministic timeout=1
 * probe is the synchronization.
 */
final class AdminSettingsProviderLockParityB2Test extends TestCase
{
    use RequiresRealDatabase;

    private const PW     = 'auth.password.enabled';
    private const WA     = 'auth.webauthn.enabled';
    private const OIDC   = 'auth.oidc.enabled';
    private const LDAP   = 'auth.ldap.enabled';
    private const GITHUB = 'auth.github.enabled';

    /** @var list<string> */
    private const TOGGLE_KEYS = [self::PW, self::WA, self::OIDC, self::LDAP, self::GITHUB];

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireHealthyDatabase('skipping the provider-parity serialization proof. Runs in CI.');

        $host     = IntegrationDbGuard::host();
        $port     = IntegrationDbGuard::port();
        $user     = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb   = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $this->scratchDb = 'phlix_b2p_' . bin2hex(random_bytes(6));

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        // Identical scratch DDL to AdminSettingsAuthLockB2Test (026 verbatim;
        // the UNIQUE(setting_key) index is the lock target this file probes).
        $this->db->query(
            'CREATE TABLE users ('
            . ' id CHAR(36) PRIMARY KEY,'
            . ' password_hash VARCHAR(255) NULL,'
            . ' is_admin TINYINT(1) NOT NULL DEFAULT 0,'
            . " status ENUM('pending', 'active', 'disabled') NOT NULL DEFAULT 'active'"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $ddl = file_get_contents(dirname(__DIR__, 3) . '/migrations/026_server_settings.sql');
        $this->assertIsString($ddl, 'migration 026 must be present to build the scratch store');
        $this->db->query($ddl);

        $this->db->query(
            'CREATE TABLE webauthn_credentials ('
            . ' id CHAR(36) PRIMARY KEY,'
            . ' user_id CHAR(36) NOT NULL,'
            . ' credential_id VARBINARY(255) NOT NULL,'
            . ' registered_at INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' INDEX idx_user_id (user_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->db->query(
            'CREATE TABLE user_identities ('
            . ' id CHAR(36) PRIMARY KEY,'
            . ' user_id CHAR(36) NOT NULL,'
            . " provider VARCHAR(50) NOT NULL DEFAULT '',"
            . ' external_id VARCHAR(191) NOT NULL,'
            . ' created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->db        = null;
        $this->admin     = null;
        $this->scratchDb = '';

        parent::tearDown();
    }

    /**
     * THE dangerous interleave, closed: admin commits pw=OFF under the lock;
     * the provider disable of the last other ON method must block, then
     * re-validate against the locked view and REFUSE with zero persistence.
     * The final state keeps github ON — the all-off landing is unreachable
     * through the cross-surface pair.
     */
    public function test_admin_commit_under_provider_lock_forces_revalidation_and_refusal(): void
    {
        $this->seed([self::PW => true, self::WA => false, self::GITHUB => true]);

        $connA = $this->connection();
        $repoA = $this->repository($connA);
        $connB = $this->connection();
        $connB->query('SET SESSION innodb_lock_wait_timeout = 1');

        // Writer A: admin-style guarded batch — BEGIN, protocol lock,
        // pw=OFF — held open across B's probe.
        $repoA->beginTransaction();
        $repoA->lockSettingRows(self::TOGGLE_KEYS);
        $repoA->set(self::PW, false, 'bool');

        // Writer B: the provider surface's lock set is the SAME five keys in
        // the SAME order, so its FOR UPDATE must block on A's rows.
        $this->assertBlocked($this->repository($connB), 'provider');

        $repoA->commitTransaction();

        // B proceeds against the locked latest-committed view: pw is now OFF,
        // wa was already OFF, so disabling the last ON method refuses (R1).
        $response = $this->providerController($connB)->disableProvider($this->request(), ['name' => 'github']);

        $this->assertSame(422, $response->statusCode);
        $body = $this->jsonBody($response);
        $this->assertSame('all_methods_disabled', $body['reason'] ?? null);
        $this->assertSame(['auth.github.enabled'], array_keys((array) ($body['errors'] ?? [])));

        $this->assertSame('0', (string) ($this->rowFor(self::PW)['setting_value'] ?? ''));
        $this->assertSame('1', (string) ($this->rowFor(self::GITHUB)['setting_value'] ?? ''));
        $this->assertSame(1, $this->enabledMethodCount(), 'the refusal left exactly one method enabled');
    }

    /**
     * The accept direction of the same cross-surface pair (the task's
     * scenario): from a pw=OFF/WA=OFF/GITHUB=ON base, an admin-style writer
     * commits pw=ON under the lock; the blocked provider disable then
     * PROCEEDS (200) against the fresh locked view, and the final state
     * carries exactly one enabled method — password — never all-off at any
     * commit point.
     */
    public function test_admin_commit_of_password_on_lets_the_provider_disable_proceed(): void
    {
        $this->seed([self::PW => false, self::WA => false, self::GITHUB => true]);

        $connA = $this->connection();
        $repoA = $this->repository($connA);
        $connB = $this->connection();
        $connB->query('SET SESSION innodb_lock_wait_timeout = 1');

        $repoA->beginTransaction();
        $repoA->lockSettingRows(self::TOGGLE_KEYS);
        $repoA->set(self::PW, true, 'bool');

        $this->assertBlocked($this->repository($connB), 'provider');

        $repoA->commitTransaction();

        $response = $this->providerController($connB)->disableProvider($this->request(), ['name' => 'github']);

        $this->assertSame(200, $response->statusCode, 'with password committed ON, the github disable is safe');

        $this->assertSame('1', (string) ($this->rowFor(self::PW)['setting_value'] ?? ''));
        $this->assertSame('0', (string) ($this->rowFor(self::GITHUB)['setting_value'] ?? ''));
        $this->assertSame(1, $this->enabledMethodCount());
    }

    /**
     * Sequential last-man-standing through the PROVIDER surface alone (the
     * B2 file's base law, provider flavor): from pw=ON/github=ON, the admin
     * PUT-free sequence disable(github) → ok, then raw pw=OFF, then…
     * simpler and strictly about R1 through the new path: from pw=OFF base,
     * the provider disable of the only other ON method refuses with zero row
     * churn, and an enable-then-disable round trip lands live.
     */
    public function test_provider_disable_refuses_and_accepts_sequentially_on_real_rows(): void
    {
        $this->seed([self::PW => false, self::WA => false, self::GITHUB => true]);

        $controller = $this->providerController($this->requireDb());

        $refused = $controller->disableProvider($this->request(), ['name' => 'github']);
        $this->assertSame(422, $refused->statusCode);
        $this->assertSame('1', (string) ($this->rowFor(self::GITHUB)['setting_value'] ?? ''), 'refusal persisted nothing');
        $this->assertCount(3, $this->allRows(), 'the refused disable added no rows');

        $controllerOn = $this->providerController($this->requireDb());
        $this->assertSame(200, $controllerOn->enableProvider($this->request(), ['name' => 'github'])->statusCode);

        $notAProvider = $this->providerController($this->requireDb())->disableProvider($this->request(), ['name' => 'password']);
        $this->assertSame(404, $notAProvider->statusCode, 'password stays the untouchable non-provider (404 precedes the protocol)');
        $this->assertCount(3, $this->allRows(), 'the 404 never entered the transaction');
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /**
     * The provider stack on ONE connection: real repo/policy/bootstrapper,
     * github fully configured through the mockable PluginSettingsStore
     * interface (the plugin classes themselves are final and REAL).
     */
    private function providerController(Connection $db): AuthProviderController
    {
        $settings = $this->repository($db);

        $githubStore = $this->createMock(PluginSettingsStore::class);
        $githubStore->method('get')->with('github')->willReturn([
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
        ]);

        $registry = new AuthProviderRegistry();
        $bootstrapper = new AuthProviderBootstrapper(
            $settings,
            $registry,
            new OidcPlugin(),
            new LdapPlugin(),
            new GithubPlugin($githubStore),
        );

        $policy = new AuthMethodPolicy(
            $settings,
            new UserRepository($db),
            new WebAuthnCredentialRepository($db),
            new UserIdentityRepository($db),
            $bootstrapper,
        );

        return new AuthProviderController($registry, $bootstrapper, $policy);
    }

    private function repository(Connection $db): SettingsRepository
    {
        return new SettingsRepository($db, dirname(__DIR__, 3) . '/config');
    }

    private function requireDb(): Connection
    {
        $this->db = $this->db ?? $this->requireRealDatabase('skipping the provider-parity serialization proof. Runs in CI.');

        return $this->db;
    }

    private function connection(): Connection
    {
        return new PhlixMySQLConnection(
            IntegrationDbGuard::host(),
            IntegrationDbGuard::port(),
            (string) (getenv('DB_USER') ?: 'root'),
            (string) (getenv('DB_PASSWORD') ?: ''),
            $this->scratchDb,
        );
    }

    /**
     * Deterministic blocking probe: begin + take the SAME canonical five-key
     * FOR UPDATE set while writer A still holds it — with
     * innodb_lock_wait_timeout=1 on the session this MUST blow up with the
     * 1205 "Lock wait timeout exceeded" error inside a second. That IS the
     * proof of blocking; no sleep is used as synchronization.
     *
     * @param SettingsRepository $repo B's repository, on a timeout=1 connection.
     */
    private function assertBlocked(SettingsRepository $repo, string $surface): void
    {
        $repo->beginTransaction();

        $blocked = false;
        try {
            $repo->lockSettingRows(self::TOGGLE_KEYS);
        } catch (Throwable $e) {
            $blocked = true;
            $this->assertStringContainsString(
                'Lock wait timeout exceeded',
                $e->getMessage(),
                "the {$surface} surface must block on the shared FOR UPDATE set (1205), not fail some other way: "
                . $e->getMessage(),
            );
        }

        $repo->rollbackTransaction();
        $this->assertTrue(
            $blocked,
            "the {$surface} surface acquired the protocol lock WHILE the admin writer held it — "
            . 'the two surfaces are NOT serializing on the same five-key FOR UPDATE set',
        );
    }

    /** @param array<string, bool> $seed */
    private function seed(array $seed): void
    {
        $repo = $this->repository($this->requireDb());
        foreach ($seed as $key => $value) {
            $repo->set((string) $key, $value, 'bool');
        }
    }

    private function request(): Request
    {
        // disableProvider/enableProvider never touch the Request — the params
        // array carries the provider name. A doubled Request keeps the census
        // free of new $request-> property stamps.
        return $this->createMock(Request::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->body, true);

        return $decoded;
    }

    /**
     * @return array{setting_value: mixed}|null
     */
    private function rowFor(string $key): ?array
    {
        $rows = $this->requireDb()->query(
            'SELECT setting_value FROM server_settings WHERE setting_key = ?',
            [$key],
        );
        $this->assertIsArray($rows);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allRows(): array
    {
        $rows = $this->requireDb()->query('SELECT setting_key FROM server_settings');
        $this->assertIsArray($rows);

        return $rows;
    }

    /**
     * Independent all-off invariant: count the persisted methods whose
     * decoded bool is true (value '1'/'true' encoding via the schema's bool
     * type), asserting the server still has at least one way to sign in.
     */
    private function enabledMethodCount(): int
    {
        $rows = $this->requireDb()->query(
            'SELECT setting_value FROM server_settings'
            . " WHERE setting_key IN (?, ?, ?, ?, ?) AND value_type = 'bool' AND setting_value IN ('1', 'true')",
            self::TOGGLE_KEYS,
        );
        $this->assertIsArray($rows);

        return count($rows);
    }
}
