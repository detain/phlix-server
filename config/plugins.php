<?php

declare(strict_types=1);

/**
 * Plugin subsystem configuration.
 *
 * `catalog` drives the admin **Plugins** section's catalog browser
 * ({@see \Phlix\Plugins\Catalog\PluginCatalogService}):
 *
 *  - `default_source` — the initial, non-removable catalog. Ships pointing at
 *    the official catalog repo (https://github.com/detain/phlix-plugins),
 *    whose `plugins.json` lists the first-party plugins. Operators may add
 *    more catalogs in the UI (persisted as a `plugins.catalog.sources`
 *    override in `server_settings`) or override this default per-install.
 *  - `sources` — config-level extra catalogs (usually empty; the UI writes
 *    its additions to the settings override of the same dotted key).
 *  - `fetch_timeout` — per-catalog HTTP fetch timeout, in seconds.
 *  - `channel` — release channel for the OFFICIAL first-party catalog:
 *    `stable` (the audited, pinned release tag) or `dev` (the catalog repo's
 *    moving default branch). This is the DECLARED DEFAULT for the
 *    `plugins.catalog.channel` setting, whose effective value is
 *    `override ?? default` ({@see \Phlix\Admin\SettingsRepository::getEffective()}).
 *    Without it the key resolves to a null default, and publishing the key to
 *    `detain/phlix-shared`'s `server-settings.schema.json` would then red
 *    {@see \Phlix\Tests\Unit\Admin\SettingsDefaultResolvabilityTest}. Keep the
 *    value one of {@see \Phlix\Plugins\Catalog\PluginCatalogService::CHANNEL_VALUES}.
 *
 * @since 0.33.0
 */

return [
    'catalog' => [
        'default_source' => 'https://github.com/detain/phlix-plugins',
        'sources'        => [],
        'fetch_timeout'  => 10,
        'channel'        => 'stable',
    ],

    // When true, the plugin auto-update worker periodically re-installs any
    // installed plugin that a configured catalog reports a newer version for.
    // Operator-toggleable in the admin Plugins section.
    'auto_update' => false,
];
