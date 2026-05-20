# Step C.9 — Hub: shared libraries (friends/family)

**Phase:** C (Server↔Hub Pairing & Remote Access)
**Step:** C.9
**Depends on:** C.8
**Review:** Yes — see `c.9-shared-libraries-review.md`
**Target repo:** `detain/phlex-hub` (local: `/home/sites/phlex-hub/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Allow a user (owner) to share specific libraries on their server with
other hub users (collaborators). Collaborators see shared libraries in
their hub dashboard alongside their own servers.

**Sharing model:**
- Owner shares a specific **library** (not the entire server)
- Sharing is per-library, not per-server
- Collaborator sees the library in "Shared with me" on the hub dashboard
- Collaborator can browse and play content from the shared library via
  their hub credentials (no separate server login required)
- Owner can revoke sharing at any time
- Both server and hub must agree on what's shared

## 2. Context (what already exists)

- After C.4: hub dashboard shows "My Servers" with `ServerInfoDto`
  including library counts
- After C.5: hub issues user-session JWTs that work against servers
- After C.6: relay tunnel available for remote library access
- After C.8: servers have unique subdomains for direct access
- B.6 schema: `shared_libraries` table already defined in hub schema:
  `shared_libraries (id, owner_user_id, collaborator_user_id, server_id,
  library_id, permission_level, created_at)`
- `PHLEX_EXPANSION_PLAN.md` §1 — current-state: "Hub: shared libraries"
  is **Missing**

## 3. Scope — files to create / modify

All paths inside `/home/sites/phlex-hub/`.

### Create

#### DB Schema

The `shared_libraries` table should have been created in B.6. Verify
its existence and add any missing columns:

- `migrations/008_shared_libraries_permissions.sql` (if not already in B.6):

  ```sql
  ALTER TABLE shared_libraries
    ADD COLUMN permission_level ENUM('read', 'readwrite') NOT NULL DEFAULT 'read',
    ADD COLUMN granted_by       CHAR(36) NOT NULL,
    ADD COLUMN expires_at        INT UNSIGNED NULL,
    ADD INDEX idx_owner (owner_user_id),
    ADD INDEX idx_collaborator (collaborator_user_id);

  CREATE TABLE library_shares (
      id               CHAR(36) PRIMARY KEY,
      owner_user_id    CHAR(36) NOT NULL,
      collaborator_user_id CHAR(36) NOT NULL,
      server_id        CHAR(36) NOT NULL,
      library_id       VARCHAR(255) NOT NULL,   -- server's library UUID
      permission_level ENUM('read', 'readwrite') NOT NULL DEFAULT 'read',
      granted_by       CHAR(36) NOT NULL,
      created_at       INT UNSIGNED NOT NULL,
      expires_at        INT UNSIGNED NULL,
      FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (collaborator_user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
      UNIQUE KEY uk_share (owner_user_id, collaborator_user_id, library_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```

#### Share Request DTO

- `src/Hub/LibraryShare.php` — DTO representing a library share:

  ```php
  final class LibraryShare
  {
      public const PERMISSION_READ    = 'read';
      public const PERMISSION_READWRITE = 'readwrite';

      public function __construct(
          public readonly string $id,
          public readonly string $ownerUserId,
          public readonly string $collaboratorUserId,
          public readonly string $serverId,
          public readonly string $libraryId,
          public readonly string $libraryName,
          public readonly string $permissionLevel,
          public readonly int $createdAt,
          public readonly ?int $expiresAt,
      ) { }

      public function isExpired(): bool { }
      public function canWrite(): bool { }
  }
  ```

#### Library Sharing Handler

- `src/Hub/LibrarySharingHandler.php` — business logic for sharing:

  ```php
  final class LibrarySharingHandler
  {
      public function __construct(
          Connection $db,
          LoggerInterface $logger,
          JwtHandler $jwtHandler,
      ) { }

      public function shareLibrary(
          string $ownerId,
          string $collaboratorEmail,
          string $serverId,
          string $libraryId,
          string $permission = 'read',
          ?int $expiresAt = null,
      ): LibraryShare { }

      public function revokeShare(string $ownerId, string $shareId): void { }

      public function getSharesForOwner(string $ownerId): array<LibraryShare> { }

      public function getSharedWithMe(string $userId): array<SharedLibraryDto> { }
  }
  ```

  `shareLibrary`:
  1. Looks up `collaboratorEmail` → `collaboratorUserId`
  2. Verifies `$ownerId` owns the `$serverId` (via `servers.user_id`)
  3. Verifies the library exists on the server (via heartbeat data or
     cached `servers.libraries`)
  4. Inserts `library_shares` row
  5. Returns `LibraryShare`

  `getSharedWithMe`:
  1. Queries `library_shares` WHERE `collaborator_user_id = ?`
  2. Joins to `servers` to get server name and `hostname_candidates`
  3. Returns `SharedLibraryDto` list

- `src/Hub/SharedLibraryDto.php` — DTO for what a collaborator sees:

  ```php
  final class SharedLibraryDto
  {
      public function __construct(
          public readonly string $shareId,
          public readonly string $ownerUserId,
          public readonly string $ownerName,
          public readonly string $serverId,
          public readonly string $serverName,
          public readonly string $libraryId,
          public readonly string $libraryName,
          public readonly string $libraryItemCount,
          public readonly string $permissionLevel,
          public readonly array $accessUrls,  // best direct + relay URL
          public readonly ?int $expiresAt,
      ) { }
  }
  ```

#### API Controllers

- `src/Server/Http/Controllers/LibraryShareController.php`:

  ```
  POST   /api/v1/me/shares
  Authorization: Bearer <user-session-jwt>
  Body: { "collaborator_email": "friend@example.com", "server_id": "...",
           "library_id": "...", "permission": "read" }
  Response: LibraryShare

  GET    /api/v1/me/shares
  Authorization: Bearer <user-session-jwt>
  Response: { "outgoing": [...LibraryShare], "incoming": [...SharedLibraryDto] }

  DELETE /api/v1/me/shares/{id}
  Authorization: Bearer <user-session-jwt>
  Response: 204 No Content
  ```

#### Smarty Pages

- `public/templates/home/shared-with-me.tpl` — collaborator's view:

  ```smarty
  {extends file="layouts/base.tpl"}

  {block name="content"}
  <div class="shared-libraries">
    <h1>Shared With Me</h1>
    {foreach $sharedLibraries as $lib}
      <div class="shared-library-card">
        <h2>{$lib.libraryName|escape:'html'} from {$lib.ownerName|escape:'html'}</h2>
        <p>On server: {$lib.serverName|escape:'html'}</p>
        <a href="/browse/{$lib.serverId}/{$lib.libraryId}">Browse Library</a>
      </div>
    {foreachelse}
      <p>No libraries shared with you yet.</p>
    {/foreach}
  </div>
  {/block}
  ```

- `public/templates/home/manage-shares.tpl` — owner's manage page:

  ```smarty
  {extends file="layouts/base.tpl"}

  {block name="content"}
  <div class="manage-shares">
    <h1>Manage Library Shares</h1>
    <!-- List of outgoing shares with revoke buttons -->
  </div>
  {/block}
  ```

#### Unit Tests

- `tests/Unit/Hub/LibraryShareTest.php` — DTO smoke tests
- `tests/Unit/Hub/LibrarySharingHandlerTest.php`:
  - share with valid email
  - share with nonexistent email → error
  - share library user doesn't own → error
  - revoke share
  - getSharesForOwner
  - getSharedWithMe (empty + populated)
- `tests/Unit/Hub/SharedLibraryDtoTest.php` — DTO smoke tests
- `tests/Unit/Server/Http/Controllers/LibraryShareControllerTest.php`

#### Documentation

- `docs/hub/shared-with-friends.md` — end-user guide for library sharing
- `docs/hub-admin/` — add to admin guide if permissions model requires
  admin configuration

### Modify

- `src/Server/Http/Router.php` — add routes:

  ```php
  $router->get ('/shared-with-me',    PageController::class);   // shared-with-me.tpl
  $router->get ('/manage-shares',     PageController::class);   // manage-shares.tpl

  $router->group('/api/v1/me/shares', function ($r) {
      $r->post  ('/',        LibraryShareController::class);
      $r->get   ('/',        LibraryShareController::class);
      $r->delete('/{id}',    LibraryShareController::class);
  }, [AuthMiddleware::class]);
  ```

- `src/Common/Container/Providers/HubServicesProvider.php` — register
  `LibrarySharingHandler`, `LibraryShare`, `SharedLibraryDto`

- `CHANGELOG.md` entry

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex-hub`.
2. **Branch:** `git checkout -b c.9-shared-libraries`.
3. **Verify schema** — check `migrations/008_shared_libraries_permissions.sql`
   or B.6 schema for `library_shares`.
4. **Write DTOs** — `LibraryShare`, `SharedLibraryDto`.
5. **Write `LibrarySharingHandler`** — core sharing logic.
6. **Write `LibraryShareController`** — API endpoints.
7. **Wire routes.**
8. **Write Smarty pages** for "Shared with me" and "Manage shares".
9. **Write tests.**
10. **Verification bar.**
11. **Doc updates.**
12. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `LibrarySharingHandlerTest::test_shareLibrary_creates_share`
2. `LibrarySharingHandlerTest::test_shareLibrary_nonexistent_email_throws`
3. `LibrarySharingHandlerTest::test_shareLibrary_not_owner_throws`
4. `LibrarySharingHandlerTest::test_revokeShare_deletes_row`
5. `LibrarySharingHandlerTest::test_getSharesForOwner_returns_outgoing_shares`
6. `LibrarySharingHandlerTest::test_getSharedWithMe_returns_incoming_shares`
7. `LibrarySharingHandlerTest::test_getSharedWithMe_empty`
8. `LibraryShareTest::test_isExpired_true`
9. `LibraryShareTest::test_canWrite`
10. `SharedLibraryDtoTest::test_smoke`
11. `LibraryShareControllerTest::test_post_creates_share`
12. `LibraryShareControllerTest::test_get_returns_both_lists`
13. `LibraryShareControllerTest::test_delete_revokes_share`

**Coverage target:** `src/Hub/LibrarySharingHandler` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Hub functionality** → `docs/hub/shared-with-friends.md` (new)
- **User-visible behavior change** → CHANGELOG entry

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] Owner can share a library with another user by email
- [ ] `GET /api/v1/me/shares` returns both outgoing (owned) and incoming
      (shared) shares
- [ ] Collaborator sees shared libraries in "Shared with me" page
- [ ] Owner can revoke a share
- [ ] Expired shares are not returned by `getSharedWithMe()`
- [ ] Share is unique per (owner, collaborator, library) tuple
- [ ] Non-owner cannot share a library they don't own
- [ ] `./vendor/bin/phpunit` — green; ≥ 13 new tests
- [ ] Coverage of `LibrarySharingHandler` ≥ 85 %
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean
- [ ] `docs/hub/shared-with-friends.md` created
- [ ] CHANGELOG entry added
- [ ] Git ritual §8 executed; postcondition checks PASS

## 8. Git ritual (copy of master plan §11.4, targeting hub repo)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex-hub
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b c.9-shared-libraries

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Library|Share'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step C.9: hub library sharing (friends/family)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step C.9: hub library sharing for friends and family" \
  --body  "Implements library sharing: share a library with another user by email, manage shares, revoke, and view shared-with-me dashboard. Part of Phase C (Step C.9 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'c.9-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `c.9-shared-libraries-review.md`.

Non-obvious point: The hub **does not** push library content directly —
it only brokers access. The collaborator's client accesses the **server**
directly (via LAN or relay) using hub-issued JWTs. The hub's role is
purely authorization: it issues JWTs that include `server_id` and the
server validates that the user is authorized for that specific library.
