# Step N.26 — Plugin SDK developer guide (manifest reference, lifecycle, sample walkthrough)

**Phase:** N (End-User Documentation)
**Step:** N.26
**Depends on:** A.7 (plugin developer docs — already merged)
**Review:** No (doc-only step)
**Target repo:** phlex-server (local: /home/sites/phlex/)

## 1. Goal

Extend `docs/dev/plugin-sdk.md` (written in A.7) to a complete §7-layout
guide: TL;DR, code blocks, what-can-go-wrong (3 failures), next-steps.

The current page is a good internals reference but lacks the TL;DR
entry point, the concrete manifest schema with all fields documented,
the lifecycle (install → enable → disable → uninstall) as a numbered
walkthrough, the full sample-plugin walkthrough, and a what-can-go-wrong
section. N.26 adds all of those.

## 2. Context (what already exists)

After A.7:

- `docs/dev/plugin-sdk.md` — exists with §1 container bindings,
  §2 adding-a-plugin-type, §3 event-catalog, §4 phlex-shared migration,
  §5 loader extension points, §6 see-also. Content is accurate but
  structured as a reference, not a getting-started guide.
- `docs/plugins/developer-guide.md` — covers lifecycle, manifest,
  `LifecycleInterface`, `subscribedEvents()`, settings, packaging,
  signing, distribution, A.6 sample walkthrough. Links outward to
  `docs/dev/plugin-sdk.md`.
- `docs/plugins/manifest.md` — field reference for `plugin.json`.
- `docs/dev/event-reference.md` — event catalog with payload shapes.
- `src/Plugins/PluginLoader.php` (A.4) — lifecycle methods:
  `installFromUrl()`, `enable()`, `disable()`, `uninstall()`.
- `src/Plugins/Manifest.php` (A.3) — manifest schema validation.
- `phlex-plugin-example` (A.6) — reference plugin repo.

## 3. Scope

### Modify

- `docs/dev/plugin-sdk.md` — restructure existing sections into §7 layout
  and add the new sections listed in §4.

### No source changes

N.26 is doc-only. No `src/` changes, no migrations, no tests.

## 4. Doc content outline

### TL;DR (≤ 5 lines, new — prepend before §1)

One paragraph: what the SDK is (server internals reference for plugin
authors and loader contributors), who reads it (plugin authors
understanding lifecycle / manifest / hooks; host contributors adding
plugin types or events), and what N.26 added (manifest reference,
lifecycle walkthrough, sample, 3-failure what-can-go-wrong).

### §A — Plugin manifest reference (new section)

Reference table for every field in `plugin.json` with type, required/
optional, and description. Use the schema from A.3 / A.7:

```
plugin.json fields
──────────────────────────────────────────────────────────────────
name                            string   required   Unique ID, kebab-case
version                         string   required   Semver 1.0.0
phlex_min_server_version       string   required   e.g. "0.10.0"
type                           enum     required   metadata-provider |
                                                         auth-provider |
                                                         notifier |
                                                         scrobbler |
                                                         tuner |
                                                         transcoder-hook |
                                                         ui-theme |
                                                         arr-integration |
                                                         analytics-sink
entry                          string   required   FQCN of Plugin class
events                         string[] optional   List of phlex.* aliases
                                                        to subscribe
settings                       object   optional   Declarative form schema
                                                        (see below)
signature                      string   optional   sha256:<hex>
──────────────────────────────────────────────────────────────────
```

Settings sub-schema field entry pattern:

```
settings.<field_key>
  type:       string | number | boolean | array | object
  required:   boolean
  secret:     boolean   (masked in UI)
  default:    mixed     (optional)
  label:      string    (human label for settings form)
  options:    array     (optional, for enum-like dropdowns)
```

Add a note: `type` is the canonical plugin category used for:
- filtering in the plugin catalog UI,
- dispatch inside host subsystems (e.g. `MetadataManager` iterates
  all `metadata-provider` plugins),
- the `ManifestType` enum in both `Phlex\Plugins\ManifestType` and
  `Phlex\Shared\Plugin\ManifestType` (legacy alias).

### §B — Lifecycle walkthrough (new section, between §1 and existing §2)

Numbered step-by-step for each lifecycle phase. Replaces the brief
lifecycle mention in the existing §1 with a concrete walkthrough.

#### Install

```
1. Operator or API calls POST /api/v1/admin/plugins/install
2. HttpInstaller fetches plugin.json from the supplied URL
   - Refuses http:// unless PHLEX_PLUGINS_ALLOW_HTTP=1
3. SignatureVerifier checks sha256:<hex> against the trusted-key
   allowlist if PHLEX_PLUGINS_REQUIRE_SIGNATURE=1 (default: off)
4. Manifest::validate() parses and validates the manifest;
   rejects missing name / version / entry
5. tarball extracted to data/plugins/<name>/
6. ComposerRunner runs composer install --no-dev inside the plugin dir
   - Plugins MUST NOT ship a composer.json that conflicts with the
     host's pinned deps (use --no-dev and avoid conflicting require)
7. Plugin row inserted to plugins table (state = staged, disabled)
```

#### Enable

```
1. PATCH /api/v1/admin/plugins/<name>/enable
2. PluginLoader calls Plugin::onEnable($container)
3. Loader subscribes every phlex.* listener returned by
   Plugin::subscribedEvents() to the PSR-14 ListenerRegistry
4. Plugin registers its routes (if any) with the host router
5. Plugin state set to enabled in plugins table
```

#### Disable

```
1. POST /api/v1/admin/plugins/<name>/disable
2. PluginLoader calls Plugin::onDisable($container)
3. Loader unsubscribes all the plugin's listeners from the registry
4. Plugin state set to disabled (config / settings_json preserved)
```

#### Uninstall

```
1. DELETE /api/v1/admin/plugins/<name>
2. Loader calls Plugin::onDisable() (cleanup before removal)
3. Plugin's vendor dir removed (data/plugins/<name>/vendor/)
4. Plugin row deleted from plugins table
5. Plugin files removed (data/plugins/<name>/)
   - Optional cleanup hook: Plugin::onUninstall() called before
     files are deleted if the method exists
```

Add a mermaid sequence diagram (GitHub-flavour) showing the four
phases with the operator, PluginLoader, HttpInstaller, ListenerRegistry,
and Plugin as participants. Place it inline after the Uninstall step.

### §C — Hooks / events reference (enhance existing §3)

Expand the existing event catalog section with the five canonical
events that plugin authors subscribe to most. Each gets a one-liner
with payload shape:

| Event alias                   | Typical plugin type         | Payload fields                              |
| ----------------------------- | -------------------------- | ------------------------------------------- |
| `phlex.playback.started`     | scrobbler, analytics-sink  | `media_id`, `user_id`, `profile_id`, `position_ticks` |
| `phlex.playback.stopped`     | scrobbler, analytics-sink  | `media_id`, `user_id`, `position_ticks`, `completed` |
| `phlex.library.scanned`       | metadata-provider          | `library_id`, `item_count`                  |
| `phlex.user.created`          | notifier, analytics-sink   | `user_id`, `email`                          |
| `phlex.scrobble.submit`      | scrobbler                  | `media_id`, `user_id`, `scrobbler_type`, `progress_percent` |

Note: plugins subscribe in `Plugin::subscribedEvents()` which returns
`[EventName => MethodName]` (PSR-14 ListenerProvider pattern). The
loader registers those with `ListenerRegistry::addListener()`.

Full twelve-event catalog → [`docs/dev/event-reference.md`](event-reference.md).

### §D — Sample walkthrough: phlex-plugin-example (new section)

Concrete end-to-end walkthrough using the A.6 reference plugin
(https://github.com/detain/phlex-plugin-example). Numbered steps:

#### 1. Create `plugin.json`

```json
{
  "name": "phlex-plugin-example",
  "version": "1.0.0",
  "phlex_min_server_version": "0.10.0",
  "type": "metadata-provider",
  "entry": "Phlex\\Plugins\\Example\\Plugin",
  "events": ["phlex.playback.started", "phlex.library.scanned"],
  "settings": {
    "api_key": { "type": "string", "required": true, "secret": true }
  }
}
```

#### 2. Write the Plugin class

```php
<?php
declare(strict_types=1);

namespace Phlex\Plugins\Example;

use Phlex\Plugins\Contract\LifecycleInterface;
use Phlex\Shared\Events\PlaybackStartedEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Plugin implements LifecycleInterface
{
    private ?LoggerInterface $log;
    private ContainerInterface $container;
    private array $settings;

    public function __construct(
        LoggerInterface $log,
        ContainerInterface $container,
        array $settings = []
    ) {
        $this->log       = $log;
        $this->container = $container;
        $this->settings  = $settings;
    }

    public static function subscribedEvents(): array
    {
        return [
            PlaybackStartedEvent::class => 'onPlaybackStarted',
        ];
    }

    public function onEnable(ContainerInterface $container): void
    {
        $this->log->info('Example plugin enabled', [
            'has_api_key' => isset($this->settings['api_key']),
        ]);
    }

    public function onDisable(ContainerInterface $container): void
    {
        $this->log->info('Example plugin disabled');
    }

    public function onPlaybackStarted(PlaybackStartedEvent $event): void
    {
        $this->log->info('Playback started', [
            'media_id'   => $event->media_id,
            'user_id'    => $event->user_id,
            'position_ticks' => $event->position_ticks,
        ]);
        // Example: submit scrobble to external service via $this->settings['api_key']
    }
}
```

#### 3. Settings form

Explain how the `settings` block in `plugin.json` drives the admin UI
settings form. Settings are persisted as JSON in `plugins.settings_json`.
The plugin receives its settings as the `$settings` array in the
constructor. Secrets (`"secret": true`) are masked in the UI input
and transmitted to the plugin via the constructor — not stored in
plain text in logs.

#### 4. Package and sign

```bash
# 1. Install deps only (no dev dependencies)
composer install --no-dev --optimize-autoloader

# 2. Create the distribution archive
zip -r phlex-plugin-example-1.0.0.tar.gz data/plugins/phlex-plugin-example/

# 3. Sign it
sha256sum phlex-plugin-example-1.0.0.tar.gz
# Add the hex digest to plugin.json:
#   "signature": "sha256:<hex>"
```

Explain `PHLEX_PLUGINS_REQUIRE_SIGNATURE` env var and how the trust
allowlist works. Remind that `--no-dev` prevents the plugin's dev deps
from conflicting with the host's pinned composer dependencies.

### What can go wrong (new section, near end of doc)

Three canonical failures for plugin authors:

| Failure                              | Symptom                              | Cause                                          | Fix                                                           |
| ------------------------------------ | ------------------------------------ | ---------------------------------------------- | ------------------------------------------------------------- |
| **Missing required manifest fields** | `ManifestValidationError` at install | `plugin.json` missing `name`, `version`, or `entry` | Add all required fields; run `Manifest::validate()` locally before publishing |
| **Version mismatch silently ignored**| Plugin loads but hooks never fire    | `phlex.server.version.check` event not handled; server older than `phlex_min_server_version` | Upgrade phlex-server, or set `phlex_min_server_version` in manifest to the minimum server version your plugin actually requires |
| **Composer dep conflict**            | `ComposerRunner` exits non-zero       | Plugin's `composer.json` requires a package version that conflicts with the host's pinned deps | Use `--no-dev`, keep your `require` block minimal, test on a clean phlex-server install before publishing |
| **Signature verification failure**    | `plugin.signature.mismatch` on install | Downloaded tarball corrupted or plugin tampered with | Re-download; ensure the plugin is served over HTTPS; verify the signature hex matches `sha256sum` output on the author's published artifact |

Keep the first three as primary failures; the fourth (signature) is
secondary because signing is optional unless
`PHLEX_PLUGINS_REQUIRE_SIGNATURE=1`.

### Next steps (new section, final)

- [Plugin developer guide](../plugins/developer-guide.md) — the full
  author-facing guide (lifecycle, types, distribution, FAQ).
- [Plugin manifest reference](../plugins/manifest.md) — exhaustive
  `plugin.json` field reference.
- [Event catalog](../dev/event-reference.md) — all twelve events with
  payload shapes and dispatch sites.
- [Plugin installation (catalog)](../plugins/install-from-catalog.md) —
  how users install your plugin from the in-product catalog.
- [Plugin installation (URL)](../plugins/install-from-url.md) —
  manual URL install for pre-release / not-yet-catalogued plugins.

## 5. Cross-links

After writing, confirm:

- `docs/dev/plugin-sdk.md` TL;DR links to `developer-guide.md`.
- `docs/dev/plugin-sdk.md` §D (sample walkthrough) links to the
  phlex-plugin-example GitHub URL.
- `docs/plugins/developer-guide.md` links outward to
  `docs/dev/plugin-sdk.md` (the "server internals" counterpart).
- Every section in `docs/dev/plugin-sdk.md` has at least one
  `{file}` link to a sibling doc.

## 6. Verification

N.26 makes no source changes. The §0.4 minimum bar is trivially
satisfied:

```bash
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

Doc-side checks (optional, not a hard gate — `markdownlint` config
not yet established):

```bash
# Confirm the new §7 sections exist
grep -E "^##? (TL;DR|What can go wrong|Next steps|Plugin manifest|Lifecycle|Hooks|events reference|Sample)" docs/dev/plugin-sdk.md

# Confirm code blocks have language hints for syntax colouring
grep -c '```php' docs/dev/plugin-sdk.md    # should be > 0
grep -c '```json' docs/dev/plugin-sdk.md   # should be > 0
grep -c '```bash' docs/dev/plugin-sdk.md   # should be > 0
```

## 7. Git ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short                          # MUST be empty
git branch --show-current                     # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b n.26-dev-plugins

# ─── 2. Do the doc work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step N.26: plugin SDK developer guide (manifest reference, lifecycle, sample)"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, merge, cleanup ───
gh pr create \
  --title "Step N.26: plugin SDK developer guide" \
  --body  "Extends docs/dev/plugin-sdk.md to §7 layout: TL;DR, full manifest reference table, lifecycle walkthrough (install/enable/disable/uninstall), hooks/events table, sample-plugin walkthrough, what-can-go-wrong (3 failures), next-steps. No src/ changes. Implements step N.26 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                         # MUST show the N.26 commit
git branch --list 'n.26-*'                  # MUST be empty
```

(End of file - total 313 lines)
