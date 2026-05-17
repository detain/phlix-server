# Review: Step F.4 — Player UI: skip button protocol

**Step:** F.4
**Plan file:** `f.4-skip-protocol.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show F.4 squashed commit
git branch --list 'f.4-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 8 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'SkipButtonSpec|PlaybackMarkerService'
# Expected: SkipButtonSpec ≥ 90%, PlaybackMarkerService ≥ 85%

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

For each acceptance criterion in `f.4-skip-protocol.md` §7:

- [ ] `SkipButtonSpec::toArray()` returns `{ skip_intro_start, ... }`
      with `null` for unavailable markers
- [ ] `SkipButtonSpec::fromMarkerSet()` correctly maps `MarkerSet` fields
- [ ] `PlaybackMarkerService::getFullSpec()` returns a spec for any item
- [ ] `PlaybackMarkerService::getSkipSpec()` returns nulls for markers
      outside the current position range
- [ ] `MediaItemController::getPlaybackInfo()` includes `markers` in response
- [ ] `docs/reference/skip-button-protocol.md` written with full JSON spec
- [ ] `docs/clients/skip-button-integration-brief.md` written for Phase M
      client teams
- [ ] CHANGELOG has F.4 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-F.4 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.12.0`
- Coverage of `SkipButtonSpec` drops below 90% or `PlaybackMarkerService`
  below 85%
- The `markers` key in playback-info response uses different field names
  than the spec in the documentation
- Client brief is missing or does not contain the JSON example
