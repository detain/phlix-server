# Review — Step A.5 (plugin admin UI)

The implementation has been merged. Re-verify without modifying code.

## 1. Re-read

- `plans/expansion/a.5-plugin-admin-ui.md`
- Diff of the squashed commit:
  ```bash
  git show --stat HEAD
  git log -1 --format=%H
  ```

## 2. Re-run the §0.4 minimum bar

```bash
cd /home/sites/phlex
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null \
  | grep -E 'PluginAdminController|AdminMiddleware|PluginAdminPageController'   # ≥ 85 %
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

Run the migration on a fresh test DB and confirm the auto-promote logic:

```bash
php scripts/run-migrations.php
mysql -u "$DB_USER" -p"$DB_PASS" -e "SELECT id,email,is_admin FROM users;" "$DB_NAME"
```

Expected: at least one user has `is_admin=1`, and `users.is_admin` is a
real column.

## 3. Verify acceptance criteria

Walk every checkbox from §7 of `a.5-plugin-admin-ui.md`. For each:

- AdminMiddleware enforces:
  ```bash
  grep -n 'is_admin' src/Server/Http/Middleware/AdminMiddleware.php
  grep -n 'findAdminById' src/Auth/UserRepository.php
  ```
- Smarty templates present:
  ```bash
  ls public/templates/admin/plugins/
  ```
- JSON API responses respect the documented status codes — re-read
  controller tests.

PASS / FAIL each.

## 4. Verify §0.4 doc deliverables

```bash
git show --stat HEAD -- docs/reference/api/admin-plugins.yaml
git show --stat HEAD -- docs/plugins/install-from-url.md
git show --stat HEAD -- docs/plugins/install-from-catalog.md
git show --stat HEAD -- docs/plugins/trusted-plugin-list.md
git show --stat HEAD -- docs/plugins/developer-guide.md
git show --stat HEAD -- CHANGELOG.md
```

Validate the OpenAPI yaml:

```bash
npx -y @apidevtools/swagger-cli validate docs/reference/api/admin-plugins.yaml
```

Should print `valid` or equivalent.

## 5. Verify postconditions

```bash
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match the A.5 squashed commit
git branch --list 'a.5-*'                   # MUST be empty
```

## 6. Report

PASS / FAIL with one-line reason per criterion. Do not modify code.
