# Review: Step H.3 — Custom CSS / themes

**Step:** H.3
**Plan file:** `h.3-themes.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)

## 1. Verify preconditions

```bash
cd /home/sites/phlex
git status --short                # MUST be empty
git branch --show-current          # MUST be 'master'
git log --oneline -1              # MUST show H.3 squashed commit
git branch --list 'h.3-*'         # MUST be empty (branch deleted)
```

## 2. Run the verification bar

```bash
# ─── Tests ───
./vendor/bin/phpunit
# Expected: 0 failures; ≥ 11 new tests pass

# ─── Coverage ───
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'ThemeRegistry|ThemeMiddleware'
# Expected: each ≥ 85%

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

For each acceptance criterion in `h.3-themes.md` §7:

- [ ] `config/themes.php` defines 4 built-in themes
- [ ] `Theme` entity with all fields
- [ ] `ThemeRegistry` built-in + plugin registration paths work
- [ ] `ThemeRegistry::getActiveThemeForUser()` falls back to `phlex-dark`
- [ ] `ThemeMiddleware` injects `<link>` into HTML; ignores non-HTML
- [ ] `migrations/006_user_theme_settings.sql` runs cleanly
- [ ] `UserProfileManager` has theme getter/setter
- [ ] `base.tpl` has theme placeholders
- [ ] `ThemePreviewController` at `/portal/theme-preview`
- [ ] `var/themes/` gitignored
- [ ] `docs/developers/ui-themes.md` written
- [ ] `docs/plugins/developer-guide.md` updated with `ui-theme` section
- [ ] CHANGELOG has H.3 entry

## 4. Reject conditions

Reviewer MUST reject if:
- Any test fails
- PHPStan reports new errors (vs. pre-H.3 master baseline)
- PHPCS reports errors (warnings are OK)
- New classes lack PHPDoc `@since 0.14.0`
- Coverage of any new class drops below 85%
- ThemeMiddleware modifies non-HTML responses
- base.tpl placeholders are missing
