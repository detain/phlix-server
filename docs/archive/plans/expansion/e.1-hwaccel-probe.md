# Step E.1 — Hardware acceleration probe & profile registry

**Phase:** E (Hardware Transcoding + Advanced Streaming)
**Step:** E.1
**Depends on:** A.7
**Review:** Yes — see `e.1-hwaccel-probe-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Add a hardware acceleration probe that detects available GPU/VAAPI/QSV encoders
at startup and populates a `HwaccelRegistry` singleton. `FfmpegRunner` is
extended with a `probeHardwareAcceleration()` method that runs `ffmpeg
-hwaccel=auto` and parses the output. The registry exposes capability
objects keyed by vendor name (`nvenc`, `vaapi`, `qsv`, `videotoolbox`, `amf`,
`v4l2`) so that `QualitySelector` and `TranscodeManager` can make
intelligent codec decisions without hardcoding vendor logic.

## 2. Context (what already exists)

- `src/Media/Transcoding/FfmpegRunner.php` — current FFmpeg wrapper;
  `buildTranscodeCommand()` only knows about `libx264` / `libx265`.
- `config/ffmpeg.php` — 10-line config with `ffmpeg_path`,
  `ffprobe_path`, `transcode_dir`, `segment_dir`, limits.
- `src/Media/Streaming/QualitySelector.php` — device profiles with
  `direct_play` / `transcode` codec lists; currently only `libx264`.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Hardware-accelerated transcoding
  (NVENC/VAAPI/QSV)" is **Missing**.
- `src/Server/Core/Application.php` — server bootstrap; a future step
  (E.2) will call the hwaccel probe at startup.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Transcoding/Hwaccel/HwaccelCapability.php` — value object:

  ```php
  final class HwaccelCapability
  {
      public function __construct(
          public readonly string $vendor,           // 'nvenc' | 'vaapi' | 'qsv' | 'videotoolbox' | 'amf' | 'v4l2'
          public readonly string $encoder,         // e.g. 'h264_nvenc'
          public readonly string $decoder,          // e.g. 'hevc_cuvid'
          public readonly bool $supports_hdr_tone_mapping,
          public readonly array $supported_codecs, // ['h264', 'hevc', 'av1', ...]
          public readonly array $supported_profiles,// ['baseline', 'main', 'high', ...]
          public readonly int $max_resolution_w,
          public readonly int $max_resolution_h,
          public readonly int $max_bitrate,
          public readonly array $extra_args = [],  // vendor-specific flags
      ) {}
  }
  ```

- `src/Media/Transcoding/Hwaccel/HwaccelProbe.php` — runs `ffmpeg
  -hwaccels`, `ffmpeg -encoders`, `ffmpeg -decoders` and parses output:

  ```php
  class HwaccelProbe
  {
      public function __construct(string $ffmpeg_path, ?LoggerInterface $logger = null) {}

      /** Runs the full probe suite and returns capabilities keyed by vendor name. */
      public function probe(): array<string, HwaccelCapability> {}

      /** Quick check: is a specific vendor available? */
      public function isVendorAvailable(string $vendor): bool {}

      /** Run vendor-specific acceptance test (encode a 1-second test clip). */
      public function probeVendor(string $vendor): ?HwaccelCapability {}
  }
  ```

- `src/Media/Transcoding/Hwaccel/HwaccelRegistry.php` — singleton holding
  the probed capabilities:

  ```php
  final class HwaccelRegistry
  {
      /** Returns 'nvenc' | 'vaapi' | 'qsv' | 'videotoolbox' | 'amf' | 'v4l2' | 'software' */
      public static function getInstance(): self {}

      /** Returns the best available encoder for the requested codec, or null. */
      public function getEncoder(string $codec, bool $require_hdr_tone_map = false): ?HwaccelCapability {}

      /** Returns the best available decoder for the requested codec, or null. */
      public function getDecoder(string $codec): ?HwaccelCapability {}

      /** Returns all registered capabilities sorted by preference (fastest first). */
      public function getAll(): array<string, HwaccelCapability> {}

      /** Returns the vendor priority order for fallback. */
      public function getVendorPriority(): array<string, int> {}

      /** Reload probe results (e.g., after GPU hotplug). */
      public function reload(): void {}
  }
  ```

- `src/Media/Transcoding/Hwaccel/HwaccelVendorNotFoundException.php`
- `src/Media/Transcoding/Hwaccel/HwaccelEncodeFailedException.php`

- `config/hwaccel.php` — default hwaccel config:

  ```php
  return [
      'enabled' => true,
      'prefer_hardware' => true,
      'vendor_priority' => ['nvenc' => 0, 'vaapi' => 1, 'qsv' => 2, 'videotoolbox' => 3, 'amf' => 4, 'v4l2' => 5],
      'probe_timeout' => 30,
      'test_clip_path' => '/tmp/hwaccel_probe_test.mp4',
      'fallback_to_software' => true,
  ];
  ```

- `src/Media/Transcoding/Hwaccel/VendorProbe/NvencProbe.php`
- `src/Media/Transcoding/Hwaccel/VendorProbe/VaapiProbe.php`
- `src/Media/Transcoding/Hwaccel/VendorProbe/QsvProbe.php`
- `src/Media/Transcoding/Hwaccel/VendorProbe/VideoToolboxProbe.php`
- `src/Media/Transcoding/Hwaccel/VendorProbe/AmfProbe.php`
- `src/Media/Transcoding/Hwaccel/VendorProbe/V4L2Probe.php`
- `src/Media/Transcoding/Hwaccel/VendorProbe/SoftwareProbe.php`

- `tests/Unit/Media/Transcoding/Hwaccel/HwaccelCapabilityTest.php`
- `tests/Unit/Media/Transcoding/Hwaccel/HwaccelProbeTest.php`
- `tests/Unit/Media/Transcoding/Hwaccel/HwaccelRegistryTest.php`

#### Documentation

- `docs/developers/hardware-acceleration.md` — new doc explaining hwaccel
  probe, vendor priority, and how to add a new vendor.

### Modify

- `src/Media/Transcoding/FfmpegRunner.php` — add
  `HwaccelRegistry $registry` property; add `probeHardwareAcceleration()`
  method; add `buildHwaccelCommand()` helper.
- `config/ffmpeg.php` — add `hwaccel` key with `enabled`,
  `prefer_hardware`, `vendor_priority` entries.
- `composer.json` — no new runtime dependencies; dev-only test helpers OK.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b e.1-hwaccel-probe`.
2. **Value objects first.** Write `HwaccelCapability` + the two exception
   classes with full PHPDoc and `@since 0.11.0`.
3. **Vendor probes.** Write one `VendorProbeInterface` + the 7 concrete
   implementations. Each `probe()` method runs `ffmpeg -encoders` and
   filters on the vendor prefix (e.g. `h264_nvenc`, `hevc_vaapi`).
4. **HwaccelProbe.** Aggregates all vendor probes, calls each one, merges
   results into the capability map.
5. **HwaccelRegistry.** Singleton that holds the result. `getInstance()`
   lazily calls `probe()` on first access. `getEncoder()` / `getDecoder()`
   use vendor priority to return the best match.
6. **FfmpegRunner integration.** Add `HwaccelRegistry` property; add
   `probeHardwareAcceleration()` which calls
   `HwaccelProbe->probe()->getAll()` and populates the registry.
7. **Config.** Write `config/hwaccel.php`.
8. **Tests.** Write all 3 test files per §5.
9. **Verification bar** (§0.4 minimum bar).
10. **Docs.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `HwaccelCapabilityTest::test_all_fields_accessible`
2. `HwaccelCapabilityTest::test_immutable`
3. `HwaccelProbeTest::test_probe_returns_map`
4. `HwaccelProbeTest::test_is_vendor_available`
5. `HwaccelProbeTest::test_probe_vendor_fallback`
6. `HwaccelRegistryTest::test_singleton_returns_same_instance`
7. `HwaccelRegistryTest::test_get_encoder_nvenc`
8. `HwaccelRegistryTest::test_get_encoder_fallback_to_software`
9. `HwaccelRegistryTest::test_get_decoder`
10. `HwaccelRegistryTest::test_vendor_priority`
11. `HwaccelRegistryTest::test_reload`

**Coverage target:** `HwaccelProbe` ≥ 85 %, `HwaccelRegistry` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Anything"** → `docs/developers/hardware-acceleration.md` (new) covers
  probe architecture, vendor priority, `HwaccelCapability` fields.
- **"New public class/method"** → all 5 new public classes get PHPDoc
  with `@since 0.11.0`.
- **"User-visible behavior change"** → CHANGELOG entry (hwaccel probe
  added; no user-visible change yet — transcode still software until E.2).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `HwaccelCapability` is an immutable value object with all fields
      documented.
- [ ] `HwaccelProbe::probe()` returns a `string→HwaccelCapability` map.
- [ ] `HwaccelProbe::isVendorAvailable('nvenc')` returns a bool without
      throwing.
- [ ] `HwaccelRegistry::getInstance()` returns the same instance on
      repeated calls.
- [ ] `HwaccelRegistry::getEncoder('h264')` returns a
      `HwaccelCapability` (or null if none found).
- [ ] `HwaccelRegistry::getEncoder('hevc', require_hdr_tone_map: true)`
      only returns capabilities where `supports_hdr_tone_mapping` is
      `true`.
- [ ] `HwaccelRegistry::getVendorPriority()` returns an ordered array.
- [ ] `FfmpegRunner` has `HwaccelRegistry` injected and a
      `probeHardwareAcceleration()` method.
- [ ] `config/hwaccel.php` exists with `enabled`, `prefer_hardware`,
      `vendor_priority`, `probe_timeout`, `test_clip_path`.
- [ ] `./vendor/bin/phpunit` — green; ≥ 11 new tests.
- [ ] Coverage of `HwaccelProbe` + `HwaccelRegistry` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/hardware-acceleration.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b e.1-hwaccel-probe

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'HwaccelProbe|HwaccelRegistry'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step E.1: hwaccel probe + HwaccelRegistry (NVENC/VAAPI/QSV/VideoToolbox/AMF/V4L2)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step E.1: hwaccel probe & profile registry" \
  --body  "Adds HwaccelProbe, HwaccelCapability, HwaccelRegistry, per-vendor probe classes, config/hwaccel.php, and integration into FfmpegRunner. Part of Phase E (Step E.1 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'e.1-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `e.1-hwaccel-probe-review.md`.

Non-obvious points:
- `HwaccelRegistry` is a lazy singleton — `probe()` is only called on
  first `getInstance()` call. This avoids running expensive probes at
  file-compile time.
- Each vendor probe only populates the fields it can detect; unknown
  fields default to conservative values (e.g. `max_resolution_w = 3840`
  unless the probe can detect the GPU's maximum).
- `SoftwareProbe` always returns a capability (even on systems with no
  GPU) so that `getEncoder()` never returns null unless `fallback_to_software`
  is false in config.
