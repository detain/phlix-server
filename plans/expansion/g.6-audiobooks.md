# Step G.6 — Audiobooks: M4B chapter handling + progress tracking

**Phase:** G (Music / Photos / Books / Audiobooks)
**Step:** G.6
**Depends on:** G.5
**Review:** Yes — see `g.6-audiobooks-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Add an `audiobook` library type with M4B chapter awareness (chapters
extracted from MP4 atoms, stored in `media_items.metadata_json`), per-user
chapter-level progress tracking, resume-in-chapter on next play, and an
audiobook player view in the web portal that mirrors the Plex audiobook
agent feature set. This is the final step of Phase G, bringing audiobook
parity with the podcast/books features of Plex/Emby/Jellyfin.

## 2. Context (what already exists)

- `src/Media/Library/BookScanner.php` — after G.5, already handles EPUB
  and PDF; will be extended for M4B / MP3 audiobook formats.
- `src/Media/Library/BookLibraryManager.php` — after G.5, orchestrates
  book scans. G.6 extends it for audiobook chapter extraction.
- `src/Session/PlaybackController.php` — existing playback controller;
  used as the model for audiobook session tracking.
- `src/Session/WatchHistory.php` — existing 90%-completion tracking;
  will be extended for chapter-level tracking.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase G table — G.6 is the audiobook step.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Library/AudiobookScanner.php` — extends `BookScanner` for
  M4B / MP3 / M4A audiobook files; handles chapter extraction from MP4
  `chpl` atom or from ID3v2 CMT2/CHAP frames:

  ```php
  class AudiobookScanner extends BookScanner
  {
      /** Extract chapters from an M4B/MP4 file. Returns [] if no chapters. */
      public function harvestChapters(string $path): array {}
      // [{title, start_ms, end_ms, duration_ms, path_hint}]

      /** Extract full audiobook metadata (title, author, narrator, series,
          description, cover) from M4B MP4 tags. */
      public function harvestAudiobookMetadata(string $path): array {}
      // {title, author, narrator, series, series_position,
      //  description, duration_ms, language, isbn}

      /** Scan an audiobook library and yield item arrays with chapters. */
      public function scanAudiobookLibrary(string $library_path): \Generator {}
  }
  ```

- `src/Media/Library/AudiobookLibraryManager.php` — extends
  `BookLibraryManager` for audiobook-specific logic:

  ```php
  class AudiobookLibraryManager extends BookLibraryManager
  {
      public function __construct(
          private readonly AudiobookScanner $scanner,
          private readonly ItemRepository $item_repo,
          private readonly AudiobookProgressStore $progress_store,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public function rescanLibrary(string $library_id): ScanResult {}
      public function upsertAudiobook(string $library_id, string $path): ?MediaItem {}

      /** Get user's progress for an audiobook. */
      public function getProgress(string $user_id, string $audiobook_id): AudiobookProgress {}

      /** Save user's progress (completed chapters, current position). */
      public function saveProgress(string $user_id, string $audiobook_id, AudiobookProgress $progress): void {}
  }
  ```

- `src/Media/Library/AudiobookProgress.php` — value object for progress:

  ```php
  final class AudiobookProgress
  {
      public function __construct(
          public readonly string $audiobook_id,
          public readonly string $user_id,
          public readonly int $position_ms,        // current position within chapter
          public readonly int $current_chapter_index,
          public readonly array $completed_chapters,  // [chapter_index => position_ms]
          public readonly float $percent_complete,       // 0.0 – 100.0
          public readonly ?int $last_played_at,
      ) {}
  }
  ```

- `src/Media/Library/AudiobookProgressStore.php` — persists progress to
  DB (extends `WatchHistory` pattern):

  ```php
  class AudiobookProgressStore
  {
      public function __construct(private readonly Connection $db) {}

      public function getProgress(string $user_id, string $audiobook_id): ?AudiobookProgress {}
      public function saveProgress(AudiobookProgress $progress): void {}
      public function markChapterComplete(string $user_id, string $audiobook_id, int $chapter_index): void {}
  }
  ```

- `src/Server/Http/Controllers/AudiobookController.php` — audiobook API:

  ```php
  class AudiobookController
  {
      // GET /audiobooks
      public function listAudiobooks(Request $req): Response {}

      // GET /audiobooks/{id}
      public function getAudiobook(Request $req, array $params): Response {}
      // Returns: {id, title, author, narrator, series, chapters: [...], cover_url}

      // GET /audiobooks/{id}/progress
      public function getProgress(Request $req, array $params): Response {}

      // POST /audiobooks/{id}/progress
      public function saveProgress(Request $req, array $params): Response {}

      // GET /audiobooks/{id}/chapters
      public function getChapters(Request $req, array $params): Response {}
      // Returns: [{index, title, start_ms, end_ms, duration_ms}]

      // GET /audiobooks/{id}/read
      public function readAudiobook(Request $req, array $params): Response {}
      // Returns HTML audiobook player stub

      // GET /audiobooks/{id}/stream?chapter=N&offset=MS
      public function streamAudiobook(Request $req, array $params): Response {}
  }
  ```

- `src/Media/Music/AudiobookLibraryType.php` — library type registration:

  ```php
  final class AudiobookLibraryType implements LibraryTypeInterface
  {
      public const TYPE = 'audiobook';
      public function getType(): string {}
      public function getLabel(): string {}
      public function getScanner(): AudiobookScanner {}
  }
  ```

- `migrations/00X_audiobook_progress.sql` — progress tracking table:

  ```sql
  CREATE TABLE audiobook_progress (
      user_id       CHAR(36) NOT NULL,
      audiobook_id  CHAR(36) NOT NULL,
      position_ms   INT UNSIGNED NOT NULL DEFAULT 0,
      current_chapter_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      completed_chapters JSON NOT NULL DEFAULT '[]',
      percent_complete DECIMAL(5,2) NOT NULL DEFAULT 0.00,
      last_played_at INT UNSIGNED NOT NULL,
      PRIMARY KEY (user_id, audiobook_id),
      INDEX (audiobook_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```

- `public/templates/audiobooks/` directory with:
  - `audiobooks.tpl` — audiobook library grid
  - `audiobook.tpl` — detail with chapter list, cover, author/narrator
  - `player.tpl` — chapter-aware audiobook player
  - `partials/chapter_row.tpl`

- `public/assets/css/audiobooks.css` — styles for audiobook grid,
  chapter list, player bar with chapter indicator.
- `public/assets/js/audiobook-player.js` — player with chapter nav,
  position persistence every 10s, skip ±30s buttons.

- `tests/unit/Media/Library/AudiobookScannerTest.php`
- `tests/unit/Media/Library/AudiobookProgressStoreTest.php`
- `tests/unit/Media/Library/AudiobookLibraryManagerTest.php`
- `tests/unit/Server/Http/Controllers/AudiobookControllerTest.php`

#### Documentation

- `docs/libraries/audiobooks.md` — new doc: supported formats (M4B,
  MP3), chapter extraction, progress tracking, player usage, naming
  conventions for multi-file series.

### Modify

- `src/Media/Library/BookScanner.php` — add M4B chapter detection by
  MP4 atom `chpl`; delegate audiobook files to `AudiobookScanner`.
- `src/Media/Library/LibraryManager.php` — detect `library_type =
  'audiobook'`; route to `AudiobookLibraryManager`.
- `src/Media/Library/BookLibraryManager.php` — no changes; G.6 extends
  it via inheritance, not modification.
- `src/Server/Http/Router.php` — register `/audiobooks/*` routes.
- `public/templates/layouts/header.tpl` — add Audiobooks nav link.
- `config/plugins.php` — register `AudiobookLibraryType`.
- `composer.json` — no new runtime dependencies.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b g.6-audiobooks`.
2. **Chapter extraction.** M4B stores chapters in the MP4 `chpl`
   atom (binary). Parse it using PHP's `file_get_contents()` + binary
   string unpacking. For MP3 audiobooks, parse ID3v2 CMT2/CHAP frames
   using existing ID3 infrastructure from G.2.
3. **AudiobookProgress value object.** Immutable; all fields readonly.
4. **AudiobookProgressStore.** Uses `Workerman\MySQL\Connection`
   (not PDO). `completed_chapters` stored as JSON array.
5. **AudiobookLibraryManager.** Extends `BookLibraryManager`. Override
   `upsertAudiobook()` to also store chapter list in `metadata_json`.
6. **AudiobookController.** Chapter-aware streaming; `streamAudiobook()`
   supports `?chapter=N&offset=MS` to resume in-chapter. Returns HLS
   or direct file stream.
7. **Player UI.** Smarty template + JS. Chapter list on left, player
   controls on right. Progress bar shows chapter completion. JS saves
   progress every 10 seconds via `POST /audiobooks/{id}/progress`.
8. **LibraryManager integration.** Detect `library_type = 'audiobook'`.
9. **Migration.** Run `php scripts/run-migrations.php` with the new
   migration file.
10. **Tests.** Write all 4 test files per §5.
11. **Verification bar** (§0.4 minimum bar).
12. **Docs.**
13. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `AudiobookScannerTest::test_harvest_chapters_parses_m4b_chpl_atom`
2. `AudiobookScannerTest::test_harvest_chapters_returns_empty_on_no_chapters`
3. `AudiobookScannerTest::test_harvest_audiobook_metadata_extracts_all_fields`
4. `AudiobookScannerTest::test_scan_audiobook_library_yields_items_with_chapters`
5. `AudiobookProgressStoreTest::test_save_and_retrieve_progress`
6. `AudiobookProgressStoreTest::test_mark_chapter_complete`
7. `AudiobookProgressStoreTest::test_get_progress_returns_null_for_new_user`
8. `AudiobookLibraryManagerTest::test_upsert_audiobook_stores_chapters`
9. `AudiobookLibraryManagerTest::test_get_progress_returns_zero_for_new_user`
10. `AudiobookLibraryManagerTest::test_save_progress_persists_to_store`
11. `AudiobookControllerTest::test_get_audiobook_returns_json_with_chapters`
12. `AudiobookControllerTest::test_get_chapters_returns_chapter_list`
13. `AudiobookControllerTest::test_get_progress_returns_user_progress`
14. `AudiobookControllerTest::test_save_progress_accepts_progress_payload`
15. `AudiobookControllerTest::test_stream_audiobook_resumes_in_chapter`

**Coverage target:** `AudiobookScanner` ≥ 85 %, `AudiobookProgressStore` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New library type (music/photos/books/audiobooks)"** →
  `docs/libraries/audiobooks.md` (new): supported formats, chapter
  extraction, naming conventions for multi-file series, progress
  tracking, player usage.
- **"Public HTTP API"** → API routes added; docs reference updated in
  Phase N.21.
- **"New library type (music/photos/books/audiobooks)"** → see above.
- **"Anything"** → all new public classes get PHPDoc with `@since 0.18.0`.
- **"User-visible behavior change"** → CHANGELOG entry (audiobook library
  + chapter-aware player + progress tracking added).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `AudiobookScanner::harvestChapters()` parses M4B `chpl` atom into
      chapter array; returns `[]` without throwing on files without
      chapters.
- [ ] `AudiobookScanner::harvestAudiobookMetadata()` extracts all documented
      fields from M4B MP4 tags.
- [ ] `AudiobookScanner::scanAudiobookLibrary()` yields media items with
      `chapters` key in `metadata_json`.
- [ ] `AudiobookProgressStore::saveProgress()` persists to DB and
      `getProgress()` retrieves it correctly.
- [ ] `AudiobookProgressStore::markChapterComplete()` updates
      `completed_chapters` JSON array.
- [ ] `AudiobookLibraryManager` correctly extends `BookLibraryManager`
      with audiobook-specific overrides.
- [ ] `AudiobookController::getAudiobook()` returns full chapter list.
- [ ] `AudiobookController::streamAudiobook()?chapter=N&offset=MS`
      starts streaming from the correct byte offset within the specified
      chapter.
- [ ] `AudiobookLibraryType` is registered as library type `'audiobook'`.
- [ ] Migration `00X_audiobook_progress.sql` creates
      `audiobook_progress` table with correct schema.
- [ ] Smarty templates exist for audiobook list, detail, player.
- [ ] `./vendor/bin/phpunit` — green; ≥ 15 new tests.
- [ ] Coverage of `AudiobookScanner` ≥ 85 %, `AudiobookProgressStore` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/libraries/audiobooks.md` written.
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
git checkout -b g.6-audiobooks

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'AudiobookScanner|AudiobookProgressStore|AudiobookController'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step G.6: Audiobooks — M4B chapter handling + progress tracking"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step G.6: Audiobooks — M4B chapter handling + progress tracking" \
  --body  "Adds AudiobookScanner (M4B chapter extraction), AudiobookLibraryManager, AudiobookProgress/AudiobookProgressStore, AudiobookController (/audiobooks/*), migration, Smarty audiobook templates. Part of Phase G (Step G.6 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'g.6-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `g.6-audiobooks-review.md`.

Non-obvious points:
- M4B chapter parsing uses pure PHP binary string unpacking on the
  `chpl` atom — no external library required.
- Progress is stored per-user per-audiobook in the `audiobook_progress`
  table (separate from `watch_history` which tracks 90% video
  completion). The chapter index + position-within-chapter gives
  precise resume.
- Multi-file audiobook series (multiple MP3s or M4Bs per book) is a
  known future enhancement; G.6 handles single-file M4B correctly and the
  naming convention for series detection is the same as the TV scanner.
- The player JS saves progress every 10 seconds via `POST
  /audiobooks/{id}/progress` to avoid losing position on sudden close.
