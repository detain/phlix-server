# Step E.6 — Subtitle burn-in pipeline

**Phase:** E (Hardware Transcoding + Advanced Streaming)
**Step:** E.6
**Depends on:** E.2
**Review:** Yes — see `e.6-subtitle-burnin-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Add subtitle burn-in (hardsubbing) to the transcoding pipeline so that
burned-in subtitles are embedded directly in the video stream — required
for players/devices that do not support external subtitle tracks (many
smart TVs, game consoles, some mobile browsers). The `SubtitleBurner`
class builds FFmpeg filter graphs that overlay SRT/ASS/SSA subtitles onto
the video during transcoding.

The burn-in path integrates with `HwaccelCommandBuilder` so that subtitle
filtering happens within the hardware pipeline without breaking the
transcode chain.

## 2. Context (what already exists)

- After E.2: `HwaccelCommandBuilder` builds complete FFmpeg commands for
  hardware-accelerated transcoding; `HwaccelProfileFactory` resolves
  vendor-specific profiles.
- `src/Media/Transcoding/FfmpegRunner.php` — already has `extractSubtitle()`
  which copies subtitle streams to an external file (for soft-subtitling).
- `src/Media/Streaming/QualitySelector.php` — device profiles handle
  `transcode` codec lists; subtitle requirements are not yet modeled.
- `config/ffmpeg.php` — no subtitle-specific config.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Subtitle burn-in pipeline" is
  **Missing**.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Transcoding/Subtitles/SubtitleFormat.php` — enum:

  ```php
  enum SubtitleFormat: string
  {
      case SRT   = 'srt';
      case ASS   = 'ass';
      case SSA   = 'ssa';
      case VTT   = 'vtt';
      case HDMV  = 'hdmv';  // Blu-ray PGS

      public function getFfmpegFormat(): string;  // maps to ffmpeg -c:s argument

      public function supportsFontstyle(): bool;   // ASS/SSA support font styles; SRT does not
  }
  ```

- `src/Media/Transcoding/Subtitles/SubtitleTrack.php` — metadata for a
  subtitle track:

  ```php
  final class SubtitleTrack
  {
      public function __construct(
          public readonly string $index,        // stream index in source
          public readonly string $language,     // 'eng', 'fra', etc.
          public readonly string $label,        // 'English (CC)', 'Spanish Subtitles'
          public readonly SubtitleFormat $format,
          public readonly string $path,         // path to subtitle file on disk
      ) {}
  }
  ```

- `src/Media/Transcoding/Subtitles/SubtitleBurner.php` — builds FFmpeg
  filter graphs for subtitle overlay:

  ```php
  class SubtitleBurner
  {
      public function __construct(FfmpegRunner $ffmpeg) {}

      /**
       * Detects all subtitle streams from an ffprobe result.
       * @return SubtitleTrack[]
       */
      public function detectSubtitleTracks(array $probe_result): array {}

      /** Extracts a subtitle stream to a file on disk. */
      public function extractSubtitle(string $input_path, int $stream_index, string $output_path): bool {}

      /** Returns the ffmpeg filter string for burning subtitles into video (hardsub). */
      public function getBurnInFilter(SubtitleTrack $track, array $style_options = []): string {}

      /**
       * Returns the ffmpeg command arguments for burning a specific subtitle track.
       * Handles both software (libass) and hardware (VAAPI/QSV/NVENC) subtitle rendering.
       */
      public function getBurnInArgs(SubtitleTrack $track, string $vendor, array $style_options = []): array {}
  }
  ```

- `src/Media/Transcoding/Subtitles/SubtitleStyleOptions.php` — value object
  for styling burn-in (position, font, size, color, outline):

  ```php
  final class SubtitleStyleOptions
  {
      public function __construct(
          public readonly string $font_name = 'Arial',
          public readonly int $font_size = 24,
          public readonly string $primary_color = '&H00FFFFFF',  // ARGB hex
          public readonly string $outline_color = '&H00000000',
          public readonly int $outline_thickness = 2,
          public readonly string $position = 'bottom',          // 'top' | 'bottom' | 'absolute'
          public readonly int $margin = 10,
      ) {}

      public function toAssStyle(): string;   // for ASS/SSA
      public function toSrtStyle(): string;   // for SRT (limited styling)
  }
  ```

- `src/Media/Transcoding/Subtitles/SubtitleBurnerFactory.php`:

  ```php
  final class SubtitleBurnerFactory
  {
      public function createForVendor(string $vendor, FfmpegRunner $ffmpeg): SubtitleBurner {}
  }
  ```

- `config/subtitles.php` — subtitle config:

  ```php
  return [
      'enabled' => true,
      'default_language' => 'eng',
      'burn_in_by_default' => false,      // true = burn in unless explicitly disabled
      'extract_to_dir' => '/var/subtitles',
      'style' => [
          'font_name' => 'Arial',
          'font_size' => 24,
          'primary_color' => '&H00FFFFFF',
          'outline_color' => '&H00000000',
          'outline_thickness' => 2,
          'position' => 'bottom',
          'margin' => 10,
      ],
  ];
  ```

- `tests/Unit/Media/Transcoding/Subtitles/SubtitleFormatTest.php`
- `tests/Unit/Media/Transcoding/Subtitles/SubtitleTrackTest.php`
- `tests/Unit/Media/Transcoding/Subtitles/SubtitleBurnerTest.php`
- `tests/Unit/Media/Transcoding/Subtitles/SubtitleStyleOptionsTest.php`

#### Documentation

- `docs/developers/subtitle-processing.md` — new doc covering soft-subtitling
  (external tracks) vs. hard-subtitling (burn-in), filter chain per vendor,
  styling options, and how to configure default behavior.

### Modify

- `src/Media/Transcoding/Hwaccel/HwaccelCommandBuilder.php` — add
  `setSubtitleTrack(?SubtitleTrack $track)` and `setSubtitleStyle(SubtitleStyleOptions)`
  methods; integrate subtitle burn-in filter args into the command.
- `src/Media/Transcoding/FfmpegRunner.php` — extend `extractSubtitle()`
  to support all formats (SRT, ASS, VTT) and return `SubtitleTrack`.
- `src/Media/Streaming/StreamManager.php` — add subtitle extraction and
  burn-in option to the streaming session API.
- `config/ffmpeg.php` — add `subtitles` key referencing `config/subtitles.php`.
- `composer.json` — no new dependencies.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch: `git checkout -b e.6-subtitle-burnin`.
2. **SubtitleFormat + SubtitleTrack.** Write the enum and value object.
3. **SubtitleStyleOptions.** Write the styling value object with `toAssStyle()`
   and `toSrtStyle()` methods.
4. **SubtitleBurner.** The core class:
   - `detectSubtitleTracks()` parses ffprobe JSON for subtitle streams and
     returns `SubtitleTrack[]`.
   - `extractSubtitle()` extends the existing `FfmpegRunner::extractSubtitle()`
     with format auto-detection and language label extraction.
   - `getBurnInFilter()` builds the FFmpeg filter graph:
     - For software (libass): `-vf subtitles='{$path}':force_style='{$style}'`
     - For ASS/SSA with styles: `-vf ass='{$path}'`
     - For SRT: use `subtitles='{$path}'` (ffmpeg's built-in SRT renderer)
     - For VAAPI: `-vaapi_device /dev/dri/renderD128 -vf
       'overlay_vaapi,format=nv12'` — note VAAPI subtitle burn-in requires
       a specific filter chain with `overlay_vaapi`
     - For NVENC: subtitles must be burned in software then uploaded to
       GPU (`hwupload`) — no native NVENC subtitle support
     - For QSV: `vpp submodule=subtitle` (limited support)
   - `getBurnInArgs()` returns the complete argument array for the
     subtitle track, suitable for `HwaccelCommandBuilder::addExtraArgs()`.
5. **SubtitleBurnerFactory.** Creates the correct burner for the target
   vendor, with fallback to software.
6. **CommandBuilder integration.** Add `setSubtitleTrack()` and
   `setSubtitleStyle()` to `HwaccelCommandBuilder`; call the burner's
   `getBurnInArgs()` and append to the command.
7. **FfmpegRunner update.** Return `SubtitleTrack` metadata from
   `probe()` / `extractSubtitle()`.
8. **StreamManager update.** Wire subtitle options into the stream session.
9. **Config.** Write `config/subtitles.php`.
10. **Tests.** Write all 4 test files.
11. **Verification bar.**
12. **Docs + changelog.**
13. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `SubtitleFormatTest::test_get_ffmpeg_format`
2. `SubtitleFormatTest::test_supports_fontstyle`
3. `SubtitleTrackTest::test_all_fields_accessible`
4. `SubtitleBurnerTest::test_detect_subtitle_tracks`
5. `SubtitleBurnerTest::test_detect_subtitle_tracks_empty`
6. `SubtitleBurnerTest::test_get_burn_in_filter_ass`
7. `SubtitleBurnerTest::test_get_burn_in_filter_srt`
8. `SubtitleBurnerTest::test_get_burn_in_filter_vtt`
9. `SubtitleBurnerTest::test_get_burn_in_args_vaapi`
10. `SubtitleBurnerTest::test_get_burn_in_args_nvenc_software_fallback`
11. `SubtitleBurnerTest::test_extract_subtitle`
12. `SubtitleStyleOptionsTest::test_defaults`
13. `SubtitleStyleOptionsTest::test_to_ass_style`
14. `SubtitleStyleOptionsTest::test_to_srt_style`
15. `SubtitleBurnerTest::test_factory_creates_correct_burner`

**Coverage target:** `SubtitleBurner` ≥ 85 %, `SubtitleStyleOptions` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New public class/method"** → all new public classes get PHPDoc
  with `@since 0.11.0`.
- **"Anything"** → `docs/developers/subtitle-processing.md` (new) covers
  soft vs. hard subtitling, vendor burn-in support matrix, styling reference.
- **"User-visible behavior change"** → CHANGELOG entry.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `SubtitleFormat::getFfmpegFormat()` returns `'srt'` for `SubtitleFormat::SRT`,
      `'ass'` for `SubtitleFormat::ASS`, etc.
- [ ] `SubtitleFormat::supportsFontstyle()` returns `true` for ASS/SSA,
      `false` for SRT/VTT.
- [ ] `SubtitleBurner::detectSubtitleTracks()` returns non-empty array
      when ffprobe result contains subtitle streams.
- [ ] `SubtitleBurner::detectSubtitleTracks()` returns empty array when
      no subtitle streams exist.
- [ ] `SubtitleBurner::getBurnInFilter()` returns a filter string
      containing `subtitles=` for SRT/ASS.
- [ ] `SubtitleBurner::getBurnInFilter()` for VAAPI vendor returns a
      filter string containing `overlay_vaapi`.
- [ ] `SubtitleBurner::getBurnInArgs()` for NVENC vendor returns args
      that use software burn-in then `hwupload` (no native NVENC subtitle support).
- [ ] `SubtitleBurner::extractSubtitle()` writes a valid subtitle file to disk.
- [ ] `SubtitleStyleOptions::toAssStyle()` returns a formatted ASS style string.
- [ ] `HwaccelCommandBuilder::setSubtitleTrack()` integrates the subtitle
      burn-in args into the built command.
- [ ] `config/subtitles.php` exists with all configuration keys including
      `style` sub-key.
- [ ] `./vendor/bin/phpunit` — green; ≥ 15 new tests.
- [ ] Coverage targets met per §5.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/subtitle-processing.md` written.
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
git checkout -b e.6-subtitle-burnin

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Subtitle'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step E.6: subtitle burn-in pipeline (SubtitleBurner + HwaccelCommandBuilder integration)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step E.6: subtitle burn-in pipeline" \
  --body  "Adds SubtitleFormat, SubtitleTrack, SubtitleBurner, SubtitleStyleOptions, SubtitleBurnerFactory, config/subtitles.php, and HwaccelCommandBuilder integration for subtitle burn-in across all vendors. Part of Phase E (Step E.6 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'e.6-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `e.6-subtitle-burnin-review.md`.

Non-obvious points:
- Not all vendors support hardware-accelerated subtitle burn-in:
  - NVENC: no native subtitle support — must use software `subtitles=`
    filter then `hwupload` to GPU. The filter chain is
    `subtitles=file.ass,hwupload=extra_hw_frames=6`.
  - VAAPI: limited — use `overlay_vaapi` in the filter graph.
  - QSV: limited — use `vpp submodule=subtitle` where supported.
  - VideoToolbox, AMF, V4L2: software fallback only.
  - Software: full support via `libass` / built-in SRT renderer.
- The vendor's `getBurnInArgs()` in `SubtitleBurner` is responsible for
  returning the correct fallback behavior, not the caller.
- `burn_in_by_default: false` means soft subtitles are preferred; the
  player handles rendering. Setting `true` burns in for all non-HDR-capable
  devices.
