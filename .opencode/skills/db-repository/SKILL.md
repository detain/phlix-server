---
name: db-repository
description: Adds a data-access/repository class in src/ using Workerman\MySQL\Connection with parameterized $db->query('... WHERE id = ?', [$id]), the project's sprintf-based generateUuid() helper, and metadata_json hydration via json_decode. Use when the user says 'add repository', 'add table', 'CRUD', 'query the database', 'data access layer', or touches src/Auth/UserRepository.php, src/Media/Library/ItemRepository.php, or src/Session/SessionManager.php. Do NOT use for PDO, mysqli, raw mysqli_* calls, Eloquent/Doctrine ORMs, or migration file authoring (use migrations/NNN_*.sql directly for schema).
---

# db-repository

Adds a repository / data-access class under `src/{Module}/` that wraps `Workerman\MySQL\Connection`, follows the project's parameterized-query + JSON-hydration patterns, and produces tests under `tests/unit/{Module}/`.

## Critical

- **Only `Workerman\MySQL\Connection`.** `use Workerman\MySQL\Connection;` and type-hint the constructor parameter as `Connection $db`. PDO, `mysqli`, raw `mysqli_*` calls, Doctrine and Eloquent are forbidden in this codebase.
- **Every query must be parameterized.** Use `$db->query("SELECT ... WHERE col = ?", [$value])`. Never interpolate user input into the SQL string. Only static column names from a hard-coded allow-list may be interpolated (see `UserRepository::updateSettings()` `$allowedFields` pattern).
- **`$db->query()` returns `array<int, array<string, mixed>>`** for SELECTs. Single-row lookups read `$result[0] ?? null`. Empty array means no rows.
- **Use the project UUID helper.** Copy `private function generateUuid(): string` verbatim from `src/Auth/UserRepository.php:475-485` — do not pull in `ramsey/uuid` or `random_bytes`-based generators.
- **Tables with a `metadata_json` column MUST hydrate it.** Implement `private function hydrateItem(array $row): array` per `src/Media/Library/ItemRepository.php:510-519` and map all SELECT results through it.
- **`declare(strict_types=1);`** is the first non-`<?php` line in every file. Namespace is `Phlix\{Module}` matching the path under `src/`.

## Instructions

1. **Locate the module and confirm the table exists.**
   - Repositories live at `src/{Module}/{Name}Repository.php` (e.g. `src/Auth/UserRepository.php`, `src/Media/Library/ItemRepository.php`). Match the module of the data you are accessing.
   - Confirm the target table is declared in `migrations/001_initial_schema.sql` or `migrations/002_user_profiles_and_parental_controls.sql`. If not present, stop and tell the user a new migration is required first — do NOT silently create one.
   - **Verify:** `ls src/{Module}/` shows the parent directory exists, and `grep -n "CREATE TABLE.*{table}" migrations/*.sql` returns a row.

2. **Create the file skeleton.** Write `src/{Module}/{Name}Repository.php` with this exact header (use `UserRepository.php:1-43` as the canonical template):
   ```php
   <?php

   declare(strict_types=1);

   namespace Phlix\{Module};

   use Workerman\MySQL\Connection;

   class {Name}Repository
   {
       private Connection $db;

       public function __construct(Connection $db)
       {
           $this->db = $db;
       }
   }
   ```
   - **Verify:** `php -l src/{Module}/{Name}Repository.php` reports `No syntax errors`.

3. **Add finder methods (`findById`, `findBy{Field}`).** One method per UNIQUE/indexed column. Pattern from `UserRepository::findById()` lines 58-62:
   ```php
   public function findById(string $id): ?array
   {
       $result = $this->db->query("SELECT * FROM {table} WHERE id = ?", [$id]);
       return $result[0] ?? null;
   }
   ```
   - If the table has a `metadata_json` column, wrap the return in `$this->hydrateItem($result[0])` instead and return `null` when `empty($result)` — see `ItemRepository::findById()` lines 42-54.
   - **Verify:** every `query()` call has exactly one `?` placeholder per element in the bound-values array.

4. **Add list/pagination methods.** Use `LIMIT ? OFFSET ?` with default `int $limit = 100, int $offset = 0` matching `ItemRepository::getByLibrary()` lines 119-127. Pass limit/offset as the LAST elements of the bind array. Map results through `hydrateItem` with `array_map(fn($r) => $this->hydrateItem($r), $results)`.

5. **Add `create(array $data): string`.** Pattern from `UserRepository::create()` lines 132-155 and `ItemRepository::create()` lines 171-193:
   - Generate the id: `$id = $data['id'] ?? $this->generateUuid();` (use `??` only if external callers may pass a pre-generated id; otherwise just `$this->generateUuid()`).
   - For password fields: `password_hash($data['password'], PASSWORD_ARGON2ID)` — never plain bcrypt, never store plaintext.
   - For `metadata_json` fields: `isset($data['metadata_json']) ? (is_array($data['metadata_json']) ? json_encode($data['metadata_json']) : $data['metadata_json']) : '{}'`.
   - Single `INSERT INTO {table} (col1, col2, ...) VALUES (?, ?, ...)` query with positional binds in column order.
   - Return the generated `$id` string.

6. **Add `update(string $id, array $data): void`.** Build a dynamic SET clause WITHOUT interpolating user keys — see `UserRepository::update()` lines 179-208 for the safe pattern (hard-coded `if (isset($data['display_name']))` branches) OR `ItemRepository::update()` lines 202-225 for the trusting pattern (only acceptable when callers are internal). Always:
   - Bail early with `if (empty($sets)) { return; }`.
   - Append `$id` to `$values` LAST.
   - Build SQL with `"UPDATE {table} SET " . implode(', ', $sets) . " WHERE id = ?"`.
   - If a field is `metadata_json` and the value is an array, `json_encode` it before binding.

7. **Add `delete(string $id): void`.** Single line per `ItemRepository::delete()` line 233-236:
   ```php
   public function delete(string $id): void
   {
       $this->db->query("DELETE FROM {table} WHERE id = ?", [$id]);
   }
   ```

8. **Add private helpers at the bottom of the class.** Copy verbatim from `src/Auth/UserRepository.php:475-485`:
   ```php
   private function generateUuid(): string
   {
       return sprintf(
           '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
           mt_rand(0, 0xffff), mt_rand(0, 0xffff),
           mt_rand(0, 0xffff),
           mt_rand(0, 0x0fff) | 0x4000,
           mt_rand(0, 0x3fff) | 0x8000,
           mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
       );
   }
   ```
   If the table stores `metadata_json`, also copy `hydrateItem()` from `ItemRepository.php:510-519`.

9. **Write the PHPUnit test.** Create `tests/unit/{Module}/{Name}RepositoryTest.php` mirroring `tests/unit/Media/Library/ItemRepositoryTest.php:1-50`:
   ```php
   <?php

   namespace Phlix\Tests\Unit\{Module};

   use PHPUnit\Framework\TestCase;
   use Phlix\{Module}\{Name}Repository;
   use Workerman\MySQL\Connection;

   class {Name}RepositoryTest extends TestCase
   {
       public function testFindByIdReturnsNullWhenNotFound(): void
       {
           $db = $this->createMock(Connection::class);
           $db->method('query')->willReturn([]);
           $repo = new {Name}Repository($db);
           $this->assertNull($repo->findById('non-existent-id'));
       }
   }
   ```
   At minimum cover: constructor instantiation, `findById` not-found returns `null`, `findById` found returns hydrated array.
   - **Verify:** `vendor/bin/phpunit tests/unit/{Module}/{Name}RepositoryTest.php` exits 0.

10. **Wire the repository into its consumer.** Repositories are constructor-injected, never `new`-ed inside business logic. Find the manager or service that should own it (e.g. `AuthManager`, `LibraryManager`, `SessionManager`) and add a `private {Name}Repository $repo;` property + constructor parameter. Do NOT register a DI container — this project uses manual constructor wiring at the composition root.

## Examples

**User says:** "Add a PlaylistRepository for storing user playlists."

**Actions taken:**
1. Confirm `playlists` table exists in `migrations/`. If not, stop and request a migration first.
2. Create `src/Media/Library/PlaylistRepository.php` with namespace `Phlix\Media\Library`, `use Workerman\MySQL\Connection;`, strict types, constructor injecting `Connection $db`.
3. Add `findById(string $id): ?array`, `findByUserId(string $userId): array`, `create(array $data): string` (UUID via `generateUuid()`, `items_json` encoded via `json_encode`), `update(string $id, array $data): void`, `delete(string $id): void`, and a private `hydrateItem()` that decodes `items_json` into `$row['items']`.
4. Copy the `generateUuid()` helper verbatim from `UserRepository.php:475-485`.
5. Create `tests/unit/Media/Library/PlaylistRepositoryTest.php` with the four baseline tests.
6. Run `vendor/bin/phpunit tests/unit/Media/Library/PlaylistRepositoryTest.php` and confirm green.
7. Inject `PlaylistRepository` into `LibraryManager` constructor.

**Result:** A new class identical in shape to `ItemRepository.php`, all queries parameterized, JSON hydrated, tested.

## Common Issues

- **`Error: Call to undefined method ... ::prepare()`** — You used PDO-style code. `Workerman\MySQL\Connection::query($sql, $params)` takes the parameter array as the second argument; there is no separate `prepare/execute` step.

- **`Warning: Undefined array key 0` after a SELECT** — `$db->query()` returns `[]` (not `null`) when no rows match. Use `$result[0] ?? null`, not `$result->fetch()`.

- **`SQLSTATE[42S22]: Column not found` on `metadata`** — The DB column is `metadata_json` (raw JSON text). The `metadata` key is added by `hydrateItem()` after `json_decode`. SELECTs must reference `metadata_json`; callers read the decoded `$row['metadata']`.

- **`Number of bound variables does not match number of tokens`** — Count `?` placeholders in the SQL and elements in the bind array; for dynamic UPDATEs, ensure `$values[] = $id;` is appended AFTER the SET-clause values, not before.

- **`Class "Ramsey\Uuid\Uuid" not found`** — You pulled in an external UUID library. Use the in-class `generateUuid()` helper instead; the project does not depend on `ramsey/uuid`.

- **PHPUnit test fails with `Cannot instantiate interface Workerman\MySQL\Connection`** — Use `$this->createMock(Connection::class)` and stub `query()` with `->method('query')->willReturn([...])`. Do not try to construct a real `Connection` in unit tests.

- **`JSON_EXTRACT` returns NULL for known-good metadata** — `metadata_json` defaults to `'{}'` for new rows. If you see literal `NULL`, an INSERT bypassed `create()` and skipped the default. Backfill with `UPDATE {table} SET metadata_json = '{}' WHERE metadata_json IS NULL` and check the offending INSERT path.