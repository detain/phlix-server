# Step H.5 — Trailers + extras

**Phase:** H (Smart Features)
**Step:** H.5
**Depends on:** A.4
**Review:** Yes — see `h.5-trailers-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement trailers and extras: local `Trailers/` folder support per
media item, `-trailer.mkv` naming convention, TMDB trailer URLs cached
from the existing TMDB provider, and a `/api/v1/media/{id}/extras`
endpoint that returns a sorted list of trailers and extras for the
client to display. Clients discover and play trailers from this endpoint;
no automatic playback in this step — that belongs to H.6 (theme media).

## 2. Context (what already exists)

- `src/Media/Library/MediaScanner.php` (Phase 1–7) — already walks
  directories; easily extended to detect `Trailers/` subfolders.
- `src/Media/Library/ItemRepository.php` — media item reads; stores
  path in `media_items` table.
- `src/Media/Metadata/TmdbProvider.php` (Phase 1–7) — already fetches
  metadata including trailer URLs from TMDB; trailer data is already
  returned in `metadata_json`.
- `src/Server/Http/Router.php` — route registration pattern.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Trailers, extras, theme music, theme
  video" is **Missing**.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase H table — H.5 covers trailers and
  extras; H.6 (theme media) follows H.5.

Existing patterns to follow:

- `src/Media/Library/FolderWatcher.php` — filesystem watching;
  `Trailers/` detection integrates naturally here.
- `config/database.php` — DB config pattern.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Extras/TrailerResolver.php` — discovers local trailers
  and merges with TMDB trailers:

  ```php
  class TrailerResolver
  {
      public function __construct(
          private readonly ItemRepository $items,
          private readonly TmdbProvider $tmdb,
      ) {}

      /** Returns Trailer[] for a media item */
      public function getTrailers(string $mediaItemId): array {}

      /** Returns Extra[] (featurettes, behind the scenes, etc.) */
      public function getExtras(string $mediaItemId): array {}

      /** Full merged list sorted by type priority */
      public function getAllExtras(string $mediaItemId): array {}
  }
  ```

- `src/Media/Extras/Trailer.php` — readonly DTO:

  ```php
  class Trailer
  {
      public function __construct(
          public readonly string $id,
          public readonly string $mediaItemId,
          public readonly string $title,       // e.g. "Official Trailer", "Teaser"
          public readonly string $source,     // 'local' | 'tmdb'
          public readonly string $url,       // absolute URL (local file or TMDB)
          public readonly int $duration,      // seconds; 0 if unknown
          public readonly int $quality,       // 480/720/1080/2160; 0 if unknown
          public readonly bool $isLocal,      // true for local -trailer.mkv files
          public readonly string $filePath,   // empty for TMDB
      ) {}
  }
  ```

- `src/Media/Extras/Extra.php` — readonly DTO for non-trailer extras:

  ```php
  class Extra
  {
      public function __construct(
          public readonly string $id,
          public readonly string $mediaItemId,
          public readonly string $title,
          public readonly string $type,    // 'featurette'|'behind_the_scenes'|'interview'|'clip'|'deleted_scene'|'trailer'
          public readonly string $source,
          public readonly string $url,
          public readonly int $duration,
          public readonly int $quality,
          public readonly bool $isLocal,
          public readonly string $filePath,
      ) {}
  }
  ```

- `src/Media/Extras/TrailerFinder.php` — filesystem scanner:

  ```php
  class TrailerFinder
  {
      public function findLocalTrailers(string $mediaDir): array {}
      // Scans for:
      //   <mediaDir>/Trailers/<name>-trailer.mkv  (or .mp4, .mkv, .avi)
      //   <mediaDir>/<name>-trailer.mkv            (same-level as the main file)
      // Returns array of {path, title, duration, quality}
  }
  ```

- `src/Server/Http/Controllers/ExtrasController.php` — JSON API:

  ```
  GET /api/v1/media/{id}/extras       full list (trailers + extras merged)
  GET /api/v1/media/{id}/trailers      trailers only
  GET /api/v1/media/{id}/extras/other  non-trailer extras only
  ```

- `migrations/007_media_extras.sql` — new table for cached extras:

  ```sql
  CREATE TABLE media_extras (
      id CHAR(36) NOT NULL PRIMARY KEY,
      media_item_id CHAR(36) NOT NULL,
      title VARCHAR(256) NOT NULL,
      extra_type VARCHAR(32) NOT NULL,  -- 'trailer'|'featurette'|'behind_the_scenes'|'interview'|'clip'|'deleted_scene'
      source VARCHAR(16) NOT NULL,      -- 'local'|'tmdb'
      url VARCHAR(1024) NOT NULL,
      file_path VARCHAR(1024) NULL,     -- only for source='local'
      duration INT NOT NULL DEFAULT 0,
      quality INT NOT NULL DEFAULT 0,
      cached_at DATETIME NOT NULL,
      INDEX idx_me_media (media_item_id),
      INDEX idx_me_type (extra_type)
  ) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```

- `tests/Unit/Media/Extras/TrailerResolverTest.php`
- `tests/Unit/Media/Extras/TrailerFinderTest.php`
- `tests/Unit/Media/Extras/TrailerTest.php`
- `tests/Unit/Media/Extras/ExtraTest.php`
- `tests/Integration/Media/Extras/TrailerScannerTest.php`

#### Documentation

- `docs/developers/trailers-and-extras.md` — naming conventions for
  local trailers and extras, how to configure TMDB trailer fetching,
  API reference for the extras endpoints.

### Modify

- `src/Media/Library/MediaScanner.php` — extend directory walk to
  detect `Trailers/` subfolders and `-trailer.mkv` files next to the
  main file; emit `ExtrasFound` events (future use by H.6).
- `src/Media/Library/FolderWatcher.php` — trigger a rescan of extras
  when the `Trailers/` folder changes.
- `src/Media/Metadata/TmdbProvider.php` — ensure `getTrailers()` and
  `getExtras()` methods exist and populate `media_extras` table; call
  from `TrailerResolver`.
- `src/Server/Http/Router.php` — register `ExtrasController` routes.
- `composer.json` — no new runtime dependencies.
- `CHANGELOG.md` — `Added: trailers + extras (H.5). Local
  Trailers/ folder support, -trailer.mkv naming, TMDB trailer URLs,
  /api/v1/media/{id}/extras endpoint.`

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b h.5-trailers`.
2. **Schema.** Write `migrations/007_media_extras.sql`.
3. **TrailerFinder.** Scans for local trailers in two locations:
   - `<mediaDir>/Trailers/<name>-trailer.mkv`
   - `<mediaDir>/<name>-trailer.mkv` (same level as main file)
   Uses `FFmpegRunner::probe()` to extract duration and resolution.
4. **Trailer + Extra DTOs.** Immutable value objects used throughout.
5. **TrailerResolver.** Merges local trailers (from DB) with TMDB
   trailers (from `TmdbProvider::getTrailers()`). Local trailers take
   priority if the same title exists on both sources. Results are cached
   in `media_extras` with a 24-hour TTL.
6. **ExtrasController.** Three endpoints for flexible client usage.
7. **Scanner integration.** Extend `MediaScanner` to detect and record
   local trailers at scan time; `FolderWatcher` already triggers
   rescans.
8. **Tests.** Unit + integration per §5.
9. **Verification bar** (§0.4 minimum bar).
10. **Docs.**
11. **Commit + PR + merge.**

**Naming conventions detected:**

```
Movies/
  Avatar (2009)/
    Avatar (2009).mkv
    Avatar (2009)-trailer.mkv              ← same-level trailer
    Trailers/
      Avatar (2009)-teaser.mkv
      Avatar (2009)-official-trailer.mkv
      Avatar (2009)-behind-the-scenes.mkv  ← extras also go in Trailers/

TV Shows/
  The Crown/
    Season 1/
      The Crown S01E01.mkv
      The Crown S01E01-trailer.mkv           ← episode trailer (rare)
      Trailers/
        The Crown Season 1 Trailer.mkv
```

The `-trailer`, `-teaser`, `-clip`, `-featurette` suffix is extracted
as the title.

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

`TrailerFinderTest`:
1. `test_finds_same_level_trailer_file`
2. `test_finds_trailers_in_subfolder`
3. `test_ignores_non_matching_extensions`
4. `test_extracts_title_from_filename`
5. `test_returns_empty_array_when_no_trailers_found`

`TrailerTest`:
6. `test_constructor_stores_all_properties`
7. `test_is_local_true_for_local_source`
8. `test_duration_and_quality_defaults`

`ExtraTest`:
9. `test_extra_type_constants_are_correct`
10. `test_constructor_stores_all_properties`

`TrailerResolverTest`:
11. `test_get_trailers_merges_local_and_tmdb`
12. `test_local_trailers_take_priority_over_tmdb`
13. `test_get_extras_returns_only_non_trailer_extras`
14. `test_get_all_extras_sorts_by_type_priority`
15. `test_get_trailers_caches_result_for_24h`

**Integration test** (`TrailerScannerTest`):
16. `test_scanner_detects_trailers_folder_and_records_trailers`
    — creates a fixture directory structure with a `Trailers/` folder,
    runs the scanner, asserts records in `media_extras`.

**Coverage target:** `TrailerResolver` ≥ 85 %, `TrailerFinder` ≥ 85 %,
`Trailer` ≥ 85 %, `Extra` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Public HTTP/WS API"** → `docs/reference/api/` adds extras
  endpoints.
- **"New library type"** → N/A (existing library types gain trailer
  support, not a new type).
- **"Anything"** → `docs/developers/trailers-and-extras.md` (new)
  covers naming conventions, API reference, TMDB cache TTL.
- **"User-visible behavior change"** → CHANGELOG entry.
- **"New public class/method"** → PHPDoc with `@since 0.14.0`.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `TrailerFinder` detects `-trailer.mkv` at same level and in
      `Trailers/` subfolder.
- [ ] `TrailerFinder` ignores non-media extensions.
- [ ] `TrailerResolver::getTrailers()` merges local + TMDB; local takes
      priority.
- [ ] `TrailerResolver::getExtras()` returns non-trailer extras.
- [ ] `TrailerResolver::getAllExtras()` returns merged, type-priority
      sorted list.
- [ ] Results cached in `media_extras` with 24h TTL; refresh on
      `FolderWatcher` change.
- [ ] All three `ExtrasController` endpoints wired.
- [ ] `MediaScanner` detects `Trailers/` at scan time.
- [ ] `migrations/007_media_extras.sql` runs cleanly.
- [ ] `./vendor/bin/phpunit` — green; ≥ 16 new tests.
- [ ] Coverage of each new class ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/trailers-and-extras.md` written.
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
git checkout -b h.5-trailers

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'TrailerResolver|TrailerFinder|Trailer|Extra'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step H.5: trailers + extras with local Trailers/ folder support"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step H.5: trailers + extras" \
  --body  "Adds TrailerResolver, TrailerFinder, Trailer, Extra, ExtrasController, and migration 007_media_extras.sql. Local Trailers/ folder support, -trailer.mkv naming, TMDB trailer URLs merged, 24h cache. Part of Phase H (Step H.5 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'h.5-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `h.5-trailers-review.md`.

Non-obvious points:
- Local trailers are stored in `media_extras` (not `media_items`) to
  avoid duplicating the entire media item machinery for trailer files;
  clients treat them as secondary play sources via the extras API.
- TMDB trailer URLs are stored for 24h and refreshed on scanner run or
  cache expiry; this avoids hitting TMDB on every request.
- The `-trailer.mkv` naming convention mirrors Plex/Emby for familiarity;
  the suffix (`-trailer`, `-teaser`, `-clip`, `-featurette`) becomes the
  display title.
