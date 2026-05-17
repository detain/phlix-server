# Review: Step D.3 — LDAP provider plugin

**Step:** D.3
**Plan file:** `d.3-ldap-plugin.md`
**Target repo:** `detain/phlex-server`

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'd.3-*'
```

## 2. Run the verification bar

```bash
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Ldap'
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

## 3. Check deliverables

- [ ] `src/Plugins/Ldap/Plugin.php`
- [ ] `src/Plugins/Ldap/LdapProvider` implements `ProviderInterface`
- [ ] `src/Plugins/Ldap/LdapConnection` caches per-request
- [ ] `src/Plugins/Ldap/UserMapper` maps LDAP attrs to user fields
- [ ] `plugin.json` type `"auth-provider"`
- [ ] Admin settings form
- [ ] Test-connection endpoint
- [ ] `composer.json` includes `LdapRecord/LdapRecord`
- [ ] `docs/plugins/auth-providers.md` LDAP section
- [ ] CHANGELOG

## 4. Reject conditions

- Any test fails
- PHPStan new errors
- PHPCS errors
- Coverage < 85 % on `LdapProvider`
- Missing PHPDoc
