# Step D.4 — Passkeys / WebAuthn

**Phase:** D (Hub-grade Auth: SSO / OIDC / LDAP / Passkeys)
**Step:** D.4
**Depends on:** D.1
**Review:** Yes — see `d.4-webauthn-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Add first-class passkey (WebAuthn) support to the server. Users can
register a passkey (platform authenticator or roaming FIDO2 token)
and use it to log in instead of a password. The implementation is
server-side only — no browser JavaScript is introduced (passkey
challenges are issued and verified in PHP via the server's existing
auth flows). Users manage passkeys in their account settings page.

## 2. Context (what already exists)

- After D.1: `ProviderInterface` exists; user records have
  `provider='webauthn'` rows via `external_id = 'webauthn:{credentialId}'`.
- After Phase A: Plugin lifecycle, events, and settings infrastructure.
- After Phase C: Hub JWT delegation in place; passkeys work alongside
  hub-issued tokens.
- No WebAuthn library present; use `web-auth/webauthn-lib: ^4.0`
  (the most complete pure-PHP WebAuthn implementation; supports FIDO2
  RS256/ES256/EdDSA attestation formats).

## 3. Scope — files to create / modify

### Create

#### Core WebAuthn classes

- `src/Auth/WebAuthn/`:
  - `WebAuthnManager.php` — orchestrates registration and authentication

    ```php
    final class WebAuthnManager
    {
        public function __construct(
            UserRepository $userRepo,
            Connection $db,
            ?StructuredLogger $logger = null,
        ) { }

        // ── Registration ──

        public function startRegistration(string $userId, string $username): array
        // Returns: ['challenge' => ..., 'rp' => [...], 'user' => [...],
        //          'pubKeyCredParams' => [...]]

        public function finishRegistration(
            string $userId,
            string $username,
            array $credential,
            string $expectedChallenge,
        ): string  // returns credentialId

        // ── Authentication (challenge + verify) ──

        public function startAuthentication(string $username): array
        // Returns: ['challenge' => ..., 'rpId' => ..., 'allowCredentials' => [...]]

        public function finishAuthentication(
            string $username,
            array $credential,
            string $expectedChallenge,
        ): AuthResult

        // ── Credential management ──

        public function listCredentials(string $userId): array<WebAuthnCredential>
        public function deleteCredential(string $userId, string $credentialId): void
    }
    ```

  - `WebAuthnCredential.php` — entity stored in DB:

    ```php
    final class WebAuthnCredential
    {
        public function __construct(
            public readonly string $credentialId,
            public readonly string $userId,
            public readonly string $publicKey,
            public readonly string $counter,         // sign counter
            public readonly string $type,           // 'public-key'
            public readonly ?string $deviceType,   // 'platform' | 'cross-platform'
            public readonly ?string $aaguid,
            public readonly int $registeredAt,
        ) { }
    }
    ```

  - `WebAuthnSettings.php` — settings entity ( RP name, origin, etc. ):

    ```php
    final class WebAuthnSettings
    {
        public function __construct(
            public readonly string $rpId,           // e.g. 'phlex.media'
            public readonly string $rpName,         // e.g. 'Phlex Media Server'
            public readonly string $rpOrigin,       // e.g. 'https://phlex.media'
            public readonly bool $attestationRequired = false,
        ) { }
    }
    ```

- `src/Auth/WebAuthnCredentialRepository.php` — implements
  `Webauthn\AuthenticatorCredentialRepository` (the library's interface
  for storing/retrieving credentials). Reads/writes `webauthn_credentials`
  table.

#### Database schema

- `migrations/010_webauthn_credentials.sql`:

  ```sql
  CREATE TABLE webauthn_credentials (
      id              CHAR(36) PRIMARY KEY,
      user_id         CHAR(36) NOT NULL,
      credential_id   VARBINARY(255) NOT NULL,
      public_key      VARBINARY(512) NOT NULL,
      counter         BIGINT UNSIGNED NOT NULL DEFAULT 0,
      type            VARCHAR(32) NOT NULL DEFAULT 'public-key',
      device_type     VARCHAR(64) NULL,
      aaguid          BINARY(16) NULL,
      registered_at   INT UNSIGNED NOT NULL,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      UNIQUE KEY uk_cred_id (credential_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```

#### HTTP API

- `src/Server/Http/Controllers/WebAuthnController.php`:

  ```
  POST /api/v1/auth/webauthn/register/options   — start registration
  POST /api/v1/auth/webauthn/register/verify   — finish registration
  POST /api/v1/auth/webauthn/login/options       — start authentication
  POST /api/v1/auth/webauthn/login/verify       — finish authentication
  GET  /api/v1/me/webauthn/credentials          — list user's credentials
  DELETE /api/v1/me/webauthn/credentials/{id}   — delete a credential
  ```

#### Settings UI

- `public/templates/auth/webauthn-settings.tpl` — user-facing passkey
  management (list credentials, register new one, delete).

#### WebAuthn as a Provider

- `src/Auth/WebAuthnProvider.php` — implements `ProviderInterface`
  so passkeys appear as an auth provider alongside OIDC/LDAP.
  The provider name is `'webauthn'` and credentials carry
  `['username' => '...']` (no password needed).

#### Unit Tests

- `tests/unit/Auth/WebAuthn/WebAuthnManagerTest.php`
- `tests/unit/Auth/WebAuthn/WebAuthnCredentialTest.php`
- `tests/unit/Auth/WebAuthn/WebAuthnControllerTest.php`

#### Documentation

- `docs/plugins/auth-providers.md` — add passkeys section.
- `docs/reference/api/auth-webauthn.md` — new API endpoint reference.
- `docs/security/passkeys.md` (new) — user-facing guide.

### Modify

- `composer.json` — add `web-auth/webauthn-lib: ^4.0`,
  `base64/base64: ^2.0` (for credential ID encoding).
- `src/Server/Http/Router.php` — wire `/api/v1/auth/webauthn/*`
  and `/api/v1/me/webauthn/*`.
- `src/Auth/AuthManager` — in `login()`, detect if the user's
  account has WebAuthn credentials and use `WebAuthnProvider`
  when `username` carries no password.
- `CHANGELOG.md`.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch: `git checkout -b d.4-webauthn`.
2. **Composer.** Add `web-auth/webauthn-lib: ^4.0`.
3. **DB schema.** Migration for `webauthn_credentials` table.
4. **Core.** `WebAuthnManager`, `WebAuthnCredential`,
   `WebAuthnCredentialRepository`, `WebAuthnSettings`.
5. **Controller.** `WebAuthnController` with all 6 endpoints.
6. **Provider.** `WebAuthnProvider` as D.1 provider.
7. **Settings UI.** Smarty template.
8. **Tests.**
9. **Docs.**
10. **Verification bar.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

1. `WebAuthnManagerTest::test_startRegistration_returns_valid_options`
2. `WebAuthnManagerTest::test_finishRegistration_success`
3. `WebAuthnManagerTest::test_finishRegistration_tampered_credential_throws`
4. `WebAuthnManagerTest::test_startAuthentication_returns_challenge`
5. `WebAuthnManagerTest::test_finishAuthentication_success`
6. `WebAuthnManagerTest::test_finishAuthentication_wrong_challenge_throws`
7. `WebAuthnManagerTest::test_listCredentials`
8. `WebAuthnManagerTest::test_deleteCredential`
9. `WebAuthnCredentialTest::test_smoke`
10. `WebAuthnControllerTest::test_start_registration`
11. `WebAuthnControllerTest::test_finish_registration`
12. `WebAuthnControllerTest::test_list_credentials`
13. `WebAuthnControllerTest::test_delete_credential`
14. `WebAuthnProviderTest::test_authenticate_success`
15. `WebAuthnProviderTest::test_authenticate_no_credentials`

**Coverage target:** `WebAuthnManager` ≥ 85 %.

## 6. Acceptance criteria

- [ ] User can register a passkey from their account settings page.
- [ ] Registration uses correct WebAuthn / FIDO2 ceremony
      (challenge from server, attestation stored).
- [ ] User can log in with passkey via `WebAuthnProvider` flow.
- [ ] Multiple passkeys per user supported.
- [ ] User can delete any of their own credentials.
- [ ] Credential ID stored as VARBINARY; not base64-encoded string.
- [ ] Sign counter incremented on each authentication; replay
      attacks detected if counter equals stored value.
- [ ] `./vendor/bin/phpunit` — green; ≥ 15 new tests.
- [ ] Coverage ≥ 85 % on `WebAuthnManager`.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/plugins/auth-providers.md` passkeys section added.
- [ ] `docs/reference/api/auth-webauthn.md` created.
- [ ] `docs/security/passkeys.md` created.
- [ ] CHANGELOG entry added.
- [ ] Git ritual executed.

## 7. Git ritual

```bash
cd /home/sites/phlex
git status --short
git pull --ff-only origin master
git checkout -b d.4-webauthn
# ... work ...
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'WebAuthn'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
git add -A
git commit -m "Step D.4: passkeys/WebAuthn support"
unset GITHUB_TOKEN
gh pr create \
  --title "Step D.4: passkeys/WebAuthn support" \
  --body  "First-class passkey support: WebAuthnManager, credential storage, registration and authentication APIs, WebAuthnProvider. Part of Phase D (Step D.4 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master
git pull --ff-only origin master
git branch --list 'd.4-*'
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `d.4-webauthn-review.md`.

Non-obvious points:
- Challenges must be 16+ bytes of cryptographically random data,
  stored server-side for the duration of the registration ceremony.
- The `attestationRequired` flag is `false` by default (allow any
  authenticator) — set to `true` in an enterprise deployment if
  required.
- The RP ID defaults to the server's registered domain; can be
  overridden via config for multi-tenant or subdomain setups.
