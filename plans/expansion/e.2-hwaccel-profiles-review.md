# Review: Step E.2 — Hardware encoder profiles

**Step:** E.2
**Plan file:** `e.2-hwaccel-profiles.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show E.2 squashed commit
git branch --list 'e.2-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 16 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'HwaccelProfile|HwaccelCommand|NvencProfile|VaapiProfile|QsvProfile'
# Expected: HwaccelProfileFactory ≥ 85%, HwaccelCommandBuilder ≥ 85%,
#           each vendor profile ≥ 80%

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

For each acceptance criterion in `e.2-hwaccel-profiles.md` §7:

- [ ] `HwaccelEncoderProfileInterface` defines all 5 methods with PHPDoc
- [ ] `NvencProfile::getEncoderName()` returns `'h264_nvenc'` or `'hevc_nvenc'`
- [ ] `NvencProfile::getQualityArgs('high', 5000000)` returns a string containing `-preset p4`
- [ ] `VaapiProfile::getInputDeviceArgs()` returns a string containing `-vaapi_device`
- [ ] `HwaccelProfileFactory::getProfile('nvenc', 'h264')` returns `NvencProfile`
- [ ] `HwaccelProfileFactory::getProfile('nvenc', 'h264')` returns `SoftwareProfile`
      if NVENC is not available
- [ ] `HwaccelCommandBuilder` produces a valid ffmpeg command string with hwaccel flags
- [ ] `FfmpegRunner::buildTranscodeCommand()` accepts a profile argument
- [ ] `QualitySelector::selectQuality()` returns vendor-specific codec names
- [ ] `config/hwaccel_profiles.php` defines quality level mappings for all 7 vendors
- [ ] `docs/developers/hardware-acceleration.md` updated
- [ ] CHANGELOG has E.2 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-E.2 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.11.0`
- Coverage of `HwaccelProfileFactory` or `HwaccelCommandBuilder` drops below 85%
