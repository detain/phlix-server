# Review — Step A.4 (plugin loader + lifecycle)

The implementation has been merged. Re-verify without modifying code.

## 1. Re-read

- `plans/expansion/a.4-plugin-loader.md`
- Diff of the squashed commit:
  ```bash
  git show --stat HEAD
  git log -1 --format=%H
  ```

## 2. Re-run the §0.4 minimum bar

```bash
cd /home/sites/phlex
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Plugins'   # ≥ 85 %
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

Then run the integration test in isolation:

```bash
./vendor/bin/phpunit tests/integration/Plugins/InstallEnableDisableTest.php
ls var/plugins/   # should be empty after tear-down
```

## 3. Verify acceptance criteria

Walk every checkbox from §7 of `a.4-plugin-loader.md`. For each:

- Migration 003 runs cleanly?
  ```bash
  php scripts/run-migrations.php 2>&1 | tail -20
  ```
- `var/plugins/` gitignored?
  ```bash
  grep 'var/plugins' .gitignore
  ```
- `LifecycleInterface` carries the "moves in B.1" note?
  ```bash
  grep -A2 'moves to' src/Plugins/Contract/LifecycleInterface.php
  ```
- `EventNameMap` covers every event class shipped in A.2?
  ```bash
  diff <(find src/Common/Events -name '*.php' \
              -not -name 'AbstractEvent.php' \
              -not -name 'EventDispatcherFactory.php' \
              -not -name 'ListenerRegistry.php' \
              -exec basename {} .php \; | sort) \
       <(php -r 'require "vendor/autoload.php"; \
                 foreach (Phlex\Plugins\EventNameMap::aliases() \
                          as $alias => $fqcn) { \
                     echo basename(str_replace("\\\\", "/", $fqcn)) . "\n"; \
                 }' | sort)
  ```
  Diff should be empty.

PASS / FAIL each.

## 4. Verify §0.4 doc deliverables

```bash
git show --stat HEAD -- docs/plugins/developer-guide.md
git show --stat HEAD -- docs/reference/env-vars.md
git show --stat HEAD -- CHANGELOG.md
git show --stat HEAD -- README.md
```

`docs/reference/env-vars.md` must list `PHLEX_PLUGINS_ALLOW_HTTP`,
`PHLEX_PLUGINS_ALLOW_UNSIGNED`, `PHLEX_PLUGINS_COMPOSER_TIMEOUT`.

`docs/plugins/developer-guide.md` must show a `LifecycleInterface` code
sample.

## 5. Verify postconditions

```bash
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match the A.4 squashed commit
git branch --list 'a.4-*'                   # MUST be empty
```

## 6. Report

PASS / FAIL with one-line reason per criterion. Do not modify code.
