# Step F.2 — Intro/outro detection job

**Phase:** F (Skip-Intro, Skip-Outro, Scene Markers)
**Step:** F.2
**Depends on:** F.1
**Review:** Yes — see `f.2-intro-detect-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Build a background queue worker that detects intro and outro sequences for
TV episodes by clustering audio fingerprints per show. Episodes with
similar fingerprints in the first N minutes are flagged as sharing an
intro; similarly for the last M minutes (outro). Detection results are
stored as marker candidates in `media_items.metadata_json` (consumed by
F.3 API).

This step does NOT write to the final `chapters` / `intro_marker` /
`outro_marker` columns — that is F.3's scope.

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §1 — "Intro/outro skip" is **Missing**.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase F table — F.2 depends on F.1.
- `src/Media/Markers/Fingerprinting/FingerprintRepository.php` — stores
  and retrieves fingerprints from `media_items.metadata_json` (F.1).
- `src/Media/Library/ItemRepository.php` — queries `media_items` rows by
  `library_id` and `parent_id` (for series/season grouping).
- `src/Media/Library/LibraryManager.php` — drives library scanning.
- `PHLEX_EXPANSION_PLAN.md` §1 — background queue worker pattern not yet
  implemented; Workerman's `Timer` class or a simple file-based job queue
  is acceptable for v1.
- `src/Media/Transcoding/FfmpegRunner.php` — can produce PCM audio for
  fingerprinting.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Markers/Detection/IntroDetectionJob.php` — the job class:

  ```php
  class IntroDetectionJob
  {
      public function __construct(
          private readonly FingerprintRepository $fingerprint_repo,
          private readonly ItemRepository $item_repo,
          private readonly ChromaPrintInterface $chroma_print,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Run detection for all episodes of a given show (parent_id = series). */
      public function detectForShow(string $show_id): IntroDetectionResult {}

      /** Convenience: detect for all shows that have unfingerprinted episodes. */
      public function detectAllPending(): \Generator {}
  }
  ```

- `src/Media/Markers/Detection/IntroDetectionResult.php` — result DTO:

  ```php
  final class IntroDetectionResult
  {
      public function __construct(
          public readonly string $show_id,
          public readonly int $episodes_ fingerprinted,
          public readonly ?IntroMarkerCandidate $intro_candidate,
          public readonly ?OutroMarkerCandidate $outro_candidate,
          public readonly array $episodes_processed, // media_item_id[]
      ) {}
  }

  final class IntroMarkerCandidate
  {
      public function __construct(
          public readonly int $start_seconds,  // e.g. 0
          public readonly int $end_seconds,      // e.g. 90
          public readonly string $fingerprint,   // representative fp
          public readonly int $confidence,       // 0–100
      ) {}
  }

  final class OutroMarkerCandidate
  {
      public function __construct(
          public readonly int $start_seconds,   // e.g. 2340
          public readonly int $end_seconds,       // e.g. 2520
          public readonly string $fingerprint,
          public readonly int $confidence,
      ) {}
  }
  ```

- `src/Media/Markers/Detection/FingerprintClusterer.php` — clustering
  algorithm using Jaccard similarity on fingerprint data:

  ```php
  class FingerprintClusterer
  {
      /** Cluster a list of (media_item_id, fingerprint, duration) into intro/outro groups. */
      public function cluster(array $episodes): ClusteringResult {}

      /** Jaccard similarity between two fingerprint strings. */
      private function similarity(string $fp_a, string $fp_b): float {}
  }
  ```

- `src/Media/Markers/Detection/ClusteringResult.php` — result of clustering:

  ```php
  final class ClusteringResult
  {
      public function __construct(
          public readonly ?IntroMarkerCandidate $intro,
          public readonly ?OutroMarkerCandidate $outro,
          public readonly array $unmatched, // episodes that didn't cluster
      ) {}
  }
  ```

- `src/Media/Markers/Detection/MarkerCandidateRepository.php` — persists
  detection candidates to `metadata_json` on each episode:

  ```php
  class MarkerCandidateRepository
  {
      public function __construct(private readonly ItemRepository $item_repo) {}

      /** Store intro/outro candidates on all episodes of a show. */
      public function storeCandidates(string $show_id, IntroDetectionResult $result): void {}

      /** Load stored candidates for an episode. Returns null if none. */
      public function getCandidates(string $media_item_id): ?StoredMarkers {}
  }
  ```

- `src/Media/Markers/Detection/MarkerCandidateStore.php` — file-based
  job queue for background processing:

  ```php
  final class MarkerCandidateStore
  {
      private const QUEUE_DIR = '/tmp/phlex_marker_jobs';

      /** Enqueue a show for intro/outro detection. Idempotent. */
      public function enqueueShow(string $show_id): void {}

      /** Dequeue next show_id, or null if queue empty. */
      public function dequeueShow(): ?string {}

      /** Mark a show's job as complete (remove from queue). */
      public function completeShow(string $show_id): void {}

      /** Return all pending show_ids. */
      public function getPendingShows(): array<string> {}
  }
  ```

- `src/Media/Markers/Detection/BackgroundDetectorWorker.php` — worker
  that processes the queue (runs as a separate PHP process):

  ```php
  class BackgroundDetectorWorker
  {
      public function __construct(
          private readonly IntroDetectionJob $job,
          private readonly MarkerCandidateStore $store,
      ) {}

      /** Run one iteration: dequeue a show, detect, store results. */
      public function runOnce(): void {}

      /** Run continuously with a sleep interval. */
      public function runLoop(int $sleep_seconds = 30): void {}
  }
  ```

- `config/marker_detection.php` — default config:

  ```php
  return [
      'intro_start_seconds' => 0,
      'intro_max_duration' => 180,       // max expected intro length
      'outro_max_duration' => 180,      // max expected outro length
      'similarity_threshold' => 0.85,    // Jaccard similarity to declare a match
      'min_episodes_for_detection' => 3,  // need at least 3 eps before detecting
      'job_queue_dir' => '/tmp/phlex_marker_jobs',
      'worker_interval' => 30,
  ];
  ```

- `scripts/run-marker-detection-worker.php` — CLI entry point:

  ```php
  #!/usr/bin/env php
  // Run BackgroundDetectorWorker in a loop
  ```

- `tests/Unit/Media/Markers/Detection/IntroDetectionJobTest.php`
- `tests/Unit/Media/Markers/Detection/FingerprintClustererTest.php`
- `tests/Unit/Media/Markers/Detection/MarkerCandidateStoreTest.php`

#### Documentation

- `docs/developers/intro-outro-detection.md` — explains the clustering
  algorithm, configuration, and how to run the background worker.

### Modify

- `composer.json` — no new dependencies.
- `src/Media/Library/ItemRepository.php` — add method
  `getEpisodesByShow(string $show_id): array` if not already present.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b f.2-intro-detect`.
2. **Result DTOs first.** `IntroMarkerCandidate`, `OutroMarkerCandidate`,
   `IntroDetectionResult`, `ClusteringResult` — all immutable.
3. **FingerprintClusterer.** Implement Jaccard similarity on fingerprint
   strings. Group episodes whose first-N-seconds fingerprints are ≥ 85 %
   similar → intro cluster. Group episodes whose last-M-seconds fingerprints
   are similarly similar → outro cluster. Keep the longest match as the
   canonical marker candidate.
4. **IntroDetectionJob.** Orchestrates: fetch all episodes of a show,
   call `FingerprintClusterer->cluster()`, return `IntroDetectionResult`.
5. **MarkerCandidateRepository.** Writes `intro_candidate` /
   `outro_candidate` objects into `metadata_json` of each episode.
6. **MarkerCandidateStore.** File-based queue in `/tmp/phlex_marker_jobs/`
   using one file per show_id. Files are created by library scan (future)
   or manually enqueued.
7. **BackgroundDetectorWorker.** Simple loop consuming the queue.
8. **CLI script.** `scripts/run-marker-detection-worker.php` runs the worker.
9. **Config.** Write `config/marker_detection.php`.
10. **Tests.** Write all 3 test files per §5.
11. **Verification bar** (§0.4 minimum bar).
12. **Docs.**
13. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `IntroDetectionJobTest::test_detect_for_show_returns_result`
2. `IntroDetectionJobTest::test_detect_for_show_needs_min_episodes`
3. `IntroDetectionJobTest::test_detect_all_pending_yields_generator`
4. `FingerprintClustererTest::test_cluster_groups_similar_fingerprints`
5. `FingerprintClustererTest::test_cluster_returns_null_when_insufficient_similarity`
6. `FingerprintClustererTest::test_similarity_returns_float_between_0_and_1`
7. `FingerprintClustererTest::test_intro_cluster_at_start`
8. `FingerprintClustererTest::test_outro_cluster_at_end`
9. `MarkerCandidateStoreTest::test_enqueue_and_dequeue`
10. `MarkerCandidateStoreTest::test_dequeue_is_fifo`
11. `MarkerCandidateStoreTest::test_complete_removes_from_queue`
12. `MarkerCandidateStoreTest::test_get_pending_shows`
13. `MarkerCandidateRepositoryTest::test_store_and_retrieve_candidates`

**Coverage target:** `IntroDetectionJob` ≥ 85 %, `FingerprintClusterer` ≥ 85 %,
`MarkerCandidateStore` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Anything"** → `docs/developers/intro-outro-detection.md` (new) covers
  clustering algorithm, config, worker script.
- **"New public class/method"** → all new public classes get PHPDoc with
  `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (background detection
  worker added; markers visible in F.3).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `IntroDetectionJob::detectForShow()` returns `IntroDetectionResult`.
- [ ] `FingerprintClusterer::cluster()` correctly groups similar fingerprints.
- [ ] `FingerprintClusterer::similarity()` returns 0.0–1.0 float.
- [ ] `MarkerCandidateStore::enqueueShow()` / `dequeueShow()` / `completeShow()`
      maintain a FIFO queue in the filesystem.
- [ ] `MarkerCandidateRepository::storeCandidates()` persists candidates
      to each episode's `metadata_json`.
- [ ] `BackgroundDetectorWorker::runOnce()` processes one show from the queue.
- [ ] `scripts/run-marker-detection-worker.php` runs as a standalone CLI.
- [ ] `config/marker_detection.php` exists with all required keys.
- [ ] `./vendor/bin/phpunit` — green; ≥ 13 new tests.
- [ ] Coverage of `IntroDetectionJob` + `FingerprintClusterer` + `MarkerCandidateStore` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/intro-outro-detection.md` written.
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
git checkout -b f.2-intro-detect

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'IntroDetectionJob|FingerprintClusterer|MarkerCandidateStore'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step F.2: Intro/outro detection — background queue worker, FingerprintClusterer"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step F.2: Intro/outro detection background worker" \
  --body  "Adds IntroDetectionJob, FingerprintClusterer (Jaccard similarity), MarkerCandidateStore (file queue), BackgroundDetectorWorker, scripts/run-marker-detection-worker.php. Part of Phase F (Step F.2 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'f.2-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `f.2-intro-detect-review.md`.

Non-obvious points:
- The clustering algorithm uses Jaccard similarity on the raw fingerprint
  strings (not the duration-weighted vectors) to keep it simple and fast.
  Threshold of 0.85 was chosen empirically — it passes episodes that are
  truly the same intro but rejects episodes where the first few seconds
  differ (cold open before title sequence).
- The file-based queue in `/tmp/phlex_marker_jobs/` was chosen over a
  database queue to avoid requiring a new `marker_jobs` table at this stage.
  Each show being processed is represented by a file named `{show_id}.lock`.
