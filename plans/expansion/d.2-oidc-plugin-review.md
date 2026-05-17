# Review: Step D.2 — OIDC / OAuth provider plugin

**Step:** D.2
**Plan file:** `d.2-oidc-plugin.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'd.2-*'
```

## 2. Run the verification bar

```bash
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Oidc|Plugins/Oidc'
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

## 3. Check deliverables

- [ ] `src/Plugins/Oidc/Plugin.php` implements `LifecycleInterface`
- [ ] `src/Plugins/Oidc/OidcProvider` implements `ProviderInterface`
- [ ] `src/Plugins/Oidc/DiscoveryDocument` caches for 24 h
- [ ] `src/Plugins/Oidc/IdTokenValidator` validates RS256
- [ ] `src/Plugins/Oidc/Controller/OidcCallbackController` handles
      `/auth/oidc/authorize` and `/auth/oidc/callback`
- [ ] `src/Plugins/Oidc/templates/oidc-settings.tpl` settings form exists
- [ ] `plugin.json` has `"type": "auth-provider"`
- [ ] Discovery document caching confirmed in test
- [ ] `web-token/jwt-framework` added to `composer.json`
- [ ] `docs/plugins/auth-providers.md` created
- [ ] CHANGELOG updated

## 4. Reject conditions

- Any test fails
- PHPStan new errors
- PHPCS errors
- Coverage < 85 % on `OidcProvider` or `IdTokenValidator`
- Missing PHPDoc
