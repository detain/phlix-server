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
 * ## Absent keys are PRESERVED (S117 — the gap S48 left open here)
 *
 * The store is a wholesale replace
 * ({@see \Phlix\Plugins\Repository\PluginSettingsRepository::save()}), so this
 * controller — not the store — is what stops a partial payload from erasing
 * configuration. The invariant, for EVERY optional key:
 *
 *   **a key ABSENT from the request body is PRESERVED; only an explicitly empty
 *   (or non-string / non-numeric) value clears it or resets it to its default.**
 *
 * Absent is never a deletion. Until S117 {@see self::saveSettings()} rebuilt the
 * whole document from the request body with hardcoded fallbacks, so a partial
 * payload RESET every optional key it omitted — `port` → 389, `ssl` → **false**,
 * `bind_dn`/`admin_group` → `''`, `user_filter` → `(uid={{username}})`. Only
 * `bind_pw` survived (its blank-keeps-existing branch). That is byte-for-byte the
 * shape that wiped live Trakt OAuth tokens on production during a plugin update,
 * and S48 closed it on
 * {@see \Phlix\Plugins\Oidc\Controller\OidcAdminController} and
 * {@see \Phlix\Plugins\Github\Controller\GithubAdminController} while leaving LDAP
 * out of scope. Two of the LDAP resets are security-relevant in their own right:
 * `ssl` → false **downgrades the directory bind to plaintext**, and a reset
 * `user_filter` breaks Active Directory logins.
 *
 * `array_key_exists()` — not `isset()`/`??`, which cannot tell "absent" from "sent
 * as empty" — is how the distinction is made. `bind_pw` keeps its own, older rule
 * (a blank value keeps the stored password) because the admin SPA deliberately
 * posts it blank on every save. The guarantee is proven against real MySQL, by
 * reading `plugin_settings.settings_json` back out of the table, in
 * `tests/Integration/Plugins/AuthProviderSettingsPreservationRealDbIntegrationTest.php`.
 *
 * @package Phlix\Plugins\Ldap\Controller
 */
final class LdapAdminController
{
    /** Port used when none is stored and none is supplied. */
    public const int DEFAULT_PORT = 389;

    /** User-search filter used when none is stored and none is supplied. */
    public const string DEFAULT_USER_FILTER = '(uid={{username}})';

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
            'port' => $settings['port'] ?? self::DEFAULT_PORT,
            'ssl' => $settings['ssl'] ?? false,
            'base_dn' => $settings['base_dn'] ?? '',
            'bind_dn' => $settings['bind_dn'] ?? '',
            'user_filter' => $settings['user_filter'] ?? self::DEFAULT_USER_FILTER,
            'admin_group' => $settings['admin_group'] ?? '',
            'configured' => isset($settings['host']) && isset($settings['base_dn']),
        ]);
    }

    /**
     * Save LDAP settings.
     *
     * `host` and `base_dn` are required (`400 missing_host` / `missing_base_dn`)
     * and `port` must be 1-65535 (`400 invalid_port`); a rejected save mutates
     * nothing. Every OTHER key may be omitted, and an omitted key KEEPS its stored
     * value — see the class docblock's absent-key contract. A blank `bind_pw`
     * keeps the stored password.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function saveSettings(Request $request, array $params): Response
    {
        $body = $request->body;

        // S117 — a save is a WHOLESALE REPLACE of `plugin_settings.settings_json`,
        // so every optional key must fall back to what is already STORED, not to a
        // hardcoded default. Read the stored map first and merge per key with
        // array_key_exists(): `isset()`/`??` cannot tell an ABSENT key (preserve)
        // from one sent as empty/null (clear), which is the whole distinction. This
        // is the shape that wiped live Trakt OAuth tokens on production; here an
        // absent `ssl` would additionally have downgraded the directory bind to
        // PLAINTEXT and an absent `user_filter` would have broken AD logins.
        $existing = $this->plugin->getSettings();

        $host = is_string($body['host'] ?? null) ? trim($body['host']) : '';
        $baseDn = is_string($body['base_dn'] ?? null) ? trim($body['base_dn']) : '';

        $port = self::defaultedPort(
            array_key_exists('port', $body) ? $body['port'] : ($existing['port'] ?? null),
        );

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

        // S337 — the replacement document is DERIVED from the schema's `properties`
        // list ({@see self::settingsSchemaProperties()}), not a hand-enumerated
        // literal. A property added to the schema without a normalizer in
        // {@see self::normalizeSavedValue()} throws loudly instead of being
        // silently dropped on every save; one added WITH a normalizer survives the
        // round-trip automatically. `bind_pw` is the schema's writeOnly property:
        // a blank value keeps the stored password (see the class docblock).
        $settings = self::buildSettingsDocument($body, $existing);

        $this->plugin->saveSettings($settings);

        return (new Response())->json([
            'message' => 'Settings saved successfully',
            'configured' => true,
        ]);
    }

    /**
     * Normalise a port value: anything numeric wins, anything else (absent from
     * BOTH the body and the stored map, or explicitly blank/null) falls back to
     * {@see self::DEFAULT_PORT}. Range validation stays with the caller so an
     * out-of-range value is REJECTED rather than silently defaulted.
     */
    private static function defaultedPort(mixed $port): int
    {
        return is_numeric($port) ? (int) $port : self::DEFAULT_PORT;
    }

    /**
     * Normalise a user-search filter: a non-blank string wins, anything else falls
     * back to {@see self::DEFAULT_USER_FILTER}. An EMPTY filter would match nothing
     * and lock every LDAP user out, so it is never stored as such.
     */
    private static function defaultedUserFilter(mixed $filter): string
    {
        return is_string($filter) && trim($filter) !== ''
            ? trim($filter)
            : self::DEFAULT_USER_FILTER;
    }

    /**
     * Normalise a plain optional string: trimmed when it is a string, `''`
     * (i.e. cleared) for anything else.
     */
    private static function trimmedString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * The schema's `properties` map — the single enumeration of settable keys,
     * shared by {@see self::getSchema()} (the API surface) and
     * {@see self::saveSettings()} (the writer). S337 removed the hand-enumerated
     * save literal; a key can only be saved if it exists here.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function settingsSchemaProperties(): array
    {
        return [
            'host' => [
                'type' => 'string',
                'description' => 'LDAP server hostname or IP address',
            ],
            'port' => [
                'type' => 'int',
                'description' => 'LDAP server port (389 for plain, 636 for SSL)',
                'default' => self::DEFAULT_PORT,
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
                'default' => self::DEFAULT_USER_FILTER,
            ],
            'admin_group' => [
                'type' => 'string',
                'description' => 'LDAP group DN whose members get admin privileges (optional)',
            ],
        ];
    }

    /**
     * Build the full replacement settings document from the schema's property
     * list (S337). This is the single source of truth for what a save writes:
     * every non-writeOnly property is normalised from the request body or the
     * stored map (the absent-key contract), and the writeOnly secret follows the
     * documented blank-keeps-existing rule.
     *
     * @param array<string, mixed> $body     The request body.
     * @param array<string, mixed> $existing The currently stored map.
     * @return array<string, mixed>
     */
    private static function buildSettingsDocument(array $body, array $existing): array
    {
        $settings = [];

        foreach (self::settingsSchemaProperties() as $key => $definition) {
            if (($definition['writeOnly'] ?? false) === true) {
                // Write-only secret: a non-blank body value replaces it, a blank
                // body value keeps the stored secret, and with neither the key is
                // simply absent (a first-ever save with no password stores none).
                $value = is_string($body[$key] ?? null) ? $body[$key] : '';
                if ($value !== '') {
                    $settings[$key] = $value;
                } elseif (isset($existing[$key])) {
                    $settings[$key] = $existing[$key];
                }
                continue;
            }

            $raw = array_key_exists($key, $body) ? $body[$key] : ($existing[$key] ?? null);
            $settings[$key] = self::normalizeSavedValue($key, $raw);
        }

        return $settings;
    }

    /**
     * Normalise one non-writeOnly schema property into its stored value.
     *
     * Fails fast when a schema property has no normalizer here — silently
     * dropping a key on every save is exactly the defect S337 removes, so a
     * missing case must be loud, not quiet.
     *
     * @return string|int|bool
     */
    private static function normalizeSavedValue(string $key, mixed $value): string|int|bool
    {
        return match ($key) {
            'host', 'base_dn', 'bind_dn', 'admin_group' => self::trimmedString($value),
            'port' => self::defaultedPort($value),
            'ssl' => (bool) $value,
            'user_filter' => self::defaultedUserFilter($value),
            default => throw new \LogicException(
                "LDAP saveSettings has no normalizer for schema property '{$key}' — "
                . 'add one to LdapAdminController::normalizeSavedValue().',
            ),
        };
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
        $port = self::defaultedPort($body['port'] ?? null);
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

        $userFilter = self::defaultedUserFilter($body['user_filter'] ?? null);

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
            'properties' => self::settingsSchemaProperties(),
            'required' => ['host', 'base_dn'],
        ];

        return (new Response())->json(['schema' => $schema]);
    }
}
