<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Plugins;

use Phlix\Plugins\Repository\PluginSettingsStore;

/**
 * A REAL {@see \Phlix\Plugins\Repository\PluginSettingsRepository} (so every query
 * runs against live MySQL) with the `plugin_settings.plugin_name` key remapped to a
 * per-run scratch value.
 *
 * The bundled plugins hardcode their key (`github` / `oidc`) via
 * `settingsStoreKey()`, and an integration test must not clobber — or leave behind —
 * a developer's genuinely configured provider rows on a local database. Remapping
 * the key keeps every SQL path (upsert, JSON round-trip, `SELECT … WHERE
 * plugin_name = ?`) real while making the row disposable. The key value itself is
 * irrelevant to the behaviour under test: nothing branches on it.
 *
 * @see AuthProviderSettingsPreservationRealDbIntegrationTest
 */
final class KeyedPluginSettingsStore implements PluginSettingsStore
{
    public function __construct(
        private readonly PluginSettingsStore $inner,
        private readonly string $key,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $pluginName): ?array
    {
        return $this->inner->get($this->key);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function save(string $pluginName, array $settings): void
    {
        $this->inner->save($this->key, $settings);
    }

    public function exists(string $pluginName): bool
    {
        return $this->inner->exists($this->key);
    }
}
