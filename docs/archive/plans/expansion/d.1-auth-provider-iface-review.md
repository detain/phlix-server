# Review: Step D.1 — Auth provider plugin interface

**Step:** D.1
**Plan file:** `d.1-auth-provider-iface.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show D.1 squashed commit
git branch --list 'd.1-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 18 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'AuthProvider|ProviderManager'
# Expected: AuthProviderRegistry ≥ 85%, ProviderManager ≥ 85%

# ─── Static analysis ───
./vendor/bin/phpstan analyze src/ --level=9 --no-progress
# Expected: [OK] No errors

# ─── Code style ───
./vendor/bin/phpcs --standard=PSR12 src/
# Expected: clean (warnings OK, 0 errors)

# ─── Syntax ───
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Expected: empty output
```

## 3. Check deliverables

For each acceptance criterion in `d.1-auth-provider-iface.md` §7:

- [ ] `ProviderInterface` + `AuthResult` + `UserInfo` in
      `phlex-shared/src/Auth/` (confirm with `ls src/Auth/` in the
      phlex-shared worktree)
- [ ] `AuthProviderRegistry` exists in `src/Auth/`
- [ ] `ProviderManager` exists in `src/Auth/`
- [ ] Migration `migrations/009_auth_provider_schema.sql` exists
- [ ] `AuthProviderController` exists and is wired in the router
- [ ] `UserRepository` has `findByExternalId`,
      `findOrCreateByExternalId`, `updateProviderData`
- [ ] `AuthManager` has `loginWithProvider` method
- [ ] `docs/plugins/developer-guide.md` updated with auth provider docs
- [ ] CHANGELOG has D.1 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-D.1 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.11.0`
- Coverage of `AuthProviderRegistry` or `ProviderManager` drops below 85%
