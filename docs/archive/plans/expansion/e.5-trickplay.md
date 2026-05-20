# Step E.5 — Trickplay / BIF thumbnail seek

**Phase:** E (Hardware Transcoding + Advanced Streaming)
**Step:** E.5
**Depends on:** E.2
**Review:** Yes — see `e.5-trickplay-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement trickplay (also called "scrub preview" or "thumbnail seek") which
allows users to preview a video by hovering over the progress bar and seeing
thumbnail images. The implementation uses the DASH-IF / HLS spec-compliant
"BIF" (Bitmap Image Format) thumbnails: a single JPEG/PNG grid image at
fixed intervals (e.g. every 10 seconds) stored alongside the stream. The
player requests a specific thumbnail index when the user hovers.

A new `TrickplayGenerator` class generates BIF thumbnail grids during or
after transcoding. A `TrickplayController` serves thumbnail images via
HTTP, mapping byte-offset ranges to BIF grid indices.

## 2. Context (what already exists)

- After E.4: DASH streaming is available with `DashStreamer`.
- `src/Media/Transcoding/FfmpegRunner.php` — already has
  `generateThumbnail()` which extracts a single frame.
- `src/Media/Streaming/HlsStreamer.php` — has `getSegmentPath()` for
  segment files; similar API is needed for trickplay files.
- `config/ffmpeg.php` — `transcode_dir` key; trickplay files stored under
  `{$transcode_dir}/trickplay/{$jobId}/`.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Trickplay / BIF thumbnail seek" is
  **Missing**.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Streaming/Trickplay/TrickplayConfig.php` — configuration value
  object:

  ```php
  final class TrickplayConfig
  {
      public function __construct(
          public readonly int $interval_seconds = 10,
          public readonly int $grid_columns = 8,
          public readonly int $grid_rows = 4,
          public readonly int $thumb_width = 160,
          public readonly int $thumb_height = 90,
          public readonly string $image_format = 'jpeg',   // 'jpeg' | 'png'
          public readonly int $jpeg_quality = 72,
      ) {}
  }
  ```

- `src/Media/Streaming/Trickplay/TrickplayGenerator.php` — generates BIF
  thumbnail grid images during transcoding:

  ```php
  class TrickplayGenerator
  {
      public function __construct(FfmpegRunner $ffmpeg, string $output_dir) {}

      /** Generates all trickplay thumbnail images for a given job. */
      public function generate(string $job_id, string $input_path, ?TrickplayConfig $config = null): TrickplayResult {}

      /** Extracts a single frame at a given timestamp. */
      public function extractFrame(string $input_path, int $timestamp_seconds, string $output_path): bool {}

      /** Generates the BIF index XML file that maps byte offsets to grid positions. */
      public function generateIndex(string $job_id, TrickplayResult $result): string {}

      /** Cleans up trickplay files for a job. */
      public function cleanup(string $job_id): void {}
  }
  ```

- `src/Media/Streaming/Trickplay/TrickplayResult.php` — result container:

  ```php
  final class TrickplayResult
  {
      public function __construct(
          public readonly string $job_id,
          public readonly int $interval_seconds,
          public readonly int $grid_columns,
          public readonly int $grid_rows,
          public readonly array $image_files,  // ['00-10s.jpg' => ['offset' => 0, 'size' => 4096], ...]
          public readonly string $index_xml,
      ) {}
  }
  ```

- `src/Media/Streaming/Trickplay/TrickplayController.php` — HTTP handler:

  ```php
  class TrickplayController
  {
      public function __construct(string $trickplay_dir, string $base_url) {}

      /** Returns the URL for the trickplay thumbnail image file. */
      public function getThumbnailUrl(string $job_id, int $image_index): string {}

      /** Returns the URL for the BIF index XML. */
      public function getIndexUrl(string $job_id): string {}

      /** Returns the thumbnail image content (with correct Content-Type). */
      public function getThumbnail(string $job_id, int $image_index): ?string {}

      /** Returns the BIF index XML content. */
      public function getIndex(string $job_id): ?string {}
  }
  ```

- `config/trickplay.php` — trickplay config:

  ```php
  return [
      'enabled' => true,
      'interval_seconds' => 10,
      'grid_columns' => 8,
      'grid_rows' => 4,
      'thumb_width' => 160,
      'thumb_height' => 90,
      'image_format' => 'jpeg',
      'jpeg_quality' => 72,
      'storage_dir' => '/var/trickplay',
  ];
  ```

- `tests/Unit/Media/Streaming/Trickplay/TrickplayConfigTest.php`
- `tests/Unit/Media/Streaming/Trickplay/TrickplayGeneratorTest.php`
- `tests/Unit/Media/Streaming/Trickplay/TrickplayResultTest.php`
- `tests/Unit/Media/Streaming/Trickplay/TrickplayControllerTest.php`

#### Documentation

- `docs/developers/streaming-protocols.md` — add "Trickplay / Thumbnail Seek"
  section documenting BIF format, generation pipeline, and client-side usage.

### Modify

- `src/Media/Streaming/StreamManager.php` — add `TrickplayGenerator`
  and `TrickplayController`; add `generateTrickplay(string $jobId,
  string $inputPath): TrickplayResult` method.
- `src/Server/Http/Router.php` — add trickplay routes:
  ```
  GET /trickplay/{jobId}/thumb-{index}.jpg  — thumbnail image
  GET /trickplay/{jobId}/index.xml          — BIF index
  ```
- `src/Media/Transcoding/FfmpegRunner.php` — extend `generateThumbnail()`
  to accept a `$timeSeconds` parameter and multiple timestamps via an
  array for batch extraction (key for generating many trickplay frames
  efficiently).
- `composer.json` — no new dependencies.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch: `git checkout -b e.5-trickplay`.
2. **TrickplayConfig.** Write the configuration value object with sensible
   defaults.
3. **TrickplayGenerator.** The core class:
   - `generate()` computes how many images are needed based on video duration
     (duration / interval, rounded up), then calls `extractFrame()` for
     each.
   - For batch efficiency, `extractFrame()` appends multiple `-ss` +
     `-vframes 1` to a single ffmpeg invocation using complex filter
     graph: `ffmpeg -i input -ss t1 -vframes 1 thumb1.jpg -ss t2 -vframes 1
     thumb2.jpg ...`.
   - After all thumbnails are extracted, it assembles them into a grid
     image using ffmpeg's `tile` filter: `-filter_complex
     tile=8x4:margin=2:padding=3` producing a single `bif_00.jpg` containing
     the 32-image grid.
   - Multiple grid images are created if the total count exceeds
     `grid_columns * grid_rows`.
4. **TrickplayResult.** Tracks the mapping: grid image filename →
   time range. The BIF index XML maps each image index to its byte offset
   in the grid file (needed for byte-range serving in HTTP).
5. **BIF index.** The `generateIndex()` method writes an XML file:
   ```xml
   <ThumbList>
     <Thumbs>
       <Thumb index="0" time="0" offset="0" length="4096"/>
       <Thumb index="1" time="10" offset="4096" length="4096"/>
       ...
     </Thumbs>
   </ThumbList>
   ```
6. **TrickplayController.** Serves the thumbnail grid images and the index
   XML with correct `Content-Type` headers (`image/jpeg` or `image/png`
   for images; `application/xml` for index).
7. **FfmpegRunner update.** Batch `extractFrame()` support.
8. **StreamManager update.** Wire trickplay generation into the transcode
   completion pipeline.
9. **Router update.** Add the two trickplay routes.
10. **Config.** Write `config/trickplay.php`.
11. **Tests.** Write all 4 test files.
12. **Verification bar.**
13. **Docs + changelog.**
14. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `TrickplayConfigTest::test_defaults`
2. `TrickplayConfigTest::test_custom_values`
3. `TrickplayGeneratorTest::test_calculate_grid_count`
4. `TrickplayGeneratorTest::test_extract_frame_returns_bool`
5. `TrickplayGeneratorTest::test_generate_result_has_images`
6. `TrickplayGeneratorTest::test_generate_index_xml`
7. `TrickplayGeneratorTest::test_cleanup`
8. `TrickplayResultTest::test_image_files_accessible`
9. `TrickplayResultTest::test_interval_accessible`
10. `TrickplayControllerTest::test_get_thumbnail_url`
11. `TrickplayControllerTest::test_get_index_url`
12. `TrickplayControllerTest::test_get_index_returns_xml_content_type`
13. `TrickplayControllerTest::test_get_thumbnail_returns_jpeg_content_type`

**Coverage target:** `TrickplayGenerator` ≥ 85 %, `TrickplayController` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New public class/method"** → all new public classes get PHPDoc
  with `@since 0.11.0`.
- **"Anything"** → `docs/developers/streaming-protocols.md` updated
  with trickplay section.
- **"User-visible behavior change"** → CHANGELOG entry.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `TrickplayConfig` has sensible defaults (10s interval, 8×4 grid,
      160×90px thumbnails, JPEG format).
- [ ] `TrickplayGenerator::generate()` produces at least one grid image
      file per job.
- [ ] `TrickplayGenerator::generateIndex()` produces a valid BIF index
      XML with `offset` and `length` attributes per thumbnail.
- [ ] `TrickplayController::getThumbnailUrl()` returns a URL path
      `/trickplay/{jobId}/thumb-{index}.jpg`.
- [ ] `TrickplayController::getIndexUrl()` returns a URL path
      `/trickplay/{jobId}/index.xml`.
- [ ] `TrickplayController::getThumbnail()` returns a string with
      `Content-Type: image/jpeg` (or `image/png`).
- [ ] `TrickplayController::getIndex()` returns a string with
      `Content-Type: application/xml`.
- [ ] `TrickplayGenerator::cleanup()` removes all trickplay files for
      the job.
- [ ] `FfmpegRunner::generateThumbnail()` supports batch extraction
      (array of timestamps).
- [ ] Trickplay routes are wired in `Router.php`.
- [ ] `config/trickplay.php` exists with all configuration keys.
- [ ] `./vendor/bin/phpunit` — green; ≥ 13 new tests.
- [ ] Coverage targets met per §5.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/streaming-protocols.md` updated.
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
git checkout -b e.5-trickplay

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Trickplay'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step E.5: trickplay / BIF thumbnail seek (TrickplayGenerator + controller)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step E.5: trickplay / BIF thumbnail seek" \
  --body  "Adds TrickplayGenerator, TrickplayController, TrickplayConfig, TrickplayResult, BIF index generation, and HTTP routes for thumbnail seek. Part of Phase E (Step E.5 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'e.5-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `e.5-trickplay-review.md`.

Non-obvious points:
- Trickplay generation runs **after** the main transcode is complete
  (post-processing step in `StreamManager`). It does not block the
  availability of the stream.
- The BIF index stores `offset` + `length` for **byte-range requests** —
  some players request only part of the grid image to display a single
  thumbnail, avoiding downloading the entire grid.
- The thumbnail grid layout (8 columns × 4 rows = 32 images per grid) is
  configurable via `TrickplayConfig`. More images per grid means fewer
  HTTP requests per seek but larger total image files.
