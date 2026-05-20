# Step I.6 — Commercial skip via Comskip

**Phase:** I (Live TV / DVR / IPTV)
**Step:** I.6
**Depends on:** F.5
**Review:** Yes — see `i.6-comskip-live-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Integrate Comskip (the commercial-detection EDL processor) into the live-TV
recording post-processing pipeline, making detected commercial segments
available as chapter markers for playback and enabling clients to skip
commercials automatically. Already has `ComskipRunner.php` and
`ComskipEdlParser.php` (Phase F.5); this step wires them into the recording
completion flow and persists EDL data to the DB.

## 2. Context (what already exists)

- `src/LiveTv/ComskipRunner.php` — already exists (Phase F.5); runs
  `comskip` binary on a recording file and outputs `.edl` file.
- `src/LiveTv/ComskipEdlParser.php` — already exists (Phase F.5); parses
  `.edl` files into a structured array.
- `src/LiveTv/ComskipPostProcessor.php` — already exists (Phase F.5);
  orchestrates running Comskip and parsing the output.
- `src/LiveTv/Recorder.php` — already has `fireOnCompleteCallbacks()` which
  is the hook point for post-processing.
- `config/ffmpeg.php` — existing FFmpeg config; may need comskip binary path.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase I table — I.6 is the Comskip integration step.
- `migrations/013_livetv_dvr.sql` (I.5) — `livetv_recordings` already has
  a recording; may need `commercial_skipped_at` column.

## 3. Scope — files to create / modify

### Create

#### Comskip Live integration

- `src/LiveTv/Recording/ComskipIntegration.php` — wires Comskip into
  the recording lifecycle:

  ```php
  class ComskipIntegration
  {
      public function __construct(
          private readonly ComskipRunner $runner,
          private readonly ComskipEdlParser $parser,
          private readonly Connection $db,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Run Comskip on a completed recording file. */
      public function processRecording(string $recordingId, string $filePath): array {}

      /** Get parsed EDL segments for a recording. Returns edlSegments[]. */
      public function getEdlSegments(string $recordingId): array {}

      /** Mark a recording's commercial processing as complete. */
      public function markProcessed(string $recordingId): void {}
  }
  ```

- `src/LiveTv/Recording/ComskipLifecycleManager.php` — manages the
  lifecycle of Comskip processing (queue, retry, completion):

  ```php
  class ComskipLifecycleManager
  {
      public function __construct(
          private readonly ComskipIntegration $integration,
          private readonly Connection $db,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Enqueue a completed recording for Comskip processing. */
      public function enqueue(string $recordingId, string $filePath): void {}

      /** Process the next queued recording. */
      public function processNext(): bool {}

      /** Get count of pending recordings. */
      public function getPendingCount(): int {}
  }
  ```

- `src/LiveTv/Recording/ChapterMarkerService.php` — converts EDL segments
  into HLS chapter markers for playback clients:

  ```php
  class ChapterMarkerService
  {
      /** Convert EDL segments to HLS EXTINF chapter markers. */
      public function toHlsChapters(array $edlSegments): array {}

      /** Persist chapter markers to media_items metadata_json. */
      public function persistChapters(string $mediaItemId, array $edlSegments): void {}

      /** Get chapter markers for a media item. */
      public function getChapters(string $mediaItemId): array {}
  }
  ```

#### DB Migration

- `migrations/014_livetv_commercials.sql` — add columns for commercial
  processing status to `livetv_recordings`:
  ```sql
  ALTER TABLE livetv_recordings
    ADD COLUMN commercial_processed_at DATETIME NULL,
    ADD COLUMN commercial_edl_path VARCHAR(512) NULL,
    ADD COLUMN commercial_frame_count INT NULL,
    ADD COLUMN commercial_duration_seconds INT NULL;
  ```

#### Config

- `config/livetv.php` — add comskip section:
  ```php
  'comskip' => [
      'enabled' => true,
      'binary_path' => '/usr/bin/comskip',
      'ini_path' => '/etc/comskip/comskip.ini',
      'output_dir' => '/var/recordings/edl',
      'queue_processing' => true,
      'max_concurrent' => 2,
  ],
  ```

#### Tests

- `tests/Unit/LiveTv/Recording/ComskipIntegrationTest.php`
- `tests/Unit/LiveTv/Recording/ComskipLifecycleManagerTest.php`
- `tests/Unit/LiveTv/Recording/ChapterMarkerServiceTest.php`

#### Documentation

- `docs/developers/comskip-live.md` — comskip integration into DVR pipeline,
  EDL format, chapter marker format, config.

### Modify

- `src/LiveTv/Recorder.php` — after I.5, `fireOnCompleteCallbacks()` is
  the hook. Wire `ComskipLifecycleManager::enqueue()` into the callback
  chain so every completed recording is automatically enqueued for Comskip.
- `config/livetv.php` — add comskip section (done in Create above).
- `composer.json` — no new dependencies.
- `CHANGELOG.md` — add entry: "Added: Comskip commercial detection for
  live TV recordings with chapter markers".

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master, branch `i.6-comskip-live`.
   Read existing: `ComskipRunner.php`, `ComskipEdlParser.php`, `ComskipPostProcessor.php`.
2. **Integration class.** `ComskipIntegration::processRecording()` calls
   `$runner->run($filePath)` → `$parser->parse($runner->getEdlPath())`.
   Stores result in `livetv_recordings` via DB query.
3. **Lifecycle manager.** `ComskipLifecycleManager` maintains an in-memory
   queue of pending recording IDs. `processNext()` pops the next, calls
   `ComskipIntegration::processRecording()`. If queue processing is disabled,
   the enqueue method processes immediately.
4. **Chapter markers.** `ChapterMarkerService::toHlsChapters()` converts
   EDL `[start, end, type]` entries into HLS `EXTINF` chapter format:
   `#EXTBINary:byte=0,URI="..."` per segment.
5. **Recorder hook.** `Recorder::onComplete()` already fires after
   `stopRecording()`. Register `ComskipLifecycleManager::enqueue()` as a
   callback at construction time.
6. **DB migration.** Add columns for commercial processing results.
7. **Tests.** Three test files per §5.
8. **Verification bar.**
9. **Docs.**
10. **Commit + PR + merge.**

## 5. Tests (REQUIRED)

Unit tests (coverage ≥ 85 % on every new class):

1. `ComskipIntegrationTest::test_process_recording_runs_comskip_and_parses`
2. `ComskipIntegrationTest::test_process_recording_stores_result_in_db`
3. `ComskipIntegrationTest::test_get_edl_segments_returns_parsed`
4. `ComskipIntegrationTest::test_mark_processed_sets_timestamp`
5. `ComskipLifecycleManagerTest::test_enqueue_adds_to_queue`
6. `ComskipLifecycleManagerTest::test_process_next_runs_integration`
7. `ComskipLifecycleManagerTest::test_process_next_returns_false_when_empty`
8. `ComskipLifecycleManagerTest::test_get_pending_count`
9. `ChapterMarkerServiceTest::test_to_hls_chapters_formats_correctly`
10. `ChapterMarkerServiceTest::test_persist_chapters_stores_in_metadata_json`
11. `ChapterMarkerServiceTest::test_get_chapters_retrieves_stored`
12. `ChapterMarkerServiceTest::test_to_hls_chapters_handles_empty_segments`

**Coverage target:** `ComskipIntegration` ≥ 85 %, `ComskipLifecycleManager` ≥ 85 %,
`ChapterMarkerService` ≥ 85 %.

## 6. Documentation

Matrix rows that apply:
- **"Anything"** → `docs/developers/comskip-live.md` covers comskip binary,
  EDL format, pipeline integration, chapter marker output.
- **"New public class/method"** → all new classes get PHPDoc with `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry.

## 7. Acceptance criteria

- [ ] `ComskipIntegration::processRecording()` runs Comskip on the recording file.
- [ ] `ComskipIntegration::processRecording()` stores results in
      `livetv_recordings.commercial_*` columns.
- [ ] `ComskipIntegration::getEdlSegments()` returns parsed EDL segments.
- [ ] `ComskipLifecycleManager::enqueue()` adds recording to queue.
- [ ] `ComskipLifecycleManager::processNext()` pops and processes one recording.
- [ ] `ComskipLifecycleManager` respects `max_concurrent` (runs at most N
      concurrent Comskip processes at once).
- [ ] `ChapterMarkerService::toHlsChapters()` converts EDL segments to
      HLS chapter marker format.
- [ ] `ChapterMarkerService::persistChapters()` stores markers in
      `media_items.metadata_json` under `commercial_chapters` key.
- [ ] `Recorder` automatically enqueues completed recordings for Comskip
      processing via `onComplete()` callback.
- [ ] `config/livetv.php` has `comskip` key with `binary_path`.
- [ ] `./vendor/bin/phpunit` — green; ≥ 12 new tests.
- [ ] Coverage targets met.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/comskip-live.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual

```bash
cd /home/sites/phlex
git status --short && git branch --show-current && git pull --ff-only origin master
git checkout -b i.6-comskip-live
php scripts/run-migrations.php
# ... implement ...
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'ComskipIntegration|ComskipLifecycle|ChapterMarker'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
git add -A
git commit -m "Step I.6: Comskip commercial skip for live TV recordings"
unset GITHUB_TOKEN
gh pr create \
  --title "Step I.6 (Live TV): Comskip commercial skip for recordings" \
  --body  "Wires Comskip into the recording post-processing pipeline: ComskipIntegration, ComskipLifecycleManager, ChapterMarkerService, HLS chapter output. Part of Phase I (Step I.6 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
git status --short && git branch --show-current && git log --oneline -1 && git branch --list 'i.6-*'
```
