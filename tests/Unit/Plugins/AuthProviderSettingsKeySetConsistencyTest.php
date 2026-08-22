<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use Phlix\Plugins\Ldap\Controller\LdapAdminController;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Oidc\Controller\OidcAdminController;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * S337 — the saveSettings() replacement document is DERIVED from the settings
 * schema's `properties` list, so the two sets can never silently diverge.
 *
 * The defect this pins: `saveSettings()` used to merge a hand-enumerated key
 * literal, which was sound only because `getSchema()` happened to enumerate the
 * same keys. A key added to the schema (or to the save literal) alone would have
 * been silently dropped on every save (or never stored at all). This test asserts
 * the LIVE property in both directions — every schema property key is emitted by
 * a save, and nothing else is — with both key sets printed when it fails, so a
 * divergence names itself instead of being buried in an assertion diff.
 *
 * The write-only secrets (`bind_pw` / `client_secret`) are the schema's
 * `writeOnly` properties. They ARE part of the write path — supplied non-blank
 * they are stored, blank they preserve the stored secret — so a full save must
 * emit them too; only the blank-and-nothing-stored case legitimately omits them
 * (pinned by the second pair of tests and by the S117 real-DB suite).
 */
final class AuthProviderSettingsKeySetConsistencyTest extends TestCase
{
    private string $ldapDir = '';

    private string $oidcDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ldapDir = sys_get_temp_dir() . '/phlix_s337_ldap_' . uniqid();
        mkdir($this->ldapDir, 0755, true);
        $this->oidcDir = sys_get_temp_dir() . '/phlix_s337_oidc_' . uniqid();
        mkdir($this->oidcDir, 0755, true);
        LdapPlugin::setPluginDirectory($this->ldapDir);
        OidcPlugin::setPluginDirectory($this->oidcDir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->ldapDir, $this->oidcDir] as $dir) {
            if ($dir !== '') {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
            }
        }
        LdapPlugin::setPluginDirectory(dirname(__DIR__, 3) . '/src/Plugins/Ldap');
        OidcPlugin::setPluginDirectory(dirname(__DIR__, 3) . '/src/Plugins/Oidc');

        parent::tearDown();
    }

    public function testLdapSaveEmitsExactlyTheSchemaPropertyKeys(): void
    {
        $plugin = new LdapPlugin();
        $controller = new LdapAdminController($plugin);
        $schemaKeys = $this->schemaKeys($controller);
        $this->assertNotEmpty($schemaKeys);

        $response = $controller->saveSettings($this->post([
            'host' => 'dc01.corp.example.org',
            'port' => 636,
            'ssl' => true,
            'base_dn' => 'dc=corp,dc=example,dc=org',
            'bind_dn' => 'cn=phlix-svc,dc=corp,dc=example,dc=org',
            'bind_pw' => 'full-save-secret',
            'user_filter' => '(sAMAccountName={{username}})',
            'admin_group' => 'cn=Phlix Admins,dc=corp,dc=example,dc=org',
        ]), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);
        $this->assertEmittedKeysEqualSchemaKeys($schemaKeys, $plugin->getSettings(), 'LDAP');
    }

    public function testOidcSaveEmitsExactlyTheSchemaPropertyKeys(): void
    {
        $plugin = new OidcPlugin();
        $controller = new OidcAdminController($plugin);
        $schemaKeys = $this->schemaKeys($controller);
        $this->assertNotEmpty($schemaKeys);

        $response = $controller->saveSettings($this->post([
            'provider_url' => 'https://idp.example.org',
            'client_id' => 'cid',
            'client_secret' => 'full-save-secret',
            'scopes' => 'openid profile email groups',
            'redirect_uri' => 'https://media.example.org/auth/oidc/callback',
        ]), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);
        $this->assertEmittedKeysEqualSchemaKeys($schemaKeys, $plugin->getSettings(), 'OIDC');
    }

    public function testLdapBlankSecretIsOmittedWhenNothingIsStored(): void
    {
        $plugin = new LdapPlugin();
        $controller = new LdapAdminController($plugin);
        $schemaKeys = $this->schemaKeys($controller);

        $response = $controller->saveSettings($this->post([
            'host' => 'dc01.corp.example.org',
            'base_dn' => 'dc=corp,dc=example,dc=org',
        ]), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);
        $emittedKeys = array_keys($plugin->getSettings());
        $this->assertSame(
            self::sorted(array_values(array_diff($schemaKeys, ['bind_pw']))),
            self::sorted($emittedKeys),
            'LDAP: a blank bind_pw with nothing stored must be omitted — only the schema\'s writeOnly '
            . "secret may be absent.\nSchema keys: " . json_encode($schemaKeys)
            . "\nEmitted keys: " . json_encode($emittedKeys),
        );
    }

    public function testOidcBlankSecretIsOmittedWhenNothingIsStored(): void
    {
        $plugin = new OidcPlugin();
        $controller = new OidcAdminController($plugin);
        $schemaKeys = $this->schemaKeys($controller);

        $response = $controller->saveSettings($this->post([
            'provider_url' => 'https://idp.example.org',
            'client_id' => 'cid',
        ]), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);
        $emittedKeys = array_keys($plugin->getSettings());
        $this->assertSame(
            self::sorted(array_values(array_diff($schemaKeys, ['client_secret']))),
            self::sorted($emittedKeys),
            'OIDC: a blank client_secret with nothing stored must be omitted — only the schema\'s '
            . "writeOnly secret may be absent.\nSchema keys: " . json_encode($schemaKeys)
            . "\nEmitted keys: " . json_encode($emittedKeys),
        );
    }

    /**
     * Both directions of the guard, with the compared key sets printed when it
     * fails (S345 lesson 3 — a set guard must name its denominators).
     *
     * @param list<string>        $schemaKeys
     * @param array<string, mixed> $emitted
     */
    private function assertEmittedKeysEqualSchemaKeys(array $schemaKeys, array $emitted, string $provider): void
    {
        $emittedKeys = array_keys($emitted);

        $missingFromSavePath = array_values(array_diff($schemaKeys, $emittedKeys));
        $this->assertSame(
            [],
            $missingFromSavePath,
            "{$provider}: schema properties missing from the save path — a schema-only key is silently "
            . "dropped on every save.\nSchema keys: " . json_encode($schemaKeys)
            . "\nEmitted keys: " . json_encode($emittedKeys),
        );

        $strayFromSavePath = array_values(array_diff($emittedKeys, $schemaKeys));
        $this->assertSame(
            [],
            $strayFromSavePath,
            "{$provider}: save path emitted keys that are absent from the schema — a save-literal-only "
            . "key is never declared to the admin UI.\nSchema keys: " . json_encode($schemaKeys)
            . "\nEmitted keys: " . json_encode($emittedKeys),
        );

        $this->assertSame(
            self::sorted($schemaKeys),
            self::sorted($emittedKeys),
            "{$provider}: save path must emit exactly the schema's property keys.\nSchema keys: "
            . json_encode($schemaKeys) . "\nEmitted keys: " . json_encode($emittedKeys),
        );
    }

    /**
     * @return list<string>
     */
    private function schemaKeys(LdapAdminController|OidcAdminController $controller): array
    {
        $response = $controller->getSchema(new Request(), []);
        $this->assertSame(200, $response->statusCode);
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $schema = is_array($decoded['schema'] ?? null) ? $decoded['schema'] : null;
        $this->assertIsArray($schema);
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : null;
        $this->assertIsArray($properties);

        return array_keys($properties);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->body = $body;

        return $request;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function sorted(array $keys): array
    {
        $sorted = $keys;
        sort($sorted);

        return $sorted;
    }
}
