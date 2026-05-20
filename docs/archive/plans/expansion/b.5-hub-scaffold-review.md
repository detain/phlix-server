# Review — Step B.5 (Scaffold `detain/phlex-hub`)

The implementation has been merged into the new `detain/phlex-hub`
repo. Re-verify without modifying code.

## 1. Re-read

- `plans/expansion/b.5-hub-scaffold.md`
- `plans/expansion/b.1-shared-design.md` §4.7 (the VCS-repository
  composer snippet — must match what's in
  `phlex-hub/composer.json`)
- Initial commit:
  ```bash
  cd /home/sites/phlex-hub
  git log --oneline
  git show --stat HEAD
  ```

## 2. Re-run the §0.4 minimum bar (inside the new repo)

```bash
cd /home/sites/phlex-hub
composer install
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Health|Version'   # confirm ≥ 85 %
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
composer validate --strict
composer audit --no-dev
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

## 3. Boot smoke

```bash
cd /home/sites/phlex-hub
php public/index.php start >/tmp/hub-review.log 2>&1 &
HUB_PID=$!
sleep 2
curl -s http://localhost:8800/health
# MUST return JSON containing "status":"ok"
kill $HUB_PID
```

## 4. Verify CI on the remote

```bash
unset GITHUB_TOKEN
gh run list --repo detain/phlex-hub --branch master --limit 1
# MUST show conclusion=success
gh run view --repo detain/phlex-hub $(gh run list --repo detain/phlex-hub --limit 1 --json databaseId -q '.[0].databaseId')
# MUST show 6 green jobs: composer-validate, phpcs, phpstan, psalm, composer-audit, phpunit
```

## 5. Verify acceptance criteria

Walk every checkbox from §7 of `b.5-hub-scaffold.md`. For each:

- Files exist?
  ```bash
  ls /home/sites/phlex-hub/
  ls /home/sites/phlex-hub/src/
  ls /home/sites/phlex-hub/config/
  ls /home/sites/phlex-hub/migrations/
  ls /home/sites/phlex-hub/public/
  ls /home/sites/phlex-hub/.github/workflows/
  ```
- `composer.json` requires `detain/phlex-shared:^0.2`?
  ```bash
  jq '.require["detain/phlex-shared"]' /home/sites/phlex-hub/composer.json
  ```
- VCS repository entry for phlex-shared is present?
  ```bash
  jq '.repositories' /home/sites/phlex-hub/composer.json
  ```
- `composer.lock` lists `detain/phlex-shared` at ^0.2.0?
  ```bash
  jq '.packages[] | select(.name == "detain/phlex-shared") | {name, version, source}' /home/sites/phlex-hub/composer.lock
  ```
- Namespace `Phlex\Hub\` PSR-4 mapped to `src/`?
  ```bash
  jq '.autoload' /home/sites/phlex-hub/composer.json
  ```
- `Phlex\Hub\Version::VERSION` is `'0.1.0'`?
- `docs/reference/env-vars.md` documents every `HUB_*` env var?
- No real migrations yet (B.6 territory)? `ls migrations/` shows
  `.gitkeep` and `001_placeholder.sql` only.

Report PASS / FAIL per criterion with a one-line reason.

## 6. Verify the repo metadata is **NOT** yet set

(B.5 ships scaffolding; the description + 19 topic tags land in B.5a.)

```bash
unset GITHUB_TOKEN
gh repo view detain/phlex-hub --json description,repositoryTopics
```

Empty description and empty topics array expected.

## 7. Verify no impact on `/home/sites/phlex` or `/home/sites/phlex-shared`

```bash
cd /home/sites/phlex
git log --oneline -5
git status --short                          # CALIBER_LEARNINGS.md OK

cd /home/sites/phlex-shared 2>/dev/null && git status --short
```

No B.5-related commits should appear in either of these repos.

## 8. Verify postconditions on the new repo

```bash
cd /home/sites/phlex-hub
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match the initial v0.1.0 commit
git remote -v                               # MUST show git@github.com:detain/phlex-hub.git
```

## 9. Report

PASS / FAIL with one-line reason per criterion. Do not modify code.
If any criterion FAILs, recommend the supervisor either (a) spawn a
follow-up "Step B.5 fixup" subagent or (b) hard-reset the new repo's
master and re-run B.5.
