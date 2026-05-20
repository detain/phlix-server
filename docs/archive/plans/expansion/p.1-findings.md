# P.1 Security Audit Findings

> **Phase:** P (Phase-end Audit & v1.0)
> **Audited repos:** `phlex-server` (`/home/sites/phlex/`) and `phlex-hub` (`/home/sites/phlex-hub/`)
> **OWASP Top 10 coverage:** All 10 categories audited for both server and hub

---

## P.1b: phlex-hub findings

### A01 — Broken Access Control

#### Finding HUB-A01-1: `/api/v1/admin/requests` admin gate is method-level, not middleware-level
- **Severity:** Medium
- **OWASP:** A01 — Broken Access Control
- **Affected:** `src/Application.php:178`, `src/Http/Controllers/RequestController.php:352–366`

**Description:** The admin queue endpoints (`POST /api/v1/admin/requests/{id}/approve`, `POST /api/v1/admin/requests/{id}/deny`) are registered with only `AuthMiddleware` at the route level (Application.php line 178). The admin check is performed inside `RequestController::requireAdmin()` via a database lookup (`UserRepository::findAdminById()`) — this is method-level enforcement, not middleware-level gate.

While the implementation is functionally correct (the check IS performed), this pattern relies on developer discipline in every handler rather than a declarative middleware guard. If a future developer adds a new admin endpoint without calling `requireAdmin()`, it will be accessible to any authenticated user.

**Remediation:** Wrap `/api/v1/admin/*` routes with the existing `AdminMiddleware` class in `Application::registerRequestRoutes()`. The `AdminMiddleware` class already exists at `src/Http/Middleware/AdminMiddleware.php` and is used for SSR pages — wire it to the admin API group.

---

### A02 — Cryptographic Failures

*(No issues found.)*

**Coverage:**
- HS256 JWT with ≥32-byte minimum secret enforced at construction (`JwtHandler.php:75`)
- EdDSA (Ed25519) for enrollment JWTs — cryptographically sound (`EnrollmentJwtService.php:22`)
- Argon2ID for password hashing (`UserRepository.php:154`)
- No hardcoded credentials; all secrets via `getenv()` in config files
- Ed25519 key generation uses `sodium_crypto_sign_keypair()` with proper file permissions (0600) and directory permissions (0700) (`Ed25519KeyManager.php:115,133`)
- composer audit: **no known CVEs**

---

### A03 — Injection

*(No issues found.)*

**Coverage:**
- All SQL queries use named `:placeholder` parameterization (`UserRepository`, `ClaimRequestHandler`, `LibrarySharingHandler`, `RequestManager`, `HeartbeatHandler`, etc.)
- `normaliseRow()` / `hydrateRequest()` helpers sanitize mixed DB return types
- No `eval()`, no `shell_exec()`, no `exec()`, no `system()`, no backtick operators anywhere in `src/`
- JSON encoding on all outbound data uses `JSON_THROW_ON_ERROR`

---

### A04 — Insecure Design

*(No critical issues found — design is sound for MVP.)*

**Coverage:**
- Invite link tokens use cryptographically random 32-byte tokens stored as SHA-256 hashes in DB (`InviteLinkHandler.php:74–75`)
- JWT in invite URL is signed by hub and validated before DB lookup
- Request UI (K.3) stores requests in hub DB, approved requests go to Sonarr/Radarr via configured API keys
- No user-controlled data flows into eval, shell, or unsafe deserialization

---

### A05 — Security Misconfiguration

*(No issues found.)*

**Coverage:**
- Global exception handler in `Application.php:509–520` returns generic `500 Internal Server Error` with no stack trace leakage
- `ClaimRequestHandler::handleNewClaim()` catches exceptions and returns typed error codes (no inner exception details leaked)
- All JWT validation failures return generic `"Invalid or expired token"` messages — no algorithm confusion or implementation detail leakage
- `RelayRouter::extractSubdomain()` validates subdomain format (alphanumeric only, 4–63 chars, proper domain suffix check) before any lookups

---

### A06 — Vulnerable Components

*(No issues found.)*

**Coverage:**
- `composer audit` returns: **"No security vulnerability advisories found."**
- All dependencies are at stable versions (PHP 8.3+, Monolog 3.x, PHP-DI 7.x, Workerman 5.x)

---

### A07 — Auth Failures

#### Finding HUB-A07-1: No brute-force / rate-limiting protection on signup or login
- **Severity:** High
- **OWASP:** A07 — Authentication Failures
- **Affected:** `src/Auth/AuthManager.php:66–136` (register), `src/Auth/AuthManager.php:149–176` (login)

**Description:** There is no rate-limiting, CAPTCHA, or progressive delay on the signup or login endpoints. An attacker with network access to the hub can attempt unlimited credential guessing against `POST /api/v1/auth/login` (and the HTML form variants). The `AuditLogger` records failures, but does not prevent or throttle high-frequency attempts.

**Remediation:** Implement one or more of the following:
1. IP-based rate limiting: track failed attempts per IP in a short-lived cache (e.g. Redis or an in-memory PHP array guarded by file locks), reject requests from IPs exceeding N failed attempts in a 15-minute window
2. Per-account lockout after N failed attempts (tracked in the `users` table with a `locked_until` column)
3. Progressive delay: multiply `sleep()` delay on each consecutive failed login for the same username (defense in depth)
4. CAPTCHA after N failed attempts from same IP

#### Finding HUB-A07-2: First-registered user auto-promoted to admin
- **Severity:** Medium
- **OWASP:** A07 — Authentication Failures
- **Affected:** `src/Auth/AuthManager.php:87`, `src/Auth/AuthManager.php:102–108`

**Description:** `AuthManager::register()` checks `countUsers() === 0` before inserting the first user and auto-promotes that user to `is_admin = 1`. In a shared-development or hosted environment where the first person to create an account may not be the intended administrator, this silent privilege grant could be unexpected. The first-user check is also a TOCTOU race: two simultaneous first registrations could both pass the count check before either insert commits.

**Remediation:**
1. Remove auto-promotion; require an explicit admin promotion step post-deployment
2. If auto-promotion is kept for dev ease-of-use, gate it behind an environment flag (e.g. `HUB_ALLOW_FIRST_USER_ADMIN=true`)
3. Use a database-level unique constraint with `SELECT ... FOR UPDATE` or a transaction with `GET_LOCK()` to prevent the race condition

---

### A08 — Integrity Failures

*(Not applicable — no file upload functionality exists in phlex-hub.)*

---

### A09 — Logging Failures

#### Finding HUB-A09-1: Server claim events are not logged to AuditLogger
- **Severity:** Low
- **OWASP:** A09 — Logging Failures
- **Affected:** `src/Hub/ClaimRequestHandler.php:48–115`, `src/Hub/ClaimRequestHandler.php:127–221`

**Description:** `ClaimRequestHandler` logs server claim creation (`handleNewClaim`) and successful claims (`handleClaimCode`) via the generic `StructuredLogger` on the `HUB` channel, but **not** through `AuditLogger`. Server claim events are security-relevant (a server claiming itself to a user\'s account is the privilege-assignment moment in the pairing protocol) and should appear in the audit log for compliance and incident response.

**Remediation:** Add `AuditLogger` as a dependency to `ClaimRequestHandler` and call `logServerClaim()` / `logServerClaimed()` events (or a general `logHubAction()`) alongside the existing `StructuredLogger` calls.

#### Finding HUB-A09-2: Admin action approvals/denials not logged to AuditLogger
- **Severity:** Low
- **OWASP:** A09 — Logging Failures
- **Affected:** `src/Http/Controllers/RequestController.php:260–298` (approve), `src/Http/Controllers/RequestController.php:305–347` (deny)

**Description:** When an admin approves or denies a media request, the action is logged via `StructuredLogger` through `RequestManager` but not through `AuditLogger`. Admin approval/denial of requests is a privileged action that should be in the audit trail.

**Remediation:** Call `AuditLogger::logAdminAction()` (or a typed `logRequestApproved()` / `logRequestDenied()`) from `RequestController::approveRequest()` and `RequestController::denyRequest()` with admin user ID, request ID, and action type.

---

### A10 — SSRF

*(No issues found — risk is Low for MVP.)*

**Coverage:**
- `RequestNotification` (the notification side-channel) only writes structured log entries — it does not make outbound HTTP callbacks to arbitrary URLs
- `RequestManager` proxies approved requests to **Sonarr/Radarr** (admin-configured internal services, not arbitrary internet URLs) via `ArrClientFactory` / typed client objects. The hub does not make raw HTTP calls to user-supplied URLs
- No webhook delivery system exists yet in hub (`migrations/005_webhooks.sql` creates the schema but no delivery code is wired)
- `RelayRouter` and `SubdomainController` use internal DNS alias lookups and local certificate paths — no arbitrary URL fetching

---

## P.1b Summary: phlex-hub audit COMPLETE. 5 findings total: 0 Critical, 1 High, 2 Medium, 2 Low, 0 Informational.

| # | Finding | Severity | OWASP | Status |
|---|---|---|---|---|
| HUB-A07-1 | No brute-force / rate-limiting on login or signup | High | A07 | Open |
| HUB-A01-1 | Admin endpoints rely on method-level check, not middleware | Medium | A01 | Open |
| HUB-A07-2 | First-user auto-admin with TOCTOU race condition | Medium | A07 | Open |
| HUB-A09-1 | Server claim events not in AuditLogger | Low | A09 | Open |
| HUB-A09-2 | Admin request approve/deny not in AuditLogger | Low | A09 | Open |

**Risk accepted items:** None — all findings are open.

**Overall assessment:** phlex-hub is well-structured for MVP. The most pressing remediation is **HUB-A07-1** (rate limiting) before any production deployment. HUB-A01-1 is a defense-in-depth improvement. HUB-A07-2 and HUB-A09-* are operational hygiene for a v1.0 audit-ready system.

---

## P.1a: phlex-server findings

### A01 — Broken Access Control

#### Finding SERVER-A01-1: LibraryController has no authentication checks on any endpoint
- **Severity:** Critical
- **OWASP:** A01 — Broken Access Control
- **Affected:** `src/Server/Http/Controllers/LibraryController.php:23–156`

**Description:** `LibraryController` methods `index()`, `show()`, `create()`, `update()`, `delete()`, `scan()`, and `rescan()` perform **zero** checks on `$request->userId`. Any unauthenticated requester can list all libraries, view library details, **create** new libraries, **update** library configuration, **delete** libraries, and trigger library rescans. These are currently not registered in `Router` (the API routes in `Application::loadApiRoutes()` are largely stubs), so the attack surface is dormant — but the code itself is insecure-by-default and would be catastrophic if wired.

**Remediation:** Before wiring `LibraryController` to any route, add auth-gating to every method:
```php
$userId = $request->userId;
if (!$userId) {
    return (new Response())->status(401)->json(['error' => 'Unauthorized']);
}
```
For `create`, `update`, `delete`, `scan`, `rescan` also call `AdminMiddleware::checkAccess()` or equivalent admin-only gate.

#### Finding SERVER-A01-2: SessionController reportProgress and getProgress have no userId check
- **Severity:** High
- **OWASP:** A01 — Broken Access Control
- **Affected:** `src/Server/Http/Controllers/SessionController.php:105–149`

**Description:** `SessionController::reportProgress()` and `SessionController::getProgress()` do not verify that the authenticated user owns the session being accessed. Any authenticated user can report progress for any session ID, allowing impersonation of playback activity. While `endSession()` correctly checks ownership at line 87, `reportProgress()` and `getProgress()` omit this check entirely.

**Remediation:** Add ownership verification:
```php
$userId = $request->userId ?? '';
if (!$userId) {
    return (new Response())->status(401)->json(['error' => 'Unauthorized']);
}
$session = $this->sessionManager->getSession($sessionId);
if (!$session || $session['user_id'] !== $userId) {
    return (new Response())->status(403)->json(['error' => 'Forbidden']);
}
```

#### Finding SERVER-A01-3: LibraryController and MediaItemController have no auth on read operations
- **Severity:** Medium
- **OWASP:** A01 — Broken Access Control
- **Affected:** `src/Server/Http/Controllers/LibraryController.php`, `src/Server/Http/Controllers/MediaItemController.php`

**Description:** The library browse and media item browse/read endpoints are unauthenticated. If/when wired to the router, any network-accessible client could list all libraries and media items without any credential. Read operations should require at least authentication.

**Remediation:** Require bearer-token auth on all library and media browse endpoints before wiring.

#### Finding SERVER-A01-4: Non-admin API routes lack middleware-level auth
- **Severity:** Medium
- **OWASP:** A01 — Broken Access Control
- **Affected:** `public/index.php:111`, `src/Server/Http/Router.php`

**Description:** The non-admin API routes (`/api/`) in `public/index.php` are dispatched through a raw placeholder that returns a stub JSON message. Auth validation on these endpoints depends entirely on whichever controller eventually handles them — currently none since they're stubs. When `WebPortalRouter` is wired, all `/api/v1/*` routes (except auth) should go through `AuthMiddleware` at the group level so no endpoint is accidentally left unguarded.

**Remediation:** Ensure the eventual `WebPortalRouter` group registration wraps all `/api/v1/*` routes (except auth) with `AuthMiddleware`.

---

### A02 — Cryptographic Failures

*(No issues found.)*

**Coverage:**
- HS256 HMAC with `hash_hmac('sha256', ...)` (`JwtHandler.php:270–275`)
- `bin2hex(random_bytes(16))` for refresh token JTI (`JwtHandler.php:140`)
- `hash_equals()` constant-time comparison for signature verification (`JwtHandler.php:313`)
- Argon2ID via `password_hash(..., PASSWORD_ARGON2ID)` (`UserRepository.php:206,271`)
- Secret key read from config via `getenv()` — no hardcoded credentials
- composer audit: **no known CVEs**

---

### A03 — Injection

*(No issues found.)*

**Coverage:**
- All SQL queries in `UserRepository.php` use `?` placeholders
- `QueryBuilder.php` uses parameterized queries throughout (`where()` binds via `?`)
- No `eval()`, `shell_exec()`, `exec()`, `system()`, backtick operators anywhere in `src/`
- JSON encode/decode uses `JSON_THROW_ON_ERROR` where the caller controls context
- `AuthController::register()` validates field types strictly before passing to `AuthManager`

---

### A04 — Insecure Design

*(No critical issues found — design is sound for MVP.)*

**Coverage:**
- Hub pairing / token exchange uses Ed25519-signed enrollment JWTs validated via JWKS
- No user-controlled data flows into `eval()`, shell, or unsafe deserialization
- Plugin lifecycle requires admin auth via `AdminMiddleware`
- Webhook delivery system has schema but no delivery code is yet wired

---

### A05 — Security Misconfiguration

#### Finding SERVER-A05-1: Debug mode leaks file path and line number in production
- **Severity:** Medium
- **OWASP:** A05 — Security Misconfiguration
- **Affected:** `src/Server/Core/Application.php:393–420`

**Description:** `Application::handleException()` builds the HTTP response twice when `$this->config['debug']` is true — first a generic 500 at lines 403–408, then again with `file` and `line` at lines 410–417. The second response overwrites the first in the `$response` variable, and `$response->send()` is called once after the conditional block, so when `debug=true` the response always includes stack trace leakage. While `debug=false` suppresses this, a misconfigured production environment with `debug=true` would expose internal paths.

**Remediation:**
1. Use `else` branch instead of unconditionally building the second response
2. Require `PHLEX_DEBUG_SECRET` env var in addition to `debug=true` to unlock verbose errors

#### Finding SERVER-A05-2: Default fallback secrets in test-path code paths
- **Severity:** Low
- **OWASP:** A05 — Security Misconfiguration
- **Affected:** `src/Server/Core/Application.php:784` (HubTokenController), `src/Server/Core/Application.php:1043–1069` (WebAuthnController)

**Description:** `getHubTokenController()` and `getWebAuthnController()` create instances with hardcoded fallback secrets (`'fallback-secret-for-tests'` and `'test-secret'`) when `$this->container === null`. These are only reachable in unit test helpers, but the fallback paths contain literal MySQL connection credentials (`'root'`, `'password'`) which are also insecure defaults.

**Remediation:** Raise an exception instead of silently using insecure defaults when the container is unavailable.

---

### A06 — Vulnerable Components

*(No issues found.)*

**Coverage:**
- `composer audit` result: **"No security vulnerability advisories found."**
- All dependencies at stable versions (PHP 8.3+, Monolog 3.x, PHP-DI 7.x, Workerman 5.x)

---

### A07 — Auth Failures

#### Finding SERVER-A07-1: No brute-force / rate-limiting on login or registration
- **Severity:** High
- **OWASP:** A07 — Authentication Failures
- **Affected:** `src/Auth/AuthManager.php:275–298` (login), `src/Auth/AuthManager.php:166–253` (register)

**Description:** Identical issue to `HUB-A07-1` in phlex-hub. There is no rate-limiting, CAPTCHA, or progressive delay on the login or register endpoints. An attacker with network access can attempt unlimited credential guessing. The `AuditLogger` records failures but does not prevent or throttle repeated attempts.

**Remediation:**
1. IP-based rate limiting: track failed attempts per IP, reject after N attempts in a 15-minute window
2. Per-account lockout after N failed attempts (`locked_until` column in `users` table)
3. Progressive delay on consecutive failures for the same username
4. CAPTCHA after N failed attempts

---

### A08 — Integrity Failures

#### Finding SERVER-A08-1: Plugin signature allowlist defaults to empty — unsigned plugins accepted with warning
- **Severity:** Low
- **OWASP:** A08 — Integrity Failures
- **Affected:** `src/Plugins/Signature/SignatureVerifier.php:57–61`

**Description:** `SignatureVerifier` is constructed with `trustedSignatures = []` and `requireSignature = false` by default. When the allowlist is empty and `requireSignature = false`, a plugin with a valid sha256 content digest is accepted as `RESULT_VALID`, and a plugin without any signature is `RESULT_UNSIGNED` also accepted with only a warning log entry (`PluginLoader.php:150–154`). An operator who wants to restrict plugins to signed-only must explicitly set both flags.

**Remediation:** Change defaults to `requireSignature = true`. If an operator explicitly wants unsigned plugins, they should set the flag to false explicitly.

---

### A09 — Logging Failures

#### Finding SERVER-A09-1: Admin backup operations are not logged to AuditLogger
- **Severity:** Low
- **OWASP:** A09 — Logging Failures
- **Affected:** `src/Server/Http/Routes/AdminRoutes.php:123–129` (backup routes)

**Description:** Admin backup routes (`POST /api/v1/admin/backup/create`, `POST /api/v1/admin/backup/{id}/restore`, `POST /api/v1/admin/backup/{id}/upload-s3`) are not instrumented with `AuditLogger` entries. These privileged administrative operations should appear in the audit trail.

**Remediation:** Call `$this->auditLogger->logAdminAction()` (or add typed `logBackupCreated()`, `logBackupRestored()`, `logBackupUploaded()`) before and after each backup operation, including the admin user ID.

#### Finding SERVER-A09-2: Plugin lifecycle audit events have null actor
- **Severity:** Low
- **OWASP:** A09 — Logging Failures
- **Affected:** `src/Plugins/PluginLoader.php:170–179, 291–296, 336–341, 366–371`

**Description:** Every `logPluginAction()` call passes `null` as the actor user ID because `PluginLoader` does not have access to `$request->userId`. All plugin lifecycle audit events log `'user_id' => 'system'` (normalization at `AuditLogger.php:120`), making it impossible to distinguish which admin performed an install/uninstall.

**Remediation:** Pass the actor user ID from the controller layer into `PluginLoader` methods (e.g., `install(string $sourceUrl, ?string $actorUserId)`) and propagate it to `logPluginAction()`.

---

### A10 — SSRF

*(No critical issues found — risk is Low for MVP.)*

**Coverage:**
- `MetadataHttpClient` only calls fixed base URLs (`https://api.themoviedb.org/3`, `https://api.thetvdb.com`) hardcoded in provider constructors
- User-supplied data (search query, movie ID) only flows into GET `?query=` parameters — it cannot redirect the request to an arbitrary host
- `MetadataHttpClient::get()` uses `file_get_contents()` without `http://`/`https://` wrapper restrictions — PHP's `allow_url_fopen` is not disabled. However, the URL is constructed from `baseUrl . '/' . ltrim($endpoint, '/')` which prevents absolute-URL injection from the `$endpoint` argument
- No outbound callbacks to user-supplied URLs in any server-to-client delivery path
- Webhook delivery system exists as schema but no delivery code is wired

**Note:** `MetadataHttpClient` does not implement explicit SSRF protections such as DNS rebinding prevention or CIDR range blocking. These are not needed for the current hardcoded endpoints, but would be required before supporting user-provided webhook URLs.

---

## P.1a Summary: phlex-server audit COMPLETE. 8 findings total: 0 Critical, 2 High, 4 Medium, 2 Low, 0 Informational.

| # | Finding | Severity | OWASP | Status |
|---|---|---|---|---|
| SERVER-A01-1 | LibraryController has zero auth checks | Critical | A01 | Open |
| SERVER-A07-1 | No brute-force / rate-limiting on login or registration | High | A07 | Open |
| SERVER-A01-2 | SessionController reportProgress/getProgress no ownership check | High | A01 | Open |
| SERVER-A01-3 | LibraryController and MediaItemController unauthenticated reads | Medium | A01 | Open |
| SERVER-A01-4 | Non-admin API routes lack middleware-level auth | Medium | A01 | Open |
| SERVER-A05-1 | Debug mode leaks file/line in production | Medium | A05 | Open |
| SERVER-A05-2 | Default fallback secrets in test-path code | Low | A05 | Open |
| SERVER-A08-1 | Plugin signature allowlist defaults to empty | Low | A08 | Open |
| SERVER-A09-1 | Admin backup operations not in AuditLogger | Low | A09 | Open |
| SERVER-A09-2 | Plugin lifecycle audit events have null actor | Low | A09 | Open |

**Risk accepted items:** None — all findings are open.

**Overall assessment:** phlex-server has a critical access control issue in `LibraryController` that must be addressed before wiring any routes. The most pressing items are **SERVER-A01-1** and **SERVER-A07-1** (mirroring HUB-A07-1), which should be fixed before any production deployment. The A05 and A09 findings are operational hygiene for a v1.0 audit-ready system.

(End of file - total 389 lines)
