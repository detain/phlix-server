# Review: Step G.2 — Music player route + ID3/MP4 tag harvest

**Step:** G.2
**Plan file:** `g.2-music-player.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show G.2 squashed commit
git branch --list 'g.2-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 14 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'AudioScanner|MusicLibraryManager|MusicController'
# Expected: AudioScanner ≥ 85%, MusicLibraryManager ≥ 85%

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

For each acceptance criterion in `g.2-music-player.md` §7:

- [ ] `AudioScanner::harvestTags()` returns structured array with all
      documented fields; returns `[]` without throwing on parse failure
- [ ] `AudioScanner::scanMusicLibrary()` yields media item arrays
- [ ] `MusicLibraryManager::rescanLibrary()` orchestrates full pipeline
- [ ] `MusicLibraryManager::upsertTrack()` stores audio metadata in DB
- [ ] `MusicController` serves `/music/artists`, `/music/albums`,
      `/music/tracks`, `/music/now-playing` with JSON responses
- [ ] `GET /music/artists/{mbid}` returns artist detail with albums
- [ ] `GET /music/albums/{mbid}` returns album detail with track listing
- [ ] `MusicLibraryType` is registered as library type `'music'`
- [ ] `LibraryManager` routes music-type libraries to `MusicLibraryManager`
- [ ] Smarty templates exist for artists, albums, tracks, player
- [ ] `docs/libraries/music.md` written
- [ ] CHANGELOG has G.2 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-G.2 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.14.0`
- Coverage of `AudioScanner` or `MusicLibraryManager` drops below 85%
- ID3 tag parser requires a non-pure-PHP extension without documented fallback
- `getID3` library choice is not documented in the approach section
