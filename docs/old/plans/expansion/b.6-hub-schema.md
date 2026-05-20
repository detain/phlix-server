# Step B.6 — Hub DB schema + migrations

**Phase:** B (Repo Split & Migration)
**Step:** B.6
**Depends on:** B.5
**Review:** Yes — see `b.6-hub-schema-review.md`
**Target repo:** `detain/phlex-hub` (local: `/home/sites/phlex-hub/`).
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Define the persistent state model the hub needs to support its Phase
B and Phase C endpoints. Write five real migrations that replace the
`001_placeholder.sql` from B.5, plus a usable
`scripts/run-migrations.php` ported from `phlex-server`.

Tables aligned with master plan §4.2 endpoint list:

| Endpoint family (§4.2) | Tables |
|---|---|
| Signup / login / session (B.7) | `users` |
| `POST /api/v1/server-claims` (C.3) | `servers`, `server_claims` |
| `POST /api/v1/servers/{id}/heartbeat` (C.3) | `server_heartbeats` (last-N), and a derived "last-seen" column on `servers` |
| `GET /api/v1/users/{id}/shared` (C.9) | `shared_libraries` |
| `/api/v1/servers/{id}/relay` (C.6) | `relay_sessions` |
| `/api/v1/webhooks/*` (L.1+) | `webhooks` |

## 2. Context (what already exists)

- After B.5:
  - `/home/sites/phlex-hub/migrations/` contains only `.gitkeep` and
    `001_placeholder.sql`.
  - `scripts/run-migrations.php` exists as a copy of
    `phlex-server/scripts/run-migrations.php` (still pointed at the
    hub config though). Verify before extending.
  - `config/database.php` defines the `mysql` connection with the
    `HUB_DB_*` env vars.
- `/home/sites/phlex/migrations/001_initial_schema.sql` and
  `migrations/002_user_profiles_and_parental_controls.sql` —
  reference shape: `CHAR(36)` UUID primary keys, `created_at /
  updated_at` columns, snake_case names. The hub follows the same
  conventions (master plan §10 risk #6 keeps the schema as the
  cross-repo boundary).
- `b.1-shared-design.md` §4.5 — the four Hub DTOs (`ClaimRequest`,
  `ClaimResponse`, `ServerInfoDto`, `HeartbeatDto`) whose field
  shapes inform the schema column choices.

## 3. Scope — files to create / modify

All paths inside `/home/sites/phlex-hub/`.

### Create

- `migrations/001_users.sql` — `users` table:
  ```sql
  CREATE TABLE users (
      id              CHAR(36) NOT NULL,
      username        VARCHAR(64) NOT NULL,
      email           VARCHAR(255) NOT NULL,
      password_hash   VARCHAR(255) NOT NULL,    -- Argon2ID
      display_name    VARCHAR(128) DEFAULT NULL,
      is_admin        TINYINT(1) NOT NULL DEFAULT 0,
      created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uk_users_username (username),
      UNIQUE KEY uk_users_email (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```
- `migrations/002_servers.sql` — three tables in one file (servers +
  server_claims + server_heartbeats):
  ```sql
  CREATE TABLE servers (
      id                 CHAR(36) NOT NULL,        -- minted by hub on successful claim
      user_id            CHAR(36) NOT NULL,        -- FK -> users.id
      server_name        VARCHAR(128) NOT NULL,
      version            VARCHAR(32) NOT NULL,
      jwks_json          TEXT NOT NULL,            -- the server's published JWKS
      hostname_candidates_json TEXT NOT NULL,      -- JSON array
      status             ENUM('online','offline','claiming','disabled') NOT NULL DEFAULT 'claiming',
      last_seen_at       DATETIME DEFAULT NULL,
      created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY ix_servers_user_id (user_id),
      KEY ix_servers_last_seen (last_seen_at),
      CONSTRAINT fk_servers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE server_claims (
      id                 CHAR(36) NOT NULL,        -- the opaque claim_id
      claim_code         VARCHAR(16) NOT NULL,     -- the human-friendly "ABCD-1234"
      user_id            CHAR(36) DEFAULT NULL,    -- NULL until paired, then FK -> users.id
      server_name        VARCHAR(128) NOT NULL,
      version            VARCHAR(32) NOT NULL,
      jwks_json          TEXT NOT NULL,
      hostname_candidates_json TEXT NOT NULL,
      status             ENUM('pending','paired','expired','revoked') NOT NULL DEFAULT 'pending',
      expires_at         DATETIME NOT NULL,
      created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      paired_at          DATETIME DEFAULT NULL,
      paired_server_id   CHAR(36) DEFAULT NULL,    -- FK -> servers.id once paired
      PRIMARY KEY (id),
      UNIQUE KEY uk_server_claims_code (claim_code),
      KEY ix_server_claims_status_expires (status, expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE server_heartbeats (
      id                 CHAR(36) NOT NULL,
      server_id          CHAR(36) NOT NULL,
      version            VARCHAR(32) NOT NULL,
      uptime_seconds     INT UNSIGNED NOT NULL,
      active_sessions    INT UNSIGNED NOT NULL DEFAULT 0,
      active_transcodes  INT UNSIGNED NOT NULL DEFAULT 0,
      hostname_candidates_json TEXT NOT NULL,
      received_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY ix_server_heartbeats_server_time (server_id, received_at),
      CONSTRAINT fk_server_heartbeats_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```
- `migrations/003_shared_libraries.sql`:
  ```sql
  CREATE TABLE shared_libraries (
      id                 CHAR(36) NOT NULL,
      owner_user_id      CHAR(36) NOT NULL,        -- FK -> users.id, the server owner
      grantee_user_id    CHAR(36) NOT NULL,        -- FK -> users.id, who can access
      server_id          CHAR(36) NOT NULL,        -- FK -> servers.id
      library_id         CHAR(36) NOT NULL,        -- server-side library UUID; opaque to hub
      library_name       VARCHAR(128) NOT NULL,    -- denormalised for dashboard listing
      created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      revoked_at         DATETIME DEFAULT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uk_shared_libraries (server_id, library_id, grantee_user_id),
      KEY ix_shared_libraries_grantee (grantee_user_id),
      KEY ix_shared_libraries_owner (owner_user_id),
      CONSTRAINT fk_shared_libraries_owner   FOREIGN KEY (owner_user_id)   REFERENCES users(id)   ON DELETE CASCADE,
      CONSTRAINT fk_shared_libraries_grantee FOREIGN KEY (grantee_user_id) REFERENCES users(id)   ON DELETE CASCADE,
      CONSTRAINT fk_shared_libraries_server  FOREIGN KEY (server_id)       REFERENCES servers(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```
- `migrations/004_relay_sessions.sql`:
  ```sql
  CREATE TABLE relay_sessions (
      id                 CHAR(36) NOT NULL,
      server_id          CHAR(36) NOT NULL,
      worker_node        VARCHAR(128) NOT NULL,    -- which hub worker the WS is open on (multi-node scaling)
      opened_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      closed_at          DATETIME DEFAULT NULL,
      bytes_in           BIGINT UNSIGNED NOT NULL DEFAULT 0,
      bytes_out          BIGINT UNSIGNED NOT NULL DEFAULT 0,
      close_reason       VARCHAR(64) DEFAULT NULL,
      PRIMARY KEY (id),
      KEY ix_relay_sessions_server (server_id, opened_at),
      KEY ix_relay_sessions_open (server_id, closed_at),
      CONSTRAINT fk_relay_sessions_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```
- `migrations/005_webhooks.sql`:
  ```sql
  CREATE TABLE webhooks (
      id                 CHAR(36) NOT NULL,
      user_id            CHAR(36) NOT NULL,
      name               VARCHAR(128) NOT NULL,
      target_url         VARCHAR(512) NOT NULL,
      secret             VARCHAR(255) DEFAULT NULL,   -- HMAC signing secret, optional
      event_aliases_json TEXT NOT NULL,               -- JSON array of phlex.* alias strings
      template_json      TEXT DEFAULT NULL,           -- handlebars template body
      enabled            TINYINT(1) NOT NULL DEFAULT 1,
      created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      last_delivery_at   DATETIME DEFAULT NULL,
      last_delivery_status VARCHAR(16) DEFAULT NULL,
      PRIMARY KEY (id),
      KEY ix_webhooks_user (user_id),
      CONSTRAINT fk_webhooks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```
- `tests/integration/Migrations/MigrationRunnerTest.php` — sqlite or
  test-MySQL backed. Runs the migrations in order against an empty
  DB, asserts every table exists, asserts the FK + index list.
  Skipped if neither sqlite nor a `HUB_TEST_DB_*` env is available.
- `docs/dev/schema.md` — ER diagram (mermaid) + table reference:
  one section per table covering columns, indexes, FKs, and which
  endpoint(s) read/write each.

### Modify

- `migrations/001_placeholder.sql` — **delete** (replaced by
  `001_users.sql`).
- `scripts/run-migrations.php` — port from `phlex-server` if not
  already done in B.5; double-check it:
  - Reads `config/database.php`.
  - Iterates `migrations/*.sql` in numeric order.
  - Tracks applied migrations in a `migrations` table (auto-created
    on first run).
  - Idempotent on re-run.
  - Strict-types + PHPDoc.
- `CHANGELOG.md` — entry:
  ```markdown
  ## [Unreleased]
  ### Added
  - Database schema: `users`, `servers`, `server_claims`, `server_heartbeats`, `shared_libraries`, `relay_sessions`, `webhooks` (migrations 001–005).
  - `scripts/run-migrations.php` runner.
  ```
- `docs/reference/env-vars.md` — already documents `HUB_DB_*` from
  B.5; no change unless we add new env vars (we don't here).
- `README.md` — update the Quick-start to reflect that running
  migrations now creates real tables instead of a placeholder.

### Delete

- `migrations/001_placeholder.sql` (replaced).

## 4. Approach

1. **Pre-flight.** Confirm `/home/sites/phlex-hub` is on clean
   master.
2. **Branch:** `git checkout -b b.6-hub-schema`.
3. **Write the five migrations** with the exact SQL from §3. Order:
   001_users → 002_servers (depends on users) → 003_shared_libraries
   (depends on users + servers) → 004_relay_sessions (depends on
   servers) → 005_webhooks (depends on users).
4. **Delete the placeholder** `migrations/001_placeholder.sql`.
5. **Verify `scripts/run-migrations.php`** can run the five
   migrations against a local MySQL or a test sqlite. (Sqlite
   doesn't support all the InnoDB-specific syntax, so prefer a
   real MySQL test DB; if not available, document a manual smoke
   test in the review template instead.)
6. **Write the integration test.** PHPUnit drives
   `run-migrations.php` via shell-exec against a transient
   `HUB_TEST_DB_*` env. Asserts every table + index + FK exists via
   `INFORMATION_SCHEMA` queries.
7. **Write `docs/dev/schema.md`.** ER diagram (mermaid) + per-table
   sections. ~300 LoC of markdown.
8. **Run the verification bar.**
9. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Integration test:

1. `MigrationRunnerTest::test_runs_all_migrations_in_order_against_empty_db`
   — fresh DB, run all migrations, assert tables + FKs + indexes
   exist. Skipped if no test DB env is configured.
2. `MigrationRunnerTest::test_rerunning_is_idempotent` — re-run on
   an already-migrated DB, no errors, no duplicate rows.
3. `MigrationRunnerTest::test_users_unique_constraints` — try to
   insert two users with the same email, assert the second insert
   throws a `Workerman\MySQL\Exception` (or a PDO duplicate-key
   exception, depending on the runtime).
4. `MigrationRunnerTest::test_server_fk_cascades_on_user_delete` —
   insert user + server, delete user, server row is gone.

Unit tests: none. The migration files are SQL; correctness is
verified by the integration suite + the schema doc cross-check.

**Coverage target:** N/A on `migrations/` (raw SQL).
`scripts/run-migrations.php` should hit ≥ 80 % via the integration
suite.

**Integration boundary:** new DB schema. Required per §0.4 — the
four integration tests above satisfy this.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Hub functionality (Phase B+)** → `docs/dev/schema.md` is the
  new doc. The end-user `docs/hub/*.md` and hub-admin
  `docs/hub-admin/*.md` trees don't change in B.6 — they cover
  user-facing flows, which need the signup flow from B.7 to write
  about.
- **"A configurable env var or `config/*.php` key"** → N/A; the
  `HUB_DB_*` vars were already documented in B.5.
- **CHANGELOG** → entry per §3.

PHPDoc per §0.4 on every public method/class in
`scripts/run-migrations.php` (it's a script with a single
`MigrationRunner` class; PHPDoc the class and the public methods).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] Files `migrations/001_users.sql` through
      `migrations/005_webhooks.sql` exist with the SQL from §3.
- [ ] `migrations/001_placeholder.sql` is deleted.
- [ ] `scripts/run-migrations.php` is present and PHPDoc'd.
- [ ] `tests/integration/Migrations/MigrationRunnerTest.php` exists
      and exercises the four scenarios listed.
- [ ] `docs/dev/schema.md` exists with the ER diagram + table
      reference.
- [ ] `./vendor/bin/phpunit` — green. Integration tests pass against
      a real test DB OR are documented-skipped with a clear reason.
- [ ] `./vendor/bin/phpstan analyze --no-progress` — `[OK] No errors`.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean (note:
      `scripts/` may need to be added to phpcs paths).
- [ ] `./vendor/bin/psalm --no-progress` — clean.
- [ ] `find src scripts -name '*.php' -exec php -l {} \;` — no
      syntax errors.
- [ ] `php scripts/run-migrations.php` against a fresh test DB
      runs to completion and creates seven tables (`migrations` +
      the six business tables) without errors.
- [ ] `CHANGELOG.md` has the B.6 entry.
- [ ] PHPDoc on every new public class/method.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4, targeting the hub repo)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex-hub
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b b.6-hub-schema

# ─── 2. Do the work — write five migrations + tests + schema doc ───

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/ scripts/
./vendor/bin/psalm --no-progress
find src scripts -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# Smoke: run the migrations against a transient test DB if available
if [ -n "$HUB_TEST_DB_NAME" ]; then
  php scripts/run-migrations.php
fi

# ─── 4. (Caliber not yet on this repo) ───
git add -A

# ─── 5. Commit ───
git commit -m "Step B.6: hub DB schema + 5 migrations + run-migrations.php"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step B.6: hub DB schema + migrations" \
  --body  "Adds migrations 001–005 (users, servers + server_claims + server_heartbeats, shared_libraries, relay_sessions, webhooks) and the run-migrations.php runner. Schema documented in docs/dev/schema.md. Implements step B.6 of PHLEX_EXPANSION_PLAN.md (run inside detain/phlex-hub)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'b.6-*'                   # MUST be empty
gh run list --repo detain/phlex-hub --branch master --limit 1 --json conclusion | grep '"conclusion":"success"'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `b.6-hub-schema-review.md`. The reviewer
additionally cross-checks that the five migration files' table list
matches the master plan §4.2 endpoint family table in §1 of this
plan.
