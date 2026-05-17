# Step F.3 — Marker storage + API

**Phase:** F (Skip-Intro, Skip-Outro, Scene Markers)
**Step:** F.3
**Depends on:** F.2
**Review:** Yes — see `f.3-markers-api-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Add `chapters`, `intro_marker`, and `outro_marker` columns to the
`media_items` table (via migration), populate them from the detection
results stored in `metadata_json` (F.2), and expose GET endpoints so
clients can fetch markers for any item.

F.3 does NOT add write endpoints (marker editing is a Phase H task);
F.3 does NOT wire markers into the player UI (that is F.4 + Phase M).

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §2 Phase F table — F.3 depends on F.2.
- `src/Media/Library/ItemRepository.php` — hydrates `media_items` rows.
- `src/Media/Markers/Detection/MarkerCandidateRepository.php` — reads
  intro/outro candidates from `metadata_json` (F.2).
- `src/Server/Http/Router.php` — already has routing; will add marker routes.
- `src/Server/Http/Controllers/MediaItemController.php` — existing
  controller; will add marker endpoints alongside existing ones.
- `migrations/001_initial_schema.sql` — existing schema reference; F.3
  adds a new migration file.

## 3. Scope — files to create / modify

### Create

#### New migration

- `migrations/003_marker_columns.sql`:

  ```sql
  ALTER TABLE media_items
    ADD COLUMN intro_start_seconds INT UNSIGNED NULL,
    ADD COLUMN intro_end_seconds   INT UNSIGNED NULL,
    ADD COLUMN outro_start_seconds INT UNSIGNED NULL,
    ADD COLUMN outro_end_seconds   INT UNSIGNED NULL,
    ADD COLUMN chapters_json       JSON NULL;  -- array of { start, end, title? }
  ```

#### New classes

- `src/Media/Markers/MarkerService.php` — reads from
  `MarkerCandidateRepository`, migrates candidates to the new columns,
  exposes a clean read API:

  ```php
  class MarkerService
  {
      public function __construct(
          private readonly ItemRepository $item_repo,
          private readonly MarkerCandidateRepository $candidate_repo,
      ) {}

      /** Promote stored detection candidates to the正式的 marker columns. */
      public function promoteCandidates(string $media_item_id): void {}

      /** Get all markers for an item. Returns MarkerSet DTO. */
      public function getMarkers(string $media_item_id): MarkerSet {}

      /** Bulk-promote all candidates for a show's episodes. */
      public function promoteShowMarkers(string $show_id): int {}
  }
  ```

- `src/Media/Markers/MarkerSet.php` — aggregate DTO:

  ```php
  final class MarkerSet
  {
      public function __construct(
          public readonly ?IntroMarker $intro,
          public readonly ?OutroMarker $outro,
          public readonly array $chapters, // IntroMarker[]
      ) {}
  }

  final class IntroMarker
  {
      public function __construct(
          public readonly int $start_seconds,
          public readonly int $end_seconds,
          public readonly int $confidence,  // 0–100
      ) {}
  }

  final class OutroMarker
  {
      public function __construct(
          public readonly int $start_seconds,
          public readonly int $end_seconds,
          public readonly int $confidence,
      ) {}
  }

  final class ChapterMarker
  {
      public function __construct(
          public readonly int $start_seconds,
          public readonly int $end_seconds,
          public readonly ?string $title,
      ) {}
  }
  ```

- `src/Server/Http/Controllers/MarkerController.php` — GET endpoints:

  ```php
  class MarkerController
  {
      public function __construct(
          private readonly MarkerService $marker_service,
      ) {}

      /** GET /api/v1/media/{id}/markers */
      public function getMarkers(Request $req, array $params): Response {}

      /** GET /api/v1/media/{id}/markers/intro */
      public function getIntroMarker(Request $req, array $params): Response {}

      /** GET /api/v1/media/{id}/markers/outro */
      public function getOutroMarker(Request $req, array $params): Response {}

      /** GET /api/v1/shows/{id}/markers/bulk — all episode markers for a show */
      public function getShowMarkers(Request $req, array $params): Response {}
  }
  ```

- `src/Server/Http/Router.php` — register the 4 marker routes
  (done in `addRoutes` or equivalent method).

- `tests/unit/Media/Markers/MarkerServiceTest.php`
- `tests/unit/Media/Markers/MarkerSetTest.php`
- `tests/unit/Server/Http/Controllers/MarkerControllerTest.php`

#### Documentation

- `docs/reference/api.md` — add marker endpoint documentation (inline
  update, not a new file).

### Modify

- `migrations/` directory — add `003_marker_columns.sql`.
- `src/Media/Library/ItemRepository.php` — add getter/setter for the 5
  new marker columns (map to `intro_start_seconds`, `intro_end_seconds`,
  etc.).
- `src/Server/Http/Router.php` — register marker routes.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b f.3-markers-api`.
2. **Migration first.** Write `003_marker_columns.sql`; run it against
   the dev DB to verify.
3. **DTOs.** `IntroMarker`, `OutroMarker`, `ChapterMarker`, `MarkerSet`
   — all immutable value objects with `@since 0.12.0`.
4. **MarkerService.** Read candidates from `metadata_json` (F.2 output),
   promote to the正式的 columns. `getMarkers()` reads from the formal
   columns if populated, falls back to candidates.
5. **MarkerController.** Four GET endpoints returning `MarkerSet` JSON.
   Uses existing `Request::fromGlobals()` and `Response` chain pattern.
6. **Router registration.** Four new routes in `Router.php`.
7. **ItemRepository update.** Add typed getters/setters for the new columns.
8. **Tests.** Write all 3 test files per §5.
9. **Verification bar** (§0.4 minimum bar).
10. **Docs.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `MarkerSetTest::test_empty_when_no_markers`
2. `MarkerSetTest::test_intro_and_outro_accessible`
3. `MarkerSetTest::test_chapters_array`
4. `MarkerServiceTest::test_promote_candidates_writes_columns`
5. `MarkerServiceTest::test_get_markers_reads_formal_columns_first`
6. `MarkerServiceTest::test_get_markers_falls_back_to_candidates`
7. `MarkerServiceTest::test_promote_show_markers`
8. `MarkerControllerTest::test_get_markers_returns_200`
9. `MarkerControllerTest::test_get_markers_returns_404_when_not_found`
10. `MarkerControllerTest::test_get_intro_marker`
11. `MarkerControllerTest::test_get_outro_marker`
12. `MarkerControllerTest::test_get_show_markers_bulk`

**Coverage target:** `MarkerService` ≥ 85 %, `MarkerController` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Public HTTP API"** → `docs/reference/api.md` updated with marker
  endpoint OpenAPI entries.
- **"New public class/method"** → all new public classes get PHPDoc with
  `@since 0.12.0`.
- **"User-visible behavior change"** → CHANGELOG entry (markers API added;
  player UI in F.4).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `003_marker_columns.sql` migration runs without error.
- [ ] `MarkerService::getMarkers()` returns `MarkerSet` with correct structure.
- [ ] `MarkerService::promoteCandidates()` writes the 4 `_seconds` columns.
- [ ] `MarkerService::promoteShowMarkers()` returns a count of promoted items.
- [ ] `MarkerController::getMarkers()` returns 200 with `{ intro, outro, chapters }`.
- [ ] `MarkerController::getIntroMarker()` returns 200 with `{ start, end, confidence }`.
- [ ] `MarkerController::getOutroMarker()` returns 200 with `{ start, end, confidence }`.
- [ ] `MarkerController::getShowMarkers()` returns bulk array for a show.
- [ ] All 4 routes registered in `Router.php`.
- [ ] `./vendor/bin/phpunit` — green; ≥ 12 new tests.
- [ ] Coverage of `MarkerService` + `MarkerController` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/reference/api.md` updated with marker endpoints.
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
git checkout -b f.3-markers-api

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'MarkerService|MarkerController'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step F.3: Marker storage columns + GET API endpoints"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step F.3: Marker storage columns + GET API (intro/outro/chapters)" \
  --body  "Adds migrations/003_marker_columns.sql, MarkerService, MarkerSet/Marker DTOs, MarkerController with 4 GET endpoints. Stores markers in media_items columns. Part of Phase F (Step F.3 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'f.3-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `f.3-markers-api-review.md`.

Non-obvious points:
- The migration adds `_seconds` columns (nullable INT UNSIGNED) so that
  items without detected markers have NULL rather than 0 (which would be
  a valid timestamp).
- `MarkerService::getMarkers()` checks formal columns first, then falls
  back to `metadata_json` candidates — this means items fingerprinted in
  F.1/F.2 but not yet promoted still return markers via the API.
- Chapter markers (`chapters_json`) are stored as a JSON array in the DB
  but the `ChapterMarker` DTO is provided for type-safe access. Manual
  chapter editing is out of scope for F.3.
