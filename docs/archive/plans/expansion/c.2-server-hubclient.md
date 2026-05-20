# Step C.2 — Server-side: HubClient

**Phase:** C (Server↔Hub Pairing & Remote Access)
**Step:** C.2
**Depends on:** C.1
**Review:** Yes — see `c.2-server-hubclient-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Build `Phlex\Hub\HubClient` — the server-side component that:

1. Generates an Ed25519 keypair on first boot (or loads existing)
2. Sends a claim request to the hub and receives a claim code
3. Displays the claim code to the operator
4. Stores the enrollment JWT + hub JWKS URL after successful claim
5. Runs a heartbeat loop every 60 seconds
6. Exposes `/.well-known/jwks.json` with the server's public key
7. Handles re-enrollment when the enrollment JWT expires

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §6 — the pairing protocol overview
- `docs/dev/pairing-protocol.md` — full protocol spec (C.1 output)
- `phlex-shared` v0.2.0: `Phlex\Shared\Hub\ClaimRequest`,
  `Phlex\Shared\Hub\ClaimResponse`, `Phlex\Shared\Hub\HeartbeatDto`
- `/home/sites/phlex/src/Common/Database/ConnectionPool.php`
- `/home/sites/phlex/src/Common/Logger/LoggerFactory.php`
- `/home/sites/phlex/src/Server/Http/Router.php` — existing routing patterns
- `/home/sites/phlex/src/Auth/JwtHandler.php` — existing JWT patterns
  (the server-side HubClient will NOT use the server's own JwtHandler for
  hub communication — it uses raw `sodium_crypto_sign_*` for the Ed25519
  keypair and the enrollment JWT for auth)
- `/home/sites/phlex/config/` — existing config structure
- `tests/Unit/Auth/JwtHandlerTest.php` — mock patterns for testing

## 3. Scope — files to create / modify

All paths inside `/home/sites/phlex/`.

### Create

#### Key Management

- `src/Hub/Ed25519KeyManager.php` — generates, stores, loads, rotates
  Ed25519 keypairs. Interface:

  ```php
  final class Ed25519KeyManager
  {
      public function __construct(string $keyPath) { }

      public function getOrCreateKeyPair(): KeyPair { }  // {privateKey, publicKey} as raw bytes
      public function getPublicKeyJwk(string $kid): array { }  // JWK map for JWKS
      public function getKid(): string { }  // current key ID (ISO 8601)
      public function rotate(): void { }  // generate new key, keep old for overlap
      public function getCurrentPrivateKey(): string { }  // raw 32-byte secret
  }
  ```

  Key file: `config/hub-server-key.pem` (mode 0600). PEM format:
  `-----BEGIN ED25519 PRIVATE KEY-----\n<base64>\n-----END ED25519 PRIVATE KEY-----`.

- `src/Hub/KeyPair.php` — value object:

  ```php
  final class KeyPair
  {
      public function __construct(
          public readonly string $secretKey,  // 32-byte raw
          public readonly string $publicKey,    // 32-byte raw
      ) { }
  }
  ```

#### Hub Client

- `src/Hub/HubClient.php` — main orchestrator:

  ```php
  final class HubClient
  {
      public function __construct(
          Ed25519KeyManager $keyManager,
          HttpClient $httpClient,
          LoggerInterface $logger,
          string $configDir,
      ) { }

      // Phase 1: pairing
      public function initiatePairing(string $hubUrl, string $serverName, string $version): ClaimInitiateResult { }
      public function pollClaimStatus(string $claimId): ClaimStatusResult { }
      public function storeEnrollment(string $enrollmentJwt, string $hubJwksUrl, string $serverId, string $hubBaseUrl): void { }
      public function loadEnrollment(): ?StoredEnrollment { }

      // Phase 2: heartbeat
      public function startHeartbeatLoop(): void { }    // runs every 60s
      public function stopHeartbeatLoop(): void { }
      public function sendHeartbeat(): HeartbeatResult { }

      // Phase 3: JWKS
      public function getPublicKeysJwk(): array { }  // for /.well-known/jwks.json

      // Phase 4: re-enrollment
      public function reEnrollIfNeeded(): bool { }  // called before heartbeat; returns true if re-enrolled
  }
  ```

  **Stored enrollment** is at `config/hub-enrollment.json` (mode 0600):
  ```json
  {
    "enrollment_jwt": "eyJ...",
    "hub_jwks_url": "https://hub.example.com/.well-known/jwks.json",
    "server_id": "uuid",
    "hub_base_url": "https://hub.example.com",
    "enrolled_at": 1747430400
  }
  ```

- `src/Hub/HttpClient.php` — HTTP client for hub communication:

  ```php
  final class HttpClient
  {
      public function __construct(string $baseUrl, ?string $bearerToken = null) { }
      public function get(string $path, array $headers = []): HttpResponse { }
      public function post(string $path, array $body, array $headers = []): HttpResponse { }
  }
  ```

- `src/Hub/HttpResponse.php` — response wrapper:

  ```php
  final class HttpResponse
  {
      public function __construct(
          public readonly int $statusCode,
          public readonly array $headers,
          public readonly array $body,
      ) { }

      public function isSuccess(): bool { }
      public function getErrorCode(): ?string { }
  }
  ```

- `src/Hub/ClaimInitiateResult.php` — result DTO:

  ```php
  final class ClaimInitiateResult
  {
      public function __construct(
          public readonly string $claimCode,
          public readonly int $expiresIn,
          public readonly string $claimId,
          public readonly string $hubBaseUrl,
      ) { }
  }
  ```

- `src/Hub/ClaimStatusResult.php` — polling result:

  ```php
  final class ClaimStatusResult
  {
      public const STATUS_PENDING = 'pending';
      public const STATUS_CLAIMED = 'claimed';
      public const STATUS_EXPIRED  = 'expired';

      public function __construct(
          public readonly string $status,
          public readonly ?string $enrollmentJwt = null,
          public readonly ?string $hubJwksUrl = null,
          public readonly ?string $serverId = null,
      ) { }
  }
  ```

- `src/Hub/HeartbeatResult.php` — heartbeat result:

  ```php
  final class HeartbeatResult
  {
      public function __construct(
          public readonly bool $ok,
          public readonly ?string $error = null,
          public readonly ?string $errorCode = null,
      ) { }
  }
  ```

- `src/Hub/StoredEnrollment.php` — loaded enrollment DTO:

  ```php
  final class StoredEnrollment
  {
      public function __construct(
          public readonly string $enrollmentJwt,
          public readonly string $hubJwksUrl,
          public readonly string $serverId,
          public readonly string $hubBaseUrl,
          public readonly int $enrolledAt,
      ) { }

      public function isExpired(): bool { }
  }
  ```

#### JWKS Endpoint

- `src/Server/Http/Controllers/HubJwksController.php` — serves
  `GET /.well-known/jwks.json`:

  ```php
  final class HubJwksController
  {
      public function __construct(HubClient $hubClient) { }
      public function handle(Request $request): Response { }
  }
  ```

  Returns:
  ```json
  { "keys": [{ "kty": "OKP", "crv": "Ed25519", "x": "...", "kid": "...", "use": "sig", "alg": "EdDSA" }] }
  ```

#### Pairing CLI Command

- `scripts/pair-with-hub.php` — CLI that triggers pairing:

  ```
  php scripts/pair-with-hub.php <hub-url> <server-name>

  # Example:
  php scripts/pair-with-hub.php https://hub.example.com "Alice's NAS"

  # Output:
  # Pairing initiated. Claim code: ABCD-1234
  # Enter this code at https://hub.example.com/claim-server
  # Waiting for claim... (press Ctrl+C to cancel)
  # Claimed! Server ID: 550e8400-e29b-41d4-a716-446655440000
  # Enrollment stored. Heartbeat loop started.
  ```

  The script:
  1. Calls `HubClient::initiatePairing()`
  2. Displays the claim code
  3. Polls `HubClient::pollClaimStatus()` every 2 seconds
  4. On `STATUS_CLAIMED`, calls `HubClient::storeEnrollment()`
  5. Starts the heartbeat loop

#### Unit Tests

- `tests/Unit/Hub/Ed25519KeyManagerTest.php` — generate key, store,
  load, rotate; invalid PEM file throws
- `tests/Unit/Hub/HubClientTest.php` — mock key manager + http client;
  test initiatePairing, pollClaimStatus, storeEnrollment, loadEnrollment,
  sendHeartbeat, reEnrollIfNeeded
- `tests/Unit/Hub/HttpClientTest.php` — GET/POST with headers, JSON
  round-trip, error handling
- `tests/Unit/Hub/ClaimInitiateResultTest.php`,
  `tests/Unit/Hub/ClaimStatusResultTest.php`,
  `tests/Unit/Hub/HeartbeatResultTest.php`,
  `tests/Unit/Hub/StoredEnrollmentTest.php` — DTO smoke tests
- `tests/Unit/Server/Http/Controllers/HubJwksControllerTest.php` —
  serves correct JWKS document

#### Documentation

- `docs/dev/pairing-protocol.md` — already written in C.1; update
  §13 cross-reference to mark C.2 complete
- `docs/reference/cli.md` — add `scripts/pair-with-hub.php` entry
- `docs/reference/api/hub-jwks.yaml` — OpenAPI for `/.well-known/jwks.json`

### Modify

- `src/Server/Http/Router.php` — add `GET /.well-known/jwks.json`
  route pointing to `HubJwksController`
- `src/Server/Core/Application.php` — wire `HubClient` into the
  container; call `HubClient::startHeartbeatLoop()` on startup if
  `config/hub-enrollment.json` exists (i.e., already paired)
- `src/Common/Container/ContainerFactory.php` — register
  `Ed25519KeyManager`, `HubClient`, `HubJwksController`
- `config/hub.php` — new config file:

  ```php
  <?php
  return [
      'hub_url'          => getenv('PHLEX_HUB_URL') ?: null,
      'heartbeat_interval' => (int)(getenv('PHLEX_HEARTBEAT_INTERVAL') ?: 60),
      'enrollment_token_ttl' => 7 * 86400,  // 7 days in seconds
      'jwks_cache_ttl'   => 900,           // 15 minutes
  ];
  ```

- `composer.json` — add `phlex-shared: ^0.2` (if not already from B.3)
- `CHANGELOG.md` — C.2 entry

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master.
2. **Branch:** `git checkout -b c.2-server-hubclient`.
3. **Write `Ed25519KeyManager`** — uses `sodium_crypto_sign_keypair()`.
   Generates auto on first run. Key ID is ISO 8601 of first-use date.
4. **Write DTOs** — all four result DTOs plus `StoredEnrollment`. Simple
   readonly classes.
5. **Write `HttpClient`** — thin wrapper around `curl_*`. Always sends
   `Accept-Phlex-Protocol: v1` header.
6. **Write `HubClient`** — orchestrates all four phases. Stores
   enrollment to `config/hub-enrollment.json`. Heartbeat loop uses
   `setInterval` (Workerman timer).
7. **Wire `HubJwksController`** into the router at
   `GET /.well-known/jwks.json`.
8. **Write `scripts/pair-with-hub.php`** — polling CLI script.
9. **Write tests** — mock `Workerman\MySQL\Connection` patterns not
   needed here (no DB); mock `HttpClient` for network layer.
10. **Verification bar.**
11. **Doc updates.**
12. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `Ed25519KeyManagerTest::test_generates_keypair_when_not_exists`
2. `Ed25519KeyManagerTest::test_loads_existing_keypair`
3. `Ed25519KeyManagerTest::test_rotate_keeps_old_key`
4. `Ed25519KeyManagerTest::test_getPublicKeyJwk_returns_valid_structure`
5. `Ed25519KeyManagerTest::test_invalid_pem_throws`
6. `HubClientTest::test_initiatePairing_returns_claim_code_and_id`
7. `HubClientTest::test_pollClaimStatus_pending_when_not_yet_claimed`
8. `HubClientTest::test_pollClaimStatus_claimed_stores_enrollment`
9. `HubClientTest::test_pollClaimStatus_expired_returns_expired_status`
10. `HubClientTest::test_storeEnrollment_writes_json_file`
11. `HubClientTest::test_loadEnrollment_returns_null_when_not_enrolled`
12. `HubClientTest::test_loadEnrollment_returns_stored_enrollment`
13. `HubClientTest::test_sendHeartbeat_success`
14. `HubClientTest::test_sendHeartbeat_unauthorized_re_enrolls`
15. `HubClientTest::test_reEnrollIfNeeded_noops_when_not_expired`
16. `HubClientTest::test_reEnrollIfNeeded_re_enrolls_when_expired`
17. `HttpClientTest::test_get_sends_correct_headers`
18. `HttpClientTest::test_post_sends_json_body`
19. `HttpClientTest::test_non_2xx_returns_error_body`
20. `HubJwksControllerTest::test_returns_jwks_json_with_valid_structure`

**Coverage target:** `src/Hub/` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Public HTTP/WS API** → `docs/reference/api/hub-jwks.yaml`
- **Hub functionality** → `docs/dev/pairing-protocol.md` (already
  written in C.1; update §13 cross-reference)
- **A CLI command** → `docs/reference/cli.md`
- **User-visible behavior change** → CHANGELOG.md entry

PHPDoc per §0.4 on every new public class/method.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `Ed25519KeyManager` generates and loads Ed25519 keypairs correctly
- [ ] `HubClient::initiatePairing()` sends correct payload to hub and
      returns `ClaimInitiateResult` with claim code
- [ ] `HubClient::pollClaimStatus()` correctly distinguishes
      `pending` / `claimed` / `expired`
- [ ] `HubClient::storeEnrollment()` writes valid `hub-enrollment.json`
- [ ] `HubClient::loadEnrollment()` returns `null` when not enrolled,
      `StoredEnrollment` when enrolled
- [ ] `HubClient::sendHeartbeat()` sends correct payload with all
      required fields and returns `HeartbeatResult`
- [ ] `HubClient::reEnrollIfNeeded()` returns `false` when not expired,
      triggers re-enrollment when expired
- [ ] `HubJwksController` serves `/.well-known/jwks.json` with correct
      Ed25519 JWK structure
- [ ] `scripts/pair-with-hub.php` works end-to-end (or its unit tests
      mock the full hub interaction)
- [ ] `./vendor/bin/phpunit` — green; ≥ 20 new tests
- [ ] Coverage of `src/Hub/` ≥ 85 %
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors
- [ ] `docs/reference/cli.md` updated
- [ ] `docs/reference/api/hub-jwks.yaml` created
- [ ] CHANGELOG.md entry added
- [ ] Caliber pre-commit hook verified active
- [ ] Git ritual §8 executed; postcondition checks PASS

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b c.2-server-hubclient

# ─── 2. Do the work ───
# (write all files in §3)

# ─── 3. Verify §0.4 minimum bar ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Hub|Controllers'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step C.2: add HubClient for server↔hub pairing and heartbeat"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step C.2: server-side HubClient for pairing and heartbeat" \
  --body  "Implements Phlex\\Hub\\HubClient — Ed25519 key management, claim initiation/polling, enrollment storage, heartbeat loop, and /.well-known/jwks.json endpoint. Part of Phase C (Step C.2 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'c.2-*'                   # MUST be empty
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `c.2-server-hubclient-review.md`.

Non-obvious points to verify:

1. **Key is auto-generated on first boot** — no manual key creation step
   required; operator just runs `php scripts/pair-with-hub.php`
2. **`HubClient::reEnrollIfNeeded()` is called before every heartbeat**
   to handle enrollment expiry gracefully
3. **JWKS endpoint is at `/.well-known/jwks.json`** (not `/api/v1/jwks`)
