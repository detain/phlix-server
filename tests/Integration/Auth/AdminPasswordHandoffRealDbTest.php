<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Database\MigrationRunner;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\Http\Controllers\Admin\AdminUserController;
use Phlix\Server\Http\Request;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * 2026-11-03 review server__srphp CRITICAL — admin-created and admin-reset
 * users could NEVER log in: `AdminUserController` pre-hashed the password
 * with `password_hash(..., ARGON2ID)` before handing it to
 * `UserRepository::create()`/`update()`, which hash AGAIN (their documented,
 * centrally-enforced contract — hashing is the repository's job, and the
 * update arm additionally stamps M-1 token revocation inside its own
 * UPDATE). Net stored value was `argon2(argon2(plaintext))`, so
 * `verifyPassword()`'s `password_verify(plaintext, stored)` permanently
 * failed: silent write-time data corruption for every account created or
 * reset through the admin API.
 *
 * ## Why real MySQL is the only venue (S345 rule 2 — the real artefact)
 *
 * The defect lives in the COMPOSITION of two honest halves (controller +
 * repository), each of which unit-tested fine in isolation — the repo's
 * double hash never ran behind `createMock(UserRepository::class)`, and the
 * unit test even pinned the wrong contract. This suite runs the REAL
 * controller over the REAL repository on a throwaway `phlix_admpw_*`
 * database built by the production MigrationRunner, and proves the cure by
 * the actual login: admin create → `AuthManager::login()` succeeds with the
 * same credentials; admin password reset → new password logs in, the old
 * one is refused (M-1 semantics stay intact).
 *
 * @see \Phlix\Tests\Integration\Auth\FirstAdminElectionBackfill109RealDbTest
 *      The scratch-DB/AuthManager harness pattern this follows.
 */
final class AdminPasswordHandoffRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const SCHEMA_MIGRATIONS = [
        '001_initial_schema.sql',
        '004_admin_user_flag.sql',
        '037_users_status.sql',
        '041_users_password_reset_fields.sql',
        '107_users_tokens_not_valid_after.sql',
    ];

    private const CREATE_PASSWORD = 'Admin-Create-Pass-1!';
    private const RESET_PASSWORD = 'Admin-Reset-Pass-2!';

    private ?Connection $admin = null;

    private ?Connection $db = null;

    private string $scratchDb = '';

    private string $fixDir = '';

    /** @var list<string> log dirs minted by logDir(), removed in tearDown(). */
    private array $mintedLogDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // S126 gate: skip on genuine absence, LOUD on reachable-but-unusable.
        $this->requireHealthyDatabase('skipping the admin password hand-off real-MySQL proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_admpw_' . $rand;

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        $this->fixDir = sys_get_temp_dir() . '/phlix-admpw-fix-' . $rand;
        mkdir($this->fixDir, 0o755, true);
        foreach (self::SCHEMA_MIGRATIONS as $file) {
            copy(dirname(__DIR__, 3) . '/migrations/' . $file, $this->fixDir . '/' . $file);
        }

        $result = $this->runner()->run();
        $this->assertSame(
            [],
            $result['errors'],
            'the schema migrations must apply cleanly on a fresh database: '
            . implode('; ', $result['errors'])
        );
    }

    protected function tearDown(): void
    {
        if ($this->fixDir !== '' && is_dir($this->fixDir)) {
            foreach ((array) glob($this->fixDir . '/*') as $file) {
                if (is_string($file)) {
                    unlink($file);
                }
            }
            rmdir($this->fixDir);
        }

        foreach ($this->mintedLogDirs as $dir) {
            // S439: each log dir is per-test — sweep them all.
            @unlink($dir . '/test.log');
            @rmdir($dir);
        }
        $this->mintedLogDirs = [];

        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->db = null;
        $this->admin = null;
        $this->scratchDb = '';
        $this->fixDir = '';

        parent::tearDown();
    }

    public function test_admin_created_user_can_log_in_with_the_same_credentials(): void
    {
        [$controller] = $this->wiredController();

        $response = $controller->create($this->jsonRequest([
            'username' => 'adminmade',
            'email' => 'adminmade@example.com',
            'password' => self::CREATE_PASSWORD,
            'is_admin' => false,
        ]));

        $this->assertSame(
            201,
            $response->statusCode,
            'the admin create rail must succeed: ' . (string) $response->body
        );

        // The stored hash must verify against the PLAINTEXT once, not against
        // an argon2-of-argon2: this is the exact predicate login depends on.
        $stored = (string) $this->passwordHashColumn('adminmade');
        $this->assertTrue(
            password_verify(self::CREATE_PASSWORD, $stored),
            'hash-once law at rest: UserRepository must have hashed the controller-handoff plaintext exactly once'
        );

        $login = $this->authManager()->login('adminmade', self::CREATE_PASSWORD, 'dev-handoff-1');
        $this->assertArrayHasKey('access_token', $login, 'an admin-created user must obtain a token');
    }

    public function test_admin_password_reset_leaves_the_new_password_loginable_and_kills_the_old(): void
    {
        [$controller, $manager] = $this->wiredController();

        $created = $controller->create($this->jsonRequest([
            'username' => 'adminreset',
            'email' => 'adminreset@example.com',
            'password' => self::CREATE_PASSWORD,
        ]));
        $this->assertSame(201, $created->statusCode);
        /** @var array<string, mixed> $createdBody */
        $createdBody = json_decode((string) $created->body, true);
        $userId = (string) $createdBody['user_id'];

        $updated = $controller->update($this->jsonRequest([
            'password' => self::RESET_PASSWORD,
        ]), ['id' => $userId]);
        $this->assertSame(200, $updated->statusCode, 'the admin update rail must succeed: ' . (string) $updated->body);

        $this->assertTrue(
            password_verify(self::RESET_PASSWORD, (string) $this->passwordHashColumn('adminreset')),
            'the reset must store a single hash of the NEW plaintext'
        );

        $login = $manager->login('adminreset', self::RESET_PASSWORD, 'dev-handoff-2');
        $this->assertIsArray($login);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid username or password');
        $manager->login('adminreset', self::CREATE_PASSWORD, 'dev-handoff-2');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * The controller wired PRODUCTION-faithfully: real UserRepository on the
     * scratch DB, real AuthManager as the policy carrier (AdminServicesProvider
     * binds it explicitly).
     *
     * @return array{0: AdminUserController, 1: AuthManager}
     */
    private function wiredController(): array
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $manager = $this->authManager();
        $repo = new UserRepository($db);

        return [new AdminUserController($repo, $manager), $manager];
    }

    private function authManager(): AuthManager
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return new AuthManager(
            new UserRepository($db),
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger($this->logDir()),
            null,
            $db,
        );
    }

    private function jsonRequest(array $body): Request
    {
        $request = new Request();
        $request->body = $body;

        return $request;
    }

    /** @return mixed the password_hash column of the named user */
    private function passwordHashColumn(string $username): mixed
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $rows = $db->query('SELECT password_hash FROM users WHERE username = ?', [$username]);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows, "user {$username} must exist to read its hash");

        return $rows[0]['password_hash'];
    }

    private function runner(): MigrationRunner
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return new MigrationRunner(static fn (): Connection => $db, $this->fixDir);
    }

    private function logDir(): string
    {
        $tmp = sys_get_temp_dir() . '/phlix_admpw_test_' . uniqid('', true);
        mkdir($tmp, 0775, true);
        $this->mintedLogDirs[] = $tmp;

        return $tmp;
    }

    private function silentLogger(string $dir): StructuredLogger
    {
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => ['type' => 'stream', 'path' => $dir . '/test.log', 'level' => 'debug'],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ]);
    }
}
