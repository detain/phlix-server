# Session-family revocation (D-1a) — how the server actually works

Status: SHIPPED. Core at commit 413765b6 (migrations 126–127 + the store/ceremony
code); retention parity cure at this working tip (migration 128 + prune legs),
2026-11-11. Every file:line below is read from that shipped tree. This document
describes what the code does — not what a design note hoped it would do.

## The problem this solves

A refresh token is a bearer credential with a multi-day lifetime. Before D-1a a
stolen refresh token and a legitimate one were indistinguishable to the server:
rotation updated the tokens, nothing remembered lineage, and logging a user out
of one session could not say anything about the token copies that had already
walked out of it. D-1a makes rotation *consumption*: each mint is a grant of a
session family, presenting a grant twice is evidence the token exists in two
places, and the family — the unit of trust — can be revoked as one row.

## The moving parts

| Part | Where |
|---|---|
| Family row (session) | `auth_token_sessions` — migration 127 (`migrations/127_auth_token_sessions.sql:36`) |
| Grant row (jti → family) | `auth_refresh_grants` — migration 127 (`migrations/127_auth_token_sessions.sql:51`) |
| Grant lifetime + sweep index | migration 128 (`migrations/128_auth_refresh_grants_expiry.sql`) |
| The store | `src/Auth/TokenSessionRepository.php:57` (`class TokenSessionRepository`) |
| The ceremony | `src/Auth/AuthManager.php:1584` (`refreshToken`) → `:1740` (`adjudicateRefreshFamily`) |
| Mint-time registration | `src/Auth/AuthManager.php:2155` (recordGrant call in `buildAuthResponse`) |
| Family claim on tokens | `src/Auth/JwtHandler.php:100` (`CLAIM_SESSION_ID = 'sid'`) |
| HTTP surface | `src/Server/Core/Application.php:477–478` → `src/Server/Http/Controllers/AuthController.php:662` (`listTokenSessions`), `:707` (`revokeTokenSession`) |
| Wiring | `src/Common/Container/Providers/AuthServicesProvider.php:633,639` |

## The ceremony, executed

1. **Presentation is validated cryptographically before any store read.**
   `AuthManager::refreshToken()` (src/Auth/AuthManager.php:1584) runs
   `JwtHandler::validateToken()` first — a token whose `exp` has passed is
   refused with `Expired refresh token` at :1592, before
   `adjudicateRefreshFamily()` ever reaches the grants table. This ordering is
   the load-bearing premise of the retention sweep (below): the sweep may
   delete a grant whose expiry has passed because the presentation path will
   refuse that token at the crypto gate anyway — the row is never the reason a
   dead token dies.
2. **First contact opens a family.** A refresh token minted before D-1a carries
   no `sid` claim. On its first rotation the server calls
   `TokenSessionRepository::createSession()` (src/Auth/TokenSessionRepository.php:112),
   stamps the family into the successor tokens (:2132 sets
   `JwtHandler::CLAIM_SESSION_ID`), and registers the successor's `jti` as the
   family's first grant (:2155).
3. **Inside a family, rotation consumes.** Presenting a family token looks its
   `jti` up via `findGrant()` (src/Auth/TokenSessionRepository.php:177). An
   unconsumed grant is marked consumed (markGrantConsumed :287) *before* the
   response returns, the successor `jti` is registered, and the family row is
   touched so `last_used_at` stays honest.
4. **Reuse is revocation.** A grant already consumed, presenting outside the
   10-second rotation grace (`AuthManager::ROTATION_GRACE_SECONDS`,
   src/Auth/AuthManager.php:269), means two copies of the same token exist.
   The whole family is revoked (`revokeSession`, src/Auth/TokenSessionRepository.php:325)
   and the presentation refused. The grace window is a database-clock fact
   (`consumed_at` vs `NOW()` inside findGrant's own query), not a PHP clock —
   proven by tests/Integration/Auth/TokenSessionFamilyMigration127RealDbTest.php.
5. **Revocation paths.** Per-family: `AuthManager::revokeTokenSession()`
   (src/Auth/AuthManager.php:2335) behind DELETE /api/v1/auth/sessions/{id};
   per-account (nuclear, the legacy `users.tokens_not_valid_after` bump and
   every live family at once): `revokeUserTokens()` (:2284) and
   `revokeAllForUser()` (src/Auth/TokenSessionRepository.php:344).

## Retention (the 2026-11-11 parity cure)

The core shipped the ceremony without a lifecycle for the rows it writes. Two
gaps, both closed now; the shape mirrors the hub twin
(phlix-hub 3096c53e, migrations/056) in semantics, adapted to the server's own
shipped idioms:

1. **Every grant carries its own lifetime claim.** `recordGrant()`
   (src/Auth/TokenSessionRepository.php:231) takes the minted token's OWN baked
   `exp` — `buildAuthResponse` reads it back off the exact bytes the client
   received (src/Auth/AuthManager.php:2154) and hands it over; the INSERT
   stamps it with `FROM_UNIXTIME(?)` into the `expires_at` column migration 128
   adds. The store never guesses a TTL from config: config can drift from what
   `JwtHandler` baked, and a grant whose row-lifetime outlives its token is
   garbage that sweeps cannot see.
2. **A write-time sweep retires dead rows.** `pruneExpired()`
   (src/Auth/TokenSessionRepository.php:258) runs, bounded and best-effort, at
   the head of every `recordGrant()` — the server has no sweeper daemon, and
   the house janitor idiom (`QuickConnectStateStore`) is what this repo
   actually ships. Two arms:
   - `expires_at < NOW() - 86400 SECOND` — dead for a day, gone;
   - `expires_at IS NULL AND created_at < NOW() - 31536000 SECOND` — rows that
     predate the cure ride a one-year grandfather horizon instead of sweeping
     instantly (they still have live tokens).
   The constants sit beside the method (PRUNE_GRACE_SECONDS :67,
   PRUNE_BATCH_SIZE :70, UNSTAMPED_RETENTION_SECONDS :76).
   What the sweep must NEVER touch, and tests pin per-arm:
   - a CONSUMED-but-unexpired grant — that row IS the reuse detector; deleting
     it early would make token theft look like ordinary absence;
   - a live (unexpired) grant of a family still signing in;
   - an ancient-created row whose stamped expiry is still live — expiry
     governs stamped rows alone.
   Bounded: each DELETE carries `LIMIT 1000` and each prune event sweeps at
   most two batches — a pathological backlog cannot stall the mint path, and
   the sweep failure is swallowed by design (janitorial, never load-bearing:
   worst case the table stays big until the next write, exactly as before).
3. **The sessions list is bounded.** `listActiveSessions()`
   (src/Auth/TokenSessionRepository.php:391) appends
   `LIMIT 200` (public `MAX_LISTED_SESSIONS` :83). Newest activity first, so
   the page a user sees is the page that matters; DELETE by id
   (src/Server/Http/Controllers/AuthController.php:707) stays capability-neutral
   — the id lives in the client's own token claims, not in the list. A 201-row
   insert showing exactly-200-returned is pinned by execution, not prose
   (testTheSessionsListIsBoundedToTheCeiling).
4. **The DDL contract is fingerprinted.** The migration 128 guards (column
   probe, index probe, `PREPARE`-gated ALTER, no directive — the
   `@phlix-require-mysql` marker is for standalone DDL and per the M28 law must
   never attach to dynamic-DDL fragments) replay idempotently; the DDL
   fingerprint test pins `expires_at` as nullable TIMESTAMP and
   `idx_arg_expires_at`'s presence, so a silent re-shape of the sweep columns
   reddens the next real-MySQL run.

## What this is NOT

- Not a device registry: the legacy playback-device `/api/v1/me/sessions`
  registry is a different table serving a different feature.
- Not a proof of storage compromise: reuse detection tells you two
  presentations happened, not where the copies live. Revocation is the answer
  the server can actually give.
- Not a KMS: JTIs are opaque 32-hex claim values, no key material touches the
  grants table.
