# Step D.5 — Invite-link sharing

**Phase:** D (Hub-grade Auth: SSO / OIDC / LDAP / Passkeys)
**Step:** D.5
**Depends on:** C.9
**Review:** Yes — see `d.5-invite-links-review.md`
**Target repos:**
  - Hub-side: `detain/phlex-hub` (local: `/home/sites/phlex-hub/`)
  - Server-side: `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Allow library owners to generate single-use invite links they can share
with friends/family. A recipient clicks the link, logs into (or creates)
a hub account, and is immediately granted read access to the specified
library — no manual email-based sharing required. Invite links are
signed JWTs issued by the hub, optionally scoped to a specific library
and with an expiry (default: 7 days).

## 2. Context (what already exists)

- After C.9: library sharing exists (`library_shares` table in hub DB,
  `LibrarySharingHandler` in hub). Invite links extend this with
  a tokenized URL instead of entering the collaborator's email.
- After C.5: hub issues JWTs that servers accept for authentication.
- After Phase A: plugin system in place on the server.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Invite-link sharing" is **Missing**.

## 3. Scope — files to create / modify

### Hub-side (`/home/sites/phlex-hub/`)

#### DB Schema

- `migrations/009_invite_links.sql`:

  ```sql
  CREATE TABLE invite_links (
      id               CHAR(36) PRIMARY KEY,
      owner_user_id   CHAR(36) NOT NULL,
      server_id       CHAR(36) NOT NULL,
      library_id     VARCHAR(255) NULL,   -- NULL = all libraries
      permission      ENUM('read','readwrite') NOT NULL DEFAULT 'read',
      token_hash      VARCHAR(255) NOT NULL,  -- SHA-256 of signed JWT
      max_uses        INT UNSIGNED NOT NULL DEFAULT 1,
      use_count       INT UNSIGNED NOT NULL DEFAULT 0,
      expires_at      INT UNSIGNED NULL,        -- UNIX ts
      created_at      INT UNSIGNED NOT NULL,
      FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (server_id)       REFERENCES servers(id) ON DELETE CASCADE,
      INDEX idx_token_hash (token_hash),
      INDEX idx_owner (owner_user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```

#### InviteLink DTO

- `src/Hub/InviteLink.php`:

  ```php
  final class InviteLink
  {
      public const SCOPE_ALL_LIBRARIES = 'all';

      public function __construct(
          public readonly string $id,
          public readonly string $ownerUserId,
          public readonly string $serverId,
          public readonly ?string $libraryId,
          public readonly string $permission,
          public readonly int $maxUses,
          public readonly int $useCount,
          public readonly ?int $expiresAt,
          public readonly int $createdAt,
          public readonly string $url,   // full invite URL
      ) { }

      public function isExpired(): bool { }
      public function isExhausted(): bool { }
      public function canUse(): bool { }
  }
  ```

#### InviteLinkHandler

- `src/Hub/InviteLinkHandler.php`:

  ```php
  final class InviteLinkHandler
  {
      public function __construct(
          Connection $db,
          JwtHandler $jwtHandler,
          LibrarySharingHandler $sharingHandler,
          LoggerInterface $logger,
      ) { }

      public function createInviteLink(
          string $ownerId,
          string $serverId,
          ?string $libraryId,
          string $permission = 'read',
          int $maxUses = 1,
          ?int $expiresAt = null,
      ): InviteLink { }

      public function redeemInviteLink(string $token, string $redeemerUserId): LibraryShare { }

      public function listForOwner(string $ownerId): array<InviteLink> { }

      public function revokeInviteLink(string $ownerId, string $linkId): void { }
  }
  ```

  `createInviteLink`:
  1. Validate `$ownerId` owns `$serverId`.
  2. Generate a cryptographically random 32-byte token.
  3. Store SHA-256 of token in `invite_links.token_hash`.
  4. Create a signed JWT containing `{token, ownerId, serverId,
     libraryId, permission, maxUses, expiresAt}` — sign with the
     hub's server-side JWT key.
  5. Build `InviteLink` DTO with a full URL: `{hub_origin}/invite/{jwt}`.

  `redeemInviteLink`:
  1. Parse + verify the JWT signature.
  2. Look up by `token_hash` (re-verify: SHA-256(token) matches).
  3. Check `isExpired()` and `canUse()`.
  4. Increment `use_count`.
  5. Call `$sharingHandler->shareLibrary()` to create the library share.
  6. Return the `LibraryShare`.

#### API Controller

- `src/Server/Http/Controllers/InviteLinkController.php`:

  ```
  POST   /api/v1/me/invite-links
         Authorization: Bearer <hub-user-jwt>
         Body: { "server_id": "...", "library_id": "...", "permission":
                 "read", "max_uses": 1, "expires_in": 604800 }
         Response: { "url": "https://hub.example.com/invite/eyJ...",
                     "expires_at": 1715000000, "id": "..." }

  GET    /api/v1/me/invite-links
         Authorization: Bearer <hub-user-jwt>
         Response: { "invite_links": [...InviteLink] }

  DELETE /api/v1/me/invite-links/{id}
         Authorization: Bearer <hub-user-jwt>
         Response: 204 No Content
  ```

#### Web page

- `public/templates/home/invite-link.tpl` — render the invite link as a
  shareable card with copy button. Shown after link creation and on the
  "My Servers" library management page.

- `public/templates/home/accept-invite.tpl` — shown when a user visits
  `/invite/{token}` without being logged in (prompts login); after
  login, auto-redirects and redeems.

#### Routes

- `src/Server/Http/Router.php` — add:
  - `GET  /invite/{token}` → invite acceptance page
  - `POST /api/v1/me/invite-links` → create
  - `GET  /api/v1/me/invite-links` → list
  - `DELETE /api/v1/me/invite-links/{id}` → revoke

### Server-side (`/home/sites/phlex/`)

The server side has no invite-link logic — invite links are purely hub
tokens that grant hub-side library shares (C.9). The server learns
about the share via the existing `library_shares` table sync from hub
heartbeat (C.3/C.9). **No server-side changes needed** for D.5 beyond
a minor `CHANGELOG.md` entry.

### Unit Tests (hub-side)

- `tests/unit/Hub/InviteLinkTest.php`
- `tests/unit/Hub/InviteLinkHandlerTest.php`:
  - createInviteLink success
  - createInviteLink not-owner throws
  - redeemInviteLink success
  - redeemInviteLink expired throws
  - redeemInviteLink already-exhausted throws
  - redeemInviteLink invalid token throws
  - listForOwner
  - revokeInviteLink
- `tests/unit/Hub/InviteLinkControllerTest.php`

### Documentation

- `docs/hub/invite-links.md` — new end-user guide for invite link sharing.
- `docs/reference/api/hub-invite-links.md` — new API reference.

### Modify (hub-side)

- `src/Common/Container/Providers/HubServicesProvider.php` — register
  `InviteLinkHandler`.
- `CHANGELOG.md` (hub).

## 4. Approach

This step touches both `phlex-hub` and `phlex-server`. The subagent
MUST do the work in `/home/sites/phlex-hub/` first, merge that PR, then
handle the server's `CHANGELOG.md` update in `/home/sites/phlex/`.

**Hub-side:**

1. **Pre-flight.** Clean master on `/home/sites/phlex-hub`.
2. **Branch:** `git checkout -b d.5-invite-links`.
3. **DB migration.**
4. **DTO + Handler + Controller.**
5. **Routes + Smarty pages.**
6. **Tests.**
7. **Docs.**
8. **Verification bar.**
9. **Commit + PR + merge** on hub.
10. **Return to master** on hub, then clean up.

**Server-side (trivial):**

1. `git checkout -b d.5-invite-links-server` on `/home/sites/phlex/`.
2. Add CHANGELOG entry noting invite-link sharing is a hub feature.
3. Commit + PR + merge.

## 5. Tests (REQUIRED — §0.4 minimum bar) — hub-side only

1. `InviteLinkTest::test_isExpired_true`
2. `InviteLinkTest::test_isExpired_false`
3. `InviteLinkTest::test_isExhausted_false`
4. `InviteLinkTest::test_isExhausted_true`
5. `InviteLinkTest::test_canUse_all_conditions`
6. `InviteLinkHandlerTest::test_createInviteLink_success`
7. `InviteLinkHandlerTest::test_createInviteLink_not_owner_throws`
8. `InviteLinkHandlerTest::test_redeemInviteLink_success`
9. `InviteLinkHandlerTest::test_redeemInviteLink_expired_throws`
10. `InviteLinkHandlerTest::test_redeemInviteLink_exhausted_throws`
11. `InviteLinkHandlerTest::test_redeemInviteLink_invalid_token_throws`
12. `InviteLinkHandlerTest::test_listForOwner`
13. `InviteLinkHandlerTest::test_revokeInviteLink`
14. `InviteLinkControllerTest::test_create_invite_link`
15. `InviteLinkControllerTest::test_list_invite_links`
16. `InviteLinkControllerTest::test_delete_invite_link`

**Coverage target:** `InviteLinkHandler` ≥ 85 %.

## 6. Acceptance criteria

- [ ] Owner can create an invite link for a specific library on a server
      they own.
- [ ] Invite link URL is a signed JWT at `/invite/{token}`.
- [ ] Unauthenticated visit to `/invite/{token}` shows login prompt.
- [ ] Authenticated user visiting `/invite/{token}` is immediately
      granted read access to the library.
- [ ] `max_uses > 1` allows multiple users to use the same link.
- [ ] Expired links are rejected with a clear error message.
- [ ] Owner can list and revoke their own invite links.
- [ ] Hub returns share in `GET /api/v1/me/shares` after redemption.
- [ ] `./vendor/bin/phpunit` — green; ≥ 16 new tests.
- [ ] Coverage ≥ 85 % on `InviteLinkHandler`.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/hub/invite-links.md` created.
- [ ] Hub CHANGELOG entry added.
- [ ] Git ritual executed on both repos.

## 7. Git ritual (hub-side first)

### Hub

```bash
# ─── Hub-side ───
cd /home/sites/phlex-hub
git status --short
git pull --ff-only origin master
git checkout -b d.5-invite-links
# ... work ...
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'InviteLink'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
git add -A
git commit -m "Step D.5: invite-link sharing (hub-side)"
unset GITHUB_TOKEN
gh pr create \
  --title "Step D.5: hub invite-link sharing" \
  --body  "Adds invite-link sharing: owner generates a signed JWT link, recipient redeems it for library access. Hub-side only. Part of Phase D (Step D.5 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master
git pull --ff-only origin master
git branch --list 'd.5-*'
```

### Server (trivial CHANGELOG update)

```bash
cd /home/sites/phlex
git status --short
git pull --ff-only origin master
git checkout -b d.5-invite-links-server
echo "## Unreleased\n### Added\n- Hub invite-link sharing (D.5)" >> CHANGELOG.md
git add CHANGELOG.md
git commit -m "Step D.5: note hub invite-link sharing in server CHANGELOG"
unset GITHUB_TOKEN
gh pr create \
  --title "Step D.5: server CHANGELOG for hub invite-link sharing" \
  --body  "Documents that invite-link sharing (D.5) is a hub feature; no server-side changes."
gh pr merge --squash --delete-branch
git checkout master
git pull --ff-only origin master
git branch --list 'd.5-*'
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `d.5-invite-links-review.md` on both repos.
Hub-side is the primary review target.

Non-obvious points:
- The token stored in `token_hash` is the SHA-256 of the raw JWT
  (not the JWT itself). The JWT is cryptographically signed and
  self-contained — anyone with the URL can redeem it. The hash
  lookup prevents enumeration.
- `max_uses` > 1 is useful for family groups: one link grants access
  to everyone who clicks it, up to N times.
- Invite links are for hub-managed library sharing (C.9); they do
  NOT create server-side accounts — the share is managed entirely by
  the hub's `library_shares` table, synced to the server via heartbeat.
