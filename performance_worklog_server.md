# Worklog — phlix-server

## Tooling (from Recon)

### Tooling Discovery

- **test:** `./vendor/bin/phpunit`
  - Default (no args) runs all suites (Unit + Integration + E2E, excluding `network` group)
  - Unit suite: `./vendor/bin/phpunit --testsuite Unit`
  - Integration suite: `./vendor/bin/phpunit --testsuite Integration`
  - Specific file: `./vendor/bin/phpunit tests/Unit/Auth/JwtHandlerTest.php --testdox`
  - Coverage output: `coverage.xml` (Clover) + `coverage-report/` (HTML)
  - Bootstrap: `tests/bootstrap.php`; DB credentials via env: `DB_HOST=127.0.0.1`, `DB_DATABASE=phlix_test`, `DB_USER=root`, `DB_PASSWORD=root`
  - Source: `composer.json:L41` (phpunit ^10.0 in require-dev), `phpunit.xml:L3-13` (testsuite definitions), `AGENTS.md`

- **static analysis:** `phpstan analyze -c phpstan.neon.dist`
  - Runs at level 9 (max); analyzes `src/` only
  - Bootstrap file: `vendor/autoload.php` (phpstan.neon.dist:L7-8)
  - Excludes: `src/Server/WebSocket/Events.php` (phpstan.neon.dist:L6)
  - Note: plan references `phpstan -c phpstan.neon.dist` at L9 — same command
  - Source: `phpstan.neon.dist:L2` (level 9), `AGENTS.md` (`./vendor/bin/phpstan analyze src/ --level=9`)

- **lint:** `./vendor/bin/phpcs --standard=PSR12 src/`
  - PSR-12 coding standard; targets `src/` directory
  - Source: `AGENTS.md`, `composer.json:L42` (squizlabs/php_codesniffer ^3.10 in require-dev)

- **build:** N/A for phlix-server
  - Server is PHP; no build step required (pure PHP runtime)

- **migrate:** `php scripts/run-migrations.php`
  - Applies all `.sql` files in `migrations/` directory via `MigrationRunner`
  - Idempotent: catches duplicate-column / duplicate-key errors and downgrades to notes
  - Requires `config/database.php` and `vendor/autoload.php`
  - No migration-tracking table — re-runs every boot per apply-all-every-time contract
  - Source: `scripts/run-migrations.php:L11-26` (MigrationRunner apply loop), `AGENTS.md` (`php scripts/run-migrations.php`)

- **deploy/verify:** `ssh root@153.75.226.242` → `/root/update_server.sh`
  - The script performs: `git pull` + `systemctl reload phlix-server`
  - CLI env requires: `. /etc/phlix/env` (loads environment variables)
  - Source: `performance_plan.md:§0.3:L164` ("Deploy: ssh root@153.75.226.242 → /root/update_server.sh"), `performance_plan.md:§H:L136` (recon requirement)

### Environment

- **PHP version:** 8.3.6 (cli) — minimum required: `php >= 8.3` (composer.json:L13)
  - Extensions: ext-ldap required, ext-swoole implied by workerman
  - OPcache enabled (Zend OPcache v8.3.6)

- **Bootstrap gotchas:**
  - **Dual entrypoints** (§0.3): Any constructor/DI/bootstrap change must be mirrored in BOTH:
    - `public/index.php` — CI/FPM path; used in testing and by the web server
    - `start.php` — Swoole resident path; used in production with `php start.php start`
    - `start.php` is outside CI — changes to it must be verified by hand on the box
  - **Coroutine/Swoole hooks:** `eventLoopClass` set in master only; workers re-assert the curated hook mask in `onWorkerStart`; deliberately excludes `SWOOLE_HOOK_NATIVE_CURL` and `exec` — respect it
  - **Migration runner:** runs every boot (apply-all-every-time); idempotent via `IF NOT EXISTS` and error-substring allowlist

## Progress
- [~] SV-0.1  wire hardware acceleration + tone-mapping config  FIX-1 LANDED 2026-07-12 → RE-REVIEW spawned. Review-1 defect (HW-surface/software-filter collision) FIXED: buildHwaccelInputFlags() now delegates to profiles' getInputDeviceArgs() (no divergence); new hwaccelUploadFilter() appends format=nv12,hwupload AFTER software filters for VAAPI/QSV only (NVENC/VT/AMF take system memory); added HwaccelProfileFactory::getProfileForVendor(). #3 vaapi test corrected + 4 coherence tests (downscale NVENC/VAAPI hwupload-ordering, HDR NVENC, QSV device); --filter Hwaccel 139 green. QSV device-key fixed. #4 confirmed (FPM lazy-probe, no boot log — OK). Commits 02606550,e54cf142,f37fc0f9. ⚠️ SCOPE-CREEP (beneficial): commit 4d49d9bc ALSO cleared the other pre-existing errors → phpstan L9 now [OK] 0 errors + phpcs 0 errors. That touched SV-2.8 (ItemRepository is_numeric-guarded casts) and SV-3.2 (WebPortalServicesProvider:295 BookController factory: BookLibraryManager→LibraryManager — was a LATENT RUNTIME FATAL). Those steps' audits MUST account for this. testAddStream* reds remain = pre-existing SV-2.8 test-drift (unchanged). REVIEW-2 2026-07-12: essentially CLEAN — VAAPI+NVENC+libx264-fallback correct (high confidence), collision defect genuinely fixed, tests guard the seam, scope-creep edits all verified correct. 1 finding LOWER-CONFIDENCE/Intel-QSV-only/unverifiable-here (QSV hwupload needs -init_hw_device qsv=hw -filter_hw_device hw + hwupload=...,format=qsv; NOT a regression) → DEFERRED to task #6 (verify on Intel HW; won't write untestable ffmpeg blind). SV-0.1 code+tests DONE for verifiable scope; docs batched w/ wave. SV-0.1/0.2 effectively DONE ✅ (docs pending).
- [x] SV-0.2  reconcile hwaccel config  RE-AUDIT 2026-07-12: DONE — single source HwAccelConfig::get(); ffmpeg.php+hwaccel.php delegate; no contradictory flags. Regression test added: HwAccelConfigTest(7) green (commit 0a35848a). Pending confirming review alongside SV-0.1.
- [~] SV-0.3  isWorkermanContext fix  RE-AUDIT 2026-07-12: PARTIAL — impl CORRECT (shared Common\Runtime\WorkerContext::isEventLoopRunning() via Worker::isRunning(); all 4 clients + PluginCatalogService use it; old defined() guard gone). GAP: mandated regression test MISSING. FIX LANDED (ce2d2e04, 2a243857): WorkerContextTest added; branch-selection guard via Webhook seam. REVIEW NO FINDINGS 2026-07-12 → DONE ✅ (docs batched w/ wave).
- [~] SV-0.4  replace usleep spin-wait with Channel  RE-AUDIT 2026-07-12: PARTIAL + REAL BUG — spin-wait gone, but coroutine gating WRONG: Metadata/Webhook/S3 route to Channel path on isEventLoopRunning()&&!requiresBlockingCurl() with NO getCid()>0 gate → Channel::pop() outside a coroutine returns false immediately = FALSE TIMEOUT while callback pending. HttpClient INVERTED (Channel used only in non-coroutine case where it's invalid). AC requires: getCid()>0 → Channel; else blocking. FIX LANDED (ce2d2e04): all 4 clients gate Channel::pop on inCoroutine(); HttpClient inversion corrected (dead requestAsync removed); TLS blocking fallback preserved. Real coroutine test (wake+clean-timeout). REVIEW NO FINDINGS 2026-07-12 → DONE ✅. (PluginCatalogService SV-4.11 has same latent gap — its step.)
- [x] SV-0.5  fix WS reaper + heartbeat timer guards  RE-AUDIT 2026-07-12: PARTIAL — function_exists→class_exists fixed (3 sites); WS reaper arms (per-worker repeating); stream heartbeats one-shot (Timer::add(30,...,[],false), storm CAPPED). GAPS: (1) S-F28 WS app-level ping ABSENT (no pingInterval/pingNotResponseLimit, no ping timer → half-open sockets undetectable); (2) heartbeat NOT keyed-per-session/deduped/torn-down on stream end (each request re-registers → bounded accumulation, not the keyed+cancelled AC); (3) tests shallow (smoke test w/ stale comment; no leak test). COMPLETED 2026-07-12: (1) server-side ping timer + onWebSocketPong (Workerman 5.x has no pingInterval); (2) StreamSessionService keyed+deduped one-shot heartbeat timers torn down in releaseStream(), callers delegate; (3) reaper-registration + ping-reap + heartbeat one-shot/keyed/leak tests. phpstan 0, phpunit 85 green, phpcs no new. See Implementer note below. Pending review.
- [x] SV-0.6  fix TMDB collections UUID-as-int bug ✅ (commit ad6d6d86)
- [x] SV-0.7  supervise marker/intro-detection worker ✅ (commit 46c71440)
- [x] SV-0.8  fix path_hash reads + stop re-probing ✅ (commit 510c8761)
- [x] SV-0.9  fix generateThumbnailBatch timestamp escaping ✅ (commit 1dbdf97c)
- [x] SV-1.1  memoize/precompute HDR tone-map decision ✅ (commit bbef742c)
- [x] SV-1.2  make non-probe ffmpeg calls coroutine-friendly ✅ (commit 6da7dc41)
- [x] SV-1.3  move chapter-thumbnail + trickplay to background job ✅ (commit 4317214b)
- [x] SV-1.4  correct zscale tone-map graph ✅ (commit 7c7156dc)
- [x] SV-1.5  implement real libplacebo tone-map mode ✅ (commit abad4b46)
- [x] SV-1.6  fix subtitle burn-in escaping + VAAPI overlay ✅ (commit 7a248f40)
- [x] SV-1.7  range parser reuse on direct-play ✅ (commit 1862fafb)
- [x] SV-1.8  CSRF Origin exact-match ✅ (commit ba3096ba)
- [x] SV-1.9  ENOSPC guard on segment cache ✅ (commit 70d99f4e)
- [x] SV-1.10 login rate limiter bound ✅ (commit a3a6b35a) — S-W1 complete 🎉
- [x] SV-2.1  stream file-backed responses over relay tunnel ✅ (commit b3e45682)
- [x] SV-2.2  pool hygiene: rollback dirty connections ✅ (commit 6bd400ee)
- [x] SV-2.3  relay byte-pipe backpressure ✅ (commit cfcbeb50)
- [x] SV-2.4  stream large binary via withFile() ✅ (commit 320efdbc)
- [x] SV-2.5  image/photo caching validators + security headers ✅ (commit 3cf0ac4c)
- [x] SV-2.6  WS routing indexes + broadcast backpressure ✅ (commit e4270321)
- [x] SV-2.7  per-request auth status cache ✅ (commit 786b80fd)
- [x] SV-2.8  list-query projection + materialized filter columns ✅ (commit ef156b1e)
- [x] SV-2.9  defer similarity computation to background job ✅ (commit c9ea405d)
- [x] SV-3.1  DVR recording data plane ✅ (commit 0579ef07)
- [x] SV-3.2  book reader + audiobook player backends ✅ (commit 4f51206f)
- [x] SV-3.3  client capability negotiation + loudness normalization ✅ (commit c9e5e599)
- [x] SV-3.4  local artwork cache with sized variants ✅ (commit 1b09f897)
- [x] SV-3.5  metadata pipeline: concurrency, 429 backoff, bounded cache ✅ (commit fa4d400f)
- [x] SV-3.6  build out Trakt history sync ✅ (commit cd3be89f) — S-W3 complete 🎉
- [x] SV-4.1  segment-cap reservation before glob() ✅ (commit 9f06522b)
- [x] SV-4.2  detached-ffmpeg cancellation + apply transcode_timeout ✅ (commit 410ffce0)
- [x] SV-4.3  ComskipRunner non-blocking pipe + reachable timeout ✅ (commit 410ffce0)
- [x] SV-4.4  WebhookDispatcher backoff + connect-timeout ✅ (commit 410ffce0)
- [x] SV-4.5  Roku/MusicBrainz blocking-I/O → coroutine/async ✅ (commit 410ffce0)
- [x] SV-4.6  original copy variant handling ✅ (commit 088bb99c)
- [ ] SV-4.7  WS auth enforcement
- [x] SV-4.8  Router static-path fast map + DI for string handlers ✅ (commit c8f94c04)
- [x] SV-4.9  Migration ledger + document rewrite-class migrations ✅ (commit c8f94c04)
- [x] SV-4.10 Provider-priority config single source of truth ✅ (commit c8f94c04)
- [x] SV-4.11 Fix PluginCatalogService blocking curl + wrong docblock ✅ (commit c8f94c04)
- [x] SV-4.12 Extend stale-job reaper glob to {chunk-*.m4s,seg-*.ts} ✅ (commit c8f94c04)
- [x] SV-4.13 Remove superseded whole-file command builders ✅ (commit c8f94c04)
- [x] SV-4.14 Fix phantom self::transcode() docref ✅ (commit c8f94c04)

## Re-baseline — Claude Code orchestrator pass (2026-07-12)

**Subagent capability (RESOLVED):** a spawned subagent here runs git, `./vendor/bin/phpunit`,
`phpstan`, `phpcs`, and `gh` (after `unset GITHUB_TOKEN GH_TOKEN`) with NO permission prompts.
=> Full-delegation model per plan §A/§G: workers self-verify AND commit+push to master themselves.
(The old memory claiming CC subagents can't run npm/vendor-bin/gh is OUTDATED for this runtime.)
git identity: Joe Huss <detain@interserver.net>. PHP 8.3.6, PCOV coverage driver. Unit suite does
NOT need live MySQL (Connection is mocked). Both dual entrypoints exist: `public/index.php` + `start.php`
(CLAUDE.md's "start.php doesn't exist" note is STALE — start.php is live, verify by hand, outside CI).

**MASTER HEALTH AT PASS START (baseline — master is RED before my changes):**
- PHPUnit Unit: 4870 tests, 11 errors, 21 failures, 7 warn, 8 skip, 3 risky. Line coverage 53%.
- PHPStan L9 (`-c phpstan.neon.dist`): **6 errors**, incl.:
  - `FfmpegRunner.php:1168` uses `HwaccelCommandBuilder` — **class.notFound** (class does not exist)
  - `FfmpegRunner.php:1676` calls undefined `FfmpegRunner::buildHwaccelInputFlags()`
  - `ItemRepository.php:1283-1284` cast mixed→float / argument.type
- PHPCS PSR-12 src/: 1 error + 205 warnings (mostly >120-char lines).
- The FfmpegRunner phpstan errors + FfmpegRunnerTest/HlsTest failures are opencode's SV-0.1/SV-1.x
  hwaccel work left BROKEN (missing/renamed HwaccelCommandBuilder + method). This is IN-SCOPE for
  SV-0.1 (not pre-existing-unrelated), so fixing it is part of the SV-0.1/SV-0.2 cycle.
- ItemRepository failures (testAddStream*) + phpstan cast errors relate to SV-2.8 / stream persistence.

**Strategy:** master-red is concentrated in hwaccel (SV-0.1) + ItemRepository (SV-2.8). Drive server
W0 in order (SV-0.2 → SV-0.1 first, since we KNOW it's broken), audit-and-complete each. Definition
of done requires master green for the touched area (plan §I), so these red items get fixed by their
owning step, not waved off.

## Notes / cross-repo blockers
- X3 HEAD-over-relay: RESOLVED 2026-07-12 — server ALREADY emits HEAD→END(zero-body)→HTTP_CANCEL for
  HEAD withFile (RelayConsumer::sendHttpResponse, END unconditional @:1010; headOnly guard @:1002 skips
  file body). No server change needed; hub HB-0.3 just adds tests. ✅
- X9 heartbeat: RESOLVED 2026-07-12 — server ALREADY sends HEARTBEAT every 30s (RelayConsumer::
  startHeartbeatTimer @:1517, repeating timer, RelayConfig::pingInterval default 30) + echoes hub
  heartbeats (onHeartbeat @:1184). Hub HB-0.1 safe; no server change. ✅
- SV-2.1 pre-audit (via X3 investigation): DONE — sendHttpResponse chunk-streams file-backed GET via
  streamFileChunks() with MAX_BODY_CHUNK + send-buffer backpressure; empty-body bug fixed; HEAD reports
  real Content-Length. Confirm with a test when SV-2.1's turn comes.
- X10 bug-class sweep: DONE 2026-07-12 — CLEAN. 0 hits for `defined('\Workerman`, `function_exists('Workerman`, `Worker::$_instance` in src/. All class_exists(\Workerman\Timer::class) sites correct. No HTTP-client usleep spin-waits remain (survivors are legit poll/backoff in LiveTv/Roku/NatPmp/pool/transcode; MetadataHttpClient:199 is an SV-3.5 retry-backoff blocking-sleep = adjacent debt, not SV-0.4). Bug-class eradicated. ✅

## Implementer — SV-0.1 / SV-0.2 complete (2026-07-12)

Completed the SV-0.1 gap list + added the missing SV-0.1/SV-0.2 tests. NOT marked done — Review pending.

**Code (files touched):**
- `src/Media/Transcoding/FfmpegRunner.php` — Fix A: added
  `use Phlix\Media\Transcoding\Hwaccel\HwaccelCommandBuilder;` (clears phpstan class.notFound @:1168,
  which was the `new HwaccelCommandBuilder(...)` in `buildTranscodeCommandWithProfile`). Fix B:
  implemented `private function buildHwaccelInputFlags(HwaccelCapability): string` (clears
  method.notFound @:1676; unbreaks the live `buildHwaccelSegmentCommand` path). Per-vendor input/decode
  flags placed before `-i`, deriving device from `$capability->extra_args` (mirrors the vendor profiles'
  `getInputDeviceArgs()` used by HwaccelCommandBuilder so the two paths don't diverge): vaapi →
  `-hwaccel vaapi -hwaccel_device <dev> -hwaccel_output_format vaapi`, qsv → `-hwaccel qsv -qsv_device <dev>`,
  nvenc/cuda → `-hwaccel cuda [-hwaccel_device N] -hwaccel_output_format cuda`, videotoolbox/amf mapped,
  software/unknown → ''. Also added `getHardwareAccelerationSummary(): array` for the boot log.
  (Idempotent probe guard `$hwaccelProbed` already existed.)
- `start.php` — Fix C: added `use ...LogChannels`; in the HTTP worker `onWorkerStart`, resolve the
  FfmpegRunner, call `probeHardwareAcceleration()` explicitly at worker start (idempotent), and log the
  chosen accelerator ONCE via `LoggerFactory::get(LogChannels::STREAMING)` +
  `getHardwareAccelerationSummary()`. Runs outside any coroutine so the probe's blocking exec can't stall
  the loop. DI `setConfig` + factory probe retained (lazy once-probe still covers the FPM `public/index.php`).
- `src/Server/Core/Application.php` — Fix D: `setConfig(\Phlix\Config\HwAccelConfig::get())` after both
  non-segment `new FfmpegRunner(...)` (MediaItemController→GaplessPlaybackManager ~:2760, SubtitleController
  ~:2790) so every runner shares the single merged config source.

**Tests (added, build-out opencode skipped):**
- `tests/Unit/Config/HwAccelConfigTest.php` (7 tests) — SV-0.2 regression: single merged config;
  `enabled` from hwaccel_base.php; tone_mapping_mode/preferred_accelerator from transcoding.php;
  ffmpeg.php `hwaccel` === HwAccelConfig::get() (no contradictory flags); hwaccel.php shares base; cached.
- `tests/Unit/Media/Transcoding/FfmpegRunnerHwaccelTest.php` (5 tests) — segment cmd contains `-hwaccel`
  + `-c:v h264_nvenc` when registry (reflection-seeded, deterministic) reports HW; vaapi input flags;
  null when no HW encoder; software fallback `buildSegmentCommand` uses libx264; summary reflects probe.
- `tests/Unit/Common/Container/Providers/TranscodeServicesProviderTest.php` (2 tests) — resolving
  FfmpegRunner through the provider calls setConfig (config has merged `enabled`) + probe (registry set,
  summary reflects seeded encoder).

**Verification:**
- phpstan L9 `-c phpstan.neon.dist`: the TWO hwaccel errors (:1168 class.notFound, :1676 method.notFound)
  are GONE. Remaining: 3 pre-existing errors NOT mine — ItemRepository.php:1283-1284 (SV-2.8) +
  WebPortalServicesProvider.php:295 (SV-3.2/BookController).
- phpunit: new tests 13/13 green; `--filter Hwaccel` 135/135 green.
- phpcs PSR-12 on touched src (FfmpegRunner.php, Application.php): 0 errors (only pre-existing >120-char
  warnings on untouched lines). start.php clean. (Test method snake_case matches existing test convention;
  tests/ is outside the `src/`-only phpcs scope.)
- Pre-existing FfmpegRunnerTest `buildTranscodeCommand()` drift STILL FAILS (3 errors) — SV-4.13's job,
  left untouched as instructed.

## Reviewer (per-step: SV-0.1 + SV-0.2) — 2026-07-12

Verified: phpstan L9 clean on all touched files (only the 3 pre-existing errors remain —
ItemRepository.php:1283-1284 + WebPortalServicesProvider/BookLibraryManager argument.type — NOT
introduced here). `--filter Hwaccel` 135/135 green; the 13 new tests green. SV-0.2 is fully correct
(single source `HwAccelConfig::get()`; ffmpeg.php + hwaccel.php both delegate; no contradictory
`enabled`; `HwAccelConfigTest` genuinely asserts ffmpeg.php['hwaccel'] === HwAccelConfig::get() and
enabled/tone-map/preferred provenance). Fix C probe placement is correct (lazy shared DI factory +
explicit idempotent `probeHardwareAcceleration()` in `start.php` onWorkerStart, OUTSIDE any coroutine,
before the loop serves — blocking exec at boot does not violate the no-native-exec hook mask). Fix D
config source is correct with no side effects.

Findings:

1. **[CONFIRMED — correctness, GPU-only so untestable on this box]**
   `src/Media/Transcoding/FfmpegRunner.php:1819-1867` (`buildHwaccelInputFlags`, nvenc + vaapi cases)
   vs `:1746-1760` (`buildHwaccelSegmentCommand` filter chain).
   The nvenc case emits `-hwaccel_output_format cuda` and the vaapi case emits
   `-hwaccel_output_format vaapi`, which force the decoded frames to stay as HARDWARE surfaces (CUDA /
   VAAPI). But `buildHwaccelSegmentCommand` then appends SOFTWARE filters to the same command:
   `scale={w}:{h}:...` whenever width+height are supplied (line 1756 — i.e. every downscaled ABR
   variant) and the software tone-map graph whenever `$needsToneMap` is true (lines 1746-1750, i.e. all
   HDR content on non-HDR-capable encoders — which includes `h264_nvenc`/`h264_vaapi`). ffmpeg does NOT
   auto-insert `hwdownload` between a hardware-format source and a software filter; it aborts with
   "Impossible to convert between the formats". Net effect: on a real NVENC/VAAPI box the HW segment
   encode SUCCEEDS only for the full-resolution, non-HDR "original" variant and FAILS for every
   downscaled variant and every HDR-tone-mapped segment — directly undermining the SV-0.1 AC ("a
   segment encodes via the HW encoder … falls back to libx264 cleanly"; here it would hard-fail, not
   fall back). Note the NVENC profile (`NvencProfile::getInputDeviceArgs`) deliberately emits only
   `-hwaccel cuda -hwaccel_device N` with NO `-hwaccel_output_format`, precisely so decoded frames land
   in system memory and software filters work; the segment path should do the same.
   Fix direction: drop `-hwaccel_output_format cuda`/`vaapi` (let frames download to system memory so
   the existing software scale/tonemap filters apply), OR switch the segment filter chain to hardware
   filters (`scale_cuda`/`scale_vaapi`) with `hwdownload,format=...` before any software tone-map.

2. **[MAINTAINABILITY / plan-directive miss]**
   `src/Media/Transcoding/FfmpegRunner.php:1819-1867`.
   The plan (performance_plan.md SV-0.1) and the review brief both call for aligning with
   `HwaccelCommandBuilder`'s vendor logic "rather than diverging". `buildHwaccelInputFlags`
   reimplements the input-device flags independently of the vendor profiles' `getInputDeviceArgs()`,
   and the two genuinely diverge: vaapi profile → `-vaapi_device <dev>` vs. segment →
   `-hwaccel vaapi -hwaccel_device <dev> -hwaccel_output_format vaapi`; nvenc profile has no
   output-format vs. segment adds one. The method docblock's claim that it "mirrors the vendor
   profiles' getInputDeviceArgs()" is therefore inaccurate. Two hand-maintained codepaths for the same
   concept will drift. Fix direction: delegate to
   `HwaccelProfileFactory->…->getInputDeviceArgs($capability)` (already imported) so the segment and
   whole-file paths share one source of truth — this also resolves finding #1 for free.

3. **[TEST COVERAGE GAP at the exact risky seam]**
   `tests/Unit/Media/Transcoding/FfmpegRunnerHwaccelTest.php:74-128`.
   Both HW-path tests call `buildHwaccelSegmentCommand` with `['video_codec' => 'libx264']` only — no
   `width`/`height` and no `require_hdr_tone_map` — so the scale/tone-map filter branch (the branch
   that collides with `-hwaccel_output_format` per finding #1) is never exercised. The vaapi test even
   asserts `-hwaccel_output_format vaapi` is present, baking the questionable flag in as expected. The
   tests pass trivially and cannot catch #1. Fix direction: add a case with width/height (and one with
   `require_hdr_tone_map => true`) asserting the input flags and the filter chain are format-compatible
   (i.e. no software `scale=`/tonemap after a hardware-only output format).

4. **[LOW — dual-entrypoint / observation]**
   `public/index.php` (bootstrap) was not touched. The plan's SV-0.1 step asks to "mirror the DI change
   in start.php and index.php bootstraps". Functionally index.php IS covered (it builds the container
   via `ContainerFactory::create`, and the shared `TranscodeServicesProvider` factory runs
   `setConfig()` + `probeHardwareAcceleration()` lazily on first `FfmpegRunner` resolution), so this is
   acceptable under the resident model — but that entrypoint emits no one-time boot log of the chosen
   accelerator and probes lazily on first transcode rather than at worker start. Confirm this is
   intended; if a boot-time log is wanted for the index.php entrypoint it must be added there too.

Out-of-scope note (NOT introduced by this diff, do not fix here): `QsvProbe.php:67` stores the device
under key `'-device'` while both `QsvProfile::getInputDeviceArgs` and the new `buildHwaccelInputFlags`
read `extra_args['device']`, so QSV always uses the `/dev/dri/renderD128` default. Harmless today;
flagging for a future Hwaccel-probe build-out step.

## Fixer — SV-0.1 REVIEW-1 findings (2026-07-12)

Fixed ALL REVIEW-1 findings (#1/#2 correctness defect, #3 test gap, #4 confirmed, plus the QSV
device-key bug). phpstan/phpunit/phpcs green; only the 3 pre-existing phpstan errors remain.

**#1 + #2 (CONFIRMED correctness defect — root fix via profile delegation).**
`src/Media/Transcoding/FfmpegRunner.php`
- Reworked `buildHwaccelInputFlags(HwaccelCapability)` to STOP re-deriving per-vendor input flags
  (which emitted `-hwaccel_output_format cuda`/`vaapi`, keeping decoded frames on a HW surface that
  the software `scale=`/tonemap filters cannot consume → ffmpeg "Impossible to convert between the
  formats" on every downscaled/HDR NVENC/VAAPI segment). It now DELEGATES to the resolved vendor
  profile's `getInputDeviceArgs($capability)` — the SAME source of truth `HwaccelCommandBuilder` uses
  for the whole-file path (:422). Added `HwaccelProfileFactory::getProfileForVendor(string): ?profile`
  (direct vendor→profile lookup, no availability fallback) and a lazily-built `$hwaccelProfileFactory`
  field on the runner. NONE of the profiles emit `-hwaccel_output_format`, so decoded frames now land
  in SYSTEM MEMORY where the software scale/tonemap filters are valid. Rewrote the (previously
  inaccurate) docblock to describe the delegation.
- Frames-flow now: HW/SW decode → frames in system memory → software scale/tonemap (valid) → for
  VAAPI/QSV encoders (which cannot consume system-memory frames) a trailing `format=nv12,hwupload`
  (`…extra_hw_frames=64` for QSV) is appended AFTER the software filters via the new
  `hwaccelUploadFilter(vendor)` helper → HW encode. NVENC/VideoToolbox/AMF accept system-memory frames
  so no upload filter is added. This makes BOTH the downscaled and the HDR-tonemap segment commands
  coherent for NVENC/VAAPI/QSV; the libx264 software fallback path (`buildSegmentCommand`) is unchanged.
- Note (delegation consequence, documented not fixed): v4l2 now also delegates to `V4L2Profile`
  (capture-oriented `getInputDeviceArgs`) exactly as the whole-file `HwaccelCommandBuilder` already
  does — segment and whole-file paths are now identical for every vendor, which is the point of #2.
  Any V4L2Profile transcode-vs-capture concern is a single, shared profile issue (out of scope here).

**#3 (test gap that hid #1).** `tests/Unit/Media/Transcoding/FfmpegRunnerHwaccelTest.php`
- Corrected the vaapi test: it no longer asserts `-hwaccel_output_format vaapi` (the buggy expectation)
  — now asserts `-vaapi_device` + `-c:v h264_vaapi` and NO `-hwaccel_output_format`.
- Added 4 coherence cases exercising the previously-untested colliding branch:
  `test_downscaled_nvenc_segment_has_no_hw_surface_software_filter_collision` (width/height → software
  `scale=1280:720`, no output-format), `test_downscaled_vaapi_segment_uploads_after_software_scale`
  (scale + `hwupload` ordered AFTER the scale, no output-format), `test_hdr_tonemap_nvenc_segment_has_no_hw_surface_collision`
  (forces a real tonemap graph via an anon FfmpegRunner subclass + an HDR-capable NVENC cap; asserts
  tonemap present, no output-format), and `test_qsv_segment_uses_probe_device` (non-default QSV device
  flows through to `-qsv_device /dev/dri/renderD129`, hwupload present, no output-format).

**QSV device-key bug (in-domain).** `src/Media/Transcoding/Hwaccel/VendorProbe/QsvProbe.php:67`
- Fixed the writer: `extra_args` key `'-device'` → `'device'` so it matches what
  `QsvProfile::getInputDeviceArgs` (and the delegated segment path) read. QSV now honors the probed
  device instead of always falling back to `/dev/dri/renderD128`. Reader-side already had a test
  (`QsvProfileTest::test_qsv_device_arg`); added the whole-chain `test_qsv_segment_uses_probe_device`.

**#4 (low — confirmed, no code change).** `public/index.php` is intentionally NOT probed at boot: it
builds the container via `ContainerFactory::create`, and the shared `TranscodeServicesProvider` factory
runs `setConfig()` + `probeHardwareAcceleration()` LAZILY on first `FfmpegRunner` resolution (once, via
the guard) — correct for the resident model. The one-time boot-log of the chosen accelerator is a
`start.php`/daemon concern only (that entrypoint probes explicitly in `onWorkerStart` and logs); the
FPM/index.php path probes lazily on first transcode and does not emit a boot log. Accepted as intended.

**Verification (this fixer pass):**
- phpstan L9 `-c phpstan.neon.dist`: only the 3 PRE-EXISTING errors remain (ItemRepository.php:1283-1284
  cast.double; WebPortalServicesProvider.php:295 BookLibraryManager argument.type). NONE introduced.
- phpunit `--filter Hwaccel`: 139 tests / 333 assertions green (incl. the 4 new coherence cases).
  Related suites (HwaccelProfileFactoryTest, QsvProfileTest, TranscodeServicesProviderTest, the full
  FfmpegRunnerHwaccelTest): 28/28 green.
- phpcs PSR-12 on FfmpegRunner.php + QsvProbe.php + HwaccelProfileFactory.php: 0 errors (only
  pre-existing >120-char warnings on untouched lines 756 / 71 / 82).

**Extra (user-requested during this pass): cleared the pre-existing phpstan L9 + phpcs errors.**
Master phpstan L9 is now fully **[OK] No errors** (was 3) and phpcs PSR-12 on `src/` has **0 errors**
(was 1); the ~205 line-length WARNINGS are left as-is (plan §0.2 marks them non-blocking).
- `src/Media/Library/ItemRepository.php:1283-1284` (SV-2.8 leftover) — `cast.double` (Cannot cast mixed
  to float) on `(float) $streamData['max_luminance'|'avg_luminance']`. Guarded with
  `is_numeric($streamData[...] ?? null)` (behaviour-neutral for numeric/absent; a non-numeric string now
  yields null instead of `0.0`, a minor correctness win). The pre-existing `testAddStream*` red is SV-2.8
  test-drift (fails identically without this edit — verified via git stash), NOT caused here.
- `src/Common/Container/Providers/WebPortalServicesProvider.php:295` (SV-3.2 leftover) — `argument.type`:
  the BookController factory resolved `BookLibraryManager` where the ctor needs `LibraryManager`.
  BookController calls `getAllLibraries()`/`getLibrary()` which BookLibraryManager does NOT expose, so
  this was also a latent runtime fatal — switched the factory to `$c->get(LibraryManager::class)` (already
  registered/autowired + used by sibling factories in the same provider) and dropped the now-unused import.
- `src/Server/Http/Controllers/AudiobookController.php:40` — phpcs PSR-12 "Opening brace must not be
  followed by a blank line": removed the stray blank line after the class brace.

## Reviewer (RE-REVIEW / REVIEW-2, SV-0.1 FIX-1) — 2026-07-12

Re-reviewed the fix diff `0a35848a..4d49d9bc`. The REVIEW-1 CONFIRMED defect (HW-surface frames
colliding with software scale=/tonemap) is genuinely fixed and the fix is correct for NVENC / VAAPI /
VideoToolbox / AMF. Verified locally: phpstan L9 `-c phpstan.neon.dist` = [OK] 0 errors; phpunit
`--filter Hwaccel` = 139 tests / 333 assertions green.

What I confirmed CORRECT (no action):
- Delegation: `buildHwaccelInputFlags()` now returns `profile->getInputDeviceArgs($cap)` (same source
  of truth as `HwaccelCommandBuilder`) and no profile emits `-hwaccel_output_format`, so decoded frames
  land in system memory where the software scale=/tonemap filters are valid. `getProfileForVendor()`
  does a direct `$this->profiles[strtolower($vendor)] ?? null` lookup — unknown/software vendor → null
  → '' (no crash, no input flags), and `hwaccelUploadFilter()` default '' → no stray upload. Safe.
- Filter ORDER (FfmpegRunner.php:1746-1773): tonemap → scale → hwupload, emitted in that array order;
  hwupload appended even with no other filter (needed for the full-res VAAPI/QSV "original" variant).
- NVENC / VideoToolbox / AMF: no upload filter (encoders consume system-memory frames; nvenc uploads
  internally). No residual `-hwaccel_output_format cuda`. Correct.
- VAAPI: `-vaapi_device <dev>` + `format=nv12,hwupload` + `-c:v h264_vaapi` is the canonical
  software-decode→hardware-encode pattern; `-vaapi_device` gives hwupload its device. CORRECT.
- 10-bit/HDR: the tonemap graph ends in `format=yuv420p`; a following `format=nv12` (8-bit 4:2:0 →
  8-bit 4:2:0) before hwupload is a safe no-loss conversion, not a clobber. Safe.
- libx264 no-HW fallback (`buildSegmentCommand`) unchanged.
- Tests genuinely guard the seam: `_downscaled_vaapi_segment_uploads_after_software_scale` asserts
  `strpos(scale) < strpos(hwupload)` (ordering), the NVENC downscale/HDR cases assert scale/tonemap
  present with NO `-hwaccel_output_format`, and `_qsv_segment_uses_probe_device` asserts the probed
  device reaches `-qsv_device`. A reorder or a dropped upload would fail these. Good.
- Scope-creep edits verified CORRECT: `WebPortalServicesProvider.php:295` — BookController's ctor is
  typed `LibraryManager` and it calls `getAllLibraries()`/`getLibrary()` then filters
  `type==='book'` itself (BookController.php:126-127,159), so it needs the generic manager, not a
  book-scoped one; the old `BookLibraryManager` resolve was a real TypeError-at-construction, fix is
  right. `ItemRepository.php:1283-1284` — `is_numeric(... ?? null) ? (float) : null` yields null (not
  a silent 0.0) for non-numeric/absent luminance, a sane "unknown", strictly better than the old
  `isset()` cast. `QsvProbe.php:67` `'-device'`→`'device'` aligns writer with the only reader
  (`QsvProfile::getInputDeviceArgs`), no other reader of `'-device'` exists.

Findings:

1. **[LOWER-CONFIDENCE — GPU-only, Intel-QSV only, unverifiable on this GPU-less box]**
   `src/Media/Transcoding/FfmpegRunner.php:1861-1868` (`hwaccelUploadFilter`, `qsv` arm) together with
   `QsvProfile::getInputDeviceArgs` (emits only `-qsv_device <dev>`).
   For QSV the command becomes `... -qsv_device <dev> -i in -vf "...,format=nv12,hwupload=extra_hw_frames=64" -c:v h264_qsv ...`.
   Two ffmpeg-semantics concerns that this box cannot catch:
   (a) The generic `hwupload` filter needs a *filter* hardware device to upload INTO. `-qsv_device` is
       an option consumed by the h264_qsv *encoder*, not a filter-device initializer; the documented
       QSV software→hardware upload pattern initializes the device explicitly
       (`-init_hw_device qsv=hw:/dev/dri/renderDXXX -filter_hw_device hw`) and neither flag is emitted
       anywhere in the codebase (grep for `init_hw_device`/`filter_hw_device` = none). On a real Intel
       box `hwupload` may abort with "A hardware device reference is required to upload frames to."
   (b) The canonical QSV chain is `hwupload=extra_hw_frames=N,format=qsv` (upload, then declare the QSV
       pixel format), whereas this emits `format=nv12,hwupload`. VAAPI's `format=nv12,hwupload` is fine
       because `-vaapi_device` sets the default device; QSV differs and this asymmetry is exactly the
       "QSV needs a different upload than VAAPI" case flagged in the review brief. The fix did add
       `extra_hw_frames=64` (good) but likely still lacks the device-init half.
   Why it matters: SV-0.1 AC requires "on a box with … QSV available … a segment encodes via the HW
   encoder." VAAPI and NVENC are correct with high confidence; QSV specifically remains unverified and
   at risk of a hard ffmpeg failure (not a clean libx264 fallback). NOT a regression introduced by this
   fix — the whole-file `HwaccelCommandBuilder` path has the same/worse QSV limitation (it emits no
   hwupload at all) — so this can be tracked/verified rather than blocking, but it should be verified on
   real Intel hardware before QSV is claimed working.
   Fix direction: for QSV emit `-init_hw_device qsv=hw:<dev> -filter_hw_device hw` on the input side
   (in place of / alongside `-qsv_device`) and use `hwupload=extra_hw_frames=64,format=qsv`; confirm on
   an Intel box (`ffmpeg -qsv_device …` vs `-init_hw_device qsv`). Applies equally to the whole-file
   builder for consistency.

## Implementer — 2026-07-12 (SV-0.3 + SV-0.4 combined fix + tests)

**Scope:** the 4 async HTTP clients' worker-context detection + coroutine wait. SV-0.5 (WS reaper/heartbeat) NOT touched.

**SV-0.4 bug fixed — Channel::pop() only ever reached inside a coroutine (getCid()>0):**
Added shared guard `WorkerContext::inCoroutine()` = `extension_loaded('swoole') && Swoole\Coroutine::getCid() > 0`.
- `src/Media/Metadata/MetadataHttpClient.php` (get loop): gate now `needsBlocking = !isEventLoopRunning() || !inCoroutine() || requiresBlockingCurl(url)`. getCid>0 (+event loop, +not-TLS) → requestAsync (Channel); getCid<=0 → requestCurl (blocking). TLS still → curl.
- `src/Webhooks/WebhookHttpClient.php` (post): `useAsync = isEventLoopRunning() && inCoroutine() && !requiresBlockingCurl(url)` → postAsync (Channel) else postCurl. Timer-dispatched webhooks (non-coroutine) now correctly blocking.
- `src/Admin/S3Client.php` (doRequest): same `useAsync` gate → requestAsync (Channel) else requestCurl.
- `src/Hub/HttpClient.php` (request): INVERSION corrected. Was: in-coroutine→requestCoroutine (valid) but non-coroutine→requestAsync (Channel, INVALID). Now: requiresBlockingCurl→curl; `isEventLoopRunning() && inCoroutine()`→requestCoroutine (hooked client suspends the coroutine — coroutine-valid, the existing correct in-coroutine path is KEPT); else→requestCurl. Removed the misplaced Channel `requestAsync()` method (now dead) and the unused `use Workerman\Coroutine;` import.
EventLoopTls `requiresBlockingCurl()` fallback preserved (blocking-by-design) and still reachable in every client. Timeout→clean-error/null behavior unchanged.

**Tests added:**
- `tests/Unit/Common/Runtime/WorkerContextTest.php` — SV-0.3: `isEventLoopRunning()` false outside a worker; SV-0.4: `inCoroutine()` false on main stack; branch-selection guard via real client seam (`WebhookHttpClient::post('')` returns blocking-only `'Empty URL'` sentinel → proves blocking path chosen out of coroutine).
- `tests/Unit/Common/Runtime/CoroutineChannelWaitTest.php` — SV-0.4 **coroutine strategy** (swoole present on box): `Swoole\Coroutine\run` asserts (a) `inCoroutine()` flips false→true across coroutine boundary; (b) Channel waiter wakes on callback `push(true)` before timeout; (c) returns false after actually waiting the timeout window (clean timeout, not the immediate-false of an out-of-coroutine invalid pop). Drives the Channel/callback pattern directly because the private client methods need a live Workerman\Http\Client + event loop a bare coroutine can't supply; the pattern is byte-for-byte the client callbacks. Deliberately never calls Channel::pop out of a coroutine (that emits a warning = the bug).

**Verify:** phpstan (level 9, `-c phpstan.neon.dist`) 0 errors. phpunit `--filter 'WorkerContext|CoroutineChannelWait|HttpClient|MetadataHttpClient|Webhook|S3Client'` = OK (91 tests, 244 assertions). phpcs PSR12 on all 5 src files + 2 tests = 0 errors (only pre-existing >120-char warnings in S3Client lines 212/238/256, not on changed lines).

**Adjacent (out of scope, noted):** `src/Plugins/Catalog/PluginCatalogService.php::defaultFetcher()` (SV-4.11) has the same latent no-getCid-gate on its `asyncFetch` Channel path — separate step, not touched here.

## Reviewer (per-step, SV-0.3 + SV-0.4 combined fix) — 2026-07-12

Reviewed diff `ce2d2e04` (fix) + `2a243857` (tests). Verified locally: phpstan L9
`-c phpstan.neon.dist` = [OK] 0 errors; phpunit `--filter
'WorkerContext|CoroutineChannelWait|Webhook|MetadataHttpClient|S3Client|HttpClient'` =
OK (91 tests, 244 assertions); CoroutineChannelWaitTest ran (not skipped — swoole loaded).

CONFIRMED CORRECT (no action):
- Invariant holds in ALL 4 clients — every `Swoole\Coroutine\Channel::pop()` is now
  reachable only with `getCid()>0`:
  - MetadataHttpClient:357 (requestAsync) gated by `!needsBlocking` where
    needsBlocking = `!isEventLoop || !inCoroutine || requiresBlockingCurl`.
  - WebhookHttpClient:126 (postAsync) gated by `useAsync = isEventLoopRunning &&
    inCoroutine && !requiresBlockingCurl`.
  - S3Client:390 (requestAsync) gated by the same useAsync.
  - Hub/HttpClient: no Channel at all after the fix — requestCoroutine() uses the
    hooked Workerman\Http\Client synchronously (suspends the coroutine), gated on
    `isEventLoopRunning && inCoroutine`; else blocking cURL. The false-timeout bug
    path (Channel::pop out of a coroutine) is eliminated.
- HttpClient inversion fix: dead `requestAsync()` (Channel) method removed; no
  residual caller/reference; `use Workerman\Coroutine;` import removed and no
  remaining `Coroutine::`/`Workerman\Coroutine` usage in the file. requestCoroutine
  is genuinely coroutine-safe (hooked client, no Channel).
- EventLoopTls `requiresBlockingCurl()` fallback stays reachable in all 4 (https-
  under-Swoole → blocking cURL); the new inCoroutine gate is ANDed alongside it, so
  TLS is never routed through the coroutine/Channel path.
- Timeout behavior: clean error/null preserved (requestCoroutine throws on null;
  Channel paths translate pop-timeout to null/error). CoroutineChannelWaitTest proves
  a real timeout waits the window (>=40ms), not the immediate-false of an invalid pop.
- `WorkerContext::inCoroutine()` = `extension_loaded('swoole') && Coroutine::getCid()>0`
  — safe when swoole absent (returns false in CLI/PHPUnit → forces blocking path).
- Tests are real, not shallow: CoroutineChannelWaitTest exercises a live
  `Swoole\Coroutine\run` — inCoroutine() flip false→true, wake-on-push before timeout,
  and clean-timeout-after-waiting; it deliberately never pops outside a coroutine.
  WorkerContextTest proves both guards false out-of-worker/out-of-coroutine AND the
  branch selection via a real client seam (WebhookHttpClient::post('') → blocking-only
  'Empty URL' sentinel).

Out of scope (correctly NOT reported as an SV-0.4 finding): PluginCatalogService.php:538
has the same ungated Channel::pop latent gap — belongs to SV-4.11.

NO FINDINGS

---

## Implementer — 2026-07-12 — SV-0.5 completion (3 audit gaps)

The `function_exists`→`class_exists` fix (3 sites), the per-worker repeating WS
reapers, and the one-shot stream heartbeats were already in place. This pass
closes the three RE-AUDIT gaps.

### Gap 1 — S-F28: application-level WS ping (was ABSENT)
- Mechanism chosen: **server-side ping timer + pong callback** (NOT
  `pingInterval`/`pingNotResponseLimit`). Reason: Workerman 5.x's `Worker` does
  NOT expose those properties (they lived in GatewayWorker); the WS protocol
  layer only offers the dynamic `onWebSocketPong` worker callback. Verified in
  `vendor/workerman/workerman/src/Worker.php` (no pingInterval) and
  `Protocols/Websocket.php` (reads `$connection->worker->onWebSocketPong`).
- `Connection.php`: added `pendingPings` counter + `ping()` (sends a WS PING
  control frame `"\x89"`, restores the prior frame type, increments the counter),
  `recordPong()` (reset + refresh activity), `getPendingPings()`.
- `WebSocketServer.php`: bind `onWebSocketPong` in the constructor
  (`@phpstan-ignore property.notFound` — dynamic Workerman callback); arm a ping
  `Timer::add($pingInterval, …)` in `onStart()` (config `ping_interval` default
  30s, `ping_not_response_limit` default 2). `pingConnections(int $limit)` reaps
  any connection whose `getPendingPings() >= $limit` (close + pool remove), else
  pings it. `onWebSocketPong()` clears the counter for the ponging connection.
- Net: a killed/half-open client stops ponging → after `$limit` unanswered pings
  (~2× ping window) it is closed and removed. A live client's pong resets the
  counter each sweep so it is never reaped.

### Gap 2 — key stream heartbeat per-session + teardown on stream end
- `StreamSessionService.php`: added `heartbeatTimerIds` map (session id → timer
  id), `registerHeartbeatTimer($sessionId)` (dedup: no-op if a timer is already
  pending for the session; one-shot `Timer::add(30, …, [], false)`),
  `onHeartbeatTimerFired($sessionId)` (clears its own slot so the next request
  re-arms it, then refreshes the heartbeat), `cancelHeartbeatTimer($sessionId)`
  (`Timer::del` + unset), and `activeHeartbeatTimerCount()`. `releaseStream()`
  now calls `cancelHeartbeatTimer()` before the DELETE — the canonical
  stream-teardown path.
- Callers now delegate: `HttpHandler::checkStreamLimit()` and
  `StreamLimitMiddleware::__invoke()` call `$service->registerHeartbeatTimer()`;
  their private `registerStreamHeartbeat`/`registerHeartbeat` helpers were
  removed. StreamSessionService is a PHP-DI shared singleton within the worker,
  so both paths share one registry (dedup + teardown are consistent).
- Result: at most ONE heartbeat timer per active session (was: one one-shot per
  request — bounded but re-created each HLS segment). Timers self-clear on fire
  and are cancelled on release. `cleanupStaleStreams()` (bulk SQL DELETE) does
  not carry session ids, but the one-shot + self-clear design means a stale
  session's last timer already fired and cleared ~30s after its final request,
  so no accumulation results from that path.

### Gap 3 — tests
- `WebSocketServerTest`: replaced the shallow `testOnStartDoesNotThrow` (stale
  comment referencing the old `function_exists` early-return) with
  `testOnStartRegistersReaperAndPingTimers` — asserts `onStart()` arms exactly 3
  Workerman timers (conn reaper + group reaper + ping) via reflection on
  `Timer::$status`. Added `testPingSweepReapsNonRespondingConnection` (peer never
  pongs → reaped on the sweep that hits the limit) and
  `testRespondingConnectionIsNeverReaped` (pong each sweep → survives).
- New `tests/Unit/Access/StreamSessionServiceTest`:
  `testHeartbeatTimerIsKeyedDedupedAndSelfClearing` (keyed, dedup no-op,
  one-shot self-clear, re-arm, teardown), `testTimerFiringRefreshesHeartbeat`
  (fire → one UPDATE + slot cleared), and the **leak test**
  `testNoTimerLeakAcrossManyStreamStartStopCycles` — 100 sessions × 3 registers
  each yields exactly 100 timers (dedup), and after 100 `releaseStream()` calls
  the active-timer count returns to baseline (0). Deterministic: drives the
  callbacks directly, no real sleeps.

### Dual-entrypoint
No mirroring needed. The WS worker runs only in `start.php` (resident path);
`public/index.php` has no WS worker. All ping/reaper wiring lives inside
`WebSocketServer` (constructor + `onStart`), which `start.php` already
instantiates and calls — so both the constructor binding and the timer arming
are captured automatically.

### Verification
- `phpstan analyze -c phpstan.neon.dist`: **0 errors**.
- `phpunit --filter 'WebSocketServer|StreamLimit|HttpHandler|StreamSession'`:
  **85 tests, 175 assertions, OK** (incl. the new tests).
- `phpcs --standard=PSR12` on touched files: no new errors/warnings (the 5
  long-line warnings in StreamSessionService are pre-existing in
  `getActiveStreamsForProfile`).

NO FINDINGS (pending review)

## Reviewer (per-step) — SV-0.5 — 2026-07-12

Reviewed commits `4decf21e`, `dd842326`, `98073f0f` against the SV-0.5 Acceptance
Criteria, §0.3 async/Swoole + dual-entrypoint rules, and the ping/heartbeat
correctness contract. Verifications (this box):
- `phpunit --filter 'WebSocketServer|StreamLimit|HttpHandler|StreamSession'` → **OK (85 tests, 175 assertions)**.
- `phpstan analyze -c phpstan.neon.dist` → **[OK] No errors** (645/645).
- `phpcs --standard=PSR12` on the 5 touched files → **0 errors**, 5 pre-existing
  >120-char warnings in `StreamSessionService::getActiveStreamsForProfile`
  (lines 356-361, untouched by this step; OK per §0.2).

What is genuinely correct (high confidence):
- `function_exists`→`class_exists` applied at all 3 sites (WebSocketServer.php:124,
  StreamSessionService.php:165/221, and the removed HttpHandler/StreamLimitMiddleware
  helpers). Reaper + ping timers now arm.
- Stream heartbeat timers are one-shot (`Timer::add(30,…,[],false)`), per-session
  keyed, deduped, self-clearing on fire, and cancelled in `releaseStream()`. The
  leak test is deterministic (100 sessions × 3 registers → 100 timers → 0 after
  release) and genuinely guards the seam. DI confirms one shared PHP-DI singleton
  feeds both the HttpHandler (`container->get`) and StreamLimitMiddleware
  (autowired) paths, so the registry is shared as claimed.
- The `ping()` frame encoding is valid: `Websocket::encode` uses
  `$connection->websocketType` as the frame first byte, so `websocketType="\x89"`
  + `send('')` emits a well-formed empty-payload PING; the prior type is restored
  synchronously (encode runs inside send()). Pong→recordPong resets the counter,
  so a responding client is never reaped (test confirms).

### Findings

1. **[HIGH — behavioral AC unverified; static analysis says it fails in the live
   path] The WS ping/pong callback surface is bound to a Worker that never
   listens in the resident `start.php` path (dual-entrypoint gap, §0.3).**
   - `WebSocketServer::__construct` binds `onConnect`/`onMessage`/`onClose`/
     `onWebSocketPong` and creates its socket on its **own** `$this->worker`
     (`WebSocketServer.php:81-97`). But `start.php` does **not** run that worker:
     it declares a **separate** listener `$wsWorker = new Worker('websocket://0.0.0.0:8097')`
     at `start.php:239` (the pre-`runAll` registered worker), and inside
     `$wsWorker->onWorkerStart` it constructs the `WebSocketServer` and calls only
     `$wsServer->onStart()` (`start.php:274-287`). It never calls `$wsServer->run()`
     and never copies WebSocketServer's callbacks onto `$wsWorker`.
   - The `WebSocketServer` internal worker is constructed **after** `Worker::runAll()`
     (inside a forked child's `onWorkerStart`); Workerman only calls `listen()`
     during `runAll()` → `initWorkers()` (`vendor/workerman/workerman/src/Worker.php:588-609`,
     and `start.php:356-357` states this rule outright). So that worker never
     accepts connections — `$wsWorker` is the real listener.
   - The Websocket protocol resolves the pong handler as
     `$connection->onWebSocketPong ?? $connection->worker->onWebSocketPong`
     (`vendor/workerman/workerman/src/Protocols/Websocket.php:215`), where
     `$connection->worker` is the **accepting** worker (`$wsWorker`), which has no
     `onWebSocketPong` (nor `onConnect`/`onMessage`). Consequences in production:
     the pool is never populated via `WebSocketServer::onConnect`, and even if it
     were, pongs would not reach `recordPong()` → `pendingPings` never resets. The
     ping sweep therefore cannot satisfy the AC ("a killed WS client is reaped
     within the ping window" **and** a live/ponging client survives).
   - The unit tests pass because they drive `WebSocketServer` in isolation, where
     its own internal worker holds the callbacks and pool — they cannot catch this
     integration gap. Per §0.7, a behavioral change like this needs an on-the-box
     verification against the running :8097 worker.
   - Why it matters: this is the exact dual-entrypoint trap §0.3 warns about. As
     wired, the SV-0.5 ping/pong (and, by the same defect, the pre-existing
     SyncPlay callback surface) is disconnected from the actual listener, so
     S-F28's half-open detection does not function in the resident path.
   - Fix direction: make the accepting worker and the callback-bearing worker the
     same instance — either (a) bind `onConnect/onMessage/onClose/onError/onWebSocketPong`
     (and the ping/reaper timers) onto `$wsWorker` in `start.php` (e.g. have
     `WebSocketServer` accept an injected Worker or expose a `bindTo(Worker $w)`),
     or (b) declare/run the `WebSocketServer` worker itself before `runAll()`.
     Then verify on the box: a real WS client that pongs survives ≥2 ping
     intervals, and a client killed with pongs suppressed is reaped.

2. **[LOW — accuracy of the stated teardown mechanism] `releaseStream()` has no
   production caller.** `grep` finds `releaseStream` referenced only in tests and
   the doc comments; no HTTP endpoint, `HttpHandler`, or middleware invokes it on
   stream end (`StreamLimitController` exposes get/update/list only). The
   worklog/comments call `releaseStream()` "the canonical stream-teardown path"
   for timer cancellation, but in production timers are only ever removed by the
   one-shot self-clear (~30s after the last request) and DB rows only decrement
   via the 60s `cleanupStaleStreams` sweep. This is **not** a leak (the
   self-clearing one-shot design bounds the timer count, as the implementer note
   acknowledges) and the AC "accounting decrements on stream end" is still met via
   the heartbeat-timeout sweep — so not blocking. Flagged only because the stated
   design overstates what runs in production; if explicit teardown is desired,
   wire `releaseStream()` into a real stream-end signal.

3. **[LOW — doc/comment inaccuracy] Reap latency is ~(limit+1)× the ping interval,
   not "~2×".** With the default `ping_not_response_limit=2`, a dead peer is
   reaped on the **third** sweep (sweep1 pending→1, sweep2 pending→2, sweep3
   observes ≥2 and reaps), i.e. ≈90s at the 30s default — as
   `testPingSweepReapsNonRespondingConnection` itself demonstrates. The
   implementer note and the code comment say "~2× ping window." The AC ("within
   the ping window") is still loosely satisfied; adjust the wording (or reap when
   `pending >= limit` is reached *at ping time* if a tighter bound is wanted).

**Verdict: 3 findings (1 HIGH requiring on-box verification / rewire, 2 LOW).**

## Fixer — SV-0.5 review findings — 2026-07-12

All 3 findings fixed. Files touched:
`src/Server/WebSocket/WebSocketServer.php`, `start.php`,
`src/Access/StreamSessionService.php`,
`tests/Unit/Server/WebSocket/WebSocketServerTest.php`.

### Finding 1 (HIGH) — WS ping/pong bound to a worker that never listens

Root cause confirmed by reading `start.php` + Workerman `vendor/`: `start.php`
declares the real listener `$wsWorker` (pre-`runAll`), but `WebSocketServer`'s
constructor built its OWN `Worker` (post-`runAll`, inside the forked child's
`onWorkerStart`) and bound `onConnect/onMessage/onClose/onError/onWebSocketPong`
to THAT throwaway worker. Workerman only `listen()`s workers created before
`runAll()`, so the internal worker never accepted connections; the accepting
`$wsWorker` had no callbacks. The Websocket protocol resolves pongs via
`$connection->worker->onWebSocketPong` (= the accepting `$wsWorker`), so pongs
never reached `recordPong()`, the pool was never populated via `onConnect`, and
S-F28 half-open detection (and, by the same defect, SyncPlay message routing)
was dead in the resident path.

**Options considered:**
(a) inject the real `$wsWorker` into `WebSocketServer` and bind the callbacks
onto it; (b) have `WebSocketServer` declare/run its own worker before `runAll()`
(would duplicate the listener + fight `start.php`'s container-building
`onWorkerStart`); (c) copy callbacks onto `$wsWorker` in `start.php` by hand
(leaks WS wiring into bootstrap, easy to drift).

**Chosen: (a).** `WebSocketServer::__construct` now takes an optional third arg
`?Worker $worker`. When provided it is used as `$this->worker` (the caller keeps
ownership of `onWorkerStart` — we do NOT clobber it), and the new private
`bindConnectionCallbacks()` binds `onConnect/onMessage/onClose/onError/`
`onWebSocketPong` onto that real listener. When null (tests / SyncPlayWorker /
`run()`) the old standalone behaviour is preserved (own worker + own
`onWorkerStart`). `start.php` now passes `$w` (the `onWorkerStart`-supplied
listening worker) as the third arg, so all callbacks — crucially the pong
handler — bind to the worker that actually accepts on :8097. The reaper + ping
timers are unchanged: they arm via `onStart()` which `start.php` calls inside
`$w`'s `onWorkerStart`, so `Timer::add` registers in that same worker process,
and they now operate over a pool that `onConnect` genuinely fills.
New `getWorker()` accessor exposes the wired worker.

Regression guard: `testCallbacksBindToInjectedListeningWorker` injects a listener
worker (with a caller-owned `onWorkerStart`) and asserts `getWorker()` returns
that same instance, that all four lifecycle callbacks AND `onWebSocketPong` are
bound to it, and that the caller's `onWorkerStart` is NOT overwritten. This
fails against the old code (callbacks would be on the internal throwaway worker).

Dual-entrypoint: `public/index.php` has NO WS worker (verified — `grep` for
`websocket|WebSocketServer|8097|wsWorker` returns nothing), so no mirroring is
needed; the WS worker is resident-only (`start.php`). `SyncPlayWorker` (the
self-listening reference pattern) is unused in both entrypoints (only referenced
in its own docblock example), so it was left untouched.

### Finding 2 (LOW) — `releaseStream()` teardown wording overstated

No clean stream-end hook exists: the streaming path is pure signed-URL HTTP GETs
(direct + HLS segments); the client just stops requesting, and `active_streams`
rows are removed only by the 60s `cleanupStaleStreams()` sweep. Chose the honest
option (correct the wording, do NOT invent a fake hook). Reworded the
`heartbeatTimerIds` property docblock, `registerHeartbeatTimer()` docblock, and
`releaseStream()` docblock in `StreamSessionService` to state teardown in the
resident HTTP path is TIMEOUT-DRIVEN (one-shot self-clear ~30s after the last
request + the 60s stale sweep), and that `releaseStream()` is the EXPLICIT
teardown path for callers that DO have a stream-end signal (currently tests).

### Finding 3 (LOW) — reap-latency figure corrected

The "~2×" figure lived in the previous implementer note. Added an accurate
comment in `WebSocketServer::onStart()` (ping-timer block): a dead peer is reaped
on the sweep that OBSERVES `pendingPings >= limit`, i.e. the `(limit + 1)`th
sweep, so with defaults (interval 30s, limit 2) reap latency is
`~(limit + 1) x interval ≈ 90s`, not ~2x. (This note's Gap-1 "~2× ping window"
line above is superseded by that figure.)

### Verification (this box)
- `phpunit --filter 'WebSocketServer|StreamLimit|HttpHandler|StreamSession'` →
  **OK (86 tests, 182 assertions)** (was 85; +1 regression test).
- `phpunit tests/Unit/Server/WebSocket tests/Unit/Access` → **OK (37 tests, 120
  assertions)** (constructor-signature regression check across the WS/Access
  suites).
- `phpstan analyze -c phpstan.neon.dist` → **[OK] No errors** (645/645).
- `phpcs --standard=PSR12` on touched files → WebSocketServer.php + the test file
  **clean**; StreamSessionService.php only the 5 PRE-EXISTING >120-char warnings
  in `getActiveStreamsForProfile` (lines 370-375, untouched); start.php only the
  PRE-EXISTING line-5 file-header error (my diff starts at line 271). No NEW
  errors/warnings introduced.

### On-box verification STILL NEEDED
Finding 1 is a resident-runtime wiring fix and `start.php` is OUTSIDE CI, so unit
tests cannot exercise the real :8097 listener. Per §0.7 this warrants an on-box
confirmation after deploy: connect a real WS client that pongs and confirm it
survives ≥2 ping intervals, then kill a WS client (suppress pongs) and confirm it
is reaped within ~(limit+1)× the ping interval, and confirm SyncPlay message
routing works over the live socket.

## Reviewer (REVIEW-2) — SV-0.5 — 2026-07-12

Confirming review of the Fixer's changes for the 3 per-step findings (commits
`1a54bc2b`, `1fbfacf7`, `238dced8`; diff `24cbd6a6..238dced8`). Read the actual
current code (not the Fixer note): `WebSocketServer.php`, `start.php`,
`StreamSessionService.php`, the regression test, and the Workerman
`Protocols/Websocket.php` pong resolution.

Verifications (this box):
- `phpunit --filter 'WebSocketServer|StreamLimit|HttpHandler|StreamSession'` →
  **OK (86 tests, 182 assertions)**.
- `phpstan analyze -c phpstan.neon.dist` → **[OK] No errors** (645/645).
- `phpcs --standard=PSR12` on `WebSocketServer.php` + `StreamSessionService.php`
  → **0 errors**; the only 5 warnings are the PRE-EXISTING >120-char lines in
  `getActiveStreamsForProfile` (lines 370-375) — confirmed untouched (diff hunks
  are only at 35-45 / 128-145 / 152-172). No new phpcs/phpstan issues.

Finding-by-finding confirmation:

1. **[HIGH — RESOLVED, on-box verify still open] WS ping/pong now binds to the
   real listening worker.** Confirmed:
   - `WebSocketServer::__construct` (WebSocketServer.php:79-104) takes optional
     `?Worker $worker`; when injected it becomes `$this->worker` WITHOUT the
     `onWorkerStart = [$this,'onStart']` assignment (that only happens in the
     else/standalone branch), so the caller's `onWorkerStart` is NOT clobbered.
     `bindConnectionCallbacks()` (124-132) binds onConnect/onMessage/onClose/
     onError/onWebSocketPong onto that worker.
   - `start.php:239-298`: `$wsWorker` is declared BEFORE `runAll()` (real
     listener on :8097); its `onWorkerStart` receives the forked worker `$w`
     (=`$wsWorker` in-child) and passes `$w` as the 3rd ctor arg, then calls
     `onStart()`. So (a) the pong genuinely resolves on the accepting worker:
     `Protocols/Websocket.php:215` reads `$connection->worker->onWebSocketPong`
     and `$connection->worker` is `$wsWorker`=`$w`, which now carries the bound
     `onWebSocketPong` → `recordPong()`; (b) `onWorkerStart` is start.php's
     container-building closure, untouched; (c) the ping/reaper timers arm via
     `onStart()` inside `$w`'s `onWorkerStart`, so `Timer::add` registers in the
     same process whose `onConnect` fills the (singleton) `ConnectionPool` that
     `pingConnections()` iterates — pool and timers coincide; (d) null-worker
     path preserved: else-branch creates its own worker + `onWorkerStart`, and
     `run()`→`Worker::runAll()` still listens (tests / SyncPlayWorker / `run()`);
     (e) MessageHandler wiring intact (onMessage → handler, unchanged); (f) no
     double-listen — the resident path creates NO second Worker, so it cannot
     fight start.php's listener.
   - `public/index.php` has NO WS worker (grep for
     `websocket|WebSocketServer|8097|wsWorker` returns nothing) → no mirroring
     needed; correct.
   - Regression test `testCallbacksBindToInjectedListeningWorker` genuinely
     guards the pre-fix defect: it asserts `getWorker() === $listener` (the
     accessor did not exist pre-fix) and that all four lifecycle callbacks AND
     `onWebSocketPong` are `[$server, …]` on the INJECTED listener (pre-fix they
     bound to the throwaway internal worker), and that the caller-owned
     `onWorkerStart` is not overwritten. Fails against the old wiring.
   - Residual: this is a resident-runtime fix; `start.php` is OUTSIDE CI so unit
     tests cannot exercise the live :8097 socket. Per §0.7 an on-box
     confirmation remains the ONLY open item (a ponging client survives ≥2 ping
     intervals; a pong-suppressed client is reaped at ~(limit+1)×interval;
     SyncPlay routing works over the live socket).

2. **[LOW — RESOLVED] `releaseStream()` teardown wording corrected.** The
   `heartbeatTimerIds` / `registerHeartbeatTimer()` / `releaseStream()`
   docblocks now state teardown in the resident HTTP path is TIMEOUT-DRIVEN
   (one-shot self-clear ~30s after the last request + the 60s
   `cleanupStaleStreams()` sweep) and that `releaseStream()` is the explicit
   path for callers with a real stream-end signal (currently tests). Accurate;
   no behavior change; still not a leak (self-clearing one-shot bounds the
   count).

3. **[LOW — RESOLVED] Reap-latency comment corrected.** The `onStart()`
   ping-timer block now documents reap on the sweep that OBSERVES
   `pendingPings >= limit` = the `(limit+1)`th sweep → `~(limit+1)×interval
   ≈ 90s` at the defaults, matching `testPingSweepReapsNonRespondingConnection`.

**Verdict: NO FINDINGS.** SV-0.5 is code/test-complete (all 3 prior findings
resolved; suite green, phpstan 0, no new phpcs) — modulo the on-box confirmation
of the resident :8097 ping/pong + SyncPlay routing (start.php is outside CI) and
the pending Docs cycle.

## Implementer — X2/HB-2.1 server side — 2026-07-12

Built the phlix-server half of cross-repo sync point X2 (HB-2.1 "bodied relay >64KB").
Before this, a >64KB relayed request failed 400-malformed: the hub chunks large
request bodies (RelayHttpRequestCodec HEAD/BODY/END tag-byte frames sharing one
requestId, `RelayProxyManager.php:288-319`), but the server did
`RelayHttpRequest::fromJson()` UNCONDITIONALLY on the first frame and threw on the
tag-byte HEAD. The server was also not repinned to the shared request codec.

### 1. Repin phlix-shared (old → new)
- `composer.json`: added a `path` repository `/home/sites/phlix/phlix-shared`
  (`symlink:false`) BEFORE the existing vcs repo, and lowered the constraint
  `detain/phlix-shared` `^0.19.0` → `^0.18.0` — MIRRORING the hub's convention
  exactly (hub `composer.json` uses the same path repo + `^0.18.0`).
- `composer update detain/phlix-shared` → **v0.19.0 (ref 0fa2709c) → 0.18.0
  (ref 216ea5dfa7faf2a422dbf0a1a4116442994e7c74)**. NOTE the version NUMBER drops
  but the CODE is a strict SUPERSET: the v0.19.0 tag commit (0fa2709) is an
  ANCESTOR of master HEAD 216ea5d, and the request-codec commits (008fcc1 subdomain,
  216ea5d codec) sit ABOVE the v0.19.0 tag but were never tagged (the shared repo's
  composer.json `version` field was left at 0.18.0). So 216ea5d = everything in
  v0.19.0 PLUS `RelayHttpRequestCodec` / `RelayHttpRequestHead` / `RelayHttpRequestChunk`.
  This is the identical situation the hub already resolved. Lock diff is scoped to
  the single `detain/phlix-shared` package (verified by diffing the package list —
  only that one line changed). Codec vendored:
  `vendor/detain/phlix-shared/src/Relay/RelayHttpRequestCodec.php` (+ Head + Chunk)
  all present after `composer install`.

### 2. Reassembly in RelayConsumer::onHttpRequest (`src/Hub/RelayConsumer.php`)
- Single-vs-chunked branch: `onHttpRequest` now peeks the first payload byte.
  The legacy single-frame JSON envelope always begins with `{` (0x7B); the chunked
  codec tags are HEAD 0x01 / BODY 0x02 / END 0x03 — disjoint sets, so the first
  byte unambiguously selects the path. `isChunkedRequestFrame()` matches the three
  tag bytes; anything else (incl. an empty payload) falls through to the UNCHANGED
  `RelayHttpRequest::fromJson()` legacy path (full back-compat, incl. the prior
  400-on-garbage behaviour).
- `onHttpRequestChunk()` mirrors the hub's response-side reassembly with a
  per-requestId accumulator map `requestAccumulators`
  (`array{head: RelayHttpRequestHead, body: string, size: int}`):
  HEAD opens an accumulator carrying method/path/headers; BODY appends raw bytes;
  END finalizes → builds the full `RelayHttpRequest(method,path,query,headers,body)`,
  calls `assertSafe()` (inheriting fromJson's method/path gate), then dispatches
  via the shared `dispatchEnvelope()` tail (extracted from the old onHttpRequest so
  both paths reuse dispatch+stream+log).
- Abuse guards: body capped at `MAX_REASSEMBLED_REQUEST_BODY` (25 MiB) — overflow →
  413 + accumulator dropped; concurrent assemblies capped at
  `MAX_CONCURRENT_REQUEST_ASSEMBLIES` (128) — excess HEAD → 503; duplicate HEAD →
  400 + drop; BODY/END without HEAD → 400; malformed chunk/head-JSON → 400.
- Accumulator lifecycle (no resident-worker leak): finalized+removed on END BEFORE
  dispatch; dropped on HTTP_CANCEL (`onHttpCancel` now calls
  `discardRequestAccumulator`); cleared wholesale in `handleDisconnect()` and
  `stop()` (tunnel teardown).

### 3. Dual entrypoints
No bootstrap/DI change — the reassembly is entirely internal to RelayConsumer
(only new private fields/methods/constants; constructor signature unchanged), so
neither `start.php` nor `public/index.php` needed mirroring.

### Tests (`tests/Unit/Hub/RelayConsumerTest.php`, +8)
- `test_http_request_chunked_reassembles_binary_body_and_dispatches` — feeds a real
  HEAD + N·BODY (>2 frames) + END for a 140,000-byte body covering the FULL byte
  range (chr(i%256) incl NUL/0xFF) via the real `RelayHttpRequestCodec::encode*`;
  asserts the dispatcher saw byte-identical `rawBody`, correct method/path/query/
  headers + forwarded relay user, that nothing dispatched before END, a 200 streamed
  back on the same requestId, and the accumulator cleared to 0.
- `test_http_request_legacy_single_frame_with_body_still_dispatches` — the `{`-prefixed
  JSON envelope path (PUT + small body) still dispatches, opens NO accumulator.
- Guards: body-without-HEAD → 400; END-without-HEAD → 400; duplicate HEAD →
  400+cleared; malformed HEAD JSON → 400; body overflow → 413+cleared (size seeded
  near the cap via reflection so no 25 MiB is shovelled through the framer);
  HTTP_CANCEL drops a partial assembly. All assert `pendingAccumulatorCount()==0`
  (reflection) so no throw escapes and nothing leaks.

### Verification (actual)
- `composer update detain/phlix-shared`: `Downgrading detain/phlix-shared
  (v0.19.0 => 0.18.0)` … `Mirroring from /home/sites/phlix/phlix-shared`; lock diff
  scoped to that one package.
- `ls vendor/detain/phlix-shared/src/Relay/RelayHttpRequestCodec.php` → exists (+ Head + Chunk).
- `./vendor/bin/phpunit --filter 'RelayConsumer|RelayHttpRequest'` → **OK (45 tests,
  227 assertions)** (8 new).
- `./vendor/bin/phpstan analyze -c phpstan.neon.dist` → **[OK] No errors** (645/645).
- `./vendor/bin/phpcs --standard=PSR12 src/Hub/RelayConsumer.php` → 0 errors. (The
  test file's snake_case method-name errors are the file's pre-existing convention
  across ALL methods and `tests/` is outside the `src/`-only phpcs gate.)

FOLLOW-UPS (owned by the phase coordinator, not this task): hub emission unit test
(RelayProxyManager >64KB → HEAD+N·BODY+END on one requestId) + the full end-to-end
hub-emit → server-reassemble round-trip integration test.

## Reviewer — X2/HB-2.1 server side (post-deps verify) — 2026-07-12

Post-pause re-verification after the external deps change. Server HEAD is now
`9fe18fa9 deps: source detain/phlix-shared from Packagist (^0.20.0), drop dev path repo`.
The X2 implementer's OLD approach (dev path repo → v0.18.0, ref 216ea5d) has been
REPLACED: `phlix-shared` is now sourced from Packagist `^0.20.0`. The exact ref the
implementer pinned no longer applies, so this pass re-confirms the reassembly work
survived the swap.

### (1) Master-green status — X2/HB-2.1 targeted gates (actual output)

- `composer install` → `Nothing to install, update or remove` (lock in sync; only the
  known abandoned-package + PSR-4 skip notices, both pre-existing/cosmetic).
- **Request codec vendored (^0.20.0):**
  `vendor/detain/phlix-shared/src/Relay/RelayHttpRequestCodec.php` (5874 B),
  `RelayHttpRequestHead.php` (5169 B), `RelayHttpRequestChunk.php` (1275 B),
  `RelayHttpRequest.php` (11172 B) — ALL PRESENT.
  `composer.lock` → `detain/phlix-shared 0.20.0`, dist type `zip`, ref `94458ab7…`
  (Packagist-sourced, no path repo). NOT a RED blocker — the reassembly code's codec
  dependency resolves.
- `phpstan analyze -c phpstan.neon.dist` → **[OK] No errors** (645 files, 0 errors).
- `phpunit --filter 'RelayConsumer|RelayHttpRequest'` → **OK (45 tests, 227 assertions)**.
- `phpcs --standard=PSR12 src/Hub/RelayConsumer.php` → **0 errors**.

Broader `phpunit --testsuite Unit` sanity (OUT OF SCOPE for this step): 4907 tests,
11 errors / 21 failures. All reds are in unrelated domains and NONE touch
RelayConsumer / RelayHttpRequest / phlix-shared, and NONE are caused by the deps bump:
- SV-4.13 command-builder drift: `FfmpegRunnerTest::testBuildTranscodeCommand*`,
  `FfmpegRunnerHlsTest::testBuildHlsCommand*`, `TranscodeManagerTest::*` (whole-file
  builder removals — separate step).
- SV-2.8 stream drift: `ItemRepositoryTest::testAddStream*`.
- Other pre-existing/domain-or-env reds: `ThemeMediaStreamControllerTest`,
  `PhotoControllerTest`, `AudiobookControllerTest` (Range requests),
  `BookProgressStoreTest`, `LibraryMetadataMatcherTest`, `SyncPlayManagerTest`,
  `LibraryScanWorkerTest`, `LibraryScanCommandTest`.
These are tracked under their own steps; the deps swap only changed phlix-shared
sourcing (path→Packagist, 0.18→0.20) and the relay-codec content is byte-for-byte the
`@since 0.17.0` API the reassembly targets. The X2/HB-2.1 slice is GREEN.

### (2) Request codec confirmed vendored via ^0.20.0

`RelayHttpRequestCodec` / `RelayHttpRequestHead` / `RelayHttpRequestChunk` /
`RelayHttpRequest` are all present in the Packagist-sourced `0.20.0` package. The codec
constants are unchanged from the version the implementer coded against:
`TAG_HEAD=0x01`, `TAG_BODY=0x02`, `TAG_END=0x03`, `MAX_BODY_CHUNK=65534`;
chunk kinds `KIND_HEAD='head'`, `KIND_BODY='body'`, `KIND_END='end'`;
`RelayHttpRequest(method, path, query, headers, body)` + `assertSafe()`;
`RelayHttpRequestHead(method, path, query, headers)` + `withBodySize()`. No 0.18→0.20
API drift that affects the reassembly.

### (3) Confirming review of the reassembly (RelayConsumer::onHttpRequest + accumulator + tests)

Reviewed `src/Hub/RelayConsumer.php:770-973` (branch/accumulator/finalize),
`:1442-1452` (cancel), `:306-308`/`:1668-1675` (teardown), and
`tests/Unit/Hub/RelayConsumerTest.php:819-1068`. Verified against the ^0.20.0 codec API:

- First-byte branch (`isChunkedRequestFrame`, :826-833) tests exactly
  `RelayHttpRequestCodec::TAG_HEAD/BODY/END`; legacy JSON envelope begins `{` (0x7B),
  which cannot collide with 0x01/0x02/0x03 — unambiguous. Empty payload falls through
  to the legacy branch and is rejected 400 as before (back-compat preserved).
- Per-requestId accumulator: HEAD opens (rejects duplicate HEAD → 400+clear; rejects
  when ≥128 concurrent → 503 without opening); BODY appends raw `$chunk->body`
  (rejects body-before-HEAD → 400; enforces the 25 MiB / 26214400-byte cap →
  413+clear); END finalizes — accumulator is `unset` BEFORE dispatch (slow dispatch
  can't pin it; late duplicate END is a no-op), rebuilds the full `RelayHttpRequest`
  with the byte-identical concatenated binary body and calls `assertSafe()` (same
  method/path gate `fromJson` applies) before `dispatchEnvelope`.
- Abuse guards all present: 25 MiB cap→413, 128-assembly cap→503,
  orphan BODY/END→400, duplicate HEAD→400, malformed chunk/head→400+clear.
- No resident-worker leak: accumulator cleared on END, on decode error, on overflow,
  on duplicate HEAD, on missing-dispatcher, on HTTP_CANCEL (:1452), and the whole map
  is reset on stop (:307) and disconnect (:1675).
- Tests genuinely exercise the REAL vendored codec: the binary-body test
  (`test_http_request_chunked_reassembles_binary_body_and_dispatches`, :819) builds a
  140000-byte body spanning the full 0x00–0xFF range via
  `RelayHttpRequestCodec::encodeHead/chunkBody/encodeEnd`, asserts >2 BODY frames,
  asserts NO dispatch before END, and asserts the reassembled `rawBody` is
  byte-identical. Companion tests cover legacy single-frame-with-body (:890),
  body-before-HEAD 400 (:923), END-before-HEAD 400 (:941), duplicate HEAD 400+clear
  (:959), malformed HEAD 400 (:983), overflow 413+clear (:1005), and
  HTTP_CANCEL clears the pending accumulator (:1044) — each asserting the
  accumulator count returns to 0.

**NO FINDINGS.** The X2/HB-2.1 server-side chunk reassembly survived the phlix-shared
path→Packagist ^0.20.0 swap intact, matches the vendored codec API exactly, and is
correct.

## Unit-suite RED triage (orchestrator, post-deps resume) — 2026-07-12

Gates GREEN: phpstan L9 0 errors, phpcs 0. Full `--testsuite Unit`: 4870 pass / 11 err / 21 fail / 5 skip.
NONE env-dependent (withFile uses tempnam temp files; FfmpegRunner tests are pure command-string builders; DB mocked). Classified:

**A — KNOWN-DRIFT (owned by step, expected):**
- SV-2.8: ItemRepositoryTest::testAddStream{PersistsTrackMetadataColumns,InsertsStream} — INSERT param projection drift.
- SV-4.13: FfmpegRunnerTest::testBuildTranscodeCommand* (×3) + FfmpegRunnerHlsTest::testBuildHlsCommand* (×5) — reference removed whole-file builders.

**C — GENUINE (step marked [x] but red → REOPEN):**
- **withFile Response-contract cluster (15 reds, ONE root cause):** Response::withFile() stores filePath/offset/length; Content-Range/Content-Length/body computed by PRIVATE finalizeFileHeaders() at send-time (Response.php:458, run at :398/:435/:542), so post-return `headers['Content-Range']`==null, `body`==''. Tests assert the pre-refactor EAGER contract.
  - SV-2.4 ThemeMediaStreamControllerTest ×7 (range/suffix/openended/clamp/200-body)
  - SV-2.5 PhotoControllerTest ×3 (full Content-Length + range)
  - SV-3.2 AudiobookControllerTest ×5 (range/full/binary-not-base64 — empty body)
  ⚠️ FIX RULE: VERIFY the deferred finalizeFileHeaders path actually emits correct Range output at emit-time BEFORE aligning tests; only update tests to the real deferred contract (or expose a test-visible finalizer). If runtime Range is actually wrong, fix the IMPL. Do NOT weaken assertions to hide a real bug.
- **SV-3.5 LibraryMetadataMatcher ×2 (real logic gap):** testPerItemExceptionDoesNotAbortRun ERRORs — per-item resolver exception NOT caught, aborts whole run (missing per-item try/catch); testEmitsProgressLogEntriesAsItRuns — 'item not matched' progress log not emitted.
- **SV-2.9 scanner rescan ×2 (real logic gap):** LibraryScanWorkerTest::testRunOnceProcessesRescanJob (rescan path calls unwired collaborator → swallowed into markFailed @LibraryScanWorker.php:170); LibraryScanCommandTest::testRescanFlagCallsRescanLibrary (--rescan returns exit 1 not 0).
- **SV-3.2 BookProgressStore ×1:** testSaveAndRetrieveProgress — saveProgress passes raw float percent_complete (BookProgressStore.php:95) where test expects is_string($params[5]); determine correct persisted-type contract.
- **SV-2.6 SyncPlayManager ×2:** testBroadcastToGroup{DeliversToAllConnectedMembers,ExcludesSpecifiedMemberIds} ERROR — ConnectionInterface::send():bool mock returns null (not stubbed willReturn(true)); backpressure reads the bool. Test-side stub gap (verify prod send() always returns bool).

**Non-red hygiene note:** TranscodeManager.php:2161-2164 reads color_transfer/primaries/space without ?? → ~15 benign 'Undefined array key' warnings in TranscodeManagerTest (tests PASS); SV-1.1 area, add ?? guards (low priority).

## Fixer — withFile Response-contract cluster (SV-2.4/2.5/3.2) — 2026-07-12

Reopened the 15-red `withFile()` cluster (ThemeMediaStreamController ×7, PhotoController ×3, AudiobookController ×5). Per the FIX RULE, verified the deferred `finalizeFileHeaders()` path against Workerman's native encode (`vendor/workerman/workerman/src/Protocols/Http.php::encode()` lines 407-435) BEFORE touching tests. `finalizeFileHeaders()` is a faithful mirror of Workerman's encode: `bodyLen = length>0 ? length : fileSize-offset`; Content-Length=bodyLen; Accept-Ranges=bytes; `if (offset || length)` → Content-Range `bytes offset-offsetEnd/fileSize` + status 206.

**Deferred path correct? — per case:**
- **RANGE (bytes=2-5, suffix -3, open-ended 5-, clamp 8-100) → CORRECT.** Byte-emulated all four (probe): Content-Range/Content-Length/206/body window all byte-exact. These tests asserted the REAL contract, just eagerly (before finalize runs). → STALE-TEST.
- **FULL non-range (offset 0, `length=fileSize`) → WRONG = REAL BUG.** All three controllers passed `withFile($path, 0, $fileSize)` (or `$start,$length` with length=full) for a plain non-Range GET. Because `length>0`, Workerman's `if ($offset || $length)` branch fires → it forces a **206 + Content-Range onto a request that never sent a Range** (RFC 7233 violation: 206 only for range requests). The controllers set status(200) but the event loop overrides to 206 on the wire. This is a genuine production defect, not test drift.

**Verdict per controller:**
- **ThemeMediaStreamController (SV-2.4):** REAL-BUG in the 200 path (`streamFile()`), STALE-TEST for the range paths. Fixed: full GET now calls `withFile($filePath)` (no window) → correct 200 + Content-Length, no spurious 206.
- **PhotoController (SV-2.5):** REAL-BUG in `getFull()` no-range path, STALE-TEST for range. Fixed: `$isPartial = $rangeHeader !== null`; full GET passes length 0 → 200; range keeps `withFile($path,$start,$length)` → 206.
- **AudiobookController (SV-3.2):** REAL-BUG in `streamAudiobook()` full-from-start path, STALE-TEST for range/binary. Fixed: `$isFullFromStart = $rangeHeader===null && $start===0` passes length 0 → 200. The chapter/offset resume window ($start>0) legitimately serves partial content and KEEPS its byte window (unchanged; `testStreamAudiobookResumesInChapter` still 200 at return).

**Impl changes:**
- `src/Server/Http/Response.php` — added public `materializeFileWindow(): self`. Collapses a `withFile()` response into a buffered one whose statusCode/headers/body equal EXACTLY the deferred wire output: runs the same private `finalizeFileHeaders()` the CGI `send()` path uses, then reads the identical offset/length window Workerman streams into `$body`. Production never calls it (still streams lazily via `toWorkermanResponse()`/`send()` — no heap buffering regression); it exists as the unit-test/inspection seam. Idempotent (clears filePath). Does NOT touch the SV-2.1 relay path or `toWorkermanResponse()`.
- `ThemeMediaStreamController.php` (streamFile 200 branch), `PhotoController.php` (getFull), `AudiobookController.php` (streamAudiobook) — full non-range GET now passes NO byte window so the deferred path keeps a correct 200.

**Test changes:** the 15 tests now call `$response->materializeFileWindow()` before asserting body/Content-Range/Content-Length — so they verify the TRUE deferred wire output (the same computation production emits), not a stale eager contract. No assertions weakened; every range/full case still proves correct status + Content-Range + Content-Length + windowed body.

**Verification (actual output):**
- `phpunit --filter 'ThemeMediaStreamController|PhotoController|AudiobookController'` → `OK (70 tests, 162 assertions)` (was 15 red).
- `phpunit --testsuite Unit` → `Tests: 4907, Assertions: 38448, Errors: 11, Failures: 6, Skipped: 5`. Failures dropped 21→6; the 15 cleared. Remaining reds are ONLY other buckets: SV-2.8 ItemRepository::testAddStream* (2), SV-4.13 FfmpegRunner*/Hls* (8), SV-3.5 LibraryMetadataMatcher (2), SV-2.9 LibraryScanWorker/LibraryScanCommand (2), BookProgressStore (1), SV-2.6 SyncPlayManager (2). No ThemeMediaStream/Photo/Audiobook reds remain; no NEW reds introduced.
- `phpstan analyze -c phpstan.neon.dist` → `[OK] No errors`.
- `phpcs --standard=PSR12` on the 4 touched src files → 0 errors (1 PRE-EXISTING >120-char warning at PhotoController.php:387, outside my diff hunks 427-441).

## Fixer — SV-3.5 — 2026-07-12

Cleared the 2 genuine SV-3.5 reds (`LibraryMetadataMatcherTest::testPerItemExceptionDoesNotAbortRun` ERROR + `testEmitsProgressLogEntriesAsItRuns` failure). Both were real logic gaps in the SEQUENTIAL (non-coroutine) branch of `matchBatchConcurrently()` — PHPUnit CLI never enters a Swoole coroutine, so the sequential path is what tests exercise, and it lacked the per-item try/catch + `not matched` instrumentation that the coroutine branch already had.

**Root cause:**
- The coroutine fan-out branch (LibraryMetadataMatcher.php ~1936) already wrapped `executeMatchForItem()` in a per-item `try/catch (Throwable)` that logs a MEDIA-channel warning and continues via `finally`. The sequential branch (~1891-1904) called `executeMatchForItem()` bare, so a resolver throwing (`RuntimeException: resolver exploded`) propagated out of the batch loop → out of `matchLibrary()` → aborting the whole run (the remaining `item-good` was never processed). Hence the ERROR + wrong `{matched,processed}`.
- The sequential branch only emitted `item matched` on a hit; a processed-but-unmatched item emitted nothing, so `item not matched` never reached the DEBUG log the test asserts.

**Fix (impl only, no test contortion):**
- `src/Media/Metadata/LibraryMetadataMatcher.php` — sequential branch: wrapped the per-item `executeMatchForItem()` in `try/catch (Throwable)`; on throw it logs `LibraryMetadataMatcher: item match failed; skipping` on the injected StructuredLogger (MEDIA channel) with `library_id/item_id/name/error` and `continue`s to the next item (the item still counts as `processed` since `$processed++` runs before the try). On a resolver miss (`$hit === false`) it now emits a per-item DEBUG `LibraryMetadataMatcher: item not matched` line mirroring the existing `item matched` shape.
- Same `item not matched` DEBUG added to the coroutine branch's `else` for genuine parity, so both execution paths emit identical per-item progress instrumentation (not a test-only hack).
- Preserved all SV-3.5 work: bounded-concurrency semaphore, coroutine fan-out, 429/backoff (in MetadataHttpClient), bounded cache — untouched. Sequential path still exact-sequential for non-coroutine callers.

**Behaviour now:** one item's exception is isolated per-item, logged, and the run continues to process every subsequent item; `matchLibrary()` returns the full `{matched, processed}` count. `testPerItemExceptionDoesNotAbortRun` → `{matched:1, processed:2}`. Progress log emits start (INFO) + per-batch progress (INFO) + complete (INFO) + per-item `item matched`/`item not matched` (DEBUG).

**Verification (actual output):**
- `phpunit --filter 'LibraryMetadataMatcher'` → `OK (39 tests, 142 assertions)` (was 2 red).
- `phpunit --testsuite Unit` → `Tests: 4907, Assertions: 38450, Errors: 10, Failures: 5, Skipped: 5, Risky: 3`. Errors 11→10, Failures 6→5 — exactly the 2 SV-3.5 reds cleared; no NEW reds. Remaining reds are ONLY the other triage buckets: SV-2.8 ItemRepository::testAddStream* (2), SV-4.13 FfmpegRunner*/Hls* (8), SV-2.9 LibraryScanWorker/LibraryScanCommand (2), SV-3.2 BookProgressStore (1), SV-2.6 SyncPlayManager (2).
- `phpstan analyze -c phpstan.neon.dist` → `[OK] No errors`.
- `phpcs --standard=PSR12 src/Media/Metadata/LibraryMetadataMatcher.php` → 0 errors (5 PRE-EXISTING >120-char warnings at lines 604/609/1227/1429/1436, all outside my ~1888-1955 diff region).

SV-3.5 red cleared — step done-note stands; the 2 shipped-red tests are now green because the behaviour is correct.

## Fixer — SV-2.9 — 2026-07-12

**Verdict: REAL IMPL BUG (rescan silently did nothing) + two stale tests exposed by the same refactor.**

Root cause traced to commit `19ca9b62` (SV-3.2 book/audiobook backends). It **gutted the base
`LibraryManager::rescanLibrary()` into a no-op stub** — the pre-existing body (commit `266f957b`)
did `DELETE FROM media_items WHERE library_id=? ` then delegated to `scanLibrary()` (which derives
the library's configured paths AND routes music/photo/book/audiobook to their scanners). SV-3.2
replaced that with `return new ScanResult()` and *moved the logic nowhere*, so for the base manager
(the one DI-injected into both the worker and the `library:scan` command, via
`container->get(LibraryManager::class)`) **`library:scan --rescan` and every rescan job did nothing
for movie/TV/generic libraries** — no delete, no rescan. It also changed the signature to
`(libraryId, array $paths = [], ?callable $onProgress): ScanResult` and adapted the worker (added a
`getLibrary()`+paths fetch) but never updated the two unit tests → the 2 reds:
- `LibraryScanWorkerTest::testRunOnceProcessesRescanJob` red because the test asserted the old
  2-arg `(libraryId, callable)` contract while the worker now calls 3-arg `(libraryId, paths, sink)`;
  the `with()` mismatch was swallowed into `markFailed()` at `LibraryScanWorker.php:170`.
- `LibraryScanCommandTest::testRescanFlagCallsRescanLibrary` red (exit 1) because the mock cannot
  auto-generate a return value for the **`final` `ScanResult`** and threw
  (`Return value ... cannot be generated: Class "...ScanResult" is declared "final"`), which the
  command's catch turned into `Command::FAILURE`. (Reproduced both in isolation.)

**Fix (logic RESTORED, not removed — per user steer):**
- `src/Media/Library/LibraryManager.php` — rebuilt `rescanLibrary()` to actually work again: DELETE
  the library's items, then `scanLibrary()` (routes every type + streams progress), and return a
  real `ScanResult` (duration + a post-scan item count via new private `countLibraryItems()`).
  Kept the SV-3.2 signature/return so the Audiobook/Book subclass overrides stay compatible; the
  `$paths` arg is documented as base-ignored (base resolves paths from the library row).
- `src/Media/Library/LibraryScanWorker.php` — dropped the now-unnecessary `getLibrary()`+paths
  adaptation; the rescan branch just forwards the progress sink:
  `rescanLibrary($libraryId, [], $this->scanProgressSink($jobId))` (base routes internally).
- Tests updated to the correct current contract (NOT weakened — both still prove the rescan runs to
  completion and is never marked-failed): worker test asserts `(libraryId, array, callable)` +
  `willReturn(new ScanResult())`; command test adds `willReturn(new ScanResult())`.

**Verification (actual output):**
- `phpunit --filter 'LibraryScanWorker|LibraryScanCommand'` → `OK (14 tests, 53 assertions)`.
- `phpunit --filter LibraryManager` → `OK (39 tests, 86 assertions)`.
- `phpunit --testsuite Unit` → `Tests: 4907, Assertions: 38455, Errors: 10, Failures: 3, Skipped: 5,
  Risky: 3`. Failures 5→3 — exactly the 2 SV-2.9 reds cleared; no NEW reds. Remaining are ONLY the
  known buckets: SV-4.13 FfmpegRunner*/Hls* (8 err), SV-2.6 SyncPlayManager (2 err), SV-3.2
  BookProgressStore (1), SV-2.8 ItemRepository::testAddStream* (2).
- `phpstan analyze -c phpstan.neon.dist` → `[OK] No errors` (645 files).
- `phpcs --standard=PSR12` on all touched files → 0 errors (one PRE-EXISTING >120-char warning at
  `LibraryManager.php:170`, an unrelated `getLibrary()` docblock example, outside my diff).

## Fixer — SV-3.2 BookProgressStore + SV-2.6 SyncPlay — 2026-07-12

Cleared the last 3 genuine-red triage entries. Root cause impl-vs-test per red:

- **SV-3.2 BookProgressStore ×1 (IMPL fix).** `book_progress.percent_complete` is `DECIMAL(5,2)`
  (`migrations/075_book_progress.sql:11`). `saveProgress()` bound the raw float `25.5`; the test
  correctly asserted the DECIMAL-string persisted contract (`is_string($params[5])`). Fixed impl to
  bind `number_format($progress->percent_complete, 2, '.', '')` → deterministic `"25.50"` at the
  column scale, locale-safe (explicit '.' separator, unlike `sprintf('%.2f')`/LC_NUMERIC). Round-trip
  preserved: `getProgress()` reads it back with `(float)` (`testGetProgressReturnsProgressWhenFound`
  7.5 → `"7.50"` → 7.5 still `assertSame`). Mirrors the sibling `AudiobookProgressStore` DECIMAL
  handling. Did NOT flip the assertion.
- **SV-2.6 SyncPlayManager ×2 (TEST fix).** `ConnectionInterface::send(): bool`
  (`ConnectionInterface.php:40`); verified the only concrete impl `Connection::send()`
  (`Connection.php:113-124`) always returns bool (`return $result !== false;`) — so the null was a
  pure mock artifact. The two `testBroadcastToGroup*` tests stubbed `send()` with
  `willReturnCallback(... : void)` which returns null → PHPUnit's generated `: bool` mock method
  raised `TypeError`. Fixed the 5 callbacks to `: bool` + `return true;`. No false/backpressure branch
  added: `SyncPlayManager::broadcastToGroup` (`:1227`) does not consume the send() bool (SV-2.6's
  buffer-full skip/pause logic lives in MessageHandler/WebSocketServer, owned by SV-2.6 proper), so a
  false-return test here would assert nothing.

Files: `src/Media/Library/BookProgressStore.php`,
`tests/Unit/Session/SyncPlay/SyncPlayManagerTest.php`.

Verification (actual output):
- `phpunit --filter 'BookProgressStore|SyncPlayManager'` → `OK (46 tests, 125 assertions)`.
- `phpunit --testsuite Unit` → `Tests: 4907, Assertions: 38485, Errors: 8, Failures: 2, Skipped: 5,
  Risky: 2.` The 3 targeted reds cleared; NO new reds. Remaining 10 reds are ONLY the known-drift
  buckets (owned by their own steps, untouched here):
  - SV-2.8: `ItemRepositoryTest::testAddStream{InsertsStream,PersistsTrackMetadataColumns}` (2 fail)
  - SV-4.13: `FfmpegRunnerTest::testBuildTranscodeCommand*` (3) + `FfmpegRunnerHlsTest::testBuildHlsCommand*` (5) = 8 err
- `phpstan analyze -c phpstan.neon.dist` → `[OK] No errors` (645 files).
- `phpcs --standard=PSR12` on both touched files → 0 errors.
