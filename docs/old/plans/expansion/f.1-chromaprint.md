# Step F.1 — Chromaprint integration

**Phase:** F (Skip-Intro, Skip-Outro, Scene Markers)
**Step:** F.1
**Depends on:** E.6
**Review:** Yes — see `f.1-chromaprint-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Integrate Chromaprint (the AcoustID fingerprinting library) into the Phlex
media server so that every media item can carry an audio fingerprint. The
fingerprint is stored in the `media_items` table and is used in F.2 to
cluster episodes of the same show into groups for intro/outro detection.

FFI bindings to `libchromaprint` are preferred; a shelled `fpcalc`
binary fallback is required for systems where FFI is unavailable or
blocked.

## 2. Context (what already exists)

- `src/Media/Transcoding/FfmpegRunner.php` — already extracts audio;
  will be extended to produce a raw PCM/WAV stream for fingerprinting.
- `src/Media/Library/ItemRepository.php` — hydrates `media_items`
  rows; schema already has `metadata_json` (JSON column) which can hold
  the fingerprint.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Intro/outro skip, scene markers,
  Chromaprint" is **Missing**.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase F table — F.1 is the Chromaprint
  integration step.
- `config/ffmpeg.php` — `ffmpeg_path` already exists; will add
  `fpcalc_path` fallback config.
- `src/Server/Core/Application.php` — server bootstrap; F.1 does NOT
  auto-trigger fingerprinting at startup (deferred to F.2 background job).

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Markers/Fingerprinting/ChromaPrint.php` — main wrapper:

  ```php
  class ChromaPrint
  {
      public function __construct(
          private readonly string $fpcalc_path,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Generate a fingerprint for an audio file or media item. Returns raw fp data or throws. */
      public function fingerprint(string $path): string {}

      /** Check if fpcalc binary is available and functional. */
      public function isAvailable(): bool {}
  }
  ```

- `src/Media/Markers/Fingerprinting/ChromaPrintFfi.php` — FFI path:

  ```php
  class ChromaPrintFfi implements ChromaPrintInterface
  {
      public function __construct(?LoggerInterface $logger = null) {}

      public function fingerprint(string $path): string {}
      public function isAvailable(): bool {}
  }
  ```

- `src/Media/Markers/Fingerprinting/ChromaPrintShelled.php` — shelled
  `fpcalc` fallback:

  ```php
  class ChromaPrintShelled implements ChromaPrintInterface
  {
      public function __construct(
          private readonly string $fpcalc_path,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public function fingerprint(string $path): string {}
      public function isAvailable(): bool {}
  }
  ```

- `src/Media/Markers/Fingerprinting/ChromaPrintFactory.php` — factory
  that tries FFI first, falls back to shelled:

  ```php
  final class ChromaPrintFactory
  {
      public static function build(?LoggerInterface $logger = null): ChromaPrintInterface {}
  }
  ```

- `src/Media/Markers/Fingerprinting/ChromaPrintNotAvailableException.php`
- `src/Media/Markers/Fingerprinting/ChromaPrintFingerprintFailedException.php`

- `src/Media/Markers/Fingerprinting/FingerprintRepository.php` — persists
  fingerprints to `media_items` via `ItemRepository`:

  ```php
  class FingerprintRepository
  {
      public function __construct(private readonly ItemRepository $item_repo) {}

      /** Store a raw fingerprint string on a media item's metadata_json. */
      public function storeFingerprint(string $media_item_id, string $fingerprint): void {}

      /** Retrieve stored fingerprint. Returns '' if none. */
      public function getFingerprint(string $media_item_id): string {}

      /** Return all media_item_ids for a given show that already have fingerprints. */
      public function getFingerprintedIdsForShow(string $show_id): array<string> {}
  }
  ```

- `config/chromaprint.php` — default config:

  ```php
  return [
      'enabled' => true,
      'fpcalc_path' => '/usr/local/bin/fpcalc',
      'use_ffi_first' => true,
      'fingerprint_audio_seconds' => 120,  // seconds of audio to fingerprint (0 = full file)
      'skip_if_duration_lt' => 300,       // don't fingerprint items < 5 minutes
  ];
  ```

- `tests/unit/Media/Markers/Fingerprinting/ChromaPrintTest.php`
- `tests/unit/Media/Markers/Fingerprinting/FingerprintRepositoryTest.php`

#### Documentation

- `docs/developers/chromaprint.md` — new doc explaining FFI vs shelled
  mode, config options, and how fingerprints feed into intro/outro detection.

### Modify

- `config/ffmpeg.php` — no changes (FFmpeg is used to extract audio for
  the shelled `fpcalc`; FFI mode bypasses `fpcalc` entirely).
- `composer.json` — no new runtime dependencies. Dev-only FFI test stubs OK.
- `src/Media/Library/ItemRepository.php` — no schema changes; fingerprint
  stored in existing `metadata_json` JSON column.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b f.1-chromaprint`.
2. **Interface first.** Define `ChromaPrintInterface` so both FFI and
   shelled implementations satisfy it.
3. **Shelled implementation.** `ChromaPrintShelled` wraps `fpcalc`
   via `proc_open()` with a 60-second timeout. Parse the `FINGERPRINT=`
   line from stdout.
4. **FFI implementation.** `ChromaPrintFfi` uses PHP FFI to call
   `chromaprint_get_fingerprint` / `chromaprint_encode_fingerprint`
   if `ffi.enable=1` in php.ini and the shared library is present.
   Catch all FFI errors gracefully and return `false` from
   `isAvailable()` so the factory can fall back to shelled.
5. **Factory.** `ChromaPrintFactory::build()` checks FFI availability
   first; falls back to shelled binary.
6. **FingerprintRepository.** Thin wrapper over `ItemRepository` that
   reads/writes `fingerprint` key in `metadata_json` blob.
7. **Config.** Write `config/chromaprint.php`.
8. **Tests.** Write both test files per §5. Mock `ItemRepository`
   and `Connection` per project conventions.
9. **Verification bar** (§0.4 minimum bar).
10. **Docs.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `ChromaPrintTest::test_fingerprint_returns_string`
2. `ChromaPrintTest::test_is_available_returns_bool`
3. `ChromaPrintTest::test_fingerprint_throws_on_failure`
4. `ChromaPrintFfiTest::test_is_available_false_when_ffi_unavailable`
5. `ChromaPrintShelledTest::test_fingerprint_parses_fpcalc_output`
6. `ChromaPrintShelledTest::test_is_available_checks_binary_exists`
7. `ChromaPrintFactoryTest::test_build_prefers_ffi_when_available`
8. `ChromaPrintFactoryTest::test_build_falls_back_to_shelled`
9. `FingerprintRepositoryTest::test_store_and_retrieve_fingerprint`
10. `FingerprintRepositoryTest::test_get_returns_empty_string_when_missing`
11. `FingerprintRepositoryTest::test_get_fingerprinted_ids_for_show`

**Coverage target:** `ChromaPrintShelled` ≥ 85 %, `ChromaPrintFfi` ≥ 70 %
(FFI is environment-dependent), `FingerprintRepository` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Anything"** → `docs/developers/chromaprint.md` (new) covers FFI vs
  shelled mode, config keys, fingerprint storage schema.
- **"New public class/method"** → all new public classes get PHPDoc with
  `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (fingerprint storage
  added; no user-visible change yet — detection runs in F.2).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `ChromaPrintInterface` defines `fingerprint()` and `isAvailable()`.
- [ ] `ChromaPrintFfi` attempts FFI first; `isAvailable()` returns `false`
      gracefully when FFI is unavailable.
- [ ] `ChromaPrintShelled` wraps `fpcalc`; `fingerprint()` parses the
      `FINGERPRINT=` output line.
- [ ] `ChromaPrintFactory::build()` selects FFI when available, shelled otherwise.
- [ ] `FingerprintRepository::storeFingerprint()` persists to `metadata_json`.
- [ ] `FingerprintRepository::getFingerprint()` retrieves or returns `''`.
- [ ] `FingerprintRepository::getFingerprintedIdsForShow()` returns a list.
- [ ] `config/chromaprint.php` exists with all required keys.
- [ ] `./vendor/bin/phpunit` — green; ≥ 11 new tests.
- [ ] Coverage of `ChromaPrintShelled` ≥ 85 %, `FingerprintRepository` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/chromaprint.md` written.
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
git checkout -b f.1-chromaprint

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'ChromaPrint|FingerprintRepository'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step F.1: Chromaprint integration — FFI + shelled fpcalc, FingerprintRepository"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step F.1: Chromaprint integration (FFI + fpcalc fallback)" \
  --body  "Adds ChromaPrint FFI + shelled wrappers, ChromaPrintFactory, FingerprintRepository, config/chromaprint.php. Fingerprints stored in media_items metadata_json. Part of Phase F (Step F.1 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'f.1-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `f.1-chromaprint-review.md`.

Non-obvious points:
- FFI is the preferred path (zero binary dependency), but the shelled
  `fpcalc` path must work on systems where FFI is disabled (common in
  shared hosting). The factory handles the switch automatically.
- Fingerprints are stored in `metadata_json`, not a separate table, to
  avoid schema changes at this stage.
- `fingerprint_audio_seconds = 120` fingerprints only the first 2 minutes
  by default — enough for episode grouping without processing entire files.
