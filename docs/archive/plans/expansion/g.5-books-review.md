# Review: Step G.5 — Books: EPUB/PDF/CBZ support + OPDS feed

**Step:** G.5
**Plan file:** `g.5-books.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show G.5 squashed commit
git branch --list 'g.5-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 16 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'BookScanner|OpdsFeedBuilder|BookController'
# Expected: BookScanner ≥ 85%, OpdsFeedBuilder ≥ 85%

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

For each acceptance criterion in `g.5-books.md` §7:

- [ ] `BookScanner::harvestEpub()` parses EPUB metadata and cover
- [ ] `BookScanner::harvestPdf()` extracts PDF metadata fields
- [ ] `BookScanner::harvestCbz()` extracts CBZ page list and cover
- [ ] `BookScanner::scanBookLibrary()` yields media item arrays
- [ ] `BookLibraryManager::rescanLibrary()` orchestrates full pipeline
- [ ] `OpdsFeedBuilder::buildAcquisitionFeed()` produces valid OPDS 1.2
      XML with correct namespaces
- [ ] `OpdsFeedBuilder::buildEntry()` includes `dc:title`, `dc:creator`,
      `opds:link rel=acquisition`
- [ ] `BookController::opdsRoot()` returns OPDS root feed at `/opds/v1.2`
- [ ] `BookController::opdsLibraries()` returns OPDS navigation feed
- [ ] `BookController::opdsLibraryBooks()` returns OPDS acquisition feed
      with pagination
- [ ] `BookLibraryType` is registered as library type `'book'`
- [ ] Smarty templates exist for book list, book detail, reader stub
- [ ] `docs/libraries/books.md` written
- [ ] `docs/reference/api.md` updated with OPDS endpoints
- [ ] CHANGELOG has G.5 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-G.5 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.17.0`
- Coverage of `BookScanner` or `OpdsFeedBuilder` drops below 85%
- OPDS XML is generated via string concatenation instead of DOMDocument
- PDF metadata extraction requires a native extension without pure-PHP fallback
