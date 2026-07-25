<?php

/**
 * Phlix media server component: Controller.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Ldap\Controller;

use Phlix\Plugins\Ldap\Plugin;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Admin API controller for LDAP provider settings.
 *
 * As of S48 the values live in the DB-backed `plugin_settings` store (migration
 * `093_plugin_settings.sql`) rather than a `settings.json` file — see
 * {@see \Phlix\Plugins\PluginDbSettings}. `bind_pw` is write-only: it is stripped
 * from the read response by {@see Plugin::maskSecrets()}, and a blank value on save
 * keeps the stored password. It is NOT encrypted at rest, so a database dump
 * contains that credential.
 *
 * ## KNOWN GAP — this controller does NOT preserve absent keys
 *
 * The store is a wholesale replace
 * ({@see \Phlix\Plugins\Repository\PluginSettingsRepository::save()}), and
 * {@see self::saveSettings()} rebuilds the whole document from the request body
 * with hardcoded fallbacks. So a partial payload RESETS every optional key it
 * omits — `port` → 389, `ssl` → false, `bind_dn`/`admin_group` → `''`,
 * `user_filter` → `(uid={{username}})` — rather than preserving it. Only `bind_pw`
 * is kept (the blank-keeps-existing branch).
 *
 * S48 closed exactly this shape on
 * {@see \Phlix\Plugins\Oidc\Controller\OidcAdminController} and
 * {@see \Phlix\Plugins\Github\Controller\GithubAdminController} (it is the shape
 * that wiped live Trakt OAuth tokens on production), but LDAP was OUT OF SCOPE for
 * that step and is deliberately left as is rather than changed unreviewed. It is
 * not currently exploitable by a shipped client: the admin SPA's LDAP form always
 * posts the complete set of fields. It would bite a scripted caller — and note that
 * two of the resets are security-relevant (`ssl` → false downgrades to a plaintext
 * bind; a reset `user_filter` breaks Active Directory logins).
 *
 * If you make this consistent with the other two, use `array_key_exists()` per
 * optional key (never `isset()`/`??`, which cannot tell "absent" from "sent
 * empty"), and mirror the coverage in
 * `tests/Integration/Plugins/AuthProviderSettingsPreservationRealDbIntegrationTest.php`.
 *
 * @package Phlix\Plugins\Ldap\Controller
 */
final class LdapAdminController
{
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function getSettings(Request $request, array $params): Response
    {
        $settings = $this->plugin->maskSecrets($this->plugin->getSettings());

        return (new Response())->json([
            'host' => $settings['host'] ?? '',
            'port' => $settings['port'] ?? 389,
            'ssl' => $settings['ssl'] ?? false,
            'base_dn' => $settings['base_dn'] ?? '',
            'bind_dn' => $settings['bind_dn'] ?? '',
            'user_filter' => $settings['user_filter'] ?? '(uid={{username}})',
            'admin_group' => $settings['admin_group'] ?? '',
            'configured' => isset($settings['host']) && isset($settings['base_dn']),
        ]);
    }

    /**
     * Save LDAP settings.
     *
     * ⚠ Send the COMPLETE settings map: unlike the OIDC/GitHub equivalents this
     * method does not preserve keys the body omits — see the class docblock's
     * "KNOWN GAP". `host` and `base_dn` are required (`400 missing_host` /
     * `missing_base_dn`), `port` must be 1-65535 (`400 invalid_port`), and a blank
     * `bind_pw` keeps the stored password.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function saveSettings(Request $request, array $params): Response
    {
        $body = $request->body;

        $host = is_string($body['host'] ?? null) ? trim($body['host']) : '';
        $port = is_numeric($body['port'] ?? null) ? (int) $body['port'] : 389;
        $ssl = isset($body['ssl']) ? (bool) $body['ssl'] : false;
        $baseDn = is_string($body['base_dn'] ?? null) ? trim($body['base_dn']) : '';
        $bindDn = is_string($body['bind_dn'] ?? null) ? trim($body['bind_dn']) : '';
        $bindPw = is_string($body['bind_pw'] ?? null) ? $body['bind_pw'] : '';
        $userFilter = is_string($body['user_filter'] ?? null) ? trim($body['user_filter']) : '(uid={{username}})';
        $adminGroup = is_string($body['admin_group'] ?? null) ? trim($body['admin_group']) : '';

        if ($host === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_host',
                'message' => 'Host is required',
            ]);
        }

        if ($baseDn === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_base_dn',
                'message' => 'Base DN is required',
            ]);
        }

        if ($port < 1 || $port > 65535) {
            return (new Response())->status(400)->json([
                'error' => 'invalid_port',
                'message' => 'Port must be between 1 and 65535',
            ]);
        }

        $settings = [
            'host' => $host,
            'port' => $port,
            'ssl' => $ssl,
            'base_dn' => $baseDn,
            'bind_dn' => $bindDn,
            'user_filter' => $userFilter,
            'admin_group' => $adminGroup,
        ];

        if ($bindPw !== '') {
            $settings['bind_pw'] = $bindPw;
        }

        $existingSettings = $this->plugin->getSettings();
        if (isset($existingSettings['bind_pw']) && $bindPw === '') {
            $settings['bind_pw'] = $existingSettings['bind_pw'];
        }

        $this->plugin->saveSettings($settings);

        return (new Response())->json([
            'message' => 'Settings saved successfully',
            'configured' => true,
        ]);
    }

    /**
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function testConnection(Request $request, array $params): Response
    {
        $body = $request->body;

        $host = is_string($body['host'] ?? null) ? trim($body['host']) : '';
        $port = is_numeric($body['port'] ?? null) ? (int) $body['port'] : 389;
        $ssl = isset($body['ssl']) ? (bool) $body['ssl'] : false;
        $baseDn = is_string($body['base_dn'] ?? null) ? trim($body['base_dn']) : '';
        $bindDn = is_string($body['bind_dn'] ?? null) ? trim($body['bind_dn']) : '';
        $bindPw = is_string($body['bind_pw'] ?? null) ? $body['bind_pw'] : '';

        if ($host === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_host',
                'message' => 'Host is required',
            ]);
        }

        if ($baseDn === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_base_dn',
                'message' => 'Base DN is required',
            ]);
        }

        $userFilter = is_string($body['user_filter'] ?? null) ? trim($body['user_filter']) : '(uid={{username}})';

        try {
            $connection = new \Phlix\Plugins\Ldap\LdapConnection(
                host: $host,
                port: $port,
                ssl: $ssl,
                baseDn: $baseDn,
                bindDn: $bindDn !== '' ? $bindDn : null,
                bindPw: $bindPw !== '' ? $bindPw : null,
                userFilter: $userFilter,
            );

            $result = $connection->testConnection();

            return (new Response())->json($result);
        } catch (\Exception $e) {
            return (new Response())->json([
                'success' => false,
                'error' => 'connection_failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function getSchema(Request $request, array $params): Response
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'LDAP Provider Configuration',
            'description' => 'Configuration for the LDAP authentication provider',
            'type' => 'object',
            'properties' => [
                'host' => [
                    'type' => 'string',
                    'description' => 'LDAP server hostname or IP address',
                ],
                'port' => [
                    'type' => 'int',
                    'description' => 'LDAP server port (389 for plain, 636 for SSL)',
                    'default' => 389,
                ],
                'ssl' => [
                    'type' => 'bool',
                    'description' => 'Use SSL/TLS encryption',
                    'default' => false,
                ],
                'base_dn' => [
                    'type' => 'string',
                    'description' => 'Base Distinguished Name for LDAP searches',
                ],
                'bind_dn' => [
                    'type' => 'string',
                    'description' => 'Bind DN for initial connection (optional)',
                ],
                'bind_pw' => [
                    'type' => 'string',
                    'description' => 'Bind password for initial connection (optional)',
                    'writeOnly' => true,
                ],
                'user_filter' => [
                    'type' => 'string',
                    'description' => 'LDAP filter for user search (use {{username}} as placeholder)',
                    'default' => '(uid={{username}})',
                ],
                'admin_group' => [
                    'type' => 'string',
                    'description' => 'LDAP group DN whose members get admin privileges (optional)',
                ],
            ],
            'required' => ['host', 'base_dn'],
        ];

        return (new Response())->json(['schema' => $schema]);
    }
}
