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
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * The v0.51.0 re-vendor seam, PROVEN ENDED — real MySQL, real vendored schema.
 *
 * ## Why this file exists (the deferred F7 integration duty)
 *
 * Admin-API admission of the eleven v0.51.0 settings keys is DERIVED, not
 * declared: `AdminSettingsController::allowedKeys()` reads
 * `vendor/detain/phlix-shared/schemas/server-settings.schema.json` at runtime.
 * Every pre-seam unit test proves that machinery against whatever schema was
 * vendored at authoring time (73 keys) — none of them can show the NEW keys
 * actually flow end-to-end: admission → type check → JSON-Schema
 * bounds/enum → F7 R1/R2 write-guard → `server_settings` persistence.
 *
 * This is the venue that closes that gap. It runs the controller against:
 *
 *  - the REAL vendored v0.51.0 schema (no synthetic key injection — the
 *    whole point is that the keys are admitted for real now),
 *  - a REAL `SettingsRepository` over a REAL MySQL `server_settings` table
 *    (production 026 DDL applied verbatim),
 *  - a REAL `AuthMethodPolicy` over REAL `UserRepository` /
 *    `WebAuthnCredentialRepository` / `UserIdentityRepository` reads.
 *
 * The one substituted collaborator is `AuthProviderBootstrapper`: its
 * `isConfigured()` answers plugin-settings truth (OIDC/LDAP/GitHub provider
 * construction), which is orthogonal to this seam and already pinned in
 * tests/Unit/Auth/AuthMethodPolicyTest.php. Mocking it keeps this file about
 * the schema admission + guard + persistence chain.
 *
 * SAFETY: every proof runs in a throwaway `phlix_s51_*` DATABASE created in
 * setUp and dropped in tearDown — never the shared `phlix_test` schema
 * (S345 rule: real artefacts, scratch databases; 112-lane pattern).
 */
final class AdminSettingsRealSchemaPutTest extends TestCase
{
    use RequiresRealDatabase;

    private const PW     = 'auth.password.enabled';
    private const WA     = 'auth.webauthn.enabled';
    private const OIDC   = 'auth.oidc.enabled';
    private const LDAP   = 'auth.ldap.enabled';
    private const GITHUB = 'auth.github.enabled';

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    protected function setUp(): void
    {
        parent::setUp();

        // S126 gate: skip on genuine absence, LOUD on reachable-but-unusable.
        $this->requireHealthyDatabase('skipping the v0.51.0 real-schema PUT proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $this->scratchDb = 'phlix_s51_' . bin2hex(random_bytes(6));

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        // users: the exact subset findActiveAdminsForLockoutProbe() reads
        // (post-091 shape: password_hash nullable). Production carries more
        // columns; none participate in the guard.
        $this->db->query(
            'CREATE TABLE users ('
            . ' id CHAR(36) PRIMARY KEY,'
            . ' password_hash VARCHAR(255) NULL,'
            . " is_admin TINYINT(1) NOT NULL DEFAULT 0,"
            . " status ENUM('pending', 'active', 'disabled') NOT NULL DEFAULT 'active'"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // server_settings: production migration 026 DDL applied VERBATIM — the
        // ENUM value_type vocabulary and the UNIQUE(setting_key) upsert target
        // are load-bearing for the persistence proofs below.
        $ddl = file_get_contents(dirname(__DIR__, 3) . '/migrations/026_server_settings.sql');
        $this->assertIsString($ddl, 'migration 026 must be present to build the scratch store');
        $this->db->query($ddl);

        // webauthn_credentials / user_identities: the two columns each R2
        // factor probe reads (countForUser: user_id; findByUserId: provider).
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

        $this->db = null;
        $this->admin = null;
        $this->scratchDb = '';

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Admission + persistence through the real vendored schema
    // ------------------------------------------------------------------

    public function testAuthQuintetKeyIsAdmittedByVendoredSchemaAndPersists(): void
    {
        // Pre-seam, this exact PUT answered 400 "Unknown setting key." because
        // the 73-key v0.49.1 schema did not declare it. A 200 here IS the seam
        // closure, observed on the live admission path.
        $response = $this->controller()->update($this->put([self::PW => true]), []);

        $this->assertSame(200, $response->statusCode, 'auth.password.enabled must be PUT-able post-re-vendor');

        $row = $this->rowFor(self::PW);
        $this->assertNotNull($row, 'the accepted write must land in server_settings');
        $this->assertSame('1', (string) $row['setting_value'], 'bool true encodes as "1"');
        $this->assertSame('bool', (string) $row['value_type']);

        // Round-trip through the repository the enforcement path reads.
        $override = $this->settingsRepository()->getOverride(self::PW);
        $this->assertIsArray($override);
        $this->assertTrue($override['value']);
    }

    public function testMetadataFloatAndIntegerKeysPassSchemaBoundsAndPersist(): void
    {
        // -0.5 violates the schema's `minimum: 0` — the JUSTINRAINBOW stage
        // (validateAgainstSchema), not the type stage (-0.5 is a valid float),
        // rejects it. This is the bounds proof the W3 docblocks promised.
        $bad = $this->controller()->update($this->put(['metadata.min_match_confidence' => -0.5]), []);
        $this->assertSame(400, $bad->statusCode, 'below the schema minimum must be rejected');
        $this->assertArrayHasKey(
            'metadata.min_match_confidence',
            $this->jsonBody($bad)['errors'] ?? [],
            'the rejection must name the offending key'
        );
        $this->assertNull($this->rowFor('metadata.min_match_confidence'), 'a rejected write persists nothing');

        // 0.5 is inside 0..1 → admitted, persisted as float.
        $good = $this->controller()->update($this->put(['metadata.min_match_confidence' => 0.5]), []);
        $this->assertSame(200, $good->statusCode);
        $row = $this->rowFor('metadata.min_match_confidence');
        $this->assertNotNull($row);
        $this->assertSame('0.5', (string) $row['setting_value']);
        $this->assertSame('float', (string) $row['value_type']);

        // W3's TTL key, same seam: in-range integer persists.
        $ttl = $this->controller()->update($this->put(['metadata.cache_ttl_hours' => 48]), []);
        $this->assertSame(200, $ttl->statusCode);
        $this->assertSame('48', (string) ($this->rowFor('metadata.cache_ttl_hours')['setting_value'] ?? ''));
    }

    public function testSecurityBoundsAndEnumAreEnforcedByTheVendoredSchema(): void
    {
        // 99,999,999 > schema maximum 31,536,000 → 400 before anything persists.
        $over = $this->controller()->update($this->put(['security.hsts_max_age_seconds' => 99999999]), []);
        $this->assertSame(400, $over->statusCode, 'schema bounds must be LIVE on PUT, not display-only');
        $this->assertNull($this->rowFor('security.hsts_max_age_seconds'));

        // frame_options is enum DENY|SAMEORIGIN|NONE → off-enum string rejected.
        $enum = $this->controller()->update($this->put(['security.frame_options' => 'SOMETIMES']), []);
        $this->assertSame(400, $enum->statusCode, 'schema enum must be enforced on PUT');
        $this->assertNull($this->rowFor('security.frame_options'));

        // In-bounds / in-enum pair accepted.
        $ok = $this->controller()->update(
            $this->put(['security.hsts_max_age_seconds' => 600, 'security.frame_options' => 'DENY']),
            []
        );
        $this->assertSame(200, $ok->statusCode);
        $this->assertSame('600', (string) ($this->rowFor('security.hsts_max_age_seconds')['setting_value'] ?? ''));
        $this->assertSame('DENY', (string) ($this->rowFor('security.frame_options')['setting_value'] ?? ''));
    }

    public function testWrongTypeForBoolKeyIsRejectedBeforeTheGuardAndPersistsNothing(): void
    {
        // 1.5 is a float; the allow-list type for the quintet is bool, and
        // 1.5 is not in the bool-ish set — 400 "Expected type bool." from the
        // type stage, guard never reached, nothing persisted.
        $response = $this->controller()->update($this->put([self::PW => 1.5]), []);

        $this->assertSame(400, $response->statusCode);
        $body = $this->jsonBody($response);
        $this->assertSame('Expected type bool.', $body['errors'][self::PW] ?? null);
        $this->assertNull($this->rowFor(self::PW));
    }

    // ------------------------------------------------------------------
    // F7 guards THROUGH the admitted path
    // ------------------------------------------------------------------

    public function testTurningAllFiveAuthMethodsOffIsRefusedAndPersistsNothing(): void
    {
        // R1: the proposed state is all-false. The keys are schema-ADMITTED and
        // individually well-typed booleans — only the F7 guard can be what
        // rejects here, which is exactly the seam's point: the guard now runs
        // on REAL admin-API writes, not only synthetic unit payloads.
        $controller = $this->controller();

        $response = $controller->update($this->put([
            self::PW     => false,
            self::WA     => false,
            self::OIDC   => false,
            self::LDAP   => false,
            self::GITHUB => false,
        ]), []);

        $this->assertSame(422, $response->statusCode);
        $body = $this->jsonBody($response);
        $this->assertSame('all_methods_disabled', $body['reason'] ?? null);

        // The atomicity law: the WHOLE batch is rejected before the first set().
        $rows = $this->db?->query('SELECT setting_key FROM server_settings');
        $this->assertIsArray($rows);
        $this->assertCount(0, $rows, 'a guard-rejected batch must persist ZERO rows');
    }

    public function testRemovingTheLastFactorFromAnActiveAdminIsRefused(): void
    {
        // R2: an active admin with NO password hash. Proposal keeps the store
        // legal (not all-off: webauthn + oidc stay on) but strands the admin —
        // no passkey rows, and the (configured) OIDC identity list is empty.
        $this->db?->query(
            "INSERT INTO users (id, password_hash, is_admin, status) VALUES ('a1', NULL, 1, 'active')"
        );

        $controller = $this->controller(configured: [AuthProviderBootstrapper::OIDC => true]);

        $response = $controller->update($this->put([
            self::PW   => false,
            self::WA   => true,
            self::OIDC => true,
        ]), []);

        $this->assertSame(422, $response->statusCode);
        $body = $this->jsonBody($response);
        $this->assertSame('admin_lockout', $body['reason'] ?? null);

        $rows = $this->db?->query('SELECT setting_key FROM server_settings');
        $this->assertIsArray($rows);
        $this->assertCount(0, $rows, 'the R2 refusal must persist nothing');
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /**
     * Wire the controller over the REAL vendored schema and the scratch DB.
     *
     * @param array<string, bool> $configured provider-configuredness answers
     *        for the bootstrapper probe (default: none configured).
     */
    private function controller(array $configured = []): AdminSettingsController
    {
        $settings = $this->settingsRepository();

        $bootstrapper = $this->createMock(AuthProviderBootstrapper::class);
        $bootstrapper->method('isConfigured')->willReturnCallback(
            static fn (string $provider): bool => ($configured[$provider] ?? false) === true
        );

        $policy = new AuthMethodPolicy(
            $settings,
            new UserRepository($this->requireDb()),
            new WebAuthnCredentialRepository($this->requireDb()),
            new UserIdentityRepository($this->requireDb()),
            $bootstrapper,
        );

        return new AdminSettingsController($settings, $policy);
    }

    private function settingsRepository(): SettingsRepository
    {
        return new SettingsRepository($this->requireDb(), dirname(__DIR__, 3) . '/config');
    }

    private function requireDb(): Connection
    {
        $this->db = $this->db ?? $this->requireRealDatabase('skipping the v0.51.0 real-schema PUT proof. Runs in CI.');

        return $this->db;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function put(array $settings): Request
    {
        $request = new Request();
        /** @var array<string, mixed> $body */
        $body = ['settings' => $settings];
        $request->body = $body;

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(\Phlix\Server\Http\Response $response): array
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
}
