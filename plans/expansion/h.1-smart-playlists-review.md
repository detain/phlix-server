# Review: Step H.1 — Smart-playlist rule engine

**Step:** H.1
**Plan file:** `h.1-smart-playlists.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show H.1 squashed commit
git branch --list 'h.1-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 29 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'SmartPlaylistEngine|RuleNode|RuleOperators|SmartPlaylistRepository'
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

For each acceptance criterion in `h.1-smart-playlists.md` §7:

- [ ] `RuleNode` AST with TYPE_AND/OR/NOT/RULE constants
- [ ] `RuleOperators` covers all 11 operators
- [ ] `SmartPlaylistEngine::buildFromDsl()` parses JSON DSL
- [ ] `SmartPlaylistEngine::evaluate()` applies AND/OR/NOT logic correctly
- [ ] `SmartPlaylistEngine::toJson()` round-trips RuleNode → JSON
- [ ] `SmartPlaylistRepository` full CRUD with parameterized queries
- [ ] `migrations/004_smart_playlists.sql` runs cleanly
- [ ] `SmartPlaylistRefreshHandler` subscribes to `LibraryUpdated`
- [ ] All 6 REST endpoints wired in `SmartPlaylistController`
- [ ] `POST /api/v1/smart-playlists/{id}/preview` evaluates without saving
- [ ] `docs/developers/smart-playlists.md` written with DSL reference
- [ ] CHANGELOG has H.1 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-H.1 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.14.0`
- Coverage of any new class drops below 85%
- JSON DSL does not support nested AND/OR/NOT groups
- RuleOperators missing any documented operator
