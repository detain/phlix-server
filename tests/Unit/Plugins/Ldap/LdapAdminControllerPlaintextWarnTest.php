<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Ldap;

use Phlix\Plugins\Ldap\Controller\LdapAdminController;
use Phlix\Plugins\Ldap\Plugin;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Unit\Plugins\Github\InMemoryPluginSettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * minors M-SERVER-78 (W-min108) — the LDAP admin save surface must speak its
 * plaintext-bind posture out loud. `ssl=false` means the directory bind rides
 * unencrypted (start_tls in this stack is diagnostic-only — the review's own
 * premise), yet the save answered a bare success with zero operator feedback.
 * The ask's stated minimum — 'or at minimum warn on save' — is exactly what is
 * shipped: a server-side error_log warning on the SUCCESS path. The response
 * envelope stays byte-ident and no connection semantics change (auto-TLS was
 * the ask's ALTERNATIVE and is consumer-visible; deliberately unshipped).
 *
 * Harness mirrors GithubAdminControllerTest: real controller + real Plugin over
 * the in-memory settings store, error_log redirected to a temp file.
 */
final class LdapAdminControllerPlaintextWarnTest extends TestCase
{
    private const WARNING_NEEDLE = 'UNENCRYPTED bind posture';

    private InMemoryPluginSettingsRepository $store;
    private LdapAdminController $controller;
    private string $logFile = '';
    private string $oldLog = '';

    protected function setUp(): void
    {
        $this->store = new InMemoryPluginSettingsRepository();
        $this->controller = new LdapAdminController(new Plugin($this->store));
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'phlix_ldap_s78_');
        $this->oldLog = (string) (ini_get('error_log') ?: '');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->oldLog);
        @unlink($this->logFile);
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Response
    {
        $request = new Request();
        $request->body = $body;

        return $this->controller->saveSettings($request, []);
    }

    /** @return array<string, mixed> */
    private function decoded(Response $response): array
    {
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function loggedText(): string
    {
        return (string) @file_get_contents($this->logFile);
    }

    public function testPlaintextSaveWarnsServerSideAndKeepsTheEnvelopeByteIdentical(): void
    {
        $response = $this->post([
            'host' => 'ldap.example.test',
            'base_dn' => 'dc=corp,dc=example,dc=test',
            'port' => 389,
        ]);

        self::assertSame(200, $response->statusCode);
        self::assertSame('Settings saved successfully', $this->decoded($response)['message'] ?? null);
        self::assertStringContainsString(self::WARNING_NEEDLE, $this->loggedText());
        self::assertStringContainsString('port=389', $this->loggedText());
    }

    public function testExplicitlyAbsentSslIsTheSamePlaintextPostureAndAlsoWarns(): void
    {
        // The S117 wholesale-replace merge means an ABSENT ssl key preserves a
        // stored true — but a FRESH install has nothing stored, so absent here
        // must still land on the warning path.
        $response = $this->post([
            'host' => 'ldap.example.test',
            'base_dn' => 'ou=people,dc=example,dc=test',
        ]);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString(self::WARNING_NEEDLE, $this->loggedText());
    }

    public function testEncryptedSaveIsSilent(): void
    {
        $response = $this->post([
            'host' => 'ldaps.example.test',
            'base_dn' => 'dc=corp,dc=example,dc=test',
            'port' => 636,
            'ssl' => true,
        ]);

        self::assertSame(200, $response->statusCode);
        self::assertStringNotContainsString(self::WARNING_NEEDLE, $this->loggedText());
    }

    public function testPreservedStoredEncryptionDoesNotWarn(): void
    {
        $this->post([
            'host' => 'ldaps.example.test',
            'base_dn' => 'dc=corp,dc=example,dc=test',
            'port' => 636,
            'ssl' => true,
        ]);
        @unlink($this->logFile);
        touch($this->logFile);

        // S117: ssl absent from THIS body preserves the stored true — the merge,
        // not the warning, owns that law, and the preserved-encrypted save must
        // stay silent.
        $response = $this->post([
            'host' => 'ldaps2.example.test',
            'base_dn' => 'dc=corp,dc=example,dc=test',
        ]);

        self::assertSame(200, $response->statusCode);
        self::assertStringNotContainsString(self::WARNING_NEEDLE, $this->loggedText());
    }

    public function testRejectedSaveNeitherWarnsNorStores(): void
    {
        $response = $this->post(['host' => '', 'base_dn' => 'dc=example,dc=test']);

        self::assertSame(400, $response->statusCode);
        self::assertSame('missing_host', $this->decoded($response)['error'] ?? null);
        self::assertStringNotContainsString(self::WARNING_NEEDLE, $this->loggedText());
        self::assertSame([], $this->store->rows);
    }
}
