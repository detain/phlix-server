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
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * B2 on REAL MySQL: the auth-lockout guard's FOR UPDATE serialization.
 *
 * Venue shape cloned from {@see AdminSettingsRealSchemaPutTest} (production
 * 026 DDL verbatim, real vendored schema, throwaway `phlix_b2_*` scratch
 * DATABASE, S345 rule). What this file proves that the unit locks in
 * AdminSettingsControllerAuthLockTest cannot: the locks are REAL InnoDB row
 * locks that block a second connection, and the re-validated guard actually
 * refuses the doomed second toggle after the first writer commits.
 *
 *  - Base law (passes pre-B2 too, pinned as the sequential foundation): from
 *    a pw+wa ON base, PUT pw=false lands, then PUT wa-off is refused 422
 *    with zero additional rows.
 *  - Two-connection probe (the B2 law, no sleeps-as-sync): connection A holds
 *    the protocol open mid-transaction; connection B's lock attempt MUST
 *    block (proved deterministically by a 1-second `innodb_lock_wait_timeout`
 *    blowing up with error 1205/1213 while A's txn is live); A commits
 *    pw=false; B's controller PUT wa=false is then refused against the locked
 *    latest-committed view — the exact interleaving the reviewer documented
 *    can no longer land the server all-off.
 */
final class AdminSettingsAuthLockB2Test extends TestCase
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

        $this->requireHealthyDatabase('skipping the B2 auth-lock serialization proof. Runs in CI.');

        $host     = IntegrationDbGuard::host();
        $port     = IntegrationDbGuard::port();
        $user     = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb   = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $this->scratchDb = 'phlix_b2_' . bin2hex(random_bytes(6));

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        // users / server_settings / webauthn_credentials / user_identities —
        // identical scratch DDL to AdminSettingsRealSchemaPutTest (026 verbatim;
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

        $this->db    = null;
        $this->admin = null;
        $this->scratchDb = '';

        parent::tearDown();
    }

    /**
     * Base law — sequential double-PUT. Also true pre-B2 (a single connection
     * never races); pinned so the concurrency proof below stands on the same
     * refusal semantics: second toggle refused, zero additional rows.
     */
    public function test_sequential_double_disable_refuses_the_last_man_standing(): void
    {
        $controller = $this->controller($this->requireDb());

        $this->assertSame(200, $controller->update($this->put([self::PW => true]), [])->statusCode);
        $this->assertSame(200, $controller->update($this->put([self::WA => true]), [])->statusCode);

        $first = $controller->update($this->put([self::PW => false]), []);
        $this->assertSame(200, $first->statusCode, 'password may go OFF while webauthn stays ON');
        $this->assertSame('0', (string) ($this->rowFor(self::PW)['setting_value'] ?? ''));

        $second = $controller->update($this->put([self::WA => false]), []);
        $this->assertSame(422, $second->statusCode, 'the last enabled method must not go OFF');
        $body = $this->jsonBody($second);
        $this->assertSame('all_methods_disabled', $body['reason'] ?? null);

        $this->assertSame('1', (string) ($this->rowFor(self::WA)['setting_value'] ?? ''));
        $this->assertCount(2, $this->allRows(), 'the refused PUT must add no rows');
    }

    /**
     * B2 law — real cross-connection row locks on the protocol's FOR UPDATE.
     *
     * Deterministic sync primitive: `innodb_lock_wait_timeout = 1` on B turns
     * "blocked by A's lock" into a thrown 1205/1213 inside one second — no
     * sleeps are used as synchronization anywhere in this test.
     */
    public function test_second_connection_blocks_on_the_protocol_lock_and_revalidates_after_commit(): void
    {
        $seed = $this->controller($this->requireDb());
        $this->assertSame(200, $seed->update($this->put([self::PW => true]), [])->statusCode);
        $this->assertSame(200, $seed->update($this->put([self::WA => true]), [])->statusCode);

        $host     = IntegrationDbGuard::host();
        $port     = IntegrationDbGuard::port();
        $user     = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');

        // Writer A: the full guarded batch — BEGIN, protocol lock, write
        // pw=OFF, COMMIT — held open across B's probe.
        $connA = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);
        $repoA = new SettingsRepository($connA, dirname(__DIR__, 3) . '/config');
        $repoA->beginTransaction();
        $repoA->lockSettingRows(self::TOGGLE_KEYS);
        $repoA->set(self::PW, false, 'bool');

        // Writer B (separate connection): the same protocol lock MUST block
        // while A's transaction holds the row locks.
        $connB = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);
        $connB->query('SET SESSION innodb_lock_wait_timeout = 1');
        $repoB = new SettingsRepository($connB, dirname(__DIR__, 3) . '/config');
        $repoB->beginTransaction();

        $blocked = false;
        try {
            $repoB->lockSettingRows(self::TOGGLE_KEYS);
        } catch (Throwable $e) {
            $blocked = true;
            $this->assertStringContainsString(
                'Lock wait timeout exceeded',
                $e->getMessage(),
                'B must be blocked by A FOR UPDATE row locks (1205), not error some other way: ' . $e->getMessage(),
            );
        }

        $repoB->rollbackTransaction();
        $this->assertTrue($blocked, 'B acquired the protocol lock WHILE A held it — FOR UPDATE is not locking');

        // A commits pw=OFF. B's controller PUT of wa=OFF must now re-validate
        // against the committed view and refuse: the all-off landing is gone.
        $repoA->commitTransaction();

        $controllerB = $this->controller($connB);
        $response    = $controllerB->update($this->put([self::WA => false]), []);

        $this->assertSame(422, $response->statusCode);
        $body = $this->jsonBody($response);
        $this->assertSame('all_methods_disabled', $body['reason'] ?? null);

        $this->assertSame('0', (string) ($this->rowFor(self::PW)['setting_value'] ?? ''));
        $this->assertSame('1', (string) ($this->rowFor(self::WA)['setting_value'] ?? ''));
        $this->assertCount(2, $this->allRows(), 'only the seeded rows exist; B persisted nothing');
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    private function controller(Connection $db): AdminSettingsController
    {
        $settings     = new SettingsRepository($db, dirname(__DIR__, 3) . '/config');
        $bootstrapper = $this->createMock(AuthProviderBootstrapper::class);
        $bootstrapper->method('isConfigured')->willReturn(false);

        $policy = new AuthMethodPolicy(
            $settings,
            new UserRepository($db),
            new WebAuthnCredentialRepository($db),
            new UserIdentityRepository($db),
            $bootstrapper,
        );

        return new AdminSettingsController($settings, $policy);
    }

    private function requireDb(): Connection
    {
        $this->db = $this->db ?? $this->requireRealDatabase('skipping the B2 auth-lock serialization proof. Runs in CI.');

        return $this->db;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function put(array $settings): Request
    {
        $request       = new Request();
        /** @var array<string, mixed> $body */
        $body          = ['settings' => $settings];
        $request->body = $body;

        return $request;
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
     * @return array{setting_value: mixed, value_type: mixed}|null
     */
    private function rowFor(string $key): ?array
    {
        $rows = $this->requireDb()->query(
            'SELECT setting_value, value_type FROM server_settings WHERE setting_key = ?',
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
}
