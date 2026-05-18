# Review: Step H.5 — Trailers + extras

**Step:** H.5
**Plan file:** `h.5-trailers.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show H.5 squashed commit
git branch --list 'h.5-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 16 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'TrailerResolver|TrailerFinder|Trailer|Extra'
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

For each acceptance criterion in `h.5-trailers.md` §7:

- [ ] `TrailerFinder` detects `-trailer.mkv` at same level and in `Trailers/` subfolder
- [ ] `TrailerFinder` ignores non-media extensions
- [ ] `TrailerResolver::getTrailers()` merges local + TMDB with local priority
- [ ] `TrailerResolver::getExtras()` returns non-trailer extras only
- [ ] `TrailerResolver::getAllExtras()` merged and type-priority sorted
- [ ] Results cached in `media_extras` with 24h TTL
- [ ] All three `ExtrasController` endpoints wired
- [ ] `MediaScanner` detects `Trailers/` at scan time
- [ ] `migrations/007_media_extras.sql` runs cleanly
- [ ] `docs/developers/trailers-and-extras.md` written
- [ ] CHANGELOG has H.5 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-H.5 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.14.0`
- Coverage of any new class drops below 85%
- Local trailers not stored in `media_extras` table
- TMDB trailer URL caching does not respect 24h TTL
