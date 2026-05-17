# Step G.5 — Books: EPUB/PDF/CBZ support + OPDS feed

**Phase:** G (Music / Photos / Books / Audiobooks)
**Step:** G.5
**Depends on:** A.4
**Review:** Yes — see `g.5-books-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Add a `book` library type that scans EPUB, PDF, and CBZ (comic book
archive) files, extracts cover thumbnails and metadata (title, author,
ISBN), serves a standard OPDS (Open Publication Distribution System)
feed at `/opds/v1.2` so that third-party OPDS clients (Uboiquity,
Komga, Kore, Moon+ Reader) can browse and download books, and renders
a basic reader stub in the web portal.

## 2. Context (what already exists)

- `src/Media/Library/MediaScanner.php` — existing scanner; will be
  extended to handle `.epub`, `.pdf`, `.cbz` extensions.
- `src/Server/Http/Router.php` — router; OPDS routes will be added here.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Books/comics + EPUB/PDF/CBZ + OPDS
  feed" is **Missing**.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase G table — G.5 is the books step.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Library/BookScanner.php` — extends `MediaScanner` for
  book files; handles EPUB, PDF, CBZ:

  ```php
  class BookScanner extends MediaScanner
  {
      /** Extract metadata from an EPUB file. Returns [] on failure. */
      public function harvestEpub(string $path): array {}
      // {title, author, publisher, isbn, language, pub_date, description, cover_url}

      /** Extract metadata from a PDF file (using pdftk or pure PHP). */
      public function harvestPdf(string $path): array {}
      // {title, author, subject, keywords, creator, producer, creation_date, page_count}

      /** Extract metadata from a CBZ (ZIP comic archive). */
      public function harvestCbz(string $path): array {}
      // {title, series, volume, authors, page_count, cover_page}

      /** Scan a book library and yield item arrays. */
      public function scanBookLibrary(string $library_path): \Generator {}
  }
  ```

- `src/Media/Library/BookLibraryManager.php` — orchestrates scan +
  metadata extraction + upsert:

  ```php
  class BookLibraryManager
  {
      public function __construct(
          private readonly BookScanner $scanner,
          private readonly ItemRepository $item_repo,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public function rescanLibrary(string $library_id): ScanResult {}
      public function upsertBook(string $library_id, string $path): ?MediaItem {}
  }
  ```

- `src/Media/Metadata/OpdsFeedBuilder.php` — builds OPDS 1.2 compliant
  XML feeds:

  ```php
  class OpdsFeedBuilder
  {
      public function __construct(
          private readonly ItemRepository $item_repo,
          private readonly string $base_url,
      ) {}

      /** Build the root OPDS catalog feed. */
      public function buildRootFeed(): string {}

      /** Build a navigation feed for library list. */
      public function buildNavigationFeed(string $title, array $links): string {}

      /** Build an acquisition feed for books in a library. */
      public function buildAcquisitionFeed(string $library_id, int $limit = 50, int $offset = 0): string {}

      /** Build a single entry XML. */
      public function buildEntry(array $book): string {}
      // Includes: dc:title, dc:creator, dc:identifier (ISBN/URN),
      //     opds:link rel=alternate for cover, opds:link rel=acquisition for download
  }
  ```

- `src/Server/Http/Controllers/BookController.php` — book + OPDS
  endpoints:

  ```php
  class BookController
  {
      // OPDS
      // GET /opds/v1.2              — root feed
      // GET /opds/v1.2/libraries    — navigation: list libraries
      // GET /opds/v1.2/libraries/{id}  — acquisition: list books
      // GET /opds/v1.2/books/{id}/cover — cover image

      public function opdsRoot(Request $req): Response {}
      public function opdsLibraries(Request $req): Response {}
      public function opdsLibraryBooks(Request $req, array $params): Response {}

      // Web portal
      // GET /books
      public function listBooks(Request $req): Response {}

      // GET /books/{id}
      public function getBook(Request $req, array $params): Response {}

      // GET /books/{id}/read?page=1
      public function readBook(Request $req, array $params): Response {}
      // Returns HTML reader stub

      // GET /books/{id}/cover
      public function getCover(Request $req, array $params): Response {}

      // GET /books/{id}/download
      public function downloadBook(Request $req, array $params): Response {}
  }
  ```

- `src/Media/Music/BookLibraryType.php` — library type registration:

  ```php
  final class BookLibraryType implements LibraryTypeInterface
  {
      public const TYPE = 'book';
      public function getType(): string {}
      public function getLabel(): string {}
      public function getScanner(): BookScanner {}
  }
  ```

- `public/templates/books/` directory with:
  - `books.tpl` — book grid view
  - `book.tpl` — book detail with cover, metadata, read button
  - `reader.tpl` — reader stub (iframe or simple paginated HTML for EPUB)

- `public/assets/css/books.css` — styles for book grid, cover cards,
  reader layout.
- `public/assets/js/reader.js` — basic reader: page navigation,
  font size controls, theme (sepia/light/dark).

- `tests/unit/Media/Library/BookScannerTest.php`
- `tests/unit/Media/Library/BookLibraryManagerTest.php`
- `tests/unit/Media/Metadata/OpdsFeedBuilderTest.php`
- `tests/unit/Server/Http/Controllers/BookControllerTest.php`

#### Documentation

- `docs/libraries/books.md` — new doc: supported formats, OPDS feed URL,
  how to use with third-party OPDS clients, reader stub limitations,
  naming conventions.
- `docs/reference/api.md` — update to document `/opds/v1.2/*` endpoints.

### Modify

- `src/Media/Library/MediaScanner.php` — add book file detection by
  extension; delegate to `BookScanner`.
- `src/Media/Library/LibraryManager.php` — detect `library_type =
  'book'`; route to `BookLibraryManager`.
- `src/Server/Http/Router.php` — register `/opds/*` routes (OPDS
  namespace) and `/books/*` routes.
- `public/templates/layouts/header.tpl` — add Books nav link.
- `composer.json` — add `tecnickcom/tcpdf` (~3 MB, no native ext
  required) for PDF text extraction if pure-PHP is insufficient.
  Document the choice.
- `config/plugins.php` — register `BookLibraryType`.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b g.5-books`.
2. **EPUB parsing.** ZIP-based format — open with `ZipArchive`, parse
   `content.opf` for metadata, extract cover image. Pure PHP.
3. **PDF metadata.** Try `exif_read_data()` first for XMP/EXIF metadata;
   fall back to pure-PHP TCPDF or strings extraction for basic fields.
4. **CBZ parsing.** ZIP archive — find first JPEG, extract as cover.
   Parse `ComicInfo.xml` if present for extended metadata.
5. **OpdsFeedBuilder.** Build OPDS 1.2 XML per spec
   (https://opds.io/spec/1.2). Use proper namespaced XML, not string
   concatenation; use `DOMDocument`.
6. **BookController.** OPDS endpoints return `Content-Type:
   application/atom+xml; charset=utf-8; profile=opds-catalog`. Web
   portal endpoints return HTML.
7. **LibraryManager integration.** Detect `library_type = 'book'`.
8. **Web portal.** Smarty templates + reader.js. The reader stub renders
   paginated HTML (not a full EPUB renderer — that is a separate
   browser-based effort; the server just provides paginated page images/text).
9. **Tests.** Write all 4 test files per §5.
10. **Verification bar** (§0.4 minimum bar).
11. **Docs.**
12. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `BookScannerTest::test_harvest_epub_parses_content_opf`
2. `BookScannerTest::test_harvest_epub_returns_empty_on_invalid`
3. `BookScannerTest::test_harvest_pdf_extracts_metadata`
4. `BookScannerTest::test_harvest_cbz_extracts_pages_and_cover`
5. `BookScannerTest::test_scan_book_library_yields_items`
6. `BookLibraryManagerTest::test_rescan_library_calls_scanner`
7. `BookLibraryManagerTest::test_upsert_book_stores_metadata`
8. `OpdsFeedBuilderTest::test_build_root_feed_has_opds_namespace`
9. `OpdsFeedBuilderTest::test_build_navigation_feed_contains_links`
10. `OpdsFeedBuilderTest::test_build_acquisition_feed_contains_entries`
11. `OpdsFeedBuilderTest::test_build_entry_has_required_fields`
12. `BookControllerTest::test_opds_root_returns_opds_xml`
13. `BookControllerTest::test_opds_libraries_returns_navigation_feed`
14. `BookControllerTest::test_opds_library_books_returns_acquisition_feed`
15. `BookControllerTest::test_get_book_returns_json`
16. `BookControllerTest::test_download_book_returns_file`

**Coverage target:** `BookScanner` ≥ 85 %, `OpdsFeedBuilder` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New library type (music/photos/books/audiobooks)"** →
  `docs/libraries/books.md` (new): supported formats, OPDS usage,
  third-party client setup, naming conventions.
- **"Public HTTP API"** → OPDS endpoints added; update
  `docs/reference/api.md`.
- **"Anything"** → all new public classes get PHPDoc with `@since 0.17.0`.
- **"User-visible behavior change"** → CHANGELOG entry (book library +
  OPDS feed + reader stub added).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `BookScanner::harvestEpub()` parses EPUB metadata and cover.
- [ ] `BookScanner::harvestPdf()` extracts PDF metadata fields.
- [ ] `BookScanner::harvestCbz()` extracts CBZ page list and cover.
- [ ] `BookScanner::scanBookLibrary()` yields media item arrays.
- [ ] `BookLibraryManager::rescanLibrary()` orchestrates full pipeline.
- [ ] `OpdsFeedBuilder::buildAcquisitionFeed()` produces valid OPDS 1.2
      XML with correct namespaces.
- [ ] `OpdsFeedBuilder::buildEntry()` includes `dc:title`, `dc:creator`,
      `opds:link rel=acquisition`.
- [ ] `BookController::opdsRoot()` returns OPDS root feed at
      `/opds/v1.2`.
- [ ] `BookController::opdsLibraries()` returns OPDS navigation feed.
- [ ] `BookController::opdsLibraryBooks()` returns OPDS acquisition feed
      with pagination (`?offset=N&limit=N`).
- [ ] `BookLibraryType` is registered as library type `'book'`.
- [ ] Smarty templates exist for book list, book detail, reader stub.
- [ ] `./vendor/bin/phpunit` — green; ≥ 16 new tests.
- [ ] Coverage of `BookScanner` ≥ 85 %, `OpdsFeedBuilder` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/libraries/books.md` written.
- [ ] `docs/reference/api.md` updated with OPDS endpoints.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b g.5-books

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'BookScanner|OpdsFeedBuilder|BookController'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step G.5: Books — EPUB/PDF/CBZ + OPDS feed + reader stub"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step G.5: Books — EPUB/PDF/CBZ + OPDS feed + reader stub" \
  --body  "Adds BookScanner (EPUB/PDF/CBZ), BookLibraryManager, OpdsFeedBuilder (OPDS 1.2), BookController (/opds/* + /books/* routes), Smarty book templates. Part of Phase G (Step G.5 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'g.5-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `g.5-books-review.md`.

Non-obvious points:
- OPDS 1.2 requires strict XML namespacing — use `DOMDocument` for
  building feeds, not string concatenation.
- The reader stub is intentionally minimal: paginated HTML view from
  EPUB content, not a full browser-based EPUB renderer. Browser-based
  EPUB rendering (via epub.js or similar) is a future enhancement.
- PDF text extraction uses TCPDF (pure-PHP) so no native extension is
  required on the server.
- CBZ support is included because many comic/graphic novel libraries
  are stored as CBZ; the `ComicInfo.xml` format is supported for
  extended metadata.
