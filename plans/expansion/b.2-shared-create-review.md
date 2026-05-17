# Review — Step B.2 (Scaffold `detain/phlex-shared` v0.1.0)

The implementation has been merged into the new `detain/phlex-shared`
repo. Re-verify without modifying code.

## 1. Re-read

- `plans/expansion/b.2-shared-create.md` (the step plan)
- `plans/expansion/b.1-shared-design.md` §4.2 and §4.6 (what v0.1.0
  ships vs. what v0.2.0 will add — must match)
- The initial commit + tag:
  ```bash
  cd /home/sites/phlex-shared
  git log --oneline
  git tag -l 'v0.1.0'
  git show v0.1.0 --stat
  ```

## 2. Re-run the §0.4 minimum bar (inside the new repo)

```bash
cd /home/sites/phlex-shared
composer install
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Version'   # confirm ≥ 85 %
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
composer validate --strict
composer audit --no-dev
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

Each must succeed.

## 3. Verify CI on the remote

```bash
unset GITHUB_TOKEN
gh run list --repo detain/phlex-shared --branch master --limit 1
# MUST show conclusion: success
gh run view --repo detain/phlex-shared $(gh run list --repo detain/phlex-shared --limit 1 --json databaseId -q '.[0].databaseId') | head -40
# MUST show 5 green checks: composer-validate, phpcs, phpstan, psalm, composer-audit (and phpunit)
```

## 4. Verify acceptance criteria

Walk every checkbox from §7 of `b.2-shared-create.md`. For each:

- Files exist?
  ```bash
  ls /home/sites/phlex-shared/
  ls /home/sites/phlex-shared/src/
  ls /home/sites/phlex-shared/tests/
  ls /home/sites/phlex-shared/.github/workflows/
  ```
  Must include `composer.json`, `phpunit.xml`, `phpstan.neon.dist`,
  `phpcs.xml.dist`, `psalm.xml`, `.gitignore`, `.editorconfig`,
  `LICENSE`, `README.md`, `AGENTS.md`, `CHANGELOG.md`,
  `src/Version.php`, `tests/VersionTest.php`, `.github/workflows/ci.yml`.
- `composer.json` matches b.1-shared-design.md §4.2 (the v0.1.0
  subset — runtime deps `php`, `psr/container`, `psr/event-dispatcher`)?
  ```bash
  jq '.require' /home/sites/phlex-shared/composer.json
  jq '.autoload' /home/sites/phlex-shared/composer.json
  ```
- `Phlex\Shared\Version::VERSION` is `'0.1.0'`?
  ```bash
  grep -E "const VERSION" /home/sites/phlex-shared/src/Version.php
  ```
- No additional classes shipped (would prematurely overlap with B.3)?
  ```bash
  find /home/sites/phlex-shared/src -name '*.php' -not -name 'Version.php'
  # MUST be empty
  ```

Report PASS / FAIL per criterion with a one-line reason.

## 5. Verify the repo metadata is **NOT** yet set

(B.2 ships scaffolding; the description + 19 topic tags land in B.2a.)

```bash
unset GITHUB_TOKEN
gh repo view detain/phlex-shared --json description,repositoryTopics
```

This SHOULD show an empty/default description and an empty topics
list. If they're already populated, the reviewer notes it but does
NOT fail B.2 — somebody applied the metadata early, which is harmless.

## 6. Verify no impact on `/home/sites/phlex`

```bash
cd /home/sites/phlex
git log --oneline -5
git status --short
git branch --show-current
```

The recent commits should NOT include a B.2-related squash commit;
B.2 lives entirely in `detain/phlex-shared`.

## 7. Verify postconditions on the new repo

```bash
cd /home/sites/phlex-shared
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match the initial v0.1.0 commit
git tag -l 'v0.1.0'                         # MUST list v0.1.0
git ls-remote --tags origin | grep refs/tags/v0.1.0
# MUST list the tag on the remote
```

## 8. Report

PASS / FAIL with one-line reason per criterion. Do not modify code. If
any criterion FAILs, recommend the supervisor either (a) spawn a
follow-up "Step B.2 fixup" subagent or (b) hard-reset the new repo's
master and re-run B.2. The reviewer never edits the codebase directly.
