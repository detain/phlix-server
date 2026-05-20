# Review: Step F.3 — Marker storage + API

**Step:** F.3
**Plan file:** `f.3-markers-api.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show F.3 squashed commit
git branch --list 'f.3-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 12 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'MarkerService|MarkerController'
# Expected: each ≥ 85%

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

For each acceptance criterion in `f.3-markers-api.md` §7:

- [ ] `003_marker_columns.sql` migration runs without error
- [ ] `MarkerService::getMarkers()` returns `MarkerSet` with correct structure
- [ ] `MarkerService::promoteCandidates()` writes the 4 `_seconds` columns
- [ ] `MarkerService::promoteShowMarkers()` returns a count of promoted items
- [ ] `MarkerController::getMarkers()` returns 200 with `{ intro, outro, chapters }`
- [ ] `MarkerController::getIntroMarker()` returns 200 with `{ start, end, confidence }`
- [ ] `MarkerController::getOutroMarker()` returns 200 with `{ start, end, confidence }`
- [ ] `MarkerController::getShowMarkers()` returns bulk array for a show
- [ ] All 4 routes registered in `Router.php`
- [ ] `docs/reference/api.md` updated with marker endpoints
- [ ] CHANGELOG has F.3 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-F.3 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.12.0`
- Coverage of `MarkerService` or `MarkerController` drops below 85%
- The migration uses `ALTER TABLE` without checking the column doesn't
  already exist (must be idempotent)
- Any route returns a non-JSON response
