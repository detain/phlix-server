# Review: Step G.4 — Photos: EXIF extraction + slideshow

**Step:** G.4
**Plan file:** `g.4-photos.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show G.4 squashed commit
git branch --list 'g.4-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 11 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'PhotoScanner|PhotoLibraryManager|PhotoController'
# Expected: PhotoScanner ≥ 85%, PhotoLibraryManager ≥ 85%

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

For each acceptance criterion in `g.4-photos.md` §7:

- [ ] `PhotoScanner::harvestExif()` returns structured array with all
      documented EXIF fields; returns `[]` without throwing on non-JPEG
- [ ] `PhotoScanner::scanPhotoLibrary()` yields media item arrays
- [ ] `PhotoLibraryManager::rescanLibrary()` orchestrates full pipeline
- [ ] `ExifProvider::getPhotoMetadata()` returns full EXIF array from
      `metadata_json`
- [ ] `PhotoController` serves `/photo/albums`, `/photo/photos`,
      `/photo/photos/{id}/thumbnail`, `/photo/slideshow` correctly
- [ ] `PhotoController::getThumbnail()` returns resized image with
      correct Content-Type header
- [ ] `PhotoLibraryType` is registered as library type `'photo'`
- [ ] `LibraryManager` routes photo-type libraries to `PhotoLibraryManager`
- [ ] Smarty templates exist for albums, photo view, slideshow
- [ ] `docs/libraries/photos.md` written
- [ ] CHANGELOG has G.4 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-G.4 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.16.0`
- Coverage of `PhotoScanner` or `PhotoLibraryManager` drops below 85%
- EXIF parser requires a non-pure-PHP extension without graceful fallback
- Thumbnail generation does not use GD (already in stack)
