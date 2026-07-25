<?php

/**
 * Phlix media server component: Repository.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Repository;

use DateTimeImmutable;
use Workerman\MySQL\Connection;

/**
 * Workerman\MySQL-backed key/value settings store for the bundled auth-provider
 * plugins (migration `093_plugin_settings.sql`).
 *
 * S48 moves each bundled provider's configuration (OIDC issuer/client-id/secret,
 * LDAP host/base-dn/bind, GitHub client-id/secret/scopes) off its hand-rolled
 * `settings.json` file and into the `plugin_settings` DB table so the config
 * persists in the database, is visible to every resident worker, and survives a
 * read-only/ephemeral filesystem. One row per plugin, keyed by manifest name.
 *
 * This is intentionally SEPARATE from {@see PluginRepository} (the catalog
 * `plugins` table): the bundled auth providers are enabled through
 * {@see \Phlix\Auth\AuthProviderBootstrapper}, not the PluginLoader pipeline, so
 * they must never appear as catalog rows (see migration 093's header comment).
 *
 * All queries are parameterized — never interpolate input into the SQL string.
 *
 * @package Phlix\Plugins\Repository
 * @since 0.102.0
 */
class PluginSettingsRepository implements PluginSettingsStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Read a plugin's persisted settings map.
     *
     * @param string $pluginName Bundled-plugin key (e.g. `oidc`, `ldap`, `github`).
     *
     * @return array<string, mixed>|null The decoded settings map, an empty array
     *         when the row exists but holds no/blank JSON, or NULL when there is
     *         no row at all (the caller uses NULL to trigger a one-time legacy
     *         settings.json import).
     */
    public function get(string $pluginName): ?array
    {
        $rows = $this->db->query(
            'SELECT settings_json FROM plugin_settings WHERE plugin_name = ? LIMIT 1',
            [$pluginName],
        );

        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $json = is_string($rows[0]['settings_json'] ?? null) ? (string) $rows[0]['settings_json'] : '';
        if ($json === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Upsert a plugin's settings map (wholesale replace).
     *
     * @param string               $pluginName Bundled-plugin key.
     * @param array<string, mixed> $settings   Full settings map to store.
     *
     * @return void
     */
    public function save(string $pluginName, array $settings): void
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $this->db->query(
            'INSERT INTO plugin_settings (plugin_name, settings_json, updated_at) '
            . 'VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json), updated_at = VALUES(updated_at)',
            [$pluginName, (string) json_encode($settings), $now],
        );
    }

    /**
     * Whether a settings row exists for the given plugin.
     *
     * @param string $pluginName Bundled-plugin key.
     */
    public function exists(string $pluginName): bool
    {
        $rows = $this->db->query('SELECT 1 FROM plugin_settings WHERE plugin_name = ? LIMIT 1', [$pluginName]);

        return is_array($rows) && count($rows) > 0;
    }
}
