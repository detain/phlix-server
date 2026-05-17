# Step D.3 — LDAP provider plugin

**Phase:** D (Hub-grade Auth: SSO / OIDC / LDAP / Passkeys)
**Step:** D.3
**Depends on:** D.1
**Review:** Yes — see `d.3-ldap-plugin-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement `phlex-plugin-ldap` for enterprise / homelab directory
authentication via LDAP (RFC 4510). Users bind with their LDAP
credentials; the plugin maps LDAP attributes to Phlex user fields.
Supports Active Directory and OpenLDAP.

## 2. Context (what already exists)

- After D.1: `ProviderInterface` in `phlex-shared`;
  `AuthProviderRegistry` holds registered providers; user records have
  `provider` + `external_id` columns.
- After Phase A: `PluginLoader` can install from `plugin.json` URL.
- No LDAP library present; use `LdapRecord/LdapRecord: ^3.0`
  (the de-facto standard Laravel/Lumen LDAP package — pure PHP, no
  extension required beyond `ext-ldap`).
- Admin UI: Smarty + JSON API from A.5.

## 3. Scope — files to create / modify

### Create

#### Plugin structure

```
src/Plugins/Ldap/
├── Plugin.php
├── LdapProvider.php              — implements ProviderInterface
├── LdapConnection.php             — wraps LdapRecord Connection
├── UserMapper.php                 — maps LDAP attrs → Phlex user fields
├── LdapUserInfo.php               — extends UserInfo
├── Controller/
│   └── LdapAdminController.php    — /api/v1/admin/auth-providers/ldap/config
└── templates/
    └── ldap-settings.tpl
```

#### Plugin manifest

- `plugin.json`:

  ```json
  {
    "name": "phlex-plugin-ldap",
    "version": "1.0.0",
    "phlex_min_server_version": "0.11.0",
    "type": "auth-provider",
    "entry": "Phlex\\Plugins\\Ldap\\Plugin",
    "settings": {
      "host":       { "type": "string", "required": true  },
      "port":       { "type": "int",    "required": false, "default": 389 },
      "ssl":        { "type": "bool",   "required": false, "default": false },
      "base_dn":    { "type": "string", "required": true  },
      "bind_dn":    { "type": "string", "required": false },
      "bind_pw":    { "type": "string", "required": false, "secret": true },
      "user_filter":{ "type": "string", "required": false,
                      "default": "(uid={{username}})" },
      "admin_group":{ "type": "string", "required": false }
    },
    "events": []
  }
  ```

#### LdapProvider

```php
final class LdapProvider implements ProviderInterface
{
    public function name(): string  // 'ldap'

    public function supportsAuthentication(array $credentials): bool
        // true if 'username' + 'password' keys present

    public function authenticate(array $credentials): AuthResult
        // 1. connect (cached connection per host:port)
        // 2. bind with user credentials (user DN from user_filter or AD search)
        // 3. fetch attributes → UserInfo
        // 4. findOrCreateByExternalId() → AuthResult

    public function getUserInfo(string $externalId): ?LdapUserInfo { }
    public function linkAccount(string $localUserId, array $externalIds): void { }
}
```

#### LdapConnection

Caches a `LdapRecord\Connection` per host:port:ssl triple (PHP static
variable with request-scoped lifetime). Handles:
- StartTLS upgrade on plain connections
- CA certificate verification for SSL
- Connection timeout (5 s)

#### UserMapper

Maps LDAP attributes to Phlex user fields:

```php
final class UserMapper
{
    public function __construct(array $attributeMap) { }

    public function map(array $ldapEntry): array
    // Maps:
    //   uid / sAMAccountName / userPrincipalName → username
    //   mail / userPrincipalName → email
    //   displayName / cn → display_name
    //   jpegPhoto → avatar_url (uploaded to /avatars/ dir)
}
```

#### Admin settings

- `ldap-settings.tpl` — Smarty form with host, port, SSL toggle,
  Base DN, Bind DN (optional), Bind PW (masked), User Filter,
  Admin Group DN (optional).
- `LdapAdminController` — CRUD for settings, test-connection action
  (`POST /api/v1/admin/auth-providers/ldap/test`).

#### Unit Tests

- `tests/unit/Plugins/Ldap/LdapProviderTest.php`
- `tests/unit/Plugins/Ldap/LdapConnectionTest.php`
- `tests/unit/Plugins/Ldap/UserMapperTest.php`

#### Documentation

- `docs/plugins/auth-providers.md` — add LDAP configuration section
  (AD and OpenLDAP examples).

### Modify

- `composer.json` — add `LdapRecord/LdapRecord: ^3.0`.
- `src/Server/Http/Routes/AdminRoutes.php` — wire LDAP admin routes.
- `CHANGELOG.md`.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Branch: `git checkout -b d.3-ldap-plugin`.
2. **Composer.** Add `LdapRecord/LdapRecord`.
3. **Plugin scaffold.** `src/Plugins/Ldap/`.
4. **Core classes.** `LdapProvider`, `LdapConnection`, `UserMapper`.
5. **Admin controller + template.**
6. **Tests.**
7. **Docs + verification bar.**
8. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

1. `LdapProviderTest::test_supportsAuthentication_with_credentials`
2. `LdapProviderTest::test_supportsAuthentication_without_credentials`
3. `LdapProviderTest::test_authenticate_success`
4. `LdapProviderTest::test_authenticate_invalid_credentials`
5. `LdapProviderTest::test_authenticate_connection_failure`
6. `LdapConnectionTest::test_cached_connection`
7. `LdapConnectionTest::test_starttls`
8. `UserMapperTest::test_map_openldap`
9. `UserMapperTest::test_map_active_directory`
10. `UserMapperTest::test_avatar_download`

**Coverage target:** `LdapProvider` ≥ 85 %.

## 6. Acceptance criteria

- [ ] `phlex-plugin-ldap` installs via plugin loader.
- [ ] `LdapProvider` implements `ProviderInterface`.
- [ ] Authentication works against OpenLDAP and Active Directory.
- [ ] User attributes mapped correctly (username, email, display_name).
- [ ] Admin settings form saves/loads all settings.
- [ ] Test-connection button returns meaningful error on bad host/credentials.
- [ ] `./vendor/bin/phpunit` — green; ≥ 10 new tests.
- [ ] Coverage ≥ 85 % on `LdapProvider`.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/plugins/auth-providers.md` LDAP section added.
- [ ] CHANGELOG entry added.
- [ ] Git ritual executed.

## 7. Git ritual

```bash
cd /home/sites/phlex
git status --short
git pull --ff-only origin master
git checkout -b d.3-ldap-plugin
# ... work ...
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
git add -A
git commit -m "Step D.3: LDAP provider plugin (phlex-plugin-ldap)"
unset GITHUB_TOKEN
gh pr create \
  --title "Step D.3: LDAP provider plugin" \
  --body  "Adds phlex-plugin-ldap: LDAP auth for OpenLDAP and Active Directory, UserMapper, admin settings UI, test-connection action. Part of Phase D (Step D.3 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch
git checkout master
git pull --ff-only origin master
git branch --list 'd.3-*'
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `d.3-ldap-plugin-review.md`.

Non-obvious points:
- Connection is cached per request (PHP static) to avoid repeated
  bind overhead.
- For AD, `user_filter` defaults to `(&(objectClass=user)(sAMAccountName={{username}}))`.
- LDAP password never stored locally; only used to bind at auth time.
- If `admin_group` is set and the authenticated user is a member,
  they are promoted to admin on first login via `UserRepository::setAdmin()`.
