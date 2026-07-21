# `restart: true` settings — FIXED, with two documented exceptions

**Status:** the mechanism is implemented and tested. **Scope:** `phlix-server`
(a sibling gap still exists in `phlix-hub`, untouched by this work).

Read this before telling a user — in docs, in a CHANGELOG entry, in a docblock, or in the admin UI —
that restarting the server makes a specific setting take effect. Fifteen of the sixteen keys now
genuinely apply; the exceptions below are real and must not be papered over.

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
boot `$config` array and stop there. **That covers only 4 of the 16 keys.** Dotted keys name config
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
| `hwaccel.probe_timeout` | ❌ **NO CONSUMER** | see below |
| `transcoding.preferred_accelerator` | ✅ | via `HwAccelConfig::get()` |
| `ffmpeg.max_concurrent_transcodes` | ✅ | `TranscodeManager` (also already had a `getEffective()` path) |
| `ffmpeg.transcode_timeout` | ✅ | `FfmpegRunner` + `Recorder` `getTranscodeTimeout()` |
| `ffmpeg.max_concurrent_scan_probes` | ✅ | `MediaScanner` via `MediaServicesProvider` |
| `server.hls.cache_max_age` | ✅ | `TranscodeManager` via `TranscodeServicesProvider` |
| `server.hls.cache_max_bytes` | ✅ | same |
| `server.hls.segment_seconds` | ✅ | same |
| `server.hls.max_concurrent_segments` | ✅ | same |
| `process.library-scan.enabled` | ⚠️ partial | see below |
| `process.plugin-auto-update.enabled` | ⚠️ partial | see below |
| `process.marker-detection.enabled` | ⚠️ partial | see below |
| `process.media-asset.enabled` | ⚠️ partial | see below |
| `process.similarity.enabled` | ⚠️ partial | see below |

### ❌ `hwaccel.probe_timeout` — genuinely inert, flag should be reconsidered

Two independent reasons, neither fixed here because both are behaviour changes outside this step's
scope:

1. **Nothing reads it.** `HwaccelRegistry` is constructed via `getInstance()` with **no config**
   (`FfmpegRunner.php:1359`), so it uses its own literal `'probe_timeout' => 30` default. The merged
   `HwAccelConfig::get()['probe_timeout']` has no consumer at all.
2. **It is shadowed even if a consumer appeared.** `HwAccelConfig::get()` resolves
   `$transcodingConfig['probe_timeout'] ?? $hwaccelBase['probe_timeout']`, and
   `config/transcoding.php` *always* declares `probe_timeout`, so the `??` never falls through to the
   `hwaccel.*` side.

**Follow-up for `phlix-shared`:** either wire `HwaccelRegistry` to the merged config (making the key
live), or drop the key from `server-settings.schema.json`. Shipping it as `restart: true` is exactly
the false advertising this remediation exists to eliminate. The audit's item (d) already flagged
this as "resolves but has no consumer"; this step confirms it and adds the shadowing detail.

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
  editing the file and a full restart. This is an operator file edit, not a UI action.

The visible cost is one idle process per UI-disabled worker. That was chosen over the alternative
(master-side DB read) because it keeps the reload path honest and avoids a fork-inherited connection.

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
