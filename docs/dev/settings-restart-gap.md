# `restart: true` settings — FIXED, with one documented asymmetry

**Status:** the mechanism is implemented and tested. **Scope:** `phlix-server`
(a sibling gap still exists in `phlix-hub`, untouched by this work).

Read this before telling a user — in docs, in a CHANGELOG entry, in a docblock, or in the admin UI —
that restarting the server makes a specific setting take effect.

**All sixteen `restart: true` keys tracked here now genuinely apply on restart.** One further
key, `hwaccel.probe_timeout`, was **deleted** from `server-settings.schema.json` in `phlix-shared`
**v0.26.0** rather than wired — it had no consumer and could not be given one cheaply or safely
(reasoning below). The one remaining caveat is the `process.<worker>.enabled` enable/disable
asymmetry, which is now disclosed in the keys' own admin-facing `helpText`, not merely here.

> **Scope note.** This table tracks the keys covered by the `EffectiveConfig` work plus
> `tmdb.api_key`. The schema has since grown other `restart: true` keys (the
> `server.rate_limit.*` family, `metrics.enabled`, `theme_music.*`, `dlna.*`) that were added
> already-wired and are not re-verified here. See
> [Audit of the other restart false keys](#audit-of-the-other-restart-false-keys)
> for the inverse problem — keys whose schema flag was wrong in the other direction.

---

## What was broken

`server-settings.schema.json` marks boot-only keys `"restart": true`, the admin SPA renders a
"requires a server restart to take effect" affordance for them, and offers a **Restart server**
button wired to `POST /api/v1/admin/restart`.

That promise was false. The effective value of a setting is `override ?? default`, resolved by
`Phlix\Admin\SettingsRepository::getEffective()` — and **almost nothing read it**. Every other
consumer read the boot `$config` array or `include`d a `config/*.php` file directly, and nothing
merged the `server_settings` table into either. Neither a graceful reload nor a full
`systemctl restart` changed behaviour.

## What the fix is

`Phlix\Config\EffectiveConfig` (`src/Config/EffectiveConfig.php`) loads the persisted overrides once
per process and overlays them onto the config defaults, using the **same dotted-key semantics as
`SettingsRepository`**: the leading segment names the config *file*, the rest walks into the array
that file returns.

It is reached two ways:

- **`bootstrapAndOverlay($config)`** — called at the top of every `onWorkerStart` in `start.php`
  (HTTP, WebSocket, hub-heartbeat, relay-tunnel, and each managed worker) and in `public/index.php`,
  **before `ContainerFactory::create($config)`**. Every DI provider that reads boot config therefore
  sees the effective value with no per-provider wiring. Mirrored across both entry points (§7).
- **`EffectiveConfig::file('<name>')`** — for consumers that `include` a config file directly and so
  bypass `$config` entirely. Rewired: `HwAccelConfig::get()`,
  `FfmpegRunner::getTranscodeTimeout()`, `Recorder::getTranscodeTimeout()`, and `start.php`'s
  managed-worker gate.

### Why not "just overlay `$config`"

The original recommendation (option (b) in the pre-fix version of this document) was to overlay the
boot `$config` array and stop there. **That covers only 4 of the 16 keys** (the schema carried 16 at
the time; it carries 15 since `hwaccel.probe_timeout` was deleted). Dotted keys name config
*files*, and only `server.hls.*` lives in `config/server.php`. `ffmpeg.*` is reachable through
`$config` solely because `config/server.php` happens to compose `'ffmpeg' => require
__DIR__ . '/ffmpeg.php'`; `hwaccel.*`, `transcoding.*` and `process.*` are not in `$config` at all.
The overlay therefore had to be applied at the config-file loading boundary as well.

### Cache invalidation (a real hazard, not a theoretical one)

`config/server.php` `require`s `config/ffmpeg.php`, which calls `HwAccelConfig::get()` — so the
merged hwaccel config is built **in the master process**, before any worker has read the overrides,
and every forked child inherits it. `HwAccelConfig` now keys its static cache on
`EffectiveConfig::generation()`, a counter bumped by each bootstrap, so the per-worker bootstrap
invalidates the master's pre-overlay merge. `EffectiveConfigTest::test_rebootstrapping_invalidates_derived_caches`
covers exactly this; removing the generation guard turns six tests red.

### Failure mode (deliberate, and tested)

If the settings store is unreachable — DB down, `server_settings` absent because migrations have not
run on a fresh install, unreadable `config/database.php` — `readOverrides()` catches and returns an
empty override set. **The server boots on the shipped file defaults; it never crash-loops.** The
overlay is also inert before `bootstrap()` runs, so CLI scripts and unit tests see the file defaults
unchanged. An override is applied **only where the default already exists**, so a malformed, unknown
or hand-edited row cannot inject a config key the code does not already read.

## Per-key status

| Key | Live after restart/reload? | Notes |
| --- | --- | --- |
| `hwaccel.enabled` | ✅ | via `HwAccelConfig::get()` → `FfmpegRunner::setConfig()` |
| `hwaccel.prefer_hardware` | ✅ | same path |
| ~~`hwaccel.probe_timeout`~~ | 🗑️ **DELETED** (shared v0.26.0) | had no consumer; see below |
| `transcoding.preferred_accelerator` | ✅ | via `HwAccelConfig::get()` |
| `ffmpeg.max_concurrent_transcodes` | ✅ | `TranscodeManager` (also already had a `getEffective()` path) |
| `ffmpeg.transcode_timeout` | ✅ | `FfmpegRunner` + `Recorder` `getTranscodeTimeout()` |
| `ffmpeg.max_concurrent_scan_probes` | ✅ | `MediaScanner` via `MediaServicesProvider` |
| `server.hls.cache_max_age` | ✅ | `TranscodeManager` via `TranscodeServicesProvider` |
| `server.hls.cache_max_bytes` | ✅ | same |
| `server.hls.segment_seconds` | ✅ | same |
| `server.hls.max_concurrent_segments` | ✅ | same |
| `process.library-scan.enabled` | ⚠️ asymmetric | disable ✅ / enable-from-file-disabled ❌ — see below |
| `process.plugin-auto-update.enabled` | ⚠️ asymmetric | same |
| `process.marker-detection.enabled` | ⚠️ asymmetric | same |
| `process.media-asset.enabled` | ⚠️ asymmetric | same |
| `process.similarity.enabled` | ⚠️ asymmetric | same |
| `tmdb.api_key` | ✅ | `TmdbProvider` factory in `MediaServicesProvider` reads `getEffective()` when the per-worker container builds it. **Was `restart: false` until `phlix-shared` v0.46.0** — see below |

### `tmdb.api_key` — was mislabelled `restart: false` until `phlix-shared` v0.46.0

The key is admin-managed (Settings → Metadata → `server_settings` row `tmdb.api_key`), and
the schema advertised it as taking effect immediately. It does not.

`TmdbProvider` is registered as a PHP-DI `factory()`, and PHP-DI caches **every** resolved
entry — factories included — in `Container::$resolvedEntries`. The provider is therefore a
per-container singleton that captures the key **by value at construction**. One container is
built per worker in `onWorkerStart`, and `phlix-library-scan` resolves it **eagerly at fork
time** (`LibraryScanWorker` → `LibraryMetadataMatcher` → `TmdbProvider`). With no TTL, no
invalidation hook and no cross-worker propagation, a saved key stays inert until the workers
are recycled.

That `SettingsRepository::getEffective()` performs an uncached `SELECT` on every call is
irrelevant here: the value is read **once**, at container-build time, and frozen into the
singleton. This is the same **Class (b) RESTART** shape already documented for the
`server.rate_limit.*` keys in `phlix-shared`'s `tests/Schema/ServerSettingsSchemaTest.php`.

Two separate defects were fixed together:

1. **The flag** (`phlix-shared` v0.46.0) — `restart: true`, with the requirement disclosed in
   the key's admin-facing `helpText`.
2. **Three consumers that never read the DB at all** — `WebPortalRouter::tmdbApiKey()`,
   `Application::getMediaPosterController()` and `Application::getExtrasController()`'s
   container-less branch read `config/tmdb.php` / `TMDB_API_KEY` only. Since
   `config/tmdb.php` resolves `api_key` to `getenv('TMDB_API_KEY') ?: ''`, and that variable
   is not exported once the key is managed from the admin UI, all three resolved to an empty
   string **permanently — no restart could fix them**. They now go through
   `Phlix\Media\Metadata\TmdbApiKeyResolver`. `scripts/backfill-{ratings,collections}.php`
   had the same read and reported "key not found" on a correctly configured server.

## Audit of the other restart false keys

**Status: ✅ all seven wrong flags FIXED in `phlix-shared` v0.47.0.**

Auditing the other 38 `restart: false` keys against the same criterion — *is the value
captured into a DI singleton's constructor, or otherwise read once at container/route build
time?* — found **7 more in the same Class (b) shape**. Several contradicted their own
consuming code's docblocks. All seven are now `restart: true`, with the requirement
disclosed in each key's admin-facing `helpText`.

| Key | Evidence | Status |
| --- | --- | --- |
| `matching.noise_suffixes` | `factory()` in `MediaServicesProvider`, injected via `constructorParameter('noiseSuffixes', …)` into `MediaScanner` at **two** wiring sites. Its own comment: *"the value is computed once at construction."* | ✅ `restart: true` |
| `metadata.provider_priority` | Captured into the `PriorityConfig` factory, injected as `constructorParameter('globalPriority', …)`. Comment: *"resolved ONCE when first built (per worker cycle, not per request)."* | ✅ `restart: true` |
| `metadata.genres_mode` | Same `PriorityConfig` factory. | ✅ `restart: true` |
| `lastfm.api_key` | Overlaid at **route-build time** by `Application::applyLastfmOverrides()`, then frozen into `LastfmApi`'s constructor-promoted readonly properties. Its docblock stated: *"this runs at route-build time (once per worker) … That is why the `lastfm.*` schema keys carry `"restart": true`"* — **the schema had been contradicting the code.** | ✅ `restart: true` |
| `lastfm.shared_secret` | Same overlay. | ✅ `restart: true` |
| `lastfm.enabled` | Same overlay. | ✅ `restart: true` |
| `port-forward.port_forwarding.upnp_enabled` | Read at container-build time in `NetworkServicesProvider::register()` — **and was additionally INERT**, see below. | ✅ `restart: true` **+ wired** |

### ⚠️ `port-forward.port_forwarding.upnp_enabled` — the flag was not the whole defect

The first pass of this audit classified this key as plain Class (b). Re-verifying it before
changing it showed that was **wrong**, and the correction matters more than the flag:

- `NetworkServicesProvider::register()` computed `$upnpEnabled` and then passed it to **no
  definition** — the variable appeared exactly once in the file, at its own assignment.
- `PortForwardService` had **no UPnP switch at all**; `autoConfigure()` called
  `$this->upnp->discoverGateway()` unconditionally.
- A repo-wide sweep found no other consumer.

So an admin who set it to `false` got nothing — **even across a restart**. Flipping the flag
alone would have replaced one false promise with another, which is exactly the trap
`hwaccel.probe_timeout` fell into before it was deleted (see above: it was *not* relabelled
`restart: false`, because that would have claimed it was live — "even less true").

The key is now genuinely wired: `PortForwardService::$upnpEnabled` gates the UPnP leg only,
so `autoConfigure()` falls through to NAT-PMP. That is deliberately different from
`$autoEnabled`, which short-circuits forwarding entirely — an operator who distrusts UPnP
(unauthenticated on most consumer routers) still wants NAT-PMP. `disable()` is deliberately
**not** gated: it is teardown, and a mapping created while UPnP was on must stay removable
afterwards or it leaks on the router.

Note `InertSettingRepairsTest` was never wrong here — it states its own scope honestly
(*"This proves the value resolves and overlays; it does NOT by itself prove
`NetworkServicesProvider` consumes that path"*). It never claimed the effect; the effect was
simply missing. The new consequence tests in `PortForwardServiceTest` assert the effect —
whether the UPnP client is consulted — and were mutation-verified by removing the gate
(2 of the 4 go red; the other 2 are deliberate guards against over-correcting into "UPnP is
always skipped" and against gating teardown).

### Verified correct as `restart: false` (23 keys)

Each resolves through `SettingsRepository` at **use** time (per request / per call), so no
restart is needed:

`auth.signup_mode` (`AuthManager:344`) · `auth.password.min_length` (`PasswordPolicy`) ·
`auth.access_ttl`, `auth.refresh_ttl` (`TokenTtlPolicy`, per mint) · `auth.max_profiles`
(`UserProfileManager`) · `access.default_concurrent_streams` (`StreamSessionService`, per
playback) · `transcoding.preset`, `transcoding.crf_h264`, `transcoding.audio_bitrate`
(`EncodeSettings`) · `artwork.download_enabled` (`ArtworkDownloadPolicy` — its docblock
explicitly requires `restart: false`) · `scanner.ignore_patterns` (`ScanIgnorePatterns`) ·
`subtitles.default_language` (`WebPortalRouter:2712`) · `subtitles.provider_priority`
(`SubtitleFetchService:291`) · `trickplay.enabled` (`MediaAssetGenerationJob:77`) ·
`metadata.overwrite_existing` (`MetadataOverwritePolicy`) · `dlna.allowed_cidrs`,
`dlna.restrict_to_lan` (`DlnaAllowlistMiddleware`) · `trakt.client_id`, `trakt.client_secret`,
`trakt.redirect_uri` (`TraktOperatorConfig::load()` via `TraktOAuthController::loadConfig()`,
re-read per request) · `casting.chromecast.enabled`, `casting.roku.enabled`,
`casting.airplay.enabled` (`CastingEnabledMiddleware:90`, per request).

### The last 8 keys — traced, and all `restart: true` (`phlix-shared` v0.48.0)

A literal-key search found **no** `getEffective()`/`getOverride()` consumer for these, so
v0.47.0 deliberately left them alone rather than guess — `port-forward.*` had just proved
that "no obvious consumer" can mean *inert*, not *mis-flagged*.

Tracing them explains the empty search: **none of them is read through `SettingsRepository`
at all.** Every one reaches its consumer by a route that snapshots per worker. None is inert.

| Key(s) | Consumer | Why it cannot apply live |
| --- | --- | --- |
| `webhooks.enabled` | `WebhookDispatcher::getConfig()` → `EffectiveConfig::file('webhooks')` | See the `EffectiveConfig` note below — the call is at use time but the *data* is a boot snapshot. |
| `stats.enabled` | `StatsCollector::isEnabled()` → `EffectiveConfig::file('stats')` | Same. Its own docblock already said the override "applies on reload". |
| `transcoding.tone_mapping_mode`, `transcoding.prefer_hdr_output` | `HwAccelConfig::get()` → `FfmpegRunner::setConfig()`, read as `$this->config[…]` | The **identical path** as `transcoding.preferred_accelerator` and the `hwaccel.*` keys — which were already `restart: true` and ✅ in the table above. Sibling keys in the same config file, same consumer, contradictory flags. |
| `newsletter.enabled`, `newsletter.send_hour` | `Application` reads boot `$config['newsletter']` when registering the newsletter timer | Timer registration happens once per worker start. |
| `relay.reconnect_delay`, `relay.ping_interval` | `RelayConfig::class => factory(…)` over `$appConfig['relay']` in `HubServicesProvider` | PHP-DI caches the factory result per container — Class (b). |

#### ⚠️ `EffectiveConfig::file()` at use time is **not** live

This is the trap that made two of these look fine. The call site is per-request, so the read
*looks* live — but the data is not:

- `EffectiveConfig::$overrides` is a **static** array, populated once by `bootstrap()`.
- `bootstrap()` runs inside `onWorkerStart` (six call sites in `start.php`), i.e. **once per
  worker**, not per request.
- `file()` is then **memoised per bootstrap generation**.

So an override saved while a worker is running is invisible to that worker no matter how often
`file()` is called. Contrast `SettingsRepository::getEffective()`, which performs an **uncached
`SELECT` on every call** and *is* genuinely live — that difference is the whole line between
the 23 `restart: false` keys and the rest.

(`public/index.php` also bootstraps, per request — but production runs Workerman via
`start.php`, so the per-worker semantics are the ones that matter.)

#### On "restart" vs "reload"

`restart: true` here means the admin **Restart server** control, which sends a graceful reload
(SIGUSR2) — workers cycle, re-run `onWorkerStart`, re-bootstrap the overlay, rebuild their DI
containers. A key that needs that cycle is `restart: true` **even when a full `systemctl
restart` is not required**. `StatsCollector::isEnabled()`'s phrase "applies on reload without a
full restart" is accurate and is not in conflict with the flag.

### Audit complete

All **72** schema keys are now traced to a consumer: **49 `restart: true`, 23 `restart: false`,
none unverified.** The 23 listed above as verified-correct are exactly what remains at
`restart: false` — a clean cross-check that nothing was left unclassified.

### 🗑️ `hwaccel.probe_timeout` — DELETED in `phlix-shared` v0.26.0

The follow-up this document previously requested has been actioned: the key is **gone from the
schema**, not flipped to `restart: false` (which would have claimed it was *live* — even less true).

**Why it was inert.** Two independent causes, both re-verified before deletion:

1. **Nothing read it.** `HwaccelRegistry` is constructed via `getInstance()` with **no config**
   (`FfmpegRunner.php:1359`), so it used its own literal `'probe_timeout' => 30`
   (`HwaccelRegistry.php:89`) — and never passed even that on: `initialize()` calls
   `new HwaccelProbe($this->ffmpeg_path)`, and `HwaccelProbe::__construct()` (`HwaccelProbe.php:51`)
   accepts only a binary path and a logger. The merged `HwAccelConfig::get()['probe_timeout']` had no
   consumer at all.
2. **It was shadowed anyway.** `HwAccelConfig::get()` resolved
   `$transcodingConfig['probe_timeout'] ?? $hwaccelBase['probe_timeout']` (`HwAccelConfig.php:103`),
   and `config/transcoding.php:33` *always* declares `probe_timeout`, so the `??` never fell through
   to the `hwaccel.*` side.

**Why it was deleted rather than wired** (plan §4 rule 10 — a setting that cannot be made to work
must be deleted). Wiring it is neither cheap nor safe:

- **The real timeouts are two different hardcoded constants.**
  `ShellTimeout::FFMPEG_TIMEOUT = 10` and `ShellTimeout::GPU_TOOL_TIMEOUT = 5`
  (`src/Media/Transcoding/Hwaccel/ShellTimeout.php:25,28`). A single `probe_timeout` (default 30)
  maps onto neither without inventing a semantic — and inventing config semantics nothing reads is
  the specific first-pass mistake this program already had to undo.
- **The plumbing is wide.** `ShellTimeout::exec()` is **static**, called from 22 sites across seven
  `VendorProbe` classes. Threading a configured value means changing
  `VendorProbeInterface::probe()` / `runAcceptanceTest()` across all seven implementations, plus
  `HwaccelProbe` and `HwaccelRegistry`'s private-constructor singleton.
- **It was an admin-reachable worker hang.** The schema declared `"minimum": 0`, and coreutils'
  `timeout 0 CMD` means **no timeout at all**. `ShellTimeout` exists precisely "to prevent coroutine
  deadlock during shutdown" (`ShellTimeout.php:15`). Wiring the key would have handed an admin a
  one-click way to hang a resident Workerman worker at boot.
- **Its `helpText` described a feature that does not exist.** It promised a *per-file, pre-transcode*
  probe of "the file's codec profile". There is no such probe: `HwaccelRegistry::initialize()` runs a
  **one-time, process-wide capability scan** (`ffmpeg -encoders`, `nvidia-smi`, `vainfo`) guarded by
  `$this->initialized`. Wiring it would have left the UI text false regardless.

**Guards against reintroduction:** `ServerSettingsSchemaTest::test_consumerless_probe_timeout_key_is_not_reintroduced()`
in `phlix-shared`, and the hand-written allow-list + `assertCount(42, …)` in
`tests/Unit/Server/Http/Controllers/Admin/AdminSettingsControllerTest.php`. `config/hwaccel_base.php`
and `config/transcoding.php` keep their `probe_timeout` literals so the merged array shape is
unchanged; they are simply not an admin setting.

### ⚠️ `process.<worker>.enabled` — disable works, enable-from-file-disabled does not

The managed-worker spawn loop runs in the **master**, before `Worker::runAll()`, and Workerman cannot
fork a new Worker group afterwards. It therefore cannot consult the override store: doing so would
need a blocking DB read in the master whose connection every fork would inherit, and the admin
Restart button sends SIGUSR2 (a **graceful reload**, re-forking children from the already-executed
master) which would not re-run it anyway.

So the spawn decision stays on the config-file default — all five ship `enabled => true` — and the
**effective** value is applied inside each managed worker's `onWorkerStart`, which *is* re-run on
every reload: a worker disabled by an admin override starts, logs
`Managed worker disabled by settings override; idling`, and never arms its poll timer.

- Disabling via the admin UI → **works** on the next reload/restart.
- Re-enabling something you disabled via the admin UI → **works** (the Worker is still spawned,
  because `config/process.php` still says `enabled => true`).
- Enabling a worker that `config/process.php` itself disables on disk → **does not work**; requires
  editing the file and a full service restart. The in-app Restart button cannot do it, because
  SIGUSR2 re-forks children from the already-executed master rather than re-running it.

The visible cost is one idle process per UI-disabled worker. That was chosen over the alternative
(master-side DB read) because it keeps the reload path honest and avoids a fork-inherited connection.

**This is now disclosed to the admin, not just to developers.** As of `phlix-shared` v0.26.0 all five
keys' `helpText` states (a) turning a worker OFF takes effect after a restart, (b) turning one back ON
needs a full service restart if it is disabled in the on-disk config, and (c) a worker switched off
here still occupies an idle process. The previous text was actively wrong — it claimed the worker
"is not spawned", when `start.php` spawns it regardless and the gate lives in `onWorkerStart`.
`ServerSettingsSchemaTest::test_managed_worker_switches_disclose_the_restart_asymmetry()` locks all
three statements in and forbids the old wording's return.

## What the restart endpoint does

`POST /api/v1/admin/restart` sends a genuine graceful reload (SIGUSR2) to the master. Workers cycle,
re-run `onWorkerStart`, re-bootstrap the overlay and rebuild their DI containers — so the fifteen
✅/⚠️ keys above are re-read. See `AdminRestartController`'s docblock.

## Tests

- `tests/Unit/Config/EffectiveConfigTest.php` — the mechanism: all four config shapes (flat
  `hwaccel.*`, nested `server.hls.*`, hyphenated `process.<worker>.enabled`, `ffmpeg.*`), the
  unreachable/empty/never-bootstrapped store, malformed and unknown persisted keys, cache
  invalidation across bootstraps.
- `tests/Unit/Config/BootOverlayConsumerTest.php` — the consequence: runs the real boot sequence
  (`bootstrap` → `overlayAppConfig` → `TranscodeServicesProvider`/`MediaServicesProvider` →
  `$container->get()`) and asserts the **DI-resolved** `TranscodeManager` / `MediaScanner` /
  `FfmpegRunner` hold the overridden values.

Every assertion is paired with a "the shipped default says otherwise" check, so none can pass by
coincidence. Mutation-verified: neutering the overlay turns 16 tests red; removing `HwAccelConfig`'s
generation guard turns 6 red; removing the boot fail-safe turns the unreachable-store test red.

## Related open items (out of scope here, recorded so they are not lost)

- The restart endpoint has no rate limit and writes no audit-log entry.
- The SyncPlay WebSocket worker and the DLNA SSDP worker are `reloadable` (Workerman's default), so a
  reload drops live WS/SyncPlay sessions and in-flight HLS segment requests. There is no confirm
  dialog warning about that.
- `config/server.php`'s `worker.count` and `process.reloadable` are read by nothing — `start.php`
  hardcodes `$httpWorker->count = 14`.
- `phlix-hub` has the same class of gap; `EffectiveConfig` has no hub counterpart.
- Other direct `include`s of config files remain (`Application::loadFfmpegConfig()`,
  `StreamProbeBackfill::resolveFfmpeg()`, the webhook notification plugins, `MetadataManager`,
  `ThemeRegistry`, …). They were audited and read **no** `restart: true` key — they read binary
  paths and unexposed settings — so they were deliberately left alone rather than widening this
  step's blast radius. Route any of them through `EffectiveConfig::file()` if a key they read is
  ever exposed in the schema.
