# Review: Step F.5 — Comskip for Live TV recordings

**Step:** F.5
**Plan file:** `f.5-comskip.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show F.5 squashed commit
git branch --list 'f.5-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 10 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Comskip'
# Expected: ComskipRunner ≥ 85%, ComskipEdlParser ≥ 85%, ComskipPostProcessor ≥ 85%

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

For each acceptance criterion in `f.5-comskip.md` §7:

- [ ] `ComskipRunner::isAvailable()` returns `bool` without throwing
- [ ] `ComskipRunner::run()` executes `comskip` on the recording path
- [ ] `ComskipEdlParser::parse()` returns `array<ChapterMarker>` from EDL file
- [ ] `ComskipEdlParser` ignores segments shorter than `min_commercial_length`
- [ ] `ComskipPostProcessor::processRecording()` is idempotent
- [ ] `ComskipPostProcessor::processRecording()` stores chapters via
      `MarkerService::storeChapters()`
- [ ] `Recorder::onComplete()` hook fires after a recording completes
- [ ] `RecordingHooks::register()` wires post-processor into the recorder
- [ ] `config/comskip.php` exists with all required keys
- [ ] `docs/advanced/live-tv-comskip.md` written
- [ ] CHANGELOG has F.5 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-F.5 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.12.0`
- Coverage of any Comskip class drops below 85%
- Comskip is invoked without a timeout (recordings can be hours long;
  comskip analysis must be bounded)
- The hook is not idempotent (running post-processor twice on the same
  recording must not duplicate chapters)
