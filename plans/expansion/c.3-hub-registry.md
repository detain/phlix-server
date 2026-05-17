# Step C.3 — Hub-side: server registry endpoints

**Phase:** C (Server↔Hub Pairing & Remote Access)
**Step:** C.3
**Depends on:** C.1
**Review:** Yes — see `c.3-hub-registry-review.md`
**Target repo:** `detain/phlex-hub` (local: `/home/sites/phlex-hub/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Build the hub-side server registry API that:

1. Accepts `POST /api/v1/server-claims/new` — server initiates pairing
2. Accepts `POST /api/v1/server-claims/claim` — user claims a server
3. Accepts `POST /api/v1/servers/{id}/heartbeat` — server health pings
4. Accepts `GET /api/v1/servers/{id}/info` — hub operator info about a server
5. Accepts `DELETE /api/v1/servers/{id}` — server deregisters
6. Serves `GET /.well-known/jwks.json` — hub's signing keys for JWT validation
7. Manages the `server_claims` and `servers` DB tables

## 2. Context (what already exists)

- After B.7: hub portal scaffolded with Auth, UserRepository, JWT,
  `HubDbSchema` (from B.6), Smarty templates
- `phlex-shared` v0.2.0: `Phlex\Shared\Hub\ClaimRequest`,
  `Phlex\Shared\Hub\ClaimResponse`, `Phlex\Shared\Hub\HeartbeatDto`,
  `Phlex\Shared\Hub\ServerInfoDto`
- `PHLEX_EXPANSION_PLAN.md` §6 — protocol overview
- `docs/dev/pairing-protocol.md` — full protocol spec (C.1 output)
- B.6 schema at `/home/sites/phlex-hub/migrations/` — verify it includes
  `server_claims` and `servers` tables with the fields defined in §3
- `/home/sites/phlex-hub/src/Auth/JwtHandler.php` — existing JWT patterns
- `/home/sites/phlex-hub/src/Server/Http/Router.php` — existing routing

## 3. Scope — files to create / modify

All paths inside `/home/sites/phlex-hub/`.

### Create

#### DB Schema Extension

If B.6 didn't include `server_claims` and `servers` tables, create a new
migration:

- `migrations/006_server_claims_and_servers.sql`:

```sql
CREATE TABLE server_claims (
    id            CHAR(36) PRIMARY KEY,
    claim_code    VARCHAR(9)  NOT NULL UNIQUE,
    server_name   VARCHAR(255) NOT NULL,
    version       VARCHAR(20)  NOT NULL,
    public_key_jwk JSON        NOT NULL,
    hostname_candidates JSON    NOT NULL,
    protocol_version VARCHAR(10) NOT NULL DEFAULT 'v1',
    expires_at    INT UNSIGNED NOT NULL,
    claimed_by    CHAR(36) NULL,
    claimed_at    INT UNSIGNED NULL,
    created_at    INT UNSIGNED NOT NULL,
    INDEX idx_claim_code (claim_code),
    INDEX idx_expires_at (expires_at),
    FOREIGN KEY (claimed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE servers (
    id            CHAR(36) PRIMARY KEY,
    user_id       CHAR(36) NOT NULL,
    server_name   VARCHAR(255) NOT NULL,
    version       VARCHAR(20)  NOT NULL,
    public_key_jwk JSON        NOT NULL,
    hostname_candidates JSON    NOT NULL,
    status        ENUM('online','offline','claiming','disabled') NOT NULL DEFAULT 'claiming',
    last_seen_at  INT UNSIGNED NULL,
    enrolled_at   INT UNSIGNED NOT NULL,
    heartbeat_interval INT UNSIGNED NOT NULL DEFAULT 60,
    capabilities  JSON NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### JWKS (Hub Signing Keys)

- `src/Hub/Ed25519KeyManager.php` — hub-side key manager (mirrors the
  server's but for the hub's signing key). Hub stores its key at
  `config/hub-signing-key.pem`.

  ```php
  final class Ed25519KeyManager
  {
      public function __construct(string $keyPath) { }
      public function getOrCreateKeyPair(): KeyPair { }
      public function getPublicKeyJwk(string $kid): array { }
      public function getKid(): string { }
      public function rotate(): void { }
  }
  ```

- `src/Hub/JwksController.php` — serves `GET /.well-known/jwks.json`:

  ```php
  final class JwksController
  {
      public function __construct(Ed25519KeyManager $keyManager) { }
      public function handle(Request $request): Response { }
  }
  ```

#### Claim Request Handler

- `src/Hub/ClaimRequestHandler.php` — validates incoming server claim
  requests, generates codes, stores pending claims:

  ```php
  final class ClaimRequestHandler
  {
      public function __construct(
          Connection $db,
          Ed25519KeyManager $keyManager,
          LoggerInterface $logger,
      ) { }

      public function handleNewClaim(ClaimRequest $request): ClaimResponse { }
      public function handleClaimCode(string $claimCode, string $userId): ClaimResponse { }
      public function generateClaimCode(): string { }  // ABCD-1234 format
  }
  ```

  On `handleNewClaim`:
  1. Validates `ClaimRequest` fields (version format, JWK structure)
  2. Checks for existing pending claim for this public key (returns
     existing code if found — prevents duplicate codes on retry)
  3. Generates `claim_id` (UUID) and `claim_code`
  4. Sets `expires_at = now + 600`
  5. Inserts `server_claims` row
  6. Returns `ClaimResponse` with `claim_code`, `expires_in`, `claim_id`,
     `hub_base_url`

  On `handleClaimCode`:
  1. Looks up claim code in `server_claims` with `FOR UPDATE` lock
  2. Validates not expired, not already claimed
  3. Atomically updates `claimed_by`, deletes the claim code
  4. Inserts `servers` row with status `online`
  5. Generates and returns enrollment JWT

#### Enrollment JWT

- `src/Hub/EnrollmentJwtService.php` — issues the enrollment JWT:

  ```php
  final class EnrollmentJwtService
  {
      public function __construct(
          Ed25519KeyManager $keyManager,
          LoggerInterface $logger,
          string $hubBaseUrl,
      ) { }

      public function createEnrollmentJwt(
          string $serverId,
          string $hubBaseUrl,
          int $ttl = 604800,  // 7 days
      ): string { }

      public function getHubJwksUrl(): string { }
  }
  ```

  Issues JWT signed with hub's Ed25519 key. Claims:
  `iss=phlex-hub`, `sub=serverId`, `aud=server`, `exp`, `iat`, `kid`,
  `hub_base_url`.

#### Heartbeat Handler

- `src/Hub/HeartbeatHandler.php` — processes server heartbeats:

  ```php
  final class HeartbeatHandler
  {
      public function __construct(Connection $db, LoggerInterface $logger) { }

      public function handle(
          string $serverId,
          string $enrollmentJwt,
          HeartbeatDto $heartbeat,
      ): void { }

      public function isServerOwnedByUser(string $serverId, string $userId): bool { }
  }
  ```

  On `handle`:
  1. Validates the `enrollmentJwt` (signature + expiry)
  2. Finds the server by `serverId`
  3. Updates `servers.last_seen_at = now`, `status = 'online'`,
     `version`, `hostname_candidates`, `heartbeat_interval`
  4. If server not found → throws `ServerNotFoundException`

#### Server Info Handler

- `src/Hub/ServerInfoHandler.php` — returns server info for hub dashboard:

  ```php
  final class ServerInfoHandler
  {
      public function __construct(Connection $db) { }

      public function getServerInfo(string $serverId): ServerInfoDto { }
      public function getServersForUser(string $userId): array<ServerInfoDto> { }
  }
  ```

#### Deregister Handler

- `src/Hub/DeregisterHandler.php` — server voluntarily deregisters:

  ```php
  final class DeregisterHandler
  {
      public function __construct(Connection $db, LoggerInterface $logger) { }

      public function handle(string $serverId, string $enrollmentJwt): void { }
  }
  ```

  1. Validates enrollment JWT
  2. Deletes `servers` row
  3. Logs the deregistration

#### HTTP Controllers

- `src/Server/Http/Controllers/ServerClaimController.php` — maps to:

  ```
  POST   /api/v1/server-claims/new     → handleNewClaim
  POST   /api/v1/server-claims/claim   → handleClaimCode (requires auth)
  ```

  Note: `POST /server-claims/new` is **public** (no auth — the server
  has no JWT yet). `POST /server-claims/claim` requires the user's hub
  session JWT.

- `src/Server/Http/Controllers/ServerController.php` — maps to:

  ```
  POST   /api/v1/servers/{id}/heartbeat   → handle heartbeat
  GET    /api/v1/servers/{id}/info        → getServerInfo
  DELETE /api/v1/servers/{id}             → handle deregister
  ```

  All three require the enrollment JWT (`Authorization: Bearer <enrollment_jwt>`).

- `src/Server/Http/Controllers/HubJwksController.php` — maps to:

  ```
  GET /.well-known/jwks.json  →  serve JWKS (public, no auth)
  ```

#### Middleware

- `src/Server/Http/Middleware/EnrollmentJwtMiddleware.php` — validates
  the enrollment JWT on server-facing routes:

  ```php
  final class EnrollmentJwtMiddleware
  {
      public function __construct(EnrollmentJwtService $jwtService) { }
      public function handle(Request $request): Request { }  // adds $request->serverId
  }
  ```

  Extracts the `server_id` from the validated enrollment JWT and
  populates `$request->serverId`.

- `src/Server/Http/Middleware/HubProtocolMiddleware.php` — validates
  `Accept-Phlex-Protocol: v1` on all server-claim and server routes:

  ```php
  final class HubProtocolMiddleware
  {
      public function handle(Request $request): Request { }
  }
  ```

  Returns `400 HUB_PROTOCOL_UNSUPPORTED` if header missing or wrong.

#### Unit Tests

- `tests/unit/Hub/Ed25519KeyManagerTest.php` — same pattern as server
- `tests/unit/Hub/JwksControllerTest.php` — serves JWKS document
- `tests/unit/Hub/ClaimRequestHandlerTest.php` — new claim, duplicate
  claim (returns existing), invalid JWK
- `tests/unit/Hub/EnrollmentJwtServiceTest.php` — create + validate
  enrollment JWT, extract claims
- `tests/unit/Hub/HeartbeatHandlerTest.php` — valid heartbeat updates
  server, invalid JWT throws
- `tests/unit/Hub/ServerInfoHandlerTest.php` — getServerInfo,
  getServersForUser (empty + populated)
- `tests/unit/Hub/DeregisterHandlerTest.php` — deregister success
- `tests/unit/Server/Http/Middleware/EnrollmentJwtMiddlewareTest.php`
- `tests/unit/Server/Http/Middleware/HubProtocolMiddlewareTest.php`
- `tests/unit/Server/Http/Controllers/ServerClaimControllerTest.php`
- `tests/unit/Server/Http/Controllers/ServerControllerTest.php`

#### Documentation

- `docs/hub/admin/api.md` — document all five server registry endpoints
- `docs/hub-admin/server-management.md` — add section on claim/declaim
- `docs/dev/architecture-hub.md` — update to show the new Hub namespace

### Modify

- `src/Server/Http/Router.php` — register new routes:

  ```php
  // Public server-claim initiation
  $router->post('/api/v1/server-claims/new', ...);

  // User-authenticated claim
  $router->post('/api/v1/server-claims/claim', ..., [AuthMiddleware::class]);

  // Server-authenticated routes
  $router->post('/api/v1/servers/{id}/heartbeat', ..., [EnrollmentJwtMiddleware::class]);
  $router->get ('/api/v1/servers/{id}/info',       ..., [EnrollmentJwtMiddleware::class]);
  $router->delete('/api/v1/servers/{id}',         ..., [EnrollmentJwtMiddleware::class]);

  // JWKS (public)
  $router->get('/.well-known/jwks.json', ...);
  ```

- `src/Common/Container/Providers/HubServicesProvider.php` — new
  provider registering all `Hub\*` classes
- `src/Common/Container/ContainerFactory.php` — add the new provider
- `src/Application.php` — run `Ed25519KeyManager::getOrCreateKeyPair()`
  on hub startup
- `config/hub.php` — add `hub_base_url` env var
- `composer.json` — add `phlex-shared: ^0.2` (if not already from B.5)
- `CHANGELOG.md` entry

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex-hub`.
2. **Branch:** `git checkout -b c.3-hub-registry`.
3. **Verify schema** — check `migrations/006_server_claims_and_servers.sql`
   exists from B.6; if not, create it.
4. **Write `Ed25519KeyManager`** — mirror of server's version.
5. **Write `JwksController`** — serves hub's signing key as JWKS.
6. **Write `EnrollmentJwtService`** — issues hub-signed enrollment JWTs.
7. **Write `ClaimRequestHandler`** — generates claim codes, stores
   pending claims, issues enrollment JWTs on claim.
8. **Write `HeartbeatHandler`** — validates enrollment JWT, updates
   server last-seen.
9. **Write `ServerInfoHandler`** — returns `ServerInfoDto` list for a user.
10. **Write `DeregisterHandler`** — deletes server row.
11. **Write middleware** — `EnrollmentJwtMiddleware`,
    `HubProtocolMiddleware`.
12. **Wire controllers and routes** in the Router.
13. **Write tests.**
14. **Verification bar.**
15. **Doc updates.**
16. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `Ed25519KeyManagerTest::test_generates_and_loads_keys`
2. `JwksControllerTest::test_serves_valid_jwks_document`
3. `ClaimRequestHandlerTest::test_newClaim_creates_pending_claim`
4. `ClaimRequestHandlerTest::test_newClaim_duplicate_returns_existing_code`
5. `ClaimRequestHandlerTest::test_newClaim_invalid_jwk_throws`
6. `ClaimRequestHandlerTest::test_claimCode_success_atomically_claims`
7. `ClaimRequestHandlerTest::test_claimCode_expired_throws`
8. `ClaimRequestHandlerTest::test_claimCode_already_claimed_throws`
9. `EnrollmentJwtServiceTest::test_createEnrollmentJwt_valid_structure`
10. `EnrollmentJwtServiceTest::test_createEnrollmentJwt_round_trips`
11. `HeartbeatHandlerTest::test_valid_heartbeat_updates_last_seen`
12. `HeartbeatHandlerTest::test_invalid_enrollment_jwt_returns_401`
13. `HeartbeatHandlerTest::test_unknown_server_returns_404`
14. `ServerInfoHandlerTest::test_getServerInfo_returns_dto`
15. `ServerInfoHandlerTest::test_getServersForUser_empty`
16. `DeregisterHandlerTest::test_deregister_deletes_row`
17. `EnrollmentJwtMiddlewareTest::test_valid_token_sets_serverId`
18. `EnrollmentJwtMiddlewareTest::test_expired_token_returns_401`
19. `HubProtocolMiddlewareTest::test_missing_header_returns_400`
20. `HubProtocolMiddlewareTest::test_valid_header_passes`

**Coverage target:** `src/Hub/` and `src/Server/Http/Middleware/EnrollmentJwtMiddleware.php` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Public HTTP/WS API** → `docs/hub/admin/api.md` (new file)
- **Hub functionality** → `docs/hub-admin/server-management.md` (new section)
- **Hub admin doc** → `docs/dev/architecture-hub.md` (update)
- **User-visible behavior change** → CHANGELOG entry

PHPDoc per §0.4 on every new public class/method.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `POST /api/v1/server-claims/new` creates a pending claim and
      returns a valid `ClaimResponse` with claim code
- [ ] `POST /api/v1/server-claims/claim` atomically claims a server,
      deletes the code, and returns an enrollment JWT
- [ ] Enrollment JWT is signed by hub's Ed25519 key and validates with
      hub's JWKS
- [ ] `POST /api/v1/servers/{id}/heartbeat` updates last_seen_at and
      returns 200; wrong/missing enrollment JWT returns 401
- [ ] `GET /api/v1/servers/{id}/info` returns `ServerInfoDto`
- [ ] `DELETE /api/v1/servers/{id}` deletes the server row
- [ ] `GET /.well-known/jwks.json` serves the hub's signing JWKS
- [ ] `HubProtocolMiddleware` rejects requests without
      `Accept-Phlex-Protocol: v1`
- [ ] `./vendor/bin/phpunit` — green; ≥ 20 new tests
- [ ] Coverage of `src/Hub/` ≥ 85 %
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean
- [ ] `docs/hub/admin/api.md` created with all 5 endpoints documented
- [ ] CHANGELOG entry added
- [ ] Git ritual §8 executed; postcondition checks PASS

## 8. Git ritual (copy of master plan §11.4, targeting hub repo)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex-hub
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b c.3-hub-registry

# ─── 2. Do the work ───
# (write all files in §3)

# ─── 3. Verify §0.4 minimum bar ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Hub|Middleware'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync (if hook exists) ───
git add -A

# ─── 5. Commit ───
git commit -m "Step C.3: hub server registry endpoints + JWKS + enrollment JWT"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step C.3: hub server registry endpoints and JWKS" \
  --body  "Implements hub-side /api/v1/server-claims/*, /api/v1/servers/{id}/*, /.well-known/jwks.json, enrollment JWT issuance, heartbeat handling, and server deregistration. Part of Phase C (Step C.3 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'c.3-*'                   # MUST be empty
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `c.3-hub-registry-review.md`.

Non-obvious points to verify:

1. **Claim code is single-use** — `server_claims` row is deleted
   atomically when claimed (not just marked claimed)
2. **Enrollment JWT is Ed25519-signed by the hub** (not RSA), matching
   the protocol spec
3. **`HubProtocolMiddleware` is on ALL server-claim and server routes**
   (not just some)
4. **Heartbeat updates `last_seen_at` AND `status = 'online'`**
