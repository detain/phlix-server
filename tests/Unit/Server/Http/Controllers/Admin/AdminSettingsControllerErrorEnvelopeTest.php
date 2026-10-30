<?php

/**
 * M-SERVER-05 law pins: production 500 envelopes from the admin settings
 * controller carry a GENERIC operator message; exception detail goes to the
 * server-side log and never onto the wire.
 *
 * The defect at ask-time: `index()` and `update()` (and the two auth-guard
 * fail-closed 500 arms) ended their catch blocks with
 * `'message' => $e->getMessage()`. Because a settings-store failure surfaces
 * as the `PDOException` re-thrown by
 * {@see \Phlix\Common\Database\PhlixMySQLConnection::query()} — whose message
 * is `'SQL:' . <the full statement> . <driver text>` — any DB blow-up
 * shipped the whole SQL statement (table names, column names, literals) in
 * the HTTP 500 body.
 *
 * These pins are deliberately mutation-toothed: every case plants an
 * exception whose message carries a unique SQL-shaped marker and then
 * asserts BOTH directions —
 *   - the wire: exact generic message, byte-identical per-arm `error`, and
 *     NOT A TRACE of the marker anywhere in the raw body;
 *   - the log: the marker and the exception class DID reach the configured
 *     stream handler (the estate's captured-logger idiom from
 *     AdminUpdatesRoutesTest: temp logger.php + LoggerFactory::init/reset).
 * Restoring `'message' => $e->getMessage()` reddens the wire pins; deleting
 * the `logFailure()` call reddens the log pins.
 *
 * The 422 auth-lockout arm is pinned as an intentional NON-target: its
 * `message` is the curated domain-validation text derived from the
 * `REASON_*` constants (never infrastructure exception text) and is part of
 * the byte-identical F7/B2 contract surface.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 *
 * @package Phlix\Tests\Unit\Server\Http\Controllers\Admin
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use PDOException;
use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

final class AdminSettingsControllerErrorEnvelopeTest extends TestCase
{
    /**
     * SQL-shaped secret planted in every thrown message. It must never be
     * found in a response body, and must always be found in the log file.
     */
    private const MARKER = 'SQL:SELECT secret_value FROM server_settings WHERE k = 0xDEADBEEF-M5ENV '
        . '[SECURE-MARKER-4f7a] SQLSTATE[HY000]: General error: 1146';

    private const GENERIC = 'An internal error occurred while handling the settings request. '
        . 'The details were logged server-side.';

    private string $tempDir = '';
    private string $logPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        LoggerFactory::reset();
        $this->tempDir = sys_get_temp_dir() . '/fix_srv05_env_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);
        $this->logPath = $this->tempDir . '/app.log';
        // Same captured-logger config shape AdminUpdatesRoutesTest writes.
        file_put_contents(
            $this->tempDir . '/logger.php',
            "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->logPath, true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n",
        );
        LoggerFactory::init($this->tempDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // index() — GET /api/v1/admin/settings, 500 arm
    // ------------------------------------------------------------------

    public function testIndexFiftyHundredCarriesGenericMessageAndLogsSqlDetail(): void
    {
        $store = $this->createMock(SettingsRepository::class);
        $store->method('getEffectiveMany')->willThrowException(new PDOException(self::MARKER));

        $response = (new AdminSettingsController($store))->index(new Request(), []);

        $this->assertSame(500, $response->statusCode);
        $this->assertWireEnvelopeIsSanitized($response->body, 'Failed to load settings');
        $this->assertLogged(self::MARKER, PDOException::class, 'Admin settings load failed');
    }

    // ------------------------------------------------------------------
    // update() — PUT /api/v1/admin/settings, outer Throwable arm
    // ------------------------------------------------------------------

    public function testUpdateFiftyHundredCarriesGenericMessageAndLogsSqlDetail(): void
    {
        $store = $this->createMock(SettingsRepository::class);
        $store->method('set')->willThrowException(new PDOException(self::MARKER));

        $response = (new AdminSettingsController($store))->update(
            $this->makeRequest(['hwaccel.enabled' => true]),
            [],
        );

        $this->assertSame(500, $response->statusCode);
        $this->assertWireEnvelopeIsSanitized($response->body, 'Failed to update settings');
        $this->assertLogged(self::MARKER, PDOException::class, 'Admin settings update failed');
    }

    // ------------------------------------------------------------------
    // updateAuthGuarded() — the two fail-closed 500 arms
    // ------------------------------------------------------------------

    public function testUnwiredGuardFiftyHundredCarriesGenericMessageAndLogsDetail(): void
    {
        // One constructor argument: the nullable policy stays unwired, so the
        // B2 protocol raises AuthMethodGuardUnwiredException after locking.
        $store = $this->createMock(SettingsRepository::class);

        $response = (new AdminSettingsController($store))->update(
            $this->makeRequest(['auth.webauthn.enabled' => false]),
            [],
        );

        $this->assertSame(500, $response->statusCode);
        $this->assertWireEnvelopeIsSanitized($response->body, 'Auth-method lock-out guard unavailable');
        $this->assertLogged(
            'The server cannot validate auth-method toggles without the auth policy service.',
            \Phlix\Auth\AuthMethodGuardUnwiredException::class,
            'Admin settings auth guard unwired',
        );
    }

    public function testGuardCheckFailureFiftyHundredDoesNotLeakWrappedSqlText(): void
    {
        // The wrapper preserves the ORIGINAL message verbatim
        // (AuthMethodPolicy:384) — here a PDOException carrying SQL — which
        // pre-M-SERVER-05 went straight onto the wire from this arm.
        $store = $this->createMock(SettingsRepository::class);
        $controller = new AdminSettingsController($store, $this->brokenPolicy());

        $response = $controller->update(
            $this->makeRequest(['auth.webauthn.enabled' => false]),
            [],
        );

        $this->assertSame(500, $response->statusCode);
        $this->assertWireEnvelopeIsSanitized($response->body, 'Auth-method policy check failed');
        // The logged exception is the WRAPPER class; its message is the
        // original SQL-bearing text, preserved verbatim (AuthMethodPolicy:384).
        $this->assertLogged(
            self::MARKER,
            \Phlix\Auth\AuthMethodGuardCheckFailedException::class,
            'Admin settings auth guard check failed',
        );
    }

    // ------------------------------------------------------------------
    // Non-target pin: the 422 lockout surface keeps its domain message
    // ------------------------------------------------------------------

    public function testLockoutFourTwentyTwoKeepsItsCuratedContractMessage(): void
    {
        $store = $this->settingsStore();
        $controller = new AdminSettingsController($store, $this->healthyPolicy());

        $response = $controller->update(
            $this->makeRequest(['auth.password.enabled' => false, 'auth.webauthn.enabled' => false]),
            [],
        );

        $body = json_decode($response->body, true);
        $this->assertSame(422, $response->statusCode);
        $this->assertSame('Validation failed', $body['error']);
        $this->assertSame('all_methods_disabled', $body['reason']);
        // The contract message is the curated REASON-derived domain text —
        // NOT the generic 500 string (this arm was deliberately not cured).
        $this->assertSame(
            'auth.method_lockout: at least one sign-in method must stay enabled',
            $body['message'],
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Shape + sanitization pins for every cured 500 arm: byte-identical
     * envelope keys, byte-identical per-arm `error`, exact generic
     * `message`, and zero trace of the exception text in the RAW body.
     */
    private function assertWireEnvelopeIsSanitized(string $rawBody, string $expectedError): void
    {
        $body = json_decode($rawBody, true);
        $this->assertIsArray($body);
        $this->assertSame(['success', 'error', 'message'], array_keys($body));
        $this->assertFalse($body['success']);
        $this->assertSame($expectedError, $body['error']);
        $this->assertSame(self::GENERIC, $body['message']);
        // Belt: the marker (SQL text) may not appear ANYWHERE in the raw body.
        $this->assertStringNotContainsString('SECURE-MARKER-4f7a', $rawBody);
        $this->assertStringNotContainsString('server_settings', $rawBody);
        $this->assertStringNotContainsString('SQL:', $rawBody);
    }

    private function assertLogged(string $marker, string $exceptionClass, string $event): void
    {
        $this->assertFileExists(
            $this->logPath,
            "expected the failure detail to be logged server-side (event {$event})",
        );
        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString($event, $log);
        $this->assertStringContainsString($marker, $log);
        // Monolog's LineFormatter JSON-encodes the context, so namespaced
        // class names appear in their JSON-escaped form ("Phlix\\Auth\\…").
        $escapedClass = str_replace('\\', '\\\\', $exceptionClass);
        $this->assertStringContainsString($escapedClass, $log);
    }

    private function makeRequest(array $settings): Request
    {
        $request = new Request();
        $request->body = ['settings' => $settings];

        return $request;
    }

    /**
     * A policy whose R2 admin probe blows up with the SQL-bearing exception,
     * AFTER passing the R1 all-off check (password stays enabled on paper).
     */
    private function brokenPolicy(): AuthMethodPolicy
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findActiveAdminsForLockoutProbe')
            ->willThrowException(new PDOException(self::MARKER));

        return new AuthMethodPolicy(
            $this->settingsStore(),
            $users,
            new WebAuthnCredentialRepository($this->createMock(Connection::class)),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );
    }

    /** A policy that answers normally, so the R1 refusal is reached. */
    private function healthyPolicy(): AuthMethodPolicy
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('findActiveAdminsForLockoutProbe');

        return new AuthMethodPolicy(
            $this->settingsStore(),
            $users,
            new WebAuthnCredentialRepository($this->createMock(Connection::class)),
            $this->createMock(UserIdentityRepository::class),
            $this->createMock(AuthProviderBootstrapper::class),
        );
    }

    /** Store whose defaults keep password+webauthn enabled (the state BEFORE). */
    private function settingsStore(): SettingsRepository
    {
        $store = $this->createMock(SettingsRepository::class);
        $store->method('getAllOverrides')->willReturn([]);
        $store->method('getDefault')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'auth.password.enabled', 'auth.webauthn.enabled' => true,
                default => false,
            },
        );
        $store->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        return $store;
    }
}
