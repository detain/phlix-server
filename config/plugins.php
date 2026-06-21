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
 *
 * @since 0.33.0
 */

return [
    'catalog' => [
        'default_source' => 'https://github.com/detain/phlix-plugins',
        'sources'        => [],
        'fetch_timeout'  => 10,
    ],

    // When true, the plugin auto-update worker periodically re-installs any
    // installed plugin that a configured catalog reports a newer version for.
    // Operator-toggleable in the admin Plugins section.
    'auto_update' => false,
];
