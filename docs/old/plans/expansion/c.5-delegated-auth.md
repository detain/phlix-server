# Step C.5 — Delegated auth: hub issues server JWTs

**Phase:** C (Server↔Hub Pairing & Remote Access)
**Step:** C.5
**Depends on:** C.4
**Review:** Yes — see `c.5-delegated-auth-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Enable the hub to issue user-session JWTs that the server can validate
using the hub's JWKS. This allows clients to log into the hub once and
access any of their claimed servers without a server-specific login.

Concretely:

1. Server fetches and caches the hub's JWKS (from the `hub_jwks_url`
   stored at enrollment time)
2. Server exposes a middleware that validates hub-issued JWTs on
   requests destined for proxied/hub-mediated clients
3. When a client presents a hub JWT, the server validates it against the
   hub's JWKS and extracts `user_id` and `server_id` to authorize access

## 2. Context (what already exists)

- After C.2: server has `HubClient` with stored `hub_jwks_url` and
  `enrollment_jwt`
- After C.3: hub issues enrollment JWTs to claimed servers
- After C.4: hub can list a user's claimed servers
- `docs/dev/pairing-protocol.md` §7 and §8 — user-session JWT format
- `phlex-shared` v0.2.0: `Phlex\Shared\Auth\JwtClaims`
- `/home/sites/phlex/src/Auth/JwtHandler.php` — existing JWT handling
  (server will NOT use its own HS256 JwtHandler for hub JWTs; it will
  use a separate `HubJwtValidator`)
- `/home/sites/phlex/src/Server/Http/Router.php` — existing routing
- `/home/sites/phlex/src/Server/Http/Middleware/AuthMiddleware.php` —
  existing auth middleware pattern

## 3. Scope — files to create / modify

All paths inside `/home/sites/phlex/`.

### Create

#### Hub JWT Validator

- `src/Hub/HubJwtValidator.php` — validates hub-issued user-session JWTs:

  ```php
  final class HubJwtValidator
  {
      public function __construct(
          string $hubJwksUrl,
          HttpClientFactory $httpClientFactory,
          LoggerInterface $logger,
          int $cacheTtl = 900,
      ) { }

      public function validate(string $jwt): ?HubUserClaims { }
      public function refreshJwks(): void { }
  }
  ```

  Behavior:
  1. Extract `kid` from JWT header
  2. Check in-memory JWKS cache (keyed by `kid`); if miss, fetch from
     `$hubJwksUrl` and cache for `$cacheTtl` seconds
  3. Find the key matching `kid`; if not found, refetch JWKS once
     (handles key rotation)
  4. Verify Ed25519 signature using `sodium_crypto_sign_verify_detached`
  5. Validate `iss == 'phlex-hub'`, `aud == 'server'`,
     `server_id` matches the server's own ID
  6. Return `HubUserClaims` DTO on success; `null` on any validation
     failure

- `src/Hub/HubUserClaims.php` — extracted claims from hub JWT:

  ```php
  final class HubUserClaims
  {
      public function __construct(
          public readonly string $userId,
          public readonly string $serverId,
          public readonly string $subject,
          public readonly string $issuer,
          public readonly int $expiresAt,
          public readonly array $scope,
      ) { }

      public function isExpired(): bool { }
      public function hasScope(string $scope): bool { }
  }
  ```

- `src/Hub/HttpClientFactory.php` — creates HTTP clients; used by
  `HubJwtValidator` for JWKS fetching:

  ```php
  final class HttpClientFactory
  {
      public function create(string $baseUrl): HttpClient { }
  }
  ```

- `src/Hub/JwksCache.php` — in-memory JWKS cache:

  ```php
  final class JwksCache
  {
      public function __construct(int $ttl = 900) { }

      public function get(string $kid): ?array { }      // returns JWK or null
      public function set(string $kid, array $jwk): void { }
      public function invalidate(): void { }
      public function getAll(): array { }                // all cached keys
  }
  ```

#### Server-Side Hub Auth Middleware

- `src/Server/Http/Middleware/HubJwtMiddleware.php` — validates hub JWTs
  on routes that support hub-mediated access:

  ```php
  final class HubJwtMiddleware
  {
      public function __construct(
          HubJwtValidator $validator,
          string $serverId,
      ) { }

      public function handle(Request $request): Request { }
          // Adds $request->hubUser (HubUserClaims) on success
          // Returns 401 on failure
  }
  ```

  This middleware is applied **alongside** the existing
  `AuthMiddleware` on routes that support both server-direct login and
  hub-mediated login.

#### Hub-Enabled Token Endpoint

- `src/Server/Http/Controllers/HubTokenController.php` — accepts a hub
  JWT and exchanges it for a server-internal session:

  ```
  POST /api/v1/auth/hub-token
  Content-Type: application/json
  { "hub_token": "eyJ..." }

  Response 200: { "server_session_token": "..." }
  ```

  This lets older clients (that don't know about hub JWTs) work with
  the hub by exchanging the hub JWT for a server-issued session token.
  The server session token is a regular server JWT from `JwtHandler`.

#### Test Infrastructure

- `tests/unit/Hub/HubJwtValidatorTest.php` — mock `HttpClientFactory`,
  test valid JWT, invalid signature, expired, wrong issuer, wrong audience,
  unknown kid refetches JWKS
- `tests/unit/Hub/HubUserClaimsTest.php` — DTO smoke tests
- `tests/unit/Hub/JwksCacheTest.php` — cache hit, cache miss, TTL expiry,
  invalidate
- `tests/unit/Server/Http/Middleware/HubJwtMiddlewareTest.php`

#### Documentation

- `docs/dev/pairing-protocol.md` — already written in C.1; update §13
  to mark C.5 complete and add any protocol nuances discovered during
  implementation
- `docs/reference/api/hub-auth.yaml` — update to document `/api/v1/auth/hub-token`

### Modify

- `src/Server/Http/Router.php` — add:

  ```php
  // Hub JWT exchange endpoint
  $router->post('/api/v1/auth/hub-token', HubTokenController::class);
  ```

- `src/Server/Http/Middleware/AuthMiddleware.php` — extend to recognize
  `HubUserClaims` in `$request->hubUser` when `HubJwtMiddleware` ran first

- `src/Common/Container/ContainerFactory.php` — register `HubJwtValidator`,
  `HubUserClaims`, `JwksCache`, `HttpClientFactory`, `HubJwtMiddleware`,
  `HubTokenController`

- `CHANGELOG.md` entry

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master.
2. **Branch:** `git checkout -b c.5-delegated-auth`.
3. **Write `JwksCache`** — simple TTL cache with kid→JWK lookup.
4. **Write `HubUserClaims`** — DTO for extracted hub JWT claims.
5. **Write `HttpClientFactory`** — factory for HTTP clients (to avoid
   injecting the full `HubClient` dependency).
6. **Write `HubJwtValidator`** — JWKS fetch + Ed25519 signature
   verification using `sodium_crypto_sign_verify_detached`.
7. **Write `HubJwtMiddleware`** — request-level middleware that validates
   hub JWTs.
8. **Write `HubTokenController`** — hub JWT → server session exchange.
9. **Wire routes.**
10. **Write tests.**
11. **Verification bar.**
12. **Doc updates.**
13. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `HubJwtValidatorTest::test_valid_jwt_returns_claims`
2. `HubJwtValidatorTest::test_expired_jwt_returns_null`
3. `HubJwtValidatorTest::test_wrong_issuer_returns_null`
4. `HubJwtValidatorTest::test_wrong_audience_returns_null`
5. `HubJwtValidatorTest::test_wrong_server_id_returns_null`
6. `HubJwtValidatorTest::test_unknown_kid_fetches_jwks_and_retries`
7. `HubJwtValidatorTest::test_invalid_signature_returns_null`
8. `HubJwtValidatorTest::test_jwks_fetch_failure_returns_null`
9. `JwksCacheTest::test_get_returns_cached_jwk`
10. `JwksCacheTest::test_get_returns_null_on_miss`
11. `JwksCacheTest::test_invalidate_clears_cache`
12. `HubUserClaimsTest::test_isExpired_true_when_past`
13. `HubUserClaimsTest::test_isExpired_false_when_future`
14. `HubUserClaimsTest::test_hasScope`
15. `HubJwtMiddlewareTest::test_valid_hub_jwt_sets_hubUser`
16. `HubJwtMiddlewareTest::test_missing_token_returns_401`
17. `HubJwtMiddlewareTest::test_expired_token_returns_401`
18. `HubTokenControllerTest::test_valid_hub_token_returns_server_token`

**Coverage target:** `src/Hub/`, `HubJwtMiddleware` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Public HTTP/WS API** → `docs/reference/api/hub-auth.yaml` (update)
- **Hub functionality** → `docs/dev/pairing-protocol.md` (update)
- **User-visible behavior change** → CHANGELOG entry

PHPDoc per §0.4.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `HubJwtValidator` validates a correctly-signed hub JWT and returns
      `HubUserClaims`
- [ ] `HubJwtValidator` returns `null` for expired, wrong-issuer,
      wrong-audience, wrong-server-id, invalid-signature tokens
- [ ] `HubJwtValidator` refetches JWKS on unknown `kid` and retries once
- [ ] `HubJwtMiddleware` populates `$request->hubUser` on success
- [ ] `HubJwtMiddleware` returns `401` on failure
- [ ] `HubTokenController` exchanges a valid hub JWT for a server session
      token
- [ ] `./vendor/bin/phpunit` — green; ≥ 18 new tests
- [ ] Coverage of `src/Hub/` ≥ 85 %
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean
- [ ] `docs/dev/pairing-protocol.md` updated
- [ ] CHANGELOG entry added
- [ ] Git ritual §8 executed; postcondition checks PASS

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b c.5-delegated-auth

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Hub|Middleware'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step C.5: hub JWT validation for delegated auth"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step C.5: hub JWT validation for delegated auth" \
  --body  "Implements HubJwtValidator, HubJwtMiddleware, HubTokenController for hub-issued JWT validation and hub-token exchange. Part of Phase C (Step C.5 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'c.5-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `c.5-delegated-auth-review.md`.

Non-obvious point: The server **must not** use its existing HS256
`JwtHandler` for hub JWT validation — hub JWTs are signed with Ed25519
by the hub. A separate `HubJwtValidator` is required that uses
`sodium_crypto_sign_verify_detached`.
