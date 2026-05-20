# Step F.5 — Comskip for Live TV recordings

**Phase:** F (Skip-Intro, Skip-Outro, Scene Markers)
**Step:** F.5
**Depends on:** F.3
**Review:** Yes — see `f.5-comskip-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

When a Live TV recording finishes (DVR), automatically run Comskip
(if installed) on the recorded file to detect commercial breaks, and
ingest the resulting EDL (Edit Decision List) file into Phlex's marker
system as chapters. This gives users automatic commercial skip on
their DVR recordings with zero additional configuration.

Comskip is a third-party C application (`comskip` or `comskip.exe`);
it is NOT bundled with Phlex. F.5 detects whether it is available,
runs it as a post-processing step, and handles the EDL → marker conversion.

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §2 Phase F table — F.5 depends on F.3.
- `src/LiveTv/Recorder.php` — existing recorder that saves DVR files.
  F.5 adds a post-processing hook after a recording completes.
- `src/Media/Markers/MarkerService.php` — `getMarkers()` reads
  markers (F.3); F.5 extends to also write Comskip-sourced chapters.
- `src/Media/Markers/MarkerSet.php` — `ChapterMarker` DTO (F.3).
- `PHLEX_EXPANSION_PLAN.md` §1 — Comskip for Live TV is explicitly listed
  as a Phase F / I.6 gap item.
- `config/` — existing config structure; will add `comskip.php`.
- `migrations/003_marker_columns.sql` (F.3) — `chapters_json` column
  already exists in `media_items`.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/LiveTv/ComskipRunner.php` — detects and runs Comskip:

  ```php
  class ComskipRunner
  {
      public function __construct(
          private readonly string $comskip_path,  // e.g. '/usr/bin/comskip'
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Check if comskip binary is available. */
      public function isAvailable(): bool {}

      /** Run comskip on a recording file. Returns path to .edl file or throws. */
      public function run(string $recording_path): string {}
  }
  ```

- `src/LiveTv/ComskipEdlParser.php` — parses Comskip EDL format:

  ```php
  class ComskipEdlParser
  {
      /**
       * Parse a Comskip .edl file.
       * Format (3 columns): start_seconds  end_seconds  scene_description
       * Returns array of ChapterMarker DTOs.
       */
      public function parse(string $edl_path): array<ChapterMarker> {}

      /** Parse from raw EDL string (for testing). */
      public function parseString(string $edl_content): array<ChapterMarker> {}
  }
  ```

- `src/LiveTv/ComskipPostProcessor.php` — orchestrator: after a
  recording finishes, check if Comskip is available, run it, parse EDL,
  store chapters in `chapters_json`:

  ```php
  class ComskipPostProcessor
  {
      public function __construct(
          private readonly ComskipRunner $comskip,
          private readonly ComskipEdlParser $edl_parser,
          private readonly MarkerService $marker_service,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /**
       * Process a completed recording: run comskip, parse EDL, store chapters.
       * Idempotent — if chapters already exist, skips silently.
       */
      public function processRecording(string $media_item_id, string $recording_path): void {}

      /** Check if a recording has already been processed. */
      public function isProcessed(string $media_item_id): bool {}
  }
  ```

- `src/LiveTv/RecordingHooks.php` — thin wrapper registering the
  post-processor as a hook on `Recorder`:

  ```php
  final class RecordingHooks
  {
      public const HOOK_POST_RECORD = 'live_tv.recording.completed';

      /** Register post-record hooks. Called during Application bootstrap. */
      public static function register(Recorder $recorder, ComskipPostProcessor $processor): void {}
  }
  ```

- `config/comskip.php` — default config:

  ```php
  return [
      'enabled' => true,
      'comskip_path' => '/usr/bin/comskip',
      'min_commercial_length' => 30,   // seconds; ignore shorter segments
      'require_confidence' => 0.7,     // skip if comskip confidence below this
      'post_process_immediately' => true,  // run immediately after recording
      'edl_output_dir' => null,         // null = same dir as recording
  ];
  ```

- `tests/unit/LiveTv/ComskipRunnerTest.php`
- `tests/unit/LiveTv/ComskipEdlParserTest.php`
- `tests/unit/LiveTv/ComskipPostProcessorTest.php`

#### Documentation

- `docs/advanced/live-tv-comskip.md` — new doc: how Comskip detection works,
  how to install Comskip, config options, and how to interpret the
  confidence threshold.

### Modify

- `src/LiveTv/Recorder.php` — add a `onComplete(callable)` hook
  mechanism; `RecordingHooks::register()` wires `ComskipPostProcessor`
  into the recorder's post-complete callback.
- `src/Media/Markers/MarkerService.php` — add
  `storeChapters(string $media_item_id, array<ChapterMarker> $chapters): void`.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b f.5-comskip`.
2. **ComskipRunner.** Detects `comskip` binary via `isAvailable()`;
   runs it via `proc_open()` with `--quiet` flag; waits for output.
   Timeout of 300 seconds (5 minutes) for the analysis phase.
3. **ComskipEdlParser.** Parses the 3-column tab-separated EDL format.
   Filters out segments shorter than `min_commercial_length`.
4. **ComskipPostProcessor.** Orchestrates: is Comskip available? has
   this recording already been processed? run → parse → store.
   Idempotent — safe to call multiple times.
5. **Recorder hook.** Add `onComplete(callable)` to `Recorder.php`.
   `RecordingHooks::register()` connects the post-processor.
6. **MarkerService update.** Add `storeChapters()` to persist the
   parsed `ChapterMarker[]` array to `chapters_json`.
7. **Config.** Write `config/comskip.php`.
8. **Tests.** Write all 3 test files per §5.
9. **Verification bar** (§0.4 minimum bar).
10. **Docs.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `ComskipRunnerTest::test_is_available_true_when_binary_exists`
2. `ComskipRunnerTest::test_is_available_false_when_binary_missing`
3. `ComskipRunnerTest::test_run_executes_comskip_and_returns_edl_path`
4. `ComskipRunnerTest::test_run_throws_when_comskip_fails`
5. `ComskipEdlParserTest::test_parse_returns_chapter_markers`
6. `ComskipEdlParserTest::test_parse_ignores_short_segments`
7. `ComskipEdlParserTest::test_parse_string_from_raw_content`
8. `ComskipPostProcessorTest::test_process_recording_runs_comskip_and_stores_chapters`
9. `ComskipPostProcessorTest::test_process_recording_is_idempotent`
10. `ComskipPostProcessorTest::test_is_processed_checks_marker_service`

**Coverage target:** `ComskipRunner` ≥ 85 %, `ComskipEdlParser` ≥ 85 %,
`ComskipPostProcessor` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Anything"** → `docs/advanced/live-tv-comskip.md` (new) covers
  Comskip installation, config, EDL format, and troubleshooting.
- **"New public class/method"** → all new public classes get PHPDoc with
  `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (Comskip commercial
  skip on Live TV recordings).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `ComskipRunner::isAvailable()` returns `bool` without throwing.
- [ ] `ComskipRunner::run()` executes `comskip` on the recording path.
- [ ] `ComskipEdlParser::parse()` returns `array<ChapterMarker>` from EDL file.
- [ ] `ComskipEdlParser` ignores segments shorter than `min_commercial_length`.
- [ ] `ComskipPostProcessor::processRecording()` is idempotent.
- [ ] `ComskipPostProcessor::processRecording()` stores chapters via
      `MarkerService::storeChapters()`.
- [ ] `Recorder::onComplete()` hook fires after a recording completes.
- [ ] `RecordingHooks::register()` wires post-processor into the recorder.
- [ ] `config/comskip.php` exists with all required keys.
- [ ] `./vendor/bin/phpunit` — green; ≥ 10 new tests.
- [ ] Coverage of `ComskipRunner` + `ComskipEdlParser` + `ComskipPostProcessor` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/advanced/live-tv-comskip.md` written.
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
git checkout -b f.5-comskip

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Comskip'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step F.5: Comskip for Live TV recordings — EDL ingestion, chapter storage"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step F.5: Comskip for Live TV recordings — EDL parsing, chapter storage" \
  --body  "Adds ComskipRunner, ComskipEdlParser, ComskipPostProcessor, RecordingHooks to wire into Recorder post-complete. Stores commercial chapters in chapters_json. Part of Phase F (Step F.5 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'f.5-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `f.5-comskip-review.md`.

Non-obvious points:
- Comskip is a **third-party closed-source binary** (Windows/macOS/Linux).
  Phlex does not install it; the user must install it separately. The
  `isAvailable()` check handles its absence gracefully.
- EDL format: 3 tab-separated columns — `start_frame`, `end_frame`,
  `type` (0=cut, 1=mute, 2=scene change, 3=commerccial). F.5 converts
  frames to seconds using Comskip's `--filmdumpy` output or the `-1` flag
  that outputs seconds directly.
- The `require_confidence` config key filters out low-confidence
  detections before storing chapters — this prevents noisy EDL files from
  producing spurious chapters on low-quality recordings.
- The hook in `Recorder` uses a simple ` callable[]` array internally
  (no event dispatcher needed at this stage — that architecture comes in
  a future phase). This keeps F.5 self-contained.
