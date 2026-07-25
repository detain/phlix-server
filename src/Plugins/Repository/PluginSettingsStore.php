<?php

/**
 * Phlix media server component: Repository.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Repository;

/**
 * Persistence contract for the bundled auth-provider plugins' settings
 * ({@see \Phlix\Plugins\PluginDbSettings}).
 *
 * Extracted in S48 review r1 (Finding 7) so a test double can implement the
 * contract instead of `extends`-ing {@see PluginSettingsRepository} and skipping
 * its constructor behind a phpstan suppression — the same shape
 * {@see \Phlix\Plugins\OAuth2\OAuth2StateStore} already has.
 *
 * @package Phlix\Plugins\Repository
 * @since 0.102.0
 */
interface PluginSettingsStore
{
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
    public function get(string $pluginName): ?array;

    /**
     * Upsert a plugin's settings map (wholesale replace).
     *
     * @param string               $pluginName Bundled-plugin key.
     * @param array<string, mixed> $settings   Full settings map to store.
     */
    public function save(string $pluginName, array $settings): void;

    /**
     * Whether a settings row exists for the given plugin.
     *
     * @param string $pluginName Bundled-plugin key.
     */
    public function exists(string $pluginName): bool;
}
