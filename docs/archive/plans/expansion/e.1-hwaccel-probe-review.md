# Review: Step E.1 — Hwaccel probe & profile registry

**Step:** E.1
**Plan file:** `e.1-hwaccel-probe.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show E.1 squashed commit
git branch --list 'e.1-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 11 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'HwaccelProbe|HwaccelRegistry'
# Expected: HwaccelProbe ≥ 85%, HwaccelRegistry ≥ 85%

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

For each acceptance criterion in `e.1-hwaccel-probe.md` §7:

- [ ] `HwaccelCapability` is an immutable value object with all fields documented
- [ ] `HwaccelProbe::probe()` returns a `string→HwaccelCapability` map
- [ ] `HwaccelProbe::isVendorAvailable('nvenc')` returns a bool without throwing
- [ ] `HwaccelRegistry::getInstance()` returns the same instance on repeated calls
- [ ] `HwaccelRegistry::getEncoder('h264')` returns a `HwaccelCapability` or null
- [ ] `HwaccelRegistry::getEncoder('hevc', require_hdr_tone_map: true)` only
      returns capabilities where `supports_hdr_tone_mapping` is `true`
- [ ] `HwaccelRegistry::getVendorPriority()` returns an ordered array
- [ ] `FfmpegRunner` has `HwaccelRegistry` injected and a
      `probeHardwareAcceleration()` method
- [ ] `config/hwaccel.php` exists with all required keys
- [ ] `docs/developers/hardware-acceleration.md` written
- [ ] CHANGELOG has E.1 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-E.1 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.11.0`
- Coverage of `HwaccelProbe` or `HwaccelRegistry` drops below 85%
