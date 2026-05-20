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
