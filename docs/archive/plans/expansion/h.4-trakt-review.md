# Review: Step H.4 — Trakt scrobble plugin

**Step:** H.4
**Plan file:** `h.4-trakt.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show H.4 squashed commit
git branch --list 'h.4-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 19 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'TraktApi|TraktSettings|TraktHistorySync|TraktPlugin'
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

For each acceptance criterion in `h.4-trakt.md` §7:

- [ ] `TraktApi` full OAuth2 PKCE flow with token refresh on 401
- [ ] `TraktPlugin::onPlaybackStarted()` → `scrobbleStart()`
- [ ] `TraktPlugin::onPlaybackStopped()` → `scrobbleStop()`
- [ ] `TraktPlugin::onPlaybackProgressUpdated()` → `scrobblePause()`
- [ ] `TraktHistorySync::syncTraktToPhlex()` pulls Trakt history → Phlex
- [ ] `TraktHistorySync::syncPhlexToTrakt()` pushes ≥90% items to Trakt
- [ ] OAuth callback at `/api/v1/oauth/trakt/callback` stores tokens
- [ ] `config/scrobblers/trakt.php` exists with all required keys
- [ ] `phlex-plugin-trakt/plugin.json` valid manifest
- [ ] `docs/developers/scrobbler-plugins.md` written
- [ ] CHANGELOG has H.4 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-H.4 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.14.0`
- Coverage of any new class drops below 85%
- OAuth PKCE is not implemented (Trakt requires it)
- History sync does not respect the 90% completion threshold
