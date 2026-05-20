# Step H.2 — Collections (manual + rule-based)

**Phase:** H (Smart Features)
**Step:** H.2
**Depends on:** H.1
**Review:** Yes — see `h.2-collections-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement collections: named groups of media items that a curator
manually assembles (bulk-add from search) or that derive from a saved
smart-playlist rule. Collections appear alongside libraries in the UI;
they have a defined sort order and can be nested. This is the feature
that lets users create "Oscar Winners 2020–2024", "Kids' Favourites",
or "Friday Night Movies" without touching file naming conventions.

## 2. Context (what already exists)

- `src/Playlists/SmartPlaylistEngine.php` (H.1) — rule DSL evaluator.
- `src/Playlists/SmartPlaylistRepository.php` (H.1) — CRUD for rules.
- `src/Media/Library/ItemRepository.php` — media item reads.
- `src/Media/Library/LibraryManager.php` — library scan.
- `src/Server/Http/Router.php` — existing route registration pattern.
- `src/Common/Events/ListenerRegistry.php` (A.2).
- `PHLEX_EXPANSION_PLAN.md` §1 — "Smart playlists, collections" is
  **Missing**; H.1 just added the smart-playlist engine.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase H table — H.2 is the collections
  step; H.3 (themes) does not depend on it.

Existing patterns to follow:

- `migrations/001_initial_schema.sql` — schema format; PKs `CHAR(36)`,
  UUID via local `generateUuid()` helper.
- `Workerman\MySQL\Connection::query("... ?", [$id])` — always
  parameterized.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Collections/CollectionManager.php` — orchestrator:

  ```php
  class CollectionManager
  {
      public function __construct(
          private readonly CollectionRepository $repo,
          private readonly SmartPlaylistEngine $engine,
          private readonly ItemRepository $items,
      ) {}

      public function create(Collection $collection): void {}
      public function update(Collection $collection): void {}
      public function delete(string $id): void {}
      public function addItem(string $collectionId, string $mediaItemId): void {}
      public function removeItem(string $collectionId, string $mediaItemId): void {}
      public function bulkAddFromSearch(string $collectionId, array $mediaItemIds): void {}
      public function getCollectionWithItems(string $id): ?CollectionWithItems {}
      public function getCollectionsForLibrary(int $libraryId): array {}
      public function refreshSmartCollection(string $id): void {}
      // Re-evaluates the underlying smart playlist and syncs collection items
  }
  ```

- `src/Collections/Collection.php` — readonly entity:

  ```php
  class Collection
  {
      public function __construct(
          public readonly string $id,
          public readonly string $name,
          public readonly int $libraryId,
          public readonly ?string $smartPlaylistId, // null = manual
          public readonly ?string $parentId,          // null = top-level
          public readonly int $sortOrder,
          public readonly \DateTimeImmutable $createdAt,
          public readonly \DateTimeImmutable $updatedAt,
      ) {}
  }
  ```

- `src/Collections/CollectionWithItems.php` — hydrated DTO:

  ```php
  class CollectionWithItems
  {
      public function __construct(
          public readonly Collection $collection,
          public readonly array $items, // MediaItem[]
          public readonly int $total,
      ) {}
  }
  ```

- `src/Collections/CollectionRepository.php` — CRUD:

  ```php
  class CollectionRepository
  {
      public function __construct(private readonly Connection $db) {}

      public function insert(Collection $c): void {}
      public function update(Collection $c): void {}
      public function delete(string $id): void {}
      public function findById(string $id): ?Collection {}
      public function findByLibraryId(int $libraryId): array {}
      public function findAll(): array {}
      public function findByParentId(?string $parentId): array {}
  }
  ```

- `src/Collections/CollectionItemRepository.php` — membership CRUD:

  ```php
  class CollectionItemRepository
  {
      public function __construct(private readonly Connection $db) {}

      public function insert(string $collectionId, string $mediaItemId, int $sortOrder): void {}
      public function delete(string $collectionId, string $mediaItemId): void {}
      public function deleteAllForCollection(string $collectionId): void {}
      public function findMediaItemIdsForCollection(string $collectionId): array {}
      public function countForCollection(string $collectionId): int {}
  }
  ```

- `src/Server/Http/Controllers/CollectionController.php` — JSON API:

  ```
  GET    /api/v1/collections               list all
  POST   /api/v1/collections               create
  GET    /api/v1/collections/{id}          get one with items
  PUT    /api/v1/collections/{id}          update
  DELETE /api/v1/collections/{id}           delete
  POST   /api/v1/collections/{id}/items/{mediaItemId}    add item
  DELETE /api/v1/collections/{id}/items/{mediaItemId}    remove item
  POST   /api/v1/collections/{id}/bulk-add             bulk-add from search
  POST   /api/v1/collections/{id}/refresh               re-evaluate smart collection
  GET    /api/v1/libraries/{libraryId}/collections      collections for library
  ```

- `migrations/005_collections.sql` — new tables:

  ```sql
  CREATE TABLE collections (
      id CHAR(36) NOT NULL PRIMARY KEY,
      name VARCHAR(128) NOT NULL,
      library_id CHAR(36) NOT NULL,
      smart_playlist_id CHAR(36) NULL,
      parent_id CHAR(36) NULL,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      INDEX idx_col_library (library_id),
      INDEX idx_col_smart_pl (smart_playlist_id),
      INDEX idx_col_parent (parent_id)
  ) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE collection_items (
      collection_id CHAR(36) NOT NULL,
      media_item_id CHAR(36) NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      added_at DATETIME NOT NULL,
      PRIMARY KEY (collection_id, media_item_id),
      INDEX idx_ci_media (media_item_id)
  ) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```

- `tests/Unit/Collections/CollectionManagerTest.php`
- `tests/Unit/Collections/CollectionRepositoryTest.php`
- `tests/Unit/Collections/CollectionItemRepositoryTest.php`
- `tests/Integration/Collections/CollectionCrudTest.php`

#### Documentation

- `docs/developers/collections.md` — collection model, API endpoints,
  how smart collections sync with their rule engine, how to extend
  collection types.

### Modify

- `src/Server/Http/Router.php` — register `CollectionController` routes.
- `src/Playlists/SmartPlaylistRefreshHandler.php` (H.1) — after
  re-evaluating a smart playlist, call
  `CollectionManager::refreshSmartCollection()` for any collection
  that references it as `smartPlaylistId`.
- `CHANGELOG.md` — `Added: collections (manual + rule-based) (H.2).
  Curators can bulk-add from search; smart collections auto-sync from
  saved rules.`

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b h.2-collections`.
2. **Schema.** Write `migrations/005_collections.sql` with two tables:
   `collections` (one row per collection) and `collection_items`
   (many-to-many with sort order).
3. **Entities + Repos.** `Collection`, `CollectionWithItems`,
   `CollectionRepository`, `CollectionItemRepository` — pure data + DB
   access, no business logic.
4. **CollectionManager.** `addItem()` / `removeItem()` / `bulkAddFromSearch()`
   — `bulkAddFromSearch()` accepts an array of pre-resolved
   `mediaItemIds` (the search UI already resolved them before calling
   the API).
5. **Smart sync.** `refreshSmartCollection($id)` — loads the collection's
   `smartPlaylistId`, fetches the `SmartPlaylist`, calls
   `SmartPlaylistEngine::evaluateOnScan()`, then diffs the result against
   current `collection_items` and adds/removes accordingly.
6. **Controller.** REST endpoints per §3.
7. **H.1 integration.** Wire `SmartPlaylistRefreshHandler` to call
   `CollectionManager::refreshSmartCollection()` when the underlying
   smart playlist changes.
8. **Tests.** Unit + integration per §5.
9. **Verification bar** (§0.4 minimum bar).
10. **Docs.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

`CollectionRepositoryTest`:
1. `test_insert_then_find_returns_same_row`
2. `test_update_modifies_row`
3. `test_delete_removes_row`
4. `test_find_by_library_id_returns_matching`
5. `test_find_by_parent_id_returns_matching`

`CollectionItemRepositoryTest`:
6. `test_insert_then_find_media_ids_returns_same`
7. `test_delete_removes_row`
8. `test_delete_all_for_collection`
9. `test_count_for_collection`

`CollectionManagerTest`:
10. `test_add_item_inserts_to_repository`
11. `test_remove_item_deletes_from_repository`
12. `test_bulk_add_from_search_calls_repo_multiple_times`
13. `test_get_collection_with_items_hydrates_items`
14. `test_refresh_smart_collection_evaluates_engine_and_syncs`

**Integration test** (`CollectionCrudTest`):
15. `test_full_lifecycle` — create collection, add items, bulk-add,
    refresh (smart), verify items, delete.

**Coverage target:** `CollectionManager` ≥ 85 %,
`CollectionRepository` ≥ 85 %, `CollectionItemRepository` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Public HTTP/WS API"** → `docs/reference/api/` adds collection
  endpoints.
- **"Anything"** → `docs/developers/collections.md` (new) covers model,
  API, smart sync algorithm.
- **"User-visible behavior change"** → CHANGELOG entry.
- **"New public class/method"** → PHPDoc with `@since 0.14.0`.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `migrations/005_collections.sql` runs cleanly.
- [ ] `Collection` entity with all fields.
- [ ] `CollectionWithItems` DTO with hydrated items.
- [ ] `CollectionRepository` full CRUD with parameterized queries.
- [ ] `CollectionItemRepository` membership CRUD with parameterized queries.
- [ ] `CollectionManager::addItem()` / `removeItem()` working.
- [ ] `CollectionManager::bulkAddFromSearch()` accepts array of IDs.
- [ ] `CollectionManager::refreshSmartCollection()` re-evaluates engine
      and syncs diff.
- [ ] All 9 REST endpoints wired in `CollectionController`.
- [ ] `SmartPlaylistRefreshHandler` calls
      `CollectionManager::refreshSmartCollection()`.
- [ ] `./vendor/bin/phpunit` — green; ≥ 15 new tests.
- [ ] Coverage of `CollectionManager` ≥ 85 %,
      `CollectionRepository` ≥ 85 %, `CollectionItemRepository` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/collections.md` written.
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
git checkout -b h.2-collections

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'CollectionManager|CollectionRepository|CollectionItemRepository'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step H.2: collections (manual + rule-based)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step H.2: collections (manual + rule-based)" \
  --body  "Adds Collection, CollectionWithItems, CollectionManager, CollectionRepository, CollectionItemRepository, CollectionController, and migration 005_collections.sql. Collections support manual curation (bulk-add from search) and smart collections that auto-sync from saved playlist rules. Part of Phase H (Step H.2 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'h.2-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `h.2-collections-review.md`.

Non-obvious points:
- `bulkAddFromSearch()` intentionally takes pre-resolved `mediaItemIds`
  because the search/resolve UX lives in the client; the server trusts
  the client to have already validated those IDs.
- Smart collection sync is a diff (add net-new items, remove items
  that no longer match); it never wipes and rebuilds to avoid
  disrupting curator custom sort orders on retained items.
- `parentId` is nullable — null means top-level collection; the UI
  renders a tree from this structure.
