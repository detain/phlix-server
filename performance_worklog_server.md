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
- [~] SV-0.3  isWorkermanContext fix  RE-AUDIT 2026-07-12: PARTIAL — impl CORRECT (shared Common\Runtime\WorkerContext::isEventLoopRunning() via Worker::isRunning(); all 4 clients + PluginCatalogService use it; old defined() guard gone). GAP: mandated regression test MISSING (no WorkerContextTest, no branch-selection test). → COMBINED SV-0.3+0.4 fix spawned. (e48e4aba)
- [~] SV-0.4  replace usleep spin-wait with Channel  RE-AUDIT 2026-07-12: PARTIAL + REAL BUG — spin-wait gone, but coroutine gating WRONG: Metadata/Webhook/S3 route to Channel path on isEventLoopRunning()&&!requiresBlockingCurl() with NO getCid()>0 gate → Channel::pop() outside a coroutine returns false immediately = FALSE TIMEOUT while callback pending. HttpClient INVERTED (Channel used only in non-coroutine case where it's invalid). AC requires: getCid()>0 → Channel; else blocking client explicitly. No tests. → COMBINED SV-0.3+0.4 fix spawned. (e48e4aba)
- [~] SV-0.5  fix WS reaper + heartbeat timer guards  RE-AUDIT 2026-07-12: PARTIAL — function_exists→class_exists fixed (3 sites); WS reaper arms (per-worker repeating); stream heartbeats one-shot (Timer::add(30,...,[],false), storm CAPPED). GAPS: (1) S-F28 WS app-level ping ABSENT (no pingInterval/pingNotResponseLimit, no ping timer → half-open sockets undetectable); (2) heartbeat NOT keyed-per-session/deduped/torn-down on stream end (each request re-registers → bounded accumulation, not the keyed+cancelled AC); (3) tests shallow (smoke test w/ stale comment; no leak test). → completion queued.
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
