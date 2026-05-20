# Review — Step B.3 (`phlex` consumes `phlex-shared`)

Two PRs have been merged: a `phlex-shared` v0.2.0 PR (with tag v0.2.0
pushed) and a `phlex` consume PR. Re-verify both without modifying
code.

## 1. Re-read

- `plans/expansion/b.3-shared-consume.md` (the step plan)
- `plans/expansion/b.1-shared-design.md` §4.1 (the move table) — every
  row in this table must correspond to either a file in
  `phlex-shared/src/` OR a `class_alias` entry in
  `phlex/src/Plugins/AliasCompatShim.php`.
- Diff of the squashed commits:
  ```bash
  cd /home/sites/phlex-shared && git show --stat HEAD
  cd /home/sites/phlex          && git show --stat HEAD
  ```

## 2. Re-run the §0.4 minimum bar (both repos)

### 2.A — `phlex-shared`

```bash
cd /home/sites/phlex-shared
composer install
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Plugin|Events|Auth|Hub'   # confirm ≥ 85 %
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
composer validate --strict
composer audit --no-dev
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
git tag -l 'v0.2.0'         # MUST list v0.2.0
git ls-remote --tags origin | grep refs/tags/v0.2.0  # MUST be present
```

### 2.B — `phlex`

```bash
cd /home/sites/phlex
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'AliasCompatShim|ManifestSchema'   # ≥ 85 %
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
./vendor/bin/phpunit tests/Integration/Plugins/SamplePluginSmokeTest.php   # shim guard
```

Total test count must be **at least 667** (the Phase A snapshot from
SESSION_HANDOFF.md). It may legitimately grow with new
AliasCompatShimTest / ManifestSchemaTest / LifecycleShimTest tests.

## 3. Verify the deprecation shims work

```bash
cd /home/sites/phlex
# 17 class_aliases registered
grep -cE "class_alias\(" src/Plugins/AliasCompatShim.php
# MUST be 17

# Lifecycle shim
cat src/Plugins/Contract/LifecycleInterface.php
# MUST contain 'extends \Phlex\Shared\Plugin\LifecycleInterface'
# MUST contain '@deprecated since 0.11.0'

# Manifest shim
cat src/Plugins/Manifest.php
# MUST contain 'extends \Phlex\Shared\Plugin\Manifest'
# MUST contain '@deprecated since 0.11.0'

# All sixteen original event/manifest files deleted
test ! -f src/Plugins/ManifestType.php
test ! -f src/Plugins/ManifestValidationError.php
test ! -f src/Plugins/EventNameMap.php
test ! -f src/Common/Events/AbstractEvent.php
test ! -f src/Common/Events/Playback/PlaybackStarted.php
# (and the other eleven event files)
```

## 4. Verify acceptance criteria

Walk every checkbox from §7 of `b.3-shared-consume.md`. For each:

- Files exist / missing as expected?
- composer.json declares `detain/phlex-shared:^0.2`?
  ```bash
  jq '.require["detain/phlex-shared"]' /home/sites/phlex/composer.json
  ```
- VCS repository entry present?
  ```bash
  jq '.repositories' /home/sites/phlex/composer.json
  ```
- `autoload.files` references the alias shim?
  ```bash
  jq '.autoload.files' /home/sites/phlex/composer.json
  ```
- `phlex-shared` Version is 0.2.0?
  ```bash
  grep -E "const VERSION" /home/sites/phlex-shared/src/Version.php
  ```
- Move table from b.1-shared-design.md §4.1 walked row-by-row: each
  "Target FQCN" exists in `phlex-shared`; each "Deprecation strategy"
  row produces a working alias in `phlex`.

Report PASS / FAIL per criterion with a one-line reason.

## 5. Verify §0.4 doc deliverables

```bash
cd /home/sites/phlex
git show --stat HEAD -- docs/dev/event-reference.md
git show --stat HEAD -- docs/plugins/developer-guide.md
git show --stat HEAD -- docs/plugins/manifest.md
git show --stat HEAD -- docs/dev/plugin-sdk.md
git show --stat HEAD -- docs/dev/architecture-server.md
git show --stat HEAD -- CHANGELOG.md
git show --stat HEAD -- README.md
```

Each must appear in the diff. Spot-check `docs/dev/event-reference.md`
for `Phlex\Shared\Events\…` rather than the old `Phlex\Common\Events\…`
FQCNs.

`docs/plugins/developer-guide.md` must have a "Migrating from 0.10.x"
section.

`CHANGELOG.md` must have a "0.11.0" entry naming `detain/phlex-shared`
and the deprecation aliases.

## 6. Verify postconditions (both repos)

### 6.A — `phlex-shared`

```bash
cd /home/sites/phlex-shared
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match the B.3-shared squashed commit
git branch --list 'b.3-*'                   # MUST be empty
git tag -l 'v0.2.0'                         # MUST list v0.2.0
```

### 6.B — `phlex`

```bash
cd /home/sites/phlex
git status --short                          # MUST be empty (CALIBER_LEARNINGS.md OK)
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match the B.3-server squashed commit
git branch --list 'b.3-*'                   # MUST be empty
```

## 7. Report

PASS / FAIL with one-line reason per criterion. Do not modify code.

If the `LifecycleShimTest` or `SamplePluginSmokeTest` fails, the
deprecation bridge is broken — recommend an immediate revert of the
`phlex` PR (the shared v0.2.0 release stays — it's correct on its
own). Otherwise, recommend either (a) a "Step B.3 fixup" subagent or
(b) acceptance of the merged state.
