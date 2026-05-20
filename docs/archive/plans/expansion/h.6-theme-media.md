# Step H.6 — Theme music + theme video

**Phase:** H (Smart Features)
**Step:** H.6
**Depends on:** H.5
**Review:** Yes — see `h.6-theme-media-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Auto-play theme music (`.mp3`) and theme video (`backdrop.mp4`) when the
user browses to a library or collection in the WebPortal. The media files
are located alongside the library folder (e.g., `/Movies/theme.mp3` and
`/Movies/backdrop.mp4`) or in a per-item location (e.g., `/Movies/Backdrops/theme.mp3`).
A `GET /api/v1/libraries/{id}/theme-media` endpoint returns the
available theme media for a library; the WebPortal UI fetches it and
plays it with the HTML5 `<audio>` or `<video>` element on the browse
page. This is the final step of Phase H.

## 2. Context (what already exists)

- `src/Media/Library/LibraryManager.php` — library CRUD and scanning.
- `src/Media/Library/ItemRepository.php` — media item reads.
- `src/Media/Extras/TrailerResolver.php` (H.5) — filesystem scanning
  pattern that H.6 mirrors.
- `src/Server/Http/Router.php` — route registration.
- `src/Server/WebPortal/WebPortalRouter.php` — portal route pattern.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Trailers, extras, theme music, theme
  video" was **Missing**; H.5 just completed the trailers/extras
  step.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase H table — H.6 is the final
  smart-features step.

Existing patterns to follow:

- `src/Media/Extras/TrailerFinder.php` (H.5) — filesystem scanning;
  `ThemeMediaFinder` reuses the same approach.
- `src/Media/Extras/Extra` DTO pattern (H.5) — `ThemeMedia` DTO is
  structurally similar.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Theming/ThemeMediaFinder.php` — discovers theme media files:

  ```php
  class ThemeMediaFinder
  {
      public function findForLibrary(string $libraryPath): ?ThemeMedia {}
      // Scans for:
      //   <libraryPath>/theme.mp3
      //   <libraryPath>/theme.mp4
      //   <libraryPath>/theme.ogg
      //   <libraryPath>/backdrop.mp4
      //   <libraryPath>/backdrop.webm
      // Returns first-match audio + first-match video, or null.

      public function findForMediaItem(string $itemDir): ?ThemeMedia {}
      // For TV shows: per-season or per-series theme.
  }
  ```

- `src/Theming/ThemeMedia.php` — readonly DTO:

  ```php
  class ThemeMedia
  {
      public function __construct(
          public readonly string $libraryId,
          public readonly ?ThemeAudio $audio,    // { path, duration, format }
          public readonly ?ThemeVideo $video,  // { path, duration, width, height, format }
          public readonly \DateTimeImmutable $scannedAt,
      ) {}
  }

  class ThemeAudio
  {
      public function __construct(
          public readonly string $path,       // absolute filesystem path
          public readonly string $url,      // internal streaming URL
          public readonly int $duration,     // seconds
          public readonly string $format,     // 'mp3' | 'ogg' | 'aac'
      ) {}
  }

  class ThemeVideo
  {
      public function __construct(
          public readonly string $path,
          public readonly string $url,
          public readonly int $duration,
          public readonly int $width,
          public readonly int $height,
          public readonly string $format,   // 'mp4' | 'webm'
      ) {}
  }
  ```

- `src/Theming/ThemeMediaRepository.php` — caches discovered theme media:

  ```php
  class ThemeMediaRepository
  {
      public function __construct(private readonly Connection $db) {}

      public function upsert(ThemeMedia $tm): void {}
      public function findByLibraryId(string $libraryId): ?ThemeMedia {}
      public function deleteByLibraryId(string $libraryId): void {}
  }
  ```

- `src/Server/Http/Controllers/ThemeMediaController.php` — JSON API:

  ```
  GET /api/v1/libraries/{id}/theme-media    get theme media for a library
  POST /api/v1/libraries/{id}/theme-media/scan   trigger rescan
  DELETE /api/v1/libraries/{id}/theme-media     clear cached entry
  ```

- `src/Server/Http/Controllers/ThemeMediaStreamController.php` — serves
  the actual audio/video files (internal streaming, not HLS):

  ```
  GET /stream/theme-media/{libraryId}/audio
  GET /stream/theme-media/{libraryId}/video
  ```
  Uses `readfile()` with `application/octet-stream` content-type;
  no transcoding needed for short theme clips.

- `migrations/008_theme_media.sql` — new table:

  ```sql
  CREATE TABLE theme_media (
      library_id CHAR(36) NOT NULL PRIMARY KEY,
      audio_path VARCHAR(1024) NULL,
      audio_url VARCHAR(512) NULL,
      audio_duration INT NULL,
      audio_format VARCHAR(8) NULL,
      video_path VARCHAR(1024) NULL,
      video_url VARCHAR(512) NULL,
      video_duration INT NULL,
      video_width INT NULL,
      video_height INT NULL,
      video_format VARCHAR(8) NULL,
      scanned_at DATETIME NOT NULL
  ) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```

- `tests/unit/Theming/ThemeMediaFinderTest.php`
- `tests/unit/Theming/ThemeMediaRepositoryTest.php`
- `tests/unit/Theming/ThemeMediaTest.php`
- `tests/integration/Theming/ThemeMediaScanTest.php`

#### WebPortal UI additions

- `public/templates/partials/library-header.tpl` — add:
  ```smarty
  {if $themeMedia}
    <div class="theme-media-player" data-audio="{$themeMedia.audioUrl}" data-video="{$themeMedia.videoUrl}">
      <button class="theme-media-toggle" title="Toggle theme music">♫</button>
    </div>
  {/if}
  ```

- `public/assets/js/theme-media.js` — handles autoplay on browse page:
  - Reads `data-audio` / `data-video` from the DOM.
  - On first user interaction with the library page, plays `theme.mp3`
    as `<audio autoplay loop>` (respects browser autoplay policy).
  - If `backdrop.mp4` exists and viewport is ≥ 1080px wide, plays it
    muted as a background loop behind the library header.
  - Uses `play()` return value to handle the autoplay Promise (shows
    a "tap to enable theme music" overlay if autoplay is blocked).

#### Documentation

- `docs/developers/theme-media.md` — file naming, per-library vs
  per-item theme media, streaming URL format, autoplay policy handling.

### Modify

- `src/Server/Http/Router.php` — register `ThemeMediaController` and
  `ThemeMediaStreamController` routes.
- `src/Media/Library/LibraryManager.php` — after library scan, call
  `ThemeMediaFinder` and cache results in `ThemeMediaRepository`.
- `src/Server/WebPortal/PageRenderer.php` — pass `themeMedia` to
  `library-header.tpl` template.
- `composer.json` — no new runtime dependencies.
- `CHANGELOG.md` — `Added: theme music + theme video (H.6). Auto-play
  theme.mp3 / backdrop.mp4 on browse; library-level and item-level;
  browser autoplay-policy aware.`

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b h.6-theme-media`.
2. **Schema.** Write `migrations/008_theme_media.sql`.
3. **ThemeMediaFinder.** Mirrors `TrailerFinder` (H.5) logic for
   filesystem scanning. Scans for `theme.mp3`, `theme.mp4`, `theme.ogg`,
   `backdrop.mp4`, `backdrop.webm` in the library root; uses
   `FFmpegRunner::probe()` to extract duration and dimensions.
4. **ThemeMedia DTOs.** Immutable value objects.
5. **ThemeMediaRepository.** Simple upsert/find/delete with parameterized
   queries; caches scan results to avoid re-scanning on every request.
6. **Controllers.** `ThemeMediaController` for CRUD + scan trigger;
   `ThemeMediaStreamController` for direct file serving (not HLS — these
   are short clips, no need for segmented streaming).
7. **Stream controller.** `readfile()` with correct Content-Type; range
   request support for audio seeking; no transcoding.
8. **UI integration.** `theme-media.js` reads DOM data attributes set by
   `library-header.tpl`; implements browser autoplay policy handling per
   the spec above.
9. **Scan trigger.** `LibraryManager` calls `ThemeMediaFinder` after
   initial library scan and on `FolderWatcher` events for the library
   root directory (mtime check before re-scanning).
10. **Tests.** Unit + integration per §5.
11. **Verification bar** (§0.4 minimum bar).
12. **Docs.**
13. **Commit + PR + merge.**

**File locations for theme media:**

```
/Movies/
  theme.mp3                        ← library-level audio theme
  backdrop.mp4                    ← library-level video backdrop
  Avatar (2009)/
    Avatar (2009).mkv

/TV Shows/
  The Crown/
    theme.mp3                    ← series-level theme
    backdrop.mp4                 ← series-level backdrop (shown on series browse)
    Season 1/
      The Crown S01E01.mkv
```

Theme media found at the library or series level applies to all items
within it.

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

`ThemeMediaFinderTest`:
1. `test_finds_theme_mp3_in_library_root`
2. `test_finds_backdrop_mp4_in_library_root`
3. `test_finds_both_audio_and_video`
4. `test_returns_null_when_no_theme_media_found`
5. `test_finds_theme_for_media_item_directory`

`ThemeMediaRepositoryTest`:
6. `test_upsert_inserts_new_row`
7. `test_upsert_updates_existing_row`
8. `test_find_by_library_id_returns_cached`
9. `test_delete_removes_row`

`ThemeMediaTest`:
10. `test_constructor_stores_audio_and_video`
11. `test_audio_null_when_no_audio_file`
12. `test_video_null_when_no_video_file`

**Integration test** (`ThemeMediaScanTest`):
13. `test_scan_library_detects_theme_files_and_caches` — creates fixture
    library dir with `theme.mp3` and `backdrop.mp4`, runs scan, asserts
    row in `theme_media` table.

**Coverage target:** `ThemeMediaFinder` ≥ 85 %,
`ThemeMediaRepository` ≥ 85 %, `ThemeMedia` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Public HTTP/WS API"** → `docs/reference/api/` adds theme-media
  endpoints.
- **"Anything"** → `docs/developers/theme-media.md` (new) covers file
  naming, scanning, streaming, autoplay policy.
- **"User-visible behavior change"** → CHANGELOG entry.
- **"New public class/method"** → PHPDoc with `@since 0.14.0`.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `ThemeMediaFinder` finds `theme.mp3` / `backdrop.mp4` at library
      root and item-level.
- [ ] `ThemeMediaFinder` uses `FFmpegRunner::probe()` to extract
      duration and resolution.
- [ ] `ThemeMediaRepository` caches scan results in `theme_media` table.
- [ ] `migrations/008_theme_media.sql` runs cleanly.
- [ ] `GET /api/v1/libraries/{id}/theme-media` returns audio + video
      metadata.
- [ ] `POST /api/v1/libraries/{id}/theme-media/scan` triggers rescan.
- [ ] `GET /stream/theme-media/{libraryId}/audio` streams the file with
      correct Content-Type.
- [ ] `GET /stream/theme-media/{libraryId}/video` streams the file.
- [ ] `theme-media.js` handles browser autoplay policy (shows overlay if
      autoplay blocked).
- [ ] `backdrop.mp4` only plays when viewport ≥ 1080px (client-side
      check in `theme-media.js`).
- [ ] `LibraryManager` triggers theme media scan after library scan.
- [ ] `./vendor/bin/phpunit` — green; ≥ 13 new tests.
- [ ] Coverage of each new class ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/theme-media.md` written.
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
git checkout -b h.6-theme-media

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'ThemeMediaFinder|ThemeMediaRepository|ThemeMedia'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step H.6: theme music + theme video auto-play on browse"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step H.6: theme music + theme video" \
  --body  "Adds ThemeMediaFinder, ThemeMedia, ThemeMediaRepository, ThemeMediaController, ThemeMediaStreamController, and migration 008_theme_media.sql. Auto-play theme.mp3 / backdrop.mp4 on library browse; browser autoplay-policy aware. Part of Phase H (Step H.6 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'h.6-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `h.6-theme-media-review.md`.

Non-obvious points:
- Theme media is served via a simple `readfile()` stream controller —
  no HLS, no transcoding. Theme clips are short (< 60s typically) and
  direct streaming is simpler and faster than the HLS pipeline.
- The `backdrop.mp4` video is intentionally muted and only shown on
  large viewports (≥ 1080px) — this avoids distraction on mobile and
  respects the "ambient" nature of backdrop video.
- Autoplay handling in `theme-media.js` uses the standard pattern:
  `audioElement.play().catch(() => showOverlay())` — the overlay says
  "tap anywhere to enable theme music".
- Theme media is library-level (not per-item) by design; per-item themes
  would require significantly more complex caching and scanning
  infrastructure and are not in scope for H.6.
