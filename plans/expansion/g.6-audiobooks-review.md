# Review: Step G.6 — Audiobooks: M4B chapter handling + progress tracking

**Step:** G.6
**Plan file:** `g.6-audiobooks.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show G.6 squashed commit
git branch --list 'g.6-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 15 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'AudiobookScanner|AudiobookProgressStore|AudiobookController'
# Expected: AudiobookScanner ≥ 85%, AudiobookProgressStore ≥ 85%

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

For each acceptance criterion in `g.6-audiobooks.md` §7:

- [ ] `AudiobookScanner::harvestChapters()` parses M4B `chpl` atom into
      chapter array; returns `[]` without throwing on files without
      chapters
- [ ] `AudiobookScanner::harvestAudiobookMetadata()` extracts all
      documented fields from M4B MP4 tags
- [ ] `AudiobookScanner::scanAudiobookLibrary()` yields media items with
      `chapters` key in `metadata_json`
- [ ] `AudiobookProgressStore::saveProgress()` persists to DB and
      `getProgress()` retrieves it correctly
- [ ] `AudiobookProgressStore::markChapterComplete()` updates
      `completed_chapters` JSON array
- [ ] `AudiobookLibraryManager` correctly extends `BookLibraryManager`
      with audiobook-specific overrides
- [ ] `AudiobookController::getAudiobook()` returns full chapter list
- [ ] `AudiobookController::streamAudiobook()?chapter=N&offset=MS`
      starts streaming from correct byte offset within the specified chapter
- [ ] `AudiobookLibraryType` is registered as library type `'audiobook'`
- [ ] Migration `00X_audiobook_progress.sql` creates
      `audiobook_progress` table with correct schema
- [ ] Smarty templates exist for audiobook list, detail, player
- [ ] `docs/libraries/audiobooks.md` written
- [ ] CHANGELOG has G.6 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-G.6 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.18.0`
- Coverage of `AudiobookScanner` or `AudiobookProgressStore` drops below 85%
- M4B chapter parsing uses string concatenation instead of proper binary parsing
- Progress save does not persist chapter index + position-within-chapter
- Multi-file series detection is broken for existing TV-style naming conventions
