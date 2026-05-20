# Review: Step G.1 — MusicBrainz + AudioDB providers

**Step:** G.1
**Plan file:** `g.1-music-providers.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show G.1 squashed commit
git branch --list 'g.1-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 13 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'MusicBrainzProvider|AudioDbProvider'
# Expected: MusicBrainzProvider ≥ 85%, AudioDbProvider ≥ 85%

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

For each acceptance criterion in `g.1-music-providers.md` §7:

- [ ] `MetadataProviderInterface` has `MEDIA_TYPE_ALBUM`, `MEDIA_TYPE_ARTIST`,
      `MEDIA_TYPE_TRACK` constants
- [ ] `MusicBrainzProvider` implements all interface methods
- [ ] `MusicBrainzProvider::search()` returns array; handles HTTP errors
- [ ] `MusicBrainzProvider::getArtist()` / `getAlbum()` / `getTrack()`
      return structured arrays
- [ ] `AudioDbProvider` implements all interface methods
- [ ] `AudioDbProvider::search()` returns array; handles missing API key
- [ ] `MusicMetadataProviderTrait::rateLimit()` applies configurable delay
- [ ] `MusicMetadataProviderTrait::mbHeaders()` returns required MusicBrainz
      user-agent + content-type headers
- [ ] `MetadataManager::fetchMetadata()` routes music types to music
      providers; respects fallback chain
- [ ] `config/music_providers.php` exists with all required keys
- [ ] `docs/developers/music-providers.md` written
- [ ] CHANGELOG has G.1 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-G.1 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.13.0`
- Coverage of `MusicBrainzProvider` or `AudioDbProvider` drops below 85%
- MusicBrainz rate-limit or user-agent requirements are not implemented
