<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Plugins;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Uuid;
use Phlix\Plugins\Ldap\Controller\LdapAdminController;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Oidc\Controller\OidcAdminController;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;
use Phlix\Plugins\Repository\PluginSettingsRepository;
use Phlix\Server\Http\Request;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S337 — the round-trip proof for the schema-derived save path.
 *
 * `saveSettings()` now builds the replacement `plugin_settings.settings_json`
 * document from the schema's `properties` list instead of a hand-enumerated key
 * literal. This suite drives the REAL controllers through the REAL
 * {@see PluginSettingsRepository} against REAL MySQL and reads the row back out
 * of the table, asserting that EVERY property the schema declares — including the
 * write-only secrets — survives a save round-trip. A key added to the schema
 * (with its normalizer) survives automatically; the negative control below pins
 * the other direction: a body key the schema does not declare is NOT written.
 *
 * The scaffold mirrors
 * {@see AuthProviderSettingsPreservationRealDbIntegrationTest} (S117): per-run
 * `plugin_name` keys and a scratch plugin dir, so a developer's real provider
 * rows and files are never touched.
 */
final class AuthProviderSettingsSchemaRoundTripRealDbIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $ldapKey = '';

    private string $oidcKey = '';

    private string $pluginDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S337 schema round-trip real-DB test. Runs in CI.');
        $this->assertNotNull($this->db);
        LoggerFactory::reset();
        LoggerFactory::init(dirname(__DIR__, 3) . '/config/logger.php');

        $token = substr(Uuid::v4(), 0, 8);
        $this->ldapKey = 's337-ldap-' . $token;
        $this->oidcKey = 's337-oidc-' . $token;

        $this->pluginDir = sys_get_temp_dir() . '/phlix_s337_rt_' . $token;
        mkdir($this->pluginDir, 0755, true);
        LdapPlugin::setPluginDirectory($this->pluginDir);
        OidcPlugin::setPluginDirectory($this->pluginDir);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            foreach ([$this->ldapKey, $this->oidcKey] as $key) {
                if ($key !== '') {
                    $db->query('DELETE FROM plugin_settings WHERE plugin_name = ?', [$key]);
                }
            }
        }
        foreach (glob($this->pluginDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->pluginDir);
        LdapPlugin::setPluginDirectory(dirname(__DIR__, 3) . '/src/Plugins/Ldap');
        OidcPlugin::setPluginDirectory(dirname(__DIR__, 3) . '/src/Plugins/Oidc');

        parent::tearDown();
    }

    /**
     * Every LDAP schema property — host, port, ssl, base_dn, bind_dn, the
     * writeOnly bind_pw, user_filter, admin_group — must survive a save and come
     * back out of the REAL `plugin_settings` row.
     */
    public function testEveryLdapSchemaPropertySurvivesASaveRoundTrip(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $controller = new LdapAdminController($this->ldapPlugin($repo));

        $response = $controller->saveSettings($this->post([
            'host' => 'dc01.corp.example.org',
            'port' => 636,
            'ssl' => true,
            'base_dn' => 'dc=corp,dc=example,dc=org',
            'bind_dn' => 'cn=phlix-svc,dc=corp,dc=example,dc=org',
            'bind_pw' => 'ROUND-TRIP-LDAP-SECRET',
            'user_filter' => '(sAMAccountName={{username}})',
            'admin_group' => 'cn=Phlix Admins,dc=corp,dc=example,dc=org',
        ]), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);

        $row = $this->readRow($this->ldapKey);
        $this->assertSame('dc01.corp.example.org', $row['host'] ?? null);
        $this->assertSame(636, $row['port'] ?? null, 'port must survive as the int the schema declares');
        $this->assertTrue($row['ssl'] ?? null, 'ssl must survive as the bool the schema declares');
        $this->assertSame('dc=corp,dc=example,dc=org', $row['base_dn'] ?? null);
        $this->assertSame('cn=phlix-svc,dc=corp,dc=example,dc=org', $row['bind_dn'] ?? null);
        $this->assertSame(
            'ROUND-TRIP-LDAP-SECRET',
            $row['bind_pw'] ?? null,
            'the writeOnly bind_pw must survive a save that supplies it',
        );
        $this->assertSame('(sAMAccountName={{username}})', $row['user_filter'] ?? null);
        $this->assertSame('cn=Phlix Admins,dc=corp,dc=example,dc=org', $row['admin_group'] ?? null);
    }

    /**
     * Every OIDC schema property — provider_url, client_id, the writeOnly
     * client_secret, scopes, redirect_uri — must survive a save round-trip.
     */
    public function testEveryOidcSchemaPropertySurvivesASaveRoundTrip(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $controller = new OidcAdminController($this->oidcPlugin($repo));

        $response = $controller->saveSettings($this->post([
            'provider_url' => 'https://idp.example.org/',
            'client_id' => 'round-trip-cid',
            'client_secret' => 'ROUND-TRIP-OIDC-SECRET',
            'scopes' => 'openid profile email groups',
            'redirect_uri' => 'https://media.example.org/auth/oidc/callback',
        ]), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);

        $row = $this->readRow($this->oidcKey);
        $this->assertSame(
            'https://idp.example.org',
            $row['provider_url'] ?? null,
            'provider_url must be stored rtrimmed of its trailing slash, as before S337',
        );
        $this->assertSame('round-trip-cid', $row['client_id'] ?? null);
        $this->assertSame(
            'ROUND-TRIP-OIDC-SECRET',
            $row['client_secret'] ?? null,
            'the writeOnly client_secret must survive a save that supplies it',
        );
        $this->assertSame('openid profile email groups', $row['scopes'] ?? null);
        $this->assertSame('https://media.example.org/auth/oidc/callback', $row['redirect_uri'] ?? null);
    }

    /**
     * Negative control: a body key the schema does NOT declare must NOT be
     * persisted — the schema-derived save path is bounded by the schema, so a
     * stray key cannot sneak into the stored document.
     */
    public function testASchemaForeignBodyKeyIsNotPersisted(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $controller = new LdapAdminController($this->ldapPlugin($repo));

        $response = $controller->saveSettings($this->post([
            'host' => 'dc01.corp.example.org',
            'base_dn' => 'dc=corp,dc=example,dc=org',
            'not_a_schema_key' => 'must not be persisted',
        ]), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);

        $row = $this->readRow($this->ldapKey);
        $this->assertArrayNotHasKey(
            'not_a_schema_key',
            $row,
            'the save path writes only schema-declared properties; a foreign body key must be dropped',
        );
        $this->assertSame('dc01.corp.example.org', $row['host'] ?? null);
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    private function ldapPlugin(PluginSettingsRepository $repo): LdapPlugin
    {
        return new LdapPlugin(new KeyedPluginSettingsStore($repo, $this->ldapKey));
    }

    private function oidcPlugin(PluginSettingsRepository $repo): OidcPlugin
    {
        return new OidcPlugin(new KeyedPluginSettingsStore($repo, $this->oidcKey));
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
     * Read the settings map straight out of the `plugin_settings` table — NOT
     * through the plugin/store — so the assertion is about what is really persisted.
     *
     * @return array<string, mixed>
     */
    private function readRow(string $key): array
    {
        $rows = $this->conn()->query(
            'SELECT settings_json FROM plugin_settings WHERE plugin_name = ? LIMIT 1',
            [$key],
        );
        $this->assertIsArray($rows);
        $this->assertArrayHasKey(0, $rows, "no plugin_settings row for {$key}");
        $this->assertIsArray($rows[0]);
        /** @var mixed $decoded */
        $decoded = json_decode((string) ($rows[0]['settings_json'] ?? ''), true);
        $this->assertIsArray($decoded, 'settings_json must decode to a map');

        /** @var array<string, mixed> */
        return $decoded;
    }

    private function conn(): Connection
    {
        $db = $this->db;
        if ($db === null) {
            $this->fail('No database connection');
        }

        return $db;
    }
}
