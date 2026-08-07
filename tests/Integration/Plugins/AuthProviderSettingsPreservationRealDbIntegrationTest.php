<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Plugins;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Uuid;
use Phlix\Plugins\Github\Controller\GithubAdminController;
use Phlix\Plugins\Github\GithubOAuthProvider;
use Phlix\Plugins\Github\Plugin as GithubPlugin;
use Phlix\Plugins\Ldap\Controller\LdapAdminController;
use Phlix\Plugins\Ldap\Plugin as LdapPlugin;
use Phlix\Plugins\Oidc\Controller\OidcAdminController;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;
use Phlix\Plugins\Repository\PluginSettingsRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * ⚠ THE REGRESSION GUARD FOR S48's HIGHEST-CONSEQUENCE BEHAVIOUR ⚠
 *
 * `PluginDbSettings::saveSettings()` is a WHOLESALE REPLACE: whatever map the
 * caller hands it becomes the entire `plugin_settings.settings_json` row. This
 * exact shape has already destroyed live data in this project — a plugin
 * re-install wiped `enabled` plus EVERY setting on production, including the Trakt
 * OAuth tokens, and recovery needed a database backup. The admin controllers are
 * the only thing standing between an older or partial API client and a repeat: for
 * a key the request body OMITS they must read the stored value back and re-supply
 * it, instead of letting the wholesale replace silently drop it.
 *
 * Two keys are protected, for BOTH bundled OAuth providers (GitHub + OIDC):
 *
 *   - `redirect_uri` — the absolute callback registered with the provider. Losing
 *     it silently re-points the flow at host-derivation, which since S48 fix r3
 *     FAILS CLOSED (`503 callback_url_not_configured`) on any box without a
 *     matching `PHLIX_DOMAIN`. So a dropped `redirect_uri` breaks login outright.
 *   - `scopes` — an operator's custom scope list, silently reset to the built-in
 *     default (a downgrade of granted permissions the operator never asked for).
 *
 * Plus the neighbouring keep-on-blank rule for `client_secret`, which is the
 * closest analogue of the Trakt-token loss.
 *
 * S117 extends the same guard to the THIRD bundled provider, LDAP, which S48 left
 * on the old wholesale-replace shape. Four keys there, two of them
 * security-relevant in their own right:
 *
 *   - `ssl` — a dropped `ssl` resets to `false`, **downgrading the directory bind
 *     to plaintext**: the bind DN and its password then cross the network in the
 *     clear. A silent TLS downgrade caused by omitting a field is the worst
 *     failure mode in this file.
 *   - `user_filter` — a dropped filter resets to the OpenLDAP-flavoured
 *     `(uid={{username}})` default, which matches nothing on Active Directory, so
 *     every LDAP login stops working.
 *   - `port` (reset to 389 — the plaintext port, alongside the `ssl` downgrade)
 *     and `admin_group`/`bind_dn` (silently emptied, dropping admin privileges).
 *
 * Plus `bind_pw`, LDAP's analogue of `client_secret`.
 *
 * Every previous test of this behaviour ran against `InMemoryPluginSettingsRepository`.
 * This one drives the REAL controllers through the REAL
 * {@see PluginSettingsRepository} against REAL MySQL and then reads the row back
 * out of the table, so the guarantee is proven where the data actually lives — and
 * so a future reviewer can see the surviving values in the database, not in a PHP
 * array a double handed back.
 *
 * If anyone reverts a controller to a plain wholesale replace, the tests below fail
 * with a message naming the incident. Self-skips with no MySQL; runs in CI.
 */
final class AuthProviderSettingsPreservationRealDbIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $githubKey = '';

    private string $oidcKey = '';

    private string $ldapKey = '';

    private string $pluginDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S48 settings-preservation real-DB test. Runs in CI.');

        $this->assertNotNull($this->db);
        LoggerFactory::reset();
        LoggerFactory::init(dirname(__DIR__, 3) . '/config/logger.php');

        // Per-run plugin keys, so this test never touches (or leaves behind) the
        // real `github`/`oidc` rows a developer's local DB may hold.
        $token = substr(Uuid::v4(), 0, 8);
        $this->githubKey = 'itest-gh-' . $token;
        $this->oidcKey = 'itest-oidc-' . $token;
        $this->ldapKey = 'itest-ldap-' . $token;

        // Empty scratch plugin dir: the trait must never fall back to (or write)
        // the real src/Plugins/*/settings.json.
        $this->pluginDir = sys_get_temp_dir() . '/phlix_s48_pres_' . $token;
        mkdir($this->pluginDir, 0755, true);
        GithubPlugin::setPluginDirectory($this->pluginDir);
        OidcPlugin::setPluginDirectory($this->pluginDir);
        LdapPlugin::setPluginDirectory($this->pluginDir);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            foreach ([$this->githubKey, $this->oidcKey, $this->ldapKey] as $key) {
                if ($key !== '') {
                    $db->query('DELETE FROM plugin_settings WHERE plugin_name = ?', [$key]);
                }
            }
        }
        foreach (glob($this->pluginDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->pluginDir);
        GithubPlugin::setPluginDirectory(dirname(__DIR__, 3) . '/src/Plugins/Github');
        OidcPlugin::setPluginDirectory(dirname(__DIR__, 3) . '/src/Plugins/Oidc');
        LdapPlugin::setPluginDirectory(dirname(__DIR__, 3) . '/src/Plugins/Ldap');

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // GitHub
    // -----------------------------------------------------------------------

    /**
     * A partial GitHub save — only `client_id`, exactly what an older admin client
     * or a scripted `curl` would send — must leave `redirect_uri`, `scopes` AND
     * `client_secret` intact in the `plugin_settings` row.
     */
    public function testPartialGithubSavePreservesRedirectUriScopesAndSecret(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $repo->save($this->githubKey, [
            'client_id' => 'old-id',
            'client_secret' => 'REAL-GITHUB-SECRET',
            'scopes' => 'read:user user:email repo',
            'redirect_uri' => 'https://media.example.org/auth/github/callback',
        ]);

        $controller = new GithubAdminController($this->githubPlugin($repo));
        $response = $controller->saveSettings($this->post(['client_id' => 'new-id']), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);

        $row = $this->readRow($this->githubKey);
        $this->assertSame('new-id', $row['client_id'] ?? null, 'the posted key must be updated');
        $this->assertSame(
            'https://media.example.org/auth/github/callback',
            $row['redirect_uri'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: an absent redirect_uri was DROPPED. This is the shape that '
            . 'wiped live Trakt OAuth tokens on production — and since S48 fix r3 a lost redirect_uri '
            . 'makes every GitHub login answer 503 callback_url_not_configured.',
        );
        $this->assertSame(
            'read:user user:email repo',
            $row['scopes'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: an absent scopes key silently reset the operator\'s custom '
            . 'scopes to the default.',
        );
        $this->assertSame(
            'REAL-GITHUB-SECRET',
            $row['client_secret'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: saving without re-entering the secret destroyed it.',
        );
    }

    /**
     * The same guarantee across a WHOLE SEQUENCE of partial saves, which is what a
     * real admin session looks like (change the id, later change the scopes, later
     * change the id again). A single-shot test can pass while the second save in a
     * row loses the value it just preserved.
     */
    public function testRepeatedPartialGithubSavesNeverErodeTheStoredSettings(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $repo->save($this->githubKey, [
            'client_id' => 'id-0',
            'client_secret' => 'secret-0',
            'scopes' => 'read:user user:email repo admin:org',
            'redirect_uri' => 'https://media.example.org/auth/github/callback',
        ]);
        $controller = new GithubAdminController($this->githubPlugin($repo));

        foreach (['id-1', 'id-2', 'id-3'] as $id) {
            $this->assertSame(200, $controller->saveSettings($this->post(['client_id' => $id]), [])->statusCode);
        }

        $row = $this->readRow($this->githubKey);
        $this->assertSame('id-3', $row['client_id'] ?? null);
        $this->assertSame('https://media.example.org/auth/github/callback', $row['redirect_uri'] ?? null);
        $this->assertSame('read:user user:email repo admin:org', $row['scopes'] ?? null);
        $this->assertSame('secret-0', $row['client_secret'] ?? null);
    }

    /**
     * The other half of the contract, so "preserve" can never be implemented as
     * "ignore": an EXPLICIT empty value really does clear `redirect_uri` back to
     * host-derivation and reset `scopes` to the default. Without this, a permanent
     * merge would be indistinguishable from the correct behaviour and an operator
     * could not undo a mistake.
     */
    public function testExplicitEmptyValuesStillClearGithubSettings(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $repo->save($this->githubKey, [
            'client_id' => 'id',
            'client_secret' => 'sec',
            'scopes' => 'read:user repo',
            'redirect_uri' => 'https://media.example.org/auth/github/callback',
        ]);

        $controller = new GithubAdminController($this->githubPlugin($repo));
        $response = $controller->saveSettings(
            $this->post(['client_id' => 'id', 'redirect_uri' => '', 'scopes' => '  ']),
            [],
        );

        $this->assertSame(200, $response->statusCode, (string) $response->body);
        $row = $this->readRow($this->githubKey);
        $this->assertSame('', $row['redirect_uri'] ?? null, 'an explicit empty redirect_uri must clear it');
        $this->assertSame(
            GithubOAuthProvider::DEFAULT_SCOPES,
            $row['scopes'] ?? null,
            'explicitly blank scopes must reset to the default',
        );
        $this->assertSame('sec', $row['client_secret'] ?? null, 'clearing those two must not touch the secret');
    }

    /**
     * A rejected save must not partially mutate the row. A relative
     * `redirect_uri` is refused with 400 (GitHub compares scheme/host/port, so a
     * path can only ever produce `redirect_uri_mismatch`) — and the previously
     * stored settings must all still be there afterwards.
     */
    public function testARejectedGithubSaveLeavesTheStoredRowUntouched(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $stored = [
            'client_id' => 'keep-id',
            'client_secret' => 'keep-secret',
            'scopes' => 'read:user repo',
            'redirect_uri' => 'https://media.example.org/auth/github/callback',
        ];
        $repo->save($this->githubKey, $stored);

        $controller = new GithubAdminController($this->githubPlugin($repo));
        $bad = $controller->saveSettings(
            $this->post(['client_id' => 'x', 'redirect_uri' => '/auth/github/callback']),
            [],
        );
        $this->assertSame(400, $bad->statusCode);
        $this->assertSame('invalid_redirect_uri', $this->errorCode($bad));

        // …and a missing client_id is refused before anything is written, too.
        $missing = $controller->saveSettings($this->post([]), []);
        $this->assertSame(400, $missing->statusCode);
        $this->assertSame('missing_client_id', $this->errorCode($missing));

        $row = $this->readRow($this->githubKey);
        foreach ($stored as $key => $value) {
            $this->assertSame($value, $row[$key] ?? null, "a rejected save must not mutate {$key}");
        }
    }

    // -----------------------------------------------------------------------
    // OIDC — the provider whose analogue really did lose live OAuth tokens.
    // -----------------------------------------------------------------------

    /**
     * A partial OIDC save (the required `provider_url` + `client_id`, nothing else)
     * must leave `redirect_uri`, `scopes` and `client_secret` intact.
     */
    public function testPartialOidcSavePreservesRedirectUriScopesAndSecret(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $repo->save($this->oidcKey, [
            'provider_url' => 'https://idp.example.org',
            'client_id' => 'old-id',
            'client_secret' => 'REAL-OIDC-SECRET',
            'scopes' => 'openid profile email groups',
            'redirect_uri' => 'https://media.example.org/auth/oidc/callback',
        ]);

        $controller = new OidcAdminController($this->oidcPlugin($repo));
        $response = $controller->saveSettings(
            $this->post(['provider_url' => 'https://idp.example.org', 'client_id' => 'new-id']),
            [],
        );

        $this->assertSame(200, $response->statusCode, (string) $response->body);

        $row = $this->readRow($this->oidcKey);
        $this->assertSame('new-id', $row['client_id'] ?? null);
        $this->assertSame(
            'https://media.example.org/auth/oidc/callback',
            $row['redirect_uri'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: an absent redirect_uri was DROPPED — the same shape that '
            . 'wiped live Trakt OAuth tokens on production, and now a hard 503 on every OIDC login.',
        );
        $this->assertSame(
            'openid profile email groups',
            $row['scopes'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: an absent scopes key reset the operator\'s custom scopes.',
        );
        $this->assertSame(
            'REAL-OIDC-SECRET',
            $row['client_secret'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: saving without re-entering the secret destroyed it.',
        );
    }

    /**
     * OIDC: repeated partial saves must not erode the row either.
     */
    public function testRepeatedPartialOidcSavesNeverErodeTheStoredSettings(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $repo->save($this->oidcKey, [
            'provider_url' => 'https://idp.example.org',
            'client_id' => 'id-0',
            'client_secret' => 'secret-0',
            'scopes' => 'openid profile email groups',
            'redirect_uri' => 'https://media.example.org/auth/oidc/callback',
        ]);
        $controller = new OidcAdminController($this->oidcPlugin($repo));

        foreach (['id-1', 'id-2', 'id-3'] as $id) {
            $response = $controller->saveSettings(
                $this->post(['provider_url' => 'https://idp.example.org', 'client_id' => $id]),
                [],
            );
            $this->assertSame(200, $response->statusCode, (string) $response->body);
        }

        $row = $this->readRow($this->oidcKey);
        $this->assertSame('id-3', $row['client_id'] ?? null);
        $this->assertSame('https://media.example.org/auth/oidc/callback', $row['redirect_uri'] ?? null);
        $this->assertSame('openid profile email groups', $row['scopes'] ?? null);
        $this->assertSame('secret-0', $row['client_secret'] ?? null);
    }

    /**
     * OIDC: explicit empties still clear, and a rejected save writes nothing.
     */
    public function testOidcExplicitClearsAndRejectedSaves(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $repo->save($this->oidcKey, [
            'provider_url' => 'https://idp.example.org',
            'client_id' => 'id',
            'client_secret' => 'sec',
            'scopes' => 'openid profile groups',
            'redirect_uri' => 'https://media.example.org/auth/oidc/callback',
        ]);
        $controller = new OidcAdminController($this->oidcPlugin($repo));

        $ok = $controller->saveSettings($this->post([
            'provider_url' => 'https://idp.example.org',
            'client_id' => 'id',
            'redirect_uri' => '',
            'scopes' => '',
        ]), []);
        $this->assertSame(200, $ok->statusCode, (string) $ok->body);
        $row = $this->readRow($this->oidcKey);
        $this->assertSame('', $row['redirect_uri'] ?? null);
        $this->assertNotSame('openid profile groups', $row['scopes'] ?? null, 'blank scopes must reset');
        $this->assertSame('sec', $row['client_secret'] ?? null);

        // A relative redirect_uri is refused and changes nothing.
        $before = $this->readRow($this->oidcKey);
        $bad = $controller->saveSettings($this->post([
            'provider_url' => 'https://idp.example.org',
            'client_id' => 'id',
            'redirect_uri' => '/auth/oidc/callback',
        ]), []);
        $this->assertSame(400, $bad->statusCode);
        $this->assertSame('invalid_redirect_uri', $this->errorCode($bad));
        $this->assertSame($before, $this->readRow($this->oidcKey), 'a rejected save must not mutate the row');
    }

    // -----------------------------------------------------------------------
    // LDAP (S117) — the provider S48 left on the old shape. Its dropped keys do
    // not just lose configuration: they downgrade the bind to PLAINTEXT.
    // -----------------------------------------------------------------------

    /**
     * A partial LDAP save — only the two REQUIRED fields, exactly what a scripted
     * `curl` that just wants to move the server to a new host would send — must
     * leave `ssl`, `port`, `user_filter`, `bind_dn`, `admin_group` AND `bind_pw`
     * intact in the `plugin_settings` row.
     *
     * The stored map here is a hardened Active Directory setup: LDAPS on 636 with
     * an AD `sAMAccountName` filter. Every one of those is what the pre-S117
     * wholesale replace threw away.
     */
    public function testPartialLdapSavePreservesSslPortFilterAndBindCredentials(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $repo->save($this->ldapKey, [
            'host' => 'dc01.corp.example.org',
            'port' => 636,
            'ssl' => true,
            'base_dn' => 'dc=corp,dc=example,dc=org',
            'bind_dn' => 'cn=phlix-svc,ou=Service Accounts,dc=corp,dc=example,dc=org',
            'bind_pw' => 'REAL-LDAP-BIND-PASSWORD',
            'user_filter' => '(sAMAccountName={{username}})',
            'admin_group' => 'cn=Phlix Admins,ou=Groups,dc=corp,dc=example,dc=org',
        ]);

        $controller = new LdapAdminController($this->ldapPlugin($repo));
        $response = $controller->saveSettings($this->post([
            'host' => 'dc02.corp.example.org',
            'base_dn' => 'dc=corp,dc=example,dc=org',
        ]), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);

        $row = $this->readRow($this->ldapKey);
        $this->assertSame('dc02.corp.example.org', $row['host'] ?? null, 'the posted key must be updated');
        $this->assertTrue(
            $row['ssl'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: an absent ssl key was reset to false, SILENTLY DOWNGRADING THE '
            . 'DIRECTORY BIND TO PLAINTEXT — the bind DN and its password would now cross the network in '
            . 'the clear. Same shape that wiped live Trakt OAuth tokens on production.',
        );
        $this->assertSame(
            636,
            $row['port'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: an absent port was reset to 389, the plaintext port.',
        );
        $this->assertSame(
            '(sAMAccountName={{username}})',
            $row['user_filter'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: an absent user_filter was reset to the OpenLDAP default, which '
            . 'matches nothing on Active Directory — every LDAP login stops working.',
        );
        $this->assertSame(
            'cn=phlix-svc,ou=Service Accounts,dc=corp,dc=example,dc=org',
            $row['bind_dn'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: an absent bind_dn was emptied.',
        );
        $this->assertSame(
            'cn=Phlix Admins,ou=Groups,dc=corp,dc=example,dc=org',
            $row['admin_group'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: an absent admin_group was emptied, dropping admin privileges '
            . 'for every LDAP user.',
        );
        $this->assertSame(
            'REAL-LDAP-BIND-PASSWORD',
            $row['bind_pw'] ?? null,
            'WHOLESALE-REPLACE REGRESSION: saving without re-entering the bind password destroyed it.',
        );
    }

    /**
     * The same guarantee across a WHOLE SEQUENCE of partial saves, which is what a
     * real admin session looks like. A single-shot test can pass while the second
     * save in a row loses the value it just preserved.
     */
    public function testRepeatedPartialLdapSavesNeverErodeTheStoredSettings(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $repo->save($this->ldapKey, [
            'host' => 'dc00.corp.example.org',
            'port' => 636,
            'ssl' => true,
            'base_dn' => 'dc=corp,dc=example,dc=org',
            'bind_dn' => 'cn=phlix-svc,dc=corp,dc=example,dc=org',
            'bind_pw' => 'bind-pw-0',
            'user_filter' => '(sAMAccountName={{username}})',
            'admin_group' => 'cn=Phlix Admins,dc=corp,dc=example,dc=org',
        ]);
        $controller = new LdapAdminController($this->ldapPlugin($repo));

        foreach (['dc01', 'dc02', 'dc03'] as $host) {
            $response = $controller->saveSettings($this->post([
                'host' => $host . '.corp.example.org',
                'base_dn' => 'dc=corp,dc=example,dc=org',
            ]), []);
            $this->assertSame(200, $response->statusCode, (string) $response->body);
        }

        $row = $this->readRow($this->ldapKey);
        $this->assertSame('dc03.corp.example.org', $row['host'] ?? null);
        $this->assertTrue($row['ssl'] ?? null, 'ssl must survive a SEQUENCE of partial saves, not just one');
        $this->assertSame(636, $row['port'] ?? null);
        $this->assertSame('(sAMAccountName={{username}})', $row['user_filter'] ?? null);
        $this->assertSame('cn=phlix-svc,dc=corp,dc=example,dc=org', $row['bind_dn'] ?? null);
        $this->assertSame('cn=Phlix Admins,dc=corp,dc=example,dc=org', $row['admin_group'] ?? null);
        $this->assertSame('bind-pw-0', $row['bind_pw'] ?? null);
    }

    /**
     * The other half of the contract, so "preserve" can never be implemented as
     * "ignore": an EXPLICIT value really does change things. An operator moving a
     * server back to plaintext must be able to, and clearing `bind_dn` /
     * `admin_group` must stick — otherwise a permanent merge would be
     * indistinguishable from the correct behaviour and a mistake could not be
     * undone.
     */
    public function testExplicitValuesStillChangeLdapSettings(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $repo->save($this->ldapKey, [
            'host' => 'dc01.corp.example.org',
            'port' => 636,
            'ssl' => true,
            'base_dn' => 'dc=corp,dc=example,dc=org',
            'bind_dn' => 'cn=phlix-svc,dc=corp,dc=example,dc=org',
            'bind_pw' => 'bind-pw',
            'user_filter' => '(sAMAccountName={{username}})',
            'admin_group' => 'cn=Phlix Admins,dc=corp,dc=example,dc=org',
        ]);

        $controller = new LdapAdminController($this->ldapPlugin($repo));
        $response = $controller->saveSettings($this->post([
            'host' => 'dc01.corp.example.org',
            'base_dn' => 'dc=corp,dc=example,dc=org',
            'port' => 389,
            'ssl' => false,
            'bind_dn' => '',
            'admin_group' => '   ',
            'user_filter' => '  ',
        ]), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);
        $row = $this->readRow($this->ldapKey);
        $this->assertFalse($row['ssl'] ?? null, 'an explicit ssl=false must really disable TLS');
        $this->assertSame(389, $row['port'] ?? null, 'an explicit port must really be stored');
        $this->assertSame('', $row['bind_dn'] ?? null, 'an explicit empty bind_dn must clear it');
        $this->assertSame('', $row['admin_group'] ?? null, 'an explicit blank admin_group must clear it');
        $this->assertSame(
            LdapAdminController::DEFAULT_USER_FILTER,
            $row['user_filter'] ?? null,
            'an explicitly blank user_filter must reset to the default — never stored as empty, which '
            . 'would match nothing and lock every user out',
        );
        $this->assertSame('bind-pw', $row['bind_pw'] ?? null, 'clearing those must not touch the bind password');
    }

    /**
     * A rejected save must not partially mutate the row: `missing_host`,
     * `missing_base_dn` and `invalid_port` all have to write nothing at all.
     *
     * The port case matters most — it is the only rejection reached AFTER the
     * absent-key merge has already computed a full replacement map, so an
     * implementation that saved first and validated second would be caught here.
     */
    public function testARejectedLdapSaveLeavesTheStoredRowUntouched(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $stored = [
            'host' => 'dc01.corp.example.org',
            'port' => 636,
            'ssl' => true,
            'base_dn' => 'dc=corp,dc=example,dc=org',
            'bind_dn' => 'cn=phlix-svc,dc=corp,dc=example,dc=org',
            'bind_pw' => 'keep-bind-pw',
            'user_filter' => '(sAMAccountName={{username}})',
            'admin_group' => 'cn=Phlix Admins,dc=corp,dc=example,dc=org',
        ];
        $repo->save($this->ldapKey, $stored);

        $controller = new LdapAdminController($this->ldapPlugin($repo));

        $missingHost = $controller->saveSettings($this->post(['base_dn' => 'dc=x']), []);
        $this->assertSame(400, $missingHost->statusCode);
        $this->assertSame('missing_host', $this->errorCode($missingHost));

        $missingBaseDn = $controller->saveSettings($this->post(['host' => 'dc09.corp.example.org']), []);
        $this->assertSame(400, $missingBaseDn->statusCode);
        $this->assertSame('missing_base_dn', $this->errorCode($missingBaseDn));

        $badPort = $controller->saveSettings($this->post([
            'host' => 'dc09.corp.example.org',
            'base_dn' => 'dc=corp,dc=example,dc=org',
            'port' => 70000,
        ]), []);
        $this->assertSame(400, $badPort->statusCode);
        $this->assertSame('invalid_port', $this->errorCode($badPort));

        $row = $this->readRow($this->ldapKey);
        foreach ($stored as $key => $value) {
            $this->assertSame($value, $row[$key] ?? null, "a rejected save must not mutate {$key}");
        }
    }

    /**
     * The absent-key merge must not INVENT settings on a first-ever save either:
     * with no stored row, the documented defaults still apply.
     */
    public function testAFirstLdapSaveWithNoStoredRowUsesTheDocumentedDefaults(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $controller = new LdapAdminController($this->ldapPlugin($repo));

        $response = $controller->saveSettings($this->post([
            'host' => 'ldap.example.org',
            'base_dn' => 'dc=example,dc=org',
        ]), []);

        $this->assertSame(200, $response->statusCode, (string) $response->body);
        $row = $this->readRow($this->ldapKey);
        $this->assertSame(LdapAdminController::DEFAULT_PORT, $row['port'] ?? null);
        $this->assertFalse($row['ssl'] ?? null);
        $this->assertSame(LdapAdminController::DEFAULT_USER_FILTER, $row['user_filter'] ?? null);
        $this->assertSame('', $row['bind_dn'] ?? null);
        $this->assertSame('', $row['admin_group'] ?? null);
        $this->assertArrayNotHasKey('bind_pw', $row, 'no password was ever supplied, so none may be stored');
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    private function ldapPlugin(PluginSettingsRepository $repo): LdapPlugin
    {
        return new LdapPlugin(new KeyedPluginSettingsStore($repo, $this->ldapKey));
    }

    private function githubPlugin(PluginSettingsRepository $repo): GithubPlugin
    {
        return new GithubPlugin(new KeyedPluginSettingsStore($repo, $this->githubKey));
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
     * through the plugin/trait — so the assertion is about what is really persisted.
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

    private function errorCode(Response $response): ?string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);

        return is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : null;
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
