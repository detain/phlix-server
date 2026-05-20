# Review: Step F.1 — Chromaprint integration

**Step:** F.1
**Plan file:** `f.1-chromaprint.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show F.1 squashed commit
git branch --list 'f.1-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 11 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'ChromaPrint|FingerprintRepository'
# Expected: ChromaPrintShelled ≥ 85%, FingerprintRepository ≥ 85%

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

For each acceptance criterion in `f.1-chromaprint.md` §7:

- [ ] `ChromaPrintInterface` defines `fingerprint()` and `isAvailable()`
- [ ] `ChromaPrintFfi` attempts FFI first; `isAvailable()` returns `false`
      gracefully when FFI is unavailable
- [ ] `ChromaPrintShelled` wraps `fpcalc`; `fingerprint()` parses the
      `FINGERPRINT=` output line
- [ ] `ChromaPrintFactory::build()` selects FFI when available, shelled otherwise
- [ ] `FingerprintRepository::storeFingerprint()` persists to `metadata_json`
- [ ] `FingerprintRepository::getFingerprint()` retrieves or returns `''`
- [ ] `FingerprintRepository::getFingerprintedIdsForShow()` returns a list
- [ ] `config/chromaprint.php` exists with all required keys
- [ ] `docs/developers/chromaprint.md` written
- [ ] CHANGELOG has F.1 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-F.1 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.12.0`
- Coverage of `ChromaPrintShelled` or `FingerprintRepository` drops below 85%
- `fpcalc` path is hardcoded without a config fallback
