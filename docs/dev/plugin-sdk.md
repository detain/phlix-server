# Plugin SDK — internals reference

This document is for **phlex-server contributors** who want to extend
the plugin loader itself, add a new plugin slot to a host subsystem,
or document new container bindings that plugin authors can resolve.

If you are writing a plugin (not extending the host), the document
you want is [`docs/plugins/developer-guide.md`](../plugins/developer-guide.md).

The contracts described here are stable enough to be relied on by
plugin authors. Step B.1 hoists the most important ones
(`LifecycleInterface`, `ManifestType`, the manifest value object) into
a separate `phlex-shared` package so plugins can depend on the
contracts without dragging in the whole server — see
[§4](#4-phlex-shared-namespace-migration-plan).

---

## 1. Container bindings plugins can resolve

`PluginLoader::enable()` instantiates the plugin's entry class through
`Psr\Container\ContainerInterface::get()`, and passes the same
container to `onEnable()`. That means every binding the host
container exposes is fair game for a plugin to type-hint in its
constructor (PHP-DI will autowire them) or to ask for inside
`onEnable()`.

The bindings below are **stable** — they ship as part of the host
container today and are intended for plugin use. Internal-only
bindings (provider classes, factories, schema loaders) are deliberately
excluded.

### Logging

| Container ID                                | Type                                         | Purpose                                                |
| ------------------------------------------- | -------------------------------------------- | ------------------------------------------------------ |
| `Psr\Log\LoggerInterface`                   | Default Monolog channel                      | Generic logging when you don't care about the channel  |
| `Phlex\Common\Logger\LoggerFactory`         | Factory                                      | Resolve `LoggerFactory::get(LogChannels::PLUGINS)` etc.|
| `logger.plugins`                            | `Phlex\Common\Logger\StructuredLogger`       | Plugins channel — recommended for plugin output        |
| `logger.auth`, `logger.http`, `logger.media`, `logger.session`, `logger.streaming`, `logger.websocket`, `logger.events` | `StructuredLogger` | Named loggers per `LogChannels` constant               |
| `Phlex\Common\Logger\AuditLogger`           | `AuditLogger`                                | Security-event audit trail                             |

Convention: use `logger.plugins` from plugin code so the operator can
filter your output with the `--channel=plugins` log-cat dial.

### Database

| Container ID                       | Type                              | Notes                                       |
| ---------------------------------- | --------------------------------- | ------------------------------------------- |
| `Workerman\MySQL\Connection`       | `Workerman\MySQL\Connection`      | Parameterized queries only (see CLAUDE.md). |

Plugins must use parameterized queries. Never interpolate plugin
input into SQL strings — `$db->query('... ?', [$value])` is the only
sanctioned shape.

### Events (PSR-14)

| Container ID                                       | Type                                            | Purpose                                         |
| -------------------------------------------------- | ----------------------------------------------- | ----------------------------------------------- |
| `Psr\EventDispatcher\EventDispatcherInterface`     | `Crell\Tukio\Dispatcher`                        | **Publish** events                              |
| `Phlex\Common\Events\ListenerRegistry`             | `ListenerRegistry`                              | **Subscribe** listeners (the loader uses this)  |
| `Phlex\Common\Events\EventDispatcherFactory`       | Factory                                         | Rarely needed — exposed for tests               |

Plugins normally subscribe via `subscribedEvents()` and let the loader
register listeners through `ListenerRegistry`. Direct use of the
registry is fair game for advanced plugins that want priority control.

### Auth

| Container ID                              | Type                  | Purpose                                |
| ----------------------------------------- | --------------------- | -------------------------------------- |
| `Phlex\Auth\AuthManager`                  | `AuthManager`         | Register / login / logout / refresh    |
| `Phlex\Auth\UserRepository`               | `UserRepository`      | Look up users by id, username, email   |
| `Phlex\Auth\JwtHandler`                   | `JwtHandler`          | HS256 token operations                 |
| `Phlex\Auth\UserProfileManager`           | `UserProfileManager`  | Profile CRUD (rating filter, PIN)      |

### Media

| Container ID                                      | Type                  | Purpose                                          |
| ------------------------------------------------- | --------------------- | ------------------------------------------------ |
| `Phlex\Media\Library\LibraryManager`              | `LibraryManager`      | Library list / detail                            |
| `Phlex\Media\Library\ItemRepository`              | `ItemRepository`      | Media-item CRUD (parses `metadata_json`)         |
| `Phlex\Media\Library\MediaScanner`                | `MediaScanner`        | Trigger ad-hoc rescans (avoid in event handlers) |
| `Phlex\Media\Metadata\MetadataManager`            | `MetadataManager`     | Resolve metadata via configured providers        |

### Session

| Container ID                                | Type                  | Purpose                                 |
| ------------------------------------------- | --------------------- | --------------------------------------- |
| `Phlex\Session\SessionManager`              | `SessionManager`      | Device session CRUD                     |
| `Phlex\Session\PlaybackController`          | `PlaybackController`  | Continue-watching / progress reporting  |

### Plugin-system services

The plugin loader itself is in the container too, which is useful for
plugins that want to enumerate or introspect other plugins:

| Container ID                                              | Type                | Purpose                                |
| --------------------------------------------------------- | ------------------- | -------------------------------------- |
| `Phlex\Plugins\PluginLoader`                              | `PluginLoader`      | List / install / enable / etc          |
| `Phlex\Plugins\Repository\PluginRepository`               | `PluginRepository`  | Read own settings, find sibling rows   |
| `Phlex\Plugins\Signature\SignatureVerifier`               | `SignatureVerifier` | Inspect the current trust posture      |

> **Adding a new binding for plugins.** Bindings considered "plugin
> stable" are documented in this table. To add one, register it in a
> service provider under `src/Common/Container/Providers/`, add a row
> here, and bump the matrix row in the developer guide if it changes
> what a given plugin type can do.

---

## 2. Adding a new plugin type

The eleven-value enum in `Phlex\Shared\Plugin\ManifestType` (shipped
in the `detain/phlex-shared` Composer package) is the master list of
plugin categories. The legacy `Phlex\Plugins\ManifestType` FQCN remains
available as a deprecated alias through 0.11.x. Each value also appears in:

- `docs/plugins/manifest.schema.json` (the `type` enum block).
- `docs/plugins/manifest.md` (the field reference table).
- `docs/plugins/developer-guide.md` §2 (the type matrix).
- `PHLEX_EXPANSION_PLAN.md` §5 (the master plan).

These five sites are kept manually in sync — there is no
single-source codegen yet. **Adding a new type is therefore a
multi-file edit** and every site needs to be touched in the same PR.

### Recipe

1. **Justify the type.** A new type only makes sense if there is a
   host-side subsystem that will iterate registered plugins of that
   type (e.g. `MetadataManager` calling `metadata-provider` plugins).
   Without a dispatch path, a new type is dead documentation — better
   to use one of the existing values until the host side is ready.
2. **Add the enum case** in `detain/phlex-shared`'s
   `src/Plugin/ManifestType.php`. Pick a kebab-case value and
   document the use case in the docblock. Bump `phlex-shared` to a new
   tag and bump `phlex-server`'s composer require accordingly.
3. **Update the JSON schema** at `docs/plugins/manifest.schema.json`
   — add the value to the `type` enum array.
4. **Update the field tables** in `docs/plugins/manifest.md` and
   `docs/plugins/developer-guide.md` §2 (the matrix). Flag the
   implementation status honestly — "Loader yes; manager dispatch
   wired in Phase X" beats over-claiming.
5. **Update `PHLEX_EXPANSION_PLAN.md` §5** so the master plan and the
   docs agree.
6. **Add a fixture** under `tests/Fixtures/Plugins/valid-<type>.json`
   so the manifest validator tests cover the new type at least once.
7. **Wire the dispatch path** in the relevant subsystem. The
   canonical pattern (once Phase C / D / E start landing it) is:

   ```php
   // Inside the subsystem that owns the type.
   foreach ($pluginLoader->getEnabled() as $installed) {
       if ($installed->manifest->manifestType() !== ManifestType::MetadataProvider) {
           continue;
       }
       $entry = $container->get($installed->manifest->entry);
       // call entry-specific method, e.g. $entry->lookup($mediaItem)
   }
   ```

   Each subsystem will eventually wrap this in its own typed registry
   (`MetadataProviderRegistry`, `ScrobblerRegistry`, …) so plugins
   talk to it through a stable interface rather than relying on
   container introspection. Until those registries land, the pattern
   above is the pragmatic interim.

---

## 3. The event catalog as integration points

The twelve events in
[`docs/dev/event-reference.md`](event-reference.md) are **public
stable extension points**. The loader's contract with plugin authors
is that:

- Once an event class is added to `src/Common/Events/`, its **payload
  field set and dispatch site** become part of the public API. The
  payload fields are `readonly` and may only grow (new fields are
  additive — never reorder or repurpose existing fields).
- Renaming an event class FQCN is a **breaking change** that requires
  a deprecation cycle of at least one minor release. The same applies
  to renaming a manifest alias.
- Removing an event is **forbidden** in a minor release. Mark it
  `@deprecated`, keep dispatching it for the deprecation window, and
  remove only at the next major release.

### Subscriber rules

Plugins (and host listeners) **must not mutate** the event payload —
events are `readonly` DTOs. The current PHP type system enforces this
at the language level (assigning to a `readonly` property after
construction is a fatal `Error`); plugins that try will crash hard.

If a plugin needs to influence behaviour (block playback, rewrite a
download URL, …), the right pattern is a **separate command-side API
on the relevant service**, exposed through the container — not a
mutable event payload. Phase A intentionally does not ship any
mutating extension points; they will be designed per-subsystem as
those subsystems gain plugin slots.

### Adding a new event

1. Add the event class to `detain/phlex-shared` under
   `src/Events/<Area>/<Name>.php`. Extend
   `Phlex\Shared\Events\AbstractEvent`. Make every payload field
   `readonly`. Tag a new `phlex-shared` release and bump
   `phlex-server`'s composer require.
2. Pick a manifest alias of the form
   `phlex.<area>.<verb>(.<sub>)*` (regex `^phlex\.[a-z]+(?:\.[a-z]+)*$`).
3. Wire the alias in `Phlex\Shared\Plugin\EventNameMap::ALIAS_TO_FQCN`
   (in `phlex-shared`). Keep the array literal sorted by alias.
4. Add a row to the catalog table in
   `docs/dev/event-reference.md` (in `phlex-server`) — payload fields,
   dispatch site, typical listener — and to the twelve-events table
   in `docs/plugins/developer-guide.md` §5.
5. Dispatch the event from the relevant service via
   `EventDispatcherInterface::dispatch(new …Event(...))`. Wrap the
   dispatch in a try/catch only if you genuinely want broken
   listeners to break the dispatching code path; otherwise let Tukio
   bubble exceptions out of the dispatcher and rely on its built-in
   error-isolation behaviour.
6. If the new event corresponds to a plugin type's typical
   subscription, update the type matrix in the developer guide.

---

## 4. `phlex-shared` migration

Step B.3 of `PHLEX_EXPANSION_PLAN.md` extracted the **contracts** —
the parts of the plugin system that plugin authors depend on — into
the separate [`detain/phlex-shared`](https://github.com/detain/phlex-shared)
Composer package. Plugins can now require:

```json
"require": {
    "detain/phlex-shared": "^0.2",
    "psr/container": "^1.1 || ^2.0"
}
```

rather than vendoring the entire phlex-server tree.

### What moved to `phlex-shared` in B.3

- `Phlex\Plugins\Contract\LifecycleInterface`
  → `Phlex\Shared\Plugin\LifecycleInterface`
- `Phlex\Plugins\ManifestType`
  → `Phlex\Shared\Plugin\ManifestType`
- `Phlex\Plugins\Manifest`, `Phlex\Plugins\ManifestValidationError`,
  `Phlex\Plugins\EventNameMap`
  → `Phlex\Shared\Plugin\…`. The validator
  (`Phlex\Plugins\Manifest\ManifestSchema`) stays in phlex-server
  because it depends on the bundled JSON Schema file.
- `Phlex\Common\Events\AbstractEvent` and the twelve concrete event
  classes under `src/Common/Events/`
  → `Phlex\Shared\Events\…`. The manifest aliases stay stable.

All legacy FQCNs remain available as deprecated `class_alias` /
interface-bridge entries through 0.11.x; they are removed in 0.12.0.
See `src/Plugins/AliasCompatShim.php` for the alias registrations and
`src/Plugins/Contract/LifecycleInterface.php` for the interface bridge.

### What stays in `phlex/phlex` (host-only)

- The loader itself (`PluginLoader`, `HttpInstaller`,
  `ComposerRunner`, `SignatureVerifier`, `PluginRepository`,
  `EventNameMap`).
- The container providers under `src/Common/Container/Providers/`.
- The admin UI and JSON API controllers.

### Backwards compatibility

For one minor release after B.1, the old FQCNs under
`Phlex\Plugins\Contract\…` and `Phlex\Common\Events\…` will continue
to work as **`class_alias()`-style aliases** to the new
`Phlex\Shared\…` classes. Plugin authors get a full release cycle to
update their imports; CI will flag the old FQCNs with a deprecation
notice but builds will not break.

Plugin authors should:

- Read the B.1 release notes when they land.
- Run the upgrade rewriter (we'll ship a sed script as part of B.1)
  to update imports in one pass.
- Bump `phlex_min_server_version` in their manifest to the release
  that introduced `phlex-shared`.

---

## 5. Loader extension points

The loader is composed of small, single-responsibility collaborators
so each can be decorated or replaced in tests and forks:

| Collaborator                                      | What it owns                                          | How to extend                                                     |
| ------------------------------------------------- | ----------------------------------------------------- | ----------------------------------------------------------------- |
| `Phlex\Plugins\Installer\HttpInstaller`           | Fetch, extract, stage to `var/plugins/<name>/`        | Subclass or decorate; rebind in container.                        |
| `Phlex\Plugins\Installer\ComposerRunner`          | Run `composer install --no-dev` per plugin            | Subclass to inject custom env, timeouts, or proxy settings.       |
| `Phlex\Plugins\Signature\SignatureVerifier`       | Trust check against allowlist                         | Replace with a real PGP / sigstore-backed implementation.         |
| `Phlex\Plugins\Repository\PluginRepository`       | `plugins` table CRUD                                  | Subclass for multi-tenant filtering, audit decoration, etc.       |
| `Phlex\Plugins\PluginLoader`                      | Public orchestrator                                   | Avoid subclassing — wrap with a façade if you need new operations.|
| `Phlex\Common\Container\Providers\PluginsProvider`| Container wiring                                      | Append your own provider to the `ContainerFactory` stack.         |

The `PluginsProvider` reads three env vars at provider-register time:

- `PHLEX_PLUGINS_COMPOSER_TIMEOUT` — integer seconds, default
  `ComposerRunner::DEFAULT_TIMEOUT_SECONDS`.
- `PHLEX_PLUGINS_REQUIRE_SIGNATURE` — truthy strings (`1`, `true`,
  `yes`, `on`) make `SignatureVerifier` reject unsigned plugins.
- The plugins base directory comes from `appConfig['plugins_base_dir']`
  with a default of `var/plugins/`.

When you add a new env var that the loader honours, document it both
here and in `docs/reference/env-vars.md`.

---

## 6. See also

- [`docs/plugins/developer-guide.md`](../plugins/developer-guide.md)
  — the plugin author's view.
- [`docs/plugins/manifest.md`](../plugins/manifest.md) — manifest
  field reference.
- [`docs/dev/event-reference.md`](event-reference.md) — event catalog.
- [`docs/dev/architecture-server.md`](architecture-server.md) —
  container, bootstrap, request lifecycle.
- [`PHLEX_EXPANSION_PLAN.md`](../../PHLEX_EXPANSION_PLAN.md) §5,
  §10, and Phase B for the long-term plan around contracts, signing,
  and the `phlex-shared` extraction.
