# Step H.1 — Smart-playlist rule engine

**Phase:** H (Smart Features)
**Step:** H.1
**Depends on:** A.4
**Review:** Yes — see `h.1-smart-playlists-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement a smart-playlist rule engine: a JSON-rule-DSL that evaluates
media items at scan time and on every folder-watch event, and a Builder UI
in the WebPortal that lets curators compose rules without writing JSON.
Smart-playlists live alongside regular (manually curated) playlists;
they auto-update as the library changes.

## 2. Context (what already exists)

- `src/Media/Library/LibraryManager.php` — library scan orchestration.
- `src/Media/Library/ItemRepository.php` — media item CRUD; hydrates
  `metadata_json`.
- `src/Media/Library/MediaScanner.php` — parses `S01E02`, `(2020)` from
  filenames.
- `src/Media/Library/FolderWatcher.php` — mtime-based checksum; fires
  `LibraryUpdated` events on change.
- `src/Plugins/PluginLoader.php` (A.4) — install/enable/disable/uninstall.
- `src/Common/Events/ListenerRegistry.php` (A.2) — event subscription.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Smart playlists, collections" is
  **Missing**.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase H table — H.1 is the first
  smart-playlist step; H.2 (collections) depends on it.

Existing patterns to follow:

- `migrations/001_initial_schema.sql` — schema format; PKs `CHAR(36)`,
  UUID via local `generateUuid()` helper.
- `Workerman\MySQL\Connection::query("... ?", [$id])` — always
  parameterized.
- JSON DSL for rules in `config/` and DB columns — same approach used
  by plugin manifest `events` field.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Playlists/SmartPlaylistEngine.php` — core rule evaluator:

  ```php
  class SmartPlaylistEngine
  {
      public function evaluate(array $rules, array $mediaItems): array {}
      // $rules = decoded JSON DSL; returns filtered + sorted $mediaItems

      public function evaluateOnScan(array $rules, int $libraryId): array {}
      // Fetches all items for $libraryId, evaluates, returns matches

      public function buildFromDsl(array $dsl): RuleNode {}
      // Parses JSON DSL into an immutable RuleNode tree

      public function toJson(RuleNode $root): string {}
      // Serialises a RuleNode tree back to JSON DSL
  }
  ```

- `src/Playlists/RuleNode.php` — immutable AST node for a rule or
  rule-group:

  ```php
  class RuleNode
  {
      public const TYPE_AND = 'and';
      public const TYPE_OR  = 'or';
      public const TYPE_NOT  = 'not';
      public const TYPE_RULE = 'rule';

      public function __construct(
          public readonly string $type,    // TYPE_AND | TYPE_OR | TYPE_NOT | TYPE_RULE
          public readonly ?string $field,  // e.g. 'genre', 'year', 'rating'
          public readonly ?string $operator, // 'equals','contains','gt','lt','between','in'
          public readonly mixed $value,    // string|int|array depending on operator
          public readonly array $children = [], // RuleNode[] for AND/OR/NOT
      ) {}
  }
  ```

- `src/Playlists/RuleOperators.php` — operator implementations:

  ```php
  class RuleOperators
  {
      public static function equals(mixed $itemValue, mixed $ruleValue): bool {}
      public static function notEquals(mixed $itemValue, mixed $ruleValue): bool {}
      public static function contains(string $itemValue, string $ruleValue): bool {}
      public static function notContains(string $itemValue, string $ruleValue): bool {}
      public static function greaterThan(int|float $itemValue, int|float $ruleValue): bool {}
      public static function lessThan(int|float $itemValue, int|float $ruleValue): bool {}
      public static function between(int|float $itemValue, int|float $lo, int|float $hi): bool {}
      public static function in(mixed $itemValue, array $ruleValues): bool {}
      public static function notIn(mixed $itemValue, array $ruleValues): bool {}
      public static function startsWith(string $itemValue, string $ruleValue): bool {}
      public static function endsWith(string $itemValue, string $ruleValue): bool {}
  }
  ```

- `src/Playlists/SmartPlaylistRepository.php` — CRUD for smart playlists:

  ```php
  class SmartPlaylistRepository
  {
      public function __construct(private readonly Connection $db) {}

      public function insert(SmartPlaylist $playlist): void {}
      public function update(SmartPlaylist $playlist): void {}
      public function delete(string $id): void {}
      public function findById(string $id): ?SmartPlaylist {}
      public function findByLibraryId(int $libraryId): array {}
      public function findAll(): array {}
  }
  ```

- `src/Playlists/SmartPlaylist.php` — readonly entity:

  ```php
  class SmartPlaylist
  {
      public function __construct(
          public readonly string $id,
          public readonly string $name,
          public readonly int $libraryId,
          public readonly string $rulesJson,   // JSON DSL
          public readonly int $limit,          // max items, 0 = unlimited
          public readonly string $sortBy,     // e.g. 'addedAt', 'random'
          public readonly bool $sortDesc,
          public readonly \DateTimeImmutable $createdAt,
          public readonly \DateTimeImmutable $updatedAt,
      ) {}
  }
  ```

- `src/Playlists/SmartPlaylistRefreshHandler.php` — listens to
  `LibraryUpdated` (A.2) and re-evaluates all smart-playlists for the
  changed library:

  ```php
  class SmartPlaylistRefreshHandler
  {
      public function __construct(
          private readonly SmartPlaylistEngine $engine,
          private readonly SmartPlaylistRepository $repo,
          private readonly ListenerRegistry $listeners,
      ) {}

      public function onLibraryUpdated(LibraryUpdated $event): void {}
  }
  ```

- `src/Server/Http/Controllers/SmartPlaylistController.php` — JSON API:

  ```
  GET    /api/v1/smart-playlists           list all
  POST   /api/v1/smart-playlists           create
  GET    /api/v1/smart-playlists/{id}      get one
  PUT    /api/v1/smart-playlists/{id}      update
  DELETE /api/v1/smart-playlists/{id}     delete
  POST   /api/v1/smart-playlists/{id}/preview   evaluate without saving
  ```

- `migrations/004_smart_playlists.sql` — new table:

  ```sql
  CREATE TABLE smart_playlists (
      id CHAR(36) NOT NULL PRIMARY KEY,
      name VARCHAR(128) NOT NULL,
      library_id CHAR(36) NOT NULL,
      rules_json JSON NOT NULL,
      `limit` INT NOT NULL DEFAULT 0,
      sort_by VARCHAR(32) NOT NULL DEFAULT 'addedAt',
      sort_desc TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      INDEX idx_smart_pl_library (library_id)
  ) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```

- `tests/Unit/Playlists/SmartPlaylistEngineTest.php`
- `tests/Unit/Playlists/RuleOperatorsTest.php`
- `tests/Unit/Playlists/RuleNodeTest.php`
- `tests/Unit/Playlists/SmartPlaylistRepositoryTest.php`
- `tests/Integration/Playlists/SmartPlaylistRefreshTest.php`

#### Documentation

- `docs/developers/smart-playlists.md` — DSL reference (every field,
  operator, and example), rule evaluation algorithm, how to add new
  operators.

### Modify

- `src/Media/Library/FolderWatcher.php` — dispatch
  `LibraryUpdated` event after processing changes (A.2 already wired).
- `src/Server/Http/Router.php` — register
  `SmartPlaylistController` routes.
- `src/Common/Events/ListenerRegistry.php` (A.2) — register
  `SmartPlaylistRefreshHandler` on `LibraryUpdated`.
- `composer.json` — no new runtime dependencies.
- `CHANGELOG.md` — `Added: smart-playlist rule engine (H.1). JSON DSL
  evaluates at scan time; re-evaluates on folder-watch events. Builder
  UI in WebPortal.`

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b h.1-smart-playlists`.
2. **RuleNode AST.** Implement `RuleNode` and `RuleOperators` first;
   pure functions, easy to unit-test exhaustively.
3. **Engine.** `SmartPlaylistEngine::buildFromDsl()` parses JSON into a
   `RuleNode` tree. `evaluate()` walks the tree against each
   `$mediaItem` (fetched from `ItemRepository`). Supports AND / OR /
   NOT groups nested arbitrarily deep.
4. **Repository.** Full CRUD for `smart_playlists` table via
   `SmartPlaylistRepository`.
5. **Refresh handler.** `SmartPlaylistRefreshHandler` subscribes to
   `LibraryUpdated`; on each event it fetches all smart-playlists for
   that library, re-evaluates them, and could emit a
   `SmartPlaylistChanged` event (future use by H.2).
6. **Controller.** REST endpoints for CRUD plus a `/preview` endpoint
   that evaluates rules without persisting — used by the Builder UI.
7. **Tests.** Unit + integration per §5.
8. **Verification bar** (§0.4 minimum bar).
9. **Docs.**
10. **Commit + PR + merge.**

**JSON DSL shape:**

```json
{
  "logic": "and",
  "rules": [
    { "field": "genre", "op": "contains", "value": "Drama" },
    { "field": "year", "op": "gt", "value": 2010 },
    {
      "logic": "or",
      "rules": [
        { "field": "rating", "op": "gte", "value": 8.0 },
        { "field": "criticScore", "op": "gte", "value": 85 }
      ]
    }
  ]
}
```

Field names map to `metadata_json` keys; `ItemRepository` is
responsible for extracting them.

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

`RuleNodeTest`:
1. `test_constructor_stores_all_properties`
2. `test_type_constants_are_correct_strings`

`RuleOperatorsTest`:
3. `test_equals_true_when_values_match`
4. `test_equals_false_when_values_differ`
5. `test_notEquals_true_when_values_differ`
6. `test_contains_true_when_substring_present`
7. `test_contains_false_when_substring_absent`
8. `test_greaterThan_true_when_greater`
9. `test_lessThan_true_when_less`
10. `test_between_true_when_value_in_range`
11. `test_between_false_when_value_outside_range`
12. `test_in_true_when_value_in_array`
13. `test_notIn_false_when_value_in_array`
14. `test_startsWith_true_when_prefix_matches`
15. `test_endsWith_true_when_suffix_matches`

`SmartPlaylistEngineTest`:
16. `test_build_from_dsl_creates_rule_node_tree`
17. `test_evaluate_and_rule_requires_all_conditions`
18. `test_evaluate_or_rule_requires_one_condition`
19. `test_evaluate_not_rule_inverts_condition`
20. `test_evaluate_nested_groups`
21. `test_evaluate_empty_rules_returns_all_items`
22. `test_evaluate_with_limit`
23. `test_evaluate_sort_by_random`
24. `test_to_json_round_trip`

`SmartPlaylistRepositoryTest`:
25. `test_insert_then_find_returns_same_row`
26. `test_update_modifies_row`
27. `test_delete_removes_row`
28. `test_find_by_library_id_returns_matching`

**Integration test** (`SmartPlaylistRefreshTest`):
29. `test_on_library_updated_re_evaluates_smart_playlists` — creates a
    smart playlist, adds media items, simulates folder-watch event,
    asserts items are correctly matched.

**Coverage target:** `SmartPlaylistEngine` ≥ 85 %, `RuleNode` ≥ 85 %,
`RuleOperators` ≥ 85 %, `SmartPlaylistRepository` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Public HTTP/WS API"** → `docs/reference/api/` adds
  `/api/v1/smart-playlists` endpoints.
- **"Anything"** → `docs/developers/smart-playlists.md` (new) covers DSL
  reference, operator list, evaluation algorithm, extension guide.
- **"User-visible behavior change"** → CHANGELOG entry.
- **"New public class/method"** → PHPDoc with `@since 0.14.0`.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `RuleNode` AST node implemented with TYPE_AND/OR/NOT/RULE.
- [ ] `RuleOperators` covers all 11 operators.
- [ ] `SmartPlaylistEngine::buildFromDsl()` parses JSON DSL correctly.
- [ ] `SmartPlaylistEngine::evaluate()` applies AND/OR/NOT logic
      correctly; handles nested groups.
- [ ] `SmartPlaylistEngine::toJson()` round-trips `RuleNode` → JSON.
- [ ] `SmartPlaylistRepository` full CRUD with parameterized queries.
- [ ] `migrations/004_smart_playlists.sql` runs cleanly.
- [ ] `SmartPlaylistRefreshHandler` subscribes to `LibraryUpdated`.
- [ ] `GET/POST/PUT/DELETE /api/v1/smart-playlists` endpoints wired.
- [ ] `POST /api/v1/smart-playlists/{id}/preview` evaluates without saving.
- [ ] `./vendor/bin/phpunit` — green; ≥ 29 new tests.
- [ ] Coverage of `SmartPlaylistEngine` ≥ 85 %, `RuleNode` ≥ 85 %,
      `RuleOperators` ≥ 85 %, `SmartPlaylistRepository` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/smart-playlists.md` written with DSL reference.
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
git checkout -b h.1-smart-playlists

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'SmartPlaylistEngine|RuleNode|RuleOperators|SmartPlaylistRepository'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step H.1: smart-playlist rule engine with JSON DSL"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step H.1: smart-playlist rule engine" \
  --body  "Adds SmartPlaylistEngine, RuleNode, RuleOperators, SmartPlaylistRepository, SmartPlaylistRefreshHandler, SmartPlaylistController, and migration 004_smart_playlists.sql. Implements JSON DSL rule evaluation at scan time and on folder-watch events. Part of Phase H (Step H.1 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'h.1-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `h.1-smart-playlists-review.md`.

Non-obvious points:
- The JSON DSL intentionally mirrors Plex's/Emby's smart playlist rule
  structure for familiarity.
- `evaluateOnScan()` is called by the refresh handler; it batches DB
  reads to avoid N+1 queries when a library has many smart playlists.
- New operators are added to `RuleOperators` only; `RuleNode` and the
  engine are untouched — open/closed principle.
