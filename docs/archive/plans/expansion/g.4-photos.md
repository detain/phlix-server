# Step G.4 — Photos: EXIF extraction + slideshow

**Phase:** G (Music / Photos / Books / Audiobooks)
**Step:** G.4
**Depends on:** A.4
**Review:** Yes — see `g.4-photos-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Add a `photo` library type that scans a photo library folder, extracts
EXIF metadata (camera, lens, aperture, ISO, GPS coordinates), serves
photos at full resolution and thumbnails, and renders a slideshow view
in the web portal. Geotag clustering (map view with location dots) is
explicitly deferred to a future step.

## 2. Context (what already exists)

- `src/Media/Library/MediaScanner.php` — already parses video files;
  will be extended to handle JPEG, PNG, TIFF, WebP, HEIC.
- `src/Media/Metadata/MetadataManager.php` — after G.1, already has
  provider infrastructure; G.4 adds EXIF as a local metadata source.
- `src/Server/Http/Router.php` — router; photo routes will be added.
- `public/templates/` — existing Smarty templates.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Photos library + EXIF + slideshow"
  is **Missing**.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase G table — G.4 is the photo step.
- `config/plugins.php` — plugin registry; photo library type registered here.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Library/PhotoScanner.php` — extends `MediaScanner` for
  image files; handles JPEG/EXIF, PNG, TIFF, WebP, HEIC:

  ```php
  class PhotoScanner extends MediaScanner
  {
      /** Extract EXIF data from a photo. Returns [] on failure. */
      public function harvestExif(string $path): array {}
      // {
      //   camera_make, camera_model, lens, aperture, iso, shutter_speed,
      //   focal_length, width, height, orientation, date_taken_unix,
      //   gps_lat, gps_lng, gps_alt,
      //   orientation_name (Normal/Mirror*/Rotate*)
      // }

      /** Scan a photo library and yield item arrays. */
      public function scanPhotoLibrary(string $library_path): \Generator {}
  }
  ```

- `src/Media/Library/PhotoLibraryManager.php` — orchestrates photo
  library scan + EXIF extraction + upsert:

  ```php
  class PhotoLibraryManager
  {
      public function __construct(
          private readonly PhotoScanner $scanner,
          private readonly ItemRepository $item_repo,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public function rescanLibrary(string $library_id): ScanResult {}
      public function upsertPhoto(string $library_id, string $path): ?MediaItem {}
  }
  ```

- `src/Media/Metadata/ExifProvider.php` — local EXIF metadata provider:

  ```php
  class ExifProvider implements MetadataProviderInterface
  {
      public function __construct(
          private readonly string $library_path,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public function supports(string $media_type): bool {}
      // Returns self::MEDIA_TYPE_PHOTO

      public function search(string $query, int $limit = 20): array {}
      // Not applicable for photos — returns []

      public function getPhotoMetadata(string $photo_id): ?array {}
      // Returns full EXIF + computed fields from media_items metadata_json
  }
  ```

- `src/Server/Http/Controllers/PhotoController.php` — photo API
  endpoints:

  ```php
  class PhotoController
  {
      // GET /photo/albums         — group by date / folder
      public function listAlbums(Request $req): Response {}

      // GET /photo/albums/{id}
      public function getAlbum(Request $req, array $params): Response {}

      // GET /photo/photos
      public function listPhotos(Request $req): Response {}

      // GET /photo/photos/{id}
      public function getPhoto(Request $req, array $params): Response {}

      // GET /photo/photos/{id}/thumbnail?w=300&h=300&fit=cover
      public function getThumbnail(Request $req, array $params): Response {}

      // GET /photo/photos/{id}/full
      public function getFull(Request $req, array $params): Response {}

      // GET /photo/slideshow?album_id=xxx&interval=5
      public function slideshow(Request $req): Response {}
      // Returns array of {id, url, thumbnail_url, caption} for JS rotation
  }
  ```

- `src/Media/Music/PhotoLibraryType.php` — library type registration:

  ```php
  final class PhotoLibraryType implements LibraryTypeInterface
  {
      public const TYPE = 'photo';
      public function getType(): string {}
      public function getLabel(): string {}
      public function getScanner(): PhotoScanner {}
  }
  ```

- `public/templates/photo/` directory with:
  - `albums.tpl` — album grid (date-based or folder-based groupings)
  - `album.tpl` — photo grid within an album
  - `photo.tpl` — single photo view with EXIF sidebar
  - `slideshow.tpl` — fullscreen slideshow player
  - `partials/exif_panel.tpl` — EXIF data sidebar partial
  - `partials/photo_card.tpl` — thumbnail card partial

- `public/assets/css/photo.css` — styles for album grid, photo grid,
  lightbox, EXIF sidebar, slideshow.
- `public/assets/js/slideshow.js` — slideshow logic: auto-advance
  interval, keyboard nav (left/right/escape), touch/swipe support.

- `tests/Unit/Media/Library/PhotoScannerTest.php`
- `tests/Unit/Media/Library/PhotoLibraryManagerTest.php`
- `tests/Unit/Server/Http/Controllers/PhotoControllerTest.php`

#### Documentation

- `docs/libraries/photos.md` — new doc: supported formats, EXIF fields
  extracted, naming conventions, slideshow usage, known limitations
  (no geotag clustering yet).

### Modify

- `src/Media/Library/MediaScanner.php` — add audio/image detection by
  MIME type; delegate image files to `PhotoScanner`.
- `src/Media/Library/LibraryManager.php` — detect `library_type =
  'photo'`; route to `PhotoLibraryManager`.
- `src/Server/Http/Router.php` — register `/photo/*` routes to
  `PhotoController`.
- `public/templates/layouts/header.tpl` — add Photos nav link.
- `composer.json` — add `PHPEXIF/php-exif` (~50 KB pure-PHP EXIF parser)
  if not using inline implementation. Document choice in approach §4.
- `config/plugins.php` — register `PhotoLibraryType`.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b g.4-photos`.
2. **EXIF parser decision.** Evaluate `PHPEXIF/php-exif` vs. pure-PHP
   inline implementation. Pure-PHP is preferred (no extension, no heavy
   dependency). Document the choice.
3. **PhotoScanner.** Pure-PHP EXIF extraction for JPEG/HEIC. Use
   `exif_read_data()` PHP built-in (no external lib needed for JPEG).
   For HEIC/AVIF, use `imagick` if available, skip gracefully if not.
4. **PhotoLibraryManager.** Orchestrates scan → EXIF harvest → upsert.
5. **ExifProvider.** Local provider that reads from `metadata_json`
   stored on the media item; no external API calls.
6. **PhotoController.** REST endpoints for albums, photos, thumbnails,
   slideshow. Thumbnail generation uses `imagecreatetruecolor()` +
   `imagecopyresampled()` from PHP GD (already available).
7. **LibraryManager integration.** Detect `library_type = 'photo'`.
8. **Web portal.** Smarty templates for albums, photo view, slideshow.
   CSS + JS for slideshow.
9. **Tests.** Write all 3 test files per §5.
10. **Verification bar** (§0.4 minimum bar).
11. **Docs.**
12. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `PhotoScannerTest::test_harvest_exif_returns_array`
2. `PhotoScannerTest::test_harvest_exif_returns_empty_on_non_jpeg`
3. `PhotoScannerTest::test_harvest_exif_gps_coordinates_parsed`
4. `PhotoScannerTest::test_scan_photo_library_yields_items`
5. `PhotoLibraryManagerTest::test_rescan_library_calls_scanner`
6. `PhotoLibraryManagerTest::test_upsert_photo_stores_exif`
7. `PhotoControllerTest::test_list_albums_returns_json`
8. `PhotoControllerTest::test_get_photo_returns_json_with_exif`
9. `PhotoControllerTest::test_get_thumbnail_returns_image`
10. `PhotoControllerTest::test_slideshow_returns_photo_list`
11. `PhotoControllerTest::test_get_photo_404_when_not_found`

**Coverage target:** `PhotoScanner` ≥ 85 %, `PhotoLibraryManager` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New library type (music/photos/books/audiobooks)"** →
  `docs/libraries/photos.md` (new): supported formats, EXIF fields,
  naming conventions, slideshow how-to, deferred geotag note.
- **"Anything"** → all new public classes get PHPDoc with `@since 0.16.0`.
- **"User-visible behavior change"** → CHANGELOG entry (photo library
  browsing + slideshow added).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `PhotoScanner::harvestExif()` returns a structured array with all
      documented EXIF fields; returns `[]` without throwing on non-JPEG.
- [ ] `PhotoScanner::scanPhotoLibrary()` yields media item arrays.
- [ ] `PhotoLibraryManager::rescanLibrary()` orchestrates full pipeline.
- [ ] `ExifProvider::getPhotoMetadata()` returns full EXIF array from
      `metadata_json`.
- [ ] `PhotoController` serves `/photo/albums`, `/photo/photos`,
      `/photo/photos/{id}/thumbnail`, `/photo/slideshow` correctly.
- [ ] `PhotoController::getThumbnail()` returns a resized image with
      correct Content-Type header.
- [ ] `PhotoLibraryType` is registered as library type `'photo'`.
- [ ] `LibraryManager` routes photo-type libraries to
      `PhotoLibraryManager`.
- [ ] Smarty templates exist for albums, photo view, slideshow.
- [ ] `./vendor/bin/phpunit` — green; ≥ 11 new tests.
- [ ] Coverage of `PhotoScanner` ≥ 85 %, `PhotoLibraryManager` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/libraries/photos.md` written.
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
git checkout -b g.4-photos

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'PhotoScanner|PhotoLibraryManager|PhotoController'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step G.4: Photos — EXIF extraction + slideshow"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step G.4: Photos — EXIF extraction + slideshow" \
  --body  "Adds PhotoScanner (EXIF extraction), PhotoLibraryManager, ExifProvider, PhotoController (/photo/* routes), Smarty photo templates, slideshow.js. Part of Phase G (Step G.4 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'g.4-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `g.4-photos-review.md`.

Non-obvious points:
- EXIF extraction uses PHP's built-in `exif_read_data()` function for
  JPEG — no external library required. HEIC/AVIF support requires
  ImageMagick extension; the scanner skips gracefully when it's absent.
- Thumbnails are generated on-demand and could be cached to disk;
  caching strategy is deferred to a future optimization step.
- Geotag clustering (map view) is explicitly deferred — the EXIF
  extraction supports `gps_lat` / `gps_lng` but no map UI is built in G.4.
