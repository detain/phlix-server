<?php

/**
 * Trakt.tv scrobbler configuration — flat re-export of config/scrobblers/trakt.php.
 *
 * This file holds no values of its own. It exists so the admin settings keys
 * `trakt.client_id`, `trakt.client_secret` and `trakt.redirect_uri` resolve
 * through {@see \Phlix\Admin\SettingsRepository::getDefault()}, which walks a
 * dotted key by trying the LONGEST config file path first: for a two-segment
 * key like `trakt.client_id` the only file path it can try is
 * `config/trakt.php`, because at least one segment must remain to address a
 * value inside the file. Without this file those three keys resolve to a null
 * default, which is what caused them to be dropped from the shared schema in
 * phlix-shared v0.24.0 — and with them, the ability to set Trakt credentials
 * from the admin Settings page at all (the PUT allow-list is derived from that
 * schema, so it began rejecting them as "Unknown setting key").
 *
 * WHY NOT rename the keys to `scrobblers.trakt.*`? That form resolves too —
 * `getDefault()` handles multi-segment file paths and reaches
 * `config/scrobblers/trakt.php` directly. But `trakt.*` overrides are ALREADY
 * persisted in the `server_settings` table on live installs, and
 * {@see \Phlix\Server\Http\Controllers\TraktOAuthController}'s
 * `SETTING_KEY_MAP` reads those exact key names. Renaming would orphan those
 * rows and silently drop working Trakt credentials on upgrade. A re-export shim
 * keeps every existing row valid and needs no read-path change.
 *
 * The `return require` idiom mirrors config/hwaccel.php, which re-exports
 * config/hwaccel_base.php the same way. It deliberately does NOT guard the
 * require with is_file(): config/scrobblers/trakt.php ships with the repo, and
 * a missing target is a broken install that must fail loudly rather than
 * degrade into an empty array — an empty array here would make the Trakt
 * settings silently inert, which is exactly the defect class this indirection
 * exists to remove.
 *
 * The canonical file — including every env-var default and the operator
 * documentation — remains config/scrobblers/trakt.php. Edit that one.
 *
 * @since 1.4.0
 */

declare(strict_types=1);

return require __DIR__ . '/scrobblers/trakt.php';
