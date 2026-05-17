# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.11.0] — 2026-05-17

### Changed

- Repository moved from `github.com/detain/phlex` to
  `github.com/detain/phlex-server`. The local working directory stays
  `/home/sites/phlex` per the expansion plan; only the `origin` remote
  URL changes. Update your local clone with
  `git remote set-url origin git@github.com:detain/phlex-server.git`.
  The old `detain/phlex` repo is archived (B.4b) with a README pointing
  at the new home.
- Refactored to depend on `detain/phlex-shared:^0.2`. The
  `LifecycleInterface`, manifest DTOs, event DTOs, and `EventNameMap`
  now live in the shared package. Old FQCNs
  (`Phlex\Plugins\Contract\LifecycleInterface`,
  `Phlex\Plugins\Manifest`, `Phlex\Plugins\ManifestType`,
  `Phlex\Plugins\ManifestValidationError`,
  `Phlex\Plugins\EventNameMap`, `Phlex\Common\Events\*`) remain as
  deprecated aliases through 0.11.x; removed in 0.12.0.
- Manifest schema validation extracted to
  `Phlex\Plugins\Manifest\ManifestSchema`.

### Added

- Composer require on `detain/phlex-shared:^0.2.0` via a VCS
  repositories entry.
- `src/Plugins/AliasCompatShim.php` registers the 16 `class_alias`
  entries for the moved classes.
- Three-line interface bridge at
  `src/Plugins/Contract/LifecycleInterface.php` (extends the shared
  interface — `class_alias` doesn't work for interfaces).

- Complete plugin developer documentation
  ([`docs/plugins/developer-guide.md`](docs/plugins/developer-guide.md))
  covering plugin types, manifest, lifecycle, event subscription,
  settings, signing, packaging, local testing, and publishing — plus a
  matching server-internals reference for contributors extending the
  loader ([`docs/dev/plugin-sdk.md`](docs/dev/plugin-sdk.md)). Phase A
  is now functionally complete; the plugin system is ready for
  external authors. `docs/plugins/install-from-catalog.md` rewritten
  to set expectations about the catalog's Phase L delivery; README
  promotes the developer guide and the reference plugin.
- Plugin manifest specification (`docs/plugins/manifest.md`,
  `docs/plugins/manifest.schema.json`) and the
  `Phlex\Plugins\Manifest` value object that parses and validates
  `plugin.json` files. The eleven plugin types from
  `PHLEX_EXPANSION_PLAN.md` §5 are codified as the
  `Phlex\Plugins\ManifestType` enum. No loader yet — see Step A.4.
  Adds `justinrainbow/json-schema:^5.2` as a runtime dependency.
- PSR-11 dependency injection container (PHP-DI). Application services are
  now auto-wired; the legacy ConnectionPool / LoggerFactory statics remain
  for backwards compatibility but are wrapped behind container bindings.
- `phpstan/phpstan` (level 9) and `squizlabs/php_codesniffer` (PSR-12) added
  as require-dev so the documented "minimum bar" is actually enforceable.
  A `phpstan-baseline.neon` absorbs pre-existing errors so new code is held
  to the bar without forcing a repo-wide refactor.
- `docs/dev/architecture-server.md` and `docs/reference/env-vars.md`.
- PSR-14 event dispatcher (Crell\Tukio). Playback, library-scan, and
  auth lifecycle events are now published from `PlaybackController`,
  `MediaScanner`, and `AuthManager`; plugins will be able to subscribe in
  Phase A.4. Twelve typed `readonly` event DTOs ship in
  `src/Common/Events/`. New env var `PHLEX_DEBUG_EVENTS` and `events`
  log channel. Canonical catalog in `docs/dev/event-reference.md`.
- Plugin loader (`Phlex\Plugins\PluginLoader`) with the full
  install / enable / disable / uninstall lifecycle. Plugins can be
  installed from a URL (HTTPS + `file://` by default; HTTP behind
  `PHLEX_PLUGINS_ALLOW_HTTP=1`) or from a local directory; each plugin
  gets its own Composer-resolved `vendor/` tree under
  `var/plugins/<name>/`. The lifecycle contract lives in
  `Phlex\Plugins\Contract\LifecycleInterface` (temporary home — moves to
  `Phlex\Shared\Plugin` in B.1). New table `plugins` (migration
  `migrations/003_plugins.sql`). New `plugins` log channel and config
  key. New env vars: `PHLEX_PLUGINS_ALLOW_HTTP`,
  `PHLEX_PLUGINS_REQUIRE_SIGNATURE`, `PHLEX_PLUGINS_COMPOSER_TIMEOUT`.
  Adds `symfony/process:^7.0`.
  See `docs/plugins/developer-guide.md` for the lifecycle diagram and
  a sample `LifecycleInterface` implementation.
- Plugin admin UI at `/admin/plugins` and JSON API under
  `/api/v1/admin/plugins/*` (list / install / enable / disable /
  uninstall). All routes gated by a new `AdminMiddleware` that reads
  the new `users.is_admin` flag (migration `004_admin_user_flag.sql`).
  The first user registered after the migration is auto-promoted to
  admin; subsequent users default to `is_admin = 0`. Adds runtime
  Composer dep `smarty/smarty:^4.0` (already used at runtime; now
  declared). OpenAPI spec at `docs/reference/api/admin-plugins.yaml`;
  end-user docs at `docs/plugins/install-from-url.md`. Editable
  settings UI deferred to a later phase — A.5 renders settings
  read-only with `secret: true` fields masked.
- Reference plugin
  [`phlex-plugin-example`](https://github.com/detain/phlex-plugin-example)
  — the first community-shaped Phlex plugin, published as its own
  public GitHub repo. Implements
  `Phlex\Plugins\Contract\LifecycleInterface` as a
  `metadata-provider` that returns `['title' => 'Hello, World']` for a
  fixed fixture path, and ships unsigned by design as the canonical
  fork-as-starter template for plugin authors. Installable through the
  A.5 admin UI by pasting
  `https://raw.githubusercontent.com/detain/phlex-plugin-example/main/plugin.json`
  into **Install from URL**. Server-side wiring: new fixture
  `tests/fixtures/plugins/example-manifest.json` mirrors the published
  manifest so the loader's URL-install test can use a `file://` URL,
  and `docs/plugins/install-from-url.md` /
  `docs/plugins/trusted-plugin-list.md` now reference the live
  example URL.

### Deprecated

- `Phlex\Server\Core\Application::getInstance()` — resolve services from
  the PSR-11 container instead. Slated for removal in Phase B.
