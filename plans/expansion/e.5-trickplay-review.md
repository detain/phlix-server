# Review: Step E.5 — Trickplay / BIF thumbnail seek

**Step:** E.5
**Plan file:** `e.5-trickplay.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show E.5 squashed commit
git branch --list 'e.5-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 13 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Trickplay'
# Expected: TrickplayGenerator ≥ 85%, TrickplayController ≥ 85%

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

For each acceptance criterion in `e.5-trickplay.md` §7:

- [ ] `TrickplayConfig` has sensible defaults (10s interval, 8×4 grid, 160×90px)
- [ ] `TrickplayGenerator::generate()` produces at least one grid image file per job
- [ ] `TrickplayGenerator::generateIndex()` produces a valid BIF index XML with
      `offset` and `length` attributes per thumbnail
- [ ] `TrickplayController::getThumbnailUrl()` returns `/trickplay/{jobId}/thumb-{index}.jpg`
- [ ] `TrickplayController::getIndexUrl()` returns `/trickplay/{jobId}/index.xml`
- [ ] `TrickplayController::getThumbnail()` returns image content with correct Content-Type
- [ ] `TrickplayController::getIndex()` returns XML with `Content-Type: application/xml`
- [ ] `TrickplayGenerator::cleanup()` removes all trickplay files for the job
- [ ] `FfmpegRunner::generateThumbnail()` supports batch extraction (array of timestamps)
- [ ] Trickplay routes are wired in `Router.php`
- [ ] `config/trickplay.php` exists with all configuration keys
- [ ] `docs/developers/streaming-protocols.md` updated
- [ ] CHANGELOG has E.5 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-E.5 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.11.0`
- Coverage of `TrickplayGenerator` or `TrickplayController` drops below 85%
