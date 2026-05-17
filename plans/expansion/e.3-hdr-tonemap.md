# Step E.3 — HDR→SDR hardware tone-mapping

**Phase:** E (Hardware Transcoding + Advanced Streaming)
**Step:** E.3
**Depends on:** E.2
**Review:** Yes — see `e.3-hdr-tonemap-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Add HDR (High Dynamic Range) to SDR (Standard Dynamic Range) tone-mapping
filter chains to the hardware encode pipeline. Each vendor has a different
tone-mapping path:

- **NVENC/NVDecode**: `scale_cuda=...` + `tonemap_cuda=...` or zscale + format
- **VAAPI**: `vaapi TONEMAP` built-in or `vf scale_vaapi=format=nv12` + zscale
- **QSV**: `vpp tone_mapping` (Intel Quick Sync Video Video Processing
  Proxy) + `scale_qsv`
- **VideoToolbox**: HDR passthrough not supported; tone-map on CPU using
  `zscale` filter before hardware encode
- **AMF**: `TONEMAP_Hardware` parameter in the encoder init
- **V4L2**: No hardware tone-mapping; use zscale before encoding
- **Software**: `zscale` filter chain

The `HwaccelToneMapper` class exposes a single `getFilterChain()` method
that returns the correct vendor-specific filter graph. It is called by
`HwaccelCommandBuilder` when the source is detected as HDR.

## 2. Context (what already exists)

- After E.2: per-vendor encoder profiles exist (`NvencProfile`,
  `VaapiProfile`, etc.) with `getQualityArgs()`, `getFilterArgs()` methods.
- `HwaccelCapability` from E.1 has `supports_hdr_tone_mapping: bool`.
- `src/Media/Transcoding/FfmpegRunner.php` — can probe HDR metadata via
  `probe()` returning `color_space`, `color_transfer`, `color_primaries`.
- `src/Media/Transcoding/EncodingHelper.php` — current CRF/preset logic
  (E.1 or earlier step had encoding params); this step extends it.
- `PHLEX_EXPANSION_PLAN.md` §1 — "HDR→SDR tone-mapping" is **Missing**.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Transcoding/Hwaccel/ToneMapping/HdrMetadata.php` — source HDR
  metadata value object:

  ```php
  final class HdrMetadata
  {
      public function __construct(
          public readonly string $color_space,      // 'bt2020nc' | 'bt709' | ...
          public readonly string $color_transfer,   // 'smpte2084' (PQ) | 'arib-std-b67' (HLG)
          public readonly string $color_primaries,   // 'bt2020' | 'bt709' | ...
          public readonly float $max_luminance,     // e.g. 1000.0 nits
          public readonly float $avg_luminance,     // e.g. 200.0 nits
      ) {}

      public function isHdr(): bool {
          return in_array($this->color_transfer, ['smpte2084', 'arib-std-b67'], true);
      }
  }
  ```

- `src/Media/Transcoding/Hwaccel/ToneMapping/ToneMapFilterChain.php` — result
  of filter chain generation:

  ```php
  final class ToneMapFilterChain
  {
      public function __construct(
          public readonly string $input_filtergraph,   // e.g. 'hwupload=extra_hw_frames=3'
          public readonly string $output_filtergraph, // e.g. 'tonemap_cuda=...'
          public readonly string $metadata_filter,     // e.g. 'zscale=...'
          public readonly array $ffmpeg_args,          // extra ffmpeg args to prepend
      ) {}

      public function isEmpty(): bool;  // true if no tonemap needed
  }
  ```

- `src/Media/Transcoding/Hwaccel/ToneMapping/HwaccelToneMapper.php` — main
  orchestrator:

  ```php
  final class HwaccelToneMapper
  {
      public function __construct(HwaccelRegistry $registry) {}

      /** Detects if the source needs HDR→SDR tone-mapping based on ffprobe color metadata. */
      public function detectHdrFromProbe(array $probe_result): ?HdrMetadata {}

      /** Generates the tone-mapping filter chain for the given vendor + HDR source. */
      public function getFilterChain(string $vendor, HdrMetadata $hdr): ToneMapFilterChain {}

      /** Returns true if the given vendor supports hardware-accelerated tone-mapping. */
      public function vendorSupportsHwToneMap(string $vendor): bool {}
  }
  ```

- `src/Media/Transcoding/Hwaccel/ToneMapping/Vendor/NvencToneMapper.php`
- `src/Media/Transcoding/Hwaccel/ToneMapping/Vendor/VaapiToneMapper.php`
- `src/Media/Transcoding/Hwaccel/ToneMapping/Vendor/QsvToneMapper.php`
- `src/Media/Transcoding/Hwaccel/ToneMapping/Vendor/VideoToolboxToneMapper.php`
- `src/Media/Transcoding/Hwaccel/ToneMapping/Vendor/AmfToneMapper.php`
- `src/Media/Transcoding/Hwaccel/ToneMapping/Vendor/V4L2ToneMapper.php`
- `src/Media/Transcoding/Hwaccel/ToneMapping/Vendor/SoftwareToneMapper.php`

- `src/Media/Transcoding/Hwaccel/ToneMapping/ToneMapperFactory.php`:

  ```php
  final class ToneMapperFactory
  {
      /** Returns the correct tone mapper for the vendor, auto-fallback to software if needed. */
      public function getToneMapper(string $vendor): HwaccelToneMapperInterface {}
  }
  ```

- `tests/unit/Media/Transcoding/Hwaccel/ToneMapping/HdrMetadataTest.php`
- `tests/unit/Media/Transcoding/Hwaccel/ToneMapping/ToneMapFilterChainTest.php`
- `tests/unit/Media/Transcoding/Hwaccel/ToneMapping/HwaccelToneMapperTest.php`
- `tests/unit/Media/Transcoding/Hwaccel/ToneMapping/Vendor/NvencToneMapperTest.php`
- `tests/unit/Media/Transcoding/Hwaccel/ToneMapping/Vendor/VaapiToneMapperTest.php`
- `tests/unit/Media/Transcoding/Hwaccel/ToneMapping/Vendor/QsvToneMapperTest.php`

#### Documentation

- `docs/developers/hardware-acceleration.md` — add "HDR Tone-Mapping"
  section documenting filter chain per vendor, zscale parameters, and
  luminance metadata handling.

### Modify

- `src/Media/Transcoding/Hwaccel/HwaccelCommandBuilder.php` — add
  `setHdrMetadata(HdrMetadata $hdr)` method; integrate tone-mapping filter
  chain into the command when the source is HDR.
- `src/Media/Transcoding/FfmpegRunner.php` — extend `probe()` to extract
  and return color space / color transfer / color primaries fields in the
  probe result array.
- `composer.json` — no new dependencies.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch: `git checkout -b e.3-hdr-tonemap`.
2. **HdrMetadata.** Write the value object with `isHdr()` helper.
3. **ToneMapFilterChain.** Write the result container.
4. **Per-vendor tone mappers.** Write `HwaccelToneMapperInterface` + 7
   implementations. Each implementation's `getFilterChain(HdrMetadata)`:

   - NVENC: `hwupload`, `scale_cuda`, `tonemap_cuda=pq=t=bt2020:tonemap=hable:desat=0`
     fallback to `zscale` if `tonemap_cuda` unavailable
   - VAAPI: `scale_vaapi=format=nv12|vaapi_upload` + `tonemap_vaapi`
     OR `zscale` + `format=nv12` + `hwupload`
   - QSV: `vpp tone_mapping=mode=1` (filmic) + `scale_qsv`
   - VideoToolbox: `zscale` only (no HW tonemap); scale to SDR target
   - AMF: `hwupload` + `tonemap_amf=-transfer=bt709`
   - V4L2: `zscale` only (V4L2 request API does not support HW tonemap)
   - Software: `zscale=transfer=bt709` + `format=nv12`

5. **HwaccelToneMapper.** Orchestrates vendor selection + tone mapper
   invocation. `vendorSupportsHwToneMap()` checks `HwaccelCapability`
   `supports_hdr_tone_mapping`.
6. **CommandBuilder integration.** Add `setHdrMetadata()` to
   `HwaccelCommandBuilder`; call `getFilterChain()` and splice the
   filter graph into the command string.
7. **FfmpegRunner probe update.** Extend probe parsing to extract
   `color_space`, `color_transfer`, `color_primaries`, `max_luminance`
   from ffprobe JSON.
8. **Tests.** Write all 6 test files.
9. **Verification bar.**
10. **Docs + changelog.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `HdrMetadataTest::test_is_hdr_pq`
2. `HdrMetadataTest::test_is_hdr_hlg`
3. `HdrMetadataTest::test_is_hdr_false_for_bt709`
4. `HdrMetadataTest::test_max_luminance_accessible`
5. `ToneMapFilterChainTest::test_is_empty_false`
6. `ToneMapFilterChainTest::test_is_empty_true`
7. `HwaccelToneMapperTest::test_detect_hdr_from_probe`
8. `HwaccelToneMapperTest::test_get_filter_chain_nvenc`
9. `HwaccelToneMapperTest::test_get_filter_chain_vaapi`
10. `HwaccelToneMapperTest::test_vendor_supports_hw_tonemap`
11. `HwaccelToneMapperTest::test_fallback_to_software_tonemap`
12. `NvencToneMapperTest::test_scale_cuda_filter`
13. `NvencToneMapperTest::test_zscale_fallback`
14. `VaapiToneMapperTest::test_vaapi_tonemap_args`
15. `QsvToneMapperTest::test_vpp_tone_mapping_args`

**Coverage target:** `HwaccelToneMapper` ≥ 85 %, each vendor tone mapper ≥ 75 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New public class/method"** → all new public classes get PHPDoc
  with `@since 0.11.0`.
- **"Anything"** → `docs/developers/hardware-acceleration.md` updated
  with HDR tone-mapping section.
- **"User-visible behavior change"** → CHANGELOG entry.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `HdrMetadata::isHdr()` returns `true` for `smpte2084` (PQ) and
      `arib-std-b67` (HLG) transfers, `false` for `bt709`.
- [ ] `NvencToneMapper::getFilterChain()` returns a filter chain containing
      `tonemap_cuda` or `zscale` as fallback.
- [ ] `VaapiToneMapper::getFilterChain()` returns a filter chain with
      VAAPI-specific tonemap filters.
- [ ] `QsvToneMapper::getFilterChain()` returns a filter chain with
      `vpp tone_mapping` parameters.
- [ ] `HwaccelToneMapper::vendorSupportsHwToneMap('videotoolbox')` returns
      `false` (VideoToolbox has no HW tonemap).
- [ ] `HwaccelToneMapper::detectHdrFromProbe()` extracts color metadata
      from ffprobe JSON and returns `HdrMetadata`.
- [ ] `HwaccelCommandBuilder::setHdrMetadata()` injects the tonemap
      filter chain into the built command.
- [ ] `FfmpegRunner::probe()` returns `color_space`, `color_transfer`,
      `color_primaries`, `max_luminance` in the result.
- [ ] `./vendor/bin/phpunit` — green; ≥ 15 new tests.
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
git checkout -b e.3-hdr-tonemap

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'ToneMapper|ToneMap|HdrMetadata'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step E.3: HDR→SDR hardware tone-mapping (NVENC/VAAPI/QSV/AMF/VideoToolbox/V4L2)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step E.3: HDR→SDR hardware tone-mapping" \
  --body  "Adds HdrMetadata, HwaccelToneMapper, per-vendor tone mapper classes (NvencToneMapper, VaapiToneMapper, QsvToneMapper, VideoToolboxToneMapper, AmfToneMapper, V4L2ToneMapper, SoftwareToneMapper), and integration into HwaccelCommandBuilder. Part of Phase E (Step E.3 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'e.3-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `e.3-hdr-tonemap-review.md`.

Non-obvious points:
- Tone-mapping is **only applied** when the source is HDR (`isHdr() == true`)
  and the output is being transcoded to SDR. Direct-play of HDR content
  does not go through the tone-mapper.
- Each vendor tone mapper has two paths: a fast hardware path (if the vendor
  supports it) and a `zscale`-based software fallback. The software fallback
  is the same `zscale=transfer=bt709` chain used by the SoftwareToneMapper.
- `max_luminance` and `avg_luminance` from ffprobe are used to compute the
  tone-mapping curve parameters (e.g. `desat` in the NVENC tonemap_cuda
  filter). If ffprobe does not return luminance data, default to 1000/200.
