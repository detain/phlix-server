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

## Fixer — SV-2.8 + SV-4.13 known-drift — 2026-07-12

Cleared the last two known-drift RED buckets → full Unit suite is now GREEN (0 err / 0 fail).

**SV-2.8 (`ItemRepositoryTest::testAddStream{InsertsStream,PersistsTrackMetadataColumns}`) — STALE
TEST, impl VERIFIED CORRECT (no impl change).**
- `ItemRepository::addStream()` (`src/Media/Library/ItemRepository.php:1253-1291`) binds **17**
  params, matching the `media_streams` schema exactly: base `001` (id, media_item_id, stream_index,
  stream_type, codec, language, bitrate, width, height) + migration `071` (channels, title,
  is_default) + migration `073`/SV-1.1 (color_space, color_transfer, color_primaries, max_luminance,
  avg_luminance). The luminance binds are `is_numeric`-guarded `(float)` casts (DECIMAL(10,2)
  columns) — correct. The tests still asserted the pre-SV-1.1 `count($params) === 12`; the first 12
  bindings are unchanged, so every existing value assertion ([1]–[11]) was still valid — only the
  count was stale and the 5 color columns were unasserted.
- Fix (TESTS only, no assertions weakened): both tests updated to `count($params) === 17`;
  `testAddStreamInsertsStream` now also asserts params[12]–[16] are NULL when color meta is unset.
  Added `testAddStreamPersistsColorMetadataColumns` to strongly cover the SV-1.1 columns + the
  luminance guard: passes color_space/transfer/primaries + `max_luminance:'1000'` (numeric string →
  `1000.0`) + `avg_luminance:'N/A'` (non-numeric → NULL), asserting the exact persisted values.

**SV-4.13 (`FfmpegRunnerTest::testBuildTranscodeCommand*` ×3 + `FfmpegRunnerHlsTest::testBuildHlsCommand*`
×5) — orphaned tests of REMOVED methods; deleted.**
- CONFIRMED gone from `src/Media/Transcoding/FfmpegRunner.php` with ZERO callers (whole-repo grep,
  vendor excluded): `buildTranscodeCommand`, `buildHlsCommand`, `buildHwaccelCommand`. Only stale
  docref `@see` mentions remain (`TranscodeManager.php:2119`, `FfmpegRunner.php:512/517`) — cosmetic,
  do not break phpstan.
- KEEP items all still present + tested: `buildCmafCommand`/`startCmafTranscode`/
  `startCmafTranscodeWithSubtitles` (`:523/601/626`, covered by `testBuildCmafCommand*` ×5 in
  FfmpegRunnerHlsTest) and `buildGaplessSegmentCommand` (`:2339`, KEEP-but-unbuilt per §8 D2 — was
  untested before, unrelated to removed builders).
- Per-segment REPLACEMENTS retain full coverage (so no real coverage dropped): `buildSegmentCommand`
  + `buildAudioSegmentCommand` → `testBuildSegmentCommand*`/`testBuildAudioSegmentCommand*` in
  FfmpegRunnerHlsTest; `buildHwaccelSegmentCommand` → FfmpegRunnerHwaccelTest (8 refs).
- Deleted the 8 orphaned test methods: `testBuildTranscodeCommand`,
  `testBuildTranscodeCommandIgnoresNonScalarParams`, `testBuildTranscodeCommandHonoursValidWidthHeight`
  (FfmpegRunnerTest); `testBuildHlsCommandCopiesCompatibleStreams`,
  `testBuildHlsCommandEncodesAndScalesWhenRequested`, `testBuildHlsCommandHonorsVariantIndex`,
  `testBuildHlsCommandDefaultsSegmentSecondsWhenInvalid`,
  `testBuildHlsCommandForcesBrowserDecodableH264Profile` (FfmpegRunnerHlsTest). Both files retain
  their other still-valid tests (probe/isAvailable/getTranscodeDir/detached/CMAF/segment) — neither
  file became empty. No test for a still-live method was removed.
- ⚠️ **NOTE (SV-4.13 impl incompleteness, out of my test-scope):** `buildTranscodeCommandWithProfile`
  (`FfmpegRunner.php:1163`) — classified REMOVE in §6 R1 — is STILL PRESENT (zero callers, zero
  tests, whole-repo grep). It does NOT affect the Unit suite or gates and no orphaned test references
  it, so I left the impl untouched (my two commits are test-only per the prescribed messages). Flagging
  for the SV-4.13 impl owner to complete the removal + fix the stale `buildHlsCommand`/`buildHwaccelCommand`
  docrefs.

**Verification (actual output):**
- `phpunit --filter 'ItemRepository|FfmpegRunner'` → `OK (193 tests, 554 assertions)`.
- `phpunit --testsuite Unit` → `OK, but some tests were skipped! Tests: 4900, Assertions: 38479,
  Skipped: 11.` — **0 errors / 0 failures.** All triage buckets cleared; full Unit suite GREEN.
  (4907 → 4900 = removed 8 orphaned tests + added 1 color test; skips are env-dependent swoole/coroutine
  markTestSkipped, not reds.)
- `phpstan analyze -c phpstan.neon.dist` → `[OK] No errors` (645 files).
- `phpcs --standard=PSR12` on the 3 touched test files → 0 errors (only pre-existing >120-char
  warnings, all outside my edit regions).

---

## Implementer — 2026-07-12 — SV-4.7 WS auth enforcement (SECURITY) — audit gap list closed

Closed all 6 gaps from the read-only audit (verdict PARTIAL). Auth is now enforced at the
correct WS-handshake lifecycle stage, privileged events are gated, the SyncPlayAuthMiddleware is
genuinely WIRED (not consolidated/queued), and the jwt_secret is plumbed through the resident path.

**GAP 1 — moved auth from TCP-accept to the WS handshake.** `WebSocketServer::onConnect()` no longer
reads `$_GET['token']` (empty/stale at TCP-accept under Workerman). It now just creates the Connection
wrapper, pools it, sends the `connected` welcome. New `onWebSocketConnect(TcpConnection, Request)` runs
at the handshake stage (bound via `bindConnectionCallbacks()` onto the SAME injected listening worker
SV-0.5 wired — `$this->worker->onWebSocketConnect`), where the upgrade request's query string
(`?token=`) is populated. It reads the token from the parsed `Workerman\Protocols\Http\Request::get('token')`
and delegates validation. Workerman resolves the hook via `$connection->worker->onWebSocketConnect`
(vendor Websocket.php:437), so binding it onto the real listener is load-bearing — same dual-entrypoint
trap as SV-0.5.

**GAP 2 — token-less connections now rejected when a secret is set.** `onWebSocketConnect` rejects
(removes from pool + `close()`) when a JWT secret is configured and the token is missing/invalid/expired.
Anonymous connections are allowed ONLY when no secret is configured (dev). Enforced via the middleware's
`requireAuth=true` when a secret is present.

**GAP 3 — jwt_secret plumbed into the WS server (resident path).** `config/server.php` websocket block
gains `'jwt_secret' => getenv('JWT_SECRET') ?: ''` (SAME source AuthServicesProvider/JwtHandler use, so a
login token validates on the WS too). `start.php` threads `$wsConfig['jwt_secret']` (falls back to the env
directly). `WebSocketServer::__construct` builds a `SyncPlayAuthMiddleware($secret, requireAuth=true)` when
the secret is non-empty, else leaves `$authMiddleware = null` (dev anonymous). `public/index.php` has NO
WS worker (re-confirmed via grep) → no mirror needed. Production always has JWT_SECRET (boot guard refuses
empty/default), so WS auth is enforced there.

**GAP 4 — MessageHandler::handle() now has an auth gate.** Right after extracting `$event`, before the
subscribe_dashboard branch and the generic dispatch, privileged events from an unauthenticated connection
are rejected with a `Messages::TYPE_ERROR` / `error_code=NOT_AUTHENTICATED` (same shape SyncPlayManager
uses) and NOT dispatched. `subscribe_dashboard` (now `WebSocketEvents::SUBSCRIBE_DASHBOARD`) is privileged,
so unauthenticated dashboard now-playing streaming is blocked. Per-event SyncPlay gates in SyncPlayManager
are unchanged (finer-grained; kept).

**GAP 5 — SyncPlayAuthMiddleware WIRED (not deleted/consolidated).** Added
`SyncPlayAuthMiddleware::authenticateConnection(Connection, ?string $token): bool` — the real,
correct-lifecycle auth path. `WebSocketServer::onWebSocketConnect` delegates connect-time JWT validation to
it; on a valid token it hands the derived `sub` straight to `Connection::setAuthenticated(true, $sub)`
(fixing the old `$_GET['syncplay_user_id']` stash that didn't cooperate with sub-derivation). The legacy
`onConnect(TcpConnection)` (TCP-accept `$_GET` path) is KEPT and marked `@deprecated` per §0.1 (no deletion),
pointing to the new method. **Decision: WIRED, not consolidated → no Removal-Confirmation-Queue entry needed.**

**GAP 6 — privileged-vs-public classification in WebSocketEvents.** Added `SYNCPLAY_PREFIX`,
`SUBSCRIBE_DASHBOARD`, private `PUBLIC_EVENTS`/`PRIVILEGED_EVENTS` sets, and `isPublic()` / `isPrivileged()`.
Privileged = every `syncplay_*` type + subscribe_dashboard/dashboard_now_playing + PLAYBACK_* + SESSION_*.
Public = ping/pong/auth_request/connected. Unknown/server-only types are not privileged (no inbound handler
dispatches them → safe no-op). Gap-4's gate consumes `isPrivileged()`.

**Files changed (absolute):**
- `/home/sites/phlix/phlix-server/src/Server/WebSocket/WebSocketServer.php` — remove $_GET auth from
  onConnect; add onWebSocketConnect handshake hook + authMiddleware field/ctor build; bind onWebSocketConnect.
- `/home/sites/phlix/phlix-server/src/Server/WebSocket/SyncPlayAuthMiddleware.php` — new
  `authenticateConnection()`; deprecate legacy onConnect.
- `/home/sites/phlix/phlix-server/src/Server/WebSocket/MessageHandler.php` — auth gate in handle().
- `/home/sites/phlix/phlix-server/src/Server/WebSocket/WebSocketEvents.php` — isPrivileged/isPublic + sets.
- `/home/sites/phlix/phlix-server/config/server.php` — websocket.jwt_secret from JWT_SECRET.
- `/home/sites/phlix/phlix-server/start.php` — thread $wsConfig['jwt_secret']; (also moved the file docblock
  above `declare` to clear a pre-existing phpcs file-header error).
- Tests: `WsAuthenticationTest.php` (drive onWebSocketConnect via a real Request; **flipped** the Gap-2
  test `testMissingTokenAllowsUnauthenticatedConnection` → `testMissingTokenRejectedWhenSecretConfigured`
  + added `testMissingTokenAllowedWhenNoSecretConfigured`); extracted `TestableSyncPlayManager` to its own
  file (`TestableSyncPlayManager.php`) to clear the pre-existing "one class per file" phpcs error;
  `MessageHandlerTest.php` (+4 gate tests); new `WebSocketEventsTest.php` (classification); WebSocketServerTest
  (+onWebSocketConnect binding assertion); `MessageHandlerFrameShapeTest.php` (authenticate the frame-shape
  connections so privileged events pass the gate).

**Verification (actual):**
- `./vendor/bin/phpstan analyze -c phpstan.neon.dist` → `[OK] No errors` (645 files).
- `./vendor/bin/phpunit --testsuite Unit --filter 'WebSocket|WsAuthentication|MessageHandler|SyncPlay'`
  → `OK (183 tests, 536 assertions)`.
- `./vendor/bin/phpcs --standard=PSR12` on all touched src + config + start.php → **0 errors**; touched
  test files → 0 errors (5 pre-existing >120-char WARNINGS in WsAuthenticationTest, non-blocking §0.2).
- Pre-existing note: `tests/E2E/Session/SyncPlay/SyncPlayE2ETest` is RED on clean master (10 errors:
  mock `ConnectionInterface::send()` returns null — unrelated to this step; E2E suite not maintained green
  per the re-baseline). My change did not add E2E failures (9 after, ≤10 before).

**OWED on-box verification (start.php outside CI, like SV-0.5):** deploy, then on :8097 confirm (a) a WS
handshake WITHOUT `?token=` is rejected when JWT_SECRET is set; (b) a valid-token handshake authenticates
and can drive SyncPlay; (c) an unauthenticated client's `subscribe_dashboard`/SyncPlay control message gets
a NOT_AUTHENTICATED error and is not dispatched.

NO FINDINGS (pending review)

## Reviewer (per-step) — SV-4.7 — 2026-07-12

Reviewed commit `fecd0ab5` (parent `62e7e1d5`) adversarially against the SV-4.7
Acceptance Criteria + S-F34 + §0 ground rules. Traced every enumerated review focus.

Verified:
- **SV-0.5 landmine cleared.** `onWebSocketConnect` is bound in `bindConnectionCallbacks()`
  onto `$this->worker`, which in the resident path is the INJECTED listening `$wsWorker`
  (`start.php:288` passes `$w`; ctor takes the `$worker !== null` branch, no re-create).
  Workerman resolves the hook via `$connection->worker->onWebSocketConnect`, so auth runs on
  the real :8097 listener. `WebSocketServerTest` now asserts this binding explicitly.
- **No bypass.** Single inbound dispatch path (`onMessage` → `MessageHandler::handle`), gate at
  the very top of `handle()` before both the `subscribe_dashboard` branch and the generic
  `callbacks[$event]` dispatch. All SyncPlay handlers register `syncplay_*` types (verified in
  `SyncPlayManager::initialize` lines 200-213) → all caught by the `SYNCPLAY_PREFIX` privileged
  check. No `auth_request` handler exists → no post-handshake alt-auth path; `setAuthenticated`
  is called ONLY from `authenticateConnection` (handshake). Legacy `SyncPlayAuthMiddleware::onConnect`
  is `@deprecated` and wired NOWHERE (grep confirms). `SyncPlayWorker` (port 8098) is not
  instantiated in any prod boot path (start.php/index.php/bin) — and even if run it fails CLOSED
  (never authenticates → gate blocks privileged events), no bypass.
- **Defense-in-depth intact.** `SyncPlayManager::handle*` still gate on `$connection->getUserId()===null`
  → NOT_AUTHENTICATED. `authenticateConnection` hands the validated `sub` to
  `Connection::setAuthenticated(true,$sub)`; `getUserId()` returns it. No `$_GET['syncplay_user_id']`
  stash remains.
- **Classification complete.** `isPrivileged` = `syncplay_*` prefix ∪ {subscribe_dashboard,
  dashboard_now_playing, PLAYBACK_*, SESSION_*}; PUBLIC = {ping,pong,auth_request,connected}.
  All 19 `Messages::TYPE_*` wire constants are `syncplay_`-prefixed → fully covered. Unknown types
  are non-privileged but have no inbound handler (safe no-op). Liveness ping/pong stay public.
- **jwt_secret plumbing consistent.** WS reads `getenv('JWT_SECRET')` (config/server.php +
  start.php fallback) — same source as `AuthServicesProvider`/`JwtHandler`; middleware builds
  `new JwtHandler($secret)` identically, so a login token validates on the WS. `assertSecretConfigured()`
  (start.php:54) refuses to boot without a real JWT_SECRET, so `authMiddleware` is always non-null
  in prod (the dev-anonymous branch is unreachable on a booted prod server). `public/index.php`
  has no WS worker (confirmed) → no FPM mirror needed.
- **Flipped test genuine.** `testMissingTokenRejectedWhenSecretConfigured` asserts close + empty
  pool (opposite of the old `testMissingTokenAllowsUnauthenticatedConnection`); `onWebSocketConnect`
  didn't exist pre-fix, so the semantic flip is real. `testMissingTokenAllowedWhenNoSecretConfigured`
  covers the dev branch.
- **No TOCTOU.** WS protocol delivers no app messages before handshake; `onWebSocketConnect`
  (auth) runs during the handshake, before any `onMessage`, in the single event loop. Rejection
  removes from pool + `close()`. The null-`findConnection` early-return fails closed (`onMessage`
  also requires the wrapper in-pool).

Gates re-run: phpstan L9 `[OK] No errors` (645 files); `phpunit --testsuite Unit --filter
'WebSocket|WsAuthentication|MessageHandler|SyncPlay'` → OK (183 tests, 536 assertions); phpcs
PSR12 on all touched src/config/start.php → clean. Confirmed `SyncPlayE2ETest` red (9 errors,
mock `send()` returns null) is PRE-EXISTING on clean master and unrelated to this step.

NO FINDINGS

---

## Implementer — SV-3.1 FOUNDATION (a + b0 + e) — 2026-07-12

Owns the "wire the DVR into production boot" concern. Sub-steps SV-3.1c (scheduler
Timers), SV-3.1d (comskip/media_items registration), SV-3.1f (timeshift) remain for
follow-up sub-steps and are intentionally NOT touched here.

### SV-3.1a — kill the fake getmypid() recording (Recorder.php)
`startRecording()` no-tuner branch (was `Recorder.php:507-541`): previously, when no
tuner stream URL resolved, it marked the row `recording` with `pid=getmypid()` — a
phantom capture with no ffmpeg behind it (violated §0.1). Now it logs a warning, calls
`updateRecordingStatus(FAILED, 'No tuner available')` and returns false: pid stays NULL,
no `activeRecordings` entry, no fake process. The spawn-success path is unchanged.
Consequence in recovery: a due `scheduled` row with no wired tuner is counted
`scheduled_skipped` (not `rearmed`) and left `failed`.

### SV-3.1b0 — DI factory: fully-wired Recorder + LiveTvManager + RecordingScheduler
- NEW `src/Common/Container/Providers/LiveTvServicesProvider.php` — registered in
  `ContainerFactory::defaultProviders()` (index 9, before WebPortal) so BOTH entrypoints
  (`public/index.php` CGI + `start.php` daemon) resolve the same stack. Every binding is a
  PHP-DI singleton = one instance per worker. Defines:
  - `ChannelManager`, `GuideManager` (DB + livetv log channel).
  - `TunerDriverInterface` → HDHomeRun primary driver (the default-enabled driver
    LiveTvManager treats as primary), built from `livetv.hdhomerun`.
  - `ComskipLifecycleManager` → built from `livetv.comskip` (ComskipRunner + EdlParser +
    Integration); passed to the Recorder ctor which registers its enqueue as an onComplete
    hook. (No media_items INSERT / RecordingHooks — that is SV-3.1d.)
  - `Recorder` → FULLY wired ($db, storage_path, max_storage_bytes, livetv logger, comskip
    lifecycle manager, ffmpeg path). Built with `liveTvManager=null` to break the
    Recorder↔LiveTvManager cycle.
  - `LiveTvManager` → resolves the shared Recorder singleton, constructs itself, then calls
    `$recorder->setLiveTvManager($this)` — closing the cycle so `resolveTunerStreamUrl()` is
    reachable. Every real usage path (getLiveTvStreamController, RecordingScheduler, boot
    recovery, AdminLiveTvController) resolves LiveTvManager, so the link is always established.
  - `RecordingScheduler` → resolves LiveTvManager first (links the recorder), then the shared
    Recorder + manager. (Object only; its Timers are SV-3.1c.)
- `config/server.php` now `require`s `config/livetv.php` as `$config['livetv']` (mirrors the
  ffmpeg/hub/relay pattern) → reaches BOTH entrypoints via `app.config['livetv']`.
- `Application::getLiveTvStreamController()` now resolves `LiveTvManager` from the container and
  uses `$liveTvManager->getRecorder()` — the ONE wired Recorder — instead of
  `new Recorder($db, $storagePath)` (which had no ffmpeg/comskip/manager). Existing recording-
  stream route (`Application.php:1462-1464` → LiveTvStreamController :63-89) unchanged.

### SV-3.1e — boot recovery in start.php (daemon only)
`start.php` HTTP worker `onWorkerStart`, gated on `$w->id === 0` so it runs EXACTLY ONCE per
boot (not 14×): resolve `LiveTvManager` (links the recorder) and call `bootstrap()` →
`Recorder::resumeActiveRecordings()` (re-attach live children, fail orphans, re-arm due). Wrapped
in try/catch so a DVR-recovery failure never stops the worker serving. Runs OUTSIDE any coroutine
(mirrors the SV-0.1 hwaccel probe precedent); `resumeActiveRecordings()`'s process checks +
detached spawns are ordinary blocking calls valid at boot — no coroutine-only work to guard. NOT
wired in `public/index.php` (single-shot CGI must not spawn recovery/timers per §0.3).

### Tests
- `tests/Unit/LiveTv/RecorderRecoveryTest.php`: repurposed the old
  `testScheduledRecordingWithPastStartTimeIsRearmed` (asserted the fake-PID re-arm) →
  `testScheduledDueRecordingWithNoTunerIsSkippedNotRearmed` (rearmed=0, scheduled_skipped=1);
  added `testStartRecordingWithNoTunerMarksFailedWithNoFakePid` (SV-3.1a: returns false, 0 active
  recordings, exactly one UPDATE to status=failed, never status=recording, never a getmypid() PID).
- NEW `tests/Unit/Common/Container/Providers/LiveTvServicesProviderTest.php`:
  `testContainerYieldsFullyWiredRecorder` (reflection — liveTvManager linked, ffmpegPath from
  config, storage/maxBytes from dvr config, comskip onComplete hook present) +
  `testStackIsSharedSingletons` (Recorder/LiveTvManager/RecordingScheduler share one instance).
- `tests/Unit/Common/Container/ContainerFactoryTest.php`: provider count 13→14, LiveTv asserted at
  index 9, later providers reindexed.

### Verification (actual)
- `phpstan analyze -c phpstan.neon.dist` → **[OK] No errors** (646 files).
- `phpunit --testsuite Unit --no-coverage` → **4923 tests, 0 failures** (4 warnings / 5 skipped,
  pre-existing). `--filter 'Recorder|LiveTv|RecordingScheduler|LiveTvServicesProvider'` → 283 OK.
- `phpcs --standard=PSR12` on touched src (Recorder, LiveTvServicesProvider, ContainerFactory,
  Application) → **0 errors** (6 pre-existing >120-char warnings on untouched lines).
- `php -l start.php` + `php -l public/index.php` → no syntax errors.

### On-box verification OWED (start.php is outside CI)
Deploy, restart phlix-server with active/scheduled recordings, confirm: (1) recovery re-attaches
live ffmpeg children and marks orphans failed; (2) the boot log line "DVR boot recovery complete"
fires exactly once (worker id 0); (3) a schedule with no available tuner is marked failed (no
phantom getmypid recording).

### Follow-up sub-steps (NOT done here)
SV-3.1c scheduler Timers · SV-3.1d comskip EDL→chapters + media_items registration · SV-3.1f
timeshift stream. LiveTvStreamController timeshift is still a 501 stub (SV-3.1f).

## Reviewer (SV-3.1 foundation: a + b0 + e) — 2026-07-12

Reviewed commit `39899c70` (diff `fecd0ab5..39899c70`) — the DVR foundation only (a/b0/e).
Verified locally: **phpstan L9 `-c phpstan.neon.dist` = [OK] 0 errors** (646/646);
`phpunit LiveTvServicesProviderTest + ContainerFactoryTest + RecorderRecoveryTest` = **20/20 OK
(80 assertions)**; **phpcs PSR-12 = 0 errors** on the changed files (only pre-existing >120-char
warnings on untouched lines). Reviewed against foundation scope; c/d/f/g/h are plan-deferred and
their absence is not a finding.

Confirmed CORRECT (high confidence):
- **b0 DI coherence:** every ctor the provider calls matches the real signature — `Recorder($db,
  storagePath, maxStorageBytes, logger, ComskipLifecycleManager, ffmpegPath, null)`, `LiveTvManager($db,
  ChannelManager, GuideManager, Recorder, TunerDriverInterface, logger)`, `RecordingScheduler($db,
  Recorder, LiveTvManager, logger)`, plus ComskipLifecycleManager/ComskipIntegration/ComskipRunner/
  ComskipEdlParser/HdHomeRun{Discovery,ApiClient,TunerDriver}/ChannelManager/GuideManager. The
  Recorder↔LiveTvManager cycle is broken correctly: Recorder built with `null` manager, LiveTvManager
  factory calls `$recorder->setLiveTvManager($manager)` on the SAME PHP-DI singleton, and
  RecordingScheduler resolves LiveTvManager first so the back-ref is set before it receives the recorder.
  `logger.livetv` (CoreServicesProvider channel alias → StructuredLogger), `Connection::class` and
  `app.config` all resolve. Config keys the provider reads (`hdhomerun.ssdp_timeout_secs`,
  `comskip.{binary_path,queue_processing,max_concurrent}`, `dvr.{storage_path,max_storage_bytes}`,
  top-level `storage_path`, `dvbt.ffmpeg_path`) all exist in `config/livetv.php`.
- **b0 wired-Recorder usage:** `Application::getLiveTvStreamController()` now resolves
  `LiveTvManager` (which links the shared Recorder) and passes `$liveTvManager->getRecorder()` — the one
  wired singleton — to the stream controller. The only `new Recorder(`/`new LiveTvManager(` in src/ are
  in the provider; no second unwired instance remains. The removed `createDatabaseConnection()` call has
  seven other live callers (not orphaned).
- **a getmypid() removal:** the no-tuner branch marks the row FAILED ('No tuner available'), returns
  false, adds NO `activeRecordings` entry, and never spawns ffmpeg; `pid` stays NULL (the row was
  `scheduled`, pid never set; `updateRecordingStatus` does not touch pid). No orphaned/partial state.
  Guarded by `testStartRecordingWithNoTunerMarksFailedWithNoFakePid` (asserts no getmypid PID is bound)
  and `testScheduledDueRecordingWithNoTunerIsSkippedNotRearmed`.
- **DUAL-ENTRYPOINT (§0.3):** `LiveTvServicesProvider` is registered in `ContainerFactory` (13→14),
  which BOTH `public/index.php:72` and `start.php:154` call via `ContainerFactory::create($config)`;
  `config/server.php` now `require`s `config/livetv.php`, and both entrypoints load `config/server.php`.
  The `getLiveTvStreamController()` change lives in `Application`, which only the daemon builds (the CGI
  path dispatches via `Router`+`WebPortalRouter` and never constructs `Application` nor its LiveTv route),
  so no CGI mirror is required and no CGI behavior changed. `bootstrap()` recovery is intentionally
  start.php-only. The `$this->container === null` throw in `getLiveTvStreamController()` is unreachable
  in practice (Application's ctor requires a non-null container).
- **e worker-0 gating:** the recovery block sits inside `$httpWorker->onWorkerStart` (count=14) gated on
  `(int) $w->id === 0`, so it fires exactly once per boot — consistent with the repo's existing
  `(int) $w->id` usage in the same closure. `$container`, `LoggerFactory`, `LogChannels::LIVETV` are all
  in scope/imported. `bootstrap()` resolves LiveTvManager (linking the Recorder) BEFORE calling
  `resumeActiveRecordings()`, so tuner resolution works during re-arm. Wrapped in try/catch so a recovery
  failure never stops the HTTP worker.
- **Swoole/coroutine (§0.3):** recovery runs at boot in `onWorkerStart`, OUTSIDE any coroutine, so the
  blocking process checks / detached spawns reachable via `resumeActiveRecordings()→startRecording()` are
  valid there (same as the adjacent hwaccel probe); the curated hook mask only affects in-coroutine work.
  All DB access is via `Workerman\MySQL\Connection` (`Connection::class`), no raw PDO. (The
  scheduler/timed-stop Timers that WOULD need `getCid()>0`/`Coroutine\System::exec` guards are SV-3.1c,
  deferred — not in this commit.)
- **Security:** storage_path is a trusted `config/livetv.php` value; `getRecordingPath()` composes it
  with an internal UUID `recordingId` (no user input), so no path-traversal vector in the foundation; the
  DI/recovery wiring opens no unauth path (timeshift endpoint is still the 501 stub, SV-3.1f).
- **No SV-4.7 regression:** the commit touches only config/server.php, ContainerFactory, Recorder,
  Application::getLiveTvStreamController, and the HTTP-worker `onWorkerStart` in start.php — it does not
  touch the :8097 WS worker, its handshake auth, or any WS wiring landed by `fecd0ab5`.

Benign observations (NOT findings — no action required): (1) in-memory `activeRecordings` is populated
by recovery only on HTTP worker 0, so other workers' Recorder singletons start empty — inherent to the
per-worker-singleton design and harmless for the foundation (DB is source of truth; the stream
controller only does path lookups; cross-worker state is an SV-3.1f/timeshift concern). (2)
`Application::getLiveTvStreamController()` recomputes `storagePath` from config inline with the same
precedence the provider's `dvrStorage()` uses, so the two cannot diverge given identical config keys.

**Verdict: NO FINDINGS.**

## Implementer — SV-3.1c (scheduler Timer + timed-stop + padding fix) — 2026-07-12

Sub-step **c** on top of the foundation (`39899c70`). Wires the production scheduler
Timer, the per-recording timed stop, the safety-net scan, and fixes the padding bug.
NOT marked done — Review/Test pending. **Git NOT run here** (Phase Coordinator owns the
git cycle); working tree carries the change for the coordinator to commit/push in two
commits (impl + tests, messages below).

### The padding bug + the fix (authoritative in Recorder)
`scheduleRecording()` persists the RAW programme `end_time` (guide boundary) + a separate
`post_padding_seconds` column — it does NOT fold padding into `end_time`. The old
`endRecording()` docblock/comment FALSELY claimed "end_time already = end_time + post_padding"
and just stopped immediately. Fix: padding is now applied at STOP time, authoritatively in the
Recorder, and NEVER folded into the stored `end_time` (so schedules keep true boundaries and
padding can't double-count). One formula, three consumers, all in `Recorder`:
- `Recorder::effectiveEndTime(int $endTime, int $postPadding): int` — the single formula
  (`end_time + max(0,padding)`); used by the scheduler to compute the one-shot timer delay.
- `Recorder::getRecordingsDueToStop(?int $now=null): array<string>` — the scan, expressing the
  formula in SQL: `status='recording' AND (end_time + COALESCE(post_padding_seconds,60)) <= now`.
- `Recorder::endRecording()` — rewritten as the AUTHORITATIVE timed-stop entry. Live-in-memory →
  `stopRecording()` (kills ffmpeg, status→completed). Orphan safety net (row still `recording`
  but no in-memory handle on this worker, e.g. timer lost across restart) → kill any live stored
  pid + UPDATE status=completed + fire onComplete, so the scan can't loop forever. Corrected the
  misleading comment.

### Scheduler (RecordingScheduler) — start + timed-stop
- `processDueRecordings()` (starts) now arms a **per-recording one-shot** `Workerman\Timer`
  after a successful `startRecording()`, at `effectiveEndTime(end_time, post_padding) - now`
  (min 1s), keyed in `stopTimerIds` (guarded `class_exists(\Workerman\Timer::class)`, `[], false`
  one-shot; idempotent re-arm; `Timer::del` on cancel). The timer callback (`fireStopTimer`)
  wraps `endRecording()` in try/catch.
- `processCompletedRecordings()` (NEW safety-net scan) — iterates
  `recorder->getRecordingsDueToStop()`, calls the authoritative `endRecording()` per id, cancels
  any lingering timer; each stop in its own try/catch so one failure can't abort the scan.
- `tick()` (NEW) — runs BOTH passes; this is what the production Timer calls.
- `activeStopTimerCount()` (NEW, test observability).

### Production Timer wiring (start.php, daemon-only, worker 0)
Added a periodic `\Workerman\Timer::add($schedulerInterval, fn → $scheduler->tick())` inside the
HTTP `onWorkerStart`, gated `(int)$w->id === 0` (same gate as the SV-3.1e boot recovery) so it
runs on ONE worker not 14. Cadence from `config/livetv.php` `dvr.scheduler_interval_seconds`
(default **30s**), sanitized. Wiring + tick body both wrapped in try/catch (a scan error can never
kill the worker) and logs "DVR scheduler timer armed". NOT mirrored in `public/index.php` (CGI
runs no timers — dual-entrypoint §0.3 satisfied; no ctor/DI signature changed, DI via the existing
`LiveTvServicesProvider` singletons).

### Coroutine/Swoole correctness (§0.3)
The Workerman Swoole event adapter wraps EVERY timer callback in `Coroutine::create()`
(`Events/Swoole.php::safeCall`, verified), so `getCid()>0` holds inside both the periodic tick and
the one-shot stop callbacks → the hooked-PDO DB work in `tick()`/`endRecording()` runs in a valid
coroutine context. No NEW subprocess spawn/kill is introduced by this sub-step (the stop reuses the
foundation's `terminateRecording()` which uses `posix_kill` — a syscall, not exec — with a
`shell_exec` fallback only when posix is absent; `SWOOLE_HOOK_NATIVE_CURL`/exec mask untouched). All
new callbacks additionally have explicit try/catch on top of `safeCall`'s.

### Files changed
- `src/LiveTv/Recorder.php` — `endRecording()` rewrite (authoritative padding + orphan reconcile);
  NEW `effectiveEndTime()`, `getRecordingsDueToStop()`.
- `src/LiveTv/Recording/RecordingScheduler.php` — `stopTimerIds` state; `processDueRecordings()`
  arms one-shot stop timer; NEW `tick()`, `processCompletedRecordings()`, `activeStopTimerCount()`,
  `scheduleStopTimer()`, `fireStopTimer()`, `cancelStopTimer()`.
- `start.php` — worker-0 periodic scheduler Timer wiring (after the boot-recovery block).
- `config/livetv.php` — NEW `dvr.scheduler_interval_seconds` (default 30).

### Tests (added/extended)
- NEW `tests/Unit/LiveTv/RecorderTimedStopTest.php` (5): `effectiveEndTime` applies/clamps padding;
  `getRecordingsDueToStop` scan SQL asserts `end_time + COALESCE(post_padding_seconds, 60)` +
  `status='recording'` + `now` bind (**the padding-applied assertion**); `endRecording` false when
  not found; `endRecording` reconciles an overdue `recording` row → exactly one UPDATE to COMPLETED
  + onComplete fires.
- EXTENDED `tests/Unit/LiveTv/Recording/RecordingSchedulerTest.php` (+4, +`new Worker()` in setUp +
  `fakeResult`): starting a due recording arms exactly one stop timer via
  `effectiveEndTime(end_time, post_padding)` (mock `expects(...)->with($endTime, 90)` — **padding
  applied in the timer path**); scan ends all due recordings; scan isolates a failure (1 ended,
  1 error); `tick()` runs both passes.

### Verification (actual)
- `./vendor/bin/phpstan analyse -c phpstan.neon.dist` → **[OK] No errors** (646 files).
- `./vendor/bin/phpunit --testsuite Unit --no-coverage` → **Tests: 4931, 0 failures, 0 errors**
  (4 warnings / 8 skipped, pre-existing). `--filter 'RecorderTimedStop|RecordingScheduler'` → 14 OK;
  `--filter 'Recorder|RecordingScheduler|LiveTvServicesProvider'` → 43 OK.
- `./vendor/bin/phpcs --standard=PSR12` on `src/LiveTv/Recorder.php` + `RecordingScheduler.php` →
  **0 errors** (2 pre-existing >120-char warnings on untouched lines 191/1538 of Recorder). `phpcs`
  on `start.php` + both test files → clean.

### Intended commits (for the Coordinator)
1. `transcode/livetv: SV-3.1c scheduler timer + timed-stop at end_time+post_padding + padding fix`
   → Recorder.php, RecordingScheduler.php, start.php, config/livetv.php
2. `SV-3.1c tests: timed-stop padding scan + per-recording stop timer + endRecording reconcile`
   → RecorderTimedStopTest.php, RecordingSchedulerTest.php

### On-box verification OWED (start.php outside CI)
Deploy + restart with a `scheduled` (due) and an active `recording` row and confirm: (1)
"DVR scheduler timer armed" logs once (worker 0); (2) a due schedule starts within one tick and
a per-recording stop timer is armed; (3) an in-progress recording stops at `end_time + post_padding`
(timer path) and, after a mid-recording restart, the scan stops it within ≤ scheduler_interval.

### Seams left for later sub-steps (NOT done here)
- **d** — register the completed `.ts` as a `media_items` row + wire comskip EDL→chapters to the
  real `media_item_id` (RecordingHooks still has no caller; onComplete fires but no INSERT).
- **f** — timeshift endpoint (still the 501 stub at `LiveTvStreamController.php:~129`).
- **g** — storage accounting on real files. **h** — `LiveTvStreamController` tests.

## Reviewer (per-step) — SV-3.1c — 2026-07-12

Reviewed `git diff 1cc85d3f..89f3f35f` (impl `50d3e992` + tests `89f3f35f`): Recorder padding
authority + `getRecordingsDueToStop`/`endRecording` rewrite, RecordingScheduler `tick()`/
`processCompletedRecordings()` + per-recording one-shot stop timers, worker-0 periodic Timer in
start.php, `dvr.scheduler_interval_seconds`. Gates run here: phpstan L9 `-c phpstan.neon.dist` on
Recorder.php + RecordingScheduler.php = **[OK] 0 errors**; phpunit `--filter
'RecorderTimedStop|RecordingScheduler'` = **OK (14 tests, 37 assertions)**.

**Check #1 (padding-default consistency) — PASS (no divergence).** All paths default post_padding to
**60** when NULL/absent: scan SQL `COALESCE(post_padding_seconds, 60)` (Recorder.php:907); timer path
`RowAccess::int($row,'post_padding_seconds',60)` (RecordingScheduler.php:269); `endRecording` reads
`mapRecording()` which coerces via `RowAccess::int(...,60)` (Recorder.php:1587) so its `is_int(...)?:60`
branch always sees an int (and is used only for logging, not gating); config
`default_post_padding_seconds`=60 (livetv.php:362); column `INT NOT NULL DEFAULT 60` (mig 012a:42,
013:13/28). Timer path and scan path compute the same effective stop for the NULL/default row. Not a
finding.

**Checks #5, #6, #7, #8, #9 — PASS.** #5 worker-0 gate `(int)$w->id===0` at start.php:232 mirrors the
SV-3.1e recovery gate at :200; Timer is repeating (2-arg `Timer::add`, persistent default). #6 verified
Workerman `Events\Swoole::add()` routes one-shot (`Timer::after`) AND repeating (`Timer::tick`)
callbacks through `safeCall`→`Coroutine::create` (vendor Swoole.php:69/105/286) → `getCid()>0`, so
`terminateRecording` uses `Coroutine::sleep` (non-blocking) and hooked PDO can yield; stop reuses
`posix_kill` (no hooked exec; `shell_exec` fallback is pre-existing and unreached when posix_kill
present); no exit/die/blocking-sleep introduced. #7 no ctor/DI signature changed (only a private field +
methods added; resolved via existing singleton) → no index.php mirror required; timer daemon-only. #8
nothing stubbed/deleted; d/f/g/h left as seams. #9 padding assertions genuine on BOTH paths
(timer: `effectiveEndTime($endTime,90)`; scan: `end_time + COALESCE(post_padding_seconds, 60)` SQL) and
the reconcile test asserts exactly one onComplete + one COMPLETED UPDATE — all would fail pre-fix
(orphan returned false / no timer armed).

### Findings (3), most-severe first

1. **[MEDIUM] Double-completion race — the safety-net scan does not exclude in-flight recordings and
   there is no atomic status compare-and-swap.** `Recorder::getRecordingsDueToStop`
   (src/LiveTv/Recorder.php:900-918) selects rows purely by DB `status='recording' AND effectiveEnd<=now`,
   with no exclusion of recordings still live in `$this->activeRecordings` on this worker or holding a
   pending one-shot stop timer. Neither `stopRecording` (Recorder.php:733-775) nor `endRecording`'s
   reconcile UPDATE (Recorder.php:~838) guards the completion with a conditional
   `WHERE ... status='recording'` + affected-rows check. Because `terminateRecording`
   (Recorder.php:1055-1068) parks in a `Coroutine::sleep(0.1)` loop for up to ~5s (SIGTERM grace) BEFORE
   `stopRecording` unsets `activeRecordings` (:753) and writes the completion UPDATE (:758), the row stays
   `status='recording'` + in `activeRecordings` for that whole window. Under the Swoole runtime the
   one-shot stop-timer coroutine and each 30s scan-tick coroutine interleave at those yields.
   *Failure scenario:* a recording whose effectiveEnd falls within ~5s of a scan tick is processed by
   BOTH the one-shot timer and the concurrent scan (or two scan re-entries) → `fireOnCompleteCallbacks`
   fires twice and the completion UPDATE runs twice. Harm today is bounded (onComplete is a no-op until
   SV-3.1d, double UPDATE is near-idempotent), but once SV-3.1d wires comskip / media_item registration
   into onComplete this becomes a double comskip / double media-item insert / double storage accounting.
   *Fix direction:* make the completion UPDATE a compare-and-swap (`UPDATE ... SET status='completed' ...
   WHERE recording_id=? AND status='recording'`) and only `fireOnCompleteCallbacks` when affected-rows==1;
   and/or have the scan skip recording_ids currently present in `activeRecordings`.

2. **[LOW-MEDIUM] One-shot stop timer is not cancelled on the manual-stop path (check #3 gap).**
   `RecordingScheduler` cancels the per-recording timer on normal fire (`fireStopTimer` self-clears) and
   on the scan (`processCompletedRecordings`→`cancelStopTimer`), but the Recorder manual paths
   `stopRecording`/`cancelRecording` (Recorder.php:1185)/`deleteRecording` (Recorder.php:1209) have NO
   coupling to `RecordingScheduler::cancelStopTimer`. A recording stopped/cancelled/deleted before its
   effective end therefore leaves its one-shot timer armed for the full remaining duration, so
   `activeStopTimerCount()` does not return to baseline on manual stop (violates the check-#3 requirement).
   The late fire is itself harmless (`endRecording` status-guards the reconcile → returns false, no double
   onComplete), and this path is untested. *Fix direction:* register a Recorder `onComplete` callback (or
   an explicit stop hook) from the scheduler that calls `cancelStopTimer`, or route manual stops through
   the scheduler; add a test asserting the count returns to baseline after a manual stop.

3. **[LOW] Scan SQL does not clamp negative padding, diverging from `effectiveEndTime`'s `max(0,...)`.**
   `getRecordingsDueToStop` computes `end_time + COALESCE(post_padding_seconds, 60)` (Recorder.php:907)
   with no `GREATEST(0, …)`, whereas the timer path clamps via `effectiveEndTime` (`end_time + max(0,
   padding)`). For a NEGATIVE `post_padding_seconds` (not clamped at write time in `scheduleRecording`/
   `SeriesRuleManager`) the scan would fire earlier than the timer. Not reachable with the default (60) or
   any non-negative value, so exposure is minimal. *Fix direction:* `end_time + GREATEST(0,
   COALESCE(post_padding_seconds, 60))` to mirror `effectiveEndTime`, or validate non-negative padding at
   write time.

**Verdict: 3 FINDINGS** (1 MEDIUM, 1 LOW-MEDIUM, 1 LOW). Padding-default consistency (check #1) PASSED —
not a finding. Loop to the Fixer for findings 1 & 2 (finding 3 is a low-risk hardening nit).

## Fixer (per-step) — SV-3.1c — 2026-07-12

Fixed ALL THREE review findings. Committed + pushed myself (§F). phpstan L9 0, Unit 4935/0, phpcs on
touched files clean (2 pre-existing >120-char warnings on untouched Recorder lines 204/1654 only).

### Finding 1 — Double-completion race [MEDIUM] — belt AND suspenders
**(a) Atomic completion CAS (the authoritative guard).** Both completion writes are now conditional +
affected-rows-gated, using the SAME idiom as `ScanJobRepository::claimNext()` (the Workerman MySQL
client returns the affected-row count for an UPDATE; verified via `PhlixMySQLConnection::query()` →
`parent::query()`):
- `Recorder::stopRecording()` (`src/LiveTv/Recorder.php`) — `UPDATE livetv_recordings SET status=... 
  WHERE recording_id=? AND status=?` (bind prior status = `recording`); `fireOnCompleteCallbacks()`
  runs ONLY when `is_int($affected) && $affected >= 1`. On a lost race it logs debug + returns false
  (the process was still stopped; only the duplicate onComplete side effect is suppressed).
- `Recorder::endRecording()` reconcile branch — same conditional CAS + affected-rows gate before
  `fireOnCompleteCallbacks()`.
This makes concurrent completers (one-shot timer + scan tick interleaving at `terminateRecording`'s
`Coroutine::sleep` yields) idempotent regardless of timing → exactly one onComplete + one status→
completed UPDATE. Matters once SV-3.1d wires comskip / media-item registration into onComplete.
**(b) Scan exclusion (defense-in-depth).** `RecordingScheduler::processCompletedRecordings()` now
`continue`s past any `recording_id` still present in `$this->stopTimerIds` (a live one-shot timer =
the primary stop path). Chose the SCHEDULER's timer-set as the exclusion key rather than the Recorder's
`activeRecordings` — **deliberately did NOT filter getRecordingsDueToStop() by activeRecordings**,
because boot recovery (`resumeActiveRecordings`) re-attaches pid-alive recordings to `activeRecordings`
WITHOUT re-arming a stop timer, so the scan is their ONLY stop path; excluding activeRecordings would
strand them past their effective end. Also moved `RecordingScheduler::fireStopTimer()`'s
`unset($stopTimerIds[$id])` into a `finally` AFTER `endRecording()` returns, so the id stays in the set
throughout the (multi-second) ffmpeg teardown and a concurrent scan tick during that fire window still
skips it. The CAS remains the ultimate guard for any residual window.

### Finding 2 — Stop-timer not cancelled on manual stop [LOW-MED] — callback hook, no ctor cycle
Added an idempotent stop-hook mechanism to the Recorder: `onStop(callable)` +
`private array $onStopCallbacks` + `fireOnStopCallbacks($recordingId)` (mirrors the existing
onComplete plumbing but fires on ANY terminal transition, not just successful completion). Fired from
all three manual paths — `stopRecording()` (always, before the CAS branch), `cancelRecording()` and
`deleteRecording()` (via a `$wasActive` guard so it fires exactly once whether or not the row was live
in memory). `RecordingScheduler.__construct()` registers the hook: `$this->recorder->onStop(fn($id) =>
$this->cancelStopTimer($id))`. **No ctor cycle / no DI-signature change** — the Recorder is built first
and injected into the scheduler (existing `LiveTvServicesProvider` order); the hook is a runtime
callback registration, not a constructor dependency. `cancelStopTimer` stays private (the closure binds
`$this`). Therefore **no `public/index.php` / `start.php` mirroring needed** (neither entrypoint
constructs the scheduler directly — DI does; §0.3 satisfied). `activeStopTimerCount()` now returns to
baseline after a manual stop.

### Finding 3 — Negative-padding clamp [LOW]
`Recorder::getRecordingsDueToStop()` scan SQL → `end_time + GREATEST(0, COALESCE(post_padding_seconds,
60))`, mirroring `effectiveEndTime()`'s `max(0, padding)` so a mis-stored negative padding cannot make
the scan fire earlier than the timer path.

### Tests (built out around each fix)
- `RecorderTimedStopTest::testCompletionIsIdempotentUnderTimerVsScanRace` (finding 1) — two
  `endRecording()` calls on the same still-`recording` orphan; DB mock returns affected-rows **1 then
  0**; asserts onComplete fires EXACTLY once, first returns true / second false, both attempted the
  conditional UPDATE. No sleeps/timers — deterministic.
- `RecorderTimedStopTest::testEndRecordingReconcilesOverdueRecordingToCompleted` (updated) — mock UPDATE
  now returns 1; added assertions that the completion UPDATE is a CAS (`status = ?` in SQL + prior
  `recording` status bound).
- `RecorderTimedStopTest::testOnStopFiresOnManualStopPaths` (finding 2) — real Recorder; asserts onStop
  fires on live `stopRecording` (activeRecordings seeded via reflection, pid=0 → no real kill),
  `cancelRecording`, and `deleteRecording`.
- `RecordingSchedulerTest::testScanSkipsRecordingsWithAnArmedStopTimer` (finding 1b) — arms a timer,
  then the scan reports the same id due-to-stop; asserts `endRecording` is NEVER called + the timer is
  left intact.
- `RecordingSchedulerTest::testManualCancelCancelsArmedStopTimer` (finding 2) — REAL Recorder (only
  `startRecording` stubbed to avoid ffmpeg) so the onStop hook is genuinely wired; arm timer via
  `processDueRecordings`, then `cancelRecording` → `activeStopTimerCount()` back to 0.
- `RecorderTimedStopTest::testGetRecordingsDueToStopAppliesPostPaddingInScan` (updated, finding 3) —
  scan-SQL assertion now expects `end_time + GREATEST(0, COALESCE(post_padding_seconds, 60))`.

### Verification (actual)
- `./vendor/bin/phpstan analyse -c phpstan.neon.dist` → **[OK] No errors** (646 files).
- `./vendor/bin/phpunit --testsuite Unit --no-coverage` → **Tests: 4935, 0 failures / 0 errors**
  (4 warnings / 8 skipped, all pre-existing in unrelated TranscodeManagerTest). `--filter
  'RecorderTimedStop|RecordingScheduler|Recorder'` → 40 OK.
- `./vendor/bin/phpcs --standard=PSR12` on the 4 touched files → 0 errors; only 2 pre-existing
  >120-char warnings on untouched Recorder lines 204 (comskip callback) / 1654 (docblock array shape).

### Files changed
- `src/LiveTv/Recorder.php` — CAS on both completion UPDATEs; onStop hook infra + firing on
  stop/cancel/delete; scan-SQL `GREATEST(0, …)` clamp.
- `src/LiveTv/Recording/RecordingScheduler.php` — scan skips ids with a live timer; onStop hook
  registered in ctor; `fireStopTimer` unset moved to `finally`.
- `tests/Unit/LiveTv/RecorderTimedStopTest.php`, `tests/Unit/LiveTv/Recording/RecordingSchedulerTest.php`.

## Reviewer (re-review) — SV-3.1c fix — 2026-07-12

Re-reviewed the fix range `bcac8e50..a61f7782` (impl `ddd41106` + tests `a61f7782`)
against the 3 original findings. Verified locally: phpstan L9 `-c phpstan.neon.dist`
= [OK] 0 errors; `phpunit --filter 'RecorderTimedStop|RecordingScheduler|Recorder'`
= OK (40 tests, 112 assertions); phpcs PSR-12 on both touched src files = 0 errors
(2 pre-existing >120-char warnings on unchanged lines 204/1654, non-blocking §0.2).

Check-by-check:
1. CAS RETURN-TYPE (highest priority) — CONFIRMED CORRECT. The parent Workerman
   client `Connection::query()` (vendor/workerman/mysql/src/Connection.php:1859-1860)
   returns `$this->sQuery->rowCount()` — an INT — for `update|delete|replace`;
   `PhlixMySQLConnection::query()` delegates to `parent::query()`. So `$affected` is a
   genuine int affected-row count and `is_int($affected) && $affected >= 1` is a valid
   gate. The normal (non-race) path matches the still-`recording` row and, because the
   UPDATE always changes `status`/`updated_at`, rowCount is 1 → onComplete fires exactly
   once (no MYSQL_ATTR_FOUND_ROWS "matched-but-unchanged 0" trap). The cited precedent
   `ScanJobRepository::claimNext()` (:158-166) relies on the identical `is_int(...) &&
   < 1` idiom for a conditional UPDATE — the usage matches. NOT the feared regression.
2. IDEMPOTENCY (finding 1) — CONFIRMED. Both completion writes are conditional CAS
   (`WHERE recording_id=? AND status='recording'`): stopRecording (Recorder.php:802-828)
   fires onComplete only after the affected>=1 guard; endRecording's reconcile branch
   (:905-919) likewise; endRecording's live branch delegates to stopRecording (no
   separate write). No path fires onComplete unconditionally. Test
   `testCompletionIsIdempotentUnderTimerVsScanRace` (affected 1→0) proves exactly-once
   and would double-fire against pre-fix code.
3. SCAN EXCLUSION + SLOT TIMING (finding 1b) — CONFIRMED. processCompletedRecordings
   skips ids in `stopTimerIds` (RecordingScheduler.php:228); fireStopTimer clears the
   slot in `finally` AFTER endRecording returns (:336-345), so the id stays set through
   the ffmpeg-teardown window (terminateRecording precedes the CAS in stopRecording, so
   the id is present during the multi-second kill; cancelStopTimer only removes it after
   the row is already `completed`). Skipping by `stopTimerIds` (NOT `activeRecordings`)
   is correct: boot recovery (LiveTvManager::bootstrap→Recorder::resumeActiveRecordings)
   re-attaches pid-alive rows WITHOUT calling scheduleStopTimer, so recovered recordings
   are NOT in stopTimerIds and remain covered by the scan. No recording is left with
   neither a timer nor scan coverage. Test asserts endRecording is never called for an
   armed-timer row.
4. onStop EXACTLY-ONCE + NO CYCLE/RECURSION (finding 2) — CONFIRMED. onStop fires once
   per manual path via the `$wasActive` guard (stopRecording fires it; cancel/delete fire
   it only in the `!$wasActive` fallback). The scheduler ctor registers
   `onStop(fn=>cancelStopTimer)` on the already-built Recorder — the DI provider
   (LiveTvServicesProvider.php:205-219) builds Recorder→LiveTvManager→scheduler in order,
   no construction cycle; ctor signature unchanged; scheduler is only ever obtained via
   `container->get` (start.php:235) and is never `new`ed in index.php/start.php, so no
   entrypoint mirroring is owed. cancelStopTimer touches only Timer::del + the local
   array — it cannot re-enter the Recorder, so no recursion. activeStopTimerCount()
   returns to baseline on manual cancel (test `testManualCancelCancelsArmedStopTimer`).
5. PADDING CLAMP (finding 3) — CONFIRMED. Scan SQL `end_time + GREATEST(0,
   COALESCE(post_padding_seconds, 60))` (Recorder.php:977) mirrors effectiveEndTime()'s
   `$endTime + max(0, $postPaddingSeconds)` (:949); COALESCE default 60 matches the PHP
   default in endRecording. Timer and scan agree on the effective stop for the same row.
6. NO NEW REGRESSION — phpstan L9 clean; no exit/die/blocking-sleep introduced; onStop
   registered exactly once (single scheduler singleton) so onStopCallbacks is not an
   unbounded static; SV-3.1c timer/scan/padding behavior intact; Recorder↔Scheduler
   wiring sound.
7. TESTS GENUINE — the idempotency, onStop-on-all-manual-paths, and scan-skips-timer-held
   tests each fail against the pre-fix code (double onComplete, missing onStop mechanism,
   endRecording called by the scan respectively). They guard the exact seams.

NO FINDINGS

## ⏸ PERF-4 PAUSE STATE (2026-07-12) — server resume point
- **SV-4.7** ✅ done (prior). **SV-3.1 foundation (a+b0+e)** ✅ review NO FINDINGS. **SV-3.1c** (scheduler Timer + timed-stop@end+padding + padding fix) ✅ FULLY DONE: impl `50d3e992`/`89f3f35f`, fix `ddd41106`/`a61f7782` (3 review findings: double-completion race→atomic CAS + scan-skips-timer-held; stop-timer leak→onStop hook; negative-padding clamp), **re-review NO FINDINGS** `6f8749ee`. Unit 4935/0.
- **SV-3.1d (media-item registration + `livetv_recordings.media_item_id` linkage migration)** — ✅ IMPL+TESTS DONE (pending review). Commits `c8845464` (impl+migration) + `0f20be7b` (tests), pushed; tree clean, local==origin. See "## Implementer — SV-3.1d" note below. Comskip STILL split out to SV-3.1d-comskip (deferred to SV-4.3) — NOT wired here.
- **Server queue after SV-3.1d:** SV-4.3 (ComskipRunner non-blocking — audit+fix) → SV-3.1d-comskip (chapter markers → real media_item_id) → SV-3.1f (timeshift 501 stub @ `LiveTvStreamController.php:~129` → withFile/HLS + rolling buffer) → g (storage accounting) → h (LiveTvStreamController tests) → SV-3.6 (Trakt pull-sync) → SV-4.13-finish (remove `buildTranscodeCommandWithProfile` zero-callers + stale docrefs) → RE-AUDIT SV-0.6–0.9/1.x/2.2-2.3-2.7/3.3-3.4/4.1-4.6/4.8-4.12/4.14.
- **NOTE:** external release-maintenance commits landed (Release v1.2.3, @phlix/ui v0.79→v0.80 bump, composer.lock) — HEAD `c9f1f26c`; all SV work preserved in ancestry.

## Implementer — SV-3.1d (media-item registration + linkage migration) — 2026-07-12

Registers a completed DVR recording's captured `.ts` as a playable `media_items`
row on the once-only Recorder `onComplete` path and persists the resulting
`media_items.id` back onto `livetv_recordings`. Comskip is NOT touched (that half
is SV-3.1d-comskip, deferred to SV-4.3). Committed + pushed myself (`c8845464`
impl+migration, `0f20be7b` tests). Tree clean, local==origin at `0f20be7b`.

### Migration
- NEW `migrations/077_livetv_recording_media_item_link.sql` — `ALTER TABLE
  livetv_recordings ADD COLUMN media_item_id CHAR(36) NULL AFTER status` +
  `ADD INDEX idx_media_item_id`. Matches `media_items.id` (CHAR(36) UUID PK).
  One-ALTER-per-clause, NO `IF NOT EXISTS` (MySQL 8 rejects it on ADD COLUMN/INDEX;
  the runner downgrades duplicate 1060/1061 on replay — same idiom as 022). No
  migration-file test in the repo lists expected files (MigrationRunnerTest uses a
  tmp dir), so nothing to update. NOT run here (no DB) — runs on deploy.

### Registration (completion wiring)
- NEW `src/LiveTv/Recording/RecordingMediaRegistrar.php` — `register(string
  $recordingId, string $recordingPath): ?string`. Wired via
  `$recorder->onComplete([$registrar, 'register'])` in `LiveTvServicesProvider`'s
  Recorder factory (NOT via `RecordingHooks::register`, which is comskip-specific —
  it takes a `ComskipPostProcessor`; media-item registration is a distinct concern,
  so inline registration is the correct fit). The Recorder fires `onComplete`
  EXACTLY once per completion (SV-3.1c atomic CAS), so registration runs once.
- Guards (each returns null, inserts nothing): row not found; `status != 'completed'`
  (onComplete ALSO fires for rows `resumeActiveRecordings()` marks FAILED —
  those must never register); already-linked `media_item_id` (idempotent replay);
  missing / zero-length capture file (never register a broken item).
- Insert delegated to the canonical **`ItemRepository::upsertByPath`** (path-deduped)
  — a retried completion returns the same id, no duplicate row. Columns populated:
  `library_id`, `name`=recording `title` (from the EPG/programme stored on the
  recording row), `type='video'` (valid `media_items.type` enum; the honest type
  for a raw capture — MediaItemShaper coerces unknown-to-it types to 'movie' for the
  API, harmless), `path`=the `.ts`, `metadata_json`={source:'livetv_dvr',
  recording_id, channel_id, program_id, description, recorded_start/end,
  duration_seconds}. `create()`'s only required keys are library_id/name/type/path;
  all other media_items columns default.
- **Library association:** find-or-create a dedicated `video`-type library by name
  (config `dvr.library_name`, default 'DVR Recordings') via DIRECT SQL against
  `libraries` (NOT LibraryManager) — deliberately NO FolderWatcher, so the storage
  path is never scanned and cannot double-register the `.ts` files. INSERT mirrors
  `LibraryManager::createLibrary`'s columns exactly (id, name, type, paths, options).
- **Linkage persisted:** `UPDATE livetv_recordings SET media_item_id = ?, updated_at
  = NOW() WHERE recording_id = ?` with the returned id — so SV-3.1d-comskip can
  attach chapter markers to the real item.
- **Reads use the plain-array media-layer convention** (like ItemRepository /
  LibraryManager), NOT the LiveTv `RowQuery` cursor — see the risk note below.

### Config + DI (no ctor/DI-signature change → no entrypoint mirror)
- `config/livetv.php` — NEW `dvr.library_name` (default 'DVR Recordings').
- `LiveTvServicesProvider` — registers `RecordingMediaRegistrar` as a per-worker
  singleton (resolving `ItemRepository` from the container) and calls
  `$recorder->onComplete(...)` in the Recorder factory. NO existing ctor/DI
  signature changed → NO `public/index.php` / `start.php` mirror needed (both
  entrypoints share this container; the wiring is a runtime callback registration).

### Tests
- NEW `tests/Unit/LiveTv/Recording/RecordingMediaRegistrarTest.php` (7): completed
  recording → asserts upsertByPath data (library_id/name/type='video'/path/metadata
  incl. duration_seconds) + the `UPDATE ... media_item_id` params `['media-1','rec-1']`;
  missing file / zero-length file / status='failed' / already-linked / missing row all
  assert `upsertByPath` is NEVER called; library find-or-create asserts a `libraries`
  INSERT with the configured name + 'video' type and that the media item uses that
  exact new library id.
- `tests/Unit/Common/Container/Providers/LiveTvServicesProviderTest.php` (+1, updated):
  Recorder now carries EXACTLY 2 onComplete hooks (comskip + registrar);
  `RecordingMediaRegistrar` resolves as a shared singleton (added an `ItemRepository`
  mock to the test container so the registrar factory resolves).

### Verification (actual, this box)
- `./vendor/bin/phpstan analyse -c phpstan.neon.dist` → **[OK] No errors** (647 files).
- `./vendor/bin/phpunit --testsuite Unit --no-coverage` → **Tests: 4943, 0 failures /
  0 errors** (4 warnings / 8 skipped — all pre-existing in TranscodeManagerTest,
  unchanged from the SV-3.1c 4935 baseline; +8 = my new tests).
  `--filter 'RecordingMediaRegistrar|LiveTvServicesProvider'` → 10 OK / 44 assertions.
- `./vendor/bin/phpcs --standard=PSR12` on the 4 touched files → **0 errors**.

### Seams left for later (NOT done here)
- **SV-3.1d-comskip** (deferred, gated on SV-4.3): attach comskip EDL→chapter markers
  to the real `media_item_id`. The `media_item_id` is now produced + persisted, so
  that step has a real item to attach to. `RecordingHooks::register` still has no
  caller (it wires a `ComskipPostProcessor`) — leave it for that step.
- **RISK / on-box verify owed (pre-existing, NOT SV-3.1d scope):** the LiveTv read
  path (`Recorder`/`RecordingScheduler` via `RowQuery`/`ResultSet`) narrows on
  `$result instanceof \Phlix\LiveTv\Dto\ResultSet`, but production
  `PhlixMySQLConnection::query("SELECT …")` returns a PLAIN ARRAY (`parent::query`
  → `PDOStatement::fetchAll`, verified) — nothing in `src/` ever produces a
  `ResultSet`. So `RowQuery::rows/firstRow` appear to yield `[]`/`null` for real
  results, which would mean `Recorder::getRecording()`/`stopRecording()` (hence the
  whole DVR completion → my `onComplete` hook) may not fire against a live DB. This
  is the DVR stack's already-OWED on-box verification (SV-3.1 foundation/c notes), a
  PRE-EXISTING concern I did NOT fix (out of SV-3.1d scope). My registrar itself uses
  the plain-array media-layer convention that provably works in prod, so its own
  logic is correct regardless; but end-to-end firing depends on that owed verify. A
  future step should either confirm the connection returns ResultSet-shaped objects
  in prod or fix `RowQuery` to accept plain arrays.
- **On-box verify OWED for SV-3.1d itself** (migration + start.php outside CI): after
  deploy, run `php scripts/run-migrations.php` (adds `media_item_id`), complete a
  recording, confirm a `media_items` row exists for the `.ts` and
  `livetv_recordings.media_item_id` is set; confirm a zero-length/failed capture
  leaves `media_item_id` NULL.

## Reviewer (per-step) — SV-3.1d — 2026-07-12

Reviewed `git diff 34bd569b..5f9c8857` = `c8845464` (impl + migration 077) + `0f20be7b`
(tests). Read the step's plan entry, the SV-3.1d implementer note, RowQuery/ResultSet,
PhlixMySQLConnection + parent `Workerman\MySQL\Connection::query()`, Recorder completion
chain, ItemRepository working-idiom, migration 077 + 022, config/livetv.php,
LiveTvServicesProvider, and both entrypoints. Read-only gates (this box):
`phpunit --filter 'RecordingMediaRegistrar|LiveTvServicesProvider'` = **OK (10 tests,
44 assertions)**; `phpstan analyse -c phpstan.neon.dist` on the touched files = **[OK]
No errors**.

The SV-3.1d registration DIFF itself is correct — CHECKS #2–#6 pass (see below). The
one blocking issue is CHECK #1, the RowQuery/ResultSet landmine, which is **PRE-EXISTING**
(not introduced by SV-3.1d) but renders the whole DVR read path — and therefore the
SV-3.1d `onComplete` hook — inert in production.

### Findings

1. **[BLOCKING — PRE-EXISTING, NOT an SV-3.1d change] The LiveTv `RowQuery` read path
   yields EMPTY against the production DB, so the entire DVR read path (and hence the
   SV-3.1d `onComplete` registrar) never fires against a live database.**
   - `src/LiveTv/Dto/RowQuery.php:45,65,82` — `rows()/firstRow()/hasRows()` all narrow
     on `if (!$result instanceof \Phlix\LiveTv\Dto\ResultSet) { return [] / null / false; }`.
   - `ResultSet` (`src/LiveTv/Dto/ResultSet.php:34`) is an **abstract** class with a
     `num_rows` property + `fetch()` method. The ONLY things that `extends ResultSet` are
     the 6 LiveTv unit-test mock files; `grep -rn "ResultSet" src/` finds nothing in `src/`
     that ever constructs or returns one.
   - Production shape: `PhlixMySQLConnection::query()` (`src/Common/Database/PhlixMySQLConnection.php:258`)
     delegates to `parent::query()`, and `Workerman\MySQL\Connection::query()`
     (`vendor/workerman/mysql/src/Connection.php:1857-1858`) for a SELECT returns
     `$this->sQuery->fetchAll($fetchmode)` — a **plain `array<int,array<string,mixed>>`**,
     never a `ResultSet` object. `Connection::class` is bound to the real
     `PhlixMySQLConnection` via `CoreServicesProvider.php:81` → `ConnectionPool::getConnection('mysql')`.
   - Net effect in prod: for every real SELECT, `$result instanceof ResultSet` is false, so
     `RowQuery::firstRow()` → `null`, `rows()` → `[]`, `hasRows()` → `false`. This poisons
     `Recorder::getRecording()` (`Recorder.php:359`), `getAllRecordings()`,
     `getRecordingsDueToStop()` (`:967`, the SV-3.1c timed-stop scan),
     `resumeActiveRecordings()` (SV-3.1e boot recovery), `RecordingScheduler::processDueRecordings`
     (`RecordingScheduler.php:133`), plus GuideManager/SeriesRuleManager/RecordingDeduplicator.
   - Failure scenario for THIS step: `Recorder::stopRecording()` (`:774-777`) and
     `endRecording()` (`:866`) both do `$recording = $this->getRecording(...); if (!$recording) return false;`
     BEFORE the atomic completion CAS + `fireOnCompleteCallbacks()` (`:828`/`:919`). In prod
     `getRecording()` is always `null`, so completion short-circuits and the SV-3.1d
     `onComplete` registrar is NEVER invoked. Green unit tests hide this exactly because the
     Recorder tests inject `ResultSet`-shaped mocks while production returns plain arrays —
     the "green tests masking inert prod code" failure mode.
   - Provenance: RowQuery/ResultSet are `@since Wave 5a (post-O.7)` — they predate SV-3.1.
     This is a PRE-EXISTING defect surfaced (and correctly flagged) by the SV-3.1d
     implementer, NOT a regression in `c8845464`/`0f20be7b`. The SV-3.1d registrar itself
     is correct: it reads via the plain-array media-layer convention
     (`RecordingMediaRegistrar::fetchRecording` at `:215-232`, `is_array($result[0])`), which
     is the idiom that provably works in prod (same as `ItemRepository::findByPath`/`firstRow`).
   - Fix direction (its OWN sub-step, regardless of which sub-step is credited): make
     `RowQuery::rows/firstRow/hasRows` accept the plain-array shape `query()` actually returns
     (iterate `array<int,array<string,mixed>>` and drop the `instanceof ResultSet` narrowing —
     keeping a fallback for the mock shape if desired), OR route all LiveTv reads through the
     same plain-array idiom the working repositories use. Then re-point the LiveTv unit-test
     mocks off `ResultSet` onto plain arrays so the tests exercise the real prod shape. This
     is the already-OWED on-box DVR verification made concrete: without it, SV-3.1 a/b0/c/d/e
     are all inert in production despite green mock-DB tests.

2. **[LOW — non-blocking; dominated by finding #1] `RecordingMediaRegistrar::ensureRecordingsLibrary`
   can create duplicate "DVR Recordings" libraries under a concurrent first-completion race.**
   - `src/LiveTv/Recording/RecordingMediaRegistrar.php:249-281` does a `SELECT id FROM
     libraries WHERE type=? AND name=? LIMIT 1` then, on miss, `INSERT INTO libraries ...`.
     `libraries` has no unique constraint on `(type, name)` (migration 001), and the registrar
     is a per-worker singleton, so two DIFFERENT recordings completing concurrently (e.g. a
     manual stop on an HTTP worker + the worker-0 scheduler, or two coroutines on worker 0)
     before the library first exists can each miss the SELECT and both INSERT → 2+ duplicate
     DVR libraries. Subsequent completions reuse whichever `LIMIT 1` returns.
   - Failure scenario: cosmetic split of DVR recordings across duplicate libraries on a fresh
     install; self-limiting (happens once, early). Benign TODAY because finding #1 makes the
     whole completion path inert, but it becomes real once #1 is fixed.
   - Fix direction (fold into the #1 fix or a follow-up): add a UNIQUE index on
     `libraries(type, name)` (or re-SELECT after a failed INSERT, mirroring
     `ItemRepository::upsertByPath`'s race-collision reuse), so find-or-create is idempotent.

### CHECKS #2–#6 — SV-3.1d registration diff: CLEAN

- **#2 Registration correctness:** all four guards short-circuit BEFORE `upsertByPath`
  (`register()` `:126` row-not-found → null; `:136-138` `status != 'completed'` → null, so a
  FAILED row from `resumeActiveRecordings()` never registers; `:142-145` already-linked →
  returns existing id, no insert; `:149-155` missing/zero-length file → null). onComplete is
  registered exactly once: PHP-DI shares the `Recorder::class` factory (`LiveTvServicesProvider.php:186`)
  as a singleton, and `$recorder->onComplete([$registrar,'register'])` runs once in that
  factory (Recorder carries exactly 2 hooks: comskip + registrar, asserted by the provider test).
- **#3 Insert correctness:** `ItemRepository::upsertByPath` (path-deduped, returns non-null
  `string`) is used with `library_id / name (EPG title) / type='video' / path=.ts /
  metadata_json`. The returned id is captured and persisted by the parameterized, colon-free
  `UPDATE livetv_recordings SET media_item_id = ?, updated_at = NOW() WHERE recording_id = ?`
  (`:192-195`) — no injection. All read columns exist in the `livetv_recordings` schema
  (012a + 022 + 077).
- **#4 Library find-or-create:** direct parameterized/colon-free SQL against `libraries`,
  deliberately NOT via LibraryManager/FolderWatcher (so the storage path is never scanned/
  double-registered); INSERT columns mirror `createLibrary`. (Race caveat = finding #2.)
- **#5 Migration 077:** MySQL-8 compatible — no `IF NOT EXISTS`, one ALTER per clause,
  `media_item_id CHAR(36) NULL` matches `media_items.id`; replay-safety relies on the runner
  downgrading 1060/1061, confirmed the real idiom (`MigrationRunner::isAlreadyAppliedNote`
  matches "Duplicate column name"/"Duplicate key name") and identical to migration 022's
  `pid` ALTER pair.
- **#6 Scope/policy:** no comskip wiring (correctly deferred to SV-3.1d-comskip/SV-4.3;
  `media_item_id` is produced + persisted for it); no ctor/DI-signature change — both
  `public/index.php:72` and `start.php` build the container via `ContainerFactory::create`,
  which registers `LiveTvServicesProvider` (`ContainerFactory.php:131`), so no entrypoint
  mirror is needed; nothing stubbed/deleted; tests are genuine (each rejection path asserts
  `upsertByPath` is NEVER called; the happy path asserts the insert fields + the exact
  `['media-1','rec-1']` linkage UPDATE params; the library test asserts the INSERT name +
  'video' type + that the item uses the new library id; test mocks return PLAIN ARRAYS,
  matching the real prod shape).

**Verdict:** 2 findings. #1 is BLOCKING for the SV-3.1 DVR stack as a whole but is
PRE-EXISTING (RowQuery/ResultSet, `@since Wave 5a`) and out of SV-3.1d's own scope — the
SV-3.1d registration diff (`c8845464`/`0f20be7b`) is itself correct and complete. The
RowQuery fix must be its own sub-step (it also fixes SV-3.1 a/b0/c/e inertness); finding #2
(LOW) should fold into that fix.

## Implementer — SV-3.1-rowquery (DVR read-path resurrection) — 2026-07-12

Fixed the CONFIRMED BLOCKING landmine (finding #1) + folded in the LOW library-race
(finding #2). Committed + pushed myself; tree clean, local==origin.

**Part A — RowQuery consumes the plain-array shape production actually returns.**
`src/LiveTv/Dto/RowQuery.php` — `rows()/firstRow()/hasRows()` no longer narrow ONLY on
`instanceof ResultSet`. Each now handles BOTH shapes:
- **Plain array (prod shape)** — `PhlixMySQLConnection::query("SELECT …")` → Workerman
  `Connection::query()` → `fetchAll()` returns `array<int, array<string, mixed>>`.
  `rows()` → new private `rowsFromArray()` returns the list (filtering non-array elements
  for L9-safe typing); `firstRow()` → `$result[0]` when it is an array, else null;
  `hasRows()` → `$result !== []`. An empty SELECT (`[]`) → `[]`/null/false (correct).
- **ResultSet cursor (test-mock shape)** — the original `num_rows`/`fetch()` path is KEPT
  verbatim, so all 6 existing LiveTv `ResultSet`-mock tests still pass. `ResultSet` is NOT
  deleted (per §0.1). Any non-array / non-ResultSet (null, scalar, INSERT/UPDATE int) → no
  rows, unchanged.
Net: against a plain-array `query()` result, `Recorder::getRecording()` returns a real row,
`getRecordingsDueToStop()`/`resumeActiveRecordings()` return real rows, and the SV-3.1d
`onComplete` registrar can finally fire. This resurrects the whole DVR read path
(SV-3.1 a/b0/c/d/e) that was silently inert vs a live DB.

**REGRESSION GUARDS (the key deliverable) — drive the read path with the PROD plain-array shape:**
- `tests/Unit/LiveTv/Dto/RowQueryTest.php` (+4) — `rows`/`firstRow`/`hasRows` fed plain
  arrays directly (prod shape) + empty-result null.
- `tests/Unit/LiveTv/RecorderPlainArrayReadPathTest.php` (NEW, 4) — a mocked
  `Connection::query()` returns PLAIN ARRAYS (not a ResultSet); asserts
  `getRecording()`→real row, empty→null, `getRecordingsDueToStop()`→`['rec-1','rec-2']`,
  `resumeActiveRecordings()`→reads the interrupted row, reconciles to FAILED, fires onComplete.
- PROOF they guard the landmine: with the src fixes stashed to HEAD (pre-fix
  `instanceof ResultSet`-only RowQuery), these fail — plain-array `rows`→size 0,
  `firstRow`→null, `hasRows`→false, `getRecording`→null, `getRecordingsDueToStop`→`[]`
  (7 failures + 1 error). With the fix: green.

**Part B — DVR library find-or-create race (finding #2).**
`src/LiveTv/Recording/RecordingMediaRegistrar.php::ensureRecordingsLibrary` — chose the
**migration-free re-SELECT convergence** (NOT a global UNIQUE(type,name) index — that could
conflict with legitimate pre-existing duplicate library names on a live box, unverifiable
here; §0.3-safe). New private `findRecordingsLibraryId()` selects with a DETERMINISTIC
`ORDER BY created_at ASC, id ASC LIMIT 1` (not a bare `LIMIT 1`, whose row order is
undefined). Flow: find → if absent, INSERT (wrapped in try/catch so a future dup-key can't
propagate) → **re-SELECT the canonical id**. Under a concurrent double-INSERT every caller
converges on the SAME single library (earliest-created, id-tiebreak), so recordings never
split across duplicate DVR libraries; the method returns the one canonical id under
concurrency. Forward-compatible: if a UNIQUE(type,name) index is ever added, the dup-key
INSERT is swallowed and reconciled. Tests: `RecordingMediaRegistrarTest`
`testConcurrentLibraryCreateConvergesOnCanonicalId` (racing re-SELECT returns a different
'lib-canonical' → item registered there, deterministic ORDER BY asserted) +
`testDuplicateKeyOnLibraryInsertReconcilesToCanonicalId` (INSERT throws → reconciled).

**ResultSet status:** still referenced by RowQuery (both-shape support) AND by the 6
LiveTv ResultSet-mock test files, so it is NOT fully unused — do NOT delete. Left in place
per §0.1; no §6 removal-queue entry needed yet.

**Verification:** phpstan L9 `-c phpstan.neon.dist` = **[OK] No errors** (647/647). phpcs
PSR-12 on `RowQuery.php` + `RecordingMediaRegistrar.php` = **0 errors**. `phpunit --testsuite
Unit` = **OK, 4953 tests, 38653 assertions, 5 skipped, 0 failures** (baseline ~4943 + 10 new).
`--filter 'RowQuery|Recorder|RecordingScheduler|RecordingMediaRegistrar'` = 63/63 green.

**On-box verify STILL OWED:** this makes the read path correct vs the prod plain-array shape
and unit-guards it, but a live end-to-end DVR record→complete→playable check on the box
(SELECT returns real rows → onComplete registrar inserts the media_item + persists the
linkage → item playable) remains the final proof.

## Reviewer (per-step) — SV-3.1-rowquery — 2026-07-12

Reviewed `git diff eee0a33a..c63e7882` = `9b32e278` (Part A: RowQuery fix + tests) +
`c63e7882` (Part B: library race + tests). Read-only gates this box: `phpstan analyse -c
phpstan.neon.dist` = **[OK] No errors** (647/647); `phpunit --filter
'RowQuery|Recorder|RecordingScheduler|RecordingMediaRegistrar'` = **63/63 green**; full
`--testsuite Unit --no-coverage` = **4953 tests, 0 failures/errors** (4 pre-existing
warnings + 5 skips — TranscodeManagerTest, not this diff). Scope confirmed: only
`RowQuery.php` + `RecordingMediaRegistrar.php` + the 3 test files touched; `ResultSet.php`
left in place (§0.1); no ctor/DI-signature change.

**1. Landmine genuinely closed (CONFIRMED).** `RowQuery::rows/firstRow/hasRows`
(`src/LiveTv/Dto/RowQuery.php:50-116`) now branch on `is_array($result)` FIRST: `rows()`
→ `rowsFromArray()` (filters non-array elements, L9-safe, returns the row list);
`firstRow()` → `$result[0]` when array else null (empty `[]` → `$result[0] ?? null` → null);
`hasRows()` → `$result !== []`. The `ResultSet` cursor path (lines 58-66/88-97/111-115) is
preserved VERBATIM below each new branch, so the 6 existing mock tests still pass. Against
the prod plain-array shape `PhlixMySQLConnection::query()` returns, `Recorder::getRecording()`
(`:359`), `getRecordingsDueToStop()` (`:983`) and `resumeActiveRecordings()` now return real
rows. Typing is L9-clean (no mixed-narrowing errors; phpstan 0).

**2. Sibling-landmine sweep — RowQuery is the ONLY inert chokepoint (CONFIRMED).**
`grep instanceof.*ResultSet` + `->num_rows`/`->fetch()` across `src/` finds cursor-narrowing
ONLY inside `RowQuery.php` (the preserved mock path). The other LiveTv reads already used the
correct prod plain-array idiom and were NOT inert: `ChannelManager` reads via
`RowMap::listFromMixed()` (`src/Common/Util/RowMap.php:61` — `is_array` + iterate, correct);
`LiveTvManager::buildStreamUrlForChannel` (`:724-732`), `ComskipIntegration::getRecordingRow`
(`:262-273`) and `ComskipLifecycleManager::getRecordingData` (`:259-275`) all guard with
`is_array($result) && !empty($result)` + `is_array($result[0])`. `RowQuery` is used ONLY in
`src/LiveTv/` (LiveTv-only; zero references elsewhere in `src/`), and `src/LiveTv/Epg/` issues
no direct `db->query`. No sibling requires the same fix.

**3. Regression tests genuine (CONFIRMED, not tautological).** `RowQueryTest` (+4) feeds
plain arrays directly and asserts count/first/has. `RecorderPlainArrayReadPathTest` (NEW, 4)
drives a mocked `Connection::query()` returning PLAIN ARRAYS (not a ResultSet) through the
real `Recorder` and asserts `getRecording()`→real row, empty→null,
`getRecordingsDueToStop()`→`['rec-1','rec-2']`, `resumeActiveRecordings()`→reconciles the
interrupted row to FAILED and fires onComplete. The pre-fix diff shows `rows/firstRow/hasRows`
had ONLY the `instanceof ResultSet` branch, so a plain array returned `[]`/null/false — these
assertions provably FAIL pre-fix (the claimed 7 failures + 1 error), pass post-fix.

**4. Part B — library race (CONFIRMED correct).** `findRecordingsLibraryId()`
(`:318-333`) selects `ORDER BY created_at ASC, id ASC LIMIT 1` (deterministic, not bare
LIMIT 1); `libraries` genuinely has `created_at` (migration 001:35) and `type='video'` is a
valid ENUM value. `ensureRecordingsLibrary()` (`:250-304`) does find → INSERT-in-try/catch →
re-SELECT the canonical id. Under a concurrent double-INSERT every caller re-SELECTs the SAME
earliest-created/id-tiebroken row, so items always register under one canonical library — no
split. Parameterized + colon-free (no injection). Migration-free re-SELECT chosen over a
UNIQUE(type,name) index deliberately (would conflict with legit pre-existing dup names,
unverifiable here; §0.3-safe) and is forward-compatible with such an index. Tests
`testConcurrentLibraryCreateConvergesOnCanonicalId` (asserts the item uses 'lib-canonical',
not the just-inserted id, and asserts the ORDER BY) + `testDuplicateKeyOnLibraryInsertReconcilesToCanonicalId`
(INSERT throws → swallowed → reconciled) guard both paths.

**5. No regression / scope (CONFIRMED).** phpstan L9 [OK] 0; Unit 4953/0 (baseline ~4943 +
10 new); `ResultSet` retained; no entrypoint/DI mirror needed.

**6. Build-out completeness (CONFIRMED at code level).** SELECT → RowQuery now yields real
rows → `Recorder::stopRecording()`/`endRecording()`'s `getRecording()` gate passes → atomic
CAS + `fireOnCompleteCallbacks()` runs → the SV-3.1d `RecordingMediaRegistrar::register`
onComplete hook fires → reads the recording (plain-array idiom) → `ensureRecordingsLibrary`
→ `upsertByPath` → linkage UPDATE. The DVR read path is code-sound end-to-end. The on-box
live record→complete→playable verification remains OWED (as the implementer noted) but the
CODE path is now correct against the prod shape.

**Verdict: NO FINDINGS.**

## Orchestrator — SV-4.1–4.6 RE-AUDIT roll-up (2026-07-12, perf-4)
Real opencode commits (worklog :91-96 hashes are WRONG/nonexistent): SV-4.1=`e263be5b`, SV-4.2–4.5=`197fb948` (one commit), SV-4.6=`a2853ff2`.
- **SV-4.1 [S-F13] segment-cap → DONE (confirmed).** Reservation `segmentEncodesInFlight[$final]` set BEFORE the yieldable glob (`TranscodeManager.php:772` precedes `:786`), rollback+`SegmentBusyException` on over-cap; `finally` releases only launched. Behavioral cap/dedup tests present. No action.
- **SV-4.2 [S-F23] ffmpeg cancel/timeout → PARTIAL.** `timeout <n>` wrapper on `buildDetachedCommand` (whole-file/CMAF) + `Recorder::terminateRecording` SIGTERM→SIGKILL DONE. GAPS: (1) the on-demand PER-SEGMENT encode path (`launchDetachedSegment` `FfmpegRunner.php:1981-1996`) has NO `timeout`, no PID tracking — the exact scrub-storm orphan; (2) no client-disconnect→kill (only `posix_kill($pid,0)` liveness probe); (3) server `RelayConsumer::onHttpCancel` (`:1442-1458`) closes local conn + discards accumulator but does NOT kill the ffmpeg PID (the X1/HB-4.9 server half). → Complete (cross-repo X1).
- **SV-4.3 [S-F11] ComskipRunner non-blocking → DONE ⭐ (confirmed).** `stream_set_blocking(false)` both pipes + `stream_select`-poll with per-iteration hrtime timeout vs 300s → `proc_terminate(SIGKILL)`; output bounded. Old blocking bug gone; timeout reachable. **SAFE TO WIRE COMSKIP (SV-3.1d-comskip).** (Recommend adding a wedged-process timeout test when wiring.) No fix needed.
- **SV-4.4 [S-F10] webhook backoff → PARTIAL (inert).** `WebhookDispatcher::dispatchAsync`+backoff+one-shot timer are correct but have ZERO callers (dead); no `CURLOPT_CONNECTTIMEOUT` in `WebhookHttpClient`; the live event→webhook path is a DIFFERENT class `WebhookService::dispatchEvent`/`handleFailedDelivery` (already has DB retry + one-shot timer + async client). → Complete (reconcile: add connect-timeout to WebhookHttpClient; wire or remove dead dispatchAsync per §0.1/§6).
- **SV-4.5 [S-F15/F16] Roku/HdHomeRun/MusicBrainz async → NOT-DONE.** Commit `197fb948` claimed "MusicBrainz async" but touched none of the targets. Still blocking: `RemoteRokuClient.php:158-172` (usleep+`file_get_contents`), `RokuEcpClient.php:125`/`HdHomeRunTunerDriver.php:129` (usleep), `MusicMetadataProviderTrait.php:45` per-INSTANCE limiter (not static-per-host, not coroutine-aware). → Complete (whole step).
- **SV-4.6 [S-F20] `original` copy variant → DONE (confirmed).** `array_filter(!isCopy)` excludes the `id:'original'` `isCopy:true` passthrough from `buildMultiVariantMaster` (all-copy fallback kept); transcoded "Original" (`isCopy:false`, real IDR) stays switchable. Behavioral tests present. No action.

Server Complete queue: SV-3.1d-comskip (NOW unblocked by SV-4.3) → SV-4.2 (scrub-storm, X1) → SV-4.5 (blocking I/O) → SV-4.4 (webhook reconcile). Then SV-3.1 f/g/h, SV-3.6, SV-4.13-finish, remaining re-audits (SV-4.8–4.12/4.14, SV-0.6–0.9, SV-1.x, SV-2.2/2.3/2.7, SV-3.3/3.4).

## Implementer — SV-3.1d-comskip (comskip → chapter markers on real media_item_id, off the hot path) — 2026-07-12

Wired comskip commercial-detection into the DVR completion path so a completed
recording's EDL is parsed into chapter markers attached to the REAL
`media_item_id` (produced by SV-3.1d's `RecordingMediaRegistrar`), run OFF the
worker-0 completion coroutine. **Chose the "wire ComskipLifecycleManager directly"
option** (the existing wired onComplete comskip handler) — NOT RecordingHooks —
so no parallel comskip system was built. `RecordingHooks::register` /
`ComskipPostProcessor` / `MarkerService` remain in place, still without a caller
(alternative path not taken; kept per §0.1).

### The completion → comskip → markers flow
- Recorder fires onComplete (once, SV-3.1c atomic CAS) with `(recordingId, filePath)`.
  Hook order in `LiveTvServicesProvider`: **#1** `ComskipLifecycleManager::enqueue`
  (auto-wired in the Recorder ctor), **#2** `RecordingMediaRegistrar::register`
  (sets `livetv_recordings.media_item_id`).
- **#1 enqueue now only QUEUES + schedules an off-hot-path drain** (see below) — it no
  longer runs comskip inline. So the completion coroutine returns immediately.
- The deferred drain (a one-shot Workerman timer, ~1s later) fires AFTER the whole
  completion coroutine — so #2 has committed `media_item_id`. It runs
  `ComskipIntegration::processRecording`, which now: resolves the recording's
  `media_item_id`, runs comskip (existing SV-4.3 `ComskipRunner`), parses the EDL
  (`ComskipEdlParser`), stores the recording-row commercial stats (unchanged), AND
  attaches the parsed segments as chapter markers on the real media item via
  `ChapterMarkerService::persistChapters(media_item_id, chapters)` →
  `media_items.metadata_json.commercial_chapters`.

### Off-hot-path mechanism (why it can't stall the worker)
`ComskipLifecycleManager::enqueue` → `scheduleDrain()`: when a Workerman worker is
RUNNING (`WorkerContext::isEventLoopRunning()`), it arms a ONE-SHOT
`Workerman\Timer::add(1s, …, [], false)` (guarded by a `drainScheduled` flag; new
enqueues while one is armed just append to the queue the pending drain will pick
up) and RETURNS — the completion coroutine is never held for the comskip duration
(up to 300s). The drain runs in the timer's own coroutine (Swoole adapter wraps
timer callbacks in `Coroutine::create()`), serialized (`drainQueue()` =
`while (processNext())`, one recording at a time). Verified the hook mask
(`SwooleRuntime::SAFE_HOOK_NAMES`): `SWOOLE_HOOK_PROC` + the blocking-function hook
are EXCLUDED (so a raw inline comskip poll could stall the loop) but
`SWOOLE_HOOK_STREAM_FUNCTION` (which covers `stream_select`) IS enabled — so the
existing SV-4.3 `ComskipRunner` poll loop cooperatively yields (hooked
`stream_select` + `Coroutine::sleep`) and does not freeze the worker. Deferring the
run off the completion coroutine + the cooperative runner = the completion path is
never blocked. Outside a running worker (CLI/PHPUnit/FPM — where `Workerman\Timer`
is unusable and there is no loop to protect) `scheduleDrain()` drains synchronously
(historic behaviour; `Timer::add` outside a live worker throws
"Timer can only be used in workerman running environment" — the reason the gate is
`isEventLoopRunning()`, not merely `class_exists(Timer)`).

### Idempotency / guards / failure-safety
- `ComskipIntegration::processRecording` (when wired with `ChapterMarkerService`)
  SKIPS the comskip run entirely if the recording has NO `media_item_id` (nothing to
  attach to — e.g. a failed/empty capture the registrar never registered); a missing
  `.ts` throws (caught upstream). "Only run for a completed recording WITH a
  media_item_id and a present .ts" ✔.
- `ComskipLifecycleManager::isAlreadyProcessed` (`commercial_processed_at`) guards
  re-enqueue; `persistChapters` overwrites `commercial_chapters` (idempotent) — no
  double markers on reprocess.
- comskip failure/timeout/unavailable → `processRecording` throws →
  `processRecordingSync` catches + logs, never rethrows → drain continues, recording
  stays playable (media item already registered, just without markers). No exception
  escapes the completion path.

### Files changed
- `src/LiveTv/ComskipRunner.php` — optional 3rd ctor param `?int $timeoutSeconds`
  (default = 300 const); makes the wedged-process timeout testable without a 300s
  wait. No behavior change at default.
- `src/LiveTv/Recording/ComskipIntegration.php` — optional 5th ctor param
  `?ChapterMarkerService`; `processRecording` resolves `media_item_id` (new
  `resolveMediaItemId`), skips when unlinked, and attaches chapter markers after the
  run. When constructed WITHOUT a chapterService (legacy/tests) behaviour is
  unchanged (no extra queries, no marker attach).
- `src/LiveTv/Recording/ComskipLifecycleManager.php` — `enqueue` defers via
  `scheduleDrain()`; new `shouldDeferDrain()`/`armDrainTimer()` (overridable test
  seams), `onDrainTimer()`, public `drainQueue()`; `drainScheduled` flag +
  `DRAIN_DELAY_SECONDS`.
- `src/Common/Container/Providers/LiveTvServicesProvider.php` — registers
  `ChapterMarkerService` (from `ItemRepository`) and injects it into
  `ComskipIntegration`. **No ctor/DI-signature change to Recorder or either
  entrypoint** — the new params are optional and wired only through this shared
  provider (both `public/index.php` + `start.php` resolve it), so no dual-entrypoint
  mirror is needed.

### Tests added (mock the comskip subprocess like ComskipRunnerTest)
- `ComskipRunnerTest::testRunTimesOutAndKillsWedgedProcess` — fake comskip that
  `sleep 30`s with a 1s timeout override → `RuntimeException "Comskip timed out
  after 1 seconds"` in <10s (SIGKILL reachable; SV-4.3 audit's recommended wedged
  test).
- `ComskipIntegrationTest::testProcessRecordingAttachesChaptersToLinkedMediaItem`
  (media_item_id 'media-1' → `persistChapters('media-1', chapters)` called),
  `::testProcessRecordingSkipsWhenNoLinkedMediaItem` (media_item_id null → comskip
  `run` NEVER called, no persist, empty result).
- `ComskipLifecycleManagerTest::testComskipFailureDoesNotEscapeEnqueue` (processing
  throws → enqueue does NOT throw, runningCount released),
  `::testEnqueueDefersToTimerAndDoesNotProcessInline` (deferred: queued + timer
  armed, NOT processed inline; manual drain processes once),
  `::testDeferredTimerFiringDrainsQueue` (timer fire → drains → processed once).
  Deferral tested via an inline anon subclass overriding the seams (a real running
  worker can't be spun in a unit test).

### Verification (this box)
- `phpstan analyse -c phpstan.neon.dist` → **[OK] No errors** (647).
- `phpunit --testsuite Unit --no-coverage` → **4959 tests, 0 failures/errors** (5
  pre-existing skips; baseline 4953 + 6 new). `--filter
  'Comskip|ChapterMarker|LiveTvServicesProvider|RecordingMediaRegistrar'` → 70 OK.
- `phpcs --standard=PSR12` on the 4 touched src files + 3 touched test files → **0
  errors**.

### On-box verify OWED (start.php / Timer / real comskip outside CI)
Deploy, complete a real recording that registered a media item, confirm within ~1s
the drain timer fires and (with comskip installed) `media_items.metadata_json.
commercial_chapters` is populated for the recording's `media_item_id`, the
`livetv_recordings.commercial_*` stats are written, and a recording with no
`media_item_id` / no comskip binary leaves the item playable with no markers and no
worker stall.

## Reviewer (per-step) — SV-3.1d-comskip — 2026-07-12

Reviewed impl commit `5700403e` + tests commit `d18a8fe8` at HEAD. Verified: phpstan
`-c phpstan.neon.dist` on the 5 touched src files = No errors; `phpunit --filter
'Comskip|ChapterMarker|LiveTvServicesProvider|RecordingMediaRegistrar'` = 70/70 OK.

Off-the-hot-path claim CONFIRMED: `ComskipLifecycleManager::enqueue` → `scheduleDrain()`
arms a genuine ONE-SHOT `Workerman\Timer::add(1.0, …, [], false)` (fourth arg `false`)
under a `drainScheduled` re-entrancy guard and returns immediately; the completion
coroutine is never held for the comskip duration. Hook ordering CONFIRMED: enqueue is
Recorder-ctor hook #1 (arms timer, returns), `RecordingMediaRegistrar::register` is
onComplete hook #2 (sets `media_item_id` synchronously in the same
`fireOnCompleteCallbacks` loop); the 1s timer fires strictly after both. Skip-when-unlinked,
failure-swallowed (`processRecordingSync` catch, never rethrows), idempotent markers
(`persistChapters` overwrites), and the `commercial_processed_at` re-enqueue guard all
verified. DB reads use plain-array-safe shape handling (`is_array($result)` + `$result[0]`),
so not inert vs the live `PhlixMySQLConnection` (the SV-3.1-rowquery landmine does not
recur here). No ctor/DI signature change to Recorder or either entrypoint — the optional
params are wired only through the shared `LiveTvServicesProvider`, so both index.php and
start.php get identical wiring; no dual-entrypoint mirror needed. SV-4.3 ComskipRunner
wedged-process SIGKILL timeout is reachable (poll loop checks the hrtime deadline every
~100ms select tick) and covered by a test. `RecordingHooks`/`ComskipPostProcessor`/
`MarkerService` confirmed dormant (never constructed/wired) — no parallel comskip path
activated.

Findings:

1. `src/Common/Container/Providers/LiveTvServicesProvider.php:173-177` — STALE DOC (low).
   The comment on the `RecordingMediaRegistrar` factory still reads "(Comskip chapter-marker
   attachment to the real media item is the SEPARATE, deferred SV-3.1d-comskip sub-step,
   gated on SV-4.3 — NOT wired here.)". That sub-step is now COMPLETE and wired ~35 lines
   above in the sibling `ComskipLifecycleManager` factory (the `ChapterMarkerService`
   injected into `ComskipIntegration` at lines 153-161). The parenthetical now misleads a
   maintainer into thinking commercial chapter-marker attachment is still unimplemented/
   deferred. Recommend updating it to note comskip marker attachment is wired via the
   ComskipLifecycleManager factory. (Documentation only — no behavioral impact.)

## Orchestrator — SV-3.1d-comskip DONE (2026-07-12, perf-5)
- [x] SV-3.1d-comskip — impl 5700403e + tests d18a8fe8; REVIEW returned 1 low finding (stale wiring comment); FIX f44bf5da (comment corrected); re-review waived (finding was a verbatim comment-only correction, no behavioral surface; reviewer had fully vetted code). Tests 70/70 green, phpstan L9 clean.
- DVR stack (SV-3.1 a/b0/c/d/e + rowquery + comskip) now CODE-COMPLETE. OWED: Docs cycle (batched) + on-box end-to-end record→complete→playable+markers verify (start.php/Timer/real comskip outside CI).
- Server active: SV-4.2 (X1 server-STOP half) IN PROGRESS.

## Implementer — SV-4.2 (detached-ffmpeg cancellation + apply transcode_timeout, [S-F23], X1 server-STOP half) — 2026-07-12

Closed the three re-audit GAPS (worklog :2176): (1) the on-demand PER-SEGMENT encode
path (`FfmpegRunner::launchDetachedSegment`) had NO `timeout` and NO PID tracking — the
exact scrub-storm orphan; (2) no kill of an abandoned encode; (3) `RelayConsumer::onHttpCancel`
did not kill the ffmpeg. The whole-file/CMAF `buildDetachedCommand` + `Recorder` SIGTERM→SIGKILL
were already done in the earlier SV-4.2 pass and are untouched.

### Files changed
- **NEW `src/Media/Transcoding/SegmentProcessRegistry.php`** — per-worker in-memory map
  `cancelKey => [pid,...]` with `register/release/kill/registeredKeyCount/pidsFor`. `kill()`
  is coroutine-safe (SIGTERM, bounded hrtime grace wait via `Coroutine::sleep` when
  `getCid()>0` else `usleep`, then SIGKILL if still alive) and drops the entry (no leak).
  Signal-sender + liveness-probe are injectable so tests spawn/kill no real processes;
  defaults use `posix_kill` with a `kill` shell fallback. Bounded by the release-in-`finally`
  contract (resident-memory discipline).
- **`FfmpegRunner.php`** — new `?SegmentProcessRegistry $segmentRegistry` (setter-injected,
  mirroring `setConfig`, so the ctor signature/tests are unchanged). `launchDetachedSegment`
  now builds via new **public** `buildDetachedSegmentCommand()` which wraps the atomic-publish
  chain (`encode && mv || rm`) in `timeout <transcode_timeout> sh -c` (from the existing
  `getTranscodeTimeout()`, config `config/ffmpeg.php` `transcode_timeout`=7200) and registers
  the spawned PID under the cancel key. `startSegmentEncode()` gained an optional
  `?string $cancelKey` (defaults to `$outFile`). Added `releaseSegmentProcess()` /
  `killSegmentProcess()` delegators.
- **`TranscodeManager.php`** — both `ensureSegment` launch sites now pass `$final` as the
  cancel key; both `finally` blocks now, for the encode WE launched, `releaseSegmentProcess($final)`
  when the segment published (already exited) or `killSegmentProcess($final)` when it did not
  (timed-out/hung/abandoned) — the wait-timeout orphan killer. `timeout <n>` on the child is
  the outer backstop.
- **`RelayConsumer.php`** — setter `setSegmentProcessRegistry()`; `onHttpCancel` now calls
  `registry?->kill((string)$channelId)` before `closeLocalConnection()`. NOTE documented in
  code: the relay-tunnel worker is a separate process from HTTP workers, so for cross-process
  proxied traffic the *effective* abort remains `closeLocalConnection()` (→ the HTTP worker's
  poll-loop wait-timeout kill); the registry kill covers same-worker traffic (the relay fork's
  own `RelayRequestDispatcher` DOES launch encodes in-process) and is the request-keyed hook.
- **`TranscodeServicesProvider.php`** — `SegmentProcessRegistry` registered as a per-worker
  DI singleton; injected into `FfmpegRunner` via the setter. Central provider = shared by BOTH
  `public/index.php` and `start.php` (both call `ContainerFactory::create`), so no
  dual-entrypoint duplication needed.
- **`start.php`** — relay-tunnel worker wires the registry into the `RelayConsumer`.

### Acceptance mapping
- *Wrap detached segment encode in `timeout <n>` from `transcode_timeout`* → `buildDetachedSegmentCommand`
  (test: `FfmpegRunnerTest::testBuildDetachedSegmentCommandWrapsInTimeout` / `...OmitsTimeoutWhenZero`).
- *Register spawned PID keyed for cancel* → `SegmentProcessRegistry` + `launchDetachedSegment`
  registration (tests: `SegmentProcessRegistryTest`, `FfmpegRunnerTest::testSegmentProcessLifecycleDelegatesToRegistry`).
- *On wait-timeout, kill the tracked PID; release on completion; no leak* →
  `TranscodeManagerTest::testEnsureSegmentKillsAbandonedEncodeOnWaitTimeout` /
  `testEnsureSegmentReleasesRegistryOnCompletion`.
- *onHttpCancel kills tracked PIDs* → `RelayConsumerTest::test_http_cancel_kills_tracked_segment_encode`
  (mock signal sender).

### Deferred (noted follow-up sub-step)
- **Client/connection-disconnect immediate kill + request-keyed onHttpCancel effectiveness.**
  Making onHttpCancel/disconnect kill the encode *directly* (rather than via the poll-loop
  wait-timeout) needs the encode registered under a request/connection-scoped cancel key
  (thread the relay request id / a per-connection token through `RequestContext` into the
  transcode cancel key) plus a direct-connection disconnect hook (the HTTP worker uses
  keep-alive `onMessage` only; no per-response `onClose` is wired today). Out of the
  ~2-3 file / ~200 line budget; the wait-timeout kill + `timeout <n>` backstop already bound
  orphan CPU, so this is a latency optimization, not a correctness gap.

### Verification
- `phpunit` (SegmentProcessRegistry/FfmpegRunner/TranscodeManager/RelayConsumer): 150/150 OK.
- Full `--testsuite Unit`: 4973 tests, 0 failures/errors; only 4 PRE-EXISTING warnings
  (`TranscodeManager.php:2188-2191` undefined `color_*` keys, code not touched here).
- `phpstan -c phpstan.neon.dist` on all 5 touched src files: No errors.
- `phpcs --standard=PSR12` src: 0 errors (11 pre-existing line-length warnings in
  TranscodeManager, none on added lines). Test snake_case method-name errors are the
  per-file existing convention and tests are outside the phpcs gate (`phpcs src/`).

## Reviewer (per-step SV-4.2) — 2026-07-12

Scope confirmed in-bounds (SegmentProcessRegistry, FfmpegRunner, TranscodeManager, RelayConsumer, TranscodeServicesProvider, start.php). Verification: `phpunit --filter 'SegmentProcessRegistry|FfmpegRunner|TranscodeManager|RelayConsumer'` = 188 tests OK (4 pre-existing `color_*` warnings, untouched); `phpstan -c phpstan.neon.dist` on all 5 touched src files = No errors. Cancel-key match, `timeout` wrapping, and registry semantics were read directly (tests alone do not settle these).

Findings (most-severe first):

1. **[Medium] Killed segment encode leaks its `.part-*` temp, which then corrupts cap + dedup accounting and can livelock playback.** `TranscodeManager.php:854` / `:1024` call `killSegmentProcess($final)` on wait-timeout, which SIGTERM/SIGKILLs the tracked `timeout` process (group). That terminates the `sh -c 'ffmpeg && mv -f tmp final || rm -f tmp'` chain mid-flight, so the `|| rm -f <tmp>` cleanup NEVER runs when the shell is signalled — the `.part-*` temp survives on disk. Both the global cap (`countInFlightSegmentEncodes()` `TranscodeManager.php:1234`, globs `seg-*.ts.part-*`) and the dedup snapshot (`reconcileInFlightSegments()` `:1273`, same glob) then treat the DEAD encode as still in-flight. Failure scenario: a scrub-storm's killed encodes each leave a corpse `.part-*` that counts against `maxConcurrentSegments` until the LRU sweep (`sweepSegmentCache`/`removeJobDir` `:1467`) evicts the whole job dir — i.e. LONGER than if the encode had run to natural completion — causing spurious 503 (`SegmentBusyException`) for legitimate playback; and a retry for the same `$final` sees the corpse as "in-flight," piggybacks on it, times out doing nothing, and can livelock until eviction. Why it matters: this partially inverts S-F23 (CPU is freed but cap occupancy gets WORSE) and can make a segment permanently unservable in that job dir. Fix: after `killSegmentProcess($final)`, `glob("{$final}.part-*")` + `@unlink()` the orphaned temp(s). Also the `buildDetachedSegmentCommand` docblock (`FfmpegRunner.php:2048-2050`) and the TranscodeManager finally comments (`:850`, `:1023`) claim the `timeout` kill "also removes the .part-* temp via the trailing rm" — that is incorrect; the `rm` only runs when ffmpeg exits nonzero on its own, not when the shell/group is signalled.

2. **[Medium] Wait-timeout kill cannot distinguish an abandoned seek from a slow-but-wanted encode; for encodes slower than `segmentMaxWaitMs` (30 s) it converts "eventually plays" into "killed + restarted."** `TranscodeManager.php:820`/`:854`: the poll loop waits `SEGMENT_MAX_WAIT_MS = 30000` (`:542`, not overridden in the provider) then unconditionally kills the encode if the segment has not published. There is no client-disconnect signal (the implementer's own deferred note), so a segment the client is STILL waiting on — a heavy software 4K/HEVC transcode on a GPU-less box (MEMORY confirms such boxes exist, "no GPU → SW transcode") can exceed 30 s — is killed at 30 s. Pre-SV-4.2 the encode was left running so the client's retry piggybacked on it (via the `.part-*` dedup) and it published progressively. Failure scenario: for a >30 s segment the first call kills at 30 s, the retry re-launches fresh (or, per finding 1, piggybacks on the corpse), and the segment either never completes or burns more aggregate CPU than before. The AC does say "kill tracked PIDs on wait-timeout," so the kill itself is AC-compliant — but combined with finding 1 the interaction is harmful; worth a guard (e.g. only kill when the client is known gone, or clean the temp so a fresh relaunch is possible) or at least an explicit documented risk. Note either way as required by the step brief.

3. **[Low] `RelayConsumer::onHttpCancel` registry kill is keyed on the wrong namespace and is inert (confirmed).** `RelayConsumer.php:1490` calls `registry?->kill((string) $channelId)`, but encodes are registered under the segment path `$final` (`TranscodeManager.php:814`/`:1010` → `FfmpegRunner::launchDetachedSegment` registers under `$cancelKey ?? $outFile` = `$final`). `$channelId` is the relay routing id, never a segment path, so the two key spaces never intersect: `onHttpCancel`'s kill always returns 0 and the `if ($killed > 0)` log never fires. This is the "green but inert" case for criterion 1 — however the implementer explicitly documents it (`RelayConsumer.php:222-229`, worklog "Deferred") and the EFFECTIVE relay abort is `closeLocalConnection()` → the HTTP worker's poll-loop wait-timeout kill, which IS keyed by `$final` and does match. Per the step's criterion-6 guidance (only a finding if the relay cancel path itself is incomplete) this is an acknowledged deferred follow-up, not a hard AC gap: the relay STOP path works via the wait-timeout kill. Flagged so the coordinator/future maintainer knows the `onHttpCancel` registry-kill line is presently dead code and its cancellation effectiveness is entirely coupled to finding 2's wait-timeout path (i.e. bounded by 30 s, not immediate).

4. **[Low] SIGKILL escalation targets the `timeout` wrapper, not ffmpeg; if SIGTERM forwarding fails ffmpeg is orphaned.** The registered PID is the `timeout` process (`nohup` execs `timeout`; `$!` in `buildDetachedSegmentCommand` `FfmpegRunner.php:2117` is that PID). `SegmentProcessRegistry::terminate()` sends SIGTERM to it — GNU coreutils `timeout` forwards that to the child process group, reaching ffmpeg (OK in the normal case). But the SIGKILL escalation (`SegmentProcessRegistry.php:186`) sends SIGKILL to `timeout`, which cannot be caught, so `timeout` dies WITHOUT forwarding and ffmpeg is left orphaned in a now-parentless group with no timer to reap it — it runs to completion. Failure scenario: any case where the graceful SIGTERM did not propagate within the 0.5 s grace, the "forced" kill does not actually reach ffmpeg. Low severity (SIGTERM forwarding normally works). Consider signalling the process group (negative PID) or launching with `timeout -k <grace> -s TERM` so `timeout` self-escalates to the child.

Non-findings verified: dual-entrypoint OK (FfmpegRunner registry wired via the shared `TranscodeServicesProvider` consumed by both `public/index.php` and `start.php`; `RelayConsumer` runs only in `start.php`, so no index.php divergence). Async/resident-memory discipline OK (`SegmentProcessRegistry::cooperativeSleep` guards `getCid()>0`; no blocking `sleep`; posix_kill non-blocking; registry bounded by release/kill-in-`finally`; no request state in static/global — `getTranscodeTimeout`'s `static` caches only immutable config). `timeout` quoting OK (structure identical to the pre-existing `nohup sh -c '<inner>'`, just inserts `timeout <n>`; nested `escapeshellarg` handled correctly). SV-4.1 reservation serializes per-worker launches so the registry key `$final` does not double-register within a worker.

## Fixer (per-step SV-4.2) — 2026-07-12

Fixed ALL 4 reviewer findings coherently. The unifying redesign: the per-request 30 s
poll wait-timeout is NOT abandonment, so it must RELEASE (stop tracking) rather than KILL;
the real kill fires only on genuine abandonment (HTTP_CANCEL), now wired to actually find
the encode. Files changed (absolute):

- **`src/Media/Transcoding/SegmentProcessRegistry.php`** — two-level keying: primary key =
  segment path (`$final`), optional GROUP = relay channel/request id, with reverse links so a
  drop always tears down the group (no leak). New `killGroup($group)` (kills every key a
  channel launched), `releaseAfterWaitTimeout($key)` (release-only; cleans the `.part-*` temp
  IFF the encode is already dead — never kills or touches a live encode's temp), and an
  injectable **temp cleaner** invoked on every `kill()` (globs `{$key}.part-*` + `@unlink`).
  The default signal sender now targets the process **GROUP** (`posix_kill(-$pid,$sig)`), and
  liveness still probes the leader. `registeredGroupCount()` added for leak assertions.
  → **finding #1** (temp cleaned on kill; docblock corrected), **#3** (group kill), **#4**
  (group-signal reaches ffmpeg, not just the wrapper).
- **`src/Media/Transcoding/FfmpegRunner.php`** — `buildDetachedSegmentCommand` now emits
  `nohup setsid timeout -k <grace> -s TERM <n> sh -c '<encode && mv || rm>' … & echo $!`.
  `setsid` makes the launched PID a process-group leader (PGID==PID, verified on-box) so a
  cancel can group-signal ffmpeg directly; `timeout -k <grace> -s TERM` makes `timeout`
  self-escalate to SIGKILL of its child group (backstop). New const
  `TIMEOUT_KILL_GRACE_SECONDS`. `startSegmentEncode`/`launchDetachedSegment` gained an optional
  `$cancelGroup` (registered alongside the segment key). New
  `releaseSegmentProcessAfterWaitTimeout()` delegator. Docblock corrected (the `|| rm` does
  NOT run when the chain is signalled). → **findings #4 & #1**.
- **`src/Media/Transcoding/TranscodeManager.php`** — both `ensureSegment` finally blocks now,
  on wait-timeout (`!is_file($final)`), call `releaseSegmentProcessAfterWaitTimeout($final)`
  instead of `killSegmentProcess($final)` — a slow-but-wanted SW 4K/HEVC encode is left to
  finish and publish for the retrying requester. Both launch sites pass
  `RequestContext::getRelayCancelGroup()` as the cancel group. → **findings #1 & #2**.
- **`src/Server/Http/RequestContext.php`** — new `KEY_RELAY_CANCEL_GROUP` +
  set/get/clearRelayCancelGroup (canonical per-coroutine store; works in both entrypoints).
- **`src/Hub/RelayConsumer.php`** — `dispatchWithDeadline` now publishes the channel/request
  id as the relay cancel group into `RequestContext` for the dispatch duration (cleared in a
  `finally`; dispatch body extracted to `dispatchWithDeadlineInner`). `onHttpCancel` calls
  `killGroup((string)$channelId)` (was an inert `kill($channelId)`). Property docblock
  rewritten — the relay fork dispatches in-process, so encodes register into the same
  registry singleton and a cancel now finds them by channel id. → **finding #3**.

**Channel→segment key mapping (how resolved):** threaded via `RequestContext` (the plan's
preferred "register under both keys", implemented as a leak-free group index). The relay fork
dispatches HTTP_REQUEST frames IN-PROCESS via its own `RelayRequestDispatcher` sharing the
container's `SegmentProcessRegistry` singleton, so the encode a relayed segment request
launches registers under both `$final` (primary) and the channel id (group). `onHttpCancel`
→ `killGroup(channelId)` → finds + kills + cleans temp. No `RequestContext` threading is
needed in `public/index.php` (direct-LAN requests carry no relay group → group is null →
encode tracked by path only, backward-compatible).

**AC reconciliation:** the AC says "kill on wait-timeout / client disconnect." "Wait-timeout"
is satisfied by the `timeout <transcode_timeout>` encode backstop (which now self-escalates
via `-k`), NOT by the per-request 30 s poll wait (killing there murdered slow-but-wanted SW
encodes — finding #2 — so that path is release-only). "Client disconnect/cancel" is satisfied
by the now-working `onHttpCancel`→`killGroup` path (finding #3).

**Verification:**
- `phpunit --filter 'SegmentProcessRegistry|FfmpegRunner|TranscodeManager|RelayConsumer|RequestContext'`
  = 197 tests, 0 fail (4 pre-existing `color_*` warnings, untouched code).
- Full `--testsuite Unit` = 4982 tests, 0 failures/errors, 5 pre-existing skips.
- `phpstan -c phpstan.neon.dist` on all 5 touched src files = No errors.
- `phpcs --standard=PSR12` = 0 errors (only pre-existing line-length warnings; none on my lines).
- On-box `nohup setsid timeout … & echo $!` confirmed to yield a group-leader PID whose whole
  group (timeout→sh→child) is killed by `kill -TERM -$PID`.

New/updated tests: registry temp-cleanup-on-kill, releaseAfterWaitTimeout (alive=no-kill/no-clean,
dead=clean), killGroup semantics + group-teardown-no-leak; FfmpegRunner command-shape
(`setsid` + `timeout -k -s TERM`) + `releaseSegmentProcessAfterWaitTimeout` delegation;
TranscodeManager wait-timeout is release-only (never kills); RelayConsumer cancel via group +
an end-to-end test that the dispatch publishes the cancel group and a later HTTP_CANCEL kills
the encode launched during dispatch.

## Reviewer (re-review per-step SV-4.2, after fix 3b6c6a3b) — 2026-07-12

Re-reviewed the 4-finding fix by READING the code (not trusting green). Verification: `phpunit --filter 'SegmentProcessRegistry|FfmpegRunner|TranscodeManager|RelayConsumer|RequestContext'` = 197 OK (4 pre-existing `color_*` warnings, untouched); `phpstan -c phpstan.neon.dist` on all 5 touched src files = No errors.

Prior findings — all CONFIRMED genuinely fixed:
- **#3 (killGroup end-to-end, was the inert bug) — FIXED, traced link-by-link.** (a) `RelayConsumer::dispatchWithDeadline` publishes `(string)$requestId` into `RequestContext::setRelayCancelGroup` (coroutine-local `support\Context`), cleared in `finally`; (b) both `TranscodeManager::ensureSegment` launch sites pass `RequestContext::getRelayCancelGroup()` → `FfmpegRunner::startSegmentEncode`→`launchDetachedSegment`→`registry->register($final, $pid, $group)` — registration is synchronous within the same coroutine that set the group, so the value is visible; (c) `onHttpCancel` reads `$frame->channelId()` — the SAME field `onHttpRequest` uses for `$requestId` — and calls `killGroup((string)$channelId)`. Registry is ONE shared instance: `start.php` builds `RelayRequestDispatcher(new Application($container,…), $container)` and `$container->get(SegmentProcessRegistry::class)` for the consumer; `FfmpegRunner`'s factory resolves the SAME `$c->get(SegmentProcessRegistry::class)` — PHP-DI caches `factory()` entries as per-container singletons, so the relay fork's in-process dispatch and the consumer share the registry. Id spaces match; instance shared; cancel is no longer inert.
- **#2 (release-only, don't kill live encode) — FIXED.** Both `finally` blocks call `releaseSegmentProcessAfterWaitTimeout($final)` (not `killSegmentProcess`) on the `!is_file($final)` branch. `releaseAfterWaitTimeout` probes `isAlive` per pid; if ANY alive it drops tracking only and does NOT signal and does NOT touch the temp; only when all dead does it clean the corpse `.part-*`. A signalled/`timeout`-killed encode is dead → temp cleaned; a live slow SW 4K/HEVC encode is left completely alone to publish for the retrying requester. Liveness check has no destructive race (it never acts on the live branch).
- **#1 (killed encode leaked `.part-*`) — FIXED.** `kill()` runs `tempCleaner` (glob `{$key}.part-*` + `@unlink`) after signalling; docblocks corrected (the `|| rm` does NOT run when the chain is signalled). Cap/dedup globs no longer count a killed encode's corpse.
- **#4 (SIGKILL hit the wrapper, not ffmpeg) — FIXED.** Launch is `nohup setsid timeout -k 10 -s TERM <n> sh -c '<encode && mv || rm>' … & echo $!`; `setsid` (invoked from a non-interactive `sh -c` background job, so not a group leader → no fork → PGID==PID==`$!`) makes the tracked pid the group leader; the default signal sender targets the GROUP (`posix_kill(-$pid,…)`) for BOTH SIGTERM and SIGKILL, reaching ffmpeg directly; `timeout -k … -s TERM` is a self-escalating backstop. `posix_kill` is non-blocking (coroutine-safe); the `kill --` shell fallback is guarded behind `!function_exists('posix_kill')`. No double-wrap.

New findings from the fix (most-severe first):

1. **[Low] The `.part-*` temp cleaner can delete a *sibling worker's* live temp for the same segment path, wasting that encode.** `SegmentProcessRegistry::defaultTempCleaner` (`SegmentProcessRegistry.php:414-427`) globs `{$final}.part-*` and `@unlink`s ALL matches. But `TranscodeManager` explicitly tolerates cross-worker duplicate encodes of the same `$final` within the reconcile window (`TranscodeManager.php:770-772`: "A sibling worker's view is eventually-consistent … a missed dedup merely costs one redundant duplicate encode"). Each worker writes a DISTINCT temp `{$final}.part-<random-hex>` (`FfmpegRunner::startSegmentEncode` `:2038`). Failure scenario: worker A (relay) encodes segment X into `X.part-aaa` and worker B (a direct-LAN HTTP worker) concurrently encodes the same X into `X.part-bbb`; client 1 abandons → `onHttpCancel`→`killGroup`→`kill(X)` globs `X.part-*` and unlinks BOTH — so B's still-running ffmpeg loses its output path and its final `mv -f X.part-bbb X` fails, wasting B's encode; client 2's poll then times out and must relaunch. Same collateral hit is possible from `releaseAfterWaitTimeout`'s dead-branch cleaner. Why it matters: the fix converts a previously-benign tolerated race ("one redundant duplicate encode") into one where the sibling's work is destroyed. Severity LOW: it is self-healing (the atomic-publish invariant still holds — the FINAL file is never globbed/deleted, only `.part-*` temps — and client 2 retries and succeeds once A's reservation/temps are gone), and it requires two clients encoding the exact same variant+index within the ~1s cross-worker window (uncommon; the scrub-storm itself is single-client via the one relay worker, where SV-4.1's per-worker reservation prevents same-`$final` duplication entirely). Optional hardening: have the launcher pass the exact `$tmp` path it created to the registry and clean only that specific temp on kill, rather than globbing the whole `{$final}.part-*` family.

Deferred edge (criterion 7) — judged ACCEPTABLE per design, NOT a finding: if a client abandons AFTER the 30s poll wait-timeout has already released the key (encode still running), `killGroup` finds nothing and the still-running encode falls back to the `timeout <transcode_timeout>` backstop. This is the documented "wait-timeout is not abandonment" trade-off; the `timeout -k -s TERM` wrapper bounds the orphan CPU, and the common abandonment case (HTTP_CANCEL arriving while the request/poll is still in flight, key still registered) is killed promptly. Acceptable.

Non-findings verified: RequestContext threading is leak-free (set/`finally`-clear, coroutine-local `support\Context`; concurrent relayed requests don't cross-contaminate; direct-LAN `index.php` never sets it → null group → path-only tracking, back-compat). Dual-entrypoint parity OK (RequestContext under `src/Server/Http` reaches both entrypoints; registry via the shared `TranscodeServicesProvider`; `RelayConsumer` is `start.php`-only). No SV-4.1 regression (reservation logic untouched). Hub/shared wire contracts untouched.

Count: 1 finding (Low).

## Implementer (re-review fix per-step SV-4.2 — precise-temp cleanup) — 2026-07-12

Resolved the single Low re-review finding: the `.part-*` temp cleaner could delete a
*sibling worker's* LIVE temp for the same final segment path. Now each launcher's OWN
`.part-<hex>` temp is cleaned, never the `{$final}.part-*` family.

Files changed (absolute):
- `/home/sites/phlix/phlix-server/src/Media/Transcoding/SegmentProcessRegistry.php`
  - Added `private array $tmpsByKey` (key => list of the exact `.part-<hex>` temps THIS
    launcher created).
  - `register()` gained an optional 4th param `?string $tmp`; a non-empty tmp is recorded
    under the key. Back-compat: default null, existing 3-arg calls unaffected.
  - `kill()` and `releaseAfterWaitTimeout()` now snapshot `$tmpsByKey[$key]` before `drop()`
    and clean ONLY those specific temps (via new private `cleanTemps()`), instead of
    `glob({$key}.part-*)`.
  - `drop()` also unsets `$tmpsByKey[$key]`.
  - `defaultTempCleaner` signature is now `fn(string $tmp): void` — `@unlink($tmp)` guarded
    by `file_exists`, NO glob. (Dropped a redundant `is_string` guard the param type already
    guarantees — phpstan `function.alreadyNarrowedType`.)
  - Docblocks updated (class comment, tempCleaner property, register, kill).
- `/home/sites/phlix/phlix-server/src/Media/Transcoding/FfmpegRunner.php`
  - `launchDetachedSegment()` threads its `$tmp` into `register($cancelKey ?? $outFile,
    $childPid, $cancelGroup, $tmp)`. `startSegmentEncode` already generated `$tmp` and passed
    it down — no signature change needed there.
- `/home/sites/phlix/phlix-server/tests/Unit/Media/Transcoding/SegmentProcessRegistryTest.php`
  - Injected cleaner now records the tmp path (not the key); existing kill/release/killGroup
    temp-cleanup tests register with an explicit `.part-<hex>` tmp and assert that exact path
    is (or is not) cleaned.
  - NEW `test_kill_with_default_cleaner_removes_only_own_temp_not_sibling`: creates two real
    files `X.part-aaaaaaaa` (own) + `X.part-bbbbbbbb` (sibling's live temp) for the same
    final `X`, registers only the own temp, kills, and asserts the own temp is removed, the
    sibling temp SURVIVES, and the final `X` is never touched. Exercises the REAL default
    cleaner.

Kill semantics, keys, release-only wait-timeout, killGroup, setsid/`timeout -k`, atomic
publish (from 3b6c6a3b) all unchanged. SV-4.1 reservation untouched. Hub/shared untouched.
Register param is optional → no dual-entrypoint (index.php/start.php) divergence; registry
still wired via the shared `TranscodeServicesProvider`.

Verification:
- `phpunit --filter 'SegmentProcessRegistry|FfmpegRunner|TranscodeManager|RelayConsumer'` →
  198 OK (4 pre-existing risky warnings, untouched).
- `phpunit --testsuite Unit` → 4983 tests, 38746 assertions, 5 skipped, 0 failures.
- `phpstan analyze -c phpstan.neon.dist` on both touched src files → No errors.
- `phpcs --standard=PSR12` → SegmentProcessRegistry.php clean; FfmpegRunner.php 1 pre-existing
  120-char warning at line 838 (unrelated to this change).

## Orchestrator — SV-4.2 DONE (2026-07-12, perf-5)
- [x] SV-4.2 detached-ffmpeg cancellation + transcode_timeout COMPLETE. impl 470111ad + tests 9fa01804; REVIEW (4 findings: 2 Med temp-leak/kill-slow-wanted, 2 Low) → FIX 3b6c6a3b (release-only wait-timeout, real killGroup via RequestContext, temp cleanup, setsid+`timeout -k -s TERM`); RE-REVIEW (1 Low: family-glob temp cleaner) → FIX 3c73fe0a (unlink only launcher's own .part). Full Unit 4983/0, phpstan L9 clean.
- X1 chain code-complete: UI-0.3 (prior) + SV-4.2 (server STOP) + HB-4.9 (hub cancel metric + shared doc). OWED: on-box scrub→HTTP_CANCEL→ffmpeg-killed round-trip verify.
- Server next: SV-4.5 (Roku/HdHomeRun/MusicBrainz blocking-I/O → async) IN PROGRESS.

## Re-audit (perf-5) — SV-4.8–4.12/4.14 (all committed together in 015ea7a7; worklog's c8f94c04 hash was WRONG/not in history)
- SV-4.8 Router static-path fast map + DI — **DONE** (real O(1) $staticRoutes map consulted before regex; string handlers resolved via container->get, index.php path only registers instances so never hits `new $class()`). GAP: DI-resolution branch + static-map-served assertion untested.
- SV-4.9 Migration ledger — **NOT-DONE (INERT).** `migrations/076_schema_migrations.sql` creates the table but `grep schema_migrations src/ scripts/ bin/` = ZERO hits; MigrationRunner still applies EVERY .sql every boot (its own docblock says "no migration-tracking table"). 076 header falsely claims run-migrations.php consults it. → COMPLETE: write+consult ledger (INSERT ON DUP KEY after clean apply; skip name+checksum match; log checksum divergence; bootstrap-create/order 076 first; keep error-squelch as safety net).
- SV-4.10 provider-priority single-source + hrtime — **PARTIAL.** hrtime DONE (MediaScanner:420/432, no microtime left). Provider-priority NOT attempted: MetadataManager.php:61-68 hardcodes providerPriority (movie=[tmdb,local]/series=[tvdb,fanart,local]) DIVERGING from config/metadata.php:33-37 (movie=[tmdb,imdb]/series=[tmdb,imdb]); ctor never loads config; LIVE via getProvidersForType→refreshItemMetadata (MusicLibraryManager:291). (A 3rd config subsystem PriorityConfig/SourceRegistry exists for LibraryMetadataMatcher but doesn't unify MetadataManager.) → COMPLETE: load priority from config/metadata.php as single source.
- SV-4.11 PluginCatalogService async+docblock+adjacent — **PARTIAL.** docblock DONE (:414-417/:457-458 correct); asyncFetch wired. ADJACENT SV-0.4-CLASS BUG PRESENT: defaultFetcher() (:436-446) gates on `WorkerContext::isEventLoopRunning()` NOT `inCoroutine()`; asyncFetch does `new Swoole\Coroutine\Channel` + pop() with no getCid guard → when loop running but caller not in coroutine (getCid==0, the common plugin-auto-update-worker case) pop() returns false immediately → spurious "async fetch timed out". → COMPLETE: change gate to `WorkerContext::inCoroutine()`; add coroutine wake+timeout test; delete stale SWOOLE_HOOK_NATIVE_CURL test comment.
- SV-4.12 stale-job reaper glob — **DONE** (reapStaleRunningJobs :2913-2916 merges chunk-*.m4s + seg-*.ts). GAP: seg-*.ts keep-branch untested.
- SV-4.14 phantom self::transcode() docref — **DONE** (now self::probe() @:735; encoder-map hevc_cl/h264_d3d11va/h264_dxva2 KEPT per plan). NOTE: a NEW stale docref `self::buildHlsCommand()` survives at FfmpegRunner.php:594 (buildHlsCommand was removed by SV-4.13) → fold into SV-4.13-finish.

### Server COMPLETE queue (post SV-4.5): SV-4.9 (ledger, inert-highest) → SV-4.11 (Channel gate fix) → SV-4.10 (provider-priority config) → SV-4.4 (webhook reconcile) → SV-3.1 f/g/h → SV-3.6 (Trakt) → SV-4.13-finish (+ the :594 docref) → SV-4.8/4.12 test-gap top-ups. Plus W0/W1/W2 re-audits pending.

## Re-audit (perf-5) — SV-0.6–0.9
- SV-0.6 TMDB collections UUID-as-int (ad6d6d86) — **DONE (code).** syncCollectionForMovie(string)/removeCollectionMembership(string); MediaScanner:1383 (int) cast GONE; other (int) casts are on real tmdb_id/part.id (correct). GAP: NO tests at all (no CollectionServiceTest; plan-mandated UUID-not-"0" unit + membership integration missing).
- SV-0.7 marker worker supervision (46c71440) — **DONE.** config/process.php:54-58 marker-detection enabled count=1 poll=30; start.php:606/610-649 launches BackgroundDetectorWorker under runAll; ::start arms Timer→runOnce→dequeueShow. GAP: no config test + no enqueue-smoke test.
- SV-0.8 path_hash reads + stop re-probing (510c8761) — **PARTIAL.** findByPath single-row DONE (library_id+path_hash+path tiebreak, index-friendly). Batch-reprobe threading DONE (processScanBatch→findPathsMap once→processFile callerConfirmedAbsent=true, no per-file re-probe). DEFECT: `findPathsMap` (ItemRepository:342-344) `WHERE path_hash IN (...)` OMITS library_id → composite idx (library_id,path_hash) leftmost-prefix can't be used → hot batch path likely FULL-SCANS. + no real-MySQL/EXPLAIN coverage (BrowseIndexUsageTest never checks path_hash). → COMPLETE: add library_id=? to findPathsMap (or standalone path_hash index) + query-plan integration test.
- SV-0.9 generateThumbnailBatch escaping (1dbdf97c) — **PARTIAL.** Escaping DONE (-ss %d (int), no escapeshellarg on numeric). DEFECT: batch builds `ffmpeg {seekArgs} -i input` with all `-vframes/output` groups BEFORE `-i` → malformed (outputs before inputs) → renders NO thumbnails. LATENT (only caller MediaAssetGenerationJob:124 passes scalar, never array) but broken if wired. No test touches the batch method. → COMPLETE: fix to per-output `-ss` output-side OR repeated `-ss N -i input` blocks + command-shape test.

### Updated server COMPLETE queue: SV-4.9 (ledger) → SV-4.11 (Channel gate) → SV-0.8 (findPathsMap library_id) → SV-4.10 (provider-priority) → SV-0.9 (batch cmd) → SV-4.4 (webhook) → SV-3.1 f/g/h → SV-3.6 (Trakt) → SV-4.13-finish(+:594 docref) → TEST top-ups (SV-0.6/0.7/4.8/4.12). W1/W2/W3.3-3.4 re-audits still pending.

## Implementer — SV-4.5 (Roku/HdHomeRun/MusicBrainz blocking-I/O → coroutine-aware async) — 2026-07-13

NOT-DONE verdict cleared. All four audited targets converted; reused the shared
`Phlix\Common\Runtime\WorkerContext` guard (`isEventLoopRunning()` + `inCoroutine()`)
— the SAME coroutine-vs-blocking decision every async client here uses — so no new
inconsistent (always-false) guard was introduced.

Files changed (src):
- `src/Media/Metadata/Provider/MusicMetadataProviderTrait.php` — `rateLimit()` rewritten
  from a PER-INSTANCE `$lastRequestTime` to a **static-per-host** limiter:
  `private static array $hostLastRequestTime` keyed by the provider's `BASE_URL` host
  (derived via new `rateLimitBucket()` — `parse_url(...HOST)`, lower-cased; falls back to
  class name). SHARED across instances of a provider (trait-statics are per-using-class,
  so all MusicBrainzProvider instances share one bucket; keyed-by-host so distinct APIs
  stay independent). **BOUNDED**: `RATE_LIMIT_HOST_CAP = 64` with unset+reassign LRU touch
  + `array_key_first` eviction of the oldest host once the map exceeds the cap. The wait is
  coroutine-aware via new `rateLimitSleep()` (`WorkerContext::inCoroutine()` →
  `\Swoole\Coroutine::sleep()`, else blocking `usleep`).
- `src/Roku/RokuEcpClient.php` — `usleep(500000)` (channel-launch fallback) → `coroutineAwareSleep(0.5)`;
  `get()`/`post()` now route through a new `fetch()` that chooses `fetchAsync()`
  (workerman/http-client + `Swoole\Coroutine\Channel` cooperative wait) vs `fetchBlocking()`
  (stream `file_get_contents`) via new protected `preferAsyncHttp()` seam.
- `src/Roku/RemoteRokuClient.php` — the `usleep(500000)` no-Timer fallback → `coroutineAwareSleep(0.5)`;
  the three `file_get_contents` sites (deferred-play, direct POST, `relayLaunchChannel`)
  collapsed into `httpPost()` → `httpPostAsync()` / `httpPostBlocking()` chosen via
  protected `preferAsyncHttp()`.
- `src/LiveTv/Tuners/HdHomeRun/HdHomeRunTunerDriver.php` — `scanChannels()` `usleep(500000)`
  → new `coroutineAwareSleep(0.5)`.

Tests (added/updated):
- NEW `tests/Unit/Media/Metadata/Provider/MusicMetadataRateLimitTest.php` (+ fixture
  `RateLimitTraitFixture.php`): bucket-key = host; **state SHARED across two instances**
  for the same host (second instance waits out the window); first-request no-wait; **map
  BOUNDED** (prefill cap, one add → one LRU eviction of the oldest, count stays at cap);
  in-coroutine wait **yields** the loop (sibling coroutine interleaves during the wait →
  proves `Coroutine::sleep`, not blocking `usleep`).
- NEW `tests/Unit/Roku/RemoteRokuClientTest.php` (+ seam `RemoteRokuClientAsyncSeamStub.php`):
  `preferAsyncHttp()` false outside a coroutine (blocking chosen); `httpPost()` routes to
  the async transport when the async decision is active (blocking NOT called); coroutine
  sleep yields.
- `tests/Unit/Roku/RokuEcpClientTest.php` — added the same three assertions (blocking-outside-coroutine,
  async-routing-when-preferred, cooperative sleep).
- `tests/Unit/LiveTv/Tuners/HdHomeRun/HdHomeRunTunerDriverTest.php` — added the cooperative-sleep yield test.
- Updated `MusicBrainzProviderTest`/`AudioDbProviderTest` rate-limit tests: reflect the new
  static `hostLastRequestTime` (musicbrainz.org / theaudiodb.com buckets) instead of the
  removed per-instance property.

Verification: relevant dirs green (87/87); full `--testsuite Unit` **4995 tests, 0 errors /
0 failures** (4 pre-existing TranscodeManager color-key warnings + 5 skips are environmental);
`phpstan -c phpstan.neon.dist` **0 errors**; `phpcs --standard=PSR12` clean on all changed files
(pre-existing snake_case method-name warnings in the two provider tests are unrelated/note-and-continue).

Deferral note: RokuEcpClient/RemoteRokuClient async transport only activates under a live
worker+coroutine (`WorkerContext::isEventLoopRunning()` gate) exactly like MetadataHttpClient/
WebhookHttpClient; the blocking stream path remains for CLI/tests. The MB limiter's trait-static
is per-using-class (shared across instances of a provider, keyed by host) — a single provider
class per remote host, so this fully addresses the "concurrent instances collectively violate"
finding; a truly cross-class global map was intentionally not added (would need a new shared file,
out of the 4-file scope).

## Orchestrator — SV-4.5 implemented (2026-07-12/13, perf-5)
- [x] SV-4.5 async I/O — impl f1576377, REVIEW NO FINDINGS (Channel gated on isEventLoopRunning()&&inCoroutine(); MB static-per-host LRU cap64; swoole-ON coroutine tests execute). Full Unit 4995/0. DONE (docs batched).

## Reviewer (per-step SV-4.5) — 2026-07-13

Reviewed commit `f1576377` against the S-F15/S-F16 findings, the SV-4.5 acceptance,
and the highest-priority Channel-gate bug class (SV-0.4/SV-4.11). Read the actual
gates (not just green tests); ran the targeted suite (98/98, swoole ON so the
coroutine-yield tests genuinely execute, not skip) + phpstan (0 in-context).

Criterion-by-criterion:
1. **Channel gate — CORRECT.** `RokuEcpClient::preferAsyncHttp()` (:368-371) and
   `RemoteRokuClient::preferAsyncHttp()` (:224-227) both return
   `WorkerContext::isEventLoopRunning() && WorkerContext::inCoroutine()`. The
   `Swoole\Coroutine\Channel::pop()` async path (`fetchAsync`/`httpPostAsync`) is
   reachable ONLY when `getCid()>0`. NOT the isEventLoopRunning()-alone defect.
2. **coroutineAwareSleep / rateLimitSleep — CORRECT.** All three sleeps
   (RokuEcpClient:448, RemoteRokuClient:297, HdHomeRunTunerDriver:coroutineAwareSleep,
   trait rateLimitSleep:128) gate `Swoole\Coroutine::sleep()` on `inCoroutine()`,
   blocking `usleep` only outside a coroutine — never a blocking usleep in-coroutine.
3. **MB static-per-host limiter — CORRECT.** `private static array $hostLastRequestTime`
   shared across instances of a using-class (proven by testStateIsSharedAcrossInstances);
   keyed by lower-cased `BASE_URL` host; LRU-bounded at cap 64 with unset+reassign touch
   + array_key_first eviction (testHostMapIsBoundedWithLruEviction proves count stays at
   cap and the oldest host is evicted). Two using-classes (MusicBrainzProvider→musicbrainz.org,
   AudioDbProvider→theaudiodb.com) hit DISTINCT hosts, so the PHP per-using-class trait-static
   causes no double-spend — acceptable per the finding's guidance.
4. **Blocking path preserved — CORRECT.** fetchBlocking/httpPostBlocking retained and
   selected outside a coroutine (testPreferAsyncHttpIsFalseOutsideCoroutine).
5. **No new loop-blocking calls; workerman/http-client usage matches the sibling async
   clients' Channel(1)+push-on-success/error+pop(timeout) pattern.** No exit/die, no
   request state in statics beyond the bounded host map.
6. **Tests exercise the coroutine branch** (swoole present → the yield tests run and pass)
   and the shared-limiter + bounded-map behaviors.

NO FINDINGS

## ON-BOX VERIFY (perf-5, 2026-07-13) — server @3c73fe0a (pre SV-4.5/4.9)
PASS: migrations 077 applied; SV-4.7 WS auth on :8097 (token-less rejected / valid JWT accepted); SV-0.5 WS ping/reaper (pinged ~30s, reaped at t+77s per limit); >64KB bodied-relay round-trip.
CANNOT-VERIFY: DVR end-to-end (livetv_tuners 0 rows, no tuner/hardware); QSV hwupload (box is a QEMU VM, bochs-drm, not Intel HW — boot probe chose software/libx264, VAAPI/NVENC correct).
⚠️ X1 direct-path FAIL: direct (non-relay) transcode client killed mid-encode → ffmpeg alive 7.4s+ (only `timeout 7200` backstop). ROOT CAUSE: `FfmpegRunner::killSegmentProcess()` has NO caller anywhere; only `killGroup()` (relay HTTP_CANCEL via RelayConsumer) is wired. Direct-LAN disconnects get no cancellation. → FOLLOW-UP SV-4.2-disconnect: wire killSegmentProcess to a direct-connection disconnect/abort hook (was the deferred piece; on-box proves real orphan-CPU impact).

## Re-audit (perf-5) — SV-1.7–1.10
- SV-1.7 range parser reuse — **DONE.** HttpHandler:752-766 uses TranscodeFileServer::parseRange (anchored, over-long clamp→206, suffix bytes=-N). Covered via HlsControllerTest/ThemeMediaStreamControllerTest (shared parser). Minor: direct serveMediaStream path itself untested (same static method).
- SV-1.8 CSRF Origin exact-match (SECURITY) — **DONE (code).** RequestAuthenticator:223-244 hostsMatch exact compare, no str_ends_with; host:port handled; gate HttpHandler:126-140 (cookie-auth POST/PUT/DELETE/PATCH → 403 csrf.invalid_origin). GAP: ZERO CSRF tests (no RequestAuthenticatorTest) — plan mandated suffix-attack-rejected/port-accepted/cross-origin-rejected. → TEST. (Minor: no configured trusted-origins list, only own Host — fine for same-origin.)
- SV-1.9 ENOSPC guard — **DONE (code+wired).** TranscodeManager:1461-1484 ensureDiskSpace (disk_free_space vs minDiskSpaceBytes default 500MiB) called before startSegmentEncode @812/@1032; HlsController:147-156 catches SegmentCacheFullException→sweep+503+Retry-After. GAPS: ZERO ENOSPC tests; threshold NOT surfaced in config (TranscodeServicesProvider doesn't pass minDiskSpaceBytes; hardcoded default). → TEST + optional config key.
- SV-1.10 login rate limiter bound — **NOT-DONE (INERT).** DbLoginRateLimitStore.php (bounded, DELETE LIMIT 100 sweep) + migration 074 exist but store NEVER injected: AuthServicesProvider:167-175 autowires AuthManager naming logger/eventDispatcher/db/statsCollector/settingsRepository but NOT loginRateLimitStore → stays null → prod uses UNBOUNDED per-worker static $rateLimitStore (no sweep/LRU/cap; still ×workers). grep: zero DbLoginRateLimitStore refs outside AuthManager+its own file. Tests only hit the static fallback. → COMPLETE: add ->constructorParameter('loginRateLimitStore', get(DbLoginRateLimitStore::class)) in AuthServicesProvider; confirm mig 074 deploy; bound-or-test-restrict the static fallback; add DbLoginRateLimitStore bound/sweep test + prod-wiring test. (Parallels hub HB-4.6 login per-worker weakening — this DB store IS the shared/bounded fix on the server side.)

### Server queue refined: SV-4.9(running) → SV-4.11(Channel gate) → SV-1.10(wire DB login store, inert-security) → SV-0.8(findPathsMap library_id) → SV-4.10(provider-priority) → SV-0.9(batch cmd) → SV-4.4(webhook) → SV-3.1 f/g/h → SV-3.6(Trakt) → SV-4.13-finish(+:594) → SV-4.2-disconnect(wire killSegmentProcess) → TEST top-ups (SV-0.6/0.7/1.8-CSRF/1.9-ENOSPC/4.8/4.12). Audits still owed: SV-1.1–1.6, SV-2.2/2.3/2.7(spawning), SV-3.3/3.4.

## Implementer — SV-4.9 (wire schema_migrations ledger) — 2026-07-13
Verdict was NOT-DONE (INERT): `migrations/076_schema_migrations.sql` created the table but nothing
read/wrote it; `MigrationRunner` re-applied every `.sql` every boot. Now wired.

Files changed:
- `src/Common/Database/MigrationRunner.php` — ledger consult + record:
  - `run()`: after resolving the connection (and early-returning if there are no files, so the
    empty-dir test still issues zero queries), bootstrap `CREATE TABLE IF NOT EXISTS schema_migrations`
    (new `ensureLedgerTable()`), then `loadLedger()` (`SELECT name, checksum`). Per file: compute
    `md5($contents)`; if recorded+checksum-match → SKIP without executing (bump `skipped_count`, add a
    "<file> already applied (ledger), skipping" note); if recorded+checksum-DIVERGED → log WARNING and
    re-apply (honours the re-run-safe contract, does NOT hard-fail); un-recorded → apply as before.
    After a CLEAN apply (`$fileHadGenuineError` false — idempotent notes are NOT failures) call
    `recordMigration()` = `INSERT ... ON DUPLICATE KEY UPDATE` with `[name, time(), checksum]`
    (positional `?` binding, Workerman\MySQL Connection — no raw PDO). A file with a genuine error is
    left UNRECORDED so it re-runs next boot.
  - New private helpers `ensureLedgerTable()`, `loadLedger()`, `recordMigration()` each swallow their
    OWN exceptions (log-and-continue) so a ledger failure degrades to the historical apply-every-file
    path, never aborts a run. `isAlreadyAppliedNote()` error-squelch KEPT as the secondary safety net.
  - Class docblock corrected (removed the false "no migration-tracking table" claim).
- `migrations/076_schema_migrations.sql` — header corrected: `@see` now points at
  `MigrationRunner::run()` (consults AND records, bootstrap-creates, warns+re-applies on divergence),
  and the rewrite-class guidance now states the runner auto-records (manual INSERT optional).
- `tests/Unit/Common/Database/MigrationRunnerTest.php` — added `connectionWithLedger()` helper that
  handles the new bookkeeping queries inertly; updated the 4 capture/count tests to route through it;
  added 5 ledger tests: (a) clean apply records row w/ name+md5; (b) recorded+match SKIPPED, not
  executed; (c) divergence WARNS + re-applies + refreshes checksum; (d) empty-ledger-but-applied
  transition re-applies safely (idempotent note) + backfills; (e) bootstrap CREATE precedes the read.
  NB: mock callbacks use `$params = null` (not `array`) because Workerman `Connection::query(...)`'s
  2nd arg default is `null` — a typed `array` param throws TypeError under PHPUnit ReturnCallback.

Transition safety (documented in a code comment in `run()`): on the current live boxes the table
exists but the ledger is EMPTY; the SELECT returns no rows → every file treated as un-recorded →
re-applied (safe via each migration's `IF NOT EXISTS` + the duplicate-error squelch) → then recorded.
Subsequent boots skip unchanged files. Divergence = WARN + re-apply + refresh (never hard-fail).

Verification: `phpunit --filter Migration` 22/22 · full `--testsuite Unit` 5000 tests, 38795
assertions, 5 skipped, 0 failures · `phpstan analyze -c phpstan.neon.dist src/Common/Database/MigrationRunner.php`
No errors · `phpcs --standard=PSR12` on both touched files clean. Both callers
(`scripts/run-migrations.php`, `bin/phlix migrate`) unchanged.

## Orchestrator — SV-4.9 implemented (2026-07-13, perf-5)
- [x] SV-4.9 ledger — impl 1788ad35; REVIEW 3 findings (deploy-safety SAFE); FIX e9a461fe (quiet deploy log + degrade/unrecorded tests + checksum normalize). 27/27 Migration, full Unit 5007/0. DONE.

## Re-audit (perf-5) — SV-2.2/2.3/2.7
- SV-2.2 pool hygiene (PooledMySQLConnection) — **DONE (code, wired; pool_enabled default true).** (a) dirty-txn rollback on defer-release (txPending[cid] set/clear via *Trans(), rollBack before idle push); (b) idle->pop(10.0) timeout→"pool exhausted"; (c) created-- on dead conn (SELECT 1 fail + factory throw); (d) non-coroutine bounded while-poll (no recursion). GAP: 3 core behaviors coroutine-only → UNTESTED in CI (on-box owed). Minor: txPending only via *Trans() family (raw "BEGIN" SQL wouldn't flip — codebase uses *Trans, low risk).
- SV-2.3 relay backpressure (RelayConsumer) — **PARTIAL.** DONE: hub→local onData (send()===false→pauseRecv+drain→resumeRecv) + HTTP_RESPONSE withFile path (proper while loop). NOT DONE: local→hub direction — sendDataFrame:1712-1720 / sendFrame:1688-1697 IGNORE send() return (fire-and-forget, unbounded queueing = the exact S-F36 large-media case); AND onLocalData:1596-1600 still `do…while` (emits empty DATA frame on zero-length read). No pause/resume test (MockConnection::send always true). → COMPLETE: check send() in sendDataFrame/sendFrame→pauseRecv localConnections[$channelId]+resumeRecv on drain; do…while→while; slow-reader local→client test.
- SV-2.7 auth status cache (AuthManager) — **PARTIAL.** Cache exists + genuinely consulted (validateAccessToken:898, refreshTokens:837; 5s TTL hrtime) — primary AC met. NOT DONE: invalidateUserStatusCache():231-234 has ZERO callers (revocation = TTL-only; in-worker status change not reflected until TTL); cache UNBOUNDED (refresh-on-read only, no cap/LRU); no cache/revocation tests. → COMPLETE: call invalidate from in-process status-change paths (or document TTL-only + remove dead method); bound/LRU cache; cache-hit/expiry + revocation-within-TTL tests.

### Server queue += SV-2.3(backpressure local→hub), SV-2.7(invalidate+bound). Audits still owed: SV-1.1–1.6 (transcoding), SV-3.3/3.4 (build-outs).

## Reviewer (per-step: SV-4.9) — 2026-07-13

Verified against commit `1788ad35`. Migration suite 22/22 green; phpstan L9 (`-c phpstan.neon.dist`)
clean on `MigrationRunner.php`. Both callers wire correctly: `scripts/run-migrations.php:21-22` and
`bin/phlix:97` construct the runner with `ConnectionPool::getConnection('mysql')` → the re-keying
`PhlixMySQLConnection` subclass, so the positional `[$name, time(), $checksum]` binding in
`recordMigration()` is the safe idiom (sequential list + `?` placeholders, no raw PDO/mysqli).
`MigrateCommand`'s exit code keys off `errors`, not `applied`, so the changed `applied` semantics
(ledger-skipped files no longer listed) do not break the exit contract.

**Deploy-safety verdict on the empty-ledger transition: SAFE.** I audited every DML/rewrite statement
in `migrations/*.sql` for re-run safety under the first-post-deploy apply-all:
- `004_admin_user_flag.sql:28` UPDATE users — guarded by `AND NOT EXISTS (... is_admin=1)`, no-op on replay.
- `043_media_items_canonical_key.sql:51` / `050_media_items_sort_indexes.sql:125,129` UPDATE — WHERE
  `... IS NULL` + re-derive identical values; idempotent.
- `051_media_item_genres_join_table.sql:116` INSERT IGNORE — PK-guarded.
- `068_metadata_ratings_source_enum.sql` — the `source='user'` UPDATE is a no-op on replay; the
  DROP INDEX → MODIFY → re-ADD UNIQUE sequence net-nulls cleanly within the migration (or is squelched);
  the aggregate backfill re-derives identical values.
Critically, this transition path is byte-identical to the pre-SV-4.9 every-boot apply-all these
migrations already survived — it introduces no NEW re-run risk, then records + skips thereafter. No
migration is unsafe to re-run. The runner's bootstrap CREATE matches `076`'s table definition exactly
(name/applied_at/checksum, PK name, InnoDB utf8mb4) — no schema drift. Degrade path is genuine:
`ensureLedgerTable` swallow → `loadLedger` catch → empty map → apply-all; `recordMigration` failures
swallowed per-file; `076` itself creates the table in the file loop. No crash, no skipped migration.

Findings:

1. **[MEDIUM — deploy-log noise regression, contradicts the documented design intent]**
   `src/Common/Database/MigrationRunner.php:176` pushes a per-file note
   `"<name> already applied (ledger), skipping"` into `notes[]` for EVERY ledger-skipped file, but
   `isAlreadyAppliedNote()` (`:326-332`) recognises only `'Duplicate column name'` / `'Duplicate key
   name'` / `'check that column/key exists'` / `'already exists'` — it does NOT match the string
   `"already applied"`. Both callers print any note that fails `isAlreadyAppliedNote` IN FULL
   (`scripts/run-migrations.php:37-39`, `src/Console/Commands/MigrateCommand.php:59-61`). Net effect:
   once the ledger is populated (steady state — i.e. every deploy after the first), the migration run
   prints ~79 lines `note: NNN_xxx.sql already applied (ledger), skipping` in full AND the
   `"N statement(s) skipped (already applied)"` summary line. This is exactly the per-deploy echo the
   `skipped_count` collapse — documented at `:108-115` ("render a single '...skipped...' summary line
   instead of echoing each on every deploy") — was designed to prevent. Fix: either do not add the
   per-file note to `notes[]` for a ledger skip (rely on `skipped_count` alone), or make the ledger-skip
   note recognised by `isAlreadyAppliedNote()` so the callers collapse it. Not deploy-unsafe, but it
   defeats the stated purpose of the skip-count mechanism and makes every steady-state deploy log read
   as if 79 things happened.

2. **[LOW — test gap vs review criterion 2/7]** The 5 new tests cover record-after-apply, skip-on-match,
   divergence-warn+reapply, empty-ledger transition, and bootstrap-before-select. But NO test asserts
   that a migration raising a GENUINE (non-idempotent) error is left UNRECORDED so it re-runs next boot
   (`if (!$fileHadGenuineError)` at `:227`), nor that a ledger READ failure degrades to apply-all
   (`loadLedger` catch at `:274-279`). Both behaviours are correct by inspection, but the "partially
   applied then errored → unrecorded / re-run safe" contract (criterion 2) and the degrade path are
   unverified by tests.

3. **[LOW — observation, self-healing]** The checksum is `md5()` over the ENTIRE file including header
   comments (`:170`). The `076` rewrite-class protocol explicitly instructs operators to "Document the
   rewrite in this header." Editing a migration's documentation header AFTER it was applied diverges the
   checksum → triggers a WARNING + one re-apply on the next boot (`:185-189`). Harmless (migrations are
   re-run safe) and self-heals (the new checksum is recorded, so it re-applies exactly once), but a mild
   surprise worth a one-line note in the 076 header (e.g. "editing any byte of an applied migration —
   including comments — will trigger a one-time re-apply").

## Orchestrator — SV-4.9 REVIEW (2026-07-13, perf-5): 3 findings (deploy-safety SAFE — empty-ledger re-apply byte-identical to pre-existing apply-all; all migrations re-run-safe, verified)
1. MEDIUM — deploy-log noise: MigrationRunner:176 adds per-file "already applied (ledger), skipping" note but isAlreadyAppliedNote() (:326-332) doesn't match "already applied" → both callers print ~79 full lines every steady-state deploy (defeats note-collapse). Fix: drop the per-file ledger-skip note OR make it match isAlreadyAppliedNote().
2. LOW — test gaps: genuine-error-left-unrecorded + ledger-read-failure degrade path unverified.
3. LOW — md5 over whole file incl comments → a doc/comment edit triggers a one-time re-apply (benign but avoidable; normalize hash to SQL-only or document).
→ FIX all 3 (server writer) AFTER SV-4.11 finishes (one writer per repo).

## Implementer — SV-4.11 (2026-07-13): Channel-gate fix LANDED ✅
The adjacent SV-0.4-class bug is fixed. `defaultFetcher()` async branch now gates on
`WorkerContext::isEventLoopRunning() && WorkerContext::inCoroutine() && !EventLoopTls::requiresBlockingCurl($url)`
(mirrors SV-4.5/S3Client `:284-286`) — was `isEventLoopRunning()` ALONE. Outside a coroutine
(getCid()==0: plugin auto-update worker / plain HTTP handler) it now takes `curlFetch()`, so the
`Swoole\Coroutine\Channel` in `asyncFetch()` is only ever constructed/`pop()`ed with getCid()>0 —
killing the spurious immediate-false "async fetch timed out".

Files changed (absolute):
- `/home/sites/phlix/phlix-server/src/Plugins/Catalog/PluginCatalogService.php` — gate in `defaultFetcher()`
  (renamed local to `$useAsync`, added `inCoroutine()` conjunct + S-F12/SV-0.4 comment); async-routing
  docblock (:407-…) updated to state the mandatory coroutine gate. `asyncFetch`/`curlFetch` bodies untouched.
- `/home/sites/phlix/phlix-server/tests/Unit/Plugins/Catalog/PluginCatalogServiceTest.php` — deleted the stale
  "coroutine-safe (cURL under SWOOLE_HOOK_NATIVE_CURL)" comment; added 3 tests:
  (1) `test_default_fetcher_uses_blocking_curl_branch_outside_coroutine` (@group network) — asserts inCoroutine()
  false on main stack and the error is a cURL-path RuntimeException (NOT "async fetch"), proving no Channel;
  (2) `test_async_fetch_wakes_on_success_callback_inside_coroutine` — inside `Swoole\Coroutine\run`, reflection-invoke
  `asyncFetch` with a mock Workerman client firing `success` → returns the body;
  (3) `test_async_fetch_times_out_cleanly_inside_coroutine` — in-coroutine, no callback → RuntimeException
  "async fetch timed out" AFTER actually waiting ≥900ms (clean timeout, not the immediate false-timeout bug).

Verification: `phpunit --filter PluginCatalog` = 31 tests / 87 assertions OK (timeout test waited full 1s via
Swoole Timer). Full `--testsuite Unit` = 5002 tests OK (4 warnings / 5 skipped all pre-existing TranscodeManager).
`phpstan analyze -c phpstan.neon.dist` clean on both files. `phpcs --standard=PSR12 src/...PluginCatalogService.php`
clean; the test-file phpcs "not in camel caps" + one fixture line-length are the file's pre-existing snake_case
convention (new methods follow it). SV-4.11 Acceptance met.

## Orchestrator — SV-4.11 DONE (2026-07-13, perf-5)
- [x] SV-4.11 — docblock+async were done; FIX cff88617 changed defaultFetcher gate to isEventLoopRunning()&&inCoroutine()&&!requiresBlockingCurl (mirrors SV-4.5/S3Client), killing the spurious async-fetch-timeout; tests: blocking-outside-coroutine + async-wake-in-coroutine + clean-timeout(≥900ms). Full Unit 5002/0, phpstan clean. Accepted w/o re-review (verbatim reviewer fix on an already-reviewed pattern, both branches tested).

## Fixer — SV-4.9 (3 review findings) — 2026-07-13 (perf-5)
Fixed all 3 SV-4.9 review findings on top of impl 1788ad35.

Finding 1 (MEDIUM — deploy-log noise regression): `MigrationRunner::run()` no longer pushes a per-file
"<name> already applied (ledger), skipping" note into `notes[]` for a ledger skip — it only bumps
`skipped_count`. Both callers print any note failing `isAlreadyAppliedNote()` in full, and that string
was never matched, so steady-state deploys were echoing ~79 note lines. Now the callers render only the
single "N statement(s) skipped (already applied)" summary line. `notes` docblock updated. Callers
unchanged (runner-side fix). Verified by new test (h) `testSteadyStateSkipEmitsNoPerFileNotes` (2 recorded
files → 0 executed, 0 notes, skipped_count=2) + updated test (b) assertion (notes now []).

Finding 2 (LOW — test gaps): added (f) `testGenuineErrorLeavesMigrationUnrecorded` — a genuine
non-idempotent error leaves the file UNRECORDED (no INSERT), so it re-runs next boot; (g)
`testLedgerReadFailureDegradesToApplyAll` — the ledger SELECT throwing degrades to the historical
apply-all path (both files applied, no crash).

Finding 3 (LOW — checksum over whole file): chose the PREFERRED normalize option (low-risk). New private
`MigrationRunner::checksum()` strips full-line `--`/`#` comments + per-line trailing whitespace before
`md5()`, so a doc/comment-only edit to a `.sql` no longer flips the checksum → no spurious one-time
re-apply. Normalization is narrow and CANNOT mask a real SQL change (only entirely-comment lines and
trailing whitespace are removed; inline trailing `-- ...` on a statement line and any SQL token change are
preserved). 076 header updated to document this. Verified by (i) `testCommentOnlyEditDoesNotDivergeChecksum`
(comment/ws-only edit → still skipped) + (j) `testRealSqlEditStillDivergesChecksum` (INT→BIGINT → re-applied).

Also fixed a pre-existing phpstan `new.resultUnused` in `testConnectionProviderIsNotCalledAtConstruction`
(assign + assertInstanceOf) at user request.

Deploy-safety preserved: re-run-safe (failed migration unrecorded), empty-ledger transition, and
swallow-and-degrade fallback all intact.

Files changed (absolute):
- /home/sites/phlix/phlix-server/src/Common/Database/MigrationRunner.php
- /home/sites/phlix/phlix-server/migrations/076_schema_migrations.sql
- /home/sites/phlix/phlix-server/tests/Unit/Common/Database/MigrationRunnerTest.php

Verification: `phpunit --filter Migration` 27/27 · full `--testsuite Unit` 5007 tests / 38818 assertions /
5 skipped / 0 failures · `phpstan analyze -c phpstan.neon.dist` (src paths) No errors + clean on both
touched files · `phpcs --standard=PSR12` clean on both touched files.

## Re-audit (perf-5) — SV-3.2/3.3/3.4
- SV-3.2 book/audiobook backends — **DONE** (BookController readBook/progress/download real; AudiobookController streamAudiobook Range-safe 206/416 + signed stream_url; 19+ tests). ★ MUSIC STREAM (X8) = NOT-DONE = the UI-3.6 blocker.
  X8 UNBLOCK (small, producer-first): (1) MusicController::formatTrack/getTrack must MINT a signed `/media/{id}/stream` URL (like AudiobookController:392 `SignedUrl::fromEnv()->mint`) — track row id IS the media_items id, and generic GET /media/{id}/stream (HttpHandler::serveMediaStream, wired :148, Range-safe + signed/session auth) already serves it. ~5 lines unblocks direct-play. (2) FIX audio MIME in HttpHandler::videoMimeFor (add mp3/flac/m4a/aac/ogg/opus/wav → currently octet-stream). (3) optional dedicated /api/v1/music/tracks/{id}/stream mirroring streamAudiobook. (4) GAPLESS INERT: FfmpegRunner::buildGaplessSegmentCommand:2518 + GaplessTranscoder:154 have ZERO callers — wire into a segmented audio path for crossfade/gapless acceptance (not needed for basic playback). (5) codec transcode fallback rides SV-3.3.
- SV-3.3 capability negotiation + loudnorm — **PARTIAL (code DONE+wired, ZERO tests).** X-Phlix-Client-Capabilities→ClientCapabilities→QualitySelector.canDirectPlay default-deny undeclared codecs; loudnorm FfmpegRunner::buildLoudnormFilter wired. GAPS: no tests (negotiation + audio-filter mandated); confirm direct-play playback-info (MediaItemController/WebPortalRouter) also reads capabilities (only TranscodeController entry confirmed); single-audio-track selection only. → TEST + verify playback-info path.
- SV-3.4 artwork cache — **DONE.** ArtworkStorage download+resize [185,342,500,780]+orig; HttpHandler::serveArtwork (route /api/v1/artwork/{itemId}?size=, signed/session, ETag+Cache-Control immutable, withFile); poster_srcset emitted (MediaItemShaper:68-91) + contract test. GAP: no ArtworkStorage download/resize unit test. NOTE: ArtworkController.php is dead/duplicate (unregistered) → §6 removal candidate. Minor: on-box confirm ArtworkStorage DI live.

### Server queue reprioritized: SV-4.9-fix(running) → **X8 music-stream producer (SMALL, unblocks UI-3.6)** → SV-1.10(login store) → SV-0.8(findPathsMap) → SV-4.10 → SV-0.9 → SV-2.3 → SV-2.7 → SV-4.4 → SV-3.1 f/g/h → SV-3.6 → SV-4.13-finish → SV-4.2-disconnect → build-out completions (SV-3.2 gapless, SV-3.3 tests) → test top-ups. Audits owed: SV-1.1–1.6 (spawning 1.1-1.3).

## Re-audit (perf-5) — SV-1.1/1.2/1.3
- SV-1.1 HDR tone-map memoize — **PARTIAL.** Probe storm KILLED: FfmpegRunner::$probeMemo keyed path:mtime (≤1 probe/file/worker); decision resolved once in computeHlsParams (require_hdr_tone_map) threaded via segment_params. AC "≤1 probe" MET. GAPS: (1) mig 073 media_streams color cols are WRITE-ONLY — ensureHlsJob:367 ALWAYS live-probes, never reconstructs decision from persisted cols (so "0 probes if scanned" never achieved, always exactly 1). (2) buildSegmentCommand:1512-1518 re-derives via getToneMappingProfile→probe (relies on memo, not threaded flag+filter string). (3) no probe-count/session test. → LOWER PRIORITY completion (read from media_streams to reach 0 probes + thread filter string + probe-count test).
- SV-1.2 non-probe ffmpeg coroutine — **DONE (minor).** thumbnails/subtitle/trickplay/hwaccel-probe route via runCoroutineAware{Command,ShellExec} (Coroutine\System::exec under cid). MINOR: detectLibplacebo:508 + getVersion:1071 still raw shell_exec (libplacebo reachable under segment coroutine, cached per-worker); wrapper coroutine-path test gap. → LOW top-up.
- SV-1.3 chapter-thumb+trickplay background job — **NOT-DONE (INERT, PROD-BREAKING).** MediaAssetJobStore/MediaAssetWorker/config supervision all real, BUT MediaScanner autowire (MediaServicesProvider:287-302) doesn't name mediaAssetJobStore (param 11) → null → enqueue guard (MediaScanner:1340) never true → nothing enqueued. WORSE: inline generation DELETED → chapter thumbnails + trickplay NEVER generated in prod. → COMPLETE: ->constructorParameter('mediaAssetJobStore', get(MediaAssetJobStore::class)) + scanner enqueue test + prod-wiring test.

### ⚠️ RECURRING DI LANDMINE (consolidate): PHP-DI skips optional defaulted ctor params unless NAMED. Produced 3 inert features: loginRateLimitStore (SV-1.10/AuthServicesProvider), mediaAssetJobStore (SV-1.3/MediaServicesProvider MediaScanner), similarityJobStore (SV-2.9/MediaServicesProvider MediaScanner param 12). → ONE consolidated "wire missing DI" fix agent: add ->constructorParameter for all three + audit every provider for other `?Type $x=null` params that should be live + prod-wiring tests. (High value: SV-1.3 no-thumbnails + SV-1.10 unbounded-login are prod-impacting.)
### Audits owed (deferred, lower priority): SV-1.4/1.5/1.6 (tonemap graph zscale/libplacebo + subtitle burn-in — visual, on-box verify needed).

## Implementer — X8 music-track stream producer (unblocks UI-3.6) — 2026-07-13
**DONE ✅** (commit: music: X8 mint signed track stream_url + audio MIME).

Files changed:
- `src/Server/Http/Controllers/MusicController.php` — `formatTrack()` now mints a signed
  `stream_url` = `SignedUrl::fromEnv()->mint('/media/' . $trackId . '/stream')` (added `use
  Phlix\Auth\SignedUrl;`). The track row id IS the `media_items` id, so the generic Range-safe
  `GET /media/{id}/stream` (`HttpHandler::serveMediaStream`) already serves it. `getTrack()` returns
  `formatTrack()` output, so it inherits `stream_url` — no separate edit needed. Emitted shape:
  `"stream_url": "/media/<trackId>/stream?exp=<unix>&sig=<base64url-hmac>"` (null when id is blank).
  → **AC1 (unblock direct-play) MET.**
- `src/Server/Workerman/HttpHandler.php` — renamed `videoMimeFor()` → `streamMimeFor()` (single call
  site updated) and added audio types: mp3→audio/mpeg, m4a→audio/mp4, aac→audio/aac, flac→audio/flac,
  ogg/oga→audio/ogg, opus→audio/opus, wav→audio/wav (video mappings retained). `serveMediaStream()`
  uses it for the track Content-Type. → **AC2 (audio MIME) MET.** Audio previously served as
  `application/octet-stream` (would not play).

Warnings cleanup (per follow-up request — phpunit + phpcs, on THIS changeset):
- Fixed pre-existing phpunit **PHP Warning** "Undefined array key color_space/color_transfer/
  color_primaries" (TranscodeManager.php:2215-2218). Root cause = TEST mock artifact: bare
  `FfmpegRunner` mock returns null for `extractColorMetadata()` (which in production always returns a
  full shape). Fixed in `tests/Unit/Media/Transcoding/TranscodeManagerTest.php` `manager()` helper via
  new `stubColorMetadata()` — NOT in production (phpstan proves the keys always exist; a prod `?? ''`
  guard would be a phpstan error). Full Unit suite now emits 0 PHP warnings.
- Fixed phpunit **deprecation** "Calling static trait method TranscodeFileServer::parseRange is
  deprecated" (surfaced by the new serveMediaStream test). Extracted the pure range parser to a new
  final class `src/Server/Http/Controllers/ByteRangeParser.php` (`::parse()`); the trait's
  `parseRange()` now delegates to it (API preserved for mixing-in consumers); `HttpHandler` calls
  `ByteRangeParser::parse()`. No external static-on-trait call remains.

Tests added:
- `MusicControllerTest::testGetTrackEmitsSignedStreamUrl` — asserts path `/media/track-42/stream` +
  `exp`/`sig` that verify via `SignedUrl::fromEnv()`.
- `HttpHandlerServeMediaStreamTest` (new) — serves a `type='track'` .flac media_item over a signed
  URL: 200 + `Content-Type: audio/flac`, Range 206, unsatisfiable 416, + a data-provider MIME map test
  over `streamMimeFor` (all 7 audio + retained video + unknown).
- `ByteRangeParserTest` (new) — 9 cases incl. trait-delegation parity via `HlsController::parseRange`.

Verification:
- `phpunit` targeted (Music|MediaStream|ByteRangeParser|TranscodeManager|HlsController): 152 tests OK.
- full `--testsuite Unit`: **5030 tests / 38855 assertions / 5 skipped / 0 failures / 0 errors /
  0 PHP warnings / 0 deprecations**.
- `phpstan analyze -c phpstan.neon.dist`: clean on all changed files (the 2 remaining errors in
  TranscodeManagerTest at ~1576/1722 are PRE-EXISTING on HEAD — confirmed via stash — untouched by me).
- `phpcs --standard=PSR12`: **clean (exit 0) on the entire X8 changeset.**

**phlix-contracts:** NO repin needed. `stream_url` matches the existing AudiobookController convention;
no contract test forced a schema change. (Follow-up flag: if a `phlix-contracts` track schema is later
added, it should carry `stream_url` — do it in a dedicated repin.)

**OUT OF SCOPE (flagged, NOT done):** the repo-wide `phpcs src/` run reports **202 pre-existing
`Generic.Files.LineLength.TooLong` warnings across 82 unrelated files** — none in the X8 changeset.
These are known/accepted style debt (CI "Server Component Tests" red via these → admin-merge pattern).
Reformatting 82 unrelated files would bury the functional change in a risky, unreviewable diff →
recommend a dedicated formatting-only commit rather than bundling into X8.
Also out of scope per the step: gapless/crossfade wiring (`buildGaplessSegmentCommand` inert) and
codec-transcode fallback (rides SV-3.3).

## Orchestrator — X8 music-stream producer DONE (2026-07-13, perf-5)
- [x] X8 producer — 1b760ad7. REVIEW NO FINDINGS (signed-URL contract verified: same signer/path/exp both sides, correct media_items id, MIME rename complete, ByteRangeParser behavior-preserving; 147/147). DONE. MusicController::formatTrack mints signed stream_url `/media/{trackId}/stream?exp&sig` (getTrack/listTracks/nowPlaying inherit); HttpHandler videoMimeFor→streamMimeFor + audio MIME (mp3/m4a/aac/flac/ogg/opus/wav). Bonus: fixed color_space test-mock warnings, extracted ByteRangeParser (killed parseRange deprecation) → full Unit 5030/0, 0 warnings/deprecations. phlix-contracts repin NOT needed (stream_url matches audiobook convention). REVIEW pending. UNBLOCKS UI-3.6.
- NOTE: 2 pre-existing phpstan errors in TranscodeManagerTest ~1576/1722 (on HEAD, not from X8); 202 pre-existing repo-wide phpcs LineLength warnings (82 files) — dedicated formatting commit candidate, not folded into functional work.

## Reviewer (per-step, X8 music-stream producer, commit 1b760ad7) — 2026-07-13

NO FINDINGS

Verified (read-only):
- CONTRACT: `formatTrack` mints `SignedUrl::fromEnv()->mint('/media/'.$trackId.'/stream')` →
  `/media/{id}/stream?exp&sig`. `serveMediaStream` matches `^/media/(?P<id>[^/]+)/stream$` on
  `$wr->path()` (query-less) and `isMediaStreamAuthorized` verifies with the SAME signer over the
  SAME path via `SignedUrl::verify($wr->path(), exp, sig)`. `canonicalResource('/media/{id}/stream')`
  returns the exact path (only /hls|/dash collapse), so both sides HMAC the identical
  `VERSION\n{path}\n{exp}` message. No path/scheme/exp mismatch → music plays will NOT 401.
- ID CORRECTNESS: track rows come from `MusicLibraryManager::getTracks` → `ItemRepository::getByType`
  → `SELECT * FROM media_items WHERE type='track'`; `track['id']` IS the media_items PK, so
  `findById($m['id'])` in serveMediaStream resolves. Not a music-domain id.
- AUTH/SECURITY: signed URL is bound to the exact per-track path + bounded exp (TTL from
  PHLIX_SIGNED_URL_TTL, default 6h); authorizes only that id, no over-grant; no secret/token leakage;
  JSON music endpoints (mint site) are behind the auth group, so only authed users obtain the URL —
  matches the AudiobookController convention.
- MIME: `streamMimeFor` returns correct `audio/*` for mp3/m4a/aac/flac/ogg/oga/opus/wav; retained
  video mappings; sane `application/octet-stream` fallback. Rename `videoMimeFor`→`streamMimeFor` is
  complete — `grep -rn videoMimeFor src/ tests/` finds ZERO stale callers.
- ByteRangeParser EXTRACTION: `parse()` body is a verbatim move of the old trait logic;
  `TranscodeFileServer::parseRange` now delegates (API preserved for trait consumers like
  HlsController); 206/416/suffix/over-long-clamp semantics unchanged. ByteRangeParserTest asserts
  trait-delegation parity.
- NO REGRESSION: `formatTrack` ADDS `stream_url` (null when id blank) without dropping/renaming other
  fields; `serveMediaStream` unchanged except the MIME helper name + `ByteRangeParser::parse` call.
- TESTS genuinely exercise the contract: MusicControllerTest::testGetTrackEmitsSignedStreamUrl does a
  real mint→`SignedUrl::fromEnv()->verify()` round-trip on `/media/track-42/stream`;
  HttpHandlerServeMediaStreamTest drives the actual `serveMediaStream` via the signed-URL (userId=null)
  auth path → 200 audio/flac, 206 Range, 416 unsatisfiable, + a 10-case MIME data-provider.
- Suite: `phpunit --filter 'Music|MediaStream|ByteRangeParser'` = 147 tests / 362 assertions OK;
  phpstan L9 on all 4 changed src files = No errors.

## Implementer — DI landmine consolidation (SV-1.10 + SV-1.3 + SV-2.9 wiring) — 2026-07-13
Root cause (per the RECURRING DI LANDMINE note): PHP-DI skips optional defaulted ctor params during
autowiring unless NAMED via `->constructorParameter(...)`. Three green-tested features were INERT in
prod because their store dependency defaulted to null. All three wired; a provider-wide sweep run.

Files changed:
- `src/Common/Container/Providers/AuthServicesProvider.php` — SV-1.10: import `DbLoginRateLimitStore`;
  added `->constructorParameter('loginRateLimitStore', get(DbLoginRateLimitStore::class))` to the
  `AuthManager` autowire (~:176). Store is autowirable (ctor = `Connection $db` [bound in
  CoreServicesProvider] + defaulted `int $windowSeconds`). Prod now uses the shared bounded DB store
  (login_rate_limit table, migration 074 — confirmed present in `migrations/074_login_rate_limit.sql`)
  instead of the unbounded per-worker static array.
- `src/Common/Container/Providers/MediaServicesProvider.php` — SV-1.3 + SV-2.9: added
  `->constructorParameter('mediaAssetJobStore', get(MediaAssetJobStore::class))` and
  `->constructorParameter('similarityJobStore', get(SimilarityJobStore::class))` to the `MediaScanner`
  autowire (~:303-321). MediaAssetJobStore was already registered (factory, config queue dir);
  registered `SimilarityJobStore::class => autowire()` (~:590) since it had NO binding — its enqueue
  in MediaScanner was therefore doubly dead (null + unregistered).
- `src/Auth/AuthManager.php` — bounded the static in-memory FALLBACK store (only reachable when no DB
  store is injected, i.e. tests/legacy): new const `RATE_LIMIT_FALLBACK_MAX_IPS = 10000` + private
  `boundFallbackStore()` (sweep-expired-then-evict-oldest) called before inserting a new IP in
  `recordFailedAttempt()`. Prevents unbounded growth if the fallback is ever exercised in a resident
  worker. No change to the store-wired path.

Dual entrypoints: both `public/index.php` and `start.php` build via `ContainerFactory::create()` using
`defaultProviders()`, so the provider edits cover FPM/CI + Swoole. Confirmed.

Tests added (all green):
- `tests/Unit/Common/Container/ContainerFactoryTest.php` — 3 PROD-WIRING tests resolving the consuming
  class from the REAL container (`containerWithMockedDb()`): AuthManager.loginRateLimitStore is a
  DbLoginRateLimitStore; MediaScanner.mediaAssetJobStore is a MediaAssetJobStore; MediaScanner
  .similarityJobStore is a SimilarityJobStore. These FAIL without the constructorParameter wiring.
- `tests/Unit/Media/Library/MediaScannerTest.php` — behaviour: a scan with both stores wired enqueues
  1 media-asset job (only the chapter-capable .mkv, not the .avi) + 2 similarity jobs (per new item);
  negative test: unwired stores enqueue nothing.
- `tests/Unit/Auth/DbLoginRateLimitStoreTest.php` (new) — behaviour: allow-when-no-record;
  throw-when-over-limit; sweep-expired-on-check; recordFailedAttempt upsert + bounded LIMITed sweep;
  clear deletes the row.

Sweep of all 14 providers (script reflected every `autowire()` class ctor for `?ObjectType $x = null`
params not named). OTHER unwired optional deps found — NONE wired here (see "SV-2.9 remaining" + report):
- Logger-typed params (`?LoggerInterface`/`?StructuredLogger`) across BackupManager, WebhookService,
  WebAuthnManager, FuzzyMatcher, LibraryMetadataMatcher, LibraryScanWorker, MediaAssetGenerationJob,
  BackgroundDetectorWorker, Movie/SeriesMetadataResolver, ThemeMusicResolver — NOT bugs (each
  self-defaults to a NullLogger or a channel logger internally).
- Fine (intentional/default-constructed): Movie/SeriesMetadataResolver `$fieldResolver`
  (PriorityFieldResolver default-constructed by design, per existing comment).
- FLAGGED as genuine additional instances of the SAME landmine but NOT fixed (out of this step's tight
  scope / not clearly-safe — each needs its own audit/cycle):
  * `LibraryMetadataMatcher::$artworkStorage` (SV-3.4) — local artwork caching is INERT (null-gated
    no-op at `cacheArtworkLocally`). NOT wired: `ArtworkStorage::downloadAndStore()` uses BLOCKING
    `curl_*`, and the matcher is reachable from the interactive HTTP `MediaMatchController` → wiring it
    would put blocking I/O on an HTTP handler (CARDINAL rule). Belongs in SV-3.4's cycle (move the
    download off the hot path / confirm Swoole curl hook).
  * `AuthManager::$providerManager` — external-provider login (`loginWithProvider`) always throws
    "ProviderManager is not configured", BUT `loginWithProvider` has ZERO callers in src/ (the real SSO
    flow goes through AuthProviderController→ProviderManager directly), so it is dead-from-AuthManager,
    not a prod-impacting inert feature. Flag only.
  * `WatchHistory::$recommendationService` — "because-you-watched" recompute on watch-completion is
    inert. NOT wired: markComplete runs in the HTTP watch path → inline O(N) recommendation compute
    would violate the no-heavy-inline rule; the correct fix is a background job (like SV-2.9). Flag.
  * `MediaScanner::$trailerFinder` — local-trailer discovery inert. NOT wired: TrailerFinder has NO DI
    binding anywhere (needs registering first); scan-only. Flag.
  * Lower-impact flags: `PlaybackController::$playToManager` (DLNA play-to), `HubClient::
    $portForwardService` (NAT), `SmartPlaylistRefreshHandler::$collectionManager/$collectionRepo`,
    `BackupManager::$auditLogger` — each inert when null; own-cycle candidates.

SV-2.9 remaining beyond wiring: the store is now live and the enqueue fires, BUT there is NO consumer —
no SimilarityWorker/queue-drain process and no `config/similarity_jobs.php` (unlike SV-1.3 which has
MediaAssetWorker + config + supervision). So jobs accumulate in `/tmp/phlix_similarity_jobs` undrained.
A follow-up must add a SimilarityWorker (mirroring MediaAssetWorker) + config + worker supervision, or
the enqueue is a leak. Flagged for SV-2.9's build-out cycle.

Verification: `phpunit tests/Unit/Auth/DbLoginRateLimitStoreTest.php ContainerFactoryTest MediaScannerTest`
= 104/911 OK; full `--testsuite Unit` = 5040 tests, 0 fail (5 skip); phpstan L9 on all changed src +
test files = No errors; `phpcs --standard=PSR12 src/<changed>` = 0 errors (only pre-existing LineLength
warnings on untouched lines).

## Orchestrator — DI-wiring consolidated fix (2026-07-13, perf-5): 3f56a7b7
- [~] Wired loginRateLimitStore (AuthServicesProvider:176), mediaAssetJobStore + similarityJobStore (MediaServicesProvider:303/318) + registered SimilarityJobStore binding (:590); bounded static login fallback (RATE_LIMIT_FALLBACK_MAX_IPS=10000). Dual-entrypoint confirmed. Prod-wiring tests (real container) + behavior tests. Full Unit 5040/0, phpstan clean. REVIEW pending (verify: login now does a DB call → coroutine-safe/no blocking/latency? scan enqueue no regression?).
### ⚠️ DI-landmine is BROADER (sweep across 14 providers) — more inert features from `?Type $x=null` unnamed:
- SV-2.9 similarityJobStore now enqueues but NO consumer (no SimilarityWorker/config/supervision) → /tmp/phlix_similarity_jobs accumulates undrained (DISK LEAK). → SV-2.9 needs worker+config+supervision (mirror MediaAssetWorker).
- ★ SV-3.4 artwork cache likely INERT: LibraryMetadataMatcher::$artworkStorage nullable-defaulted → PHP-DI skips → cacheArtworkLocally no-op → local artwork NEVER downloaded (contradicts earlier SV-3.4 DONE). NOT wired b/c ArtworkStorage::downloadAndStore uses BLOCKING curl on an HTTP-reachable matcher. → SV-3.4 completion: make artwork download coroutine-aware/async THEN wire. (VERIFY poster_srcset isn't pointing at never-generated variants.)
- Dead/low: AuthManager::$providerManager (loginWithProvider 0 callers), WatchHistory::$recommendationService (needs bg job not inline), MediaScanner::$trailerFinder (TrailerFinder has NO binding), PlaybackController::$playToManager, HubClient::$portForwardService, SmartPlaylistRefreshHandler::$collection*, BackupManager::$auditLogger. Logger params self-default (NOT bugs).

## Reviewer (per-step, DI-wiring fix, commit 3f56a7b7) — 2026-07-13

1. src/Auth/DbLoginRateLimitStore.php:166-169 (`cleanupExpiredEntries()`) —
   `DELETE FROM login_rate_limit WHERE reset_at <= ? LIMIT ?` binds BOTH params as
   STRINGS: `[(string) time(), (string) self::CLEANUP_BATCH_SIZE]`. The project DB layer
   (`PhlixMySQLConnection`/`PooledMySQLConnection`) uses EMULATED prepares with type-aware
   binding: `pdoParamType()` maps a PHP string to `PDO::PARAM_STR`, which PDO QUOTES →
   the SQL becomes `LIMIT '100'` → MySQL error 1064 (syntax error). This is the exact
   failure the `PhlixMySQLConnection` binding override was written to prevent ("integers
   stay unquoted so LIMIT ?/OFFSET ? work" — every other live `LIMIT ?` call in src/ passes
   an INT, e.g. Recorder.php:399, SimilarityService.php:147, MetricsRepository.php:312).
   WHY IT MATTERS: this commit is the FIRST time the store runs live — it wires
   `loginRateLimitStore` onto the login path (AuthManager::recordFailedAttempt →
   store->recordFailedAttempt → cleanupExpiredEntries). `cleanupExpiredEntries()` fires on
   EVERY failed login. The thrown `PDOException` is not caught → it propagates out of
   `AuthManager::login()`, turning every failed-credential attempt into a 500 instead of a
   clean auth failure, AND expired rows are never swept (table bloat). The store was inert
   before this commit so the latent bug was dormant; wiring it activates it.
   The new `DbLoginRateLimitStoreTest` does NOT catch this because it `createMock`s the
   Connection and stubs `query()` — the real emulated-prepare binding is never exercised
   (the recurring "green mocked test hides broken DB SQL" landmine).
   FIX: pass the LIMIT (and ideally the timestamp) as INTEGERS, not strings —
   `[time(), self::CLEANUP_BATCH_SIZE]` — so `pdoParamType()` binds them PARAM_INT and the
   LIMIT stays unquoted. (The `reset_at <= ?` string alone would coerce fine numerically,
   but the `LIMIT` string is a hard 1064.) Add a non-mocked/integration assertion that the
   store's cleanup runs against the real connection binding, or at least assert the params
   passed to `query()` for the LIMIT slot are int.

## Fixer — SV-DI LIMIT-bind bug (2026-07-13): FIXED
- src/Auth/DbLoginRateLimitStore.php cleanupExpiredEntries(): removed the `(string)` casts →
  `[time(), self::CLEANUP_BATCH_SIZE]` (both int). Was `LIMIT '100'` under emulated prepares → MySQL
  1064 on EVERY failed login (recordFailedAttempt→cleanup). reset_at is INT UNSIGNED (mig 074) so both
  params are correctly int. Full-file audit: no other numeric-param-as-string sites (all other calls
  pass $ip=string [VARCHAR] and $resetAt/$now=int correctly).
- Regression guard: added test_cleanup_sweep_binds_numeric_params_as_int — captures the DELETE...LIMIT
  params and asserts is_int() on both (LIMIT slot + reset_at threshold); a re-introduced (string) cast
  goes red despite the DB mock. phpunit --filter DbLoginRateLimitStore = 6/6 OK. phpstan L9 (dist) on
  both changed files = No errors. phpcs src = clean (test file has pre-existing test_snake_case names,
  file-wide convention).

## Implementer — 2026-07-13 — SV-3.4 sub-step 1/7 (ArtworkStorage non-blocking download)
- `src/Media/Storage/ArtworkStorage.php`: `downloadToTemp()` now dispatches via `shouldUseBlockingDownload()`
  (reuses shared `Common\Runtime\WorkerContext::isEventLoopRunning()/inCoroutine()` + `Common\Http\EventLoopTls::requiresBlockingCurl()`)
  → async path `downloadToTempAsync()` uses `Workerman\Http\Client` + `Swoole\Coroutine\Channel` cooperative
  wait (mirrors MetadataHttpClient; no busy-spin, 30s timeout via Channel::pop), blocking fallback
  `downloadToTempBlocking()` keeps the original cURL (CLI/scan/test + https-under-Swoole TLS stall) and now
  fclose()s the file handle. Failure = clean `\RuntimeException` (caller `cacheArtworkLocally` already
  try/catches → never bubbles to HTTP handler). GD/resize + public signatures unchanged.
- Tests: `tests/Unit/Media/Storage/ArtworkStorageTest.php` (+ fixture `TestableArtworkStorage.php`): async
  success writes temp + generates variants; non-worker context selects blocking; async error / non-200 →
  clean RuntimeException, no partial dir. phpunit --filter ArtworkStorage = 5 tests / 22 assertions OK;
  phpstan L (dist) 3 files = No errors; phpcs PSR12 3 files = clean.
- Wiring note: ArtworkStorage stays `autowire()` in MediaServicesProvider (no ctor change) — next sub-step
  can wire LibraryMetadataMatcher::$artworkStorage without a blocking-I/O CARDINAL violation.
