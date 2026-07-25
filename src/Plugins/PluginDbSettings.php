<?php

/**
 * Phlix media server component: Plugins.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins;

use Phlix\Plugins\Repository\PluginSettingsRepository;
use Phlix\Plugins\Repository\PluginSettingsStore;

/**
 * DB-backed settings behaviour shared by the bundled auth-provider plugins
 * (OIDC / LDAP / GitHub). S48 replaces each plugin's hand-rolled `settings.json`
 * with a row in the `plugin_settings` table ({@see PluginSettingsRepository}).
 *
 * ## Backward-compatible migration path (critical)
 *
 * Existing deployments store provider config in an on-disk `settings.json`.
 * {@see self::getSettings()} performs a ONE-TIME, lazy import: when the DB store
 * holds no row for the plugin yet, it reads the legacy file and, if it is
 * non-empty, writes it into the DB store before returning it. So the very first
 * read after upgrading seeds the DB from the file — no operator loses their
 * configured OIDC/LDAP settings, and no separate data-migration step is needed.
 *
 * ## Test / no-DB fallback
 *
 * The settings store is injected as an OPTIONAL constructor dependency. When it
 * is absent (unit tests that construct the plugin directly, or any context with
 * no DB) the plugin transparently falls back to the legacy file-based store, so
 * existing tests keep working unchanged.
 *
 * A class using this trait must provide {@see loadFileSettings()},
 * {@see persistFileSettings()} and {@see settingsStoreKey()}, and set
 * {@see $settingsStore} in its constructor.
 *
 * @package Phlix\Plugins
 * @since 0.102.0
 */
trait PluginDbSettings
{
    /**
     * DB-backed settings store, or null to fall back to the legacy file store.
     */
    protected ?PluginSettingsStore $settingsStore = null;

    /**
     * The `plugin_settings.plugin_name` key this plugin persists under.
     */
    abstract protected function settingsStoreKey(): string;

    /**
     * Read the legacy on-disk `settings.json` (the pre-S48 store). Used as the
     * no-DB fallback and as the one-time import source for {@see getSettings()}.
     *
     * @return array<string, mixed>
     */
    abstract protected function loadFileSettings(): array;

    /**
     * Persist to the legacy on-disk `settings.json`. Only used in the no-DB
     * fallback path.
     *
     * @param array<string, mixed> $settings
     */
    abstract protected function persistFileSettings(array $settings): void;

    /**
     * Return this plugin's persisted settings.
     *
     * DB store present: read the row; if absent, one-time import the legacy
     * settings.json into the DB (preserving existing operators' config) and
     * return it. DB store absent (tests): read the file directly.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        if ($this->settingsStore === null) {
            return $this->loadFileSettings();
        }

        $stored = $this->settingsStore->get($this->settingsStoreKey());
        if ($stored !== null) {
            return $stored;
        }

        // One-time migration: no DB row yet — import a legacy settings.json so no
        // operator loses their configured provider settings, then serve it.
        $legacy = $this->loadFileSettings();
        if ($legacy !== []) {
            $this->settingsStore->save($this->settingsStoreKey(), $legacy);
        }

        return $legacy;
    }

    /**
     * Persist a replacement settings map (wholesale).
     *
     * @param array<string, mixed> $settings
     */
    public function saveSettings(array $settings): void
    {
        if ($this->settingsStore === null) {
            $this->persistFileSettings($settings);
            return;
        }

        $this->settingsStore->save($this->settingsStoreKey(), $settings);
    }
}
