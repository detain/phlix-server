# Step A.4 — Plugin loader + lifecycle (install/enable/disable/uninstall)

**Phase:** A (Plugin Foundation & DI)
**Step:** A.4
**Depends on:** A.3
**Review:** Yes — see `a.4-plugin-loader-review.md`
**Target repo:** detain/phlex (local: /home/sites/phlex)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Make the manifest defined in A.3 **executable**. After A.4 lands:

1. An operator can hand the loader a `plugin.json` URL (or a local
   directory) and the plugin is downloaded, parsed, validated, and
   installed into `var/plugins/<name>/`, with its own `vendor/` resolved
   via `composer install` run in process isolation (no global vendor
   pollution).
2. `enable($name)` loads the entry class through the A.1 container,
   subscribes its declared events to the A.2 dispatcher (translating
   manifest aliases like `phlex.playback.started` to FQCNs), and
   persists `enabled = 1` in a new `plugins` DB table.
3. `disable($name)` unsubscribes and persists `enabled = 0` but keeps
   the on-disk files and per-plugin settings.
4. `uninstall($name)` removes the directory and the DB row.

This is the step that exercises every Phase A prerequisite. A.5 builds
the admin UI on top of it.

## 2. Context (what already exists)

After A.3:

- `src/Plugins/Manifest.php` + `ManifestType.php` —
  parsed/validated manifest.
- `docs/plugins/manifest.schema.json` — the formal contract.
- `tests/Fixtures/Plugins/valid-*.json` — fixtures the loader can re-use.

From earlier steps:

- `src/Common/Container/ContainerFactory.php` (A.1) —
  `Psr\Container\ContainerInterface`, autowiring.
- `src/Common/Events/{EventDispatcherFactory,ListenerRegistry}.php` and
  the twelve event classes (A.2).
- `Workerman\MySQL\Connection` for the DB.

Existing patterns to follow:

- `migrations/001_initial_schema.sql` and
  `migrations/002_user_profiles_and_parental_controls.sql` — schema
  format. All PKs `CHAR(36)`, UUID via the local `generateUuid()`
  helper (already duplicated across many classes — re-use the pattern,
  do **not** introduce a UUID library).
- `Workerman\MySQL\Connection::query("... ?", [$id])` — always
  parameterized.

## 3. Scope — files to create / modify

### Create

- `src/Plugins/PluginLoader.php` — the orchestrator. Public surface:
  - `install(string $sourceUrl): Manifest`
  - `installFromDirectory(string $localPath): Manifest`
  - `enable(string $name): void`
  - `disable(string $name): void`
  - `uninstall(string $name): void`
  - `listInstalled(): InstalledPlugin[]`
  - `getEnabled(): InstalledPlugin[]`
- `src/Plugins/InstalledPlugin.php` — readonly DTO bundling `Manifest
  $manifest, bool $enabled, \DateTimeImmutable $installedAt, array
  $settings`.
- `src/Plugins/Contract/LifecycleInterface.php` — the contract every
  plugin entry class implements:
  - `onEnable(\Psr\Container\ContainerInterface $container): void`
  - `onDisable(): void`
  - `subscribedEvents(): array` — returns `[FQCN => callable|method]`.
  - Class-level docblock notes: "**Temporary home — moves to
    `Phlex\Shared\Plugin\LifecycleInterface` in Step B.1.** Plugin
    authors who target master may pin to this interface; once B.1
    lands, `Phlex\Plugins\Contract\LifecycleInterface` becomes a
    deprecated alias for one minor release."
- `src/Plugins/EventNameMap.php` — static map between manifest aliases
  and event FQCNs. E.g., `phlex.playback.started` →
  `Phlex\Common\Events\Playback\PlaybackStarted::class`. Methods:
  `fromAlias(string $alias): ?string`, `toAlias(string $fqcn):
  ?string`, `aliases(): array<string,string>`.
- `src/Plugins/Repository/PluginRepository.php` —
  `Workerman\MySQL\Connection`-backed CRUD for the `plugins` table.
- `src/Plugins/Installer/HttpInstaller.php` — downloads
  `plugin.json` + zipped source from a URL, extracts to
  `var/plugins/<name>/`.
- `src/Plugins/Installer/ComposerRunner.php` — shells out to `composer
  install --no-dev --no-interaction` inside the plugin directory; uses
  `proc_open` with a hard timeout (default 120s) and rejects any
  manifest that does not include a `composer.json` (plugins MUST be
  Composer projects to get scoped dependencies).
- `src/Plugins/Exception/PluginInstallException.php`
- `src/Plugins/Exception/PluginEnableException.php`
- `src/Plugins/Exception/PluginNotFoundException.php`
- `src/Common/Container/Providers/PluginsProvider.php` — registers
  `PluginLoader`, `PluginRepository`, the installers, and an
  auto-enable bootstrap that runs at container build time to re-attach
  enabled plugins to the dispatcher.
- `migrations/003_plugins.sql` — new table:
  ```sql
  CREATE TABLE plugins (
      id CHAR(36) NOT NULL PRIMARY KEY,
      name VARCHAR(64) NOT NULL UNIQUE,
      version VARCHAR(32) NOT NULL,
      type VARCHAR(32) NOT NULL,
      entry VARCHAR(255) NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 0,
      installed_at DATETIME NOT NULL,
      settings_json JSON NULL,
      manifest_json JSON NOT NULL,
      INDEX idx_plugins_enabled (enabled)
  ) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```
- `tests/Unit/Plugins/PluginLoaderTest.php` — see §5.
- `tests/Unit/Plugins/Repository/PluginRepositoryTest.php`
- `tests/Unit/Plugins/EventNameMapTest.php`
- `tests/Integration/Plugins/InstallEnableDisableTest.php` — uses a
  fixture plugin from `tests/Fixtures/Plugins/fixture-plugin/`.
- `tests/Fixtures/Plugins/fixture-plugin/plugin.json` — minimal
  metadata-provider manifest.
- `tests/Fixtures/Plugins/fixture-plugin/composer.json` — empty
  composer file for the install step.
- `tests/Fixtures/Plugins/fixture-plugin/src/FixturePlugin.php` —
  implements `LifecycleInterface`, subscribes to `PlaybackStarted`.

### Modify

- `composer.json` — add `symfony/process: ^7.0` for the `proc_open`
  wrapper used by `ComposerRunner`.
- `composer.lock` — regenerate.
- `src/Common/Container/ContainerFactory.php` — register
  `PluginsProvider`.
- `scripts/run-migrations.php` — no change needed; it already discovers
  every `migrations/*.sql` file in order.
- `.gitignore` — add `var/plugins/` (plugin installs are runtime data,
  not source; the test fixture lives under `tests/Fixtures/` so it's
  immune to the ignore).
- `CHANGELOG.md` — `Added: plugin install/enable/disable/uninstall
  lifecycle. plugin.json manifests can be installed from URL or local
  directory; each plugin gets its own composer-resolved vendor dir
  under var/plugins/<name>/. New table plugins (migration 003).`
- `AGENTS.md` / `CLAUDE.md` — Caliber regenerates.

### Delete

- None.

## 4. Approach

1. **Add `symfony/process`.** Wraps `proc_open` with timeouts and
   captured stdout/stderr — much safer than rolling our own.
2. **`HttpInstaller::install($sourceUrl)`:**
   - Fetch `<sourceUrl>` — must be HTTPS unless
     `PHLEX_PLUGINS_ALLOW_HTTP=1` (default off).
   - If the URL points at a `plugin.json`, treat it as a "stub" pointing
     at the real source via a `source` field that holds a tarball URL.
     If the URL points at a `.tar.gz` or `.zip`, treat the whole
     archive as the source.
   - Extract into a temp dir, parse `plugin.json` via
     `Manifest::fromJson()`, run `Manifest::validate()`, fail loudly on
     any errors (`PluginInstallException` carrying the
     `ManifestValidationError[]`).
   - Check `phlex_min_server_version` against the running server's
     version (constant in `src/Common/Version.php` — if it doesn't
     exist, add it: `class Version { public const STRING = '0.10.0'; }`).
     If unsatisfied, throw.
   - Verify signature if present (sha256 of the tarball matches
     `signature` field). If the manifest has no signature, log a
     warning via the AUTH channel and proceed only if
     `PHLEX_PLUGINS_ALLOW_UNSIGNED=1` (default on for now; A.5 will
     surface a UI warning to the operator).
   - Move temp dir to `var/plugins/<name>/`.
3. **`ComposerRunner::install($pluginDir)`:**
   - Refuse if no `composer.json` exists in `$pluginDir`.
   - Refuse if the plugin's composer.json declares a
     `require` entry that conflicts with the host server's locked
     versions (read `composer.lock` of the host, fail on direct
     conflicts; transitive divergence is fine because the plugin has
     its own vendor dir).
   - Run `composer install --no-dev --no-interaction --no-progress`
     with cwd set to `$pluginDir`. Capture stdout/stderr. Hard timeout
     120s (configurable via `PHLEX_PLUGINS_COMPOSER_TIMEOUT`).
4. **`PluginLoader::enable($name)`:**
   - Load `Manifest` + on-disk plugin via the repository.
   - Require `$pluginDir/vendor/autoload.php` — exactly once per
     enable call (re-enables after disable simply re-require it; PHP
     autoload cache makes this idempotent).
   - Resolve the `Manifest::$entry` FQCN through the container.
   - Assert it implements `LifecycleInterface`. Throw if not.
   - Call `$plugin->onEnable($container)`.
   - For each `subscribedEvents()` entry, translate alias→FQCN via
     `EventNameMap` and call
     `$listenerRegistry->subscribe($fqcn, $callable)`. Keep the
     callable references in a per-plugin array so `disable()` can
     unsubscribe them.
   - Persist `enabled = 1`.
5. **`PluginLoader::disable($name)`:**
   - Unsubscribe every callable previously registered.
   - Call `$plugin->onDisable()`.
   - Persist `enabled = 0`. Keep settings.
6. **`PluginLoader::uninstall($name)`:**
   - If enabled, call `disable()` first.
   - Recursive delete of `var/plugins/<name>/`. Use a vetted helper
     (`Symfony\Component\Filesystem\Filesystem::remove()` — pull in
     `symfony/filesystem`? Or hand-roll a `RecursiveDirectoryIterator`
     loop). Pick the hand-rolled helper to avoid adding another dep;
     write it as `src/Plugins/Util/RecursiveDelete.php` with its own
     unit tests.
   - Delete the DB row.
7. **Auto-enable at boot.** `PluginsProvider::register()` schedules a
   post-build callback that loads every `enabled = 1` row and calls
   the loader's internal enable path. This means restarting the server
   automatically re-subscribes plugins.
8. **`InstalledPlugin`** is intentionally read-only — callers mutate
   via `PluginLoader`, not by editing the DTO.

## 5. Tests (REQUIRED — §0.4 minimum bar)

**Unit tests** (`PluginLoaderTest` — uses Mockery for `HttpInstaller`,
`ComposerRunner`, `PluginRepository`, `ListenerRegistry`):

1. `test_install_from_directory_persists_manifest_and_returns_it`.
2. `test_install_rejects_invalid_manifest_with_install_exception`.
3. `test_install_rejects_unsupported_phlex_min_server_version`.
4. `test_install_writes_to_var_plugins_subdir_named_after_plugin`.
5. `test_enable_requires_lifecycle_interface_or_throws`.
6. `test_enable_subscribes_each_declared_event_to_listener_registry`.
7. `test_enable_translates_manifest_alias_to_event_fqcn`.
8. `test_enable_persists_enabled_true`.
9. `test_disable_unsubscribes_all_previously_subscribed_listeners`.
10. `test_disable_calls_on_disable_on_plugin_entry_class`.
11. `test_uninstall_calls_disable_first_when_currently_enabled`.
12. `test_uninstall_removes_var_plugins_subdir_and_db_row`.
13. `test_listInstalled_returns_dtos_with_settings_hydrated`.

`EventNameMapTest`:

14. `test_every_event_class_has_a_round_trip_alias` — loop
    `EventNameMap::aliases()`, assert
    `EventNameMap::fromAlias($alias) === $fqcn` and
    `EventNameMap::toAlias($fqcn) === $alias`.

`PluginRepositoryTest`:

15. `test_insert_then_find_returns_same_row`.
16. `test_update_enabled_persists_flag`.
17. `test_delete_removes_row`.

**Integration test** (`InstallEnableDisableTest`, in
`tests/Integration/Plugins/`):

18. `test_full_lifecycle_with_fixture_plugin` — installs
    `tests/Fixtures/Plugins/fixture-plugin/` via
    `installFromDirectory()`, enables, dispatches a fake
    `PlaybackStarted` event, asserts the fixture plugin's listener
    fired exactly once. Disables, dispatches again, asserts the
    listener does **not** fire. Uninstalls, asserts the var dir is
    gone.

**Coverage target:** ≥ 85 % on `src/Plugins/**`.

**Integration boundary:** A.4 touches DB + filesystem + process exec.
The integration test above plus the unit tests on the repository
cover the boundaries.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"A configurable env var or config/*.php key"** →
  `docs/reference/env-vars.md` adds:
  - `PHLEX_PLUGINS_ALLOW_HTTP` (`0` / `1`, default `0`)
  - `PHLEX_PLUGINS_ALLOW_UNSIGNED` (`0` / `1`, default `1`)
  - `PHLEX_PLUGINS_COMPOSER_TIMEOUT` (seconds, default `120`)
- **"The plugin API"** → flesh out
  `docs/plugins/developer-guide.md` (created as stub in A.3) with a
  "Lifecycle" section: install, enable, disable, uninstall sequence
  diagram in mermaid, plus a code sample for
  `LifecycleInterface::subscribedEvents()`.
- **"A new library type"** → N/A (plugins can ship `library-type`
  plugins later but A.4 doesn't ship one).
- **"Anything"** → `README.md` adds bullet `* Plugin system —
  install/enable/disable/uninstall lifecycle, sandboxed vendor dirs,
  signature-checked`.
- **CHANGELOG** → already in §3 Modify.

PHPDoc per §0.4 on every new public class/method. The
`LifecycleInterface` docblock spells out the contract loud and clear
because plugin authors will copy-paste it.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] All §3 "Create" files exist.
- [ ] All §3 "Modify" files updated.
- [ ] `composer.json` declares `symfony/process:^7.0`.
- [ ] `migrations/003_plugins.sql` runs cleanly via
      `php scripts/run-migrations.php`.
- [ ] `var/plugins/` is git-ignored.
- [ ] Fixture plugin at `tests/Fixtures/Plugins/fixture-plugin/` is
      runnable through the integration test.
- [ ] `./vendor/bin/phpunit` — green.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] Coverage of `src/Plugins/**` ≥ 85 %.
- [ ] PHPDoc on every new public class/method, including the
      "moves to phlex-shared in B.1" note on `LifecycleInterface`.
- [ ] Docs from §6 updated.
- [ ] CHANGELOG.md updated.
- [ ] Caliber pre-commit hook ran; regenerated agent files staged.
- [ ] Git ritual §8 below executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION: confirm we're starting from clean master ───
cd /home/sites/phlex
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b a.4-plugin-loader

# ─── 2. Do the work; add tests; update docs (§0.4); add PHPDocs ───

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text | grep 'Plugins'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync (hook active) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step A.4: plugin loader + install/enable/disable/uninstall lifecycle"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, auto-merge, branch delete ───
gh pr create \
  --title "Step A.4: plugin loader + lifecycle" \
  --body  "Adds PluginLoader, HttpInstaller, ComposerRunner, PluginRepository, EventNameMap, LifecycleInterface, and migration 003_plugins.sql. Plugins can now be installed from URL or local dir, enabled (subscribing their declared events to the A.2 dispatcher), disabled, and uninstalled. Implements step A.4 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'a.4-*'                   # MUST be empty
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `a.4-plugin-loader-review.md`. Reviewer
must also run the integration test in isolation:

```bash
./vendor/bin/phpunit tests/Integration/Plugins/InstallEnableDisableTest.php
```

and confirm `var/plugins/` is clean after the test tear-down.
