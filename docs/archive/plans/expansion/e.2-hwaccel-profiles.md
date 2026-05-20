# Step E.2 — Hardware encoder profiles

**Phase:** E (Hardware Transcoding + Advanced Streaming)
**Step:** E.2
**Depends on:** E.1
**Review:** Yes — see `e.2-hwaccel-profiles-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Define per-vendor encoding profiles that map the abstract quality levels
from `QualitySelector` (e.g. `1080p-high`, `720p-medium`) to concrete
FFmpeg encoder flags for each supported hardware accelerator. Each vendor
has a `HwaccelEncoderProfile` class that provides:

- The encoder/decoder device flags (`-hwaccel`, `-c:v`, `-preset` equivalents)
- Bitrate / CRF / GOP target values
- Maximum concurrent encode sessions (per GPU, or unlimited for software)
- Special flags (e.g. `-tune zerolatency` for NVENC)

The profiles are consumed by `TranscodeManager` to build FFmpeg commands
without any vendor-specific if/else chains.

## 2. Context (what already exists)

- After E.1: `HwaccelRegistry` holds `HwaccelCapability` objects for all 7
  vendors; `FfmpegRunner` can call `probeHardwareAcceleration()`.
- `src/Media/Streaming/QualitySelector.php` — device profiles with
  `direct_play` / `transcode` codec lists; `selectQuality()` returns
  `['method' => 'transcode', 'video_codec' => 'libx264', ...]`.
- `src/Media/Transcoding/FfmpegRunner.php` — `buildTranscodeCommand()` with
  hardcoded `libx264` / `libx265` switch; to be extended in this step.
- `config/ffmpeg.php` — current FFmpeg config; to be extended with
  hwaccel profile keys.
- `config/hwaccel.php` — from E.1.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Transcoding/Hwaccel/Profiles/HwaccelEncoderProfileInterface.php`:

  ```php
  interface HwaccelEncoderProfileInterface
  {
      public function getVendor(): string;  // 'nvenc' | 'vaapi' | 'qsv' | 'videotoolbox' | 'amf' | 'v4l2' | 'software'

      public function getEncoderName(): string;  // e.g. 'h264_nvenc'

      /** Returns FFmpeg input device flag, e.g. '-vaapi_device /dev/dri/renderD128' */
      public function getInputDeviceArgs(HwaccelCapability $capability): string;

      /** Returns FFmpeg output codec flag, e.g. '-c:v h264_nvenc' */
      public function getCodecArg(HwaccelCapability $capability, string $codec): string;

      /** Returns vendor-specific quality/preset flags (e.g. '-preset p4 -tune zerolatency') */
      public function getQualityArgs(string $quality_level, int $target_bitrate): string;

      /** Returns extra encode filters specific to this vendor (e.g. deinterlace, denoise). */
      public function getFilterArgs(array $filters): string;

      /** Returns the maximum concurrent encodes for this hardware (0 = unlimited). */
      public function getMaxConcurrent(): int;
  }
  ```

- `src/Media/Transcoding/Hwaccel/Profiles/NvencProfile.php` — NVENC-specific
  implementation; `-preset p1..p7` (fastest to slowest), `-tune zerolatency`,
  `-rc constqp` / `-rc vbr`, B-frames off by default, multipass support.
- `src/Media/Transcoding/Hwaccel/Profiles/VaapiProfile.php` — VAAPI;
  `-vaapi_device`, `-vf 'format=nv12,hwupload'`, `-rc_mode CQP`,
  driver-specific rate control.
- `src/Media/Transcoding/Hwaccel/Profiles/QsvProfile.php` — QSV;
  `-qsv_device`, `-load_plugin`, `-preset speed` / `balanced` / `quality`,
  Look-ahead / BRC parameters.
- `src/Media/Transcoding/Hwaccel/Profiles/VideoToolboxProfile.php` — macOS;
  `- videotoolbox` input, `-vcodec hevc_videotoolbox` etc., hardware
  frame management.
- `src/Media/Transcoding/Hwaccel/Profiles/AmfProfile.php` — AMD;
  `-hwaccel d3d11va`, `-vcodec h264_amf`, quality/preset mapping.
- `src/Media/Transcoding/Hwaccel/Profiles/V4L2Profile.php` — Linux kernel;
  `-request脸红 hw` (from V4L2 request API), `-output_fmt`, `-file`.
- `src/Media/Transcoding/Hwaccel/Profiles/SoftwareProfile.php` — software
  fallback; wraps the existing `libx264` / `libx265` logic with CRF/preset
  settings consistent with hwaccel quality levels.

- `src/Media/Transcoding/Hwaccel/HwaccelProfileFactory.php`:

  ```php
  final class HwaccelProfileFactory
  {
      public function __construct(HwaccelRegistry $registry) {}

      /** Returns the best profile for the requested vendor + codec combination. */
      public function getProfile(string $vendor, string $codec): HwaccelEncoderProfileInterface;

      /** Returns all registered profiles sorted by vendor priority. */
      public function getAllProfiles(): array<string, HwaccelEncoderProfileInterface>;

      /** Creates a command builder for the given job parameters. */
      public function createCommandBuilder(string $vendor, string $codec, string $quality): HwaccelCommandBuilder;
  }
  ```

- `src/Media/Transcoding/Hwaccel/HwaccelCommandBuilder.php` — fluent builder
  for FFmpeg hwaccel commands:

  ```php
  final class HwaccelCommandBuilder
  {
      public function __construct(HwaccelEncoderProfileInterface $profile, HwaccelCapability $capability) {}

      public function setInput(string $path): self;
      public function setOutput(string $path): self;
      public function setVideoCodec(string $codec): self;
      public function setAudioCodec(string $codec): self;
      public function setBitrate(int $bps): self;
      public function setResolution(int $w, int $h): self;
      public function setQualityLevel(string $level): self;  // 'ultra' | 'high' | 'medium' | 'low'
      public function addFilter(string $filter): self;
      public function addExtraArgs(array $args): self;
      public function build(): string;  // returns the complete ffmpeg command string
  }
  ```

- `config/hwaccel_profiles.php` — quality level → bitrate mapping per profile:

  ```php
  return [
      'nvenc' => [
          'ultra'   => ['bitrate' => 8000000,  'preset' => 'p3', 'bframes' => 0],
          'high'    => ['bitrate' => 5000000,  'preset' => 'p4', 'bframes' => 0],
          'medium'  => ['bitrate' => 2500000,  'preset' => 'p5', 'bframes' => 0],
          'low'     => ['bitrate' => 1000000,  'preset' => 'p6', 'bframes' => 0],
      ],
      'vaapi'  => [...],
      'qsv'    => [...],
      // ...
  ];
  ```

- `tests/unit/Media/Transcoding/Hwaccel/HwaccelEncoderProfileTest.php`
  (tests the interface and each concrete profile)
- `tests/unit/Media/Transcoding/Hwaccel/HwaccelProfileFactoryTest.php`
- `tests/unit/Media/Transcoding/Hwaccel/HwaccelCommandBuilderTest.php`
- `tests/unit/Media/Transcoding/Hwaccel/Profiles/NvencProfileTest.php`
- `tests/unit/Media/Transcoding/Hwaccel/Profiles/VaapiProfileTest.php`
- `tests/unit/Media/Transcoding/Hwaccel/Profiles/QsvProfileTest.php`

#### Documentation

- `docs/developers/hardware-acceleration.md` — add "Encoding Profiles"
  section documenting each vendor's preset mapping, bitrate guidelines,
  and known limitations.

### Modify

- `src/Media/Transcoding/FfmpegRunner.php` — add `HwaccelProfileFactory`
  and `HwaccelCommandBuilder` to build hwaccel-aware transcode commands;
  extend `buildTranscodeCommand()` to accept a `HwaccelEncoderProfileInterface`
  and delegate to `HwaccelCommandBuilder`.
- `src/Media/Streaming/QualitySelector.php` — extend `selectQuality()`
  to optionally accept a vendor hint and return vendor-specific codec names
  (e.g. `h264_nvenc` instead of `libx264`).
- `config/ffmpeg.php` — add `hwaccel_profiles` key referencing
  `config/hwaccel_profiles.php`.
- `composer.json` — no new dependencies.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch: `git checkout -b e.2-hwaccel-profiles`.
2. **Interface first.** Define `HwaccelEncoderProfileInterface` with all 5
   methods. Write `SoftwareProfile` as the baseline (current libx264/libx265
   behavior, with explicit preset/bitrate/CRF mapping).
3. **Per-vendor profiles.** Write all 6 vendor-specific classes. Use
   E.1's `HwaccelCapability` to gate vendor-specific capabilities (e.g.
   NVENC's multipass requires a newer driver — check `extra_args`).
4. **Factory.** `HwaccelProfileFactory` takes `HwaccelRegistry` and
   resolves the best profile for a vendor+codec pair. If the requested
   vendor is not available, it falls back to the next vendor in priority
   order (from `config/hwaccel.php` `vendor_priority`) until it finds
   one that supports the requested codec.
5. **CommandBuilder.** `HwaccelCommandBuilder` is the fluent API that
   `FfmpegRunner` uses to construct the final command string. The builder
   holds the profile + capability + job params and produces the complete
   `ffmpeg ...` string in `build()`.
6. **QualitySelector update.** Extend `selectQuality()` to return the
   vendor-specific codec name in the result when hwaccel is available.
7. **Config.** Write `config/hwaccel_profiles.php`.
8. **Tests.** Write all 6 test files.
9. **Verification bar.**
10. **Docs + changelog.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `HwaccelEncoderProfileTest::test_interface_all_methods_defined`
2. `HwaccelEncoderProfileTest::test_software_profile_encoder_name`
3. `HwaccelEncoderProfileTest::test_nvenc_profile_preset_mapping`
4. `HwaccelEncoderProfileTest::test_vaapi_profile_device_args`
5. `HwaccelProfileFactoryTest::test_get_profile_nvenc`
6. `HwaccelProfileFactoryTest::test_fallback_to_next_vendor`
7. `HwaccelProfileFactoryTest::test_fallback_to_software`
8. `HwaccelCommandBuilderTest::test_build_simple_command`
9. `HwaccelCommandBuilderTest::test_add_filter`
10. `HwaccelCommandBuilderTest::test_set_quality_level`
11. `HwaccelCommandBuilderTest::test_build_nvenc_command`
12. `HwaccelCommandBuilderTest::test_build_vaapi_command`
13. `NvencProfileTest::test_preset_p1_is_fastest`
14. `NvencProfileTest::test_bframes_disabled_by_default`
15. `VaapiProfileTest::test_vaapi_device_arg`
16. `QsvProfileTest::test_qsv_device_arg`

**Coverage target:** `HwaccelProfileFactory` ≥ 85 %, `HwaccelCommandBuilder`
≥ 85 %, each vendor profile ≥ 80 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New public class/method"** → all new public classes get PHPDoc
  with `@since 0.11.0`.
- **"Anything"** → `docs/developers/hardware-acceleration.md` updated
  with encoding profile section.
- **"User-visible behavior change"** → CHANGELOG entry (no user-visible
  change yet — profile selection is internal).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `HwaccelEncoderProfileInterface` defines all 5 methods with PHPDoc.
- [ ] `NvencProfile::getEncoderName()` returns `'h264_nvenc'` or
      `'hevc_nvenc'` depending on codec.
- [ ] `NvencProfile::getQualityArgs('high', 5000000)` returns a string
      containing `-preset p4` (or equivalent for the quality level).
- [ ] `VaapiProfile::getInputDeviceArgs()` returns a string containing
      `-vaapi_device`.
- [ ] `HwaccelProfileFactory::getProfile('nvenc', 'h264')` returns the
      `NvencProfile` instance.
- [ ] `HwaccelProfileFactory::getProfile('nvenc', 'h264')` returns
      `SoftwareProfile` if NVENC is not available and fallback is enabled.
- [ ] `HwaccelCommandBuilder` produces a valid ffmpeg command string with
      hwaccel flags in the correct order.
- [ ] `FfmpegRunner::buildTranscodeCommand()` accepts a profile argument
      and delegates to `HwaccelCommandBuilder`.
- [ ] `QualitySelector::selectQuality()` returns `h264_nvenc` (not
      `libx264`) when a hwaccel profile is available.
- [ ] `config/hwaccel_profiles.php` defines quality level mappings for
      all 7 vendors.
- [ ] `./vendor/bin/phpunit` — green; ≥ 16 new tests.
- [ ] Coverage targets met per §5.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/hardware-acceleration.md` updated.
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
git checkout -b e.2-hwaccel-profiles

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'HwaccelProfile|HwaccelCommand|NvencProfile|VaapiProfile|QsvProfile'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step E.2: hwaccel encoder profiles (NVENC/VAAPI/QSV/VideoToolbox/AMF/V4L2/Software)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step E.2: hardware encoder profiles" \
  --body  "Adds HwaccelEncoderProfileInterface, per-vendor profile classes (NvencProfile, VaapiProfile, QsvProfile, VideoToolboxProfile, AmfProfile, V4L2Profile, SoftwareProfile), HwaccelProfileFactory, HwaccelCommandBuilder, and config/hwaccel_profiles.php. Part of Phase E (Step E.2 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'e.2-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `e.2-hwaccel-profiles-review.md`.

Non-obvious points:
- Profile selection is hierarchical: E.2 does NOT automatically switch
  to hardware — it only defines the *profiles*. A future step (TranscodeManager
  integration) will wire the actual hardware path.
- `SoftwareProfile` is the reference implementation — its behavior for
  `libx264` / `libx265` must exactly match the existing
  `FfmpegRunner::buildTranscodeCommand()` output, so the transition
  to using the profile is invisible to existing callers.
- Vendor priority for fallback is read from `config/hwaccel.php`
  `vendor_priority` (set in E.1), not hardcoded.
