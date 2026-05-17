# Step D.2 — OIDC / OAuth provider plugin

**Phase:** D (Hub-grade Auth: SSO / OIDC / LDAP / Passkeys)
**Step:** D.2
**Depends on:** D.1
**Review:** Yes — see `d.2-oidc-plugin-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement `phlex-plugin-oidc` as the first concrete auth provider plugin.
It supports any OIDC-compliant identity provider (Authelia, Authentik,
Keycloak, Google, GitHub) and uses the `ProviderInterface` introduced
in D.1. The plugin ships with a `DiscoveryDocument` fetcher (caches
discovery for 24 h), an `IdTokenValidator` (RS256 / RS384 / RS512),
and a settings UI (provider URL + client ID + client secret + scopes).

## 2. Context (what already exists)

- After D.1: `ProviderInterface` exists in `phlex-shared`;
  `AuthProviderRegistry` holds registered providers; `ProviderManager`
  handles `oidc:username` prefix parsing.
- After Phase A: `PluginLoader` can install a plugin from a
  `plugin.json` URL, call `enable()`, and subscribe declared event
  listeners to the PSR-14 dispatcher.
- `composer.json` has `detain/phlex-shared:^0.3.0` and `php-di/php-di:^7.0`.
- No OIDC library is present; use `web-token/jwt-framework:^3.0`
  (the standard for OIDC token verification in PHP; already used by
  many Workerman projects).

## 3. Scope — files to create / modify

### Create

#### Plugin structure

```
src/Plugins/Oidc/
├── Plugin.php                          — implements LifecycleInterface
├── OidcProvider.php                    — implements ProviderInterface
├── DiscoveryDocument.php               — cached discovery document
├── IdTokenValidator.php                — RS256/RS384/RS512 validation
├── OidcUserInfo.php                    — extends UserInfo
├── Controller/
│   └── OidcCallbackController.php       — /auth/oidc/callback
└── templates/
    └── oidc-settings.tpl               — Smarty settings form
```

#### Plugin manifest

- `plugin.json`:

  ```json
  {
    "name": "phlex-plugin-oidc",
    "version": "1.0.0",
    "phlex_min_server_version": "0.11.0",
    "type": "auth-provider",
    "entry": "Phlex\\Plugins\\Oidc\\Plugin",
    "settings": {
      "provider_url":   { "type": "string", "required": true, "secret": false },
      "client_id":      { "type": "string", "required": true, "secret": false },
      "client_secret":  { "type": "string", "required": true, "secret": true  },
      "scopes":         { "type": "string", "required": false, "default": "openid profile email" }
    },
    "events": []
  }
  ```

#### OidcProvider

`OidcProvider::authenticate(array $credentials)` handles two flows:

1. **Authorization Code flow** (normal browser login):
   - `$credentials` contains `['code' => '...', 'redirect_uri' => '...']`
   - Exchange code at `{provider_url}/oauth/token` for id_token
   - Validate id_token (signature, iss, aud, exp, nonce)
   - Extract claims → build `AuthResult` with local user id
   - If local user doesn't exist, call `UserRepository::findOrCreateByExternalId()`
     with `{provider}.{subject}` as external_id

2. **Direct API token** (mobile clients that already obtained a token
   via the provider's normal flow):
   - `$credentials` contains `['access_token' => '...']`
   - Validate RS256 token
   - Fetch `/userinfo` endpoint with the access token
   - Build `AuthResult`

#### DiscoveryDocument

```php
final class DiscoveryDocument
{
    public function __construct(string $providerUrl, ?CacheInterface $cache = null) { }

    public function get(string $key): mixed { }  // cached, 24 h TTL

    public function issuer(): string { }
    public function authorizationEndpoint(): string { }
    public function tokenEndpoint(): string { }
    public function userinfoEndpoint(): string { }
    public function jwksUri(): string { }
}
```

#### IdTokenValidator

```php
final class IdTokenValidator
{
    public function __construct(DiscoveryDocument $doc, JWKSet $jwkSet) { }

    public function validate(
        string $idToken,
        string $clientId,
        string $expectedNonce = '',
    ): IdTokenClaims { }
    // throws OidcValidationException on failure
}
```

#### OidcCallbackController

```
GET  /auth/oidc/authorize?provider={name}&redirect_uri={uri}
     → redirect to provider's /authorization endpoint

GET  /auth/oidc/callback?code={code}&state={state}&provider={name}
     → exchange code, validate id_token, create local session,
       redirect to redirect_uri with ?token=...&refresh=...
```

The `state` parameter carries `{redirect_uri}` + a CSRF nonce so the
callback can verify the request is not forged.

#### Settings form

- `templates/oidc-settings.tpl` — Smarty template with four fields:
  Provider URL, Client ID, Client Secret (masked), Scopes. POST to
  `/api/v1/admin/auth-providers/oidc/config` (handled by a new
  `OidcAdminController` that persists settings to the plugin's
  `settings.json`).

#### Unit Tests

- `tests/unit/Plugins/Oidc/OidcProviderTest.php`
- `tests/unit/Plugins/Oidc/DiscoveryDocumentTest.php`
- `tests/unit/Plugins/Oidc/IdTokenValidatorTest.php`
- `tests/unit/Plugins/Oidc/OidcCallbackControllerTest.php`

#### Documentation

- `docs/plugins/auth-providers.md` (new) — auth provider overview,
  OIDC configuration walkthrough (Keycloak, Authelia, Google).
- `docs/plugins/plugin-catalog.md` — add phlex-plugin-oidc entry.

### Modify

- `composer.json` — add `web-token/jwt-framework: ^3.0` and
  `phpseclib/phpseclib: ^3.0` (for RSA key handling in OIDC token
  parsing).
- `src/Server/Http/Router.php` — add `/auth/oidc/*` routes wired to
  `OidcCallbackController`.
- `src/Server/Http/Routes/AdminRoutes.php` — wire
  `POST /api/v1/admin/auth-providers/oidc/config`.
- `CHANGELOG.md` — add entry.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b d.2-oidc-plugin`.
2. **Composer.** Add `web-token/jwt-framework` to `composer.json`.
   Run `composer install`.
3. **Plugin scaffold.** Create `src/Plugins/Oidc/` with `Plugin.php`,
   `plugin.json`, and directory structure.
4. **Core classes.** Write `DiscoveryDocument`, `IdTokenValidator`,
   `OidcUserInfo`, `OidcProvider`.
5. **HTTP callback.** Write `OidcCallbackController` + router wiring.
6. **Admin settings.** Write `OidcAdminController` + Smarty template.
7. **Tests.**
8. **Docs.**
9. **Verification bar** (§0.4 minimum bar).
10. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

1. `OidcProviderTest::test_authenticate_with_valid_code`
2. `OidcProviderTest::test_authenticate_with_invalid_code_throws`
3. `OidcProviderTest::test_authenticate_with_access_token`
4. `OidcProviderTest::test_authenticate_unknown_provider_returns_false`
5. `OidcProviderTest::test_getUserInfo`
6. `DiscoveryDocumentTest::test_cached_discovery`
7. `DiscoveryDocumentTest::test_fetches_and_caches`
8. `IdTokenValidatorTest::test_valid_rs256_token`
9. `IdTokenValidatorTest::test_expired_token_throws`
10. `IdTokenValidatorTest::test_wrong_audience_throws`
11. `OidcCallbackControllerTest::test_authorize_redirect`

**Coverage target:** `src/Plugins/Oidc/OidcProvider` ≥ 85 %,
`src/Plugins/Oidc/IdTokenValidator` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

- **Plugin API** → `docs/plugins/auth-providers.md` (new)
- **Plugin API** → `docs/plugins/plugin-catalog.md` (update)
- **User-visible behavior change** → CHANGELOG

## 7. Acceptance criteria

- [ ] `phlex-plugin-oidc` installs via plugin loader from its
      `plugin.json` URL.
- [ ] Plugin manifest type is `"auth-provider"`.
- [ ] `OidcProvider` implements `ProviderInterface` from D.1.
- [ ] Authorization code flow works end-to-end against a real OIDC
      provider (Keycloak or Authelia in dev mode).
- [ ] `DiscoveryDocument` caches for 24 h.
- [ ] `IdTokenValidator` validates RS256 tokens with cached JWKS.
- [ ] Settings form saves/loads provider URL, client ID, client secret,
      scopes.
- [ ] `./vendor/bin/phpunit` — green; ≥ 11 new tests.
- [ ] Coverage ≥ 85 % on `OidcProvider` + `IdTokenValidator`.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/plugins/auth-providers.md` created.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b d.2-oidc-plugin

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Oidc'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step D.2: OIDC/OAuth provider plugin (phlex-plugin-oidc)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step D.2: OIDC/OAuth provider plugin" \
  --body  "Adds phlex-plugin-oidc: Authorization Code flow, RS256 id_token validation, DiscoveryDocument cache, admin settings UI. Supports Keycloak, Authelia, Authentik, Google, GitHub. Part of Phase D (Step D.2 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'd.2-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `d.2-oidc-plugin-review.md`.

Non-obvious points:
- The plugin stores its settings in the plugin's own directory
  (`plugins/phlex-plugin-oidc/settings.json`) — NOT in the server's
  config files.
- JWKS is cached for 24 h alongside the discovery document to avoid
  hitting the OIDC provider on every request.
- The CSRF `state` parameter encodes the original `redirect_uri` so a
  malicious callback cannot redirect tokens to an attacker's URI.
