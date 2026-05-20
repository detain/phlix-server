# Review: Step G.3 — Last.fm scrobble plugin

**Step:** G.3
**Plan file:** `g.3-lastfm.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show G.3 squashed commit
git branch --list 'g.3-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 12 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'LastfmApiClient|Plugin'
# Expected: LastfmApiClient ≥ 85%, Plugin ≥ 85%

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

For each acceptance criterion in `g.3-lastfm.md` §7:

- [ ] `ScrobbleData` and `NowPlayingData` are immutable value objects
- [ ] `LastfmApiClient::getMobileSession()` calls the correct endpoint
      with correct HMAC-MD5 signature
- [ ] `LastfmApiClient::scrobble()` builds correct POST body and parses
      response correctly
- [ ] `LastfmApiClient::nowPlaying()` calls `track.updateNowPlaying`
- [ ] `Plugin::onPlaybackStopped()` is called when `phlex.playback.stopped`
      event is dispatched
- [ ] `Plugin::onPlaybackStopped()` only scrobbles when enabled + configured
      + threshold met
- [ ] `config/lastfm.php` exists with all documented keys
- [ ] `docs/plugins/developer-guide.md` updated with scrobbler type section
- [ ] CHANGELOG has G.3 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-G.3 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.15.0`
- Coverage of `LastfmApiClient` or `Plugin` drops below 85%
- Last.fm HMAC-MD5 signature is incorrectly computed
- Scrobble threshold logic is not implemented
