# Review: Step E.3 — HDR→SDR hardware tone-mapping

**Step:** E.3
**Plan file:** `e.3-hdr-tonemap.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show E.3 squashed commit
git branch --list 'e.3-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 15 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'ToneMapper|ToneMap|HdrMetadata'
# Expected: HwaccelToneMapper ≥ 85%, each vendor tone mapper ≥ 75%

# ─── Static analysis ───
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
# Expected: [OK] No errors

# ─── Code style ───
./vendor/bin/phpcs --standard=PSR12 src/
# Expected: clean (warnings OK, 0 errors)

# ─── Syntax ───
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Expected: empty output
```

## 3. Check deliverables

For each acceptance criterion in `e.3-hdr-tonemap.md` §7:

- [ ] `HdrMetadata::isHdr()` returns `true` for `smpte2084` (PQ) and
      `arib-std-b67` (HLG) transfers, `false` for `bt709`
- [ ] `NvencToneMapper::getFilterChain()` returns a filter chain containing
      `tonemap_cuda` or `zscale` as fallback
- [ ] `VaapiToneMapper::getFilterChain()` returns a filter chain with VAAPI-specific tonemap filters
- [ ] `QsvToneMapper::getFilterChain()` returns a filter chain with `vpp tone_mapping` parameters
- [ ] `HwaccelToneMapper::vendorSupportsHwToneMap('videotoolbox')` returns `false`
- [ ] `HwaccelToneMapper::detectHdrFromProbe()` extracts color metadata from ffprobe JSON
- [ ] `HwaccelCommandBuilder::setHdrMetadata()` injects the tonemap filter chain into the built command
- [ ] `FfmpegRunner::probe()` returns color metadata in the result
- [ ] `docs/developers/hardware-acceleration.md` updated
- [ ] CHANGELOG has E.3 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-E.3 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.11.0`
- Coverage of `HwaccelToneMapper` drops below 85%
