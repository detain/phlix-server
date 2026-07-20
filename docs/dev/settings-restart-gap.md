# KNOWN GAP: `restart: true` settings do not take effect on restart

**Status:** open, not fixed. **Scope:** `phlix-server` (a sibling gap exists in `phlix-hub`).
**Owner:** unassigned — needs its own scheduled step.

Read this before telling a user, in docs, in a CHANGELOG entry, in a docblock, or in the admin UI,
that restarting the server makes a setting take effect.

---

## The claim vs the reality

`server-settings.schema.json` marks boot-only keys `"restart": true`. The admin SPA renders a
"requires a server restart to take effect" affordance for those keys and offers a **Restart server**
button wired to `POST /api/v1/admin/restart`.

**That promise is currently false.** Changing a `restart: true` key and then restarting does not
change the server's behaviour — and this is not a defect in the restart endpoint. A full
`systemctl restart phlix-server` does not apply them either.

## Why

The effective value of a setting is `override ?? default`, resolved by
`Phlix\Admin\SettingsRepository::getEffective()`. **Almost nothing reads it.** Only a handful of
files in `src/` call `getEffective()` at all:

- `src/Server/Http/Controllers/Admin/AdminSettingsController.php` (the settings API itself)
- `src/Common/Container/Providers/MediaServicesProvider.php`
- `src/Common/Container/Providers/TranscodeServicesProvider.php` (added for
  `ffmpeg.max_concurrent_transcodes`)
- `src/Plugins/Catalog/PluginCatalogService.php`
- `src/Auth/AuthManager.php`

Every other consumer reads the **boot `$config` array** instead, and that array never sees the
`server_settings` table:

1. `start.php` does `$config = include __DIR__ . '/config/server.php';` **once, in the master**, and
   the worker closures capture it with `use ($config, ...)`.
2. A Workerman reload re-forks children from that same master, so they inherit the identical frozen
   `$config`. `onWorkerStart` rebuilds the DI container — but from unchanged input.
3. Nothing, at any point in boot, merges DB overrides onto `$config`.
4. `Phlix\Config\HwAccelConfig::get()` independently `include`s `config/transcoding.php` straight
   from disk, bypassing the settings store entirely.

So a DB override for such a key is stored, echoed back by the GET, displayed as the current value in
the UI — and read by nothing.

## What the restart endpoint *does* do

`POST /api/v1/admin/restart` sends a genuine graceful reload (`SIGUSR2`) to the master. Workers do
cycle, and anything that is genuinely re-read at `onWorkerStart` — DI-constructed services, the
`getEffective()` call sites listed above, `config/*.php` files edited on disk — is genuinely
re-applied. It is a real, working reload. It just does not, and cannot, make a boot-`$config`
consumer observe a DB override that was never merged into `$config`.

## The real fix (NOT done — needs its own step)

Two viable shapes, per `plan_settings.md` §4 rule 2:

- **(a) Rewire consumers.** Change each `restart: true` key's consumer to read
  `SettingsRepository::getEffective()` at container-build time, the way
  `MediaServicesProvider`/`TranscodeServicesProvider` already do. Precise, incremental, and it makes
  most of those keys genuinely live rather than merely restart-applied — but it touches every
  affected provider.
- **(b) Overlay at boot.** Add a step *inside* `onWorkerStart`, before
  `ContainerFactory::create($config)`, that reads `server_settings` and deep-merges the overrides
  onto `$config` by dotted key. One place, covers every key at once, and makes `restart: true`
  mean what it says. Costs one DB round-trip per worker start and needs care so a settings-store
  failure degrades to the config defaults rather than breaking boot.

(b) is the smaller change and the one that matches the schema's existing semantics. Either way it is
an architectural change with real blast radius across both entry points (§7), which is why it was
explicitly held out of the pagination/masking/validation/restart-signal fix batch.

## Until then

- Do **not** describe a `restart: true` key as "applies after restart" in docs, CHANGELOGs or
  docblocks.
- Do **not** claim the restart button makes settings take effect.
- Prefer wiring any NEW setting through `getEffective()` at the consumer, so it is live and never
  needs `restart: true` at all.

## Related open items (out of scope here, recorded so they are not lost)

- The restart endpoint has no rate limit and writes no audit-log entry.
- The SyncPlay WebSocket worker and the DLNA SSDP worker are `reloadable` (Workerman's default), so a
  reload drops live WS/SyncPlay sessions and in-flight HLS segment requests. There is no confirm
  dialog warning about that.
- `config/server.php`'s `worker.count` and `process.reloadable` are read by nothing — `start.php`
  hardcodes `$httpWorker->count = 14`. Same "decorative config block" class of defect that
  `worker.pid_file` was in before `Phlix\Server\Runtime\PidFile`.
