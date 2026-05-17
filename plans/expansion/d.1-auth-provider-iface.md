# Step D.1 — Auth provider plugin interface

**Phase:** D (Hub-grade Auth: SSO / OIDC / LDAP / Passkeys)
**Step:** D.1
**Depends on:** C.9
**Review:** Yes — see `d.1-auth-provider-iface-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Define `Phlex\Auth\ProviderInterface` in `phlex-shared` so that
OIDC, LDAP, SAML, and passkey auth providers can be plugged in without
touching the core `AuthManager`. The interface follows the same pattern
as `MetadataProviderInterface`: a focused contract with `authenticate()`,
`getUserInfo()`, and `linkAccount()` methods. A `AuthProviderRegistry`
singleton (in `phlex-server`) holds the currently-enabled providers and
is consulted by `AuthManager` when the username field carries a special
provider prefix (e.g. `oidc:alice@google.com` or `ldap:bob`).

## 2. Context (what already exists)

- After Phase C: hub issues JWTs; servers validate against hub JWKS.
  Local username+password auth still the only built-in path.
- After Phase A: `PluginLoader` + `LifecycleInterface` exist;
  `plugin.json` supports `"type": "auth-provider"`.
- `Phlex\Auth\AuthManager` — current `login()` / `register()` /
  `validateAccessToken()` methods accept only username+password.
- `Phlex\Auth\UserRepository` — user records live in `users` table
  with `password_hash`. Social/OIDC users have `password_hash = NULL`
  and a `provider` column (added in this step).
- `PHLEX_EXPANSION_PLAN.md` §1 — "SSO/OIDC/LDAP/SAML/WebAuthn auth
  providers" is **Missing**.

## 3. Scope — files to create / modify

### Create

#### phlex-shared (interface contract — no I/O dependencies)

- `src/Auth/ProviderInterface.php` — core interface:

  ```php
  namespace Phlex\Auth;

  interface ProviderInterface
  {
      public function name(): string;  // e.g. 'oidc', 'ldap', 'saml'

      public function supportsAuthentication(array $credentials): bool;

      public function authenticate(array $credentials): AuthResult;

      public function getUserInfo(string $externalId): ?UserInfo;

      public function linkAccount(string $localUserId, array $externalIds): void;
  }
  ```

- `src/Auth/AuthResult.php` — value object returned by `authenticate()`:

  ```php
  final class AuthResult
  {
      public function __construct(
          public readonly bool $success,
          public readonly ?string $userId = null,     // local user id
          public readonly ?string $externalId = null,  // provider-specific id
          public readonly ?string $error = null,
          public readonly array $attributes = [],      // email, name, avatar …
      ) { }
  }
  ```

- `src/Auth/UserInfo.php` — value object from `getUserInfo()`:

  ```php
  final class UserInfo
  {
      public function __construct(
          public readonly string $externalId,
          public readonly ?string $email,
          public readonly ?string $displayName,
          public readonly ?string $avatarUrl,
          public readonly array $rawAttributes = [],
      ) { }
  }
  ```

#### phlex-server (implementation)

- `src/Auth/AuthProviderRegistry.php` — holds enabled
  `ProviderInterface` instances; resolves provider prefix from username;

  ```php
  final class AuthProviderRegistry
  {
      public function __construct(\Psr\Container\ContainerInterface $container) { }

      public function registerProvider(ProviderInterface $provider): void { }

      public function authenticate(string $username, array $credentials = []): AuthResult { }
  }
  ```

- `src/Auth/ProviderManager.php` — bridges `AuthManager` to the
  registry; handles `provider:username` parsing.

- `src/Server/Http/Controllers/AuthProviderController.php` — admin
  API for listing / enabling / disabling providers:

  ```
  GET    /api/v1/admin/auth-providers         — list registered providers
  POST   /api/v1/admin/auth-providers/{name}/enable
  POST   /api/v1/admin/auth-providers/{name}/disable
  GET    /api/v1/admin/auth-providers/{name}/config-schema  — JSON Schema for settings
  ```

- `src/Common/Container/Providers/AuthServicesProvider.php` — register
  `AuthProviderRegistry`, `ProviderManager`.

- `src/Server/Http/Middleware/AuthProviderMiddleware.php` — if a request
  carries a provider-prefixed username, delegate to `ProviderManager`
  instead of the default password verifier.

- `migrations/009_auth_provider_schema.sql` — add to `users` table:

  ```sql
  ALTER TABLE users
    ADD COLUMN provider       VARCHAR(64)     NULL AFTER password_hash,
    ADD COLUMN external_id    VARCHAR(255)    NULL,
    ADD COLUMN provider_data  JSON            NULL,
    ADD INDEX idx_provider (provider),
    ADD UNIQUE INDEX idx_external (provider, external_id);
  ```

- `tests/unit/Auth/AuthProviderRegistryTest.php`
- `tests/unit/Auth/AuthResultTest.php`
- `tests/unit/Auth/UserInfoTest.php`
- `tests/unit/Auth/ProviderManagerTest.php`
- `tests/unit/Auth/AuthProviderControllerTest.php`

#### Documentation

- `docs/plugins/developer-guide.md` — add "Auth Provider Plugins" section
  (auth provider type, `ProviderInterface`, `AuthResult`, lifecycle).
- `docs/reference/api/admin-auth-providers.md` — new endpoint doc.

### Modify

- `src/Auth/AuthManager.php` — add `loginWithProvider()` method that
  delegates to `ProviderManager`; keep existing `login()` for password-
  based auth (backwards compatible). Add provider-prefix parsing to
  `login()` javadoc.
- `src/Auth/UserRepository.php` — add `findByExternalId()`,
  `findOrCreateByExternalId()`, and `updateProviderData()`.
- `composer.json` — bump `detain/phlex-shared` to `^0.3.0` (new
  `ProviderInterface` types).
- `CHANGELOG.md` — add entry for auth provider plugin system.
- `src/Server/Http/Router.php` — wire `/api/v1/admin/auth-providers/*`.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b d.1-auth-provider-iface`.
2. **phlex-shared.** `git clone` the `phlex-shared` repo next to
   `phlex-server` (they are siblings at `/home/sites/`). Add the three
   interface files to `src/Auth/` there, tag v0.3.0, push, then
   `composer require detain/phlex-shared:^0.3.0` in the server.
3. **Registry + Manager.** Write `AuthProviderRegistry`,
   `ProviderManager`, and the DB migration.
4. **AuthManager integration.** Add `loginWithProvider()`; do not
   change the existing `login()` signature (backwards compat).
5. **Admin API.** `AuthProviderController` + routes.
6. **Tests.** Write all 5 test files per §5.
7. **Verification bar** (§0.4 minimum bar).
8. **Docs.**
9. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `AuthResultTest::test_success_result`
2. `AuthResultTest::test_failure_result`
3. `AuthResultTest::test_attributes_access`
4. `UserInfoTest::test_smoke`
5. `AuthProviderRegistryTest::test_register_and_authenticate`
6. `AuthProviderRegistryTest::test_no_provider_returns_null`
7. `AuthProviderRegistryTest::test_unknown_provider_throws`
8. `ProviderManagerTest::test_parse_provider_prefix`
9. `ProviderManagerTest::test_authenticate_with_provider`
10. `ProviderManagerTest::test_fallback_to_password_auth`
11. `UserRepositoryTest::test_findByExternalId`
12. `UserRepositoryTest::test_findOrCreateByExternalId_creates`
13. `UserRepositoryTest::test_findOrCreateByExternalId_finds`
14. `UserRepositoryTest::test_updateProviderData`
15. `AuthProviderControllerTest::test_list_providers`
16. `AuthProviderControllerTest::test_enable_provider`
17. `AuthProviderControllerTest::test_disable_provider`
18. `AuthProviderControllerTest::test_config_schema`

**Coverage target:** `src/Auth/AuthProviderRegistry` ≥ 85 %,
`src/Auth/ProviderManager` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Plugin API** → update `docs/plugins/developer-guide.md`
- **Public HTTP API** → `docs/reference/api/admin-auth-providers.md` (new)
- **User-visible behavior change** → CHANGELOG entry

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `ProviderInterface` + `AuthResult` + `UserInfo` live in
      `phlex-shared/src/Auth/` with PHPDoc per §0.4.
- [ ] `AuthProviderRegistry` holds registered providers; unknown provider
      throws `AuthProviderNotFoundException`.
- [ ] `ProviderManager::authenticate('oidc:alice@example.com', [...])`
      delegates to the `oidc` provider.
- [ ] `ProviderManager::authenticate('alice', ['password' => '...'])`
      falls back to existing password flow.
- [ ] `UserRepository::findOrCreateByExternalId()` creates a local user
      with `provider` + `external_id` set and `password_hash = NULL`.
- [ ] Migration `009_auth_provider_schema.sql` adds `provider`,
      `external_id`, `provider_data` columns to `users` table.
- [ ] Admin API: list / enable / disable / config-schema endpoints
      wired in router.
- [ ] `./vendor/bin/phpunit` — green; ≥ 18 new tests.
- [ ] Coverage of `AuthProviderRegistry` + `ProviderManager` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/plugins/developer-guide.md` updated.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b d.1-auth-provider-iface

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'AuthProvider|ProviderManager'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step D.1: auth provider plugin interface (ProviderInterface + registry)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step D.1: auth provider plugin interface" \
  --body  "Adds Phlex\\Auth\\ProviderInterface to phlex-shared, AuthProviderRegistry, ProviderManager, migration for provider/external_id columns, and admin API. Part of Phase D (Step D.1 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'd.1-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `d.1-auth-provider-iface-review.md`.

Non-obvious points:
- The interface lives in `phlex-shared` (no I/O) so both the server
  and a future hub can implement providers without pulling in server
  dependencies.
- Provider prefix parsing: `oidc:alice@example.com` splits on the
  first `:` — if no colon, it is a plain username and falls through to
  password auth.
- Local account linking: if the same email appears via OIDC for the
  first time, `ProviderManager` auto-creates the local user row (with
  `password_hash = NULL`) so the user can set a local password later.
