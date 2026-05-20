# Review: Step D.4 — Passkeys / WebAuthn

**Step:** D.4
**Plan file:** `d.4-webauthn.md`
**Target repo:** `detain/phlex-server`

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'd.4-*'
```

## 2. Run the verification bar

```bash
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'WebAuthn'
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

## 3. Check deliverables

- [ ] `src/Auth/WebAuthn/WebAuthnManager.php`
- [ ] `src/Auth/WebAuthn/WebAuthnCredential.php`
- [ ] `src/Auth/WebAuthn/WebAuthnCredentialRepository.php`
- [ ] `migrations/010_webauthn_credentials.sql`
- [ ] `src/Auth/WebAuthnProvider.php` implements `ProviderInterface`
- [ ] `WebAuthnController` handles all 6 endpoints
- [ ] Routes: `/api/v1/auth/webauthn/*` and `/api/v1/me/webauthn/*`
- [ ] Smarty template for passkey management
- [ ] `composer.json` includes `web-auth/webauthn-lib: ^4.0`
- [ ] `docs/plugins/auth-providers.md` passkeys section
- [ ] `docs/reference/api/auth-webauthn.md`
- [ ] `docs/security/passkeys.md`
- [ ] CHANGELOG

## 4. Reject conditions

- Any test fails
- PHPStan new errors
- PHPCS errors
- Coverage < 85 % on `WebAuthnManager`
- Missing PHPDoc
