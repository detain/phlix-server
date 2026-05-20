# Review: Step H.6 — Theme music + theme video

**Step:** H.6
**Plan file:** `h.6-theme-media.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show H.6 squashed commit
git branch --list 'h.6-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 13 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'ThemeMediaFinder|ThemeMediaRepository|ThemeMedia'
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

For each acceptance criterion in `h.6-theme-media.md` §7:

- [ ] `ThemeMediaFinder` finds `theme.mp3` / `backdrop.mp4` at library root
- [ ] `ThemeMediaFinder::findForMediaItem()` works for TV series
- [ ] `FFmpegRunner::probe()` extracts duration and resolution
- [ ] `ThemeMediaRepository` caches in `theme_media` table
- [ ] `migrations/008_theme_media.sql` runs cleanly
- [ ] `GET /api/v1/libraries/{id}/theme-media` returns audio + video metadata
- [ ] `POST /api/v1/libraries/{id}/theme-media/scan` triggers rescan
- [ ] `GET /stream/theme-media/{libraryId}/audio` streams with correct Content-Type
- [ ] `GET /stream/theme-media/{libraryId}/video` streams with correct Content-Type
- [ ] `theme-media.js` handles browser autoplay policy (overlay if blocked)
- [ ] `backdrop.mp4` only plays on viewport ≥ 1080px (client-side check)
- [ ] `LibraryManager` triggers theme media scan after library scan
- [ ] `docs/developers/theme-media.md` written
- [ ] CHANGELOG has H.6 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-H.6 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.14.0`
- Coverage of any new class drops below 85%
- Stream controller does not handle range requests for audio seeking
- backdrop.mp4 plays on mobile viewports
- Autoplay blocking not handled gracefully (must show overlay)
