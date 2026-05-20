# Review — Step A.1 (PSR-11 DI container)

The implementation has been merged. Re-verify without modifying code.

## 1. Re-read

- `plans/expansion/a.1-di-container.md` (the step plan)
- Diff of the squashed commit:
  ```bash
  git show --stat HEAD
  git log -1 --format=%H
  ```

## 2. Re-run the §0.4 minimum bar

```bash
cd /home/sites/phlex
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Common/Container|Providers'   # confirm ≥ 85 %
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

Bonus smoke test: confirm the refactored bootstrap doesn't throw on a
synthetic request.

```bash
php -d 'auto_prepend_file=' public/index.php >/tmp/a1-smoke.out 2>&1 || true
grep -E '500|Internal Server Error' /tmp/a1-smoke.out && echo FAIL || echo PASS
```

## 3. Verify acceptance criteria

Walk every checkbox from §7 of `a.1-di-container.md`. For each:

- Files exist? `ls src/Common/Container/` and `ls
  tests/Unit/Common/Container/`.
- `composer.json` declares `php-di/php-di:^7.0` and `psr/container:^2.0`?
  `jq '.require' composer.json`.
- `Application` still has a backwards-compatible constructor?
  `git show HEAD -- src/Server/Core/Application.php | head -120`.
- `public/index.php` no longer hardcodes service instantiation?
  `git show HEAD -- public/index.php`.

Report PASS / FAIL per criterion with a one-line reason.

## 4. Verify §0.4 doc deliverables

Confirm each promised file was touched in the commit:

```bash
git show --stat HEAD -- docs/dev/architecture-server.md
git show --stat HEAD -- docs/reference/env-vars.md
git show --stat HEAD -- CHANGELOG.md
git show --stat HEAD -- README.md
```

Each must appear in the diff. The CHANGELOG line must mention "PSR-11" or
"DI container". `docs/reference/env-vars.md` must document
`PHLEX_CONTAINER_COMPILE` and `JWT_SECRET`.

## 5. Verify postconditions

```bash
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match the A.1 squashed commit
git branch --list 'a.1-*'                   # MUST be empty
```

## 6. Report

PASS / FAIL with one-line reason per criterion. Do not modify code. If any
criterion FAILs, recommend the supervisor either (a) spawn a follow-up
"Step A.1 fixup" subagent or (b) revert the squashed commit and re-run A.1
from scratch. The reviewer never edits the codebase directly.
