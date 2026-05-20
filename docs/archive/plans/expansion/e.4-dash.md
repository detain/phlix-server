# Step E.4 — DASH output alongside HLS

**Phase:** E (Hardware Transcoding + Advanced Streaming)
**Step:** E.4
**Depends on:** E.2
**Review:** Yes — see `e.4-dash-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Add DASH (Dynamic Adaptive Streaming over HTTP) as a second streaming
protocol alongside the existing HLS implementation in `HlsStreamer`. A new
`DashStreamer` class generates DASH MPD (Media Presentation Description)
manifests and writes segmented output compatible with the DASH-IF
Interoperability Points. Both HLS and DASH share the same segment files
on disk (`.m4s` for DASH vs. `.ts` for HLS), so the transcode pipeline
writes segments once and both streamers reference the same files.

The streaming controller (`StreamManager`) gains a `getManifestUrl(string
$jobId, string $protocol)` method that returns either the HLS or DASH
master manifest URL based on client request.

## 2. Context (what already exists)

- After E.2: `HlsStreamer` generates master + variant `.m3u8` playlists
  and segment `.ts` files; `getPlaylistUrl()` / `getVariantPlaylistUrl()`
  return URLs; `savePlaylist()` / `getSegmentContent()` handle files.
- `src/Media/Streaming/StreamManager.php` — orchestrates streaming
  sessions; currently only knows about HLS.
- `src/Media/Streaming/HlsStreamer` uses a segment directory per job:
  `{$segmentDir}/{$jobId}/segment_{variant}_{index}.ts`.
- `config/ffmpeg.php` — `segment_dir` key; shared by both HLS and DASH.
- `config/ffmpeg.php` — no existing DASH configuration.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Streaming/Dash/DashStreamer.php` — DASH manifest generator
  and segment manager:

  ```php
  class DashStreamer
  {
      public function __construct(string $segment_dir, string $base_url) {}

      /** Generates the DASH MPD master manifest listing all adaptation sets. */
      public function generateMasterMpd(string $job_id, array $adaptation_sets): string {}

      /** Generates a DASH MPD for a specific adaptation set (video or audio). */
      public function generateAdaptationSetMpd(string $job_id, int $set_id, array $segments, array $params): string {}

      /** Returns the URL path to the master MPD. */
      public function getMasterMpdUrl(string $job_id): string;

      /** Returns the URL path to an adaptation set MPD. */
      public function getAdaptationSetMpdUrl(string $job_id, int $set_id): string;

      /** Saves an MPD file to the job directory. */
      public function saveMpd(string $job_id, string $content, string $filename): void {}

      /** Gets the filesystem path for a DASH segment file (.m4s). */
      public function getSegmentPath(string $job_id, int $set_id, int $segment_number): string {}

      /** Saves a DASH segment file. */
      public function saveSegment(string $job_id, int $set_id, int $segment_number, string $content): void {}

      /** Cleans up all DASH files for a job. */
      public function cleanupJob(string $job_id): void {}
  }
  ```

- `src/Media/Streaming/Dash/SegmentTemplate.php` — DASH segment template
  handling (SegmentTemplate vs. SegmentList):

  ```php
  final class SegmentTemplate
  {
      public function __construct(
          public readonly int $duration,       // in seconds
          public readonly int $start_number,   // usually 1
          public readonly string $media,       // $RepresentationID$_$Number%05d$.m4s
          public readonly ?string $initialization, // $RepresentationID$_init.m4s
      ) {}

      public function toXml(): \DOMElement {}
  }
  ```

- `src/Media/Streaming/Dash/AdaptationSet.php` — DASH adaptation set model:

  ```php
  final class AdaptationSet
  {
      public function __construct(
          public readonly string $id,
          public readonly string $content_type,  // 'video' | 'audio' | 'text'
          public readonly string $codecs,
          public readonly int $width,
          public readonly int $height,           // 0 for audio
          public readonly int $bandwidth,
          public readonly int $sample_rate,      // 0 for video
          public readonly array $segments,
      ) {}

      public function toXml(): \DOMElement {}
  }
  ```

- `config/dash.php` — DASH-specific config:

  ```php
  return [
      'enabled' => true,
      'manifest_refresh_seconds' => 30,
      'min_buffer_time' => 'PT2S',
      'min_buffer_time_live' => 'PT10S',
      'time_shift_buffer_depth' => 'PT30M',
      'default_codecs' => [
          'video' => 'avc1.64001f',   // H.264 High Profile Level 3.1
          'audio' => 'mp4a.40.2',    // AAC-LC
      ],
  ];
  ```

- `tests/Unit/Media/Streaming/Dash/DashStreamerTest.php`
- `tests/Unit/Media/Streaming/Dash/SegmentTemplateTest.php`
- `tests/Unit/Media/Streaming/Dash/AdaptationSetTest.php`

#### Documentation

- `docs/developers/streaming-protocols.md` — new doc covering HLS vs. DASH
  comparison, when to use each, MPD structure, and client-side selection.

### Modify

- `src/Media/Streaming/StreamManager.php` — add `DashStreamer` property;
  add `getManifestUrl(string $jobId, string $protocol): string` method
  (`$protocol` is `'hls'` or `'dash'`).
- `src/Media/Streaming/HlsStreamer.php` — add `setSegmentContent()`
  method so that `StreamManager` can write once and both HLS and DASH can
  read the same segment files (HLS reads `.ts`, DASH needs `.m4s` — a
  conversion wrapper handles the container format).
- `config/ffmpeg.php` — add `dash` key with `enabled`, `segment_dir`
  reference, `default_codecs`.
- `src/Server/Http/Router.php` — add DASH routes:
  ```
  GET /dash/{jobId}/manifest.mpd           — master manifest
  GET /dash/{jobId}/{setId}/manifest.mpd  — adaptation set manifest
  GET /dash/{jobId}/{setId}/segment_{n}.m4s — segment file
  ```
- `composer.json` — no new dependencies.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch: `git checkout -b e.4-dash`.
2. **SegmentTemplate + AdaptationSet.** Write the value objects with full
   `toXml()` methods that produce valid DASH MPD XML fragments.
3. **DashStreamer.** Write the main class:
   - `generateMasterMpd()` creates the root `<MPD>` with
     `@profiles="urn:mpeg:dash:profile:isoff-live:2011"` and lists
     all adaptation sets.
   - `generateAdaptationSetMpd()` creates a per-set MPD with
     `<SegmentTemplate>` element referencing segment URLs.
   - DASH uses M4S (MPEG-4 container) segments instead of TS. Since the
     transcode pipeline already writes MP4/M4S segments, the same files
     work for both HLS (when muxed to TS) and DASH (native M4S).
4. **HlsStreamer update.** Add `setSegmentContent()` so the segment
   writer can store once and both streamers can reference.
5. **StreamManager update.** Add `DashStreamer` + `getManifestUrl()`.
6. **Router update.** Wire the three DASH routes.
7. **Config.** Write `config/dash.php`.
8. **Tests.** Write all 3 test files.
9. **Verification bar.**
10. **Docs + changelog.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `DashStreamerTest::test_generate_master_mpd`
2. `DashStreamerTest::test_generate_adaptation_set_mpd`
3. `DashStreamerTest::test_get_master_mpd_url`
4. `DashStreamerTest::test_get_segment_path`
5. `DashStreamerTest::test_save_mpd`
6. `DashStreamerTest::test_cleanup_job`
7. `SegmentTemplateTest::test_to_xml`
8. `SegmentTemplateTest::test_initialization_url`
9. `SegmentTemplateTest::test_start_number`
10. `AdaptationSetTest::test_to_xml_video`
11. `AdaptationSetTest::test_to_xml_audio`

**Coverage target:** `DashStreamer` ≥ 85 %, `SegmentTemplate` ≥ 85 %,
`AdaptationSet` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New public class/method"** → all new public classes get PHPDoc
  with `@since 0.11.0`.
- **"Anything"** → `docs/developers/streaming-protocols.md` (new) covers
  HLS vs. DASH tradeoffs, manifest structure, and usage.
- **"User-visible behavior change"** → CHANGELOG entry.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `DashStreamer::generateMasterMpd()` produces a valid XML document
      with `<MPD>` root and `<AdaptationSet>` children.
- [ ] `DashStreamer::generateMasterMpd()` includes
      `profiles="urn:mpeg:dash:profile:isoff-live:2011"`.
- [ ] `DashStreamer::generateAdaptationSetMpd()` produces a valid MPD
      with `<SegmentTemplate>` referencing `Initialization` and `Media`
      URLs.
- [ ] `DashStreamer::getMasterMpdUrl()` returns a URL path
      `/dash/{jobId}/manifest.mpd`.
- [ ] `DashStreamer::saveMpd()` writes the MPD file to the job directory.
- [ ] `DashStreamer::getSegmentPath()` returns a path ending in `.m4s`.
- [ ] `StreamManager::getManifestUrl('job-123', 'dash')` returns the DASH
      manifest URL; `getManifestUrl('job-123', 'hls')` returns the HLS
      manifest URL.
- [ ] DASH routes are wired in `Router.php` for all three endpoints.
- [ ] `config/dash.php` exists with `enabled`, `manifest_refresh_seconds`,
      `min_buffer_time`, `default_codecs`.
- [ ] `./vendor/bin/phpunit` — green; ≥ 11 new tests.
- [ ] Coverage targets met per §5.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/streaming-protocols.md` written.
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
git checkout -b e.4-dash

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'DashStreamer|SegmentTemplate|AdaptationSet'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step E.4: DASH streaming output alongside HLS (DashStreamer + MPD generation)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step E.4: DASH output alongside HLS" \
  --body  "Adds DashStreamer, SegmentTemplate, AdaptationSet, config/dash.php, and DASH routes. Enables DASH-IF compliant MPD manifest generation alongside existing HLS support. Part of Phase E (Step E.4 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'e.4-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `e.4-dash-review.md`.

Non-obvious points:
- DASH and HLS share the same segment files (`.m4s`) in the transcode
  pipeline — the segment writer writes once. HLS uses `.ts` containers
  by default, but the `HlsStreamer` can be configured to also produce
  `.m4s` segments for content that will be served via both protocols.
- The MPD uses SegmentTemplate (not SegmentList) for live-streaming
  efficiency — templates avoid listing every segment number in the
  manifest.
- DASH routes use different paths from HLS routes (`/dash/` vs. `/hls/`)
  so a CDN or proxy can route to the correct streamer without inspecting
  the manifest content.
